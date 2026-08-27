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
 */
class CategorySeeder extends Seeder
{
    /*
     * Fixed ids, referenced by name rather than by array position.
     *
     * Equipment points at these, and an earlier version indexed into the array
     * instead: inserting Desktops shifted every later index and silently moved
     * the projector into the wrong category. They are public because
     * EquipmentSeeder resolves against them — a constant that fails to compile
     * beats a name lookup that quietly returns null.
     */
    public const LAPTOPS = '0f2a1c34-1111-4a11-8a11-000000000001';

    public const NETWORKING = '0f2a1c34-1111-4a11-8a11-000000000002';

    public const PERIPHERALS = '0f2a1c34-1111-4a11-8a11-000000000003';

    public const DESKTOPS = '0f2a1c34-1111-4a11-8a11-000000000004';

    public function run(): void
    {
        $categories = [
            [
                'id' => self::LAPTOPS,
                'name' => 'Laptops',
                'description' => 'Portable computers issued to staff and students.',
                'default_loan_days' => 14,
                'custom_fields' => ['fields' => [
                    ['key' => 'processor', 'label' => 'Processor', 'type' => 'text', 'required' => true],
                    ['key' => 'ram', 'label' => 'RAM', 'type' => 'select', 'required' => true, 'options' => ['8GB', '16GB', '32GB']],
                    ['key' => 'storage_gb', 'label' => 'Storage (GB)', 'type' => 'number', 'required' => false],
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
                'custom_fields' => ['fields' => [
                    ['key' => 'processor', 'label' => 'Processor', 'type' => 'text', 'required' => true],
                    ['key' => 'ram', 'label' => 'RAM', 'type' => 'select', 'required' => true, 'options' => ['8GB', '16GB', '32GB']],
                    ['key' => 'monitor_size', 'label' => 'Monitor size', 'type' => 'text', 'required' => false],
                ]],
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
}
