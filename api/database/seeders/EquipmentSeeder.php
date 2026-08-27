<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\Office;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sample assets, one per category, with a full accountability trail.
 *
 * **Opt-in.** DatabaseSeeder does not call this, because a real deployment does
 * not want five fictional machines in a property register that gets audited.
 * Run it when you want demonstration data:
 *
 *     php artisan db:seed --class=EquipmentSeeder
 *
 * Depends on both CategorySeeder and OfficeSeeder having run: category ids come
 * from the constants, office ids are resolved by code. Neither is optional — an
 * asset with no division is not a record a property officer can use, so a
 * missing office fails loudly here rather than writing a NULL nobody notices
 * until an audit.
 */
class EquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $ppd = $this->office('XI-FO-PPD');
        $ict = $this->office('XI-FO-PPD-ICT');
        $ord = $this->office('XI-FO-ORD');
        $smu = $this->office('XI-FO-SMU');

        $equipment = [
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000001',
                'asset_tag' => 'ICT-LT-0001',
                'property_number' => '2026-11-LT-0001',
                'date_acquired' => '2026-02-14',
                'serial_number' => 'PF3ZK9QA',
                'category_id' => CategorySeeder::LAPTOPS,
                'model_name' => 'ThinkPad T14 Gen 4',
                'brand' => 'Lenovo',
                'location' => 'ICT Storeroom / Shelf A',
                'division_id' => $ppd,
                'section_id' => $ict,
                'province' => 'Davao del Sur',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
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
                'category_id' => CategorySeeder::LAPTOPS,
                'model_name' => 'Latitude 5440',
                'brand' => 'Dell',
                'location' => 'ICT Storeroom / Shelf A',
                'division_id' => $ppd,
                'section_id' => $ict,
                'province' => 'Davao del Sur',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
                'sub_par_issued_to' => 'Ed Michael O. Flete',
                'specifications' => ['processor' => 'Intel Core i7-1355U', 'ram' => '32GB', 'storage_gb' => 1024],
            ],
            [
                'id' => '0f2a1c34-2222-4a22-8a22-000000000003',
                'asset_tag' => 'ICT-NW-0001',
                'property_number' => '2025-11-NW-0001',
                'date_acquired' => '2025-09-30',
                'serial_number' => 'FGL2249L0AB',
                'category_id' => CategorySeeder::NETWORKING,
                'model_name' => 'Catalyst 2960-X',
                'brand' => 'Cisco',
                'location' => 'Server Room / Rack B',
                'division_id' => $ppd,
                'section_id' => $ict,
                'province' => 'Davao del Norte',
                'par_issued_to' => 'ICT Section Head',
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
                'category_id' => CategorySeeder::DESKTOPS,
                'model_name' => 'OptiPlex 7010 + P2422H',
                'brand' => 'Dell',
                'location' => 'ICT Office / Workstation 3',
                'division_id' => $ppd,
                'section_id' => $ict,
                'province' => 'Davao del Sur',
                'par_issued_to' => 'Mark Rezzel T. Sarabia',
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
                'category_id' => CategorySeeder::PERIPHERALS,
                'model_name' => 'EB-X51 Projector',
                'brand' => 'Epson',
                'location' => 'Media Room',
                'division_id' => $ord,
                'section_id' => $smu,
                'province' => 'Davao Oriental',
                'par_issued_to' => 'Social Marketing Officer',
                'sub_par_issued_to' => null,
                'specifications' => ['connector' => 'HDMI'],
            ],
        ];

        foreach ($equipment as $item) {
            Equipment::updateOrCreate(['id' => $item['id']], $item);
        }

        $this->command?->info(sprintf('Seeded %d equipment items.', count($equipment)));
    }

    /**
     * An office id from its code — the register's stable business key, unlike
     * the name, which gets re-worded.
     */
    private function office(string $code): string
    {
        $id = Office::where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException(
                "Office \"{$code}\" is not in the register, so the sample equipment ".
                'cannot be filed against it. Run OfficeSeeder first.'
            );
        }

        return $id;
    }
}
