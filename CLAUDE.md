# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Kura** (蔵) — A Laravel package that provides a QueryBuilder-compatible interface over APCu cache. Reference data is loaded once from DB or CSV, stored in APCu, and queried via a fluent API. Traversal is generator-based for low memory usage; lookups are accelerated via index trees.

Target: Laravel composer package (PHP ^8.4, Laravel ^12.0)

## Commands

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --filter testMethodName
vendor/bin/pint
vendor/bin/phpstan analyse
```

## APCu Key Structure

```
kura:{table}:{version}:meta                    # メタ情報（columns + indexes + composites）
kura:{table}:{version}:ids                     # 全IDリスト [id, ...]
kura:{table}:{version}:record:{id}             # 1レコード（連想配列）
kura:{table}:{version}:idx:{col}               # index（chunk なし）
kura:{table}:{version}:idx:{col}:{chunk}       # index（chunk あり）
kura:{table}:{version}:cidx:{col1|col2}        # composite index（hashmap）
kura:{table}:lock                               # rebuild ロック（version 非依存）
```

## Self-Healing Cache

`ids` キーが各テーブルキャッシュの存在を担保する。

```
クエリ実行
  └─ ids キーなし             → 全件リロード（DB/CSV から再取得）
  └─ ids キーあり
       └─ record:{id} なし   → CacheInconsistencyException → 全再構築
```

APCu に evict されても自動復旧する。全件リロードは常に全件入れ直し（差分更新なし）。

## TTL

デフォルト TTL はパッケージ全体で設定し、テーブル単位でオーバーライド可能。

## Data Flow

```
DB / CSV
  └─ Generator で読み込み（省メモリ）
       └─ apcu_store で record・index・ids を一括書き込み
```

## Query Flow

```
ReferenceQueryBuilder::where(...)
  └─ index ヒット → APCu から ID セット取得 → record を fetch
  └─ index なし   → ids からジェネレーターで全走査 + フィルタ
```

## Architecture

- `src/`
  - `ReferenceQueryBuilder` — fluent API。`Illuminate\Database\Query\Builder` のメソッドシグネチャに準拠
  - `CacheProcessor` — Processor パターン。実行を担当
  - `CacheRepository` — テーブル単位のキャッシュ管理・self-healing
  - `KuraManager` — テーブル登録・クエリ・rebuild の中央レジストリ
  - `Index/` — unique / non-unique / composite インデックスの管理
  - `Store/` — APCu の read/write 抽象化（key 生成・TTL・healing を含む）
  - `Loader/` — DB・CSV などのデータソースから generator で読み込み
  - `Support/` — ジェネレーターベースのカーソル・イテレーターユーティリティ
- `tests/` — src/ に対応した PHPUnit テスト

## Key Design Constraints

- 結果を複数返す操作は内部で配列に収集せず、必ずジェネレーターで返す
- インデックスにヒットしない `where` のみ全走査にフォールバック
- public API のメソッドシグネチャは `Illuminate\Database\Query\Builder` と揃える
