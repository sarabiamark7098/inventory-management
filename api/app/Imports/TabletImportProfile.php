<?php

namespace App\Imports;

use Database\Seeders\CategorySeeder;

/** Tablets: no host name, no monitor, no Active Directory account. */
final class TabletImportProfile extends ImportProfile
{
    public function name(): string
    {
        return 'tablet';
    }

    public function label(): string
    {
        return 'Tablets';
    }

    public function description(): string
    {
        return 'Tablets issued to field staff. No host name, monitor or Active Directory account — the workbook records none for these.';
    }

    public function categoryId(): string
    {
        return CategorySeeder::TABLETS;
    }

    public function assetTagPrefix(): string
    {
        return 'ICT-TB';
    }

    protected function orderedColumns(): array
    {
        return [
            $this->assetTag(),
            $this->propertyNumber(),
            ...$this->basics(),
            ...$this->charger(),
            ...$this->accountability(),
            // One warranty, not three: a tablet has no printer or scanner
            // hanging off it, so those columns would always be blank.
            $this->yesNo('Warranty', 'spec:warranty', ['WARRANTY', 'Tablet Warranty']),
        ];
    }
}
