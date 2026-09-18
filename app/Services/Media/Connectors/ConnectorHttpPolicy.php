<?php

namespace App\Services\Media\Connectors;

use Illuminate\Http\Client\Response;

class ConnectorHttpPolicy
{
    public function assertAccessToken(string $accessToken): void
    {
        if ($accessToken === '') {
            throw MediaConnectorException::unauthorized();
        }
    }

    public function assertSuccessful(
        Response $response,
        bool $download = false,
    ): void {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            throw MediaConnectorException::unauthorized();
        }

        if ($response->status() === 429) {
            throw MediaConnectorException::rateLimited(
                $this->retryAfterSeconds($response),
            );
        }

        throw $download
            ? MediaConnectorException::downloadFailed()
            : MediaConnectorException::requestFailed();
    }

    /**
     * @return resource
     */
    public function detachStream(Response $response)
    {
        $stream = $response->toPsrResponse()
            ->getBody()
            ->detach();

        if (is_resource($stream) === false) {
            throw MediaConnectorException::downloadFailed();
        }

        return $stream;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter) === false) {
            return null;
        }

        return max(1, min((int) $retryAfter, 3600));
    }
}
