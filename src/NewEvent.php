<?php

declare(strict_types=1);

namespace GenesisDB;

/** An event to commit. ID, time, hashes and the remaining CloudEvents attributes are assigned by the server. */
final class NewEvent implements \JsonSerializable
{
    /**
     * @param string $subject must start with "/". A single segment such as "/customer" gets a generated ID appended
     * @param array<string, mixed>|\JsonSerializable|object $data a JSON object
     */
    public function __construct(
        public readonly string $source,
        public readonly string $subject,
        public readonly string $type,
        public readonly mixed $data,
        public readonly ?CommitOptions $options = null,
    ) {
    }

    /** Store the payload as reference data, so it can be erased later without breaking the hash chain. */
    public function asReference(): self
    {
        return new self($this->source, $this->subject, $this->type, $this->data, new CommitOptions(storeDataAsReference: true));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [
            'source' => $this->source,
            'subject' => $this->subject,
            'type' => $this->type,
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ];
        if ($this->options !== null) {
            $out['options'] = $this->options;
        }
        return $out;
    }
}
