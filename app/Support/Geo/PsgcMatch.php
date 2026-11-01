<?php

namespace App\Support\Geo;

/**
 * The outcome of trying to resolve one GADM barangay to a PSGC code.
 *
 * A failed match is a real, reportable state rather than an error, so the
 * reason travels with the result and gets stored on the row.
 */
final readonly class PsgcMatch
{
    /**
     * @param  string|null  $code  The nine digit PSGC barangay code, if resolved.
     * @param  string  $status  One of the PsgcMatcher status constants.
     * @param  list<string>  $candidates  PSA names that were considered, when
     *                                    the name could not be pinned down.
     */
    public function __construct(
        public ?string $code,
        public string $status,
        public array $candidates = [],
    ) {}

    public function matched(): bool
    {
        return $this->code !== null;
    }
}
