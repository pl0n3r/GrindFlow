<?php

namespace App\Services\Media\Connections;

use App\Jobs\ScanMediaConnection;
use App\Models\MediaConnection;
use App\Models\Scopes\TenantScope;
use App\Models\User;

class MediaConnectionScheduler
{
    public function dispatchDue(): int
    {
        $batchSize = max(1, min(
            (int) config('grindflow.media.connector_scan_batch_size', 20),
            100,
        ));

        $connections = MediaConnection::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('status', MediaConnection::STATUS_ACTIVE)
            ->whereNotNull('next_scan_at')
            ->where('next_scan_at', '<=', now())
            ->orderBy('next_scan_at')
            ->limit($batchSize)
            ->get();

        $dispatched = 0;

        foreach ($connections as $connection) {
            $actorId = $connection->authorized_by_user_id;

            if (is_string($actorId) === false || $actorId === '') {
                $this->markNeedsReconnect($connection, 'connection_actor_missing');

                continue;
            }

            $actor = User::query()->find($actorId);

            if (
                $actor === null
                || $actor->canManageOrganization((string) $connection->organization_id) === false
            ) {
                $this->markNeedsReconnect($connection, 'connection_actor_unauthorized');

                continue;
            }

            ScanMediaConnection::dispatch(
                (string) $connection->getKey(),
                (string) $connection->organization_id,
                (string) $actor->getKey(),
            );

            $dispatched++;
        }

        return $dispatched;
    }

    private function markNeedsReconnect(
        MediaConnection $connection,
        string $safeError,
    ): void {
        MediaConnection::query()
            ->withoutGlobalScope(TenantScope::class)
            ->whereKey($connection->getKey())
            ->update([
                'status' => MediaConnection::STATUS_NEEDS_RECONNECT,
                'next_scan_at' => null,
                'last_error' => $safeError,
                'updated_at' => now(),
            ]);
    }
}
