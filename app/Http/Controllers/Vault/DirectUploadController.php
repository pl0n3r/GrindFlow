<?php

namespace App\Http\Controllers\Vault;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\CompleteDirectUploadRequest;
use App\Http\Requests\Vault\CreateDirectUploadRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\DirectMediaUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectUploadController extends Controller
{
    public function create(
        CreateDirectUploadRequest $request,
        DirectMediaUpload $uploads,
    ): JsonResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        $intent = $uploads->createIntent(
            $organization,
            $user,
            (string) $request->validated('filename'),
            (string) $request->validated('mime_type'),
            (int) $request->validated('byte_size'),
        );

        return response()->json($intent);
    }

    public function complete(
        CompleteDirectUploadRequest $request,
        DirectMediaUpload $uploads,
    ): JsonResponse {
        $organization = $this->organization($request);

        /** @var User $user */
        $user = $request->user();

        $asset = $uploads->complete(
            (string) $request->validated('upload_token'),
            $organization,
            $user,
        );

        return response()->json([
            'asset_id' => (string) $asset->getKey(),
            'status' => $asset->status,
            'message' => $asset->status === 'duplicate'
                ? 'Archivo registrado como duplicado sin guardar una segunda copia.'
                : 'Archivo agregado al Vault.',
        ]);
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenantOrganization');

        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
