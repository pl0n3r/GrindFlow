<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\Diagnostics\DiagnosticLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DiagnosticsController extends Controller
{
    public function index(Request $request, DiagnosticLog $diagnostics): View
    {
        $this->authorizePlatformAdmin($request);

        $workspaceOrganization = null;

        try {
            $workspaceOrganization = Organization::query()->orderBy('name')->first();
        } catch (Throwable) {
            // Diagnostics must remain usable when the application database is unavailable.
        }

        return view('admin.diagnostics', [
            'entries' => $diagnostics->recent(50),
            'workspaceOrganization' => $workspaceOrganization,
        ]);
    }

    public function json(Request $request, DiagnosticLog $diagnostics): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        return response()->json([
            'generated_at' => now()->utc()->toIso8601String(),
            'entries' => $diagnostics->recent(50),
        ]);
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->isPlatformAdmin(),
            403,
        );
    }
}
