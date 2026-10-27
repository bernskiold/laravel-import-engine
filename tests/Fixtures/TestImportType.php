<?php

namespace Bernskiold\LaravelImportEngine\Tests\Fixtures;

use Bernskiold\LaravelImportEngine\Contracts\ImportType;
use Bernskiold\LaravelImportEngine\Importer;

enum TestImportType: string implements ImportType
{
    case Contacts = 'contacts';
    case Sectors = 'sectors';

    public function label(): string
    {
        return match ($this) {
            self::Contacts => 'Contacts',
            self::Sectors => 'Sectors',
        };
    }

    public function importer(): Importer
    {
        return app(match ($this) {
            self::Contacts => ContactImporter::class,
            self::Sectors => SectorImporter::class,
        });
    }
}
