<?php

namespace App\Support\Geo;

/**
 * Resolves GADM barangays to Philippine Standard Geographic Code numbers.
 *
 * GADM and PSA disagree about names, so a straight comparison matches only
 * about two thirds of the country. The differences are systematic and each is
 * handled explicitly in comparisonKey():
 *
 *  - GADM writes names in CamelCase ("DanacEast") where PSA separates words.
 *  - PSA keeps diacritics ("Santo Niño", "Agusan Pequeño"); GADM drops them.
 *  - PSA marks the poblacion as "(Pob.)", "Pob." or spells it out.
 *  - GADM prefixes some names with "Bo." and numbers zones ("Zone1Poblacion").
 *
 * A barangay is identified by its parent city/municipality code plus its name.
 * Name alone is unsafe: "San Isidro" appears in hundreds of municipalities.
 *
 * Whatever cannot be matched is reported rather than guessed, so a partial
 * backfill stays auditable.
 */
class PsgcMatcher
{
    /** Resolved cleanly. */
    public const MATCHED = 'matched';

    /** The parent municipality has no PSGC code of its own. */
    public const NO_PARENT_CODE = 'no_parent_psgc_code';

    /** The parent code is not present in the PSGC release. */
    public const PARENT_UNKNOWN = 'parent_not_in_release';

    /** The parent resolved but no PSA barangay carried a comparable name. */
    public const NAME_NOT_FOUND = 'name_not_found';

    /** Several PSA barangays matched, so no code can be trusted. */
    public const AMBIGUOUS = 'ambiguous_name';

    /**
     * @param  array<string, array<string, list<string>>>  $byParent
     *                                                                Parent code => comparison key => PSA codes sharing that key.
     * @param  array<string, array<string, list<string>>>  $byParentCompact
     *                                                                As above, keyed on the comparison key with all
     *                                                                spaces removed, for the fallback pass.
     */
    public function __construct(
        private readonly array $byParent,
        private readonly array $byParentCompact = [],
    ) {}

    /**
     * Build a matcher from PSGC rows.
     *
     * Kept free of database access so the matching rules can be tested on their
     * own; PsgcUnitIndex reads the release and hands the rows over.
     *
     * Each row is
     *   ['code' => 9-digit code, 'parent' => 6-digit city/mun code,
     *    'name' => PSA name, 'oldName' => former PSA name or null].
     *
     * A former name is indexed alongside the current one because PSA records
     * renames, and GADM tends to carry the older spelling. It points at the same
     * code, so it can only ever resolve to the unit it belongs to.
     *
     * @param  list<array<string, string|null>>  $rows
     */
    public static function fromRows(array $rows): self
    {
        $byParent = [];
        $byParentCompact = [];

        foreach ($rows as $row) {
            if (! isset($row['code'], $row['parent'])) {
                continue;
            }

            $parent = (string) $row['parent'];
            $code = (string) $row['code'];

            $names = [(string) ($row['name'] ?? '')];

            if (($row['oldName'] ?? '') !== '') {
                $names[] = (string) $row['oldName'];
            }

            foreach ($names as $name) {
                if (trim($name) === '') {
                    continue;
                }

                $key = self::comparisonKey($name);

                $byParent[$parent][$key] ??= [];

                if (! in_array($code, $byParent[$parent][$key], true)) {
                    $byParent[$parent][$key][] = $code;
                }

                $compact = self::compactKey($key);

                if ($compact !== '') {
                    $byParentCompact[$parent][$compact] ??= [];

                    if (! in_array($code, $byParentCompact[$parent][$compact], true)) {
                        $byParentCompact[$parent][$compact][] = $code;
                    }
                }
            }
        }

        return new self($byParent, $byParentCompact);
    }

    /**
     * Resolve one barangay, trying each candidate name in turn.
     *
     * @param  string|null  $parentCode  Parent city/municipality PSGC code, in
     *                                   any width; padded to six digits here.
     * @param  string  ...$names  Usually the GADM name then its variant.
     */
    public function match(?string $parentCode, string ...$names): PsgcMatch
    {
        $parent = self::normaliseParentCode($parentCode);

        if ($parent === null) {
            return new PsgcMatch(null, self::NO_PARENT_CODE);
        }

        if (! isset($this->byParent[$parent])) {
            return new PsgcMatch(null, self::PARENT_UNKNOWN);
        }

        foreach ($names as $name) {
            if (trim($name) === '') {
                continue;
            }

            $key = self::comparisonKey($name);

            if (isset($this->byParent[$parent][$key])) {
                $codes = $this->byParent[$parent][$key];

                if (count($codes) === 1) {
                    return new PsgcMatch($codes[0], self::MATCHED);
                }

                // Two PSA barangays reduce to the same name, so choosing one would
                // be a guess.
                return new PsgcMatch(null, self::AMBIGUOUS, $codes);
            }
        }

        // Second pass. GADM glues small connector words into the word before
        // them, so "Agusan del Norte" arrives as "AgusandelNorte" and the word
        // split above cannot recover the space. Comparing with all spaces
        // removed recovers those names without loosening the earlier pass.
        foreach ($names as $name) {
            $compact = self::compactKey(self::comparisonKey($name));

            if ($compact === '' || ! isset($this->byParentCompact[$parent][$compact])) {
                continue;
            }

            $codes = $this->byParentCompact[$parent][$compact];

            if (count($codes) === 1) {
                return new PsgcMatch($codes[0], self::MATCHED);
            }

            return new PsgcMatch(null, self::AMBIGUOUS, $codes);
        }

        return new PsgcMatch(null, self::NAME_NOT_FOUND, array_keys($this->byParent[$parent]));
    }

    /**
     * A comparison key with spaces removed.
     *
     * Used only as a second pass, so that GADM's glued connector words
     * ("AgusandelNorte") still reduce to the same key as PSA's spaced form
     * ("Agusan del Norte").
     */
    public static function compactKey(string $key): string
    {
        return str_replace(' ', '', $key);
    }

    /**
     * PSGC city/municipality codes are six digits, but our municipalities store
     * Iloilo as "63022" rather than "063022".
     */
    public static function normaliseParentCode(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Reduce a barangay name to a comparable form.
     */
    public static function comparisonKey(string $name): string
    {
        $name = trim($name);

        // GADM CamelCase versus PSA spaced words.
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<=[A-Za-z])(?=\d)/u', ' ', $name) ?? $name;
        $name = preg_replace('/(?<=\d)(?=[A-Za-z])/u', ' ', $name) ?? $name;

        // PSA keeps diacritics, GADM does not. Only the letters PSA actually
        // uses are listed; a full transliteration would be overkill.
        $name = strtr($name, [
            'ñ' => 'n', 'Ñ' => 'N',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);

        $name = mb_strtolower($name);

        // Poblacion markers, in the three forms PSA uses.
        $name = preg_replace('/\s*\(pob\.?\)\s*$/u', '', $name) ?? $name;
        $name = preg_replace('/\s*\(poblacion\)\s*$/u', '', $name) ?? $name;
        $name = preg_replace('/\s+pob\.?\s*$/u', ' poblacion', $name) ?? $name;

        // GADM's "Bo." prefix.
        $name = preg_replace('/^bo\.?\s+/u', '', $name) ?? $name;

        $name = str_replace(['.', ',', '-'], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * Parent codes present in the release.
     *
     * @return list<string>
     */
    public function parentCodes(): array
    {
        return array_keys($this->byParent);
    }
}
