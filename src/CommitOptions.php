<?php

declare(strict_types=1);

namespace GenesisDB;

/** Per-event options for a commit. */
final class CommitOptions implements \JsonSerializable
{
    public function __construct(public readonly bool $storeDataAsReference = false)
    {
    }

    /** @return array<string, bool> */
    public function jsonSerialize(): array
    {
        return ['storeDataAsReference' => $this->storeDataAsReference];
    }
}
