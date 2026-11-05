<?php

namespace App\Support\Assessment;

/**
 * The outcome of measuring one indicator for one location.
 *
 * Carries its own provenance so a finding can be traced without consulting
 * the code that produced it.
 */
final readonly class IndicatorResult
{
    public const STATUS_AVAILABLE = 'available';

    /** Underlying source data is too incomplete to trust the measured value. */
    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_HIGH = 'high';

    /** The location has no data for this indicator. */
    public const STATUS_NO_DATA = 'no_data';

    /** An indicator was computed but there is no reference rate to judge it against. */
    public const STATUS_NO_BENCHMARK = 'no_benchmark';

    /**
     * @param  float|null  $value  The measured value, or null when unavailable.
     * @param  float|null  $benchmark  The reference rate, or null when none exists.
     * @param  string  $status  One of the STATUS_* constants.
     * @param  string  $reason  Human readable explanation when not available.
     * @param  string|null  $gap  Difference against the benchmark, as a percentage.
     */
    public function __construct(
        public Indicator $indicator,
        public ?float $value = null,
        public ?float $benchmark = null,
        public ?float $median = null,
        public string $status = self::STATUS_AVAILABLE,
        public string $confidence = self::CONFIDENCE_HIGH,
        public string $reason = '',
        public ?float $gap = null,
        public ?string $basisNote = null,
        public ?array $calculation = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /**
     * Whether the measurement rests on source data too incomplete to rely on.
     */
    public function isLowConfidence(): bool
    {
        return $this->confidence === self::CONFIDENCE_LOW;
    }

    /**
     * Whether this result falls short of its benchmark.
     *
     * Never returns true without both a value and a benchmark: a shortfall
     * cannot be claimed from a measurement alone.
     */
    public function isShortfall(): bool
    {
        if (! $this->isAvailable() || $this->value === null || $this->benchmark === null) {
            return false;
        }

        return $this->indicator->shortfallIsBelow()
            ? $this->value < $this->benchmark
            : $this->value > $this->benchmark;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->indicator->key,
            'label' => $this->indicator->label,
            'description' => $this->indicator->description,
            'unit' => $this->indicator->unit,
            'dimension' => $this->indicator->dimension,
            'source' => $this->indicator->source,
            'caveat' => $this->indicator->caveat,
            'value' => $this->value,
            'benchmark' => $this->benchmark,
            'median' => $this->median,
            'gap' => $this->gap,
            'status' => $this->status,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'is_shortfall' => $this->isShortfall(),
            'calculation' => $this->calculation,
        ];
    }
}
