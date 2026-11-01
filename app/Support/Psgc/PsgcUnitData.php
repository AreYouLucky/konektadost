<?php

namespace App\Support\Psgc;

/**
 * One geographic unit as PSA published it.
 *
 * Nothing here is derived by us except parentCorrespondenceCode, which is
 * derived from the unit's own code and level width and is validated at import.
 * Values PSA left blank stay blank; values PSA marked stay marked.
 */
final readonly class PsgcUnitData
{
    public const LEVELS = ['Reg', 'Prov', 'City', 'Mun', 'SubMun', 'Bgy'];

    /**
     * How many leading digits of the correspondence code identify the parent,
     * by level. The correspondence code is structured region / province /
     * city-or-municipality / barangay, and a unit's own level says how much
     * of it belongs to its ancestors.
     *
     * @var array<string, int>
     */
    private const PARENT_WIDTH = [
        'Reg' => 0,
        'Prov' => 2,
        'City' => 4,
        'Mun' => 4,
        'SubMun' => 4,
        'Bgy' => 6,
    ];

    public function __construct(
        public string $psgc10,
        public string $name,
        public ?string $correspondenceCode,
        public string $level,
        public ?string $oldName,
        public ?string $cityClass,
        public ?string $incomeClass,
        public ?string $urbanRural,
        public ?int $population2024,
        public ?string $status,
    ) {}

    /**
     * The parent's nine digit correspondence code, right padded with zeros.
     *
     * Derived from this unit's own correspondence code rather than by slicing
     * the 10-digit code, because the two encodings do not agree: Caloocan
     * Barangay 1 is 1380100001 but its parent is correspondence code
     * 137501000. Slicing the 10-digit code would invent a parent that does not
     * exist.
     */
    public function parentCorrespondenceCode(): ?string
    {
        $width = self::PARENT_WIDTH[$this->level] ?? null;

        if ($width === null || $width === 0 || $this->correspondenceCode === null) {
            return null;
        }

        if (strlen($this->correspondenceCode) <= $width) {
            return null;
        }

        return str_pad(substr($this->correspondenceCode, 0, $width), 9, '0', STR_PAD_RIGHT);
    }

    /**
     * Row shape for insertion.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $releaseId): array
    {
        return [
            'release_id' => $releaseId,
            'psgc10' => $this->psgc10,
            'name' => $this->name,
            'correspondence_code' => $this->correspondenceCode,
            'level' => $this->level,
            'old_name' => $this->oldName,
            'city_class' => $this->cityClass,
            'income_class' => $this->incomeClass,
            'urban_rural' => $this->urbanRural,
            'population_2024' => $this->population2024,
            'status' => $this->status,
            'parent_correspondence_code' => $this->parentCorrespondenceCode(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
