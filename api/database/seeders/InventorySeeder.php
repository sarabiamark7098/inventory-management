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

        $equipment = [
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000001',
                'asset_tag' => 'ICT-LT-0001',
                'property_number' => '2026-11-LT-0001',
                'date_acquired' => '2026-02-14',
                'serial_number' => 'PF3ZK9QA',
                'category_id' => self::CAT_LAPTOPS,
                'model_name' => 'ThinkPad T14 Gen 4',
                'brand' => 'Lenovo',
                'location' => 'ICT Storeroom / Shelf A',
                'section' => 'Information and Communications Technology Section',
                'division' => 'Administrative Division',
                'province' => 'Davao del Sur',
                'par_number' => 'PAR-2026-11-0001',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
                'sub_par_number' => null,
                'sub_par_issued_to' => null,
                'actual_user' => 'Mark Rezzel T. Sarabia',
                'position' => 'Information Technology Officer II',
                'ad_username' => 'mrtsarabia',
                'anydesk_number' => '9 876 543 210',
                'specifications' => ['processor' => 'Intel Core i5-1335U', 'ram' => '16GB', 'storage_gb' => 512],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000002',
                'asset_tag' => 'ICT-LT-0002',
                'property_number' => '2026-11-LT-0002',
                'date_acquired' => '2026-02-14',
                'serial_number' => 'PF3ZK9QB',
                'category_id' => self::CAT_LAPTOPS,
                'model_name' => 'Latitude 5440',
                'brand' => 'Dell',
                'location' => 'ICT Storeroom / Shelf A',
                'section' => 'Information and Communications Technology Section',
                'division' => 'Administrative Division',
                'province' => 'Davao del Sur',
                'par_number' => 'PAR-2026-11-0002',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
                'sub_par_number' => 'SUB-PAR-2026-11-0002A',
                'sub_par_issued_to' => 'Ed Michael O. Flete',
                'specifications' => ['processor' => 'Intel Core i7-1355U', 'ram' => '32GB', 'storage_gb' => 1024],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000003',
                'asset_tag' => 'ICT-NW-0001',
                'property_number' => '2025-11-NW-0001',
                'date_acquired' => '2025-09-30',
                'serial_number' => 'FGL2249L0AB',
                'category_id' => self::CAT_NETWORKING,
                'model_name' => 'Catalyst 2960-X',
                'brand' => 'Cisco',
                'location' => 'Server Room / Rack B',
                'section' => 'Information and Communications Technology Section',
                'division' => 'Administrative Division',
                'province' => 'Davao del Norte',
                'par_number' => 'PAR-2026-11-0003',
                'par_issued_to' => 'ICT Section Head',
                'sub_par_number' => null,
                'sub_par_issued_to' => null,
                'specifications' => ['mac_address' => '00:1A:2B:3C:4D:5E', 'ports' => 24, 'poe' => true],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000005',
                'asset_tag' => 'ICT-DT-0001',
                'property_number' => '2026-11-DT-0001',
                'date_acquired' => '2026-01-20',
                'serial_number' => 'MXL3421ABC',
                'monitor_serial_number' => 'CNC4210XYZ',
                'category_id' => self::CAT_DESKTOPS,
                'model_name' => 'OptiPlex 7010 + P2422H',
                'brand' => 'Dell',
                'location' => 'ICT Office / Workstation 3',
                'section' => 'Information and Communications Technology Section',
                'division' => 'Administrative Division',
                'province' => 'Davao del Sur',
                'par_number' => 'PAR-2026-11-0005',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
                'sub_par_number' => null,
                'sub_par_issued_to' => null,
                'actual_user' => 'Ed Michael O. Flete',
                'position' => 'Information Technology Officer I',
                'ad_username' => 'eoflete',
                'anydesk_number' => '1 234 567 890',
                'specifications' => ['processor' => 'Intel Core i5-13500', 'ram' => '16GB', 'monitor_size' => '24 inch'],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000004',
                'asset_tag' => 'ICT-PR-0001',
                'property_number' => '2024-11-PR-0001',
                'date_acquired' => '2024-06-05',
                'serial_number' => null,
                'category_id' => self::CAT_PERIPHERALS,
                'model_name' => 'EB-X51 Projector',
                'brand' => 'Epson',
                'location' => 'Media Room',
                'section' => 'Social Marketing Section',
                'division' => 'Policy and Plans Division',
                'province' => 'Davao Oriental',
                'par_number' => 'PAR-2026-11-0004',
                'par_issued_to' => 'Social Marketing Officer',
                'sub_par_number' => null,
                'sub_par_issued_to' => null,
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
