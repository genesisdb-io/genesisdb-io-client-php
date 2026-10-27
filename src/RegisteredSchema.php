<?php

declare(strict_types=1);

namespace GenesisDB;

/** One entry of the schema list. */
final class RegisteredSchema
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public readonly string $type, public readonly array $schema)
    {
    }
}
