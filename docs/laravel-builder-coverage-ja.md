> English version: [laravel-builder-coverage.md](laravel-builder-coverage.md)

# Laravel QueryBuilder vs ReferenceQueryBuilder — カバレッジ表

凡例:
- ✅ 実装済み
- ❌ 非対応（SQL/JOIN/DB接続が必要、書き込み操作など）

---

## WHERE 条件

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `where($col, $op, $val)` | ✅ | 演算子: `=` `!=` `<>` `>` `>=` `<` `<=` `like` `not like` `&` `\|` `^` `<<` `>>` `&~` `!&` |
| `where(Closure)` | ✅ | ネストされた AND サブグループ |
| `orWhere($col, $op, $val)` | ✅ | |
| `orWhere(Closure)` | ✅ | ネストされた OR サブグループ |
| `whereNot($col, $op, $val)` | ✅ | `negate: true` フラグで否定 |
| `whereNot(Closure)` | ✅ | |
| `orWhereNot(...)` | ✅ | |
| `whereColumn($first, $op, $second)` | ✅ | |
| `orWhereColumn(...)` | ✅ | |
| `whereNested(Closure, $boolean)` | ✅ | |
| `whereNull($column)` | ✅ | |
| `orWhereNull($column)` | ✅ | |
| `whereNotNull($column)` | ✅ | |
| `orWhereNotNull($column)` | ✅ | |
| `whereIn($column, $values)` | ✅ | `Closure`（遅延サブクエリ）も受付可能。O(1) ハッシュマップルックアップ |
| `orWhereIn(...)` | ✅ | |
| `whereNotIn(...)` | ✅ | |
| `orWhereNotIn(...)` | ✅ | |
| `whereBetween($column, $values)` | ✅ | |
| `orWhereBetween(...)` | ✅ | |
| `whereNotBetween(...)` | ✅ | |
| `orWhereNotBetween(...)` | ✅ | |
| `whereBetweenColumns($col, [$min_col, $max_col])` | ✅ | col が同一レコードの min_col と max_col の間にあるか |
| `orWhereBetweenColumns(...)` | ✅ | |
| `whereNotBetweenColumns(...)` | ✅ | |
| `orWhereNotBetweenColumns(...)` | ✅ | |
| `whereValueBetween($scalar, [$min_col, $max_col])` | ✅ | スカラー値が2つのカラム値の間にあるか |
| `orWhereValueBetween(...)` | ✅ | |
| `whereValueNotBetween(...)` | ✅ | |
| `orWhereValueNotBetween(...)` | ✅ | |
| `whereLike($col, $val, $caseSensitive)` | ✅ | |
| `orWhereLike(...)` | ✅ | |
| `whereNotLike(...)` | ✅ | |
| `orWhereNotLike(...)` | ✅ | |
| `whereNullSafeEquals($col, $val)` | ✅ | |
| `orWhereNullSafeEquals($col, $val)` | ✅ | |
| `whereAll($columns, $op, $val)` | ✅ | |
| `orWhereAll(...)` | ✅ | |
| `whereAny($columns, $op, $val)` | ✅ | |
| `orWhereAny(...)` | ✅ | |
| `whereNone($columns, $op, $val)` | ✅ | |
| `orWhereNone(...)` | ✅ | |
| `whereExists(Closure)` | ✅ | Closure はレコード配列を受け取り bool を返す（SQL EXISTS サブクエリとは異なる） |
| `orWhereExists(Closure)` | ✅ | |
| `whereNotExists(Closure)` | ✅ | |
| `orWhereNotExists(Closure)` | ✅ | |
| `whereFilter(Closure)` *（拡張）* | ✅ | 生の PHP 述語; `whereExists` の基盤 |
| `orWhereFilter(Closure)` *（拡張）* | ✅ | |
| `whereIntegerInRaw(...)` | ❌ | 生 SQL（バインディング回避） |
| `whereRaw($sql, $bindings)` | ❌ | SQL 専用 |
| `whereDate / whereTime / whereDay / whereMonth / whereYear` | ❌ | SQL 日付抽出; 代わりに `whereFilter` を使用 |
| `whereRowValues(...)` | ❌ | SQL 行値比較（下記の `whereRowValuesIn` を参照） |
| `whereRowValuesIn(...)` *（拡張）* | ✅ | `(col1, col2) IN ((v1, v2), ...)` — Kura 拡張 |
| `whereRowValuesNotIn(...)` *（拡張）* | ✅ | NOT IN バリアント |
| `orWhereRowValuesIn(...)` *（拡張）* | ✅ | OR バリアント |
| `orWhereRowValuesNotIn(...)` *（拡張）* | ✅ | OR NOT IN バリアント |
| `whereJson*(...)` | ❌ | SQL JSON 演算子 |
| `whereFullText(...)` | ❌ | 全文検索 SQL インデックス |
| `whereVector*(...)` | ❌ | ベクトル DB |
| `dynamicWhere(...)` | ❌ | Laravel `__call` マジックルーティング |
| `mergeWheres / forNestedWhere / addNestedWhereQuery / addWhereExistsQuery` | ❌ | Laravel 内部メカニズム |

---

## ORDER BY

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `orderBy($column, $direction)` | ✅ | |
| `orderByDesc($column)` | ✅ | |
| `latest($column)` | ✅ | エイリアス: `orderByDesc($column ?? 'created_at')` |
| `oldest($column)` | ✅ | エイリアス: `orderBy($column ?? 'created_at')` |
| `inRandomOrder($seed)` | ✅ | 収集後に `shuffle()`; seed は無視 |
| `reorder($column, $direction)` | ✅ | 全ソートをクリアし、必要に応じて新しいソートを追加 |
| `reorderDesc($column)` | ✅ | |
| `orderByRaw($sql, $bindings)` | ❌ | SQL 式 |
| `orderByVectorDistance(...)` | ❌ | ベクトル DB |

---

## LIMIT / OFFSET / ページネーション

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `limit($value)` | ✅ | |
| `take($value)` | ✅ | エイリアス |
| `offset($value)` | ✅ | |
| `skip($value)` | ✅ | エイリアス |
| `forPage($page, $perPage)` | ✅ | `offset(($page-1)*$perPage)->limit($perPage)` |
| `forPageBeforeId($perPage, $lastId, $col)` | ✅ | カーソルスタイル、降順 |
| `forPageAfterId($perPage, $lastId, $col)` | ✅ | カーソルスタイル、昇順 |
| `getLimit()` | ✅ | |
| `getOffset()` | ✅ | |
| `paginate($perPage, $pageName, $page)` | ✅ | `LengthAwarePaginator` を返す（総件数含む） |
| `simplePaginate($perPage, $pageName, $page)` | ✅ | `Paginator` を返す（総件数なし） |
| `groupLimit($value, $column)` | ❌ | SQL ウィンドウ関数 |
| `cursorPaginate(...)` | ❌ | DB カーソルロジックが必要 |

---

## 実行 / 取得

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `get()` | ✅ | カラムプロジェクション非対応（APCu は全レコードを保存） |
| `cursor()` | ✅ | `\Generator` を返す |
| `first()` | ✅ | |
| `sole()` | ✅ | `RecordsNotFoundException` / `MultipleRecordsFoundException` をスロー |
| `soleValue($column)` | ✅ | |
| `find($id)` | ✅ | `CacheRepository` の `$primaryKey` を使用 |
| `findOr($id, Closure)` | ✅ | |
| `value($column)` | ✅ | |
| `implode($column, $glue)` | ✅ | |
| `count()` | ✅ | |
| `pluck($column, $key)` | ✅ | `$key` パラメータ完全対応 |
| `exists()` | ✅ | |
| `doesntExist()` | ✅ | |
| `existsOr(Closure)` | ✅ | |
| `doesntExistOr(Closure)` | ✅ | |
| `rawValue(...)` | ❌ | SQL 式 |

---

## 集計

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `min($column)` | ✅ | 空の結果セットでは null を返す |
| `max($column)` | ✅ | |
| `sum($column)` | ✅ | 空の結果セットでは 0 を返す |
| `avg($column)` | ✅ | 空の結果セットでは null を返す |
| `average($column)` | ✅ | `avg()` のエイリアス |
| `aggregate($function, $columns)` | ❌ | SQL 集計ディスパッチ |

---

## クローン / ユーティリティ

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `clone()` | ✅ | |
| `cloneWithout(array $properties)` | ✅ | 対応: `wheres` `orders` `limit` `offset` `randomOrder` |
| `newQuery()` | ✅ | 同じテーブル/リポジトリの新しいビルダー |
| `getLimit()` | ✅ | |
| `getOffset()` | ✅ | |
| `dump()` | ✅ | クエリ状態をダンプし、チェーン用に `$this` を返す |
| `dd()` | ✅ | |
| `cloneWithoutBindings(...)` | ❌ | SQL バインディングの概念 |
| `toSql() / toRawSql()` | ❌ | SQL 文字列生成 |
| `raw($value)` | ❌ | SQL 生式 |
| `getColumns / getBindings / getConnection / getGrammar / ...` | ❌ | SQL/DB 内部 |
| `__call($method, $params)` | ❌ | Laravel マクロ/ミキシンシステム |

---

## SELECT / FROM

| Laravel メソッド | 状態 | 備考 |
|---|---|---|
| `select / addSelect / selectSub / selectRaw / distinct` | ❌ | カラムプロジェクション非対応; APCu は全レコードを保存 |
| `from / fromSub / fromRaw` | ❌ | テーブルは構築時に固定 |

---

## GROUP BY / HAVING / JOIN / UNION / 書き込み / ロック

| カテゴリ | 状態 | 備考 |
|---|---|---|
| GROUP BY / HAVING | ❌ | SQL 集計 — `min/max/sum/avg` + `whereFilter` を使用 |
| JOIN | ❌ | クロステーブルフィルタリングには `whereIn('col', fn() => $other->pluck('id'))` を使用 |
| UNION | ❌ | SQL UNION |
| 書き込み操作 (insert/update/delete/truncate) | ❌ | データは `Loader` 経由でロード; リフレッシュには `CacheRepository::reload()` を使用 |
| ロック / タイムアウト | ❌ | DB 並行性の概念 |
| クエリライフサイクルコールバック (beforeQuery/afterQuery) | ❌ | Laravel DB フック |

---

## サマリー

| カテゴリ | ✅ 実装済み | ❌ 非対応 |
|---|---|---|
| WHERE | 54 | 30+ |
| ORDER BY | 7 | 2 |
| LIMIT / ページネーション | 11 | 2 |
| 実行 / 取得 | 15 | 1 |
| 集計 | 5 | 1 |
| クローン / ユーティリティ | 7 | 10+ |
| SELECT / FROM / GROUP BY / HAVING / JOIN / UNION / 書き込み / ロック | 0 | 80+ |
| **合計** | **~99** | **~125** |

### 設計上の重要ポイント

1. **WHERE カバレッジは完全** — SQL 固有でないすべてのパターンに対応。
   クロステーブルフィルタリングは遅延サブクエリで慣用的に処理:
   `whereIn('country', fn() => $countries->where('active', true)->pluck('code'))`

2. **集計 (min/max/sum/avg)** はインメモリのレコードセットで動作 — SQL 不要。

3. **ページネーション** は標準 Laravel の `LengthAwarePaginator` / `Paginator` オブジェクトを返し、
   Blade の `{{ $results->links() }}` と完全互換。

4. **`whereExists(Closure)`** は SQL EXISTS とは異なる: Closure はレコード配列を受け取り
   bool を返す。SQL スタイルの EXISTS（別テーブルに行があるか？）には
   `whereIn('id', fn() => $other->pluck('foreign_key'))` を使用。

5. **SQL 固有の機能**（JSON、全文検索、ベクトル、生式、JOIN、書き込み操作）は
   正しく除外 — これは読み取り専用のインメモリキャッシュレイヤー。

### アーキテクチャ

```
src/
  Contracts/
    ReferenceQueryBuilderInterface.php  ← テストではこれをモック
  Concerns/
    BuildsWhereConditions.php           ← 全 where* メソッド (+ whereRowValuesIn 拡張)
    BuildsOrderAndPagination.php        ← order/limit/paginate メソッド
    ExecutesQueries.php                 ← get/find/aggregate/paginate 実行
  ReferenceQueryBuilder.php             ← 薄いクラス: trait を使用、interface を実装
  CacheProcessor.php                    ← Processor パターン: resolveIds, compilePredicate
  CacheRepository.php                   ← テーブル単位のキャッシュロード/修復
  Index/
    IndexResolver.php                   ← index → 候補 ID（composite index 高速化）
    BinarySearch.php                    ← ソート済み index のバイナリサーチ
  Support/
    RecordCursor.php                    ← Generator ベースの評価エンジン
```
