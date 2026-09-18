<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Diagnostics\DiagnosticLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiagnosticsController extends Controller
{
    public function index(Request $request, DiagnosticLog $diagnostics): View
    {
        $this->authorizePlatformAdmin($request);

        return view('admin.diagnostics', [
            'entries' => $diagnostics->recent(50),
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
