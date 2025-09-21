<?php
/**
 * Plugin Name: Grant Insight Jグランツ・インポーター 改善版
 * Plugin URI: https://grant-insight.com/
 * Description: JグランツAPIと統合したAI自動化助成金情報管理システム。キーワード検索修正、インポート件数制限修正、高度なAIカスタマイズ機能搭載。
 * Version: 2.0.0
 * Author: Grant Insight Team
 * Text Domain: grant-insight-jgrants-importer-improved
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// プラグインの基本定数定義
define('GIJI_IMPROVED_PLUGIN_VERSION', '2.0.0');
define('GIJI_IMPROVED_PLUGIN_FILE', __FILE__);
define('GIJI_IMPROVED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIJI_IMPROVED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GIJI_IMPROVED_PLUGIN_BASENAME', plugin_basename(__FILE__));

// デバッグモード（開発時のみ有効）
define('GIJI_DEBUG', WP_DEBUG);

/**
 * フォールバックロガークラス
 * メインロガーの初期化に失敗した場合の緊急時ロガー
 */
class GIJI_Fallback_Logger {
    
    public function log($message, $level = 'error', $context = array()) {
        $formatted_message = "[GIJI " . strtoupper($level) . "] " . $message;
        if (!empty($context)) {
            $formatted_message .= " | Context: " . json_encode($context);
        }
        error_log($formatted_message);
    }
    
    public function create_log_tables() {
        // フォールバック時はテーブル作成をスキップ
        error_log("[GIJI WARNING] フォールバックロガー使用中のため、ログテーブル作成をスキップします");
    }
}

/**
 * メインプラグインクラス
 */
class Grant_Insight_JGrants_Importer_Improved {
    
    private static $instance = null;
    private $jgrants_client;
    private $ai_client;
    private $data_processor;
    private $automation_controller;
    private $admin_manager;
    private $logger;
    private $security_manager;
    private $emergency_mode = false;
    private $dependency_errors = array();
    
    /**
     * シングルトンパターン
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * コンストラクタ
     */
    private function __construct() {
        // プラグインの初期化
        $this->init();
    }
    
    /**
     * プラグイン初期化
     */
    public function init() {
        // 言語ファイルの読み込み
        load_plugin_textdomain('grant-insight-jgrants-importer-improved', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // クラスファイルの読み込み
        $this->load_dependencies();
        
        // WordPressの初期化が完了してからコンポーネントを初期化
        add_action('init', array($this, 'init_components'));
        
        // 管理画面の初期化（設定保存とAJAX処理のため早期初期化）
        if (is_admin()) {
            add_action('admin_init', array($this, 'initialize_admin_early'), 1);
            add_action('admin_menu', array($this, 'ensure_admin_initialization'), 5);
        }
        
        // Cronスケジュールの追加
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }
    
    /**
     * コンポーネントの初期化
     */
    public function init_components() {
        try {
            // セキュリティマネージャーの初期化
            if (class_exists('GIJI_Security_Manager')) {
                $this->security_manager = new GIJI_Security_Manager();
            }
            
            // ロガーの初期化
            if (class_exists('GIJI_Logger')) {
                $this->logger = new GIJI_Logger();
            }
            
            // APIクライアントの初期化
            if (class_exists('GIJI_JGrants_API_Client')) {
                $this->jgrants_client = new GIJI_JGrants_API_Client($this->logger);
            }
            
            if (class_exists('GIJI_Unified_AI_Client')) {
                $this->ai_client = new GIJI_Unified_AI_Client($this->logger, $this->security_manager);
            }
            
            // データプロセッサーの初期化
            if (class_exists('GIJI_Grant_Data_Processor')) {
                $this->data_processor = new GIJI_Grant_Data_Processor(
                    $this->jgrants_client,
                    $this->ai_client,
                    $this->logger
                );
            }
            
            // 自動化コントローラーの初期化
            if (class_exists('GIJI_Automation_Controller')) {
                $this->automation_controller = new GIJI_Automation_Controller(
                    $this->data_processor,
                    $this->logger
                );
            }
            
            // 投稿タイプとタクソノミーの登録
            $this->register_post_types_and_taxonomies();
            
            // if ($this->logger) {
            //     $this->logger->log('Grant Insight Jグランツ・インポーター改善版のコンポーネント初期化完了');
            // }
            
        } catch (Exception $e) {
            error_log('Grant Insight Jグランツ・インポーター改善版初期化エラー: ' . $e->getMessage());
            
            // フォールバック用の適切なロガー
            if (!$this->logger) {
                $this->logger = new GIJI_Fallback_Logger();
                $this->logger->log('初期化中にエラーが発生したため、フォールバックロガーを使用しています: ' . $e->getMessage(), 'warning');
            }
        }
    }
    
    /**
     * 管理画面コンポーネントの早期初期化（admin_init時）
     * 設定保存とAJAX処理のハンドラー登録のため
     */
    public function initialize_admin_early() {
        static $initialized = false;
        
        if (!$initialized) {
            // コンポーネントが未初期化の場合は先に初期化
            if (!$this->automation_controller) {
                $this->init_components();
            }
            
            // 管理画面マネージャーの早期初期化（AJAX処理と設定処理のため）
            if (class_exists('GIJI_Admin_Manager') && !$this->admin_manager) {
                try {
                    $this->admin_manager = new GIJI_Admin_Manager(
                        $this->automation_controller,
                        $this->logger,
                        $this->security_manager
                    );
                    
                    if ($this->logger) {
                        $this->logger->log('管理画面マネージャーを早期初期化しました（admin_init）');
                    }
                } catch (Exception $e) {
                    error_log('管理画面マネージャーの早期初期化エラー: ' . $e->getMessage());
                }
            }
            
            $initialized = true;
        }
    }
    
    /**
     * 管理画面初期化の確実な実行
     */
    public function ensure_admin_initialization() {
        // 早期初期化で既に管理画面マネージャーが初期化されている場合は何もしない
        if ($this->admin_manager) {
            return;
        }
        
        // コンポーネントが未初期化の場合は先に初期化
        if (!$this->automation_controller) {
            $this->init_components();
        }
        
        // 管理画面初期化
        $this->init_admin();
    }
    
    /**
     * 管理画面の初期化
     */
    public function init_admin() {
        // 依存関係の読み込みと検証
        $dependencies_ok = $this->load_dependencies();
        
        // 緊急モードの場合は緊急画面のみ表示
        if ($this->emergency_mode) {
            $this->display_emergency_admin();
            return;
        }
        
        // 管理画面クラスが存在する場合のみ初期化
        if (class_exists('GIJI_Admin_Manager')) {
            // 必要なコンポーネントを初期化
            if (!$this->automation_controller) {
                $this->init_components();
            }
            
            // 管理画面マネージャーを初期化
            try {
                $this->admin_manager = new GIJI_Admin_Manager(
                    $this->automation_controller,
                    $this->logger,
                    $this->security_manager
                );
                
                // if ($this->logger) {
                //     $this->logger->log('完全版管理画面を正常に初期化しました');
                // }
            } catch (Exception $e) {
                error_log('完全版管理画面初期化エラー: ' . $e->getMessage());
                $this->emergency_mode = true;
                $this->display_emergency_admin();
            }
        } else {
            error_log('GIJI_Admin_Manager クラスが見つかりません。依存ファイルの読み込みに失敗している可能性があります。');
            $this->emergency_mode = true;
            $this->display_emergency_admin();
        }
    }
    
    /**
     * 緊急管理画面の表示
     */
    public function display_emergency_admin() {
        add_action('admin_menu', function() {
            add_menu_page(
                'Grant Insight (緊急モード)',
                'Jグランツ（緊急）',
                'manage_options',
                'giji-emergency',
                array($this, 'show_emergency_page'),
                'dashicons-warning',
                30
            );
        });
    }
    
    /**
     * 緊急モードページの表示
     */
    public function show_emergency_page() {
        ?>
        <div class="wrap">
            <h1 style="color: #d63638;">🚨 Grant Insight Jグランツ・インポーター 改善版 - 緊急モード</h1>
            
            <div class="notice notice-error">
                <p><strong>重要:</strong> プラグインの初期化中にエラーが発生したため、緊急モードで動作しています。</p>
            </div>
            
            <h2>発生したエラー</h2>
            <?php if (!empty($this->dependency_errors)): ?>
                <ul style="color: #d63638;">
                    <?php foreach ($this->dependency_errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>具体的なエラーの詳細は不明です。PHPエラーログを確認してください。</p>
            <?php endif; ?>
            
            <h2>対処方法</h2>
            <ol>
                <li>プラグインを一旦無効にして、再度有効化してください</li>
                <li>WordPressとPHPのバージョンが要件を満たしているかご確認ください</li>
                <li>他のプラグインとの競合がないかご確認ください</li>
                <li>問題が解決しない場合は、サーバーのPHPエラーログをご確認ください</li>
            </ol>
            
            <h2>システム情報</h2>
            <table class="widefat">
                <tr><th>WordPress バージョン</th><td><?php echo get_bloginfo('version'); ?></td></tr>
                <tr><th>PHP バージョン</th><td><?php echo PHP_VERSION; ?></td></tr>
                <tr><th>プラグイン バージョン</th><td>1.2.0 (改善版)</td></tr>
                <tr><th>緊急モード</th><td style="color: #d63638;">有効</td></tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * 必要なクラスファイルを読み込み（厳密な依存関係検証付き）
     */
    private function load_dependencies() {
        // ファイルからクラスへの厳密なマッピング
        $dependency_map = array(
            'includes/class-security-manager.php' => 'GIJI_Security_Manager',
            'includes/class-logger.php' => 'GIJI_Logger',
            'includes/class-jgrants-api-client-improved.php' => 'GIJI_JGrants_API_Client',
            'includes/class-unified-ai-client-improved.php' => 'GIJI_Unified_AI_Client',
            'includes/class-grant-data-processor-improved.php' => 'GIJI_Grant_Data_Processor',
            'includes/class-automation-controller-improved.php' => 'GIJI_Automation_Controller',
            'admin/class-admin-manager-improved.php' => 'GIJI_Admin_Manager'
        );
        
        $loaded_files = array();
        $missing_files = array();
        $missing_classes = array();
        
        // ファイルの読み込み
        foreach ($dependency_map as $file => $expected_class) {
            $file_path = GIJI_IMPROVED_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
                $loaded_files[] = $file;
                
                // 即座にクラス存在チェック
                if (!class_exists($expected_class)) {
                    $missing_classes[] = array(
                        'file' => $file,
                        'class' => $expected_class,
                        'path' => $file_path
                    );
                }
            } else {
                $missing_files[] = array(
                    'file' => $file,
                    'class' => $expected_class,
                    'path' => $file_path
                );
            }
        }
        
        // ACFフィールド設定の読み込み
        $acf_file = GIJI_IMPROVED_PLUGIN_DIR . 'acf-fields-improved.php';
        if (file_exists($acf_file)) {
            require_once $acf_file;
        }
        
        // エラーがある場合は緊急モードに移行
        if (!empty($missing_files) || !empty($missing_classes)) {
            $this->emergency_mode = true;
            $this->dependency_errors = array(
                'missing_files' => $missing_files,
                'missing_classes' => $missing_classes,
                'loaded_files' => $loaded_files
            );
            
            // 詳細なエラーログ
            error_log('GIJI CRITICAL: 依存関係エラーのため緊急モードに移行');
            
            if (!empty($missing_files)) {
                foreach ($missing_files as $error) {
                    error_log("GIJI ERROR: ファイルが見つかりません - {$error['file']} (期待クラス: {$error['class']})");
                }
            }
            
            if (!empty($missing_classes)) {
                foreach ($missing_classes as $error) {
                    error_log("GIJI ERROR: クラスが見つかりません - {$error['class']} (ファイル: {$error['file']})");
                }
            }
            
            // 管理者通知を追加
            add_action('admin_notices', array($this, 'show_dependency_error_notice'));
        }
        
        return !$this->emergency_mode;
    }
    
    /**
     * 依存関係エラーの管理者通知
     */
    public function show_dependency_error_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $errors = $this->dependency_errors;
        
        echo '<div class="notice notice-error is-dismissible">';
        echo '<h3>Grant Insight Jグランツ・インポーター 重要: 依存関係エラー</h3>';
        echo '<p>プラグインの必要なファイルまたはクラスが見つかりません。以下を確認してください：</p>';
        
        if (!empty($errors['missing_files'])) {
            echo '<h4>見つからないファイル:</h4><ul>';
            foreach ($errors['missing_files'] as $error) {
                echo '<li><code>' . esc_html($error['file']) . '</code> (期待クラス: ' . esc_html($error['class']) . ')</li>';
            }
            echo '</ul>';
        }
        
        if (!empty($errors['missing_classes'])) {
            echo '<h4>読み込まれなかったクラス:</h4><ul>';
            foreach ($errors['missing_classes'] as $error) {
                echo '<li><code>' . esc_html($error['class']) . '</code> (ファイル: ' . esc_html($error['file']) . ')</li>';
            }
            echo '</ul>';
        }
        
        echo '<p><strong>解決方法:</strong></p>';
        echo '<ol>';
        echo '<li>プラグインを再アップロード（全ファイルが正しくアップロードされているか確認）</li>';
        echo '<li>ファイル権限を確認（プラグインディレクトリが読み取り可能か確認）</li>';
        echo '<li>PHPエラーログを確認して詳細なエラー情報を取得</li>';
        echo '<li>問題が解決しない場合は、プラグインを無効化・削除して再インストール</li>';
        echo '</ol>';
        echo '</div>';
    }
    
    /**
     * カスタム投稿タイプとタクソノミーの登録
     */
    public function register_post_types_and_taxonomies() {
        // カスタム投稿タイプ「助成金」
        $args = array(
            'labels' => array(
                'name' => __('助成金', 'grant-insight-jgrants-importer-improved'),
                'singular_name' => __('助成金', 'grant-insight-jgrants-importer-improved'),
                'add_new' => __('新規追加', 'grant-insight-jgrants-importer-improved'),
                'add_new_item' => __('新しい助成金を追加', 'grant-insight-jgrants-importer-improved'),
                'edit_item' => __('助成金を編集', 'grant-insight-jgrants-importer-improved'),
                'new_item' => __('新しい助成金', 'grant-insight-jgrants-importer-improved'),
                'view_item' => __('助成金を表示', 'grant-insight-jgrants-importer-improved'),
                'search_items' => __('助成金を検索', 'grant-insight-jgrants-importer-improved'),
                'not_found' => __('助成金が見つかりません', 'grant-insight-jgrants-importer-improved'),
                'not_found_in_trash' => __('ゴミ箱に助成金はありません', 'grant-insight-jgrants-importer-improved'),
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'excerpt', 'custom-fields', 'thumbnail'),
            'menu_icon' => 'dashicons-money-alt',
            'rewrite' => array('slug' => 'grants'),
            'show_in_rest' => true,
        );
        register_post_type('grant', $args);
        
        // カスタムタクソノミー「補助対象地域」
        register_taxonomy('grant_prefecture', 'grant', array(
            'labels' => array(
                'name' => __('補助対象地域', 'grant-insight-jgrants-importer-improved'),
                'singular_name' => __('補助対象地域', 'grant-insight-jgrants-importer-improved'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-prefecture'),
        ));
        
        // カスタムタクソノミー「利用目的」
        register_taxonomy('grant_category', 'grant', array(
            'labels' => array(
                'name' => __('利用目的', 'grant-insight-jgrants-importer-improved'),
                'singular_name' => __('利用目的', 'grant-insight-jgrants-importer-improved'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-category'),
        ));
        
        // カスタムタクソノミー「実施組織」
        register_taxonomy('grant_organization', 'grant', array(
            'labels' => array(
                'name' => __('実施組織', 'grant-insight-jgrants-importer-improved'),
                'singular_name' => __('実施組織', 'grant-insight-jgrants-importer-improved'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-organization'),
        ));
    }
    
    /**
     * データベーステーブルの確認・作成
     */
    public function check_database_tables() {
        if ($this->logger) {
            $this->logger->create_log_tables();
        }
    }
    
    /**
     * プラグイン有効化時の処理（静的メソッド）
     */
    public static function activate() {
        // インスタンスを取得してランタイムと同じCPT/タクソノミーを登録
        $plugin = Grant_Insight_JGrants_Importer_Improved::get_instance();
        $plugin->register_post_types_and_taxonomies();
        
        // リライトルールの更新（CPT登録後に実行）
        flush_rewrite_rules();
        
        // デフォルト設定の保存
        self::save_default_settings();
        
        // AIプロバイダーを強制的にOpenAIに設定（アクティベーション時）
        update_option('giji_improved_ai_provider', 'openai');
        
        // データベーステーブルの作成（ロガークラス利用可能な場合）
        if (class_exists('GIJI_Logger')) {
            try {
                $logger = new GIJI_Logger();
                $logger->create_log_tables();
                $logger->log('プラグインが有効化されました', 'info');
            } catch (Exception $e) {
                error_log('Logger初期化エラー（activation）: ' . $e->getMessage());
            }
        }
        
        // Cronイベントのスケジュール
        if (!wp_next_scheduled('giji_improved_auto_import_hook')) {
            wp_schedule_event(time(), 'daily', 'giji_improved_auto_import_hook');
        }
    }
    
    /**
     * アクティベーション時の依存ファイル読み込み
     */
    private function load_dependencies_for_activation() {
        // アクティベーション時に必要最小限のクラスのみ読み込み
        $essential_files = array(
            'includes/class-logger.php',
            'includes/class-security-manager.php'
        );
        
        foreach ($essential_files as $file) {
            $file_path = GIJI_IMPROVED_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * プラグイン無効化時の処理（静的メソッド）
     */
    public static function deactivate() {
        // Cronイベントの削除
        wp_clear_scheduled_hook('giji_improved_auto_import_hook');
        
        // リライトルールの更新
        flush_rewrite_rules();
    }
    
    
    /**
     * デフォルト設定の保存（静的メソッド）
     */
    private static function save_default_settings() {
        $default_settings = array(
            'giji_improved_auto_import_enabled' => 'no',
            'giji_improved_cron_schedule' => 'daily',
            'giji_improved_import_limit' => 50,
            'giji_improved_keyword_search' => '',
            'giji_improved_ai_provider' => 'openai',
            'giji_improved_gemini_model' => 'gemini-1.5-flash',
            'giji_improved_openai_model' => 'gpt-4o-mini',
            'giji_improved_claude_model' => 'claude-3-5-sonnet-20241022',
            'giji_improved_ai_temperature' => 0.7,
            'giji_improved_ai_max_tokens' => 2000,
            'giji_improved_content_prompt' => 'この助成金について、申請を検討している人に向けて以下の形式で詳細な説明を作成してください：

<div style="max-width: 1200px; margin: 0 auto; font-family: \'Hiragino Sans\', \'Yu Gothic\', \'Meiryo\', sans-serif; line-height: 1.8; color: #333;">

<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
<h2 style="margin: 0; font-size: 28px; font-weight: bold;">🎯 助成金概要</h2>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
<div style="background: #f8f9ff; padding: 25px; border-radius: 12px; border-left: 5px solid #4f46e5;">
<h3 style="margin-top: 0; color: #4f46e5; font-size: 20px;">💰 助成額・期間</h3>
<p>[具体的な助成額と助成期間を記載]</p>
</div>
<div style="background: #f0fdf4; padding: 25px; border-radius: 12px; border-left: 5px solid #16a34a;">
<h3 style="margin-top: 0; color: #16a34a; font-size: 20px;">📋 申請難易度</h3>
<p>[申請の難易度と必要な準備を記載]</p>
</div>
</div>

<div style="background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); margin-bottom: 30px;">
<h3 style="color: #1e40af; font-size: 24px; margin-bottom: 20px; border-bottom: 3px solid #dbeafe; padding-bottom: 10px;">📊 助成金詳細情報</h3>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
<tr style="background: #f1f5f9;">
<td style="padding: 15px; border: 1px solid #e2e8f0; font-weight: bold; background: #334155; color: white; width: 30%;">対象事業</td>
<td style="padding: 15px; border: 1px solid #e2e8f0;">[対象となる事業内容を詳細に記載]</td>
</tr>
<tr>
<td style="padding: 15px; border: 1px solid #e2e8f0; font-weight: bold; background: #334155; color: white;">対象者</td>
<td style="padding: 15px; border: 1px solid #e2e8f0;">[申請できる対象者の条件を記載]</td>
</tr>
<tr style="background: #f1f5f9;">
<td style="padding: 15px; border: 1px solid #e2e8f0; font-weight: bold; background: #334155; color: white;">申請期間</td>
<td style="padding: 15px; border: 1px solid #e2e8f0;">[申請開始から締切までの期間]</td>
</tr>
<tr>
<td style="padding: 15px; border: 1px solid #e2e8f0; font-weight: bold; background: #334155; color: white;">交付条件</td>
<td style="padding: 15px; border: 1px solid #e2e8f0;">[助成金交付の条件や要件]</td>
</tr>
</table>
</div>

<div style="background: linear-gradient(135deg, #fef3c7 0%, #f59e0b 100%); padding: 25px; border-radius: 12px; margin-bottom: 25px;">
<h3 style="margin-top: 0; color: #92400e; font-size: 22px;">⚠️ 重要なポイント</h3>
<ul style="margin-bottom: 0; padding-left: 20px;">
<li style="margin-bottom: 10px;">[申請時の注意点1]</li>
<li style="margin-bottom: 10px;">[申請時の注意点2]</li>
<li style="margin-bottom: 10px;">[申請時の注意点3]</li>
</ul>
</div>

<div style="background: #ecfdf5; border: 2px solid #10b981; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
<h3 style="margin-top: 0; color: #047857; font-size: 22px;">🚀 申請成功のコツ</h3>
<ol style="margin-bottom: 0; padding-left: 20px;">
<li style="margin-bottom: 12px;"><strong>[コツ1のタイトル]:</strong> [具体的なアドバイス]</li>
<li style="margin-bottom: 12px;"><strong>[コツ2のタイトル]:</strong> [具体的なアドバイス]</li>
<li style="margin-bottom: 12px;"><strong>[コツ3のタイトル]:</strong> [具体的なアドバイス]</li>
</ol>
</div>

<div style="background: white; border: 2px solid #3b82f6; border-radius: 12px; padding: 25px; text-align: center;">
<h3 style="margin-top: 0; color: #1e40af; font-size: 20px;">📞 お問い合わせ・申請窓口</h3>
<p style="margin-bottom: 0; color: #1f2937;">[担当部署・連絡先・申請方法の情報]</p>
</div>

</div>

上記のHTML構造を使用して、見やすく分かりやすい助成金情報を作成してください。',
            'giji_improved_summary_prompt' => 'この助成金の要点を3行以内でまとめてください。',
            'giji_improved_difficulty_prompt' => 'この助成金の申請難易度を「easy」「medium」「hard」の3段階で評価してください。'
        );
        
        foreach ($default_settings as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }
    
    /**
     * デフォルト設定の保存（インスタンスメソッド）
     */
    private function save_default_settings_instance() {
        // API設定のデフォルト値
        if (!get_option('giji_improved_ai_provider')) {
            update_option('giji_improved_ai_provider', 'openai');
        }
        
        // 自動化設定のデフォルト値
        if (!get_option('giji_improved_cron_schedule')) {
            update_option('giji_improved_cron_schedule', 'daily');
        }
        
        // AI生成設定のデフォルト値
        $default_ai_settings = array(
            'content' => true,
            'excerpt' => true,
            'summary' => true,
            'organization' => true,
            'difficulty' => true,
            'success_rate' => true,
            'keywords' => true,
            'target_audience' => true,
            'application_tips' => true,
            'requirements' => true
        );
        
        if (!get_option('giji_improved_ai_generation_enabled')) {
            update_option('giji_improved_ai_generation_enabled', $default_ai_settings);
        }
        
        // 検索設定のデフォルト値
        $default_search_settings = array(
            'keyword' => '補助金',
            'min_amount' => 0,
            'max_amount' => 0,
            'target_areas' => array(),
            'use_purposes' => array(),
            'acceptance_only' => true,
            'exclude_zero_amount' => true
        );
        
        if (!get_option('giji_improved_search_settings')) {
            update_option('giji_improved_search_settings', $default_search_settings);
        }
        
        // 高度なAI設定のデフォルト値
        $default_ai_advanced = array(
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'retry_count' => 3,
            'timeout' => 60,
            'fallback_enabled' => true
        );
        
        if (!get_option('giji_improved_ai_advanced_settings')) {
            update_option('giji_improved_ai_advanced_settings', $default_ai_advanced);
        }
        
        // デフォルトプロンプトテンプレートの設定
        $this->save_default_prompts();
    }
    
    /**
     * デフォルトプロンプトテンプレートの保存
     */
    private function save_default_prompts() {
        $default_prompts = array(
            'content_prompt' => "助成金情報を基に、わかりやすく魅力的な記事を作成してください。

【助成金名】: [title]
【概要】: [overview]
【補助額上限】: [max_amount]
【募集終了日】: [deadline_text]
【実施組織】: [organization]
【公式URL】: [official_url]

以下の構成で1000-1500文字程度の記事を作成してください：

## この助成金の特徴
- 対象者や利用目的について説明
- 補助額や条件の魅力を伝える

## 申請のポイント
- 申請時の注意点
- 成功のコツ

## まとめ
- なぜこの助成金がおすすめなのか

読者が申請を検討したくなるような、親しみやすい文章でお願いします。",

            'excerpt_prompt' => "以下の助成金情報から、100文字程度の魅力的な抜粋を作成してください。

【助成金名】: [title]
【概要】: [overview]
【補助額上限】: [max_amount]

読者が興味を持ち、詳細を読みたくなるような簡潔で魅力的な文章にしてください。",

            'summary_prompt' => "以下の助成金情報を3行で要約してください。

【助成金名】: [title]
【概要】: [overview]
【補助額上限】: [max_amount]
【募集終了日】: [deadline_text]

各行は50文字程度で、要点を分かりやすくまとめてください。"
        );
        
        foreach ($default_prompts as $key => $prompt) {
            if (!get_option('giji_improved_' . $key)) {
                update_option('giji_improved_' . $key, $prompt);
            }
        }
    }
    
    /**
     * Cronスケジュールの追加
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_6_hours'] = array(
            'interval' => 6 * 60 * 60, // 6時間
            'display' => __('6時間ごと', 'grant-insight-jgrants-importer-improved')
        );
        
        $schedules['every_12_hours'] = array(
            'interval' => 12 * 60 * 60, // 12時間
            'display' => __('12時間ごと', 'grant-insight-jgrants-importer-improved')
        );
        
        return $schedules;
    }
    
    /**
     * 公開メソッド：コンポーネントへのアクセス
     */
    public function get_logger() {
        return $this->logger;
    }
    
    public function get_automation_controller() {
        return $this->automation_controller;
    }
    
    public function get_data_processor() {
        return $this->data_processor;
    }
}

// プラグインの初期化（単一の初期化ポイント）
function giji_improved_init() {
    return Grant_Insight_JGrants_Importer_Improved::get_instance();
}

// 確実にメニューを表示するための緊急フォールバック（完全版が失敗した場合のみ）
add_action('admin_menu', 'giji_improved_emergency_menu', 999);
function giji_improved_emergency_menu() {
    // 既にメニューが存在するかチェック
    global $menu;
    $menu_exists = false;
    
    if (is_array($menu)) {
        foreach ($menu as $menu_item) {
            if (isset($menu_item[2]) && strpos($menu_item[2], 'grant-insight-jgrants-importer-improved') !== false) {
                $menu_exists = true;
                break;
            }
        }
    }
    
    // 完全版のメニューが存在しない場合のみ緊急フォールバックを追加
    if (!$menu_exists) {
        add_menu_page(
            'Jグランツ・インポーター改善版（緊急モード）',
            'Jグランツ・インポーター改善版',
            'manage_options',
            'grant-insight-jgrants-importer-improved',
            'giji_improved_emergency_page',
            'dashicons-money-alt',
            30
        );
        
        // サブメニューも追加（管理者のみ）
        add_submenu_page(
            'grant-insight-jgrants-importer-improved',
            '設定',
            '設定',
            'manage_options',
            'giji-improved-settings',
            'giji_improved_emergency_settings_page'
        );
    }
}

// 緊急用ページ表示関数
function giji_improved_emergency_page() {
    echo '<div class="wrap">';
    echo '<h1>Jグランツ・インポーター改善版（緊急モード）</h1>';
    echo '<div class="notice notice-warning">';
    echo '<p><strong>緊急モードで動作中</strong></p>';
    echo '<p>完全版の管理画面の初期化に失敗したため、緊急モードで動作しています。</p>';
    echo '<p>プラグインを再インストールするか、サーバーのエラーログを確認してください。</p>';
    echo '</div>';
    
    // 基本的な操作フォーム
    echo '<div class="card">';
    echo '<h2>基本操作</h2>';
    echo '<p>キーワード検索とインポートが実行できます：</p>';
    echo '<form method="post" action="">';
    wp_nonce_field('giji_emergency_action', 'giji_emergency_nonce');
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="keyword">検索キーワード</label></th>';
    echo '<td><input type="text" id="keyword" name="keyword" value="補助金" class="regular-text"></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<th><label for="count">取得件数</label></th>';
    echo '<td><input type="number" id="count" name="count" value="5" min="1" max="20" class="small-text"></td>';
    echo '</tr>';
    echo '</table>';
    echo '<p class="submit">';
    echo '<input type="submit" name="giji_emergency_import" class="button-primary" value="手動インポート実行">';
    echo '</p>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    
    // 簡易インポート処理
    if (isset($_POST['giji_emergency_import']) && wp_verify_nonce($_POST['giji_emergency_nonce'], 'giji_emergency_action')) {
        $keyword = sanitize_text_field($_POST['keyword']);
        $count = intval($_POST['count']);
        echo '<div class="notice notice-success">';
        echo '<p>キーワード「' . esc_html($keyword) . '」で' . $count . '件のインポートを実行しました。（デモモード）</p>';
        echo '</div>';
    }
}

// 緊急用設定ページ
function giji_improved_emergency_settings_page() {
    echo '<div class="wrap">';
    echo '<h1>設定</h1>';
    echo '<div class="notice notice-info">';
    echo '<p>プラグインの基本設定画面です。完全版では詳細なAI設定が可能です。</p>';
    echo '</div>';
    echo '</div>';
}

// 下位互換性のための関数
function giji_improved_get_instance() {
    return Grant_Insight_JGrants_Importer_Improved::get_instance();
}

// エラーハンドリング
if (!class_exists('WP_Error')) {
    // WordPressが完全に読み込まれていない場合のフォールバック
    function giji_improved_error_fallback($message) {
        error_log('Grant Insight Jグランツ・インポーター改善版エラー: ' . $message);
    }
}

// プラグインのヘルスチェック
function giji_improved_health_check() {
    $health = array(
        'plugin_version' => GIJI_IMPROVED_PLUGIN_VERSION,
        'wp_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'wp_debug' => WP_DEBUG,
        'giji_debug' => GIJI_DEBUG
    );
    
    return $health;
}

// Cronジョブの健全性チェック
function giji_improved_check_cron_health() {
    if (!wp_next_scheduled('giji_improved_auto_import_hook')) {
        $schedule = get_option('giji_improved_cron_schedule', 'daily');
        if ($schedule !== 'disabled') {
            wp_schedule_event(time(), $schedule, 'giji_improved_auto_import_hook');
        }
    }
}

// WordPress初期化後にCronチェックを実行
add_action('wp_loaded', 'giji_improved_check_cron_health');

// PHP致命的エラーの処理
function giji_improved_shutdown_handler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('Grant Insight Jグランツ・インポーター改善版で致命的エラーが発生: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
    }
}
register_shutdown_function('giji_improved_shutdown_handler');

// アクティベーション・ディアクティベーションフックの登録（ファイルスコープ）
register_activation_hook(__FILE__, array('Grant_Insight_JGrants_Importer_Improved', 'activate'));
register_deactivation_hook(__FILE__, array('Grant_Insight_JGrants_Importer_Improved', 'deactivate'));

// プラグインの初期化（単一の初期化ポイント）
add_action('plugins_loaded', 'giji_improved_init');