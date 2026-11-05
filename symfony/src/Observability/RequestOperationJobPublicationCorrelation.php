<?php

declare(strict_types=1);

namespace GrindFlow\Observability;

use DateTimeImmutable;
use DateTimeZone;
use GrindFlow\Distribution\DistributionCommand;
use GrindFlow\Distribution\DistributionProviderException;
use GrindFlow\Distribution\FacebookPagePublicationService;
use GrindFlow\Infrastructure\Scheduler\JobRunEvidence;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * In-process correlation for an explicitly trusted, synthetic execution path.
 * It is not an HTTP controller, durable queue, logger or production worker hook.
 * The caller must resolve tenant membership before constructing this context.
 */
final class RequestOperationJobPublicationCorrelation
{
    private const ERROR = 'INVALID_REQUEST_JOB_PUBLICATION_CORRELATION';

    private bool $consumed = false;

    private function __construct(
        public readonly string $requestId,
        public readonly string $operationId,
        public readonly string $jobId,
        public readonly string $publicationId,
        private readonly DistributionCommand $command,
        private readonly string $jobEvidenceKey,
    ) {}

    /**
     * Bind only the server-generated RequestIdSubscriber attribute to a command
     * scoped by an already-verified organization. Never read request headers,
     * cookies or body for correlation identifiers or tenant authority.
     */
    public static function fromTrustedRequest(
        Request $request,
        string $verifiedOrganizationId,
        DistributionCommand $command,
    ): self {
        $requestId = $request->attributes->get('_grindflow_request_id');
        if (!is_string($requestId)
            || preg_match('/\Areq_[a-f0-9]{24}\z/D', $requestId) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $verifiedOrganizationId) !== 1
            || !hash_equals(strtolower($verifiedOrganizationId), strtolower($command->organizationId))) {
            throw new InvalidArgumentException(self::ERROR);
        }

        return new self(
            $requestId,
            'op_'.bin2hex(random_bytes(12)),
            'job_'.bin2hex(random_bytes(12)),
            'pub_'.bin2hex(random_bytes(12)),
            $command,
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * Exercise the real publication handler and its DB ledger with a caller-
     * supplied fake transport in tests. JobRunEvidence is process-local only.
     * Neither the provider response nor the command/payload enters the result.
     *
     * @return array{schema_version:int,request_id:string,operation_id:string,job_id:string,publication_id:string,status:string,operational_ready:bool,receipts:list<array<string,mixed>>}
     */
    public function execute(FacebookPagePublicationService $handler, JobRunEvidence $jobs): array
    {
        if ($this->consumed) {
            throw new InvalidArgumentException(self::ERROR);
        }
        $this->consumed = true;
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$jobs->begin('publication', $this->jobEvidenceKey, 1, $started)) {
            throw new InvalidArgumentException(self::ERROR);
        }

        $status = 'succeeded';
        $receipts = [];
        try {
            $handler->publish($this->command);
            $jobs->finish($this->jobEvidenceKey, 1, 'succeeded', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        } catch (Throwable $error) {
            $jobs->finish($this->jobEvidenceKey, 1, 'failed', new DateTimeImmutable('now', new DateTimeZone('UTC')));
            if (!$error instanceof DistributionProviderException) {
                throw $error;
            }

            $status = 'failed';
            $jobMetadata = [
                'event_id' => 'evt_'.bin2hex(random_bytes(12)),
                'kind' => 'JOB_FAILURE',
                'state' => 'FAILED',
                'request_id' => $this->requestId,
                'job_id' => $this->jobId,
            ];
            $receipts[] = DiagnosticReceipt::fromTrustedMetadata($jobMetadata);
            $receipts[] = DiagnosticReceipt::fromTrustedMetadata([
                'event_id' => 'evt_'.bin2hex(random_bytes(12)),
                'kind' => 'PUBLICATION_FAILURE',
                'state' => 'FAILED',
                'request_id' => $this->requestId,
                'job_id' => $this->jobId,
                'publication_id' => $this->publicationId,
            ]);
        }

        return [
            'schema_version' => 1,
            'request_id' => $this->requestId,
            'operation_id' => $this->operationId,
            'job_id' => $this->jobId,
            'publication_id' => $this->publicationId,
            'status' => $status,
            'operational_ready' => false,
            'receipts' => $receipts,
        ];
    }
}
