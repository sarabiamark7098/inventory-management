<?php

namespace App\Imports;

use Database\Seeders\CategorySeeder;

/** Desktop sets: a system unit and a monitor, each serialised, as one asset. */
final class DesktopImportProfile extends ImportProfile
{
    public function name(): string
    {
        return 'desktop';
    }

    public function label(): string
    {
        return 'Desktops';
    }

    public function description(): string
    {
        return 'Desktop sets — system unit plus monitor. Both serial numbers are required, because the category records them as one asset.';
    }

    public function categoryId(): string
    {
        return CategorySeeder::DESKTOPS;
    }

    public function assetTagPrefix(): string
    {
        return 'ICT-DT';
    }

    protected function orderedColumns(): array
    {
        return [
            $this->assetTag(),
            $this->propertyNumber(),
            ...$this->hostNames(),
            ...$this->basics(),
            ...$this->accountability(),
            ...$this->monitor(),
            ...$this->support(),
            ...$this->peripherals(),
            ...$this->warranties('Desktop Warranty'),
        ];
    }

    /**
     * The display, which is the second serialised half of the asset.
     *
     * Its serial is a real column rather than a specification because the
     * category is flagged `requires_monitor_serial`, and because the scanner
     * has to resolve a monitor's sticker to this record.
     */
    private function monitor(): array
    {
        return [
            new ImportColumn(
                label: 'Monitor Serial Number',
                targets: ['monitorSerialNumber'],
                aliases: ['Monitor SN', 'Monitor Serial'],
                kind: 'identifier',
                example: 'SAMPLE-MON-0001',
            ),
            new ImportColumn(
                label: 'Monitor Property Number',
                targets: ['spec:monitorPropertyNumber'],
                aliases: ['Monitor Property No'],
                kind: 'identifier',
                example: '',
            ),
            /*
             * The workbook's combined monitor column. Its header reads
             * "property / serial", but in practice the cell holds a bare
             * monitor serial — `MMLY7SS02264908B0D85BA`, never an `FO11-…`
             * property number — so an unseparated value is read as the serial.
             */
            new ImportColumn(
                label: 'Monitor Property No. / SN No.',
                targets: ['spec:monitorPropertyNumber', 'monitorSerialNumber'],
                aliases: ['Monitor Property No / SN No', 'MONITOR PROPERTY NO. / SN NO.'],
                template: false,
                soleTarget: 'monitorSerialNumber',
                kind: 'identifier',
            ),
        ];
    }
}
