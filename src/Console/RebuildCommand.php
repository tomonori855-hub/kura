<?php

namespace Kura\Console;

use Illuminate\Console\Command;
use Kura\KuraManager;

class RebuildCommand extends Command
{
    protected $signature = 'kura:rebuild
        {table?* : Table names to rebuild (omit for all)}
        {--reference-version= : Reference data version to use for rebuild (resolves from Loader if omitted)}';

    protected $description = 'Rebuild Kura APCu cache for registered tables';

    public function handle(KuraManager $manager): int
    {
        /** @var string|null $version */
        $version = $this->option('reference-version');

        if ($version !== null) {
            $manager->setVersionOverride($version);
            $this->info("Using reference version: {$version}");
        }

        /** @var list<string> $tables */
        $tables = $this->argument('table');

        if ($tables === []) {
            $tables = $manager->registeredTables();
        }

        if ($tables === []) {
            $this->warn('No tables registered.');

            return self::SUCCESS;
        }

        foreach ($tables as $table) {
            $repo = $manager->repository($table);
            $this->info("Rebuilding: {$table} (version: {$repo->version()})");

            try {
                $manager->rebuild($table);
                $this->info('  Done.');
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info('All rebuilds completed.');

        return self::SUCCESS;
    }
}
