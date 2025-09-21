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
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// プラグインの基本定数定義
define('GIJI_FIXED_VERSION', '2.1.0-fixed');
define('GIJI_FIXED_PLUGIN_FILE', __FILE__);
define('GIJI_FIXED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIJI_FIXED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GIJI_FIXED_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * プラグインの安全な初期化
 */
class GIJI_Fixed_Plugin_Loader {
    
    private static $instance = null;
    private $loaded_classes = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // アクティベーション・ディアクティベーションフック
        register_activation_hook(GIJI_FIXED_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(GIJI_FIXED_PLUGIN_FILE, array($this, 'deactivate'));
        
        // プラグイン読み込み完了後に初期化
        add_action('plugins_loaded', array($this, 'init'), 10);
        
        // フォールバックメニューシステム（常時有効）
        if (is_admin()) {
            require_once GIJI_FIXED_PLUGIN_DIR . 'fallback-menu.php';
        }
        
        // デバッグ用緊急メニュー（WP_DEBUG時のみ）
        if (defined('WP_DEBUG') && WP_DEBUG && is_admin()) {
            require_once GIJI_FIXED_PLUGIN_DIR . 'debug-menu.php';
        }
    }
    
    /**
     * プラグインの初期化
     */
    public function init() {
        try {
            // 必要なファイルの読み込み
            $this->load_required_files();
            
            // 基本コンポーネントの初期化
            add_action('wp_loaded', array($this, 'init_components'), 10);
            
            // 管理画面の初期化（より早い段階で）
            if (is_admin()) {
                add_action('admin_init', array($this, 'init_admin'), 5);
                add_action('current_screen', array($this, 'ensure_admin_manager'));
            }
            
        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>GIJI Fixed Plugin Error: ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
    
    /**
     * 必要なファイルの読み込み
     */
    private function load_required_files() {
        $files = array(
            'includes/class-singleton-base.php',
            'includes/class-installer.php',
        );
        
        foreach ($files as $file) {
            $filepath = GIJI_FIXED_PLUGIN_DIR . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
                $this->loaded_classes[] = $file;
            } else {
                throw new Exception("Required file not found: {$file}");
            }
        }
    }
    
    /**
     * オプションファイルの読み込み
     */
    private function load_optional_files() {
        $optional_files = array(
            'includes/class-security-manager-fixed.php',
            'includes/class-logger-fixed.php',
            'includes/class-jgrants-api-client-fixed.php',
            'includes/class-ai-client-fixed.php',
            'includes/class-data-processor-fixed.php',
            'includes/class-automation-controller-fixed.php',
            'admin/class-admin-manager-fixed.php'
        );
        
        foreach ($optional_files as $file) {
            $filepath = GIJI_FIXED_PLUGIN_DIR . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
                $this->loaded_classes[] = $file;
            }
        }
    }
    
    /**
     * コンポーネントの初期化
     */
    public function init_components() {
        // オプションファイルを読み込み
        $this->load_optional_files();
        
        // シングルトンクラスの初期化（安全に）
        $this->safe_init_singleton('GIJI_Fixed_Security_Manager');
        $this->safe_init_singleton('GIJI_Fixed_Logger');
        $this->safe_init_singleton('GIJI_Fixed_JGrants_API_Client');
        $this->safe_init_singleton('GIJI_Fixed_AI_Client');
        $this->safe_init_singleton('GIJI_Fixed_Data_Processor');
        $this->safe_init_singleton('GIJI_Fixed_Automation_Controller');
    }
    
    /**
     * 管理画面の初期化
     */
    public function init_admin() {
        error_log('GIJI Fixed: init_admin() called for user: ' . get_current_user_id());
        
        // 管理者権限の確認
        if (!current_user_can('manage_options')) {
            error_log('GIJI Fixed: Current user lacks manage_options capability');
            return;
        }
        
        $this->safe_init_singleton('GIJI_Fixed_Admin_Manager');
    }
    
    /**
     * 管理画面マネージャーの確実な初期化
     */
    public function ensure_admin_manager() {
        if (is_admin() && current_user_can('manage_options')) {
            if (!class_exists('GIJI_Fixed_Admin_Manager') || 
                !GIJI_Fixed_Admin_Manager::instance_exists()) {
                error_log('GIJI Fixed: Force initializing Admin Manager');
                $this->safe_init_singleton('GIJI_Fixed_Admin_Manager');
            }
        }
    }
    
    /**
     * 安全なシングルトン初期化
     */
    private function safe_init_singleton($class_name) {
        try {
            error_log("GIJI Fixed: Attempting to initialize {$class_name}");
            if (class_exists($class_name)) {
                error_log("GIJI Fixed: Class {$class_name} exists");
                if (method_exists($class_name, 'get_instance')) {
                    error_log("GIJI Fixed: get_instance method exists for {$class_name}");
                    $instance = $class_name::get_instance();
                    error_log("GIJI Fixed: Successfully initialized {$class_name}");
                } else {
                    error_log("GIJI Fixed: get_instance method does not exist for {$class_name}");
                }
            } else {
                error_log("GIJI Fixed: Class {$class_name} does not exist");
            }
        } catch (Exception $e) {
            error_log("GIJI Fixed: Failed to initialize {$class_name}: " . $e->getMessage());
        }
    }
    
    /**
     * プラグインアクティベーション
     */
    public function activate() {
        try {
            // インストーラーファイルを読み込み
            if (file_exists(GIJI_FIXED_PLUGIN_DIR . 'includes/class-installer.php')) {
                require_once GIJI_FIXED_PLUGIN_DIR . 'includes/class-installer.php';
            }
            
            // インストーラーの実行
            if (class_exists('GIJI_Fixed_Installer')) {
                GIJI_Fixed_Installer::activate();
            }
            
            // 有効化フラグの設定
            add_option('giji_fixed_activated', current_time('mysql'));
            
        } catch (Exception $e) {
            error_log('GIJI Fixed activation error: ' . $e->getMessage());
        }
    }
    
    /**
     * プラグインディアクティベーション
     */
    public function deactivate() {
        try {
            if (class_exists('GIJI_Fixed_Installer')) {
                GIJI_Fixed_Installer::deactivate();
            }
            
            delete_option('giji_fixed_activated');
            
        } catch (Exception $e) {
            error_log('GIJI Fixed deactivation error: ' . $e->getMessage());
        }
    }
    
    /**
     * デバッグ情報の取得
     */
    public function get_debug_info() {
        return array(
            'version' => GIJI_FIXED_VERSION,
            'loaded_classes' => $this->loaded_classes,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION
        );
    }
}

// プラグインローダーの初期化
GIJI_Fixed_Plugin_Loader::get_instance();

// デバッグ用の admin notice（開発時のみ）
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_notices', function() {
        if (current_user_can('manage_options')) {
            $loader = GIJI_Fixed_Plugin_Loader::get_instance();
            $debug_info = $loader->get_debug_info();
            echo '<div class="notice notice-info"><p>GIJI Fixed Debug: Loaded ' . count($debug_info['loaded_classes']) . ' classes successfully.</p></div>';
        }
    });
}