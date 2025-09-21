<?php
/**
 * Grant Insight Jグランツ・インポーター 手動公開機能修正パッチ
 * 
 * 使用方法:
 * 1. このファイルをプラグインのrootディレクトリにアップロード
 * 2. WordPressの管理画面で一度アクセスして動作確認
 * 3. 問題が解決したらこのファイルを削除
 */

if (!defined('ABSPATH')) {
    exit('直接アクセスは禁止されています。');
}

class GIJI_Manual_Publish_Fix {
    
    public function __construct() {
        add_action('wp_ajax_giji_improved_manual_publish_fixed', array($this, 'handle_manual_publish_fixed'));
        add_action('admin_footer', array($this, 'add_debug_script'));
    }
    
    /**
     * 修正版の手動公開処理
     */
    public function handle_manual_publish_fixed() {
        // 詳細なデバッグ情報
        $debug_info = array(
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'post_data' => $_POST,
            'user_can_manage' => current_user_can('manage_options')
        );
        
        try {
            // 1. セキュリティチェック（wp_die使用せず）
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'giji_improved_ajax_nonce')) {
                wp_send_json_error(array(
                    'message' => 'セキュリティ検証に失敗しました。ページを再読込してください。',
                    'debug' => $debug_info
                ));
                return;
            }
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array(
                    'message' => 'この操作を実行する権限がありません。',
                    'debug' => $debug_info
                ));
                return;
            }
            
            // 2. 入力値検証
            $count = intval($_POST['count'] ?? 5);
            if ($count < 1 || $count > 100) {
                wp_send_json_error(array(
                    'message' => '公開件数は1～100の間で指定してください。現在値: ' . $count,
                    'debug' => $debug_info
                ));
                return;
            }
            
            // 3. プラグインコンポーネントの確認
            $main_plugin = Grant_Insight_JGrants_Importer_Improved::get_instance();
            $automation_controller = $main_plugin->get_automation_controller();
            
            if (!$automation_controller) {
                wp_send_json_error(array(
                    'message' => 'システムコンポーネントが初期化されていません。プラグインを無効化・有効化してください。',
                    'debug' => array_merge($debug_info, array(
                        'main_plugin_exists' => !empty($main_plugin),
                        'automation_controller_exists' => !empty($automation_controller)
                    ))
                ));
                return;
            }
            
            // 4. 下書き投稿の確認
            $draft_posts = get_posts(array(
                'post_type' => 'grant',
                'post_status' => 'draft',
                'posts_per_page' => 1,
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
            
            // 5. 実際の公開処理実行
            $results = $automation_controller->execute_manual_publish($count);
            
            if (is_wp_error($results)) {
                wp_send_json_error(array(
                    'message' => '公開処理でエラーが発生しました: ' . $results->get_error_message(),
                    'debug' => array_merge($debug_info, array(
                        'wp_error_code' => $results->get_error_code(),
                        'wp_error_data' => $results->get_error_data()
                    ))
                ));
                return;
            }
            
            // 6. 成功レスポンス
            wp_send_json_success(array(
                'message' => sprintf('公開処理が正常に完了しました。成功: %d件, エラー: %d件', 
                    $results['success'], 
                    $results['error']
                ),
                'results' => $results,
                'debug' => $debug_info
            ));
            
        } catch (Exception $e) {
            // 7. 例外処理
            wp_send_json_error(array(
                'message' => '予期しないエラーが発生しました: ' . $e->getMessage(),
                'debug' => array_merge($debug_info, array(
                    'exception_line' => $e->getLine(),
                    'exception_file' => basename($e->getFile()),
                    'exception_trace' => WP_DEBUG ? $e->getTraceAsString() : '(デバッグモードで詳細表示)'
                ))
            ));
        }
    }
    
    /**
     * デバッグ用JavaScript追加
     */
    public function add_debug_script() {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'grant-insight-jgrants-importer-improved') === false) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // 修正版ボタンを追加
            var fixButton = $('<button type="button" class="button button-secondary" id="giji-improved-manual-publish-fixed" style="margin-left: 10px;">修正版で公開実行（デバッグ）</button>');
            $('#giji-improved-manual-publish').after(fixButton);
            
            // 修正版ハンドラー
            fixButton.on('click', function() {
                var button = $(this);
                var resultDiv = $('#giji-improved-publish-result');
                var count = parseInt($('#giji-improved-publish-count').val());
                
                if (!count || count < 1) {
                    resultDiv.html('<div class="notice notice-error"><p>公開件数を正しく入力してください。</p></div>');
                    return;
                }
                
                if (!confirm('修正版で' + count + '件の下書きを公開しますか？（デバッグ情報付き）')) {
                    return;
                }
                
                button.prop('disabled', true).text('修正版で処理中...');
                resultDiv.html('<div class="notice notice-info"><p>修正版で公開処理を実行しています...</p></div>');
                
                $.ajax({
                    url: giji_improved_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'giji_improved_manual_publish_fixed',
                        nonce: giji_improved_ajax.nonce,
                        count: count
                    },
                    timeout: 120000, // 2分
                    success: function(response) {
                        console.log('修正版レスポンス:', response);
                        
                        var html = '';
                        if (response.success) {
                            html += '<div class="notice notice-success"><p><strong>修正版: 公開処理が完了しました！</strong></p></div>';
                            if (response.data && response.data.results) {
                                var results = response.data.results;
                                html += '<p>成功: <span style="color: green; font-weight: bold;">' + results.success + '件</span>, ';
                                html += 'エラー: <span style="color: red; font-weight: bold;">' + results.error + '件</span></p>';
                                
                                if (results.details && results.details.length > 0) {
                                    html += '<details><summary>詳細結果</summary><pre>' + JSON.stringify(results.details, null, 2) + '</pre></details>';
                                }
                            }
                        } else {
                            html += '<div class="notice notice-error"><p><strong>修正版: エラーが発生しました</strong></p>';
                            html += '<p>' + (response.data && response.data.message ? response.data.message : '不明なエラー') + '</p></div>';
                        }
                        
                        // デバッグ情報表示
                        if (response.data && response.data.debug) {
                            html += '<details style="margin-top: 10px;"><summary><strong>デバッグ情報</strong></summary>';
                            html += '<pre style="background: #f0f0f0; padding: 10px; font-size: 12px;">';
                            html += JSON.stringify(response.data.debug, null, 2);
                            html += '</pre></details>';
                        }
                        
                        resultDiv.html(html);
                    },
                    error: function(xhr, status, error) {
                        console.error('修正版AJAX エラー:', xhr, status, error);
                        
                        var html = '<div class="notice notice-error"><p><strong>修正版: 通信エラーが発生しました</strong></p>';
                        html += '<p>ステータス: ' + status + '</p>';
                        html += '<p>エラー: ' + error + '</p>';
                        
                        if (xhr.responseJSON) {
                            html += '<details><summary>サーバーレスポンス</summary><pre>' + JSON.stringify(xhr.responseJSON, null, 2) + '</pre></details>';
                        } else if (xhr.responseText) {
                            html += '<details><summary>レスポンステキスト</summary><pre>' + xhr.responseText.substring(0, 1000) + '</pre></details>';
                        }
                        html += '</div>';
                        
                        resultDiv.html(html);
                    },
                    complete: function() {
                        button.prop('disabled', false).text('修正版で公開実行（デバッグ）');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// 修正版を有効化
new GIJI_Manual_Publish_Fix();