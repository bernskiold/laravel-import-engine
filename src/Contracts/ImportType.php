<?php

namespace Bernskiold\LaravelImportEngine\Contracts;

use Bernskiold\LaravelImportEngine\Importer;

/**
 * The list of the imports that an application has.
 *
 * Put this contract on a string-backed enum, and set the enum in the
 * "import-engine.types" config. Each case is one import. The engine stores
 * the value of the case on each import record, so do not change the values.
 *
 *     enum ImportType: string implements \Bernskiold\LaravelImportEngine\Contracts\ImportType
 *     {
 *         case Contacts = 'contacts';
 *
 *         public function label(): string
 *         {
 *             return __('Contacts');
 *         }
 *
 *         public function importer(): Importer
 *         {
 *             return app(ContactImporter::class);
 *         }
 *     }
 */
interface ImportType
{
    /**
     * The name of the import, for a person.
     */
    public function label(): string;

    /**
     * A new importer for this type.
     *
     * Resolve it from the container, so that the importer can get its
     * dependencies in its constructor. Do not keep one instance, because an
     * importer can keep state during a run.
     */
    public function importer(): Importer;
}
