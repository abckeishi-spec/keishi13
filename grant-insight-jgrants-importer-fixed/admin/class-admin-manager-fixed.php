<?php
/**
 * 管理画面UIクラス（修正版）- セキュリティ強化
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Fixed_Admin_Manager extends GIJI_Singleton_Base {
    
    private $automation_controller;
    private $logger;
    private $security_manager;
    private $menu_hook_suffix;
    
    protected function init() {
        // 管理画面でのみ動作
        if (!is_admin()) {
            return;
        }
        
        // ユーザー権限の確認
        if (!current_user_can('manage_options')) {
            error_log('GIJI Fixed: User does not have manage_options capability');
            return;
        }
        
        error_log('GIJI Fixed: Admin Manager init() called for user ID: ' . get_current_user_id());
        
        // 依存関係の安全な初期化
        $this->init_dependencies();
        
        // 管理画面フックの登録（優先度を高く設定）
        add_action('admin_menu', array($this, 'add_admin_menu'), 5);
        add_action('admin_init', array($this, 'register_settings'), 5);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // セキュリティ強化されたAJAX処理の登録
        $this->register_ajax_handlers();
        
        // 管理画面の通知
        add_action('admin_notices', array($this, 'admin_notices'));
        
        error_log('GIJI Fixed: Admin Manager initialization completed');
    }
    
    /**
     * 依存関係の安全な初期化
     */
    private function init_dependencies() {
        // セキュリティマネージャー
        if (class_exists('GIJI_Fixed_Security_Manager')) {
            try {
                $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
                error_log('GIJI Fixed: Security Manager initialized');
            } catch (Exception $e) {
                error_log('GIJI Fixed: Security Manager init failed: ' . $e->getMessage());
            }
        }
        
        // ロガー
        if (class_exists('GIJI_Fixed_Logger')) {
            try {
                $this->logger = GIJI_Fixed_Logger::get_instance();
                error_log('GIJI Fixed: Logger initialized');
            } catch (Exception $e) {
                error_log('GIJI Fixed: Logger init failed: ' . $e->getMessage());
            }
        }
        
        // 自動化コントローラー
        if (class_exists('GIJI_Fixed_Automation_Controller')) {
            try {
                $this->automation_controller = GIJI_Fixed_Automation_Controller::get_instance();
                error_log('GIJI Fixed: Automation Controller initialized');
            } catch (Exception $e) {
                error_log('GIJI Fixed: Automation Controller init failed: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * AJAX ハンドラーの登録
     */
    private function register_ajax_handlers() {
        $ajax_actions = array(
            'giji_fixed_manual_import' => 'handle_manual_import',
            'giji_fixed_manual_publish' => 'handle_manual_publish', 
            'giji_fixed_bulk_delete_drafts' => 'handle_bulk_delete_drafts',
            'giji_fixed_test_api_keys' => 'handle_test_api_keys',
            'giji_fixed_save_settings' => 'handle_save_settings',
            'giji_fixed_clear_logs' => 'handle_clear_logs',
            'giji_fixed_export_logs' => 'handle_export_logs'
        );
        
        foreach ($ajax_actions as $action => $method) {
            add_action("wp_ajax_{$action}", array($this, $method));
        }
    }
    
    /**
     * 管理画面メニューの追加（セキュリティ強化版）
     */
    public function add_admin_menu() {
        error_log('GIJI Fixed: add_admin_menu() called');
        
        // 権限の再確認
        if (!current_user_can('manage_options')) {
            error_log('GIJI Fixed: User lacks manage_options capability in add_admin_menu');
            return;
        }
        
        // メインメニューページの追加
        $this->menu_hook_suffix = add_menu_page(
            'Grant Insight Jグランツ・インポーター 修正版', // page_title
            'Jグランツ修正版', // menu_title
            'manage_options', // capability
            'grant-insight-jgrants-importer-fixed', // menu_slug
            array($this, 'display_main_page'), // callback
            'dashicons-money-alt', // icon
            25 // position（ダッシュボードの後、投稿の前）
        );
        
        if ($this->menu_hook_suffix) {
            error_log('GIJI Fixed: Main menu page added successfully: ' . $this->menu_hook_suffix);
            
            // サブメニューの追加
            add_submenu_page(
                'grant-insight-jgrants-importer-fixed',
                '設定',
                '設定',
                'manage_options',
                'giji-fixed-settings',
                array($this, 'display_settings_page')
            );
            
            add_submenu_page(
                'grant-insight-jgrants-importer-fixed', 
                'ログ',
                'ログ',
                'manage_options',
                'giji-fixed-logs',
                array($this, 'display_logs_page')
            );
            
            // ページ固有のスクリプト読み込み
            add_action('load-' . $this->menu_hook_suffix, array($this, 'load_admin_page'));
            
        } else {
            error_log('GIJI Fixed: Failed to add main menu page');
        }
    }
    
    /**
     * 管理画面ページ読み込み時の処理
     */
    public function load_admin_page() {
        error_log('GIJI Fixed: Admin page loaded');
        
        // ヘルプタブの追加
        $screen = get_current_screen();
        $screen->add_help_tab(array(
            'id' => 'giji-fixed-help',
            'title' => 'ヘルプ',
            'content' => '<p>Grant Insight JGrants Importer Fixed の使い方説明</p>'
        ));
    }
    
    /**
     * 設定の登録
     */
    public function register_settings() {
        error_log('GIJI Fixed: register_settings() called');
        
        // 設定グループの登録
        register_setting('giji_fixed_settings_group', 'giji_fixed_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
        
        // 設定セクションの追加
        add_settings_section(
            'giji_fixed_main_section',
            'メイン設定',
            array($this, 'settings_section_callback'),
            'giji-fixed-settings'
        );
        
        // 設定フィールドの追加
        add_settings_field(
            'openai_api_key',
            'OpenAI APIキー',
            array($this, 'api_key_field_callback'),
            'giji-fixed-settings',
            'giji_fixed_main_section',
            array('label_for' => 'openai_api_key')
        );
    }
    
    /**
     * メイン管理画面の表示
     */
    public function display_main_page() {
        // セキュリティチェック
        if (!current_user_can('manage_options')) {
            wp_die(__('このページにアクセスする権限がありません。'));
        }
        
        error_log('GIJI Fixed: Main page displayed for user: ' . get_current_user_id());
        
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html(get_admin_page_title()); ?>
                <span class="title-count theme-count"><?php echo GIJI_FIXED_VERSION; ?></span>
            </h1>
            
            <div class="notice notice-success">
                <p><strong>✅ プラグインが正常に動作しています！</strong></p>
                <p>URL: <code><?php echo admin_url('admin.php?page=grant-insight-jgrants-importer-fixed'); ?></code></p>
                <p>ユーザー: <?php echo wp_get_current_user()->display_name; ?> (ID: <?php echo get_current_user_id(); ?>)</p>
            </div>
            
            <div class="giji-fixed-dashboard">
                <div class="giji-fixed-stats">
                    <h2>統計情報</h2>
                    <?php $this->display_stats(); ?>
                </div>
                
                <div class="giji-fixed-actions">
                    <h2>アクション</h2>
                    <?php $this->display_actions(); ?>
                </div>
                
                <div class="giji-fixed-status">
                    <h2>システム状態</h2>
                    <?php $this->display_system_status(); ?>
                </div>
            </div>
        </div>
        
        <style>
        .giji-fixed-dashboard {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        .giji-fixed-stats, .giji-fixed-actions, .giji-fixed-status {
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 20px;
            border-radius: 4px;
        }
        .giji-fixed-status {
            grid-column: 1 / -1;
        }
        .stat-box {
            display: inline-block;
            background: #f0f0f1;
            padding: 15px;
            margin: 5px;
            border-radius: 4px;
            text-align: center;
            min-width: 120px;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #135e96;
        }
        </style>
        <?php
    }
    
    /**
     * 統計情報の表示
     */
    private function display_stats() {
        $stats = array(
            'posts_total' => wp_count_posts()->publish,
            'drafts' => wp_count_posts()->draft,
            'users' => count_users()['total_users']
        );
        ?>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['posts_total']; ?></div>
                <div class="stat-label">公開投稿</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['drafts']; ?></div>
                <div class="stat-label">下書き</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['users']; ?></div>
                <div class="stat-label">ユーザー</div>
            </div>
        </div>
        <?php
    }
    
    /**
     * アクションボタンの表示
     */
    private function display_actions() {
        $nonce = wp_create_nonce('giji_fixed_action_nonce');
        ?>
        <div class="action-buttons">
            <button type="button" class="button button-primary" id="giji-manual-import">
                手動インポート
            </button>
            <button type="button" class="button" id="giji-test-api">
                API接続テスト
            </button>
            <a href="<?php echo admin_url('admin.php?page=giji-fixed-settings'); ?>" class="button">
                設定
            </a>
            <a href="<?php echo admin_url('admin.php?page=giji-fixed-logs'); ?>" class="button">
                ログ確認
            </a>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#giji-manual-import').click(function() {
                alert('手動インポート機能（実装予定）');
            });
            
            $('#giji-test-api').click(function() {
                alert('API接続テスト機能（実装予定）');
            });
        });
        </script>
        <?php
    }
    
    /**
     * システム状態の表示
     */
    private function display_system_status() {
        ?>
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
                    <td>プラグインバージョン</td>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span></td>
                    <td><?php echo GIJI_FIXED_VERSION; ?></td>
                </tr>
                <tr>
                    <td>WordPress バージョン</td>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span></td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <td>PHP バージョン</td>
                    <td><span class="dashicons dashicons-yes-alt" style="color:green;"></span></td>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <td>セキュリティマネージャー</td>
                    <td>
                        <?php if ($this->security_manager): ?>
                            <span class="dashicons dashicons-yes-alt" style="color:green;"></span>
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color:orange;"></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->security_manager ? '動作中' : '未初期化'; ?></td>
                </tr>
                <tr>
                    <td>ログシステム</td>
                    <td>
                        <?php if ($this->logger): ?>
                            <span class="dashicons dashicons-yes-alt" style="color:green;"></span>
                        <?php else: ?>
                            <span class="dashicons dashicons-warning" style="color:orange;"></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->logger ? '動作中' : '未初期化'; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * 設定画面の表示
     */
    public function display_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('このページにアクセスする権限がありません。'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('giji_fixed_settings_group');
                do_settings_sections('giji-fixed-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * ログ画面の表示
     */
    public function display_logs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('このページにアクセスする権限がありません。'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p>ログ機能は実装中です。</p>
        </div>
        <?php
    }
    
    /**
     * スクリプトとスタイルの読み込み
     */
    public function enqueue_admin_scripts($hook) {
        // このプラグインの管理画面でのみ読み込み
        if (strpos($hook, 'grant-insight-jgrants-importer-fixed') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');
        
        error_log('GIJI Fixed: Admin scripts enqueued for hook: ' . $hook);
    }
    
    /**
     * 管理画面通知
     */
    public function admin_notices() {
        if (get_current_screen()->id === $this->menu_hook_suffix) {
            // プラグイン固有の通知をここに追加
        }
    }
    
    /**
     * 設定値のサニタイゼーション
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['openai_api_key'])) {
            $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
        }
        
        return $sanitized;
    }
    
    /**
     * 設定セクションのコールバック
     */
    public function settings_section_callback() {
        echo '<p>プラグインの基本設定を行います。</p>';
    }
    
    /**
     * APIキーフィールドのコールバック
     */
    public function api_key_field_callback($args) {
        $options = get_option('giji_fixed_settings');
        $value = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
        ?>
        <input type="password" 
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="giji_fixed_settings[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <p class="description">OpenAI APIキーを入力してください（オプション）</p>
        <?php
    }
    
    // AJAX ハンドラーのスタブ（後で実装）
    public function handle_manual_import() {
        wp_send_json_success(array('message' => '手動インポート機能は実装中です'));
    }
    
    public function handle_manual_publish() {
        wp_send_json_success(array('message' => '手動公開機能は実装中です'));
    }
    
    public function handle_bulk_delete_drafts() {
        wp_send_json_success(array('message' => '一括削除機能は実装中です'));
    }
    
    public function handle_test_api_keys() {
        wp_send_json_success(array('message' => 'APIテスト機能は実装中です'));
    }
    
    public function handle_save_settings() {
        wp_send_json_success(array('message' => '設定保存機能は実装中です'));
    }
    
    public function handle_clear_logs() {
        wp_send_json_success(array('message' => 'ログクリア機能は実装中です'));
    }
    
    public function handle_export_logs() {
        wp_send_json_success(array('message' => 'ログエクスポート機能は実装中です'));
    }
}