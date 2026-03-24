> English version: [todo-v1.0.md](todo-v1.0.md)

# v1.0 TODO: Octane 対応

Laravel Octane サポートと全環境でのリクエスト単位バージョン一貫性を保証するために必要な変更をまとめます。

---

## 解決すべき問題

### 問題 A — リクエスト内でバージョンが変わりうる（Octane）

`CachedVersionResolver` は PHP var にバージョンを TTL 付きでキャッシュしています（`microtime(true) - $cachedAt < $ttl`）。Octane の永続プロセスでは、前リクエストのキャッシュが残ったまま TTL が切れると、同一リクエスト内で2つのクエリが異なるバージョンを参照してしまいます。

```
Octane プロセス内:
  cachedAt = 前リクエストから 299 秒経過時点
  → 最初のクエリ: TTL 以内 → "v1.0.0"
  → 2 秒後のクエリ: 301 秒経過 → TTL 切れ → 再解決 → "v2.0.0"
  → 1 リクエスト内で v1.0.0 と v2.0.0 が混在 ❌
```

PHP-FPM では毎リクエスト新プロセスなので実害はないが、設計上の保証がない。

### 問題 B — `setVersionOverride()` がリクエストをまたいで残る（Octane）

`KuraManager` は singleton。`setVersionOverride('v2.0.0')` を呼ぶと `$versionOverride` がプロセスに残り続け、`null` に戻す手段がない。

```
Request 1: X-Reference-Version ヘッダーあり → setVersionOverride('v2.0.0')
Request 2: ヘッダーなし → $versionOverride まだ 'v2.0.0' → 全クエリが v2.0.0 ❌
```

### 問題 C — auto-discover がコンテナと独立した resolver を生成している

`KuraServiceProvider::autoDiscoverCsvTables()` が `new CsvVersionResolver()` → `new CachedVersionResolver()` をコンテナのバインドとは別に生成している。`version.driver = 'database'` を設定しても、auto-discover テーブルは独自の CSV resolver を使い続ける。

### 問題 D — 時刻が注入できない

`DatabaseVersionResolver` は内部で `new \DateTimeImmutable` を生成。`CsvVersionResolver` は `$defaultNow` パラメータがあるがコンストラクタ時に固定される。`ClockInterface` を受け取らないため、テストで時刻を固定できず、リクエスト開始時刻を外から差し込めない。

---

## 解決方法

### 解決 A + B — リクエスト境界でのリセット

`CachedVersionResolver` に `resetRequestCache()` を追加（PHP var のみクリア、APCu は残す）:

```php
public function resetRequestCache(): void
{
    $this->cachedVersion = null;
    $this->cachedAt = null;
}
```

`KuraManager` に `resetForRequest()` を追加（override と cached インスタンスをクリア）:

```php
public function resetForRequest(): void
{
    $this->versionOverride = null;
    $this->repositories = [];
    $this->processors = [];
}
```

`KuraServiceProvider` に Octane リスナーを登録:

```php
if (class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
    $this->app['events']->listen(
        \Laravel\Octane\Events\RequestReceived::class,
        function () {
            $resolver = $this->app->make(VersionResolverInterface::class);
            if ($resolver instanceof CachedVersionResolver) {
                $resolver->resetRequestCache();
            }
            $this->app->make(KuraManager::class)->resetForRequest();
        }
    );
}
```

### 解決 C — auto-discover をコンテナの resolver に統合

```php
// before
$inner = new CsvVersionResolver($versionsFile);
$resolver = $cacheTtl > 0 ? new CachedVersionResolver($inner, ...) : $inner;

// after
$resolver = $this->app->make(VersionResolverInterface::class);
```

### 解決 D — `ClockInterface` の注入

`DatabaseVersionResolver` と `CsvVersionResolver` のコンストラクタに `ClockInterface`（既存の `Kura\Version\SystemClock`、PSR-20 準拠）をデフォルト `new SystemClock()` で注入。

テスト用 `tests/Support/FrozenClock.php` を追加:

```php
final class FrozenClock implements \Psr\Clock\ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now) {}
    public function now(): \DateTimeImmutable { return $this->now; }
}
```

---

## 変更ファイル

| ファイル | 変更内容 |
|---|---|
| `src/Version/CachedVersionResolver.php` | `resetRequestCache()` 追加 |
| `src/Version/DatabaseVersionResolver.php` | `ClockInterface` 注入 |
| `src/Loader/CsvVersionResolver.php` | `ClockInterface` 注入（`$defaultNow` 置換） |
| `src/KuraManager.php` | `resetForRequest()` 追加 |
| `src/KuraServiceProvider.php` | Octane リスナー登録 + auto-discover resolver 統合 |
| `tests/Support/FrozenClock.php` | **新規**: テスト用固定時刻 |
| `tests/Version/CachedVersionResolverTest.php` | `resetRequestCache` テスト追加 |
| `tests/Version/DatabaseVersionResolverTest.php` | `FrozenClock` 注入に変更 |
| `tests/Loader/CsvVersionResolverTest.php` | 後方互換維持（`resolveVersion($now)` 継続） |

**変更不要:**

| ファイル | 理由 |
|---|---|
| `src/CacheRepository.php` | `version()` は既に動的 |
| `src/CacheProcessor.php` | クエリごとに `repository->version()` を呼ぶ設計 |
| `src/Store/ApcuStore.php` | ステートレス（version はパラメータ） |
| `src/Loader/CsvLoader.php` | resolver に委譲するのみ |
| `src/Loader/EloquentLoader.php` | 既に `VersionResolverInterface` 対応済み |
| `src/Loader/QueryBuilderLoader.php` | 同上 |

---

## 破壊的変更

なし:
- `ClockInterface` はデフォルト `new SystemClock()` → 既存コード変更不要
- `CsvVersionResolver::resolveVersion(?DateTimeInterface $now)` は後方互換維持
- `resetRequestCache()` / `resetForRequest()` は新規メソッド
- Octane リスナーは `class_exists` ガード付き（Octane 未使用なら何も起きない）

---

## 検証

```bash
vendor/bin/phpunit
vendor/bin/phpunit --filter "CachedVersionResolverTest|DatabaseVersionResolverTest|CsvVersionResolverTest|DatabaseLoaderTest"
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test
```
