<?php

namespace App\Http\Controllers\Traffic;

use App\Http\Controllers\Controller;
use App\Jobs\RecordTrackedLinkClick;
use App\Models\Scopes\TenantScope;
use App\Models\TrackedLink;
use App\Services\Traffic\VisitorFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class TrackedLinkRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        VisitorFingerprint $fingerprint,
        string $token,
    ): RedirectResponse {
        abort_unless(Schema::hasTable('tracked_links'), 404);

        $link = TrackedLink::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('token', $token)
            ->where('status', TrackedLink::STATUS_ACTIVE)
            ->firstOrFail();

        $visitorHash = $fingerprint->forLink(
            $request,
            (string) $link->getKey(),
        );

        if ($visitorHash !== null) {
            RecordTrackedLinkClick::dispatchAfterResponse(
                (string) $link->organization_id,
                (string) $link->getKey(),
                $visitorHash,
                now('UTC')->toIso8601String(),
            );
        }

        return redirect()
            ->away($link->destination_url, 302)
            ->withHeaders([
                'Cache-Control' => 'no-store, max-age=0',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }
}
