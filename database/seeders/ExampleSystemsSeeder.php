<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\SystemTemplateImporter;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class ExampleSystemsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @throws FileNotFoundException
     * @throws JsonException
     * @throws Throwable
     */
    public function run(SystemTemplateImporter $importer): void
    {
        $files = File::glob(resource_path('system-templates/*.json'));

        foreach ($files as $file) {
            $system = $importer->importFile($file);

            if ($system !== null) {
                $this->command->info('Imported: ' . $system->name);
            } else {
                $this->command->warn('Skipped ' . basename($file) . ': already imported');
            }
        }
    }
}
