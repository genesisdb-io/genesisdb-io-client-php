<?php

declare(strict_types=1);

namespace GenesisDB;

/** A commit was rejected because a precondition did not hold (412). Nothing was written. */
final class PreconditionFailedException extends GenesisDBException
{
    /** The precondition type that failed, for example "isSubjectNew". */
    public readonly string $preconditionType;

    /** The server's explanation. */
    public readonly string $reason;

    public function __construct(string $operation, string $body)
    {
        parent::__construct($operation, 412, $body);
        $parsed = json_decode($body, true);
        $this->preconditionType = is_array($parsed) ? (string) ($parsed['type'] ?? '') : '';
        $this->reason = is_array($parsed) && isset($parsed['message']) ? (string) $parsed['message'] : trim($body);
    }
}
