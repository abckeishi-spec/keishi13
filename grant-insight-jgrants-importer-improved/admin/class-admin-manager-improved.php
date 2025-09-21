<?php
/**
 * 管理画面UIクラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Admin_Manager {
    
    private $automation_controller;
    private $logger;
    private $security_manager;
    
    public function __construct($automation_controller, $logger, $security_manager) {
        $this->automation_controller = $automation_controller;
        $this->logger = $logger;
        $this->security_manager = $security_manager;
        
        // 管理画面フックの登録
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_settings_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX処理の登録
        add_action('wp_ajax_giji_improved_manual_import', array($this, 'handle_manual_import'));
        add_action('wp_ajax_giji_improved_manual_publish', array($this, 'handle_manual_publish'));
        add_action('wp_ajax_giji_improved_bulk_delete_drafts', array($this, 'handle_bulk_delete_drafts'));
        add_action('wp_ajax_giji_improved_test_api_keys', array($this, 'handle_test_api_keys'));
        add_action('wp_ajax_giji_improved_clear_logs', array($this, 'handle_clear_logs'));
        add_action('wp_ajax_giji_improved_export_logs', array($this, 'handle_export_logs'));
    }
    
    /**
     * 管理画面メニューの追加
     */
    public function add_admin_menu() {
        // メインメニューページ
        add_menu_page(
            'Grant Insight Jグランツ・インポーター 改善版',
            'Jグランツ・インポーター改善版',
            'manage_options',
            'grant-insight-jgrants-importer-improved',
            array($this, 'display_main_page'),
            'dashicons-money-alt',
            30
        );
        
        // サブメニューページ
        add_submenu_page(
            'grant-insight-jgrants-importer-improved',
            '設定',
            '設定',
            'manage_options',
            'giji-improved-settings',
            array($this, 'display_settings_page')
        );
        
        add_submenu_page(
            'grant-insight-jgrants-importer-improved',
            'プロンプト管理',
            'プロンプト管理',
            'manage_options',
            'giji-improved-prompts',
            array($this, 'display_prompts_page')
        );
        
        add_submenu_page(
            'grant-insight-jgrants-importer-improved',
            'ログ',
            'ログ',
            'manage_options',
            'giji-improved-logs',
            array($this, 'display_logs_page')
        );
        
        add_submenu_page(
            'grant-insight-jgrants-importer-improved',
            '統計',
            '統計',
            'manage_options',
            'giji-improved-statistics',
            array($this, 'display_statistics_page')
        );
    }
    
    /**
     * 設定の登録
     */
    public function register_settings() {
        // API設定
        register_setting('giji_improved_api_settings', 'giji_improved_ai_provider');
        register_setting('giji_improved_api_settings', 'giji_improved_gemini_model');
        register_setting('giji_improved_api_settings', 'giji_improved_openai_model');
        register_setting('giji_improved_api_settings', 'giji_improved_claude_model');
        
        // 自動化設定
        register_setting('giji_improved_automation_settings', 'giji_improved_cron_schedule');
        register_setting('giji_improved_automation_settings', 'giji_improved_max_process_count');
        
        // 検索設定
        register_setting('giji_improved_search_settings', 'giji_improved_search_settings');
        
        // AI生成設定
        register_setting('giji_improved_ai_settings', 'giji_improved_ai_generation_enabled');
        register_setting('giji_improved_ai_settings', 'giji_improved_ai_advanced_settings');
        
        // プロンプト設定
        $prompt_types = array(
            'content_prompt', 'excerpt_prompt', 'summary_prompt',
            'organization_prompt', 'difficulty_prompt', 'success_rate_prompt',
            'keywords_prompt', 'target_audience_prompt', 'application_tips_prompt',
            'requirements_prompt'
        );
        
        foreach ($prompt_types as $prompt_type) {
            register_setting('giji_improved_prompt_settings', 'giji_improved_' . $prompt_type);
        }
    }
    
    /**
     * 設定保存処理（完全実装版）
     */
    public function handle_settings_save() {
        if (!isset($_POST['giji_improved_settings_nonce']) || 
            !wp_verify_nonce($_POST['giji_improved_settings_nonce'], 'giji_improved_settings_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // API設定の保存
        if (isset($_POST['giji_improved_save_api_settings'])) {
            $this->save_api_settings();
        }
        
        // 検索設定の保存
        if (isset($_POST['giji_improved_save_search_settings'])) {
            $this->save_search_settings();
        }
        
        // AI設定の保存
        if (isset($_POST['giji_improved_save_ai_settings'])) {
            $this->save_ai_settings();
        }
        
        // プロンプト設定の保存
        if (isset($_POST['giji_improved_save_prompt_settings'])) {
            $this->save_prompt_settings();
        }
    }
    
    /**
     * API設定の保存
     */
    private function save_api_settings() {
        // 依存関係チェック（最優先）
        if (!$this->security_manager || !$this->logger) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>エラー: セキュリティマネージャーまたはロガーが初期化されていません。</p></div>';
            });
            return;
        }
        
        // デバッグ: 送信されたPOSTデータをログに記録
        $this->logger->log('API設定保存開始', 'info');
        $this->logger->log('POSTデータ keys: ' . implode(', ', array_keys($_POST)), 'debug');
        
        // AIプロバイダー
        $current_provider = get_option('giji_improved_ai_provider', 'gemini');
        if (isset($_POST['giji_improved_ai_provider'])) {
            $provider = sanitize_text_field($_POST['giji_improved_ai_provider']);
            update_option('giji_improved_ai_provider', $provider);
            $this->logger->log('AIプロバイダー保存: ' . $provider, 'info');
            $current_provider = $provider;
        }
        
        // APIキーの保存（選択されたプロバイダーのみ処理）
        $api_keys_map = array(
            'gemini' => 'gemini_api_key',
            'openai' => 'openai_api_key', 
            'claude' => 'claude_api_key'
        );
        
        $saved_keys = 0;
        $deleted_keys = 0;
        $no_change_keys = 0;
        
        // 現在選択されているプロバイダーのAPIキーのみを処理
        if (isset($api_keys_map[$current_provider])) {
            $key = $api_keys_map[$current_provider];
            $post_key = 'giji_improved_' . $key;
            $this->logger->log('処理中のAPIキー: ' . $post_key . ' (プロバイダー: ' . $current_provider . ')', 'debug');
            
            if (isset($_POST[$post_key])) {
                $api_key = sanitize_text_field($_POST[$post_key]);
                $this->logger->log('APIキーフィールド存在: ' . $post_key . ' (長さ: ' . strlen($api_key) . ')', 'debug');
                
                // 空でない場合のみ保存処理
                if (!empty($api_key)) {
                    $result = $this->security_manager->save_api_key($key, $api_key);
                    
                    if ($result) {
                        $saved_keys++;
                        $this->logger->log('APIキー保存成功: ' . $key, 'info');
                    } else {
                        $this->logger->log('APIキー保存失敗: ' . $key, 'error');
                    }
                } else {
                    // 空の場合は変更なしとして扱う（削除しない）
                    $no_change_keys++;
                    $this->logger->log('APIキー空白 - 変更なしとして処理: ' . $key, 'debug');
                }
            } else {
                $this->logger->log('APIキーフィールド未存在: ' . $post_key, 'warning');
            }
        }
        
        // 明示的な削除要求があった場合の処理
        foreach ($api_keys_map as $provider => $key) {
            $delete_key = 'giji_improved_delete_' . $key;
            if (isset($_POST[$delete_key]) && $_POST[$delete_key] === '1') {
                $result = $this->security_manager->save_api_key($key, ''); // 空で削除
                if ($result) {
                    $deleted_keys++;
                    $this->logger->log('APIキー明示的削除成功: ' . $key, 'info');
                }
            }
        }
        
        $this->logger->log('API設定保存完了: ' . $saved_keys . '個のキーを保存、' . $deleted_keys . '個のキーを削除、' . $no_change_keys . '個のキーで変更なし', 'info');
        
        // モデル設定
        $models = array('gemini_model', 'openai_model', 'claude_model');
        foreach ($models as $model) {
            if (isset($_POST['giji_improved_' . $model])) {
                $model_value = sanitize_text_field($_POST['giji_improved_' . $model]);
                update_option('giji_improved_' . $model, $model_value);
            }
        }
        
        // 成功メッセージ
        if ($saved_keys > 0 || $deleted_keys > 0) {
            $message = 'API設定を更新しました。';
            if ($saved_keys > 0) $message .= ' 保存: ' . $saved_keys . '個';
            if ($deleted_keys > 0) $message .= ' 削除: ' . $deleted_keys . '個';
            
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-info"><p>APIキーに変更はありませんでした。既存のキーが保持されています。</p></div>';
            });
        }
    }
    
    /**
     * 検索設定の保存
     */
    private function save_search_settings() {
        $search_settings = array();
        
        // 基本検索設定
        $search_settings['keyword'] = isset($_POST['giji_improved_keyword']) ? 
            sanitize_text_field($_POST['giji_improved_keyword']) : '補助金';
            
        $search_settings['min_amount'] = isset($_POST['giji_improved_min_amount']) ? 
            intval($_POST['giji_improved_min_amount']) : 0;
            
        $search_settings['max_amount'] = isset($_POST['giji_improved_max_amount']) ? 
            intval($_POST['giji_improved_max_amount']) : 0;
        
        // 配列設定
        $search_settings['target_areas'] = isset($_POST['giji_improved_target_areas']) && 
            is_array($_POST['giji_improved_target_areas']) ? 
            array_map('sanitize_text_field', $_POST['giji_improved_target_areas']) : array();
            
        $search_settings['use_purposes'] = isset($_POST['giji_improved_use_purposes']) && 
            is_array($_POST['giji_improved_use_purposes']) ? 
            array_map('sanitize_text_field', $_POST['giji_improved_use_purposes']) : array();
        
        // チェックボックス設定
        $search_settings['acceptance_only'] = isset($_POST['giji_improved_acceptance_only']);
        $search_settings['exclude_zero_amount'] = isset($_POST['giji_improved_exclude_zero_amount']);
        
        update_option('giji_improved_search_settings', $search_settings);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>検索設定を保存しました。</p></div>';
        });
    }
    
    /**
     * AI設定の保存
     */
    private function save_ai_settings() {
        // AI生成機能設定
        $ai_enabled = array();
        $ai_functions = array(
            'content', 'excerpt', 'summary', 'organization', 'difficulty', 
            'success_rate', 'keywords', 'target_audience', 'application_tips', 'requirements'
        );
        
        foreach ($ai_functions as $function) {
            $ai_enabled[$function] = isset($_POST['giji_improved_ai_' . $function]);
        }
        
        update_option('giji_improved_ai_generation_enabled', $ai_enabled);
        
        // 高度なAI設定
        $advanced_settings = array();
        $advanced_fields = array(
            'temperature', 'max_tokens', 'top_p', 'frequency_penalty', 
            'presence_penalty', 'retry_count', 'timeout'
        );
        
        foreach ($advanced_fields as $field) {
            if (isset($_POST['giji_improved_ai_' . $field])) {
                $value = sanitize_text_field($_POST['giji_improved_ai_' . $field]);
                $advanced_settings[$field] = is_numeric($value) ? floatval($value) : $value;
            }
        }
        
        $advanced_settings['fallback_enabled'] = isset($_POST['giji_improved_ai_fallback_enabled']);
        
        update_option('giji_improved_ai_advanced_settings', $advanced_settings);
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>AI設定を保存しました。</p></div>';
        });
    }
    
    /**
     * プロンプト設定の保存
     */
    private function save_prompt_settings() {
        $prompt_types = array(
            'content_prompt', 'excerpt_prompt', 'summary_prompt',
            'organization_prompt', 'difficulty_prompt', 'success_rate_prompt',
            'keywords_prompt', 'target_audience_prompt', 'application_tips_prompt',
            'requirements_prompt'
        );
        
        foreach ($prompt_types as $prompt_type) {
            if (isset($_POST['giji_improved_' . $prompt_type])) {
                $prompt = wp_kses_post($_POST['giji_improved_' . $prompt_type]);
                update_option('giji_improved_' . $prompt_type, $prompt);
            }
        }
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>プロンプト設定を保存しました。</p></div>';
        });
    }
    
    /**
     * 管理画面用スクリプトの読み込み
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'grant-insight-jgrants-importer-improved') === false && 
            strpos($hook, 'giji-improved-') === false) {
            return;
        }
        
        if (!function_exists('wp_enqueue_script') || !function_exists('wp_create_nonce')) {
            return;
        }
        
        try {
            wp_enqueue_script(
                'giji-improved-admin-script',
                GIJI_IMPROVED_PLUGIN_URL . 'assets/admin.js',
                array('jquery'),
                GIJI_IMPROVED_PLUGIN_VERSION,
                true
            );
            
            wp_localize_script('giji-improved-admin-script', 'giji_improved_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('giji_improved_ajax_nonce')
            ));
            
            wp_enqueue_style(
                'giji-improved-admin-style',
                GIJI_IMPROVED_PLUGIN_URL . 'assets/admin.css',
                array(),
                GIJI_IMPROVED_PLUGIN_VERSION
            );
        } catch (Exception $e) {
            if (function_exists('error_log')) {
                error_log('Jグランツ・インポーター改善版スクリプト読み込みエラー: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * メインページの表示
     */
    public function display_main_page() {
        $stats = $this->automation_controller->get_statistics();
        $last_import = $this->automation_controller->get_last_import_result();
        $next_scheduled = $this->automation_controller->get_next_scheduled_time();
        
        ?>
        <div class="wrap">
            <h1>Grant Insight Jグランツ・インポーター 改善版</h1>
            
            <div class="giji-improved-dashboard">
                <div class="giji-improved-stats">
                    <div class="giji-improved-stat-box">
                        <h3>下書き投稿数</h3>
                        <div class="giji-improved-stat-number"><?php echo $stats['draft_count']; ?></div>
                    </div>
                    <div class="giji-improved-stat-box">
                        <h3>公開投稿数</h3>
                        <div class="giji-improved-stat-number"><?php echo $stats['published_count']; ?></div>
                    </div>
                    <div class="giji-improved-stat-box">
                        <h3>今月の追加</h3>
                        <div class="giji-improved-stat-number"><?php echo $stats['monthly_total']; ?></div>
                    </div>
                </div>
                
                <div class="giji-improved-actions">
                    <div class="giji-improved-action-section">
                        <h3>手動インポート</h3>
                        <p>JグランツAPIから最新の助成金情報を手動で取得します。</p>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="giji-improved-import-keyword">キーワード</label></th>
                                <td>
                                    <input type="text" id="giji-improved-import-keyword" 
                                           value="<?php echo esc_attr(get_option('giji_improved_search_settings')['keyword'] ?? '補助金'); ?>" 
                                           class="regular-text">
                                    <p class="description">Jグランツで検索するキーワード（必須、2文字以上）</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="giji-improved-import-count">取得件数</label></th>
                                <td>
                                    <input type="number" id="giji-improved-import-count" value="5" min="1" max="50" class="small-text">
                                    <p class="description">一度に取得する最大件数（1-50）</p>
                                </td>
                            </tr>
                        </table>
                        <button type="button" class="button button-primary" id="giji-improved-manual-import">手動インポート実行</button>
                        <div id="giji-improved-import-result" class="giji-improved-result"></div>
                    </div>
                    
                    <div class="giji-improved-action-section">
                        <h3>手動公開</h3>
                        <p>下書き状態の助成金投稿を公開します。</p>
                        <label for="giji-improved-publish-count">公開件数:</label>
                        <input type="number" id="giji-improved-publish-count" value="5" min="1" max="<?php echo $stats['draft_count']; ?>">
                        <button type="button" class="button button-primary" id="giji-improved-manual-publish">公開実行</button>
                        <div id="giji-improved-publish-result" class="giji-improved-result"></div>
                    </div>
                    
                    <div class="giji-improved-action-section">
                        <h3>下書き一括削除</h3>
                        <p>下書き状態の助成金投稿をすべて削除します。</p>
                        <button type="button" class="button button-secondary" id="giji-improved-bulk-delete" 
                                onclick="return confirm('本当に下書きをすべて削除しますか？この操作は取り消せません。')">一括削除実行</button>
                        <div id="giji-improved-delete-result" class="giji-improved-result"></div>
                    </div>
                </div>
                
                <div class="giji-improved-status">
                    <h3>自動インポート状況</h3>
                    <?php if ($next_scheduled): ?>
                        <p><strong>次回実行予定:</strong> <?php echo $next_scheduled; ?></p>
                    <?php else: ?>
                        <p><strong>自動インポート:</strong> 無効</p>
                    <?php endif; ?>
                    
                    <?php if ($last_import): ?>
                        <p><strong>最後の実行:</strong> <?php echo $last_import['timestamp']; ?></p>
                        <p><strong>処理件数:</strong> <?php echo $last_import['processed_count']; ?>件 / <?php echo $last_import['total_count']; ?>件</p>
                        <?php if (isset($last_import['error_count']) && $last_import['error_count'] > 0): ?>
                            <p><strong>エラー件数:</strong> <?php echo $last_import['error_count']; ?>件</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * 設定ページの表示
     */
    public function display_settings_page() {
        // 設定値の取得
        $ai_provider = get_option('giji_improved_ai_provider', 'gemini');
        $search_settings = get_option('giji_improved_search_settings', array());
        $ai_settings = get_option('giji_improved_ai_generation_enabled', array());
        $advanced_settings = get_option('giji_improved_ai_advanced_settings', array());
        
        ?>
        <div class="wrap">
            <h1>設定</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('giji_improved_settings_action', 'giji_improved_settings_nonce'); ?>
                
                <h2>API設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="giji_improved_ai_provider">AIプロバイダー</label></th>
                        <td>
                            <select id="giji_improved_ai_provider" name="giji_improved_ai_provider">
                                <option value="gemini" <?php selected($ai_provider, 'gemini'); ?>>Google Gemini</option>
                                <option value="openai" <?php selected($ai_provider, 'openai'); ?>>OpenAI ChatGPT</option>
                                <option value="claude" <?php selected($ai_provider, 'claude'); ?>>Anthropic Claude</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="api-key-row gemini-row" <?php echo $ai_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_improved_gemini_api_key">Gemini APIキー</label></th>
                        <td>
                            <input type="password" id="giji_improved_gemini_api_key" name="giji_improved_gemini_api_key" class="regular-text" placeholder="AIz...">
                        </td>
                    </tr>
                    <tr class="api-key-row openai-row" <?php echo $ai_provider !== 'openai' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_improved_openai_api_key">OpenAI APIキー</label></th>
                        <td>
                            <input type="password" id="giji_improved_openai_api_key" name="giji_improved_openai_api_key" class="regular-text" placeholder="sk-...">
                        </td>
                    </tr>
                    <tr class="api-key-row claude-row" <?php echo $ai_provider !== 'claude' ? 'style="display:none;"' : ''; ?>>
                        <th scope="row"><label for="giji_improved_claude_api_key">Claude APIキー</label></th>
                        <td>
                            <input type="password" id="giji_improved_claude_api_key" name="giji_improved_claude_api_key" class="regular-text" placeholder="sk-ant-...">
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="giji_improved_save_api_settings" class="button-primary" value="API設定を保存">
                    <button type="button" class="button" id="giji-improved-test-api-keys">APIキーをテスト</button>
                </p>
                <div id="giji-improved-api-test-result"></div>
                
                <h2>検索設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="giji_improved_keyword">デフォルトキーワード</label></th>
                        <td>
                            <input type="text" id="giji_improved_keyword" name="giji_improved_keyword" 
                                   value="<?php echo esc_attr($search_settings['keyword'] ?? '補助金'); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">金額範囲</th>
                        <td>
                            <input type="number" name="giji_improved_min_amount" 
                                   value="<?php echo esc_attr($search_settings['min_amount'] ?? 0); ?>" 
                                   placeholder="最小金額" class="small-text"> 円 ～ 
                            <input type="number" name="giji_improved_max_amount" 
                                   value="<?php echo esc_attr($search_settings['max_amount'] ?? 0); ?>" 
                                   placeholder="最大金額" class="small-text"> 円
                            <p class="description">0を入力すると制限なし</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">フィルター</th>
                        <td>
                            <label>
                                <input type="checkbox" name="giji_improved_acceptance_only" 
                                       <?php checked($search_settings['acceptance_only'] ?? true); ?>>
                                募集中のみ取得
                            </label><br>
                            <label>
                                <input type="checkbox" name="giji_improved_exclude_zero_amount" 
                                       <?php checked($search_settings['exclude_zero_amount'] ?? true); ?>>
                                補助額不明・0円を除外
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="giji_improved_save_search_settings" class="button-primary" value="検索設定を保存">
                </p>
                
                <h2>AI生成設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">AI生成機能</th>
                        <td>
                            <?php
                            $ai_functions = array(
                                'content' => '本文生成',
                                'excerpt' => '抜粋生成',
                                'summary' => '3行要約',
                                'organization' => '実施組織抽出',
                                'difficulty' => '申請難易度判定',
                                'success_rate' => '採択率推定',
                                'keywords' => 'キーワード生成',
                                'target_audience' => '対象者説明',
                                'application_tips' => '申請のコツ',
                                'requirements' => '要件整理'
                            );
                            
                            foreach ($ai_functions as $key => $label): ?>
                                <label>
                                    <input type="checkbox" name="giji_improved_ai_<?php echo $key; ?>" 
                                           <?php checked($ai_settings[$key] ?? false); ?>>
                                    <?php echo esc_html($label); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                
                <h3>高度なAI設定</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="giji_improved_ai_temperature">Temperature</label></th>
                        <td>
                            <input type="number" id="giji_improved_ai_temperature" name="giji_improved_ai_temperature" 
                                   value="<?php echo esc_attr($advanced_settings['temperature'] ?? 0.7); ?>" 
                                   min="0" max="2" step="0.1" class="small-text">
                            <p class="description">0.0（決定的）～ 2.0（創造的）</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="giji_improved_ai_max_tokens">最大トークン数</label></th>
                        <td>
                            <input type="number" id="giji_improved_ai_max_tokens" name="giji_improved_ai_max_tokens" 
                                   value="<?php echo esc_attr($advanced_settings['max_tokens'] ?? 2048); ?>" 
                                   min="1" max="8192" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="giji_improved_ai_retry_count">リトライ回数</label></th>
                        <td>
                            <input type="number" id="giji_improved_ai_retry_count" name="giji_improved_ai_retry_count" 
                                   value="<?php echo esc_attr($advanced_settings['retry_count'] ?? 3); ?>" 
                                   min="1" max="10" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">フォールバック</th>
                        <td>
                            <label>
                                <input type="checkbox" name="giji_improved_ai_fallback_enabled" 
                                       <?php checked($advanced_settings['fallback_enabled'] ?? true); ?>>
                                AI生成失敗時にフォールバックコンテンツを使用
                            </label>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="giji_improved_save_ai_settings" class="button-primary" value="AI設定を保存">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * プロンプト管理ページの表示
     */
    public function display_prompts_page() {
        // プロンプトテンプレートの読み込み
        $prompts = array(
            'content_prompt' => get_option('giji_improved_content_prompt', ''),
            'excerpt_prompt' => get_option('giji_improved_excerpt_prompt', ''),
            'summary_prompt' => get_option('giji_improved_summary_prompt', ''),
        );
        
        ?>
        <div class="wrap">
            <h1>プロンプト管理</h1>
            
            <div class="giji-improved-prompt-info">
                <h3>利用可能な変数</h3>
                <p>プロンプト内で以下の変数を使用できます：</p>
                <ul>
                    <li><code>[title]</code> または <code>[補助金名]</code> - 助成金名</li>
                    <li><code>[overview]</code> または <code>[概要]</code> - 助成金の概要</li>
                    <li><code>[max_amount]</code> または <code>[補助額上限]</code> - 補助額上限</li>
                    <li><code>[deadline_text]</code> または <code>[募集終了日]</code> - 募集終了日</li>
                    <li><code>[organization]</code> または <code>[実施組織]</code> - 実施組織</li>
                    <li><code>[official_url]</code> または <code>[公式URL]</code> - 公式サイトURL</li>
                    <li><code>[subsidy_rate]</code> または <code>[補助率]</code> - 補助率</li>
                    <li><code>[use_purpose]</code> または <code>[利用目的]</code> - 利用目的</li>
                    <li><code>[target_area]</code> または <code>[対象地域]</code> - 対象地域</li>
                </ul>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('giji_improved_settings_action', 'giji_improved_settings_nonce'); ?>
                
                <h2>基本プロンプト</h2>
                
                <h3>本文生成プロンプト</h3>
                <textarea name="giji_improved_content_prompt" rows="10" class="large-text"><?php echo esc_textarea($prompts['content_prompt']); ?></textarea>
                
                <h3>抜粋生成プロンプト</h3>
                <textarea name="giji_improved_excerpt_prompt" rows="5" class="large-text"><?php echo esc_textarea($prompts['excerpt_prompt']); ?></textarea>
                
                <h3>要約生成プロンプト</h3>
                <textarea name="giji_improved_summary_prompt" rows="5" class="large-text"><?php echo esc_textarea($prompts['summary_prompt']); ?></textarea>
                
                <p class="submit">
                    <input type="submit" name="giji_improved_save_prompt_settings" class="button-primary" value="プロンプト設定を保存">
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * ログページの表示
     */
    public function display_logs_page() {
        $logs = $this->logger->get_logs(100);
        $stats = $this->logger->get_log_stats();
        
        ?>
        <div class="wrap">
            <h1>ログ</h1>
            
            <div class="giji-improved-log-stats">
                <h3>ログ統計（過去7日間）</h3>
                <?php foreach ($stats as $stat): ?>
                    <span class="giji-improved-log-stat-item">
                        <?php echo esc_html($stat->level); ?>: <?php echo esc_html($stat->count); ?>件
                    </span>
                <?php endforeach; ?>
            </div>
            
            <div class="giji-improved-log-actions">
                <button type="button" class="button" id="giji-improved-clear-logs">ログをクリア</button>
                <button type="button" class="button" id="giji-improved-export-logs">CSVエクスポート</button>
            </div>
            
            <div class="giji-improved-log-viewer">
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
                        <?php foreach ($logs as $log): ?>
                            <tr class="log-level-<?php echo esc_attr($log->level); ?>">
                                <td><?php echo esc_html($log->timestamp); ?></td>
                                <td><span class="log-level-badge log-level-<?php echo esc_attr($log->level); ?>"><?php echo esc_html(strtoupper($log->level)); ?></span></td>
                                <td><?php echo esc_html($log->message); ?></td>
                                <td><?php echo $log->user_id ? get_user_by('id', $log->user_id)->display_name : 'システム'; ?></td>
                            </tr>
                        <?php endforeach; ?>
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
        $stats = $this->automation_controller->get_statistics();
        $history = $this->automation_controller->get_import_history();
        
        ?>
        <div class="wrap">
            <h1>統計</h1>
            
            <div class="giji-improved-statistics">
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
                        <tr>
                            <td>今月の新規追加</td>
                            <td><?php echo esc_html($stats['monthly_total']); ?>件</td>
                        </tr>
                    </tbody>
                </table>
                
                <h2>インポート履歴</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>実行日時</th>
                            <th>処理件数</th>
                            <th>取得件数</th>
                            <th>エラー件数</th>
                            <th>状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $record): ?>
                            <tr>
                                <td><?php echo esc_html($record['timestamp']); ?></td>
                                <td><?php echo esc_html($record['processed_count']); ?>件</td>
                                <td><?php echo esc_html($record['total_count']); ?>件</td>
                                <td><?php echo esc_html($record['error_count'] ?? 0); ?>件</td>
                                <td>
                                    <span class="status-<?php echo esc_attr($record['status']); ?>">
                                        <?php echo esc_html($record['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: 手動インポート処理
     */
    public function handle_manual_import() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        $keyword = $this->security_manager->sanitize_input($_POST['keyword'] ?? '補助金');
        $count = intval($_POST['count'] ?? 5);
        
        $search_params = array(
            'keyword' => $keyword,
            'per_page' => $count
        );
        
        $result = $this->automation_controller->execute_manual_import($search_params, $count);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: 手動公開処理
     */
    public function handle_manual_publish() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        $count = intval($_POST['count'] ?? 5);
        $results = $this->automation_controller->execute_manual_publish($count);
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: 一括削除処理
     */
    public function handle_bulk_delete_drafts() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        $results = $this->automation_controller->execute_bulk_delete_drafts();
        
        wp_send_json_success($results);
    }
    
    /**
     * AJAX: APIキーテスト
     */
    public function handle_test_api_keys() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        try {
            // JグランツAPIテスト
            $jgrants_client = new GIJI_JGrants_API_Client($this->logger);
            $jgrants_test = $jgrants_client->test_connection();
            
            // AI APIテスト
            $ai_client = new GIJI_Unified_AI_Client($this->logger, $this->security_manager);
            $ai_test = $ai_client->test_connection();
            
            $provider = get_option('giji_improved_ai_provider', 'gemini');
            
            wp_send_json_success(array(
                'jgrants' => $jgrants_test,
                'ai_api' => $ai_test,
                'provider' => $provider
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'テストに失敗しました: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX: ログクリア
     */
    public function handle_clear_logs() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        $result = $this->logger->clear_logs();
        
        if ($result) {
            wp_send_json_success(array('message' => 'ログをクリアしました。'));
        } else {
            wp_send_json_error(array('message' => 'ログのクリアに失敗しました。'));
        }
    }
    
    /**
     * AJAX: ログエクスポート
     */
    public function handle_export_logs() {
        $this->security_manager->verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce');
        $this->security_manager->check_admin_permission();
        
        $csv_content = $this->logger->export_logs('csv');
        
        if ($csv_content) {
            $filename = 'giji_improved_logs_' . date('Y-m-d_H-i-s') . '.csv';
            
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
}