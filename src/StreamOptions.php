<?php

namespace GenesisDB\GenesisDB;

/**
 * Options for streaming or observing events
 */
class StreamOptions
{
    /**
     * @param string|null $lowerBound Resume streaming from this event ID
     * @param bool|null $includeLowerBoundEvent Whether to include the lower-bound event itself in the results
     * @param string|null $upperBound Stop streaming at this event ID
     * @param bool|null $includeUpperBoundEvent Whether to include the upper-bound event itself in the results
     * @param string|null $latestByEventType Stream only the latest event of the given type per subject
     */
    public function __construct(
        public readonly ?string $lowerBound = null,
        public readonly ?bool $includeLowerBoundEvent = null,
        public readonly ?string $upperBound = null,
        public readonly ?bool $includeUpperBoundEvent = null,
        public readonly ?string $latestByEventType = null,
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

        if ($this->lowerBound !== null) {
            $data['lowerBound'] = $this->lowerBound;
        }

        if ($this->includeLowerBoundEvent !== null) {
            $data['includeLowerBoundEvent'] = $this->includeLowerBoundEvent;
        }

        if ($this->upperBound !== null) {
            $data['upperBound'] = $this->upperBound;
        }

        if ($this->includeUpperBoundEvent !== null) {
            $data['includeUpperBoundEvent'] = $this->includeUpperBoundEvent;
        }

        if ($this->latestByEventType !== null) {
            $data['latestByEventType'] = $this->latestByEventType;
        }

        return $data;
    }
}
