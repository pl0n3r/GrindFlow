<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Scheduling/Mode/AutomationMode.php';
require dirname(__DIR__, 2) . '/src/Scheduling/Mode/ApprovalPolicy.php';

use GrindFlow\Scheduling\Mode\AutomationMode;
use GrindFlow\Scheduling\Mode\ApprovalPolicy;
function gf427_checkbool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException('test_failed:' . $reason);
    }
}
function gf427_context): array
{
    return ['tenant_id' => 'tenant_1', 'account_id' => 'account_1',
        'permission_granted' => true, 'content_eligible' => true,
        'within_daily_cap' => true, 'within_time_window' => true,
        'minimum_separation_met' => true, 'external_state_known' => true,
        'approval_granted' => true];
}
function gf427_requestbool $studio = true): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1',
        'request_id' => 'approval_001', 'issued_at' => 1800000000,
        'profile' => $studio ? 'studio' : 'individual', 'approval_required' => true,
        'required_roles' => $studio ? ['editor', 'reviewer'] : ['owner'],
        'expires_at' => 1800000100];
}
function gf427_responsestring $role = 'editor'): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1',
        'request_id' => 'approval_001',
        'role' => $role, 'decision' => 'approve', 'role_verified' => true,
        'at' => 1800000000];
}
$tests = [
    'hard_rules' => static function (): void {
        $mode = new AutomationMode();
        $hardRules = array_diff(array_keys(gf427_context)), ['tenant_id', 'account_id']);
        foreach ($hardRules as $name) {
            foreach (['manual', 'assisted', 'autopilot', 'pilot'] as $value) {
                $ctx = gf427_context);
                $ctx[$name] = false;
                $out = $mode->assess($value, $ctx, true);
                gf427_check$out['status'] === 'blocked' && !$out['execution_performed'],
                    'same_hard_rule_' . $name . '_' . $value);
            }
        }
        $bad = gf427_context);
        $bad['unknown_gate'] = true;
        gf427_check$mode->assess('pilot', $bad)['status'] === 'blocked', 'unknown_input');
        $bad = gf427_context);
        $bad['approval_granted'] = 'true';
        gf427_check$mode->assess('pilot', $bad)['status'] === 'blocked', 'type_bypass');
    },
    'assisted' => static function (): void {
        $mode = new AutomationMode();
        gf427_check$mode->assess('manual', gf427_context), true)['status'] ===
            'manual_action_required', 'manual_no_autonomy');
        gf427_check$mode->assess('assisted', gf427_context))['status'] ===
            'confirmation_required', 'assisted_requires_confirmation');
        gf427_check$mode->assess('assisted', gf427_context), true)['status'] ===
            'eligible', 'explicit_confirmation');
        gf427_check$mode->assess('pilot', gf427_context))['status'] === 'eligible', 'legacy_pilot');
        gf427_check$mode->assess('autopilot', gf427_context))['status'] === 'eligible',
            'contractual_autopilot');
        gf427_check$mode->assess('autopilot', gf427_context)) === $mode->assess('pilot', gf427_context)),
            'pilot_alias_same_result');
        gf427_check$mode->assess('unknown', gf427_context), true)['status'] === 'blocked',
            'invalid_mode');
        foreach (['manual','assisted','autopilot','pilot'] as $value) {
            gf427_check$mode->assess($value, gf427_context), true)['execution_performed'] ===
                false, 'pure_no_execution');
        }
    },
    'studio' => static function (): void {
        $p = new ApprovalPolicy();
        $now = 1800000001;
        $req = gf427_request);
        $first = $p->decide($req, [gf427_response)], $now);
        gf427_check$first['status'] === 'pending' && !$first['approved']
            && $first['pending_roles'] === ['reviewer'], 'missing_required_role');
        $both = $p->decide($req, [gf427_response), gf427_response'reviewer')], $now);
        gf427_check$both['status'] === 'approved' && $both['approved']
            && count($both['audit']) === 2, 'all_roles_required');
        // A previous Studio approval must never authorize a new request.
        $new = $req;
        $new['request_id'] = 'approval_002';
        $new['issued_at'] = $now;
        $oldResponses = [gf427_response), gf427_response'reviewer')];
        gf427_check$p->decide($new, $oldResponses, $now)['status'] === 'invalid',
            'replay_from_other_request_rejected');
        foreach ($oldResponses as &$oldResponse) {
            $oldResponse['request_id'] = 'approval_002';
        }
        unset($oldResponse);
        gf427_check$p->decide($new, $oldResponses, $now)['status'] === 'invalid',
            'old_timestamp_rejected_even_with_new_request_id');
        foreach ($oldResponses as &$currentResponse) {
            $currentResponse['at'] = $now;
        }
        unset($currentResponse);
        gf427_check$p->decide($new, $oldResponses, $now)['approved'],
            'all_new_request_approvals_valid');
        $malformedRequest = $new;
        $malformedRequest['issued_at'] = true;
        gf427_check$p->decide($malformedRequest, [], $now)['status'] === 'invalid',
            'issued_at_requires_integer');
        $malformedRequest = $new;
        $malformedRequest['request_id'] = '';
        gf427_check$p->decide($malformedRequest, [], $now)['status'] === 'invalid',
            'request_id_required');
        $foreign = gf427_response'reviewer');
        $foreign['tenant_id'] = 'tenant_2';
        gf427_check!$p->decide($req, [gf427_response), $foreign], $now)['approved'],
            'foreign_tenant');
        $dup = $p->decide($req, [gf427_response), gf427_response)], $now);
        gf427_check$dup['status'] === 'invalid', 'duplicate_role');
        $duproles = gf427_request);
        $duproles['required_roles'] = ['editor', 'editor'];
        gf427_check$p->decide($duproles, [], $now)['status'] === 'invalid',
            'duplicate_required_role');
        // Malformed nested roles must fail without warnings or runtime errors.
        $oldHandler = set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            foreach ([['editor'], new \stdClass(), true] as $malformedRole) {
                $malformed = gf427_request);
                $malformed['required_roles'] = [$malformedRole];
                $out = $p->decide($malformed, [], $now);
                gf427_check$out['status'] === 'invalid' && !$out['approved']
                    && $out['audit'] === [], 'nested_role_fails_cleanly');
            }
            foreach ([null, false, 17, 'untrusted', new \stdClass()] as $badResponse) {
                $out = $p->decide(gf427_request), [$badResponse], $now);
                gf427_check$out['status'] === 'invalid' && !$out['approved']
                    && $out['audit'] === [], 'non_array_response_fails_closed');
            }
        } finally {
            restore_error_handler();
        }
        $deny = gf427_response'reviewer');
        $deny['decision'] = 'reject';
        gf427_check$p->decide($req, [gf427_response), $deny], $now)['status'] ===
            'rejected', 'studio_rejection');
        gf427_check$p->decide($req, [gf427_response), gf427_response'reviewer')], 1800000100)['status']
            === 'expired', 'expired_request');
    },
    'insufficient_role' => static function (): void {
        $p = new ApprovalPolicy();
        $now = 1800000001;
        $req = gf427_requestfalse);
        $unverified = gf427_response'owner');
        $unverified['role_verified'] = false;
        gf427_check!$p->decide($req, [$unverified], $now)['approved'], 'unverified_role');
        gf427_check!$p->decide($req, [gf427_response'editor')], $now)['approved'],
            'wrong_role');
        gf427_check$p->decide($req, [gf427_response'owner')], $now)['approved'],
            'verified_personal');
        $req['approval_required'] = false;
        $req['required_roles'] = [];
        gf427_check$p->decide($req, [], $now)['status'] === 'not_required',
            'optional_personal');
        // An expired timestamp applies to an actual required approval only.
        $req['expires_at'] = $now;
        $optionalExpired = $p->decide($req, [], $now);
        gf427_check$optionalExpired['status'] === 'not_required'
            && $optionalExpired['approved'] && $optionalExpired['audit'] === [],
            'optional_personal_no_expiry_veto');
        gf427_check$p->decide($req, [gf427_response'owner')], $now)['status'] === 'invalid',
            'optional_personal_rejects_spurious_approval');
        $req['approval_required'] = true;
        $req['required_roles'] = ['owner'];
        gf427_check$p->decide($req, [gf427_response'owner')], $now)['status'] === 'expired',
            'required_personal_still_expires');
        $req = gf427_request);
        $out = $p->decide($req, [['secret' => 'untrusted']], $now);
        gf427_check$out['status'] === 'invalid' && !str_contains(json_encode($out),
            'untrusted'), 'safe_audit');
    },
];
$name = $argv[1] ?? null;
if ($name !== null && !array_key_exists($name, $tests)) {
    fwrite(STDERR, "unknown_case\n");
    exit(2);
}
foreach ($tests as $case => $test) {
    if ($name === null || $name === $case) {
        $test();
        echo "PASS {$case}\n";
    }
}
