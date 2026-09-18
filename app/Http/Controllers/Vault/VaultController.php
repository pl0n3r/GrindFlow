<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\StoreMediaUploadRequest;
use App\Models\MediaAsset;
use App\Models\MediaBlob;
use App\Models\MediaConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\DirectMediaUpload;
use App\Services\Media\MediaIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class VaultController extends Controller
{
    public function index(Request $request, DirectMediaUpload $directUploads): View
    {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        $assets = MediaAsset::query()
            ->with('blob')
            ->latest()
            ->limit(100)
            ->get();

        $assetCount = MediaAsset::query()->count();
        $blobCount = MediaBlob::query()->count();
        $duplicateCount = MediaAsset::query()
            ->where('status', MediaAsset::STATUS_DUPLICATE)
            ->count();
        $storageBytes = (int) MediaBlob::query()->sum('byte_size');
        $canUpload = $user->canManageOrganization($organization);
        $connectionsReady = Schema::hasTable('media_connections');
        $dropboxOAuthConfigured = (string) config(
            'grindflow.connectors.dropbox.app_key',
            '',
        ) !== '' && (string) config(
            'grindflow.connectors.dropbox.app_secret',
            '',
        ) !== '';
        $dropboxConnectionCount = $connectionsReady
            ? MediaConnection::query()
                ->where('provider', MediaConnection::PROVIDER_DROPBOX)
                ->count()
            : 0;
        $googleOAuthConfigured = (string) config(
            'grindflow.connectors.google_drive.client_id',
            '',
        ) !== '' && (string) config(
            'grindflow.connectors.google_drive.client_secret',
            '',
        ) !== '';
        $googleConnectionCount = $connectionsReady
            ? MediaConnection::query()
                ->where('provider', MediaConnection::PROVIDER_GOOGLE_DRIVE)
                ->count()
            : 0;

        return view('vault.index', [
            'organization' => $organization,
            'assets' => $assets,
            'assetCount' => $assetCount,
            'blobCount' => $blobCount,
            'duplicateCount' => $duplicateCount,
            'storageBytes' => $storageBytes,
            'canUpload' => $canUpload,
            'directUploadAvailable' => $directUploads->available(),
            'dropboxConnectAvailable' => $canUpload
                && $connectionsReady
                && $dropboxOAuthConfigured,
            'dropboxConnectionCount' => $dropboxConnectionCount,
            'googleDriveConnectAvailable' => $canUpload
                && $connectionsReady
                && $googleOAuthConfigured,
            'googleDriveConnectionCount' => $googleConnectionCount,
            'mediaConnectionsReady' => $connectionsReady,
            'directUploadMaxBytes' => $directUploads->maxBytes(),
        ]);
    }

    public function store(
        StoreMediaUploadRequest $request,
        MediaIngestor $ingestor,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $file = $request->file('media');

        abort_unless($file instanceof UploadedFile, 422);

        $asset = $ingestor->ingest($file, $user);

        $message = $asset->status === MediaAsset::STATUS_DUPLICATE
            ? 'Archivo registrado como duplicado sin guardar una segunda copia.'
            : 'Archivo agregado al Vault.';

        return redirect()
            ->route('organizations.vault.index', [
                'organizationId' => $this->organization($request)->getKey(),
            ])
            ->with('status', $message);
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
