<?php

namespace App\Imports;

/**
 * An import profile: the column set for one kind of ICT equipment.
 *
 * There is a profile per type rather than one universal importer because the
 * field office's own workbook has a sheet per type and they genuinely differ —
 * a desktop records a monitor and a host name, a tablet records neither but
 * does record a charger. One combined column set would make every type's
 * required fields optional, which is the same as validating nothing.
 *
 * Each profile lists its columns **in the order the generated template emits
 * them**, built from the small factories below. Order is cosmetic to the
 * importer, which matches on names, but it is not cosmetic to the person
 * filling the sheet in, so it mirrors the templates the field office works from.
 *
 * Adding a type (printers and scanners are the obvious next ones) means one
 * subclass naming its columns, plus a line in ImportProfileRegistry.
 */
abstract class ImportProfile
{
    /** URL-safe identifier, e.g. `desktop`. */
    abstract public function name(): string;

    /** Human label for the UI. */
    abstract public function label(): string;

    /** One sentence describing what belongs in this import. */
    abstract public function description(): string;

    /** The category every row of this import is filed under. */
    abstract public function categoryId(): string;

    /** Prefix for generated asset tags, e.g. `ICT-DT`. */
    abstract public function assetTagPrefix(): string;

    /** The template's columns, in the order they are written. */
    abstract protected function orderedColumns(): array;

    /**
     * The profile's full column set: what the template offers, then the
     * spellings that are accepted but never suggested.
     *
     * @return list<ImportColumn>
     */
    public function columns(): array
    {
        return [...$this->orderedColumns(), ...$this->acceptedColumns()];
    }

    /**
     * Headers accepted without being offered.
     *
     * These are what the field office's original workbook uses. Keeping them
     * means a sheet exported straight from it still imports, while the template
     * stays the cleaner shape — one value per column, and no `Status`, which
     * belongs on the import form where it is set once for the whole file.
     *
     * A legacy column only survives here while a category still has somewhere
     * to put it. The workbook's `Description` and its combined
     * `Keyboard / Mouse Available ( K / M )` no longer do, so they are reported
     * as unrecognised rather than written into a field the equipment screen
     * cannot show.
     *
     * @return list<ImportColumn>
     */
    protected function acceptedColumns(): array
    {
        return [
            new ImportColumn(
                label: 'Status',
                targets: ['status'],
                aliases: ['Equipment Status'],
                template: false,
                kind: 'status',
            ),
            // The workbook packs both identifiers into one cell as
            // "property / serial"; split in that order.
            new ImportColumn(
                label: 'PROPERTY NO / SN No.',
                targets: ['propertyNumber', 'serialNumber'],
                aliases: ['Property No / SN No', 'Property Number / Serial Number', 'PROPERTY NO / SN NO.'],
                template: false,
                kind: 'identifier',
            ),
        ];
    }

    /* ------------------------------ factories ----------------------------- */

    protected function assetTag(): ImportColumn
    {
        return new ImportColumn(
            label: 'Asset Tag',
            targets: ['assetTag'],
            aliases: ['Asset Tag No', 'Asset Number', 'Tag'],
            kind: 'identifier',
            example: '',
            note: 'Leave blank and the system assigns the next '.$this->assetTagPrefix().'-#### tag.',
        );
    }

    protected function propertyNumber(): ImportColumn
    {
        return new ImportColumn(
            label: 'Property Number',
            targets: ['propertyNumber'],
            aliases: ['Property No', 'Property No.', 'PROPERTY NO', 'Property #'],
            required: true,
            kind: 'identifier',
            example: 'SAMPLE-FO11-CO1-26-0001',
            note: 'The official government property number. Unique across the register.',
        );
    }

    /** Date, make and model, plus the serial that identifies the unit itself. */
    protected function basics(): array
    {
        return [
            new ImportColumn(
                label: 'Date Acquired',
                targets: ['dateAcquired'],
                // The source workbook has misspelt this since at least 2024.
                aliases: ['Date Aquired', 'Acquisition Date', 'Date of Acquisition'],
                required: true,
                kind: 'date',
                example: '2026-01-20',
                note: 'YYYY-MM-DD is safest. A slash date is only read when one part is above 12.',
            ),
            new ImportColumn(
                label: 'Brand',
                targets: ['brand'],
                aliases: ['BRAND', 'Make', 'Manufacturer'],
                example: 'Acer',
            ),
            new ImportColumn(
                label: 'Model',
                targets: ['modelName'],
                aliases: ['Model Name', 'MODEL'],
                required: true,
                example: 'Veriton X4710G',
            ),
            new ImportColumn(
                label: 'Serial Number',
                targets: ['serialNumber'],
                aliases: ['SN No', 'SN No.', 'SERIAL NUMBER', 'Serial'],
                kind: 'identifier',
                example: 'SAMPLE-SN-0001',
            ),
        ];
    }

    /** Where the asset sits, and who answers for it. */
    protected function accountability(): array
    {
        return [
            new ImportColumn(
                label: 'Division',
                targets: ['division'],
                aliases: ['Division Name', 'Division Code', 'Office'],
                required: true,
                example: 'XI-FO-PPD',
                note: 'Office code or name from the register. Set a default on the import form to leave this blank.',
            ),
            new ImportColumn(
                label: 'Section',
                targets: ['section'],
                aliases: ['Section Name', 'Section Code'],
                example: 'XI-FO-PPD-ICT',
                note: 'Optional, and must belong to the division above.',
            ),
            new ImportColumn(
                label: 'Program / Section',
                targets: ['spec:program'],
                aliases: ['Program/ Section', 'Program', 'PROGRAM'],
                example: '4Ps',
                note: 'The programme the asset serves (4Ps, SLP, CIS). Kept separately from the office register.',
            ),
            new ImportColumn(
                label: 'Province',
                targets: ['province'],
                aliases: ['PROVINCE'],
                example: 'Davao Oriental',
            ),
            new ImportColumn(
                label: 'Location',
                targets: ['location'],
                aliases: ['Site Name', 'SITE NAME', 'Site'],
                example: 'MOO Banay-Banay',
            ),
            new ImportColumn(
                label: 'PAR Issued To',
                targets: ['parIssuedTo'],
                aliases: ['PAR / Assigned to', 'PAR', 'Assigned To', 'PAR Assigned To'],
                required: true,
                example: 'Juan M. Dela Cruz',
                note: 'The accountable officer named on the Property Acknowledgement Receipt.',
            ),
            new ImportColumn(
                label: 'Sub-PAR Issued To',
                targets: ['subParIssuedTo'],
                aliases: ['Sub-PAR', 'Sub PAR Issued To'],
                example: '',
            ),
            new ImportColumn(
                label: 'Actual User',
                targets: ['actualUser'],
                aliases: ['Sub-PAR / Actual User', 'ACTUAL USER', 'User'],
                example: 'Maria S. Santos',
                note: 'Who is really using it, when that is not the accountable officer.',
            ),
            new ImportColumn(
                label: 'Position',
                targets: ['position'],
                aliases: ['POSITION', 'Designation'],
                example: 'Project Development Officer II',
            ),
            new ImportColumn(
                label: 'ID Number',
                targets: ['spec:idNumber'],
                aliases: ['Employee ID', 'ID No'],
                example: '',
                note: "The user's employee ID. Format it as text in the spreadsheet, or Excel turns 6789-11 into a date.",
            ),
            new ImportColumn(
                label: 'Condition',
                targets: ['condition'],
                aliases: ['Service Status', 'SERVICE STATUS'],
                kind: 'condition',
                example: 'GOOD',
                note: 'NEW, GOOD, FAIR, POOR or DAMAGED. "Serviceable" and "Unserviceable" are accepted.',
            ),
            new ImportColumn(
                label: 'Remarks',
                targets: ['remarks'],
                aliases: ['REMARKS', 'Notes'],
                example: '',
            ),
            new ImportColumn(
                label: 'Acquisition Type',
                targets: ['spec:acquisitionType'],
                aliases: ['ACQUISITION TYPE'],
                example: 'DSWD Acquired',
            ),
            new ImportColumn(
                label: 'Administration Type',
                targets: ['spec:administrationType'],
                aliases: ['ADMINISTRATION TYPE'],
                example: 'User',
            ),
            new ImportColumn(
                label: 'Operating System',
                targets: ['spec:operatingSystem'],
                aliases: ['OS'],
                example: 'Windows',
            ),
        ];
    }

    /** What a machine on the network is called. */
    protected function hostNames(): array
    {
        return [
            new ImportColumn(
                label: 'Host Name',
                targets: ['spec:hostName'],
                aliases: ['Computer/Host Name', 'Computer Name', 'Hostname'],
                example: '11-PPP-WS019',
            ),
            new ImportColumn(
                label: 'New Host Name',
                targets: ['spec:newHostName'],
                aliases: ['NEW HOST NAME'],
                example: '',
            ),
        ];
    }

    /** How support reaches the machine, and what is protecting it. */
    protected function support(): array
    {
        return [
            new ImportColumn(
                label: 'AD User',
                targets: ['adUsername'],
                aliases: ['AD USER', 'Active Directory User', 'AD Username'],
                example: 'jmdelacruz',
            ),
            new ImportColumn(
                label: 'AnyDesk Number',
                targets: ['anydeskNumber'],
                aliases: ['AnyDesk', 'ANYDESK', 'AnyDesk Address'],
                example: '',
            ),
            new ImportColumn(
                label: 'XDR',
                targets: ['spec:xdr'],
                kind: 'yesno',
                example: 'Yes',
            ),
            new ImportColumn(
                label: 'XDR Version',
                targets: ['spec:xdrVersion'],
                aliases: ['XDR Ver'],
                example: '8.7',
            ),
            /*
             * Legacy: the old sheet put agent and version in one cell as
             * "YES - 8.7". It is kept whole rather than guessed apart — a
             * hyphen is not a reliable separator, and the field is free text
             * precisely so a value like that survives.
             */
            new ImportColumn(
                label: 'With XDR',
                targets: ['spec:xdr'],
                template: false,
            ),
            new ImportColumn(
                label: 'Sentinel',
                targets: ['spec:sentinel'],
                aliases: ['SENTINEL 2026', 'Sentinel 2026'],
                kind: 'yesno',
                example: 'No',
            ),
            new ImportColumn(
                label: 'Keyboard',
                targets: ['spec:keyboard'],
                kind: 'yesno',
                example: 'Yes',
            ),
            new ImportColumn(
                label: 'Mouse',
                targets: ['spec:mouse'],
                kind: 'yesno',
                example: 'Yes',
            ),
        ];
    }

    /**
     * The printer and scanner attached to a workstation.
     *
     * These columns do not describe the computer — they describe two other
     * pieces of equipment that happen to sit next to it, each with its own
     * property number and its own accountable officer. So they are not stored
     * as specifications on the machine; the importer reads them and registers
     * a Printer and a Scanner of their own, inheriting the division, the PAR
     * holder and the acquisition date from the row they came from.
     *
     * The `printer:` and `scanner:` prefixes mark a target as belonging to a
     * derived record rather than to the row's own asset.
     */
    protected function peripherals(): array
    {
        return [
            new ImportColumn(
                label: 'Printer',
                targets: ['printer:modelName'],
                aliases: ['PRINTER'],
                example: '',
                note: 'The make and model. Given a property number too, it is registered as its own asset.',
            ),
            new ImportColumn(
                label: 'Printer Property',
                targets: ['printer:propertyNumber'],
                aliases: ['Printer Property No', 'Printer Property #'],
                kind: 'identifier',
                example: '',
                note: 'Required before a printer can be registered in its own right.',
            ),
            new ImportColumn(
                label: 'Printer Serial',
                targets: ['printer:serialNumber'],
                aliases: [
                    'Printer Serial Number',
                    'PRINTER PROPERTY # / SERIAL NUMBER /',
                    'PRINTER SERIAL NUMBER / PROPERTY #',
                ],
                kind: 'identifier',
                example: '',
            ),
            new ImportColumn(
                label: 'Scanner',
                targets: ['scanner:modelName'],
                aliases: ['SCANNER'],
                example: '',
            ),
            new ImportColumn(
                label: 'Scanner Property',
                targets: ['scanner:propertyNumber'],
                aliases: ['Scanner Property No', 'Scanner Property #'],
                kind: 'identifier',
                example: '',
            ),
            new ImportColumn(
                label: 'Scanner Serial',
                targets: ['scanner:serialNumber'],
                aliases: ['Scanner Serial Number', 'SCANNER SERIAL NUMBER / PROPERTY #'],
                kind: 'identifier',
                example: '',
            ),
            new ImportColumn(
                label: 'Peripheral Remarks',
                targets: ['peripheral:remarks'],
                aliases: ['Printer / Scanner Remarks'],
                example: '',
                note: 'Written onto whichever of the two is registered from this row.',
            ),
        ];
    }

    /** The charger, for anything that runs on a battery. */
    protected function charger(): array
    {
        return [
            new ImportColumn(
                label: 'Working Charger',
                targets: ['spec:workingCharger'],
                aliases: ['WORKING CHARGER', 'Charger'],
                kind: 'yesno',
                example: 'Yes',
            ),
            new ImportColumn(
                label: 'Charger Serial Number',
                targets: ['spec:chargerSerial'],
                aliases: ['Charger SN No.', 'Charger SN', 'CHARGER SN NO.'],
                kind: 'identifier',
                example: '',
            ),
        ];
    }

    /** A yes/no column. */
    protected function yesNo(string $label, string $target, array $aliases = [], string $example = 'No'): ImportColumn
    {
        return new ImportColumn(
            label: $label,
            targets: [$target],
            aliases: $aliases,
            kind: 'yesno',
            example: $example,
        );
    }

    /**
     * The warranty columns a profile ends with.
     *
     * The asset's own warranty is labelled per type — "Desktop Warranty",
     * "Laptop Warranty", plain "Warranty" — because that is what the field
     * office's sheets say, but all three mean the same field and share a target.
     */
    protected function warranties(string $ownLabel): array
    {
        return [
            $this->yesNo($ownLabel, 'spec:warranty', ['Warranty', 'WARRANTY']),
            // These belong to the printer and the scanner, not to the machine,
            // so they travel with the records derived from this row.
            $this->yesNo('Printer Warranty', 'printer:spec:warranty'),
            $this->yesNo('Scanner Warranty', 'scanner:spec:warranty'),
        ];
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'label' => $this->label(),
            'description' => $this->description(),
            'categoryId' => $this->categoryId(),
            'assetTagPrefix' => $this->assetTagPrefix(),
            'columns' => array_map(fn (ImportColumn $c) => $c->toArray(), $this->columns()),
        ];
    }
}
