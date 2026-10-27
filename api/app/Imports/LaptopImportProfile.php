<?php

namespace App\Imports;

use Database\Seeders\CategorySeeder;

/** Laptops: a computer that also travels, so the charger is tracked too. */
final class LaptopImportProfile extends ImportProfile
{
    public function name(): string
    {
        return 'laptop';
    }

    public function label(): string
    {
        return 'Laptops';
    }

    public function description(): string
    {
        return 'Laptops and notebooks, including the charger and warranty detail the workbook tracks.';
    }

    public function categoryId(): string
    {
        return CategorySeeder::LAPTOPS;
    }

    public function assetTagPrefix(): string
    {
        return 'ICT-LT';
    }

    protected function orderedColumns(): array
    {
        return [
            $this->assetTag(),
            $this->propertyNumber(),
            ...$this->hostNames(),
            ...$this->basics(),
            ...$this->charger(),
            ...$this->accountability(),
            ...$this->support(),
            ...$this->peripherals(),
            ...$this->warranties('Laptop Warranty'),
        ];
    }

    /**
     * A laptop's template has no monitor columns, but the field office's own
     * laptop sheet records a serial for the display on a docked machine.
     *
     * Only the serial is still accepted. The monitor's *property* number went
     * with the Laptops category's field for it — a laptop is not a two-part
     * asset the way a desktop set is — so the workbook's combined
     * `Monitor Property No. / SN No.` is now reported as unrecognised rather
     * than half-written somewhere nothing displays.
     */
    protected function acceptedColumns(): array
    {
        return [
            ...parent::acceptedColumns(),
            new ImportColumn(
                label: 'Monitor Serial Number',
                targets: ['monitorSerialNumber'],
                aliases: ['Monitor SN', 'Monitor Serial'],
                template: false,
                kind: 'identifier',
            ),
        ];
    }
}
