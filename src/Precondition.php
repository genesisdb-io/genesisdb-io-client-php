<?php

declare(strict_types=1);

namespace GenesisDB;

/** A precondition that is checked atomically with the commit. Build one with the static factories. */
final class Precondition implements \JsonSerializable
{
    /**
     * @param array<string, string> $payload
     */
    private function __construct(public readonly string $type, public readonly array $payload)
    {
    }

    /** Holds when neither the subject nor a descendant has events. */
    public static function isSubjectNew(string $subject): self
    {
        return new self('isSubjectNew', ['subject' => $subject]);
    }

    /** Holds when the subject or a descendant has events. */
    public static function isSubjectExisting(string $subject): self
    {
        return new self('isSubjectExisting', ['subject' => $subject]);
    }

    /** Holds when the GDBQL query result is true. */
    public static function isQueryResultTrue(string $query): self
    {
        return new self('isQueryResultTrue', ['query' => $query]);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'payload' => $this->payload];
    }
}
