=== Grant Insight JGrants Importer Fixed ===
Contributors: Grant Insight Team
Tags: grants, jgrants, importer, automation, government
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhanced and fixed version of Grant Insight JGrants Importer with improved stability, security, and functionality.

== Description ==

Grant Insight JGrants Importer Fixed は、日本政府の助成金情報プラットフォーム「Jグランツ」からデータを自動的にWordPressサイトにインポートする高機能プラグインです。

**主な特徴:**

* **自動化されたデータインポート** - Jグランツから最新の助成金情報を自動取得
* **AI統合** - OpenAI APIを使用した内容分析と最適化
* **セキュリティ強化** - nonce検証、データ暗号化、入力サニタイゼーション
* **エラー処理改善** - 包括的なエラーハンドリングとロギング
* **シングルトンパターン** - 重複初期化の防止
* **レート制限** - API呼び出しの制限と管理
* **バッチ処理** - 大量データの効率的な処理
* **AJAX安全性** - 適切なAJAX通信とエラーハンドリング

**修正された問題:**

* 重複初期化エラーの解決
* 設定保存時のAPIキー検証問題
* 手動投稿機能での通信エラー
* cron実行の暴走防止
* セキュリティ脆弱性の修正

**技術的改善:**

* シングルトンベースクラスの実装
* wp_die()からwp_send_json_error()への変更
* トランジェント基盤の実行制御
* 包括的なエラーログ
* データベース操作の最適化

== Installation ==

1. プラグインファイルを `/wp-content/plugins/grant-insight-jgrants-importer-fixed/` ディレクトリにアップロードします
2. WordPress管理画面の「プラグイン」メニューからプラグインを有効化します
3. 「設定」→「Grant Insight JGrants Fixed」で初期設定を行います
4. 必要に応じてAPIキー（OpenAI等）を設定します
5. 自動インポートスケジュールを設定します

== Configuration ==

**基本設定:**

1. **JGrants API設定**
   - API接続テストを実行
   - 取得データの形式を確認

2. **AIサービス設定**（オプション）
   - OpenAI APIキーの設定
   - AI分析機能の有効化

3. **インポート設定**
   - バッチサイズの調整
   - 投稿ステータスの設定
   - 重複チェックオプション

4. **自動化設定**
   - cron実行頻度の設定
   - 実行時間の制限
   - 並列実行の防止

== Usage ==

**手動インポート:**
1. 管理画面で「Grant Insight JGrants Fixed」にアクセス
2. 「手動インポート」ボタンをクリック
3. 処理結果を確認

**自動インポート:**
1. 自動化設定で実行頻度を選択
2. WordPressのcron機能により定期実行
3. ログで実行状況を監視

**AI分析:**
1. OpenAI APIキーを設定
2. AI分析機能を有効化
3. 自動的な内容最適化とタグ付け

== Screenshots ==

1. **管理画面** - メインの設定と操作画面
2. **ログ表示** - 詳細な実行ログとエラー情報
3. **設定画面** - API設定とカスタマイズオプション
4. **インポート結果** - 処理結果とステータス表示

== Frequently Asked Questions ==

= JGrants APIキーは必要ですか？ =

このプラグインは公開APIを使用するため、特別なAPIキーは不要です。ただし、レート制限に注意して使用してください。

= OpenAI APIキーの設定は必須ですか？ =

いいえ、OpenAI APIキーはオプションです。AI分析機能を使用しない場合は不要です。

= 重複した投稿が作成されますか？ =

プラグインには重複チェック機能があり、既存の投稿は自動的にスキップまたは更新されます。

= エラーが発生した場合はどうすればよいですか？ =

管理画面のログセクションで詳細なエラー情報を確認できます。また、WordPress debug.logにも記録されます。

= cronジョブが動作しない場合は？ =

WordPressのcron機能が正常に動作していることを確認してください。必要に応じてサーバーレベルのcronジョブを設定することも可能です。

== Changelog ==

= 2.0.0 =
* 完全リファクタリング - シングルトンパターンの実装
* セキュリティ強化 - nonce検証とデータ暗号化
* AJAX通信の改善 - wp_die()からwp_send_json_error()への変更
* エラーハンドリングの改善 - 包括的なエラー処理とログ
* パフォーマンス最適化 - バッチ処理とメモリ管理
* API統合改善 - レート制限と再試行ロジック
* UI/UX改善 - レスポンシブデザインとアクセシビリティ
* 自動化機能強化 - 並列実行防止とタイムアウト処理
* データ処理改善 - サニタイゼーションとバリデーション
* ログ機能拡張 - 詳細なログ記録と管理

= 1.x.x =
* 初期バージョンの機能
* 基本的なJGrantsデータインポート
* 簡単なスケジュール機能

== Upgrade Notice ==

= 2.0.0 =
これは完全に書き直されたバージョンです。アップグレード前に既存の設定とデータのバックアップを取ることを強く推奨します。新バージョンでは設定の再構成が必要になる場合があります。

== Technical Requirements ==

* **PHP**: 7.4以上（8.1推奨）
* **WordPress**: 5.0以上（最新版推奨）
* **MySQL**: 5.6以上またはMariaDB 10.1以上
* **メモリ**: 最低128MB（256MB推奨）
* **外部接続**: JGrants API、OpenAI API（オプション）

== Support ==

技術サポートが必要な場合は、以下のリソースをご利用ください：

* プラグインの管理画面内ログ機能
* WordPress debug.log
* 開発者向けドキュメント
* コミュニティサポートフォーラム

== Privacy Policy ==

このプラグインは以下のデータを処理します：

* JGrantsから取得した公開助成金情報
* 設定されたAPIキー（暗号化して保存）
* 処理ログと統計情報
* ユーザー操作ログ

外部サービス（OpenAI）を使用する場合、それらのプライバシーポリシーが適用されます。

== Development ==

このプラグインはモダンなPHPとWordPress開発標準に従って作成されています：

* PSR-4準拠のオートローディング
* PHPDocによる包括的なドキュメント
* 単体テスト対応の構造
* セキュリティベストプラクティス
* パフォーマンス最適化
* 国際化対応（i18n）

== License ==

This plugin is licensed under the GPLv2 or later license.