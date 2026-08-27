<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * What a fresh install needs to be usable — and nothing else.
 *
 * Order matters: equipment is filed against offices and categories, so those
 * have to exist first. An earlier version ran the office register last, which
 * meant every seeded asset resolved its division to NULL on a clean install.
 *
 * EquipmentSeeder is deliberately absent. Accounts, the office register and the
 * category taxonomy are configuration a field office keeps and edits; the
 * sample assets are demonstration data, and seeding them into a real deployment
 * puts five fictional machines into a property register that gets audited. Run
 * it explicitly when you want them:
 *
 *     php artisan db:seed --class=EquipmentSeeder
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            OfficeSeeder::class,
            CategorySeeder::class,
        ]);
    }
}
