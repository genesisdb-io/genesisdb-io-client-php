<?php

declare(strict_types=1);

namespace GenesisDB;

/**
 * A non-2xx answer from GenesisDB, or a transport failure. Subclasses classify the status.
 */
class GenesisDBException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly int $status,
        public readonly string $body,
        ?\Throwable $previous = null,
    ) {
        $trimmed = trim($body);
        parent::__construct(sprintf('%s: %d %s', $operation, $status, $trimmed === '' ? 'no response body' : $trimmed), $status, $previous);
    }

    public static function forResponse(string $operation, int $status, string $body): self
    {
        return match ($status) {
            401, 403 => new AuthenticationException($operation, $status, $body),
            404 => new NotFoundException($operation, $status, $body),
            409 => new ConflictException($operation, $status, $body),
            412 => new PreconditionFailedException($operation, $body),
            422 => new UnprocessableException($operation, $status, $body),
            503 => new NotReadyException($operation, $status, $body),
            default => new self($operation, $status, $body),
        };
    }
}
