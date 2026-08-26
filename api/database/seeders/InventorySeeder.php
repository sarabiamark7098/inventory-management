<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Equipment;
use Illuminate\Database\Seeder;

/** Starter taxonomy and assets so a fresh install has something to sync down. */
class InventorySeeder extends Seeder
{
    /*
     * Categories are referenced by these constants, never by array position:
     * inserting a category used to shift every later index and silently move
     * equipment into the wrong category.
     */
    private const CAT_LAPTOPS = '0f2a1c34-1111-4a11-8a11-000000000001';

    private const CAT_NETWORKING = '0f2a1c34-1111-4a11-8a11-000000000002';

    private const CAT_PERIPHERALS = '0f2a1c34-1111-4a11-8a11-000000000003';

    private const CAT_DESKTOPS = '0f2a1c34-1111-4a11-8a11-000000000004';

    public function run(): void
    {
        $categories = [
            [
                'id' => self::CAT_LAPTOPS,
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
                'id' => self::CAT_NETWORKING,
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
                'id' => self::CAT_DESKTOPS,
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
                'id' => self::CAT_PERIPHERALS,
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

        $this->command?->info(sprintf(
            'Seeded %d categories and %d equipment items.',
            count($categories),
        ));
    }
}
