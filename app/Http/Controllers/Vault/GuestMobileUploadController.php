<?php

declare(strict_types=1);

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\GuestMobileUploadRequest;
use App\Services\Media\GuestMobileUploadIngestor;
use App\Support\Security\MobileUploadGrant;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final readonly class GuestMobileUploadController
{
    public function __construct(
        private MobileUploadGrant $grants,
        private GuestMobileUploadIngestor $ingestor,
    ) {}

    public function show(string $token): Response
    {
        try {
            $grant = $this->grants->validateForGuest($token, now()->timestamp);
        } catch (InvalidArgumentException|RuntimeException) {
            abort(404);
        }

        return $this->page($token, $grant);
    }

    public function store(GuestMobileUploadRequest $request): Response
    {
        $validated = $request->validated();
        $token = (string) $validated['grant'];
        $profileId = isset($validated['profile_id'])
            ? (string) $validated['profile_id']
            : null;

        try {
            /** @var array<int, mixed> $media */
            $media = $validated['media'];
            $assets = $this->ingestor->ingest(
                $token,
                $media,
                $profileId,
                now()->timestamp,
            );
            $grant = $this->grants->validateForGuest($token, now()->timestamp);

            return $this->page(
                $token,
                $grant,
                submittedCount: count($assets),
            );
        } catch (ValidationException|InvalidArgumentException|LogicException|RuntimeException) {
            $grant = $this->safeGrant($token);

            return $this->page(
                $token,
                $grant,
                publicError: 'No pudimos enviar los archivos. Revisa el enlace y la selección antes de intentar de nuevo.',
                status: 422,
            );
        }
    }

    /**
     * @param array{
     *     scope: string,
     *     organization_id: string,
     *     issued_at: int,
     *     expires_at: int,
     *     max_files: int,
     *     max_bytes: int,
     *     nonce: string
     * }|null $grant
     */
    private function page(
        string $token,
        ?array $grant,
        ?string $publicError = null,
        int $submittedCount = 0,
        int $status = 200,
    ): Response {
        /** @var array<int, string> $allowedMimeTypes */
        $allowedMimeTypes = config('grindflow.media.allowed_mimetypes', []);

        return response()->view('vault.guest-upload', [
            'token' => $token,
            'available' => $grant !== null && $submittedCount === 0,
            'maxFiles' => $grant['max_files'] ?? 0,
            'maxBytes' => $grant['max_bytes'] ?? 0,
            'allowedMimeTypes' => $allowedMimeTypes,
            'publicError' => $publicError,
            'submittedCount' => $submittedCount,
        ], $status)
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return array{
     *     scope: string,
     *     organization_id: string,
     *     issued_at: int,
     *     expires_at: int,
     *     max_files: int,
     *     max_bytes: int,
     *     nonce: string
     * }|null
     */
    private function safeGrant(string $token): ?array
    {
        try {
            return $this->grants->validateForGuest($token, now()->timestamp);
        } catch (InvalidArgumentException|RuntimeException) {
            return null;
        }
    }
}
