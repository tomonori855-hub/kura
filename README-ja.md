> English version: [README.md](README.md)

# Kura

参照データを APCu にキャッシュし、Laravel の **QueryBuilder 互換 API** で検索できる Laravel パッケージです。

DB や CSV からデータを一度読み込んで APCu にキャッシュし、Laravel の fluent API でクエリ — 実行時の DB クエリは不要です。

## 特徴

- **Laravel QueryBuilder API** — `where`, `orderBy`, `paginate`, `find`, `count`, `sum` など
- **APCu ベース** — 共有メモリからサブミリ秒で読み取り
- **Generator ベース** — 大規模データセットでも低メモリ使用量
- **インデックス高速化** — バイナリサーチインデックスと composite index hashmap による O(1) ルックアップ
- **Self-Healing** — キャッシュが消えても自動再構築
- **バージョン管理** — DB または CSV によるシームレスなバージョン切り替え

## 要件

- PHP ^8.4
- Laravel ^12.0
- APCu 拡張

## インストール

```bash
composer require tomonori/kura
```

設定ファイルの公開:

```bash
php artisan vendor:publish --tag=kura-config
```

## クイックスタート

### 1. CSV ファイルを用意する

```
data/
├── versions.csv
└── products/
    ├── defines.csv
    └── v1.0.0.csv
```

**versions.csv**
```csv
id,version,activated_at
1,v1.0.0,2024-01-01 00:00:00
```

**products/defines.csv**
```csv
column,type,description
id,int,商品ID
name,string,商品名
country,string,国コード
price,int,価格
```

**products/v1.0.0.csv**
```csv
id,name,country,price
1,Widget A,JP,500
2,Widget B,US,200
3,Widget C,JP,100
```

### 2. テーブルを登録する

```php
// ServiceProvider 内
use Kura\Facades\Kura;
use Kura\Loader\CsvLoader;
use Kura\Loader\CsvVersionResolver;

$resolver = new CsvVersionResolver(base_path('data/versions.csv'));
$loader = new CsvLoader(
    tableDirectory: base_path('data/products'),
    resolver: $resolver,
    indexDefinitions: [
        ['columns' => ['country'], 'unique' => false],
    ],
);

Kura::register('products', $loader, primaryKey: 'id');
```

### 3. キャッシュを構築する

```bash
php artisan kura:rebuild
php artisan kura:rebuild products                    # 特定テーブルのみ
php artisan kura:rebuild --reference-version=v2.0.0  # バージョン指定
```

### 4. クエリ

```php
use Kura\Facades\Kura;

// 基本クエリ
$products = Kura::table('products')->where('country', 'JP')->get();
$product  = Kura::table('products')->find(1);
$count    = Kura::table('products')->where('country', 'JP')->count();

// ソート・ページネーション
$page = Kura::table('products')
    ->orderBy('price', 'desc')
    ->paginate(20);

// 集計
$max = Kura::table('products')->max('price');
$avg = Kura::table('products')->where('country', 'JP')->avg('price');
```

### Eloquent を使う場合

```php
use Kura\Loader\EloquentLoader;

$loader = new EloquentLoader(
    query: Product::query(),
    columns: ['id' => 'int', 'name' => 'string', 'country' => 'string', 'price' => 'int'],
    indexDefinitions: [['columns' => ['country'], 'unique' => false]],
    version: 'v1.0.0',
);

Kura::register('products', $loader);
```

## 対応クエリメソッド

### WHERE

`where`, `orWhere`, `whereNot`, `orWhereNot`, `whereIn`, `whereNotIn`, `orWhereIn`, `orWhereNotIn`, `whereNull`, `whereNotNull`, `orWhereNull`, `orWhereNotNull`, `whereBetween`, `whereNotBetween`, `orWhereBetween`, `orWhereNotBetween`, `whereBetweenColumns`, `whereNotBetweenColumns`, `orWhereBetweenColumns`, `orWhereNotBetweenColumns`, `whereValueBetween`, `whereValueNotBetween`, `orWhereValueBetween`, `orWhereValueNotBetween`, `whereLike`, `whereNotLike`, `orWhereLike`, `orWhereNotLike`, `whereColumn`, `orWhereColumn`, `whereAll`, `orWhereAll`, `whereAny`, `orWhereAny`, `whereNone`, `orWhereNone`, `whereNullSafeEquals`, `orWhereNullSafeEquals`, `whereExists`, `orWhereExists`, `whereNotExists`, `orWhereNotExists`, `whereNested`, `whereFilter`, `orWhereFilter`, `whereRowValuesIn`, `whereRowValuesNotIn`, `orWhereRowValuesIn`, `orWhereRowValuesNotIn`

### ORDER BY

`orderBy`, `orderByDesc`, `latest`, `oldest`, `inRandomOrder`, `reorder`, `reorderDesc`

### LIMIT / OFFSET

`limit`, `offset`, `take`, `skip`, `forPage`, `forPageBeforeId`, `forPageAfterId`

### 取得

`get`, `first`, `sole`, `soleValue`, `find`, `findOr`, `value`, `cursor`, `pluck`, `implode`

### 集計

`count`, `min`, `max`, `sum`, `avg`, `average`, `exists`, `doesntExist`, `existsOr`, `doesntExistOr`

### ページネーション

`paginate`, `simplePaginate`

## 設定

```php
// config/kura.php
return [
    'prefix' => 'kura',

    'ttl' => [
        'ids'        => 3600,   // 1時間（最短 — 再構築トリガー）
        'meta'       => 4800,
        'record'     => 4800,
        'index'      => 4800,
        'ids_jitter' => 600,    // Thundering Herd 防止用ランダム 0〜600秒
    ],

    'chunk_size' => null,       // null = chunk なし
    'lock_ttl'   => 60,

    'rebuild' => [
        'strategy' => 'sync',   // 'sync', 'queue', 'callback'
        'queue' => [
            'connection' => null,
            'queue'      => null,
            'retry'      => 3,
        ],
    ],

    'version' => [
        'driver'    => 'database',  // 'database' または 'csv'
        'table'     => 'reference_versions',
        'columns'   => ['version' => 'version', 'activated_at' => 'activated_at'],
        'csv_path'  => '',
        'cache_ttl' => 300,
    ],

    // テーブル単位のオーバーライド
    'tables' => [
        // 'products' => [
        //     'ttl' => ['record' => 7200],
        //     'chunk_size' => 10000,
        // ],
    ],
];
```

## ドキュメント

- [キャッシュアーキテクチャ](docs/cache-architecture-ja.md) / [English](docs/cache-architecture.md) — 設計詳細（TTL・インデックス・self-healing・Queue）
- [概要](docs/overview-ja.md) / [English](docs/overview.md) — 構成と使用方法
- [Laravel Builder カバレッジ](docs/laravel-builder-coverage-ja.md) / [English](docs/laravel-builder-coverage.md) — API 対応表

## ライセンス

MIT
