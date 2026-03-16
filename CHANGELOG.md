# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `ReferenceQueryBuilder` — Laravel QueryBuilder 互換の fluent API
- `CacheProcessor` — Processor パターンによるキャッシュクエリ実行
- `CacheRepository` — テーブル単位のキャッシュ管理・Self-Healing
- `KuraManager` — テーブル登録・クエリ・rebuild の中央レジストリ
- `KuraServiceProvider` — Laravel サービスプロバイダ（auto-discovery 対応）
- `Kura` Facade
- `StoreInterface` / `ApcuStore` / `ArrayStore` — APCu 抽象化層
- `LoaderInterface` / `CsvLoader` / `EloquentLoader` / `QueryBuilderLoader` — データソース抽象化
- `VersionResolverInterface` / `DatabaseVersionResolver` / `CsvVersionResolver` / `CachedVersionResolver` — バージョン管理
- `IndexBuilder` / `IndexResolver` / `BinarySearch` — インデックス構築・検索
- `RecordCursor` — Generator ベースのレコード走査・where 評価
- `RebuildCommand` (`kura:rebuild`) — artisan コマンド
- `RebuildCacheJob` — 非同期キャッシュ再構築ジョブ
- `whereRowValuesIn` — MySQL ROW constructor IN 構文の Kura 独自拡張
- NULL 処理の MySQL 準拠（ORDER BY 含む）
- Composite index による AND equality / ROW constructor IN の O(1) 高速化
- Per-table TTL / chunk_size オーバーライド
- Self-Healing（ids/meta/record/index 欠損時の自動復旧）
