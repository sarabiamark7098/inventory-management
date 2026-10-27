<?php

namespace App\Imports;

/**
 * The list of import profiles, and the only place that knows their names.
 *
 * Registering a new type of ICT equipment is a line here plus its profile
 * class; nothing else in the import path names a type.
 */
final class ImportProfileRegistry
{
    /** @var list<class-string<ImportProfile>> */
    private const PROFILES = [
        DesktopImportProfile::class,
        LaptopImportProfile::class,
        TabletImportProfile::class,
    ];

    /** @return list<ImportProfile> */
    public function all(): array
    {
        return array_map(fn (string $class) => new $class, self::PROFILES);
    }

    public function find(string $name): ?ImportProfile
    {
        foreach ($this->all() as $profile) {
            if ($profile->name() === $name) {
                return $profile;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(fn (ImportProfile $p) => $p->name(), $this->all());
    }
}
