<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The starter taxonomy: what kinds of thing the register holds, and what each
 * kind records beyond the common columns.
 *
 * Separate from EquipmentSeeder because the two answer different questions. A
 * field office adapting this system keeps and edits the categories — they are
 * the shape of its inventory — while the equipment is sample data it deletes on
 * day one. Running one without the other has to be possible.
 *
 * The custom fields here are also what makes an import visible. The equipment
 * form renders exactly the fields its category declares, so a specification the
 * importer writes under a key no category names would be stored and searchable
 * but could never be read or edited on the equipment screen. The keys below
 * therefore match the `spec:` targets in `app/Imports/ImportProfile.php`, and
 * the two want changing together.
 *
 * A field may also name a `slot`, which lifts it out of the Specifications
 * block and places it beside the field it belongs with:
 *
 *   identity   — next to the property number (a machine's host names)
 *   monitor    — next to the monitor's serial (its property number)
 *   deployment — ahead of the actual user (their ID number)
 *
 * These are still specifications; the slot only says where the form draws
 * them. A host name read half a screen away from the property number it
 * belongs to is harder to check against the machine in front of you, and
 * checking is the whole point of an inventory.
 */
class CategorySeeder extends Seeder
{
    /*
     * Fixed ids, referenced by name rather than by array position.
     *
     * Equipment points at these, and an earlier version indexed into the array
     * instead: inserting Desktops shifted every later index and silently moved
     * the projector into the wrong category. They are public because
     * EquipmentSeeder and the import profiles resolve against them — a constant
     * that fails to compile beats a name lookup that quietly returns null.
     */
    public const LAPTOPS = '0f2a1c34-1111-4a11-8a11-000000000001';

    public const NETWORKING = '0f2a1c34-1111-4a11-8a11-000000000002';

    public const PERIPHERALS = '0f2a1c34-1111-4a11-8a11-000000000003';

    public const DESKTOPS = '0f2a1c34-1111-4a11-8a11-000000000004';

    public const TABLETS = '0f2a1c34-1111-4a11-8a11-000000000005';

    public const PRINTERS = '0f2a1c34-1111-4a11-8a11-000000000006';

    public const SCANNERS = '0f2a1c34-1111-4a11-8a11-000000000007';

    private const YES_NO = ['Yes', 'No'];

    public function run(): void
    {
        $categories = [
            [
                'id' => self::LAPTOPS,
                'name' => 'Laptops',
                'description' => 'Portable computers issued to staff and students.',
                'default_loan_days' => 14,
                'tracks_anydesk' => true,
                'custom_fields' => ['fields' => [
                    ['key' => 'processor', 'label' => 'Processor', 'type' => 'text', 'required' => false],
                    ['key' => 'ram', 'label' => 'RAM', 'type' => 'select', 'required' => false, 'options' => ['4GB', '8GB', '16GB', '32GB']],
                    ['key' => 'storage_gb', 'label' => 'Storage (GB)', 'type' => 'number', 'required' => false],
                    ...$this->hostNames(),
                    ...$this->deployment(),
                    ...$this->computer(),
                    ...$this->battery(),
                    $this->yesNo('warranty', 'Laptop warranty'),
                ]],
            ],
            [
                'id' => self::NETWORKING,
                'name' => 'Networking',
                'description' => 'Routers, switches, and access points.',
                'default_loan_days' => 30,
                'custom_fields' => ['fields' => [
                    ['key' => 'mac_address', 'label' => 'MAC Address', 'type' => 'text', 'required' => true],
                    ['key' => 'ports', 'label' => 'Port count', 'type' => 'number', 'required' => false],
                    ['key' => 'poe', 'label' => 'PoE capable', 'type' => 'boolean', 'required' => false],
                ]],
            ],
            [
                'id' => self::DESKTOPS,
                'name' => 'Desktops',
                'description' => 'Desktop sets — system unit plus monitor, each serialised.',
                'default_loan_days' => 30,
                // One asset, two serialised parts.
                'requires_monitor_serial' => true,
                'tracks_anydesk' => true,
                'custom_fields' => ['fields' => [
                    ['key' => 'processor', 'label' => 'Processor', 'type' => 'text', 'required' => false],
                    ['key' => 'ram', 'label' => 'RAM', 'type' => 'select', 'required' => false, 'options' => ['4GB', '8GB', '16GB', '32GB']],
                    ['key' => 'monitor_size', 'label' => 'Monitor size', 'type' => 'text', 'required' => false],
                    // Beside the monitor's serial number, not buried in the
                    // specifications: they identify the same physical display.
                    ['key' => 'monitorPropertyNumber', 'label' => 'Property number (monitor)', 'type' => 'text', 'required' => false, 'slot' => 'monitor'],
                    ...$this->hostNames(),
                    ...$this->deployment(),
                    ...$this->computer(),
                    $this->yesNo('warranty', 'Desktop warranty'),
                ]],
            ],
            [
                'id' => self::TABLETS,
                'name' => 'Tablets',
                'description' => 'Tablets issued to field staff for beneficiary data collection.',
                'default_loan_days' => 14,
                'custom_fields' => ['fields' => [
                    ...$this->deployment(),
                    ...$this->battery(),
                    $this->yesNo('warranty', 'Warranty'),
                ]],
            ],
            [
                'id' => self::PRINTERS,
                'name' => 'Printers',
                'description' => 'Printers, whether standalone or attached to a workstation.',
                'default_loan_days' => 7,
                'custom_fields' => ['fields' => $this->attachedPeripheral()],
            ],
            [
                'id' => self::SCANNERS,
                'name' => 'Scanners',
                'description' => 'Scanners, whether standalone or attached to a workstation.',
                'default_loan_days' => 7,
                'custom_fields' => ['fields' => $this->attachedPeripheral()],
            ],
            [
                'id' => self::PERIPHERALS,
                'name' => 'Peripherals',
                'description' => 'Projectors, monitors, and accessories.',
                'default_loan_days' => 7,
                'custom_fields' => ['fields' => [
                    ['key' => 'connector', 'label' => 'Connector', 'type' => 'select', 'required' => false, 'options' => ['HDMI', 'VGA', 'USB-C']],
                ]],
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(['id' => $category['id']], $category);
        }

        $this->command?->info(sprintf('Seeded %d categories.', count($categories)));
    }

    /** Who issued it and under what arrangement — recorded for every type. */
    private function deployment(): array
    {
        return [
            ['key' => 'program', 'label' => 'Programme', 'type' => 'text', 'required' => false, 'placeholder' => '4Ps, SLP, CIS'],
            // Ahead of the actual user, because it identifies them.
            ['key' => 'idNumber', 'label' => 'ID number', 'type' => 'text', 'required' => false, 'slot' => 'deployment'],
            ['key' => 'acquisitionType', 'label' => 'Acquisition type', 'type' => 'text', 'required' => false, 'placeholder' => 'DSWD Acquired'],
            ['key' => 'administrationType', 'label' => 'Administration type', 'type' => 'text', 'required' => false, 'placeholder' => 'User'],
            ['key' => 'operatingSystem', 'label' => 'Operating system', 'type' => 'text', 'required' => false],
        ];
    }

    /**
     * What a machine is called on the network.
     *
     * Slotted beside the property number: both are how you confirm the box in
     * front of you is the record on the screen, and an operator renaming a
     * machine is reading the sticker at the same moment.
     */
    private function hostNames(): array
    {
        return [
            ['key' => 'hostName', 'label' => 'Host name', 'type' => 'text', 'required' => false, 'slot' => 'identity'],
            ['key' => 'newHostName', 'label' => 'New host name', 'type' => 'text', 'required' => false, 'slot' => 'identity'],
        ];
    }

    /** Anything that joins the network and gets supported remotely. */
    private function computer(): array
    {
        return [
            // Free text, not a Yes/No list: the older sheets answer this with
            // "YES - 8.7", and a dropdown could not show that at all.
            ['key' => 'xdr', 'label' => 'XDR', 'type' => 'text', 'required' => false],
            ['key' => 'xdrVersion', 'label' => 'XDR version', 'type' => 'text', 'required' => false],
            $this->yesNo('sentinel', 'Sentinel'),
            $this->yesNo('keyboard', 'Keyboard'),
            $this->yesNo('mouse', 'Mouse'),
        ];
    }

    /**
     * A printer or a scanner: registered by hand like any other peripheral,
     * and created by the importer out of a desktop or laptop row.
     *
     * `attachedTo` is what keeps the pair findable after they are split: it
     * holds the asset tag of the workstation the import read it from, so the
     * printer on someone's desk can still be traced to the machine beside it
     * without the machine's record duplicating the printer's details.
     */
    private function attachedPeripheral(): array
    {
        return [
            ['key' => 'attachedTo', 'label' => 'Attached to', 'type' => 'text', 'required' => false, 'placeholder' => 'ICT-DT-0001'],
            // The shared deployment block minus the operating system, which a
            // printer does not have in any sense the register cares about.
            ...array_values(array_filter(
                $this->deployment(),
                fn (array $field) => $field['key'] !== 'operatingSystem',
            )),
            $this->yesNo('warranty', 'Warranty'),
        ];
    }

    private function battery(): array
    {
        return [
            $this->yesNo('workingCharger', 'Working charger'),
            ['key' => 'chargerSerial', 'label' => 'Charger serial', 'type' => 'text', 'required' => false],
        ];
    }

    private function yesNo(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'type' => 'select', 'required' => false, 'options' => self::YES_NO];
    }
}
