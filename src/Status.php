<?php

declare(strict_types=1);

namespace GenesisDB;

/** The body of GET /api/v1/status. */
final class Status
{
    /**
     * @param array<string, mixed> $raw the whole document, for fields not mapped here (system, host)
     */
    public function __construct(
        public readonly string $version,
        public readonly string $channel,
        public readonly int $eventCount,
        public readonly int $subjectCount,
        public readonly int $typeCount,
        public readonly string $storageFormat,
        public readonly bool $ready,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            version: (string) ($raw['engine']['version'] ?? ''),
            channel: (string) ($raw['engine']['channel'] ?? ''),
            eventCount: (int) ($raw['events']['count'] ?? 0),
            subjectCount: (int) ($raw['events']['subjects'] ?? 0),
            typeCount: (int) ($raw['events']['types'] ?? 0),
            storageFormat: (string) ($raw['storage']['storageFormat'] ?? ''),
            ready: (bool) ($raw['storage']['ready'] ?? false),
            raw: $raw,
        );
    }
}
