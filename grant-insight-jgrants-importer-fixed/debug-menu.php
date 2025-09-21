<?php
/**
 * 緊急用管理画面メニュー
 */

// 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// 緊急用メニューの追加
add_action('admin_menu', 'giji_fixed_emergency_menu');

function giji_fixed_emergency_menu() {
    add_menu_page(
        'GIJI Fixed Emergency',
        'GIJI 緊急アクセス',
        'manage_options',
        'giji-fixed-emergency',
        'giji_fixed_emergency_page',
        'dashicons-sos',
        99
    );
}

function giji_fixed_emergency_page() {
    ?>
    <div class="wrap">
        <h1>GIJI Fixed 緊急アクセス</h1>
        
        <div class="notice notice-info">
            <p><strong>正常なメニューURL:</strong> 
                <a href="<?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?>">
                    <?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?>
                </a>
            </p>
        </div>
        
        <h2>プラグイン状態確認</h2>
        <table class="widefat">
            <tr>
                <td><strong>管理画面マネージャークラス</strong></td>
                <td><?php echo class_exists('GIJI_Fixed_Admin_Manager') ? '✅ 存在' : '❌ 不存在'; ?></td>
            </tr>
            <tr>
                <td><strong>シングルトンベース</strong></td>
                <td><?php echo class_exists('GIJI_Singleton_Base') ? '✅ 存在' : '❌ 不存在'; ?></td>
            </tr>
            <tr>
                <td><strong>セキュリティマネージャー</strong></td>
                <td><?php echo class_exists('GIJI_Fixed_Security_Manager') ? '✅ 存在' : '❌ 不存在'; ?></td>
            </tr>
            <tr>
                <td><strong>ログファイル確認</strong></td>
                <td>
                    <?php 
                    $log_file = WP_CONTENT_DIR . '/debug.log';
                    if (file_exists($log_file)) {
                        $log_content = file_get_contents($log_file);
                        $giji_logs = array();
                        $lines = explode("\n", $log_content);
                        foreach ($lines as $line) {
                            if (strpos($line, 'GIJI Fixed:') !== false) {
                                $giji_logs[] = $line;
                            }
                        }
                        echo count($giji_logs) . ' 件のGIJIログエントリ';
                        if (count($giji_logs) > 0) {
                            echo '<details><summary>最新5件表示</summary><pre>';
                            echo implode("\n", array_slice($giji_logs, -5));
                            echo '</pre></details>';
                        }
                    } else {
                        echo '❌ debug.logファイルなし';
                    }
                    ?>
                </td>
            </tr>
        </table>
        
        <h2>手動初期化テスト</h2>
        <p>
            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=giji-fixed-emergency&action=test_init'), 'giji_test_init'); ?>" class="button button-primary">
                管理画面マネージャーを手動初期化
            </a>
        </p>
        
        <?php
        if (isset($_GET['action']) && $_GET['action'] === 'test_init' && wp_verify_nonce($_GET['_wpnonce'], 'giji_test_init')) {
            echo '<div class="notice notice-success"><p>手動初期化を実行中...</p></div>';
            
            if (class_exists('GIJI_Fixed_Admin_Manager')) {
                try {
                    $admin_manager = GIJI_Fixed_Admin_Manager::get_instance();
                    echo '<div class="notice notice-success"><p>✅ 管理画面マネージャー初期化成功！</p></div>';
                } catch (Exception $e) {
                    echo '<div class="notice notice-error"><p>❌ 初期化エラー: ' . esc_html($e->getMessage()) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>❌ GIJI_Fixed_Admin_Managerクラスが見つかりません</p></div>';
            }
        }
        ?>
        
    </div>
    <?php
}