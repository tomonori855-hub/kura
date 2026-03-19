# Kura TODO

## 設計検討中（未決定）

### A. テーブル登録の自動化
現状は ServiceProvider でテーブルごとに手書き登録が必要。以下を検討中。

**CSV 自動発見**
- `data_path` 配下のサブディレクトリを自動スキャン → テーブルとして登録
- `defines.csv` + `indexes.csv` + `data.csv` が揃っていれば自動で CsvLoader を構築
- `config/kura.php` に `auto_discover: true` を追加するだけで動く

**DB テーブルの config 駆動登録**
- config にテーブル名を列挙するだけで EloquentLoader を自動構築
- スキーマ（カラム型・インデックス）は `SHOW COLUMNS` / `SHOW INDEXES` でイントロスペクト
- scope やインデックス絞り込みが必要な場合のみ明示指定

```php
// 理想の config
'auto_discover' => true,           // CSV テーブルを自動発見
'data_path'     => storage_path('kura'),
'tables' => [                      // DB テーブル（列挙するだけ）
    'products',
    'categories' => [
        'scope'   => fn($q) => $q->where('active', true),
        'indexes' => ['country'],
    ],
],
```

**未解決の問題:**
- DB 型マッピング（`varchar`, `tinyint(1)`, `decimal` → `int/float/bool/string`）
- DB インデックスを全部キャッシュするか選択するか
- Eloquent vs QueryBuilder どちらを基本にするか

---

### B. `indexes.csv` サポート
CsvLoader に `indexes.csv` の読み込みを追加する。

現状の二重管理問題:
- カラム定義 → `defines.csv`（CSV ファイル）
- インデックス定義 → PHP コンストラクタ引数（コード）

`indexes.csv` を追加すれば CSV ディレクトリが自己完結し、自動発見（A）とも相性が良い。

```csv
columns,unique
name,false
code,true
country|type,false
```

**依存:** A（自動発見）を実装するなら B は必須。

---

### C. meta キー廃止（ids キーへの統合）
現状の meta キーに含まれる3情報のうち2つが不要になる見込み。

| 情報 | 代替手段 | 状態 |
|---|---|---|
| columns（型） | `defines.csv` / `LoaderInterface::columns()` | A+B が決まれば不要 |
| composites リスト | `indexes.csv` / `LoaderInterface::indexes()` | A+B が決まれば不要 |
| **chunk min/max** | ids キーに統合する案あり | **要検討** |

**ids キーの新構造案:**
```php
// 現在
[1, 2, 3, ...]

// 変更後
['ids' => [1, 2, 3, ...], 'chunks' => ['price' => [{min, max}, ...]]]
```

**メリット:** APCu キーが5種類→4種類。クエリ開始時の1 fetch で全メタ情報取得。
**コスト:** Store / CacheRepository / IndexResolver / テスト全体に波及する大きな変更。

**判断:** A+B が固まってから検討する。

---

## 実装予定（設計確定済み）

### D. パフォーマンス改善（実装済み ✅）
- [x] orderBy の二重 fetch 解消 — `RecordCursor` に `idsMap` を渡してインライン整合性チェック
- [x] 部分的 AND index 解決 — 非 index の AND 条件をスキップ（WhereEvaluator が後で評価）

---

## ドキュメント・品質（残り）

- [ ] README にバッジ追加（CI / Packagist / PHP バージョン / ライセンス）
- [ ] CHANGELOG に最初のリリースを切る（現在全て `[Unreleased]`）
- [x] `README: ⚠️ This package is in development` など開発ステータスを明示
- [ ] CONTRIBUTING.md に PR/Issue 手順・ブランチ戦略を追加
- [ ] SECURITY.md を追加（脆弱性報告窓口）
- [ ] CSV の空セル = NULL として扱う旨を明示（`data.csv` セクション）
- [x] パフォーマンスの裏付け（ベンチマーク結果を README に追加、`benchmarks/benchmark.php` 追加）
- [ ] トラブルシューティングガイドを追加（APCu が有効にならない / キャッシュが消えた / apc.enable_cli など）

---

## ドキュメント修正（完了 ✅）

- [x] ロック機構の説明修正（TTL はクラッシュ安全策、明示削除は finally ブロック）
- [x] 「ids のみ再構築」の誤記削除（rebuild は常に全件フラッシュ）
- [x] 擬似コードのシグネチャ修正（`cursor(Builder $builder)` → 実際の引数列）
- [x] Self-Healing サマリ修正（meta なし → 全再構築。index/meta 再構築ではない）
- [x] `strategy: callback` の誤解を解消（config 値ではなく `app->extend()` で登録）
- [x] 「Loader は別パッケージ」の誤記修正（src/Loader/ に含まれる）
- [x] `indexes.csv` を概要ドキュメントから削除（現時点では存在しない）
- [x] CsvLoader の読み込みルール修正（`version = X` → `version <= X`）
- [x] スケール目安セクション追加（推奨 ~100K 件/テーブル）
- [x] README Requirements を composer.json と一致させる（PHP ^8.2, Laravel ^10+）
- [x] Warm API / kura:token を README に追加（エンドポイント仕様・curl 例）
- [x] ArrayStore によるテスト方法を README に追加
- [x] APCu 制約を cache-architecture.md に追加（プロセスローカル・マルチサーバー・shm_size）
- [x] Dockerfile の `katana.ini` → `kura.ini` に修正（旧プロジェクト名の残滓）
