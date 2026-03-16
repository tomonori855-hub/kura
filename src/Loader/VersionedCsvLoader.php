<?php

namespace Kura\Loader;

/**
 * Loads versioned records from a shared CSV data file.
 *
 * Directory layout:
 *   {basePath}/
 *     data/
 *       {table}.csv            — all rows with a 'version' column
 *     definitions/
 *       {table}.csv            — column,type
 *     indexes/
 *       {table}.csv            — columns,unique
 *
 * Loading rule:
 *   CsvVersionResolver resolves the active version (e.g. "v2.0.0").
 *   All rows whose version < active version are loaded.
 *   Primary keys are unique — no deduplication needed.
 *
 * Supported column types: int, float, bool, string (default)
 */
final class VersionedCsvLoader implements LoaderInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $table,
        private readonly CsvVersionResolver $resolver,
        private readonly string $versionColumn = 'version',
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function load(): \Generator
    {
        $activeVersion = $this->resolver->resolveVersion();
        if ($activeVersion === null) {
            return;
        }

        $dataFile = "{$this->basePath}/data/{$this->table}.csv";
        if (! file_exists($dataFile)) {
            return;
        }

        $types = $this->loadDefinitions();

        $fp = fopen($dataFile, 'r');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open data file: {$dataFile}");
        }

        try {
            $headers = fgetcsv($fp, escape: '');
            if ($headers === false) {
                return;
            }

            $versionIndex = array_search($this->versionColumn, $headers, true);
            if ($versionIndex === false) {
                return;
            }

            $index = 0;
            while (($row = fgetcsv($fp, escape: '')) !== false) {
                $rowVersion = $row[$versionIndex] ?? null;

                // version < activeVersion のレコードのみ
                if ($rowVersion === null || version_compare($rowVersion, $activeVersion, '>=')) {
                    continue;
                }

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

    /** @return array<string, string> */
    public function columns(): array
    {
        return $this->loadDefinitions();
    }

    /** @return list<array{columns: list<string>, unique: bool}> */
    public function indexes(): array
    {
        return $this->loadIndexDefinitions();
    }

    public function version(): string
    {
        return $this->resolver->resolveVersion() ?? '';
    }

    // -------------------------------------------------------------------------
    // CSV parsers
    // -------------------------------------------------------------------------

    /**
     * @return array<string, string> column => type
     */
    private function loadDefinitions(): array
    {
        $file = "{$this->basePath}/definitions/{$this->table}.csv";
        if (! file_exists($file)) {
            return [];
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return [];
        }

        try {
            // Skip header
            fgetcsv($fp, escape: '');

            $types = [];
            while (($row = fgetcsv($fp, escape: '')) !== false) {
                if (count($row) >= 2 && $row[0] !== null && $row[1] !== null) {
                    $types[$row[0]] = $row[1];
                }
            }

            return $types;
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    private function loadIndexDefinitions(): array
    {
        $file = "{$this->basePath}/indexes/{$this->table}.csv";
        if (! file_exists($file)) {
            return [];
        }

        $fp = fopen($file, 'r');
        if ($fp === false) {
            return [];
        }

        try {
            // Skip header
            fgetcsv($fp, escape: '');

            /** @var list<array{columns: list<string>, unique: bool}> $indexes */
            $indexes = [];
            while (($row = fgetcsv($fp, escape: '')) !== false) {
                if (count($row) >= 2 && $row[0] !== null && $row[1] !== null) {
                    $columns = array_map('trim', explode('|', $row[0]));
                    $indexes[] = [
                        'columns' => $columns,
                        'unique' => $row[1] === '1' || $row[1] === 'true',
                    ];
                }
            }

            return $indexes;
        } finally {
            fclose($fp);
        }
    }

    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
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
