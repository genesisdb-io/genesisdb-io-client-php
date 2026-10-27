<?php

declare(strict_types=1);

namespace GenesisDB;

/** An event as stored and returned by GenesisDB. The property names follow CloudEvents. */
final class Event
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly string $source,
        public readonly string $subject,
        public readonly string $type,
        public readonly string $specversion,
        public readonly string $id,
        public readonly \DateTimeImmutable $time,
        public readonly string $datacontenttype,
        public readonly string $predecessorhash,
        public readonly mixed $data,
        public readonly string $hash,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            source: (string) $raw['source'],
            subject: (string) $raw['subject'],
            type: (string) $raw['type'],
            specversion: (string) ($raw['specversion'] ?? '1.0'),
            id: (string) $raw['id'],
            time: self::parseTime((string) $raw['time']),
            datacontenttype: (string) ($raw['datacontenttype'] ?? 'application/json'),
            predecessorhash: (string) ($raw['predecessorhash'] ?? ''),
            data: $raw['data'] ?? null,
            hash: (string) ($raw['hash'] ?? ''),
        );
    }

    /** RFC 3339 with any number of fractional digits. PHP keeps microseconds. */
    private static function parseTime(string $text): \DateTimeImmutable
    {
        $normalised = preg_replace_callback('/\.(\d+)/', fn (array $m) => '.' . substr(str_pad($m[1], 6, '0'), 0, 6), $text, 1);
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', (string) $normalised)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $text);
        if ($parsed === false) {
            throw new \InvalidArgumentException("not an RFC 3339 time: $text");
        }
        return $parsed;
    }
}
