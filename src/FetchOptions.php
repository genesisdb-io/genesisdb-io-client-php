<?php

declare(strict_types=1);

namespace GenesisDB;

/** Narrow a fetch or a subscription. Bounds are event IDs. */
final class FetchOptions implements \JsonSerializable
{
    /**
     * @param string|null $lowerBound skip every event before this ID. An ID that does not occur below the subject yields an empty result
     * @param string|null $upperBound stop at this ID
     * @param string|null $latestByEventType return only the newest event of this type below the subject. Ignored by subscriptions
     */
    public function __construct(
        public readonly ?string $lowerBound = null,
        public readonly ?bool $includeLowerBoundEvent = null,
        public readonly ?string $upperBound = null,
        public readonly ?bool $includeUpperBoundEvent = null,
        public readonly ?string $latestByEventType = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->jsonSerialize() === [];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'lowerBound' => $this->lowerBound,
            'includeLowerBoundEvent' => $this->includeLowerBoundEvent,
            'upperBound' => $this->upperBound,
            'includeUpperBoundEvent' => $this->includeUpperBoundEvent,
            'latestByEventType' => $this->latestByEventType,
        ], fn ($value) => $value !== null);
    }
}
