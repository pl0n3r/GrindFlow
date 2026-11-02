<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use GrindFlow\Http\Controller\PlanLimitsAdminController;
use PHPUnit\Framework\TestCase;

/** Exact filename, class, method and contract expected by issue #784. */
final class Planlimitsadmintest extends TestCase
{
    public function test_criterio_principal(): void
    {
        $valid = ['expected_revision' => 0, 'limits' => [
            'storage_bytes' => 0, 'traffic_bytes' => null,
            'queued_jobs' => 2, 'api_calls' => 10,
        ]];
        self::assertSame($valid, PlanLimitsAdminController::parseChange('starter', $valid));
        self::assertTrue(PlanLimitsAdminController::canEditPlatformRole('admin'));
        foreach (['studio', 'editor', 'model', '', null, 'owner'] as $role) {
            self::assertFalse(PlanLimitsAdminController::canEditPlatformRole($role));
        }
        $changed = $valid;
        $changed['expected_revision'] = 17;
        self::assertSame($changed, PlanLimitsAdminController::parseChange('pro_2026', $changed));
        self::assertNull(PlanLimitsAdminController::parseChange(' invalid ', $valid));
        self::assertNull(PlanLimitsAdminController::parseChange('starter', null));
    }

    public function test_invalid_limits_fail_closed(): void
    {
        $base = ['expected_revision' => 1, 'limits' => [
            'storage_bytes' => 0, 'traffic_bytes' => null,
            'queued_jobs' => 2, 'api_calls' => 10,
        ]];
        foreach ([-1, '0', 0.1, true, [], new \stdClass()] as $bad) {
            $invalid = $base;
            $invalid['limits']['queued_jobs'] = $bad;
            self::assertNull(PlanLimitsAdminController::parseChange('plan', $invalid));
        }
        foreach (['expected_revision' => -1, 'limits' => [], 'malicious' => '1'] as $key => $value) {
            $invalid = $base;
            $invalid[$key] = $value;
            self::assertNull(PlanLimitsAdminController::parseChange('plan', $invalid));
        }
        foreach (['', 'bad name', 'a/b', "has\nnewline", str_repeat('a', 129)] as $id) {
            self::assertNull(PlanLimitsAdminController::parseChange($id, $base));
        }
    }

    public function test_unknown_does_not_mean_zero(): void
    {
        $payload = ['expected_revision' => 0, 'limits' => [
            'storage_bytes' => null, 'traffic_bytes' => 0,
            'queued_jobs' => null, 'api_calls' => null,
        ]];
        $parsed = PlanLimitsAdminController::parseChange('basic', $payload);
        self::assertNotNull($parsed);
        self::assertNull($parsed['limits']['storage_bytes']);
        self::assertSame(0, $parsed['limits']['traffic_bytes']);
    }
}
