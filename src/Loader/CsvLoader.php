<?php

namespace Kura\Loader;

/**
 * Loads records from a versioned CSV snapshot.
 *
 * Directory layout:
 *   {tableDirectory}/
 *     defines.csv         — column,type,description
 *     {version}.csv       — data snapshot for that version
 *
 * The active version is resolved by CsvVersionResolver.
 * Column types declared in defines.csv are applied to every record.
 *
 * Supported types: int, float, bool, string (default)
 */
final class CsvLoader implements LoaderInterface
{
    /**
     * @param  list<array{columns: list<string>, unique: bool}>  $indexDefinitions
     */
    public function __construct(
        private readonly string $tableDirectory,
        private readonly CsvVersionResolver $resolver,
        private readonly array $indexDefinitions = [],
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator
    {
        $version = $this->resolver->resolveVersion();
        if ($version === null) {
            return;
        }

        $dataFile = $this->tableDirectory.'/'.$version.'.csv';
        if (! file_exists($dataFile)) {
            return;
        }

        $types = $this->loadDefines();

        $fp = fopen($dataFile, 'r');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open data file: {$dataFile}");
        }

        try {
            $headers = fgetcsv($fp, escape: '');
            if ($headers === false) {
                return;
            }

            $index = 0;
            while (($row = fgetcsv($fp, escape: '')) !== false) {
                $record = [];
                foreach ($headers as $i => $column) {
                    $value = $row[$i] ?? null;
                    $record[$column] = $this->cast($value, $types[$column] ?? 'string');
                }
                yield $index++ => $record;
            }
        } finally {
            fclose($fp);
        }
    }

    /** @return array<string, string> column => type */
    public function columns(): array
    {
        return $this->loadDefines();
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        return $this->indexDefinitions;
    }

    public function version(): string
    {
        return $this->resolver->resolveVersion() ?? '';
    }

    /** @return array<string, string> column => type */
    private function loadDefines(): array
    {
        $definesFile = $this->tableDirectory.'/defines.csv';
        if (! file_exists($definesFile)) {
            return [];
        }

        $fp = fopen($definesFile, 'r');
        if ($fp === false) {
            return [];
        }

        // Skip header
        fgetcsv($fp, escape: '');

        $types = [];
        while (($row = fgetcsv($fp, escape: '')) !== false) {
            if (count($row) >= 2 && $row[0] !== null && $row[1] !== null) {
                $types[$row[0]] = $row[1];
            }
        }

        fclose($fp);

        return $types;
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => $value === '1' || $value === 'true',
            default => (string) $value,
        };
    }
}
