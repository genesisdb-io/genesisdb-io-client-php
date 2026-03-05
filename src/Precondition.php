<?php

namespace GenesisDB\GenesisDB;

/**
 * A precondition that must hold true before a commit is accepted.
 *
 * Use one of the factory methods for strongly-typed variants, or create
 * a generic precondition for forward compatibility with future server releases.
 */
class Precondition
{
    /**
     * @param string $type The precondition type
     * @param array<string, mixed> $payload The precondition payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
    ) {
    }

    /**
     * Ensures that no events exist yet for the given subject
     *
     * @param string $subject
     * @return self
     */
    public static function isSubjectNew(string $subject): self
    {
        return new self('isSubjectNew', ['subject' => $subject]);
    }

    /**
     * Ensures that at least one event exists for the given subject
     *
     * @param string $subject
     * @return self
     */
    public static function isSubjectExisting(string $subject): self
    {
        return new self('isSubjectExisting', ['subject' => $subject]);
    }

    /**
     * Evaluates a GDBQL query and ensures the result is truthy
     *
     * @param string $query
     * @return self
     */
    public static function isQueryResultTrue(string $query): self
    {
        return new self('isQueryResultTrue', ['query' => $query]);
    }

    /**
     * Escape-hatch for any precondition type that is not yet covered by
     * the SDK types (forward-compatible with future server releases).
     *
     * @param string $type
     * @param array<string, mixed> $payload
     * @return self
     */
    public static function generic(string $type, array $payload): self
    {
        return new self($type, $payload);
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }
}
