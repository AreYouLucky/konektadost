<?php

namespace App\Support\Assessment;

/**
 * One measurable indicator.
 *
 * Definitions are declared here rather than hard-coded in the calculator so
 * that the indicator key, unit, dimension and provenance travel together and
 * cannot drift apart.
 */
final readonly class Indicator
{
    /**
     * @param  string  $key  Stable identifier used in URLs, API payloads and the benchmarks table.
     * @param  string  $unit  Display unit, e.g. "per 100,000 people".
     * @param  string  $dimension  Which assessment dimension this belongs to.
     * @param  string  $source  Where the underlying data came from.
     * @param  string|null  $coverageCheck  Names a coverage figure in the assessment
     *                                      context. When this source field is mostly
     *                                      blank for an area, the measured value is
     *                                      more likely to reflect missing data than a
     *                                      real condition, and confidence is lowered.
     * @param  float  $coverageWarnAbove  Share above which confidence is lowered.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $unit,
        public string $dimension,
        public string $source,
        public bool $higherIsBetter = true,
        public ?string $caveat = null,
        public ?string $coverageCheck = null,
        public float $coverageWarnAbove = 0.20,
        /**
         * True when the source is volunteer or crowd-sourced (currently
         * OpenStreetMap). A zero from such a source means "none recorded",
         * which is not evidence that none exists.
         */
        public bool $volunteerSourced = false,
    ) {}

    /**
     * Whether a value below the benchmark represents a shortfall.
     *
     * For density this is inverted: a very high density relative to the
     * national rate signals pressure on services rather than surplus capacity.
     */
    public function shortfallIsBelow(): bool
    {
        return $this->higherIsBetter;
    }
}
