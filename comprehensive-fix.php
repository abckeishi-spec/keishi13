<?php
/**
 * Grant Insight Jグランツ・インポーター 包括的修正パッチ
 * 
 * 修正対象:
 * 1. 重複初期化問題
 * 2. 設定保存の不具合
 * 3. APIテストの偽陽性
 * 4. 自動実行の暴走
 * 5. 通信エラー改善
 */

if (!defined('ABSPATH')) {
    exit('直接アクセスは禁止されています。');
}

class GIJI_Comprehensive_Fix {
    
    private static $initialization_count = 0;
    private static $instances = array();
    
    public function __construct() {
        // 重複初期化防止
        if (isset(self::$instances[get_class($this)])) {
            error_log('GIJI FIX: 重複初期化を検出・防止: ' . get_class($this));
            return self::$instances[get_class($this)];
        }
        
        self::$instances[get_class($this)] = $this;
        self::$initialization_count++;
        
        add_action('init', array($this, 'setup_fixes'), 1);
        add_action('admin_init', array($this, 'setup_admin_fixes'), 1);
        
        // デバッグ用
        add_action('wp_footer', array($this, 'add_debug_info'));
        add_action('admin_footer', array($this, 'add_debug_info'));
    }
    
    public function setup_fixes() {
        // 1. 重複初期化問題の修正
        add_filter('giji_improved_prevent_duplicate_init', array($this, 'prevent_duplicate_initialization'));
        
        // 2. Cron暴走の防止
        add_action('giji_improved_auto_import_hook', array($this, 'controlled_auto_import'));
        remove_action('giji_improved_auto_import_hook', array('GIJI_Automation_Controller', 'execute_auto_import'));
        
        // 3. 修正版AJAX処理の登録
        add_action('wp_ajax_giji_fixed_manual_publish', array($this, 'handle_fixed_manual_publish'));
        add_action('wp_ajax_giji_fixed_api_test', array($this, 'handle_fixed_api_test'));
        add_action('wp_ajax_giji_fixed_save_settings', array($this, 'handle_fixed_save_settings'));
    }
    
    public function setup_admin_fixes() {
        // 管理画面での修正処理
        add_action('admin_enqueue_scripts', array($this, 'enqueue_fix_scripts'));
    }
    
    /**
     * 重複初期化防止
     */
    public function prevent_duplicate_initialization($allowed) {
        if (self::$initialization_count > 3) {
            error_log('GIJI FIX: 初期化回数制限に達しました。初期化を停止します。');
            return false;
        }
        return true;
    }
    
    /**
     * 制御された自動インポート
     */
    public function controlled_auto_import() {
        // 実行中フラグをチェック
        $running_flag = get_transient('giji_auto_import_running');
        if ($running_flag) {
            error_log('GIJI FIX: 自動インポートが既に実行中のためスキップ');
            return;
        }
        
        // 実行フラグを設定（5分間）
        set_transient('giji_auto_import_running', true, 300);
        
        try {
            $main_plugin = Grant_Insight_JGrants_Importer_Improved::get_instance();
            $automation_controller = $main_plugin->get_automation_controller();
            
            if ($automation_controller) {
                error_log('GIJI FIX: 制御された自動インポート開始');
                $automation_controller->execute_auto_import();
            }
        } catch (Exception $e) {
            error_log('GIJI FIX: 自動インポートでエラー: ' . $e->getMessage());
        } finally {
            // 実行フラグをクリア
            delete_transient('giji_auto_import_running');
        }
    }
    
    /**
     * 修正版 手動公開処理
     */
    public function handle_fixed_manual_publish() {
        $debug_info = array(
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'initialization_count' => self::$initialization_count,
            'instances_count' => count(self::$instances)
        );
        
        try {
            // セキュリティチェック
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce')) {
                wp_send_json_error(array(
                    'message' => 'セキュリティ検証に失敗しました。',
                    'debug' => $debug_info
                ));
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => '権限がありません。',
                    'debug' => $debug_info
                ));
                return;
            }
            
            $count = intval($_POST['count'] ?? 5);
            
            // プラグインコンポーネントの安全な取得
            $main_plugin = Grant_Insight_JGrants_Importer_Improved::get_instance();
            $automation_controller = $main_plugin ? $main_plugin->get_automation_controller() : null;
            
            if (!$automation_controller) {
                wp_send_json_error(array(
                    'message' => 'システムコンポーネントが利用できません。プラグインを再有効化してください。',
                    'debug' => $debug_info
                ));
                return;
            }
            
            // 下書き投稿を直接確認
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
                    ),
                    'debug' => $debug_info
                ));
                return;
            }
            
            // 手動で公開処理実行
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
                } else {
                    $results['success']++;
                    $results['details'][] = array(
                        'id' => $post_id,
                        'title' => $post->post_title,
                        'status' => 'success'
                    );
                }
            }
            
            wp_send_json_success(array(
                'message' => sprintf('公開処理完了: 成功 %d件, エラー %d件', $results['success'], $results['error']),
                'results' => $results,
                'debug' => $debug_info
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => '予期しないエラー: ' . $e->getMessage(),
                'debug' => array_merge($debug_info, array(
                    'exception_line' => $e->getLine(),
                    'exception_file' => basename($e->getFile())
                ))
            ));
        }
    }
    
    /**
     * 修正版 APIテスト処理
     */
    public function handle_fixed_api_test() {
        if (!wp_verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'セキュリティエラー'));
            return;
        }
        
        try {
            $results = array();
            
            // JグランツAPIテスト
            $jgrants_client = new GIJI_JGrants_API_Client(new GIJI_Logger());
            $jgrants_test = $jgrants_client->test_connection();
            $results['jgrants'] = $jgrants_test;
            
            // AI APIテスト（詳細チェック）
            $provider = get_option('giji_improved_ai_provider', 'gemini');
            $security_manager = new GIJI_Security_Manager();
            
            // APIキーの存在確認
            $api_key_map = array(
                'openai' => 'openai_api_key',
                'claude' => 'claude_api_key', 
                'gemini' => 'gemini_api_key'
            );
            
            $api_key = null;
            if (isset($api_key_map[$provider])) {
                $api_key = $security_manager->get_api_key($api_key_map[$provider]);
            }
            
            $results['provider'] = $provider;
            $results['api_key_exists'] = !empty($api_key);
            $results['api_key_length'] = $api_key ? strlen($api_key) : 0;
            
            if (empty($api_key)) {
                $results['ai_api'] = false;
                $results['ai_message'] = 'APIキーが設定されていません';
            } else {
                // 実際にAI APIテストを実行
                $ai_client = new GIJI_Unified_AI_Client(new GIJI_Logger(), $security_manager);
                $ai_test_result = $ai_client->test_connection();
                $results['ai_api'] = $ai_test_result;
                $results['ai_message'] = $ai_test_result ? 'API接続成功' : 'API接続失敗';
            }
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'テスト実行エラー: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * 修正版 設定保存処理
     */
    public function handle_fixed_save_settings() {
        if (!wp_verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'セキュリティエラー'));
            return;
        }
        
        try {
            $saved_count = 0;
            $security_manager = new GIJI_Security_Manager();
            
            // AIプロバイダーの保存
            if (isset($_POST['ai_provider'])) {
                $provider = sanitize_text_field($_POST['ai_provider']);
                update_option('giji_improved_ai_provider', $provider);
                $saved_count++;
            }
            
            // APIキーの保存（各プロバイダー）
            $api_keys = array(
                'gemini_api_key' => $_POST['gemini_api_key'] ?? '',
                'openai_api_key' => $_POST['openai_api_key'] ?? '',
                'claude_api_key' => $_POST['claude_api_key'] ?? ''
            );
            
            foreach ($api_keys as $key => $value) {
                $value = sanitize_text_field($value);
                if (!empty($value)) {
                    $result = $security_manager->save_api_key($key, $value);
                    if ($result) {
                        $saved_count++;
                    }
                }
            }
            
            wp_send_json_success(array(
                'message' => "{$saved_count}個の設定を保存しました",
                'saved_count' => $saved_count
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => '設定保存エラー: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * 修正版JavaScript
     */
    public function enqueue_fix_scripts($hook) {
        if (strpos($hook, 'grant-insight-jgrants-importer-improved') === false) {
            return;
        }
        
        wp_add_inline_script('jquery', $this->get_fix_javascript());
    }
    
    private function get_fix_javascript() {
        return "
        jQuery(document).ready(function($) {
            console.log('GIJI修正版スクリプト読み込み完了');
            
            // 修正版ボタンの追加
            if ($('#giji-improved-manual-publish').length) {
                var fixPublishBtn = $('<button type=\"button\" class=\"button button-secondary\" id=\"giji-fixed-manual-publish\" style=\"margin-left: 10px;\">修正版で公開</button>');
                $('#giji-improved-manual-publish').after(fixPublishBtn);
                
                fixPublishBtn.on('click', function() {
                    var count = parseInt($('#giji-improved-publish-count').val());
                    if (!count || count < 1) {
                        alert('公開件数を正しく入力してください');
                        return;
                    }
                    
                    if (!confirm('修正版で' + count + '件を公開しますか？')) {
                        return;
                    }
                    
                    var button = $(this);
                    button.prop('disabled', true).text('修正版で処理中...');
                    
                    $.ajax({
                        url: giji_improved_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'giji_fixed_manual_publish',
                            nonce: giji_improved_ajax.nonce,
                            count: count
                        },
                        success: function(response) {
                            console.log('修正版レスポンス:', response);
                            var html = '';
                            if (response.success) {
                                html = '<div class=\"notice notice-success\"><p>' + response.data.message + '</p></div>';
                                if (response.data.results) {
                                    html += '<p>成功: ' + response.data.results.success + '件, エラー: ' + response.data.results.error + '件</p>';
                                }
                            } else {
                                html = '<div class=\"notice notice-error\"><p>' + (response.data ? response.data.message : 'エラーが発生しました') + '</p></div>';
                            }
                            $('#giji-improved-publish-result').html(html);
                        },
                        error: function(xhr, status, error) {
                            console.error('修正版エラー:', xhr, status, error);
                            var errorMsg = '通信エラーが発生しました';
                            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                                errorMsg = xhr.responseJSON.data.message;
                            }
                            $('#giji-improved-publish-result').html('<div class=\"notice notice-error\"><p>' + errorMsg + '</p></div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('修正版で公開');
                        }
                    });
                });
            }
            
            // 修正版APIテストボタン
            if ($('#giji-improved-test-api-keys').length) {
                var fixTestBtn = $('<button type=\"button\" class=\"button\" id=\"giji-fixed-test-api\" style=\"margin-left: 10px;\">修正版でテスト</button>');
                $('#giji-improved-test-api-keys').after(fixTestBtn);
                
                fixTestBtn.on('click', function() {
                    var button = $(this);
                    button.prop('disabled', true).text('修正版でテスト中...');
                    
                    $.ajax({
                        url: giji_improved_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'giji_fixed_api_test',
                            nonce: giji_improved_ajax.nonce
                        },
                        success: function(response) {
                            console.log('修正版APIテスト結果:', response);
                            var html = '<div class=\"notice notice-info\"><h4>修正版APIテスト結果:</h4>';
                            if (response.success) {
                                html += '<ul>';
                                html += '<li>JグランツAPI: ' + (response.data.jgrants ? '✓ 成功' : '✗ 失敗') + '</li>';
                                html += '<li>選択プロバイダー: ' + response.data.provider + '</li>';
                                html += '<li>APIキー存在: ' + (response.data.api_key_exists ? '✓ あり (' + response.data.api_key_length + '文字)' : '✗ なし') + '</li>';
                                html += '<li>AI API: ' + (response.data.ai_api ? '✓ 成功' : '✗ 失敗') + ' - ' + response.data.ai_message + '</li>';
                                html += '</ul>';
                            } else {
                                html += '<p>エラー: ' + response.data.message + '</p>';
                            }
                            html += '</div>';
                            $('#giji-improved-api-test-result').html(html);
                        },
                        error: function() {
                            $('#giji-improved-api-test-result').html('<div class=\"notice notice-error\"><p>修正版: 通信エラーが発生しました</p></div>');
                        },
                        complete: function() {
                            button.prop('disabled', false).text('修正版でテスト');
                        }
                    });
                });
            }
        });
        ";
    }
    
    /**
     * デバッグ情報の表示
     */
    public function add_debug_info() {
        if (!current_user_can('manage_options') || !WP_DEBUG) {
            return;
        }
        
        echo '<div id="giji-debug-info" style="position: fixed; bottom: 10px; right: 10px; background: rgba(0,0,0,0.8); color: white; padding: 10px; font-size: 12px; z-index: 9999;">';
        echo '<strong>GIJI修正版デバッグ:</strong><br>';
        echo '初期化回数: ' . self::$initialization_count . '<br>';
        echo 'インスタンス数: ' . count(self::$instances) . '<br>';
        echo '自動インポート実行中: ' . (get_transient('giji_auto_import_running') ? 'はい' : 'いいえ') . '<br>';
        
        // メモリ使用量
        echo 'メモリ使用量: ' . round(memory_get_usage() / 1024 / 1024, 2) . 'MB<br>';
        
        // 現在のプロバイダー
        $provider = get_option('giji_improved_ai_provider', 'none');
        echo 'AIプロバイダー: ' . $provider . '<br>';
        
        echo '</div>';
    }
}

// 修正版を有効化
new GIJI_Comprehensive_Fix();