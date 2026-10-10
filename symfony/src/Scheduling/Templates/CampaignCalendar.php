<?php

declare(strict_types=1);

namespace GrindFlow\Scheduling\Templates;

use InvalidArgumentException;

/** Immutable campaign-level calendar description. Never writes or publishes. */
final class CampaignCalendar
{
    public function define(array $campaign, array $pattern, array $ownership = []): array
    {
        $expected = ['tenant_id', 'campaign_id', 'account_id', 'collection_ids'];
        $keys = array_keys($campaign);
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || !CalendarTemplate::id($campaign['tenant_id'])
            || !CalendarTemplate::id($campaign['campaign_id'])
            || !CalendarTemplate::id($campaign['account_id'])
            || !is_array($campaign['collection_ids'])
            || !array_is_list($campaign['collection_ids'])
            || count($campaign['collection_ids']) < 1
            || count($campaign['collection_ids']) > 100) {
            throw new InvalidArgumentException('invalid_campaign_shape');
        }
        foreach ($campaign['collection_ids'] as $id) {
            if (!CalendarTemplate::id($id)) {
                throw new InvalidArgumentException('invalid_campaign_collection');
            }
        }
        if (count(array_unique($campaign['collection_ids'])) !== count($campaign['collection_ids'])) {
            throw new InvalidArgumentException('duplicate_campaign_collection');
        }

        $this->verifyOwnership($campaign, $ownership);

        $effective = (new CalendarTemplate())->apply($pattern, [
            'tenant_id' => $campaign['tenant_id'],
            'campaign_id' => $campaign['campaign_id'],
        ]);
        if ($effective['scope'] !== 'campaign') {
            throw new InvalidArgumentException('invalid_campaign_scope');
        }
        return [
            'tenant_id' => $campaign['tenant_id'],
            'campaign_id' => $campaign['campaign_id'],
            'account_id' => $campaign['account_id'],
            'collection_ids' => array_values($campaign['collection_ids']),
            'rule' => $effective,
            'activation_allowed' => false,
        ];
    }

    /**
     * Ownership is a trusted, server-sourced catalog snapshot injected by the
     * eventual consumer; callers must not synthesize it from campaign input.
     */
    private function verifyOwnership(array $campaign, array $ownership): void
    {
        $expected = ['account_tenant_id', 'collection_tenant_by_id'];
        $keys = array_keys($ownership);
        sort($keys);
        sort($expected);
        if ($keys !== $expected
            || $ownership['account_tenant_id'] !== $campaign['tenant_id']
            || !is_array($ownership['collection_tenant_by_id'])
            || count($ownership['collection_tenant_by_id']) !== count($campaign['collection_ids'])) {
            throw new InvalidArgumentException('invalid_campaign_ownership');
        }
        foreach ($campaign['collection_ids'] as $id) {
            if (!array_key_exists($id, $ownership['collection_tenant_by_id'])
                || $ownership['collection_tenant_by_id'][$id] !== $campaign['tenant_id']) {
                throw new InvalidArgumentException('invalid_campaign_ownership');
            }
        }
    }
}
