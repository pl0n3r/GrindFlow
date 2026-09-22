<?php

declare(strict_types=1);

namespace GrindFlow\Http\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use GrindFlow\Identity\Application\MembershipContext;
use GrindFlow\Identity\Entity\IdentityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * S5 pilot baseline: seven UTC days of internal manual-work events.
 *
 * Internal records are not evidence of external publication or conversion.
 * No visitor identity, raw click or cross-organization information is exposed.
 */
final class PilotWeeklySummaryController extends AbstractController
{
    #[Route('/api/admin/pilot/weekly-summary', name: 'grindflow_pilot_weekly_summary', methods: ['GET'])]
    public function __invoke(Request $request, MembershipContext $memberships, Connection $db): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof IdentityUser || !$user->isActive()) {
            return $this->error(401, 'authentication_required', 'Inicia sesión para continuar.');
        }

        $selected = $request->getSession()->get('grindflow_organization_id');
        if (!is_string($selected) || $selected === '') {
            return $this->error(409, 'organization_required', 'Selecciona una organización para continuar.');
        }

        $organization = $memberships->find($user->id(), $selected);
        if ($organization === null) {
            $request->getSession()->remove('grindflow_organization_id');

            return $this->error(403, 'organization_access_changed', 'Tu acceso a esta organización ha cambiado.');
        }

        $query = $request->query->all();
        if (array_diff(array_keys($query), ['week']) !== []) {
            return $this->invalidWeek();
        }
        $utc = new DateTimeZone('UTC');
        $current = (new DateTimeImmutable('now', $utc))->modify('monday this week')->setTime(0, 0);
        $raw = $query['week'] ?? $current->format('Y-m-d');
        if (!is_string($raw) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $raw) !== 1) {
            return $this->invalidWeek();
        }
        $week = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $utc);
        if (!$week instanceof DateTimeImmutable || $week->format('Y-m-d') !== $raw
            || $week->format('N') !== '1'
            || $week > $current
            || $week < $current->modify('-11 weeks')) {
            return $this->invalidWeek();
        }

        $start = $week->format('Y-m-d H:i:s');
        $end = $week->modify('+7 days')->format('Y-m-d H:i:s');
        $ready = $db->createSchemaManager()->tablesExist(['gf_manual_handoff_events']);

        if (!$ready) {
            return $this->privateJson(['data' => [
                'ready' => false,
                'week_start_utc' => $raw,
                'week_end_exclusive_utc' => $week->modify('+7 days')->format('Y-m-d'),
                'days' => [],
                'totals' => null,
                'traffic' => ['ready' => false, 'clicks' => null],
                'external_publications_verified' => null,
                'provider_calls' => false,
            ]]);
        }

        $rows = $db->fetchAllAssociative(
            <<<'SQL'
                SELECT DATE(created_at) AS day_utc, action, COUNT(*) AS event_count
                FROM gf_manual_handoff_events
                WHERE organization_id = :organization
                  AND created_at >= :start AND created_at < :end
                GROUP BY DATE(created_at), action
                ORDER BY day_utc, action
                SQL,
            [
                'organization' => $organization['id'],
                'start' => $start,
                'end' => $end,
            ],
        );

        $days = [];
        $totals = ['prepared_attempts' => 0, 'completed_reports' => 0, 'failed_attempts' => 0];
        for ($index = 0; $index < 7; ++$index) {
            $day = $week->modify('+'.$index.' days')->format('Y-m-d');
            $days[$day] = [
                'date_utc' => $day,
                'prepared_attempts' => 0,
                'completed_reports' => 0,
                'failed_attempts' => 0,
            ];
        }
        $fields = ['prepare' => 'prepared_attempts', 'complete' => 'completed_reports', 'fail' => 'failed_attempts'];
        foreach ($rows as $row) {
            $date = (string) $row['day_utc'];
            $field = $fields[$row['action']] ?? null;
            if ($field === null || !isset($days[$date])) {
                continue;
            }
            $count = (int) $row['event_count'];
            $days[$date][$field] = $count;
            $totals[$field] += $count;
        }

        return $this->privateJson(['data' => [
            'ready' => true,
            'week_start_utc' => $raw,
            'week_end_exclusive_utc' => $week->modify('+7 days')->format('Y-m-d'),
            'days' => array_values($days),
            'totals' => $totals,
            // A Traffic pipeline is not implemented in the isolated Symfony runtime.
            // Never substitute a fabricated zero for an unavailable metric.
            'traffic' => ['ready' => false, 'clicks' => null],
            'external_publications_verified' => null,
            'provider_calls' => false,
        ]]);
    }

    private function invalidWeek(): JsonResponse
    {
        return $this->error(
            422,
            'invalid_pilot_week',
            'Selecciona un lunes UTC dentro de las últimas doce semanas.',
        );
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->privateJson(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /** @param array<string, mixed> $payload */
    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
