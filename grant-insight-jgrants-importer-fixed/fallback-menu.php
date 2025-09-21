<?php
/**
 * フォールバック管理画面メニュー
 * メインの管理画面が表示されない場合の緊急対応
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * フォールバックメニューの追加
 */
add_action('admin_menu', 'giji_fixed_add_fallback_menu', 1);

function giji_fixed_add_fallback_menu() {
    // 権限チェック
    if (!current_user_can('manage_options')) {
        return;
    }
    
    error_log('GIJI Fixed Fallback: Adding fallback menu');
    
    // フォールバックメニューの追加
    $hook = add_menu_page(
        'GIJI Fixed フォールバック',
        'GIJI 助成金管理',
        'manage_options',
        'giji-fixed-fallback',
        'giji_fixed_fallback_page',
        'dashicons-money-alt',
        26
    );
    
    if ($hook) {
        error_log('GIJI Fixed Fallback: Menu added successfully');
        
        // サブメニュー
        add_submenu_page(
            'giji-fixed-fallback',
            'メイン画面',
            'メイン画面',
            'manage_options',
            'grant-insight-jgrants-importer-fixed',
            'giji_fixed_main_page_wrapper'
        );
        
        add_submenu_page(
            'giji-fixed-fallback',
            '緊急診断',
            '緊急診断',
            'manage_options',
            'giji-fixed-diagnosis',
            'giji_fixed_diagnosis_page'
        );
    }
}

/**
 * フォールバックページの表示
 */
function giji_fixed_fallback_page() {
    ?>
    <div class="wrap">
        <h1>Grant Insight JGrants Importer Fixed - フォールバックメニュー</h1>
        
        <div class="notice notice-info">
            <p><strong>このフォールバックメニューが表示されている理由：</strong></p>
            <ul>
                <li>メインの管理画面メニューが正常に表示されていない可能性があります</li>
                <li>権限やセキュリティの問題が発生している可能性があります</li>
                <li>プラグインの初期化プロセスに問題がある可能性があります</li>
            </ul>
        </div>
        
        <div class="card">
            <h2>アクセス方法</h2>
            <table class="form-table">
                <tr>
                    <th>メイン画面URL</th>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?>">
                            <?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th>あなたのサイト</th>
                    <td><?php echo home_url(); ?></td>
                </tr>
                <tr>
                    <th>現在のユーザー</th>
                    <td>
                        <?php 
                        $user = wp_get_current_user();
                        echo $user->display_name . ' (ID: ' . $user->ID . ')';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>権限</th>
                    <td>
                        <?php if (current_user_can('manage_options')): ?>
                            <span style="color: green;">✅ 管理者権限あり</span>
                        <?php else: ?>
                            <span style="color: red;">❌ 管理者権限なし</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2>クイックアクション</h2>
            <p>
                <a href="<?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?>" 
                   class="button button-primary">
                    メイン画面にアクセス
                </a>
                <a href="<?php echo admin_url('admin.php?page=giji-fixed-diagnosis'); ?>" 
                   class="button">
                    システム診断
                </a>
                <a href="<?php echo admin_url('plugins.php'); ?>" 
                   class="button">
                    プラグイン管理
                </a>
            </p>
        </div>
    </div>
    <?php
}

/**
 * メイン画面のラッパー
 */
function giji_fixed_main_page_wrapper() {
    if (class_exists('GIJI_Fixed_Admin_Manager')) {
        $admin_manager = GIJI_Fixed_Admin_Manager::get_instance();
        if (method_exists($admin_manager, 'display_main_page')) {
            $admin_manager->display_main_page();
            return;
        }
    }
    
    // フォールバック表示
    ?>
    <div class="wrap">
        <h1>Grant Insight JGrants Importer Fixed</h1>
        <div class="notice notice-warning">
            <p>管理画面クラスが初期化されていません。システム診断を実行してください。</p>
        </div>
        <p>
            <a href="<?php echo admin_url('admin.php?page=giji-fixed-diagnosis'); ?>" class="button button-primary">
                システム診断を実行
            </a>
        </p>
    </div>
    <?php
}

/**
 * システム診断ページ
 */
function giji_fixed_diagnosis_page() {
    ?>
    <div class="wrap">
        <h1>システム診断</h1>
        
        <div class="card">
            <h2>プラグイン状態</h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>項目</th>
                        <th>状態</th>
                        <th>詳細</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>管理画面マネージャー</td>
                        <td>
                            <?php if (class_exists('GIJI_Fixed_Admin_Manager')): ?>
                                <span style="color: green;">✅</span>
                            <?php else: ?>
                                <span style="color: red;">❌</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            if (class_exists('GIJI_Fixed_Admin_Manager')) {
                                echo 'クラス存在';
                                if (method_exists('GIJI_Fixed_Admin_Manager', 'instance_exists')) {
                                    echo GIJI_Fixed_Admin_Manager::instance_exists() ? ' (インスタンス化済み)' : ' (未初期化)';
                                }
                            } else {
                                echo 'クラス未読み込み';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>シングルトンベース</td>
                        <td>
                            <?php if (class_exists('GIJI_Singleton_Base')): ?>
                                <span style="color: green;">✅</span>
                            <?php else: ?>
                                <span style="color: red;">❌</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo class_exists('GIJI_Singleton_Base') ? 'クラス存在' : 'クラス未読み込み'; ?></td>
                    </tr>
                    <tr>
                        <td>セキュリティマネージャー</td>
                        <td>
                            <?php if (class_exists('GIJI_Fixed_Security_Manager')): ?>
                                <span style="color: green;">✅</span>
                            <?php else: ?>
                                <span style="color: red;">❌</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo class_exists('GIJI_Fixed_Security_Manager') ? 'クラス存在' : 'クラス未読み込み'; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2>WordPressログ確認</h2>
            <?php
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (file_exists($log_file) && is_readable($log_file)) {
                $log_content = file_get_contents($log_file);
                $giji_logs = array();
                $lines = explode("\n", $log_content);
                
                foreach ($lines as $line) {
                    if (strpos($line, 'GIJI Fixed') !== false) {
                        $giji_logs[] = $line;
                    }
                }
                
                if (!empty($giji_logs)) {
                    echo '<h3>GIJI Fixedログ（最新10件）</h3>';
                    echo '<textarea style="width: 100%; height: 200px;" readonly>';
                    echo esc_textarea(implode("\n", array_slice($giji_logs, -10)));
                    echo '</textarea>';
                } else {
                    echo '<p>GIJI Fixedログエントリが見つかりません。</p>';
                }
            } else {
                echo '<p>debug.logファイルが見つからないか、読み取れません。</p>';
                echo '<p>wp-config.php で WP_DEBUG_LOG を有効にしてください。</p>';
            }
            ?>
        </div>
        
        <div class="card">
            <h2>修復アクション</h2>
            <p>
                <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=giji-fixed-diagnosis&action=force_init'), 'giji_force_init'); ?>" 
                   class="button button-primary">
                    強制初期化実行
                </a>
                <a href="<?php echo admin_url('plugins.php'); ?>" class="button">
                    プラグイン再有効化
                </a>
            </p>
            
            <?php
            if (isset($_GET['action']) && $_GET['action'] === 'force_init' && 
                isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'giji_force_init')) {
                
                echo '<div class="notice notice-info"><p>強制初期化を実行中...</p></div>';
                
                try {
                    // 強制的にローダーを再初期化
                    if (class_exists('GIJI_Fixed_Plugin_Loader')) {
                        $loader = GIJI_Fixed_Plugin_Loader::get_instance();
                        $loader->init_components();
                        $loader->init_admin();
                        echo '<div class="notice notice-success"><p>✅ 強制初期化完了</p></div>';
                    }
                } catch (Exception $e) {
                    echo '<div class="notice notice-error"><p>❌ 強制初期化エラー: ' . esc_html($e->getMessage()) . '</p></div>';
                }
            }
            ?>
        </div>
    </div>
    <?php
}