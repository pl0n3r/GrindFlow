<?php

namespace App\Services\Media\Connections;

use App\Models\MediaConnection;
use App\Models\User;
use App\Services\Media\Connectors\DropboxMediaAdapter;
use App\Services\Media\Connectors\GoogleDriveMediaAdapter;
use App\Services\Media\Connectors\MediaConnectorException;
use App\Support\Security\SecretCryptoException;
use InvalidArgumentException;

class MediaConnectionScanner
{
    public function __construct(
        private readonly DropboxMediaAdapter $dropbox,
        private readonly GoogleDriveMediaAdapter $googleDrive,
        private readonly MediaConnectionTokenProvider $tokens,
    ) {}

    public function scan(MediaConnection $connection, User $actor): void
    {
        if ($connection->status !== MediaConnection::STATUS_ACTIVE) {
            return;
        }

        if ($actor->canManageOrganization((string) $connection->organization_id) === false) {
            $this->needsReconnect($connection, 'connection_actor_unauthorized');

            return;
        }

        try {
            match ($connection->provider) {
                MediaConnection::PROVIDER_DROPBOX => $this->scanDropbox($connection, $actor),
                MediaConnection::PROVIDER_GOOGLE_DRIVE => $this->scanGoogleDrive($connection, $actor),
                default => throw new InvalidArgumentException('Unsupported media connection provider.'),
            };
        } catch (SecretCryptoException) {
            $this->needsReconnect($connection, 'connection_credentials_unreadable');
        } catch (MediaConnectorException $exception) {
            $this->handleConnectorFailure($connection, $exception);
        }
    }

    private function scanDropbox(MediaConnection $connection, User $actor): void
    {
        $accessToken = $this->tokens->accessToken(
            $connection,
            $actor,
        );

        $cursor = is_string($connection->cursor) && $connection->cursor !== ''
            ? $connection->cursor
            : null;
        $pageBudget = $this->pageBudget();
        $pages = 0;
        $hasMore = true;

        while ($hasMore && $pages < $pageBudget) {
            $listing = $cursor === null
                ? $this->dropbox->listInitial($accessToken, $connection->root_path)
                : $this->dropbox->listIncremental($accessToken, $cursor);

            foreach ($listing->files as $file) {
                $this->dropbox->stageAndQueue($actor, $accessToken, $file);
            }

            if ($listing->cursor !== null && $listing->cursor !== '') {
                $cursor = $listing->cursor;
            }

            $hasMore = $listing->hasMore;
            $pages++;
        }

        $this->completeScan(
            $connection,
            $cursor,
            $hasMore,
        );
    }

    private function scanGoogleDrive(
        MediaConnection $connection,
        User $actor,
    ): void {
        $accessToken = $this->tokens->accessToken(
            $connection,
            $actor,
        );

        $cursor = GoogleDriveScanCursor::decode(
            is_string($connection->cursor)
                ? $connection->cursor
                : null,
        );

        if ($cursor === null) {
            $cursor = GoogleDriveScanCursor::bootstrap(
                $this->googleDrive->startPageToken($accessToken),
            );
            $this->persistCursor($connection, $cursor);
        }

        $pageBudget = $this->pageBudget();
        $pages = 0;
        $needsContinuation = true;

        if ($cursor->isBootstrap()) {
            [$cursor, $pages, $needsContinuation] = $this->bootstrapGoogleDrive(
                $connection,
                $actor,
                $accessToken,
                $cursor,
                $pageBudget,
            );
        }

        if (
            $cursor->isBootstrap() === false
            && $pages < $pageBudget
        ) {
            [$cursor, $pages, $needsContinuation] = $this->scanGoogleDriveChanges(
                $connection,
                $actor,
                $accessToken,
                $cursor,
                $pageBudget,
                $pages,
            );
        }

        $this->completeScan(
            $connection,
            $cursor->encode(),
            $needsContinuation,
        );
    }

    /**
     * @return array{GoogleDriveScanCursor, int, bool}
     */
    private function bootstrapGoogleDrive(
        MediaConnection $connection,
        User $actor,
        string $accessToken,
        GoogleDriveScanCursor $cursor,
        int $pageBudget,
    ): array {
        $pages = 0;

        while ($pages < $pageBudget) {
            $listing = $cursor->pageToken === ''
                ? $this->googleDrive->listInitial(
                    $accessToken,
                    $connection->root_path,
                )
                : $this->googleDrive->listPage(
                    $accessToken,
                    $cursor->pageToken,
                    $connection->root_path,
                );

            foreach ($listing->files as $file) {
                $this->googleDrive->stageAndQueue(
                    $actor,
                    $accessToken,
                    $file,
                );
            }

            $pages++;

            if ($listing->hasMore) {
                if ($listing->cursor === null || $listing->cursor === '') {
                    throw MediaConnectorException::requestFailed();
                }

                $cursor = GoogleDriveScanCursor::bootstrap(
                    (string) $cursor->startPageToken,
                    $listing->cursor,
                );
                $this->persistCursor($connection, $cursor);

                continue;
            }

            $cursor = GoogleDriveScanCursor::changes(
                (string) $cursor->startPageToken,
            );
            $this->persistCursor($connection, $cursor);

            return [
                $cursor,
                $pages,
                $pages >= $pageBudget,
            ];
        }

        return [$cursor, $pages, true];
    }

    /**
     * @return array{GoogleDriveScanCursor, int, bool}
     */
    private function scanGoogleDriveChanges(
        MediaConnection $connection,
        User $actor,
        string $accessToken,
        GoogleDriveScanCursor $cursor,
        int $pageBudget,
        int $pages,
    ): array {
        while ($pages < $pageBudget) {
            $listing = $this->googleDrive->listChanges(
                $accessToken,
                $cursor->pageToken,
                $connection->root_path,
            );

            foreach ($listing->files as $file) {
                $this->googleDrive->stageAndQueue(
                    $actor,
                    $accessToken,
                    $file,
                );
            }

            $pages++;

            if ($listing->hasMore()) {
                $nextPageToken = $listing->nextPageToken;

                if ($nextPageToken === null || $nextPageToken === '') {
                    throw MediaConnectorException::requestFailed();
                }

                $cursor = GoogleDriveScanCursor::changes(
                    $nextPageToken,
                );
                $this->persistCursor($connection, $cursor);

                continue;
            }

            $newStartPageToken = $listing->newStartPageToken;

            if (
                $newStartPageToken === null
                || $newStartPageToken === ''
            ) {
                throw MediaConnectorException::requestFailed();
            }

            $cursor = GoogleDriveScanCursor::changes(
                $newStartPageToken,
            );
            $this->persistCursor($connection, $cursor);

            return [$cursor, $pages, false];
        }

        return [$cursor, $pages, true];
    }

    private function persistCursor(
        MediaConnection $connection,
        GoogleDriveScanCursor $cursor,
    ): void {
        $connection->forceFill([
            'cursor' => $cursor->encode(),
        ])->save();
    }

    private function completeScan(
        MediaConnection $connection,
        ?string $cursor,
        bool $hasMore,
    ): void {
        $now = now();
        $nextScanAt = $hasMore
            ? $now->copy()->addMinute()
            : $now->copy()->addMinutes($connection->scan_interval_minutes);

        $connection->forceFill([
            'cursor' => $cursor,
            'last_scan_at' => $now,
            'next_scan_at' => $nextScanAt,
            'last_error' => $hasMore ? 'scan_page_budget_reached' : null,
            'consecutive_failures' => 0,
        ])->save();
    }

    private function handleConnectorFailure(
        MediaConnection $connection,
        MediaConnectorException $exception,
    ): void {
        if ($exception->needsReconnect) {
            $this->needsReconnect($connection, $exception->getMessage());

            return;
        }

        if ($exception->getMessage() === 'connector_rate_limited') {
            $delay = $exception->retryAfterSeconds ?? 300;

            $connection->forceFill([
                'next_scan_at' => now()->addSeconds(max(60, min($delay, 3600))),
                'last_error' => 'connector_rate_limited',
            ])->save();

            return;
        }

        $failures = min($connection->consecutive_failures + 1, 10);
        $delayMinutes = min(15 * (2 ** max(0, $failures - 1)), 360);

        $connection->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => mb_substr($exception->getMessage(), 0, 191),
            'next_scan_at' => now()->addMinutes($delayMinutes),
        ])->save();
    }

    private function needsReconnect(
        MediaConnection $connection,
        string $safeError,
    ): void {
        $connection->forceFill([
            'status' => MediaConnection::STATUS_NEEDS_RECONNECT,
            'next_scan_at' => null,
            'last_error' => mb_substr($safeError, 0, 191),
        ])->save();
    }

    private function pageBudget(): int
    {
        $configured = (int) config(
            'grindflow.media.connector_scan_page_budget',
            20,
        );

        return max(1, min($configured, 100));
    }
}
