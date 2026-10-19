<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use Closure;
use Doctrine\DBAL\Connection;

final readonly class InstagramReelPublicationService
{
    public function __construct(
        private Connection $db,
        private InstagramReelProvider $provider,
        private ?Closure $clock = null,
    ) {}

    /**
     * Reutiliza gf_external_publication_attempts y la misma semántica del primer
     * provider. Un estado ambiguous/in_flight nunca reenvía contenido.
     */
    public function publish(DistributionCommand $command): DistributionOutcome
    {
        $this->provider->assertAvailableFor($command->organizationId);
        $ledger = new ExternalPublicationLedger($this->db, $this->clock);
        return $ledger->publish(
            $command,
            'instagram_reels',
            'instagram_user_id',
            $this->provider->destinationId(),
            fn (): DistributionOutcome => $this->provider->publish($command),
        );
    }
}
