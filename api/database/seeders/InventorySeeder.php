<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Equipment;
use Illuminate\Database\Seeder;

/** Starter taxonomy and assets so a fresh install has something to sync down. */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'id' => '0f2a1c34-1111-4a11-8a11-000000000001',
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
                'id' => '0f2a1c34-1111-4a11-8a11-000000000002',
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
                'id' => '0f2a1c34-1111-4a11-8a11-000000000003',
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

        $equipment = [
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000001',
                'asset_tag' => 'ICT-LT-0001',
                'serial_number' => 'PF3ZK9QA',
                'category_id' => $categories[0]['id'],
                'model_name' => 'ThinkPad T14 Gen 4',
                'brand' => 'Lenovo',
                'location' => 'ICT Storeroom / Shelf A',
                'specifications' => ['processor' => 'Intel Core i5-1335U', 'ram' => '16GB', 'storage_gb' => 512],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000002',
                'asset_tag' => 'ICT-LT-0002',
                'serial_number' => 'PF3ZK9QB',
                'category_id' => $categories[0]['id'],
                'model_name' => 'Latitude 5440',
                'brand' => 'Dell',
                'location' => 'ICT Storeroom / Shelf A',
                'specifications' => ['processor' => 'Intel Core i7-1355U', 'ram' => '32GB', 'storage_gb' => 1024],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000003',
                'asset_tag' => 'ICT-NW-0001',
                'serial_number' => 'FGL2249L0AB',
                'category_id' => $categories[1]['id'],
                'model_name' => 'Catalyst 2960-X',
                'brand' => 'Cisco',
                'location' => 'Server Room / Rack B',
                'specifications' => ['mac_address' => '00:1A:2B:3C:4D:5E', 'ports' => 24, 'poe' => true],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000004',
                'asset_tag' => 'ICT-PR-0001',
                'serial_number' => null,
                'category_id' => $categories[2]['id'],
                'model_name' => 'EB-X51 Projector',
                'brand' => 'Epson',
                'location' => 'Media Room',
                'specifications' => ['connector' => 'HDMI'],
            ],
        ];

        foreach ($equipment as $item) {
            Equipment::updateOrCreate(['id' => $item['id']], $item);
        }

        $this->command?->info(sprintf(
            'Seeded %d categories and %d equipment items.',
            count($categories),
            count($equipment),
        ));
    }
}
