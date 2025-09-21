<?php
/**
 * 管理画面UIクラス（修正版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Fixed_Admin_Manager extends GIJI_Singleton_Base {
    
    private $automation_controller;
    private $logger;
    private $security_manager;
    
    protected function init() {
        // 依存関係の初期化
        $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
        $this->logger = GIJI_Fixed_Logger::get_instance();
        
        if (class_exists('GIJI_Fixed_Automation_Controller')) {
            $this->automation_controller = GIJI_Fixed_Automation_Controller::get_instance();
        }
        
        // 管理画面フックの登録
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX処理の登録（修正版）
        add_action('wp_ajax_giji_fixed_manual_import', array($this, 'handle_manual_import'));
        add_action('wp_ajax_giji_fixed_manual_publish', array($this, 'handle_manual_publish'));
        add_action('wp_ajax_giji_fixed_bulk_delete_drafts', array($this, 'handle_bulk_delete_drafts'));
        add_action('wp_ajax_giji_fixed_test_api_keys', array($this, 'handle_test_api_keys'));
        add_action('wp_ajax_giji_fixed_save_settings', array($this, 'handle_save_settings'));
        add_action('wp_ajax_giji_fixed_clear_logs', array($this, 'handle_clear_logs'));
        add_action('wp_ajax_giji_fixed_export_logs', array($this, 'handle_export_logs'));
    }
    
    /**
     * 管理画面メニューの追加
     */
    public function add_admin_menu() {
        add_menu_page(
            'Grant Insight Jグランツ・インポーター 修正版',
            'Jグランツ修正版',
            'manage_options',
            'grant-insight-jgrants-importer-fixed',
            array($this, 'display_main_page'),
            'dashicons-money-alt',
            30
        );
        
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
        
        add_submenu_page(
            'grant-insight-jgrants-importer-fixed',
            '統計',
            '統計',
            'manage_options',
            'giji-fixed-statistics',
            array($this, 'display_statistics_page')
        );
    }
    
    /**
     * 設定の登録
     */
    public function register_settings() {
        register_setting('giji_fixed_api_settings', 'giji_fixed_ai_provider');
        register_setting('giji_fixed_api_settings', 'giji_fixed_gemini_model');
        register_setting('giji_fixed_api_settings', 'giji_fixed_openai_model');
        register_setting('giji_fixed_api_settings', 'giji_fixed_claude_model');
        register_setting('giji_fixed_automation_settings', 'giji_fixed_cron_schedule');
        register_setting('giji_fixed_search_settings', 'giji_fixed_search_settings');
    }
    
    /**
     * 管理画面用スクリプトの読み込み
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'grant-insight-jgrants-importer-fixed') === false && 
            strpos($hook, 'giji-fixed-') === false) {
            return;
        }
        
        wp_enqueue_script(
            'giji-fixed-admin-script',
            GIJI_FIXED_PLUGIN_URL . 'assets/admin-fixed.js',
            array('jquery'),
            GIJI_FIXED_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('giji-fixed-admin-script', 'giji_fixed_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('giji_fixed_ajax_nonce')
        ));
        
        wp_enqueue_style(
            'giji-fixed-admin-style',
            GIJI_FIXED_PLUGIN_URL . 'assets/admin-fixed.css',
            array(),
            GIJI_FIXED_PLUGIN_VERSION
        );
    }
    
    /**
     * メインページの表示
     */
    public function display_main_page() {
        $stats = $this->get_basic_statistics();
        
        ?>
        <div class="wrap">
            <h1>Grant Insight Jグランツ・インポーター 修正版</h1>
            
            <div class="notice notice-success">
                <p><strong>修正版の特徴:</strong> 通信エラー修正、重複初期化防止、正確なAPIテスト、安定した設定保存</p>
            </div>
            
            <div class="giji-fixed-dashboard">
                <div class="giji-fixed-stats">
                    <div class="giji-fixed-stat-box">
                        <h3>下書き投稿数</h3>
                        <div class="giji-fixed-stat-number"><?php echo $stats['draft_count']; ?></div>
                    </div>
                    <div class="giji-fixed-stat-box">
                        <h3>公開投稿数</h3>
                        <div class="giji-fixed-stat-number"><?php echo $stats['published_count']; ?></div>
                    </div>
                    <div class="giji-fixed-stat-box">
                        <h3>合計投稿数</h3>
                        <div class="giji-fixed-stat-number"><?php echo $stats['total_count']; ?></div>
                    </div>
                </div>
                
                <div class="giji-fixed-actions">
                    <div class="giji-fixed-action-section">
                        <h3>手動インポート（修正版）</h3>
                        <p>JグランツAPIから最新の助成金情報を取得します。</p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="giji-fixed-import-keyword">キーワード</label></th>
                                <td>
                                    <input type="text" id="giji-fixed-import-keyword" value="補助金" class="regular-text">
                                    <p class="description">検索キーワード（必須、2文字以上）</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="giji-fixed-import-count">取得件数</label></th>
                                <td>
                                    <input type="number" id="giji-fixed-import-count" value="5" min="1" max="50" class="small-text">
                                    <p class="description">一度に取得する最大件数（1-50）</p>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button button-primary" id="giji-fixed-manual-import">修正版でインポート実行</button>
                        <div id="giji-fixed-import-result" class="giji-fixed-result"></div>
                    </div>
                    
                    <div class="giji-fixed-action-section">
                        <h3>手動公開（修正版）</h3>
                        <p>下書き状態の助成金投稿を公開します。</p>
                        <label for="giji-fixed-publish-count">公開件数:</label>
                        <input type="number" id="giji-fixed-publish-count" value="5" min="1" max="<?php echo $stats['draft_count']; ?>">
                        <button type="button" class="button button-primary" id="giji-fixed-manual-publish">修正版で公開実行</button>
                        <div id="giji-fixed-publish-result" class="giji-fixed-result"></div>
                    </div>
                    
                    <div class="giji-fixed-action-section">
                        <h3>下書き一括削除（修正版）</h3>
                        <p>下書き状態の助成金投稿をすべて削除します。</p>
                        <button type="button" class="button button-secondary" id="giji-fixed-bulk-delete" 
                                onclick="return confirm('本当に下書きをすべて削除しますか？この操作は取り消せません。')">修正版で一括削除実行</button>
                        <div id="giji-fixed-delete-result" class="giji-fixed-result"></div>
                    </div>
                </div>
                
                <div class="giji-fixed-status">
                    <h3>システム状況（修正版）</h3>
                    <table class="widefat">
                        <tr>
                            <th>自動インポート実行中</th>
                            <td><?php echo get_transient('giji_fixed_auto_import_running') ? 'はい' : 'いいえ'; ?></td>
                        </tr>
                        <tr>
                            <th>プラグインバージョン</th>
                            <td><?php echo GIJI_FIXED_PLUGIN_VERSION; ?></td>
                        </tr>
                        <tr>
                            <th>WordPress バージョン</th>
                            <td><?php echo get_bloginfo('version'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 設定ページの表示（修正版）
     */
    public function display_settings_page() {
        $ai_provider = get_option('giji_fixed_ai_provider', 'openai');
        
        ?>
        <div class="wrap">
            <h1>設定（修正版）</h1>
            
            <div class="notice notice-info">
                <p><strong>修正版の改善点:</strong> APIキーの確実な保存、正確なテスト機能、安全な通信処理</p>
            </div>
            
            <div id="giji-fixed-settings-form">
                <h2>API設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="giji_fixed_ai_provider">AIプロバイダー</label></th>
                        <td>
                            <select id="giji_fixed_ai_provider" name="giji_fixed_ai_provider">
                                <option value="openai" <?php selected($ai_provider, 'openai'); ?>>OpenAI ChatGPT</option>
                                <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>>Google Gemini</option>
                                <option value="claude" <?php selected($ai_provider, 'claude'); ?>>Anthropic Claude</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="api-key-row openai-row" <?php echo $ai_provider !== 'openai' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_fixed_openai_api_key">OpenAI APIキー</label></th>
                        <td>
                            <input type="password" id="giji_fixed_openai_api_key" class="regular-text" placeholder="sk-...">
                            <p class="description">OpenAI APIキーを入力してください</p>
                        </td>
                    </tr>
                    <tr class="api-key-row gemini-row" <?php echo $ai_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_fixed_gemini_api_key">Gemini APIキー</label></th>
                        <td>
                            <input type="password" id="giji_fixed_gemini_api_key" class="regular-text" placeholder="AIz...">
                            <p class="description">Google Gemini APIキーを入力してください</p>
                        </td>
                    </tr>
                    <tr class="api-key-row claude-row" <?php echo $ai_provider !== 'claude' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_fixed_claude_api_key">Claude APIキー</label></th>
                        <td>
                            <input type="password" id="giji_fixed_claude_api_key" class="regular-text" placeholder="sk-ant-...">
                            <p class="description">Anthropic Claude APIキーを入力してください</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="button" id="giji-fixed-save-settings" class="button-primary">修正版で設定保存</button>
                    <button type="button" id="giji-fixed-test-api-keys" class="button">修正版でAPIテスト</button>
                </p>
                <div id="giji-fixed-settings-result"></div>
                <div id="giji-fixed-api-test-result"></div>
            </div>
        </div>
        <?php
    }
    
    /**
     * ログページの表示
     */
    public function display_logs_page() {
        $logs = $this->logger ? $this->logger->get_logs(50) : array();
        $stats = $this->logger ? $this->logger->get_log_stats() : array();
        
        ?>
        <div class="wrap">
            <h1>ログ（修正版）</h1>
            
            <div class="giji-fixed-log-actions">
                <button type="button" class="button" id="giji-fixed-clear-logs">ログをクリア</button>
                <button type="button" class="button" id="giji-fixed-export-logs">CSVエクスポート</button>
            </div>
            
            <?php if (!empty($stats)): ?>
            <div class="giji-fixed-log-stats">
                <h3>ログ統計（過去7日間）</h3>
                <?php foreach ($stats as $stat): ?>
                    <span class="giji-fixed-log-stat-item">
                        <?php echo esc_html($stat->level); ?>: <?php echo esc_html($stat->count); ?>件
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="giji-fixed-log-viewer">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>日時</th>
                            <th>レベル</th>
                            <th>メッセージ</th>
                            <th>ユーザー</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="log-level-<?php echo esc_attr($log->level); ?>">
                                    <td><?php echo esc_html($log->timestamp); ?></td>
                                    <td><span class="log-level-badge log-level-<?php echo esc_attr($log->level); ?>"><?php echo esc_html(strtoupper($log->level)); ?></span></td>
                                    <td><?php echo esc_html($log->message); ?></td>
                                    <td><?php echo $log->user_id ? get_user_by('id', $log->user_id)->display_name : 'システム'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">ログがありません</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * 統計ページの表示
     */
    public function display_statistics_page() {
        $stats = $this->get_basic_statistics();
        
        ?>
        <div class="wrap">
            <h1>統計（修正版）</h1>
            
            <div class="giji-fixed-statistics">
                <h2>基本統計</h2>
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <td>下書き投稿数</td>
                            <td><?php echo esc_html($stats['draft_count']); ?>件</td>
                        </tr>
                        <tr>
                            <td>公開投稿数</td>
                            <td><?php echo esc_html($stats['published_count']); ?>件</td>
                        </tr>
                        <tr>
                            <td>合計投稿数</td>
                            <td><?php echo esc_html($stats['total_count']); ?>件</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * 基本統計の取得
     */
    private function get_basic_statistics() {
        $draft_count = wp_count_posts('grant');
        $draft_count = isset($draft_count->draft) ? intval($draft_count->draft) : 0;
        
        $published_count = wp_count_posts('grant');
        $published_count = isset($published_count->publish) ? intval($published_count->publish) : 0;
        
        return array(
            'draft_count' => $draft_count,
            'published_count' => $published_count,
            'total_count' => $draft_count + $published_count
        );
    }
    
    /**
     * AJAX: 手動公開処理（修正版）
     */
    public function handle_manual_publish() {
        $validation = $this->security_manager->validate_ajax_request($_POST['nonce'] ?? '', 'giji_fixed_ajax_nonce');
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()));
            return;
        }
        
        try {
            $count = intval($_POST['count'] ?? 5);
            
            if ($count < 1 || $count > 100) {
                wp_send_json_error(array('message' => '公開件数は1～100の間で指定してください。'));
                return;
            }
            
            // 下書き投稿を取得
            $draft_posts = get_posts(array(
                'post_type' => 'grant',
                'post_status' => 'draft',
                'posts_per_page' => $count,
                'orderby' => 'date',
                'order' => 'ASC',
                'fields' => 'ids'
            ));
            
            if (empty($draft_posts)) {
                wp_send_json_success(array(
                    'message' => '公開する下書きがありません。',
                    'results' => array(
                        'success' => 0,
                        'error' => 0,
                        'details' => array()
                    )
                ));
                return;
            }
            
            $results = array(
                'success' => 0,
                'error' => 0,
                'details' => array()
            );
            
            foreach ($draft_posts as $post_id) {
                $post = get_post($post_id);
                if (!$post) continue;
                
                $update_result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ), true);
                
                if (is_wp_error($update_result)) {
                    $results['error']++;
                    $results['details'][] = array(
                        'id' => $post_id,
                        'title' => $post->post_title,
                        'status' => 'error',
                        'message' => $update_result->get_error_message()
                    );
                    
                    $this->logger->error('公開エラー (ID: ' . $post_id . '): ' . $update_result->get_error_message());
                } else {
                    $results['success']++;
                    $results['details'][] = array(
                        'id' => $post_id,
                        'title' => $post->post_title,
                        'status' => 'success'
                    );
                    
                    $this->logger->info('公開成功: ' . $post->post_title);
                }
            }
            
            $message = sprintf('修正版公開処理完了: 成功 %d件, エラー %d件', $results['success'], $results['error']);
            $this->logger->info($message);
            
            wp_send_json_success(array(
                'message' => $message,
                'results' => $results
            ));
            
        } catch (Exception $e) {
            $error_message = '修正版公開処理でエラー発生: ' . $e->getMessage();
            $this->logger->error($error_message);
            
            wp_send_json_error(array(
                'message' => '予期しないエラーが発生しました: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX: APIテスト処理（修正版）
     */
    public function handle_test_api_keys() {
        $validation = $this->security_manager->validate_ajax_request($_POST['nonce'] ?? '', 'giji_fixed_ajax_nonce');
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()));
            return;
        }
        
        try {
            $results = array();
            
            // JグランツAPIテスト
            $jgrants_client = new GIJI_Fixed_JGrants_API_Client($this->logger);
            $results['jgrants'] = $jgrants_client->test_connection();
            
            // AI APIテスト
            $provider = get_option('giji_fixed_ai_provider', 'openai');
            $api_key_map = array(
                'openai' => 'openai_api_key',
                'claude' => 'claude_api_key', 
                'gemini' => 'gemini_api_key'
            );
            
            $api_key = '';
            if (isset($api_key_map[$provider])) {
                $api_key = $this->security_manager->get_api_key($api_key_map[$provider]);
            }
            
            $results['provider'] = $provider;
            $results['api_key_exists'] = !empty($api_key);
            $results['api_key_length'] = $api_key ? strlen($api_key) : 0;
            
            if (empty($api_key)) {
                $results['ai_api'] = false;
                $results['ai_message'] = 'APIキーが設定されていません';
            } else {
                // 実際にAI APIテスト
                $ai_client = GIJI_Fixed_Unified_AI_Client::get_instance($this->logger, $this->security_manager);
                $ai_test_result = $ai_client->test_connection();
                $results['ai_api'] = $ai_test_result;
                $results['ai_message'] = $ai_test_result ? 'API接続成功' : 'API接続失敗';
            }
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'APIテスト実行エラー: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX: 設定保存処理（修正版）
     */
    public function handle_save_settings() {
        $validation = $this->security_manager->validate_ajax_request($_POST['nonce'] ?? '', 'giji_fixed_ajax_nonce');
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()));
            return;
        }
        
        try {
            $saved_count = 0;
            
            // AIプロバイダーの保存
            if (isset($_POST['ai_provider'])) {
                $provider = sanitize_text_field($_POST['ai_provider']);
                if (in_array($provider, array('openai', 'gemini', 'claude'))) {
                    update_option('giji_fixed_ai_provider', $provider);
                    $saved_count++;
                    $this->logger->info('AIプロバイダー保存: ' . $provider);
                }
            }
            
            // APIキーの保存
            $api_keys = array(
                'openai_api_key' => $_POST['openai_api_key'] ?? '',
                'gemini_api_key' => $_POST['gemini_api_key'] ?? '',
                'claude_api_key' => $_POST['claude_api_key'] ?? ''
            );
            
            foreach ($api_keys as $key => $value) {
                $value = sanitize_text_field($value);
                if (!empty($value) && strlen($value) > 10) { // 最小限の長さチェック
                    $result = $this->security_manager->save_api_key($key, $value);
                    if ($result) {
                        $saved_count++;
                        $this->logger->info('APIキー保存成功: ' . $key);
                    }
                }
            }
            
            wp_send_json_success(array(
                'message' => "修正版で{$saved_count}個の設定を保存しました",
                'saved_count' => $saved_count
            ));
            
        } catch (Exception $e) {
            $error_message = '修正版設定保存エラー: ' . $e->getMessage();
            $this->logger->error($error_message);
            
            wp_send_json_error(array(
                'message' => $error_message
            ));
        }
    }
    
    /**
     * AJAX: ログクリア（修正版）
     */
    public function handle_clear_logs() {
        $validation = $this->security_manager->validate_ajax_request($_POST['nonce'] ?? '', 'giji_fixed_ajax_nonce');
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()));
            return;
        }
        
        $result = $this->logger ? $this->logger->clear_logs() : false;
        
        if ($result) {
            wp_send_json_success(array('message' => '修正版でログをクリアしました。'));
        } else {
            wp_send_json_error(array('message' => 'ログのクリアに失敗しました。'));
        }
    }
    
    /**
     * AJAX: ログエクスポート（修正版）
     */
    public function handle_export_logs() {
        $validation = $this->security_manager->validate_ajax_request($_POST['nonce'] ?? '', 'giji_fixed_ajax_nonce');
        if (is_wp_error($validation)) {
            wp_send_json_error(array('message' => $validation->get_error_message()));
            return;
        }
        
        $csv_content = $this->logger ? $this->logger->export_logs('csv') : null;
        
        if ($csv_content) {
            $filename = 'giji_fixed_logs_' . date('Y-m-d_H-i-s') . '.csv';
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Pragma: no-cache');
            header('Expires: 0');
            
            echo "\xEF\xBB\xBF"; // BOM for UTF-8
            echo $csv_content;
            exit;
        }
        
        wp_send_json_error(array('message' => 'エクスポートに失敗しました。'));
    }
    
    /**
     * 他のAJAXハンドラー（簡略化）
     */
    public function handle_manual_import() {
        wp_send_json_error(array('message' => '修正版では未実装です。'));
    }
    
    public function handle_bulk_delete_drafts() {
        wp_send_json_error(array('message' => '修正版では未実装です。'));
    }
}