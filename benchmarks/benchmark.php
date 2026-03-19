<?php

declare(strict_types=1);

/**
 * Kura Benchmark
 *
 * Measures query performance across dataset sizes and query patterns.
 * Requires APCu (apc.enable_cli=1).
 *
 * Usage:
 *   php benchmarks/benchmark.php
 *   php benchmarks/benchmark.php --size=10000
 *   php benchmarks/benchmark.php --size=1000,10000,100000 --iterations=200
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Kura\CacheProcessor;
use Kura\CacheRepository;
use Kura\Index\IndexDefinition;
use Kura\Loader\LoaderInterface;
use Kura\ReferenceQueryBuilder;
use Kura\Store\ApcuStore;

// ---------------------------------------------------------------------------
// CLI options
// ---------------------------------------------------------------------------

$opts = getopt('', ['size:', 'iterations:']);
$sizes      = array_map('intval', explode(',', $opts['size'] ?? '1000,10000,100000'));
$iterations = (int) ($opts['iterations'] ?? 500);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param  list<array<string, mixed>>  $records
 * @param  list<array{columns: list<string>, unique: bool}>  $indexes
 */
function buildRepository(
    string $prefix,
    string $table,
    array $records,
    array $indexes,
): CacheRepository {
    $store  = new ApcuStore(prefix: $prefix);
    $loader = new class($records, $indexes) implements LoaderInterface {
        /** @param list<array<string, mixed>> $records */
        /** @param list<array{columns: list<string>, unique: bool}> $indexes */
        public function __construct(
            private readonly array $records,
            private readonly array $indexes,
        ) {}

        public function load(): Generator { yield from $this->records; }
        public function columns(): array  { return []; }
        public function indexes(): array  { return $this->indexes; }
        public function version(): string { return 'bench'; }
    };

    $repo = new CacheRepository(
        table: $table,
        primaryKey: 'id',
        store: $store,
        loader: $loader,
    );
    $repo->rebuild();

    return $repo;
}

/**
 * Return a fresh stateless builder from a pre-built repository.
 * ReferenceQueryBuilder is stateful — reuse of the same instance accumulates
 * where conditions across iterations. Always create a fresh instance per query.
 */
function q(CacheRepository $repo, string $table): ReferenceQueryBuilder
{
    return new ReferenceQueryBuilder(table: $table, repository: $repo);
}

/**
 * Run a closure N times and return [min, avg, max] in microseconds.
 *
 * @return array{min: float, avg: float, max: float, p95: float}
 */
function measure(callable $fn, int $n): array
{
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $t = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t) / 1_000; // ns → µs
    }
    sort($times);
    return [
        'min' => $times[0],
        'avg' => array_sum($times) / count($times),
        'p95' => $times[(int) floor(count($times) * 0.95)],
        'max' => $times[count($times) - 1],
    ];
}

function fmt(float $us): string
{
    if ($us < 1_000) {
        return sprintf('%6.1f µs', $us);
    }
    return sprintf('%6.2f ms', $us / 1_000);
}

function printRow(string $label, array $m): void
{
    printf(
        "  %-42s  min:%s  avg:%s  p95:%s  max:%s\n",
        $label,
        fmt($m['min']),
        fmt($m['avg']),
        fmt($m['p95']),
        fmt($m['max']),
    );
}

// ---------------------------------------------------------------------------
// Data generation
// ---------------------------------------------------------------------------

$countries  = ['JP', 'US', 'GB', 'DE', 'FR'];
$categories = ['electronics', 'clothing', 'food', 'books', 'sports',
               'toys', 'furniture', 'tools', 'beauty', 'music'];

/**
 * @return list<array<string, mixed>>
 */
function generateRecords(int $n): array
{
    global $countries, $categories;

    $records = [];
    for ($i = 1; $i <= $n; $i++) {
        $records[] = [
            'id'       => $i,
            'name'     => 'Product ' . $i,
            'country'  => $countries[$i % count($countries)],
            'category' => $categories[$i % count($categories)],
            'price'    => round(($i % 200) + 0.99, 2),
            'active'   => $i % 3 !== 0,
        ];
    }
    return $records;
}

$indexes = [
    ['columns' => ['country'],           'unique' => false],
    ['columns' => ['price'],             'unique' => false],
    ['columns' => ['country', 'category'], 'unique' => false],
];

// ---------------------------------------------------------------------------
// Run benchmarks
// ---------------------------------------------------------------------------

$sep = str_repeat('-', 80);

echo "\n";
echo "Kura Benchmark\n";
echo "PHP " . PHP_VERSION . "  |  APCu " . phpversion('apcu') . "  |  " . php_uname('m') . "\n";
echo "Iterations per scenario: {$iterations}\n";
echo $sep . "\n";

foreach ($sizes as $size) {
    $prefix  = 'bench_' . $size . '_' . uniqid();
    $records = generateRecords($size);

    echo "\n";
    echo "Dataset: {$size} records\n";
    echo $sep . "\n";

    // Build cache (measure once)
    $rebuildStart = hrtime(true);
    $repo = buildRepository($prefix, 'products', $records, $indexes);
    $rebuildMs = (hrtime(true) - $rebuildStart) / 1_000_000;
    printf("  Cache build time: %.2f ms\n", $rebuildMs);

    $t = 'products';

    // Memo: pick a specific ID from the middle for find()
    $middleId = (int) ($size / 2);

    // --- Scenarios ---

    // 1. Full scan — get all
    $m = measure(fn() => q($repo, $t)->get(), $iterations);
    printRow("get() all ({$size} records)", $m);

    // 2. where (indexed, =)  — ~20% selectivity
    $m = measure(fn() => q($repo, $t)->where('country', 'JP')->get(), $iterations);
    printRow("where('country','JP')  [indexed =]", $m);

    // 3. where (non-indexed) — ~67% selectivity
    $m = measure(fn() => q($repo, $t)->where('active', true)->get(), $iterations);
    printRow("where('active',true)   [non-indexed]", $m);

    // 4. whereBetween (range index)
    $m = measure(fn() => q($repo, $t)->whereBetween('price', [50, 100])->get(), $iterations);
    printRow("whereBetween('price',[50,100]) [range]", $m);

    // 5. Composite index — AND equality
    $m = measure(
        fn() => q($repo, $t)->where('country', 'JP')->where('category', 'electronics')->get(),
        $iterations,
    );
    printRow("where country+category  [composite]", $m);

    // 6. orderBy — sorted traversal
    $m = measure(fn() => q($repo, $t)->where('country', 'JP')->orderBy('price')->get(), $iterations);
    printRow("where+orderBy('price')  [sorted]", $m);

    // 7. find — single record by ID
    $m = measure(fn() => q($repo, $t)->find($middleId), $iterations);
    printRow("find({$middleId})  [single record]", $m);

    // 8. count — aggregate
    $m = measure(fn() => q($repo, $t)->where('country', 'JP')->count(), $iterations);
    printRow("where('country','JP')->count()", $m);
}

echo "\n";
echo $sep . "\n";
echo "Done.\n\n";
