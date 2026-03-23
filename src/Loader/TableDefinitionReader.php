<?php

namespace Kura\Loader;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads column type definitions, index definitions, and primary key from a YAML file.
 *
 * Expected file in the table directory:
 *   table.yaml
 *
 * Format:
 *   primary_key: id        # optional, defaults to 'id'
 *   columns:
 *     id: int
 *     code: string
 *   indexes:               # optional
 *     - columns: [code]
 *       unique: true
 *
 * Results are cached per instance (read once, reused).
 */
final class TableDefinitionReader
{
    /** @var array<string, string>|null */
    private ?array $columns = null;

    /** @var list<array{columns: list<string>, unique: bool}>|null */
    private ?array $indexes = null;

    private ?string $primaryKey = null;

    /** @var array<string, mixed>|null */
    private ?array $yaml = null;

    public function __construct(private readonly string $tableDirectory) {}

    /**
     * @return array<string, string> column => type
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $yaml = $this->loadYaml();

        if (! isset($yaml['columns']) || ! is_array($yaml['columns'])) {
            return $this->columns = [];
        }

        /** @var array<string, mixed> $rawColumns */
        $rawColumns = $yaml['columns'];

        $types = [];
        foreach ($rawColumns as $column => $type) {
            if (is_string($type)) {
                $types[$column] = $type;
            }
        }

        return $this->columns = $types;
    }

    /**
     * @return list<array{columns: list<string>, unique: bool}>
     */
    public function indexes(): array
    {
        if ($this->indexes !== null) {
            return $this->indexes;
        }

        $yaml = $this->loadYaml();

        if (! isset($yaml['indexes']) || ! is_array($yaml['indexes'])) {
            return $this->indexes = [];
        }

        /** @var list<mixed> $rawIndexes */
        $rawIndexes = array_values($yaml['indexes']);

        $indexes = [];
        foreach ($rawIndexes as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! isset($entry['columns']) || ! is_array($entry['columns'])) {
                continue;
            }

            /** @var list<mixed> $cols */
            $cols = array_values($entry['columns']);

            $columns = [];
            foreach ($cols as $col) {
                if (is_string($col) && $col !== '') {
                    $columns[] = $col;
                }
            }

            if ($columns === []) {
                continue;
            }

            $unique = isset($entry['unique']) && (bool) $entry['unique'];

            $indexes[] = [
                'columns' => $columns,
                'unique' => $unique,
            ];
        }

        return $this->indexes = $indexes;
    }

    public function primaryKey(): string
    {
        if ($this->primaryKey !== null) {
            return $this->primaryKey;
        }

        $yaml = $this->loadYaml();

        if (isset($yaml['primary_key']) && is_string($yaml['primary_key']) && $yaml['primary_key'] !== '') {
            return $this->primaryKey = $yaml['primary_key'];
        }

        return $this->primaryKey = 'id';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadYaml(): array
    {
        if ($this->yaml !== null) {
            return $this->yaml;
        }

        $file = $this->tableDirectory.'/table.yaml';

        if (! file_exists($file)) {
            return $this->yaml = [];
        }

        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return $this->yaml = [];
        }

        /** @var array<string, mixed>|null $parsed */
        $parsed = Yaml::parse($content);

        return $this->yaml = is_array($parsed) ? $parsed : [];
    }
}
