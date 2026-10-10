<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/Scheduling/Mode/AutomationMode.php';
require dirname(__DIR__, 2) . '/src/Scheduling/Mode/ApprovalPolicy.php';

use GrindFlow\Scheduling\Mode\AutomationMode;
use GrindFlow\Scheduling\Mode\ApprovalPolicy;

function check(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException('test_failed:' . $reason);
    }
}
function context(): array
{
    return ['tenant_id' => 'tenant_1', 'account_id' => 'account_1',
        'permission_granted' => true, 'content_eligible' => true,
        'within_daily_cap' => true, 'within_time_window' => true,
        'minimum_separation_met' => true, 'external_state_known' => true,
        'approval_granted' => true];
}
function request(bool $studio = true): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1',
        'profile' => $studio ? 'studio' : 'individual', 'approval_required' => true,
        'required_roles' => $studio ? ['editor', 'reviewer'] : ['owner'],
        'expires_at' => 1800000100];
}
function response(string $role = 'editor'): array
{
    return ['tenant_id' => 'tenant_1', 'creator_id' => 'creator_1',
        'role' => $role, 'decision' => 'approve', 'role_verified' => true,
        'at' => 1800000000];
}
$tests = [
    'hard_rules' => static function (): void {
        $mode = new AutomationMode();
        $hardRules = array_diff(array_keys(context()), ['tenant_id', 'account_id']);
        foreach ($hardRules as $name) {
            foreach (['manual', 'assisted', 'pilot'] as $value) {
                $ctx = context();
                $ctx[$name] = false;
                $out = $mode->assess($value, $ctx, true);
                check($out['status'] === 'blocked' && !$out['execution_performed'],
                    'same_hard_rule_' . $name . '_' . $value);
            }
        }
        $bad = context();
        $bad['unknown_gate'] = true;
        check($mode->assess('pilot', $bad)['status'] === 'blocked', 'unknown_input');
        $bad = context();
        $bad['approval_granted'] = 'true';
        check($mode->assess('pilot', $bad)['status'] === 'blocked', 'type_bypass');
    },
    'assisted' => static function (): void {
        $mode = new AutomationMode();
        check($mode->assess('manual', context(), true)['status'] ===
            'manual_action_required', 'manual_no_autonomy');
        check($mode->assess('assisted', context())['status'] ===
            'confirmation_required', 'assisted_requires_confirmation');
        check($mode->assess('assisted', context(), true)['status'] ===
            'eligible', 'explicit_confirmation');
        check($mode->assess('pilot', context())['status'] === 'eligible', 'pilot');
        check($mode->assess('unknown', context(), true)['status'] === 'blocked',
            'invalid_mode');
        foreach (['manual','assisted','pilot'] as $value) {
            check($mode->assess($value, context(), true)['execution_performed'] ===
                false, 'pure_no_execution');
        }
    },
    'studio' => static function (): void {
        $p = new ApprovalPolicy();
        $now = 1800000001;
        $req = request();
        $first = $p->decide($req, [response()], $now);
        check($first['status'] === 'pending' && !$first['approved']
            && $first['pending_roles'] === ['reviewer'], 'missing_required_role');
        $both = $p->decide($req, [response(), response('reviewer')], $now);
        check($both['status'] === 'approved' && $both['approved']
            && count($both['audit']) === 2, 'all_roles_required');
        $foreign = response('reviewer');
        $foreign['tenant_id'] = 'tenant_2';
        check(!$p->decide($req, [response(), $foreign], $now)['approved'],
            'foreign_tenant');
        $dup = $p->decide($req, [response(), response()], $now);
        check($dup['status'] === 'invalid', 'duplicate_role');
        // Malformed nested roles must fail without warnings or runtime errors.
        $oldHandler = set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            foreach ([['editor'], new \stdClass(), true] as $malformedRole) {
                $malformed = request();
                $malformed['required_roles'] = [$malformedRole];
                $out = $p->decide($malformed, [], $now);
                check($out['status'] === 'invalid' && !$out['approved']
                    && $out['audit'] === [], 'nested_role_fails_cleanly');
            }
        } finally {
            restore_error_handler();
        }

        $deny = response('reviewer');
        $deny['decision'] = 'reject';
        check($p->decide($req, [response(), $deny], $now)['status'] ===
            'rejected', 'studio_rejection');
        check($p->decide($req, [response(), response('reviewer')], 1800000100)['status']
            === 'expired', 'expired_request');
    },
    'insufficient_role' => static function (): void {
        $p = new ApprovalPolicy();
        $now = 1800000001;
        $req = request(false);
        $unverified = response('owner');
        $unverified['role_verified'] = false;
        check(!$p->decide($req, [$unverified], $now)['approved'], 'unverified_role');
        check(!$p->decide($req, [response('editor')], $now)['approved'],
            'wrong_role');
        check($p->decide($req, [response('owner')], $now)['approved'],
            'verified_personal');
        $req['approval_required'] = false;
        $req['required_roles'] = [];
        check($p->decide($req, [], $now)['status'] === 'not_required',
            'optional_personal');
        // An expired timestamp applies to an actual required approval only.
        $req['expires_at'] = $now;
        $optionalExpired = $p->decide($req, [], $now);
        check($optionalExpired['status'] === 'not_required'
            && $optionalExpired['approved'] && $optionalExpired['audit'] === [],
            'optional_personal_no_expiry_veto');
        check($p->decide($req, [response('owner')], $now)['status'] === 'invalid',
            'optional_personal_rejects_spurious_approval');
        $req['approval_required'] = true;
        $req['required_roles'] = ['owner'];
        check($p->decide($req, [response('owner')], $now)['status'] === 'expired',
            'required_personal_still_expires');
        $req = request();
        $out = $p->decide($req, [['secret' => 'untrusted']], $now);
        check($out['status'] === 'invalid' && !str_contains(json_encode($out),
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
