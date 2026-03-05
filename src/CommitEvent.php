<?php

namespace GenesisDB\GenesisDB;

/**
 * An event to commit to GenesisDB
 */
class CommitEvent
{
    /**
     * @param string $source CloudEvents source identifier
     * @param string $subject Hierarchical subject path
     * @param string $type Event type identifier
     * @param array|mixed $data Event payload data
     * @param CommitEventOptions|null $options Optional commit options
     */
    public function __construct(
        public readonly string $source,
        public readonly string $subject,
        public readonly string $type,
        public readonly mixed $data,
        public readonly ?CommitEventOptions $options = null,
    ) {
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $eventData = [
            'source' => $this->source,
            'subject' => $this->subject,
            'type' => $this->type,
            'data' => $this->data,
        ];

        if ($this->options !== null) {
            $eventData['options'] = $this->options->toArray();
        }

        return $eventData;
    }
}
