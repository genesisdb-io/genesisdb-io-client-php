<?php

namespace GenesisDB\GenesisDB;

/**
 * Options for storing an event
 */
class CommitEventOptions
{
    /**
     * @param bool|null $storeDataAsReference When true, event data is stored as a separate reference that can be erased for GDPR compliance
     */
    public function __construct(
        public readonly ?bool $storeDataAsReference = null,
    ) {
    }

    /**
     * Convert to array for JSON serialization, omitting null values
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->storeDataAsReference !== null) {
            $data['storeDataAsReference'] = $this->storeDataAsReference;
        }

        return $data;
    }
}
