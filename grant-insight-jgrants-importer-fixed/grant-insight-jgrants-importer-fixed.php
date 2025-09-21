<?php
/**
 * Plugin Name: Grant Insight Jグランツ・インポーター 修正版
 * Plugin URI: https://grant-insight.com/
 * Description: JグランツAPIと統合したAI自動化助成金情報管理システム。通信エラー、重複初期化、設定保存の問題を修正済み。
 * Version: 2.1.0-fixed
 * Author: Grant Insight Team (Fixed)
 * Text Domain: grant-insight-jgrants-importer-fixed
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
define('GIJI_FIXED_PLUGIN_VERSION', '2.1.0-fixed');
define('GIJI_FIXED_PLUGIN_FILE', __FILE__);
define('GIJI_FIXED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIJI_FIXED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GIJI_FIXED_PLUGIN_BASENAME', plugin_basename(__FILE__));

// デバッグモード（開発時のみ有効）
define('GIJI_DEBUG', WP_DEBUG);

/**
 * シングルトンベースクラス（重複初期化防止）
 */
abstract class GIJI_Singleton_Base {
    protected static $instances = array();
    private static $initialization_count = 0;
    
    public static function get_instance() {
        $class = get_called_class();
        
        if (!isset(self::$instances[$class])) {
            self::$initialization_count++;
            
            // 初期化回数制限（異常な重複を防ぐ）
            if (self::$initialization_count > 10) {
                error_log("GIJI FIXED: 異常な初期化回数を検出: {$class} (回数: " . self::$initialization_count . ")");
                return null;
            }
            
            self::$instances[$class] = new $class();
            error_log("GIJI FIXED: 正常初期化完了: {$class} (初期化回数: " . self::$initialization_count . ")");
        }
        
        return self::$instances[$class];
    }
    
    protected function __construct() {
        // 継承クラスで実装
    }
    
    // クローンを無効化
    private function __clone() {}
    
    // シリアライゼーションを無効化
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * フォールバックロガークラス
 */
class GIJI_Fixed_Fallback_Logger {
    
    public function log($message, $level = 'error', $context = array()) {
        $formatted_message = "[GIJI FIXED " . strtoupper($level) . "] " . $message;
        if (!empty($context)) {
            $formatted_message .= " | Context: " . json_encode($context);
        }
        error_log($formatted_message);
    }
    
    public function create_log_tables() {
        error_log("[GIJI FIXED] フォールバックロガー使用中のため、ログテーブル作成をスキップします");
    }
}

/**
 * メインプラグインクラス（修正版）
 */
class Grant_Insight_JGrants_Importer_Fixed extends GIJI_Singleton_Base {
    
    private $jgrants_client;
    private $ai_client;
    private $data_processor;
    private $automation_controller;
    private $admin_manager;
    private $logger;
    private $security_manager;
    private $emergency_mode = false;
    private $dependency_errors = array();
    private $initialized = false;
    
    protected function __construct() {
        // 重複初期化防止チェック
        if ($this->initialized) {
            error_log('GIJI FIXED: 重複初期化を防止しました');
            return;
        }
        
        $this->initialized = true;
        $this->init();
    }
    
    /**
     * プラグイン初期化（修正版）
     */
    public function init() {
        // 言語ファイルの読み込み
        load_plugin_textdomain('grant-insight-jgrants-importer-fixed', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // クラスファイルの読み込み
        $this->load_dependencies();
        
        // 単一の初期化ポイント（WordPressの準備完了後）
        add_action('wp_loaded', array($this, 'init_components_once'), 10);
        
        // 管理画面の初期化（admin_init時に一度だけ）
        if (is_admin()) {
            add_action('admin_init', array($this, 'init_admin_once'), 1);
        }
        
        // Cronスケジュールの追加
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // 自動実行制御フック
        add_action('giji_fixed_auto_import_hook', array($this, 'controlled_auto_import'));
    }
    
    /**
     * コンポーネントの一度だけ初期化
     */
    public function init_components_once() {
        static $components_initialized = false;
        
        if ($components_initialized) {
            return;
        }
        
        $components_initialized = true;
        
        try {
            // セキュリティマネージャーの初期化
            if (class_exists('GIJI_Fixed_Security_Manager')) {
                $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
            }
            
            // ロガーの初期化
            if (class_exists('GIJI_Fixed_Logger')) {
                $this->logger = GIJI_Fixed_Logger::get_instance();
            }
            
            // APIクライアントの初期化
            if (class_exists('GIJI_Fixed_JGrants_API_Client')) {
                $this->jgrants_client = new GIJI_Fixed_JGrants_API_Client($this->logger);
            }
            
            if (class_exists('GIJI_Fixed_Unified_AI_Client')) {
                $this->ai_client = GIJI_Fixed_Unified_AI_Client::get_instance($this->logger, $this->security_manager);
            }
            
            // データプロセッサーの初期化
            if (class_exists('GIJI_Fixed_Grant_Data_Processor')) {
                $this->data_processor = new GIJI_Fixed_Grant_Data_Processor(
                    $this->jgrants_client,
                    $this->ai_client,
                    $this->logger
                );
            }
            
            // 自動化コントローラーの初期化
            if (class_exists('GIJI_Fixed_Automation_Controller')) {
                $this->automation_controller = new GIJI_Fixed_Automation_Controller(
                    $this->data_processor,
                    $this->logger
                );
            }
            
            // 投稿タイプとタクソノミーの登録
            $this->register_post_types_and_taxonomies();
            
            if ($this->logger) {
                $this->logger->log('Grant Insight Jグランツ・インポーター修正版のコンポーネント初期化完了');
            }
            
        } catch (Exception $e) {
            error_log('Grant Insight Jグランツ・インポーター修正版初期化エラー: ' . $e->getMessage());
            
            if (!$this->logger) {
                $this->logger = new GIJI_Fixed_Fallback_Logger();
                $this->logger->log('初期化中にエラーが発生したため、フォールバックロガーを使用: ' . $e->getMessage(), 'warning');
            }
        }
    }
    
    /**
     * 管理画面の一度だけ初期化
     */
    public function init_admin_once() {
        static $admin_initialized = false;
        
        if ($admin_initialized) {
            return;
        }
        
        $admin_initialized = true;
        
        // コンポーネントが未初期化の場合は先に初期化
        if (!$this->automation_controller) {
            $this->init_components_once();
        }
        
        // 管理画面マネージャーの初期化
        if (class_exists('GIJI_Fixed_Admin_Manager') && !$this->admin_manager) {
            try {
                $this->admin_manager = new GIJI_Fixed_Admin_Manager(
                    $this->automation_controller,
                    $this->logger,
                    $this->security_manager
                );
                
                if ($this->logger) {
                    $this->logger->log('修正版管理画面マネージャーを初期化しました');
                }
            } catch (Exception $e) {
                error_log('修正版管理画面マネージャーの初期化エラー: ' . $e->getMessage());
                $this->emergency_mode = true;
                $this->display_emergency_admin();
            }
        }
    }
    
    /**
     * 制御された自動インポート（重複実行防止）
     */
    public function controlled_auto_import() {
        $running_flag = get_transient('giji_fixed_auto_import_running');
        
        if ($running_flag) {
            if ($this->logger) {
                $this->logger->log('自動インポートが既に実行中のためスキップ');
            }
            return;
        }
        
        // 実行フラグを設定（10分間）
        set_transient('giji_fixed_auto_import_running', true, 600);
        
        try {
            if ($this->logger) {
                $this->logger->log('制御された自動インポート開始');
            }
            
            if ($this->automation_controller) {
                $this->automation_controller->execute_auto_import();
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->log('制御された自動インポートでエラー: ' . $e->getMessage(), 'error');
            }
        } finally {
            // 実行フラグをクリア
            delete_transient('giji_fixed_auto_import_running');
            
            if ($this->logger) {
                $this->logger->log('制御された自動インポート完了');
            }
        }
    }
    
    /**
     * 緊急管理画面の表示
     */
    public function display_emergency_admin() {
        add_action('admin_menu', function() {
            add_menu_page(
                'Grant Insight 修正版 (緊急モード)',
                'Jグランツ修正版（緊急）',
                'manage_options',
                'giji-fixed-emergency',
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
            <h1 style="color: #d63638;">🚨 Grant Insight Jグランツ・インポーター 修正版 - 緊急モード</h1>
            
            <div class="notice notice-error">
                <p><strong>重要:</strong> プラグインの初期化中にエラーが発生したため、緊急モードで動作しています。</p>
            </div>
            
            <h2>修正版の特徴</h2>
            <ul>
                <li>重複初期化問題の修正</li>
                <li>通信エラーの改善</li>
                <li>設定保存の修正</li>
                <li>APIテストの正確性向上</li>
                <li>自動実行の制御機能</li>
            </ul>
            
            <h2>システム情報</h2>
            <table class="widefat">
                <tr><th>WordPress バージョン</th><td><?php echo get_bloginfo('version'); ?></td></tr>
                <tr><th>PHP バージョン</th><td><?php echo PHP_VERSION; ?></td></tr>
                <tr><th>プラグイン バージョン</th><td>2.1.0-fixed (修正版)</td></tr>
                <tr><th>モード</th><td style="color: #d63638;">緊急モード</td></tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * 必要なクラスファイルを読み込み（修正版）
     */
    private function load_dependencies() {
        // 修正版ファイルのマッピング
        $dependency_map = array(
            'includes/class-security-manager-fixed.php' => 'GIJI_Fixed_Security_Manager',
            'includes/class-logger-fixed.php' => 'GIJI_Fixed_Logger',
            'includes/class-jgrants-api-client-fixed.php' => 'GIJI_Fixed_JGrants_API_Client',
            'includes/class-unified-ai-client-fixed.php' => 'GIJI_Fixed_Unified_AI_Client',
            'includes/class-grant-data-processor-fixed.php' => 'GIJI_Fixed_Grant_Data_Processor',
            'includes/class-automation-controller-fixed.php' => 'GIJI_Fixed_Automation_Controller',
            'admin/class-admin-manager-fixed.php' => 'GIJI_Fixed_Admin_Manager'
        );
        
        $loaded_files = array();
        $missing_files = array();
        
        foreach ($dependency_map as $file => $expected_class) {
            $file_path = GIJI_FIXED_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
                $loaded_files[] = $file;
                
                if (!class_exists($expected_class)) {
                    $missing_files[] = array(
                        'file' => $file,
                        'class' => $expected_class
                    );
                }
            } else {
                $missing_files[] = array(
                    'file' => $file,
                    'class' => $expected_class
                );
            }
        }
        
        // ACFフィールド設定の読み込み
        $acf_file = GIJI_FIXED_PLUGIN_DIR . 'acf-fields-fixed.php';
        if (file_exists($acf_file)) {
            require_once $acf_file;
        }
        
        if (!empty($missing_files)) {
            $this->emergency_mode = true;
            $this->dependency_errors = $missing_files;
            
            error_log('GIJI FIXED: 依存関係エラーのため緊急モードに移行');
            foreach ($missing_files as $error) {
                error_log("GIJI FIXED ERROR: ファイルまたはクラスが見つかりません - {$error['file']} ({$error['class']})");
            }
            
            add_action('admin_notices', array($this, 'show_dependency_error_notice'));
        }
        
        return empty($missing_files);
    }
    
    /**
     * 依存関係エラーの管理者通知
     */
    public function show_dependency_error_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div class="notice notice-error is-dismissible">';
        echo '<h3>Grant Insight Jグランツ・インポーター 修正版: 依存関係エラー</h3>';
        echo '<p>プラグインの必要なファイルが見つかりません。修正版のファイル構成を確認してください。</p>';
        echo '</div>';
    }
    
    /**
     * カスタム投稿タイプとタクソノミーの登録
     */
    public function register_post_types_and_taxonomies() {
        // カスタム投稿タイプ「助成金」
        $args = array(
            'labels' => array(
                'name' => __('助成金', 'grant-insight-jgrants-importer-fixed'),
                'singular_name' => __('助成金', 'grant-insight-jgrants-importer-fixed'),
                'add_new' => __('新規追加', 'grant-insight-jgrants-importer-fixed'),
                'add_new_item' => __('新しい助成金を追加', 'grant-insight-jgrants-importer-fixed'),
                'edit_item' => __('助成金を編集', 'grant-insight-jgrants-importer-fixed'),
                'new_item' => __('新しい助成金', 'grant-insight-jgrants-importer-fixed'),
                'view_item' => __('助成金を表示', 'grant-insight-jgrants-importer-fixed'),
                'search_items' => __('助成金を検索', 'grant-insight-jgrants-importer-fixed'),
                'not_found' => __('助成金が見つかりません', 'grant-insight-jgrants-importer-fixed'),
                'not_found_in_trash' => __('ゴミ箱に助成金はありません', 'grant-insight-jgrants-importer-fixed'),
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
                'name' => __('補助対象地域', 'grant-insight-jgrants-importer-fixed'),
                'singular_name' => __('補助対象地域', 'grant-insight-jgrants-importer-fixed'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-prefecture'),
        ));
        
        // カスタムタクソノミー「利用目的」
        register_taxonomy('grant_category', 'grant', array(
            'labels' => array(
                'name' => __('利用目的', 'grant-insight-jgrants-importer-fixed'),
                'singular_name' => __('利用目的', 'grant-insight-jgrants-importer-fixed'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-category'),
        ));
        
        // カスタムタクソノミー「実施組織」
        register_taxonomy('grant_organization', 'grant', array(
            'labels' => array(
                'name' => __('実施組織', 'grant-insight-jgrants-importer-fixed'),
                'singular_name' => __('実施組織', 'grant-insight-jgrants-importer-fixed'),
            ),
            'hierarchical' => true,
            'public' => true,
            'show_in_rest' => true,
            'rewrite' => array('slug' => 'grant-organization'),
        ));
    }
    
    /**
     * Cronスケジュールの追加
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_6_hours'] = array(
            'interval' => 6 * 60 * 60,
            'display' => __('6時間ごと', 'grant-insight-jgrants-importer-fixed')
        );
        
        $schedules['every_12_hours'] = array(
            'interval' => 12 * 60 * 60,
            'display' => __('12時間ごと', 'grant-insight-jgrants-importer-fixed')
        );
        
        return $schedules;
    }
    
    /**
     * プラグイン有効化時の処理
     */
    public static function activate() {
        $plugin = Grant_Insight_JGrants_Importer_Fixed::get_instance();
        $plugin->register_post_types_and_taxonomies();
        
        flush_rewrite_rules();
        
        // デフォルト設定の保存
        self::save_default_settings();
        
        // 修正版のcronイベント設定
        if (!wp_next_scheduled('giji_fixed_auto_import_hook')) {
            wp_schedule_event(time(), 'daily', 'giji_fixed_auto_import_hook');
        }
    }
    
    /**
     * プラグイン無効化時の処理
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('giji_fixed_auto_import_hook');
        flush_rewrite_rules();
    }
    
    /**
     * デフォルト設定の保存
     */
    private static function save_default_settings() {
        $default_settings = array(
            'giji_fixed_auto_import_enabled' => 'no',
            'giji_fixed_cron_schedule' => 'daily',
            'giji_fixed_import_limit' => 50,
            'giji_fixed_keyword_search' => '',
            'giji_fixed_ai_provider' => 'openai',
            'giji_fixed_gemini_model' => 'gemini-1.5-flash',
            'giji_fixed_openai_model' => 'gpt-4o-mini',
            'giji_fixed_claude_model' => 'claude-3-5-sonnet-20241022'
        );
        
        foreach ($default_settings as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
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

// プラグインの初期化
function giji_fixed_init() {
    return Grant_Insight_JGrants_Importer_Fixed::get_instance();
}

// 確実に単一の初期化を行う
add_action('plugins_loaded', 'giji_fixed_init', 1);

// アクティベーション・ディアクティベーションフック
register_activation_hook(__FILE__, array('Grant_Insight_JGrants_Importer_Fixed', 'activate'));
register_deactivation_hook(__FILE__, array('Grant_Insight_JGrants_Importer_Fixed', 'deactivate'));

// 緊急フォールバックメニュー（完全版が失敗した場合のみ）
add_action('admin_menu', 'giji_fixed_emergency_menu', 999);
function giji_fixed_emergency_menu() {
    global $menu;
    $menu_exists = false;
    
    if (is_array($menu)) {
        foreach ($menu as $menu_item) {
            if (isset($menu_item[2]) && (strpos($menu_item[2], 'giji-fixed') !== false || strpos($menu_item[2], 'grant-insight') !== false)) {
                $menu_exists = true;
                break;
            }
        }
    }
    
    if (!$menu_exists) {
        add_menu_page(
            'Jグランツ・インポーター修正版（緊急モード）',
            'Jグランツ修正版',
            'manage_options',
            'giji-fixed-emergency-menu',
            'giji_fixed_emergency_page_content',
            'dashicons-money-alt',
            30
        );
    }
}

function giji_fixed_emergency_page_content() {
    echo '<div class="wrap">';
    echo '<h1>Jグランツ・インポーター修正版（緊急モード）</h1>';
    echo '<div class="notice notice-warning">';
    echo '<p><strong>緊急モードで動作中</strong></p>';
    echo '<p>完全版の管理画面の初期化に失敗したため、緊急モードで動作しています。</p>';
    echo '<p>修正版のファイル構成を確認してください。</p>';
    echo '</div>';
    echo '</div>';
}

// 修正版のヘルスチェック
function giji_fixed_health_check() {
    $health = array(
        'plugin_version' => GIJI_FIXED_PLUGIN_VERSION,
        'wp_version' => get_bloginfo('version'),
        'php_version' => phpversion(),
        'memory_limit' => ini_get('memory_limit'),
        'auto_import_running' => get_transient('giji_fixed_auto_import_running') ? 'yes' : 'no',
        'initialization_count' => count(GIJI_Singleton_Base::$instances ?? array())
    );
    
    return $health;
}

// PHP致命的エラーの処理
function giji_fixed_shutdown_handler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('Grant Insight Jグランツ・インポーター修正版で致命的エラーが発生: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
    }
}
register_shutdown_function('giji_fixed_shutdown_handler');