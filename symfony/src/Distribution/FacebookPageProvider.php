<?php

declare(strict_types=1);

namespace GrindFlow\Distribution;

use JsonException;
use Throwable;

final readonly class FacebookPageProvider implements DistributionProvider
{
    public function __construct(
        private FacebookPageConfiguration $configuration,
        private FacebookPageTransport $transport,
    ) {}

    public function assertAvailableFor(string $organizationId): void
    {
        $this->configuration->assertAvailableFor($organizationId);
    }

    public function destinationPageId(): string
    {
        return $this->configuration->pageId();
    }

    public function publish(DistributionCommand $command): DistributionOutcome
    {
        $this->assertAvailableFor($command->organizationId);

        $payload = ['message' => $command->message];
        if ($command->link !== null) {
            $payload['link'] = $command->link;
        }

        try {
            $response = $this->transport->postFeed(
                $this->configuration->graphVersion(),
                $this->configuration->pageId(),
                $this->configuration->accessToken(),
                $payload,
            );
        } catch (Throwable) {
            throw DistributionProviderException::ambiguous();
        }

        $status = $response['status'];
        if ($status >= 200 && $status < 300) {
            try {
                $decoded = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw DistributionProviderException::ambiguous();
            }

            $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
            if (!is_string($id) || trim($id) === '' || mb_strlen($id) > 191) {
                throw DistributionProviderException::ambiguous();
            }

            return new DistributionOutcome($id);
        }

        if (in_array($status, [401, 403], true)) {
            throw DistributionProviderException::authentication();
        }

        if ($status === 429) {
            throw DistributionProviderException::rateLimited(
                $this->retryAfterSeconds($response['headers']),
            );
        }

        if ($status >= 500) {
            throw DistributionProviderException::ambiguous();
        }

        throw DistributionProviderException::rejected();
    }

    /** @param array<string,string> $headers */
    private function retryAfterSeconds(array $headers): int
    {
        $value = $headers['retry-after'] ?? null;
        if (!is_string($value) || preg_match('/^\d{1,5}$/', $value) !== 1) {
            return 300;
        }

        return max(60, min((int) $value, 3600));
    }
}
