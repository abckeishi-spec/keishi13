<?php
/**
 * Plugin Name: Grant Insight JGrants Importer Fixed - Test Version
 * Description: テスト用の最小限プラグイン
 * Version: 2.1.0-test
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// 基本定数の定義
define('GIJI_FIXED_TEST_DIR', plugin_dir_path(__FILE__));

// シングルトンベースクラスの読み込み
if (file_exists(GIJI_FIXED_TEST_DIR . 'includes/class-singleton-base.php')) {
    require_once GIJI_FIXED_TEST_DIR . 'includes/class-singleton-base.php';
}

// テスト用メインクラス
class GIJI_Fixed_Test_Plugin {
    
    public function __construct() {
        add_action('admin_notices', array($this, 'show_activation_notice'));
    }
    
    public function show_activation_notice() {
        echo '<div class="notice notice-success"><p>Grant Insight JGrants Importer Fixed - テストバージョンが正常に読み込まれました！</p></div>';
    }
}

// プラグイン初期化
add_action('plugins_loaded', function() {
    new GIJI_Fixed_Test_Plugin();
});

// アクティベーションフック
register_activation_hook(__FILE__, function() {
    add_option('giji_fixed_test_activated', current_time('mysql'));
    error_log('GIJI Fixed Test: Plugin activated successfully at ' . current_time('mysql'));
});