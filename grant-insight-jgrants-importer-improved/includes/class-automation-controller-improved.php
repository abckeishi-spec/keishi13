<?php
/**
 * 自動化・公開制御クラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Automation_Controller {
    
    private $data_processor;
    private $logger;
    
    const CRON_HOOK = 'giji_improved_auto_import_hook';
    
    public function __construct($data_processor, $logger) {
        $this->data_processor = $data_processor;
        $this->logger = $logger;
        
        // Cronフックの登録
        add_action(self::CRON_HOOK, array($this, 'execute_auto_import'));
    }
    
    /**
     * 自動インポートの実行（改善版）
     */
    public function execute_auto_import() {
        $this->logger->log('自動インポート開始');
        
        if (!$this->is_auto_import_enabled()) {
            $this->logger->log('自動インポートが無効のため処理を停止');
            return;
        }
        
        // メモリ制限の設定
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5分
        
        try {
            // JグランツAPIクライアントの取得
            $jgrants_client = new GIJI_JGrants_API_Client($this->logger);
            
            // APIの接続テスト
            if (!$jgrants_client->test_connection()) {
                $this->logger->log('JグランツAPIに接続できませんでした', 'error');
                return;
            }
            
            // 検索パラメータの取得
            $search_params = $this->get_search_parameters();
            $max_process_count = $this->get_max_process_count();
            
            $this->logger->log("自動インポート設定: 最大処理件数={$max_process_count}, キーワード=" . $search_params['keyword']);
            
            // 助成金一覧の取得
            $response = $jgrants_client->get_subsidies($search_params);
            
            if (is_wp_error($response)) {
                $this->logger->log('助成金一覧取得エラー: ' . $response->get_error_message(), 'error');
                return;
            }
            
            if (!isset($response['result']) || empty($response['result'])) {
                $this->logger->log('取得できた助成金データがありません');
                return;
            }
            
            // バッチ処理での詳細データ取得
            $subsidies = array_slice($response['result'], 0, $max_process_count);
            $results = $this->process_subsidies_batch($subsidies, $jgrants_client);
            
            // 処理結果の保存
            $this->save_import_result($results['processed'], count($subsidies), $results['errors']);
            
            $this->logger->log("自動インポート完了: 成功={$results['processed']}件, エラー={$results['errors']}件");
            
        } catch (Exception $e) {
            $this->logger->log('自動インポート中に例外発生: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * バッチ処理で助成金を処理
     */
    private function process_subsidies_batch($subsidies, $jgrants_client) {
        $processed_count = 0;
        $error_count = 0;
        $batch_size = 5; // バッチサイズ
        
        $batches = array_chunk($subsidies, $batch_size);
        
        foreach ($batches as $batch_index => $batch) {
            $this->logger->log("バッチ処理 " . ($batch_index + 1) . "/" . count($batches) . " 開始");
            
            foreach ($batch as $subsidy) {
                try {
                    // 詳細データの取得
                    $detail_response = $jgrants_client->get_subsidy_detail($subsidy['id']);
                    
                    if (is_wp_error($detail_response)) {
                        $this->logger->log('詳細データ取得エラー (ID: ' . $subsidy['id'] . '): ' . $detail_response->get_error_message(), 'warning');
                        $error_count++;
                        continue;
                    }
                    
                    if (isset($detail_response['result'][0])) {
                        $detail_data = $detail_response['result'][0];
                        
                        // データの処理と保存
                        $result = $this->data_processor->process_and_save_grant($detail_data);
                        
                        if (!is_wp_error($result)) {
                            $processed_count++;
                            $this->logger->log('助成金データ処理成功: ' . $detail_data['title'] . ' (投稿ID: ' . $result . ')');
                        } else {
                            $error_count++;
                            $this->logger->log('助成金データ処理エラー: ' . $result->get_error_message(), 'warning');
                        }
                    }
                    
                } catch (Exception $e) {
                    $error_count++;
                    $this->logger->log('処理中に例外発生 (ID: ' . $subsidy['id'] . '): ' . $e->getMessage(), 'error');
                }
                
                // API制限を考慮した待機
                sleep(2);
            }
            
            // バッチ間の待機
            if ($batch_index < count($batches) - 1) {
                $this->logger->log("バッチ処理完了、次のバッチまで5秒待機");
                sleep(5);
            }
            
            // メモリクリーンアップ
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        return array(
            'processed' => $processed_count,
            'errors' => $error_count
        );
    }
    
    /**
     * 手動インポートの実行（改善版）
     */
    public function execute_manual_import($search_params = array(), $max_count = 5) {
        $this->logger->log('手動インポート開始: 最大' . $max_count . '件');
        
        // メモリ制限の設定
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        
        try {
            // JグランツAPIクライアントの取得
            $jgrants_client = new GIJI_JGrants_API_Client($this->logger);
            
            // APIの接続テスト
            if (!$jgrants_client->test_connection()) {
                return array(
                    'success' => false,
                    'message' => 'JグランツAPIに接続できませんでした。'
                );
            }
            
            // デフォルトパラメータとマージ
            $default_search_params = $this->get_search_parameters();
            $search_params = wp_parse_args($search_params, $default_search_params);
            $search_params['per_page'] = intval($max_count);
            
            $this->logger->log('検索パラメータ: ' . wp_json_encode($search_params));
            
            // 助成金一覧の取得
            $response = $jgrants_client->get_subsidies($search_params);
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => '助成金一覧取得エラー: ' . $response->get_error_message()
                );
            }
            
            if (!isset($response['result']) || empty($response['result'])) {
                return array(
                    'success' => true,
                    'message' => '指定条件に該当する助成金が見つかりませんでした。',
                    'results' => array(
                        'success' => 0,
                        'error' => 0,
                        'duplicate' => 0,
                        'details' => array()
                    )
                );
            }
            
            // 詳細データの処理
            $subsidies = array_slice($response['result'], 0, $max_count);
            $results = $this->process_manual_import_batch($subsidies, $jgrants_client);
            
            // 成功メッセージの作成
            $message = sprintf(
                '手動インポート完了: 成功=%d件, エラー=%d件, 重複=%d件',
                $results['success'],
                $results['error'],
                $results['duplicate']
            );
            
            return array(
                'success' => true,
                'message' => $message,
                'results' => $results
            );
            
        } catch (Exception $e) {
            $this->logger->log('手動インポート中に例外発生: ' . $e->getMessage(), 'error');
            return array(
                'success' => false,
                'message' => 'エラーが発生しました: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * 手動インポートのバッチ処理
     */
    private function process_manual_import_batch($subsidies, $jgrants_client) {
        $results = array(
            'success' => 0,
            'error' => 0,
            'duplicate' => 0,
            'details' => array()
        );
        
        foreach ($subsidies as $subsidy) {
            try {
                // 詳細データの取得
                $detail_response = $jgrants_client->get_subsidy_detail($subsidy['id']);
                
                if (is_wp_error($detail_response)) {
                    $results['error']++;
                    $results['details'][] = array(
                        'id' => $subsidy['id'],
                        'title' => isset($subsidy['title']) ? $subsidy['title'] : 'ID: ' . $subsidy['id'],
                        'status' => 'error',
                        'message' => $detail_response->get_error_message()
                    );
                    continue;
                }
                
                if (isset($detail_response['result'][0])) {
                    $detail_data = $detail_response['result'][0];
                    
                    // データの処理と保存
                    $result = $this->data_processor->process_and_save_grant($detail_data);
                    
                    if (!is_wp_error($result)) {
                        $results['success']++;
                        $results['details'][] = array(
                            'id' => $detail_data['id'],
                            'title' => $detail_data['title'],
                            'status' => 'success',
                            'post_id' => $result
                        );
                    } else {
                        if ($result->get_error_code() === 'duplicate_grant') {
                            $results['duplicate']++;
                            $results['details'][] = array(
                                'id' => $detail_data['id'],
                                'title' => $detail_data['title'],
                                'status' => 'duplicate',
                                'message' => '既に登録済み'
                            );
                        } else {
                            $results['error']++;
                            $results['details'][] = array(
                                'id' => $detail_data['id'],
                                'title' => $detail_data['title'],
                                'status' => 'error',
                                'message' => $result->get_error_message()
                            );
                        }
                    }
                }
                
            } catch (Exception $e) {
                $results['error']++;
                $results['details'][] = array(
                    'id' => $subsidy['id'],
                    'title' => isset($subsidy['title']) ? $subsidy['title'] : 'ID: ' . $subsidy['id'],
                    'status' => 'error',
                    'message' => $e->getMessage()
                );
            }
            
            // API制限を考慮した待機
            sleep(1);
        }
        
        return $results;
    }
    
    /**
     * 手動公開の実行（改善版）
     */
    public function execute_manual_publish($count) {
        $this->logger->log('手動公開開始: ' . $count . '件');
        
        $draft_posts = get_posts(array(
            'post_type' => 'grant',
            'post_status' => 'draft',
            'posts_per_page' => intval($count),
            'orderby' => 'date',
            'order' => 'ASC',
            'fields' => 'ids'
        ));
        
        $results = array(
            'success' => 0,
            'error' => 0,
            'details' => array()
        );
        
        if (empty($draft_posts)) {
            return $results;
        }
        
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
                $this->logger->log('公開エラー (ID: ' . $post_id . '): ' . $update_result->get_error_message(), 'error');
            } else {
                $results['success']++;
                $results['details'][] = array(
                    'id' => $post_id,
                    'title' => $post->post_title,
                    'status' => 'success'
                );
                $this->logger->log('公開成功: ' . $post->post_title);
            }
        }
        
        $this->logger->log('手動公開完了: 成功 ' . $results['success'] . '件、エラー ' . $results['error'] . '件');
        
        return $results;
    }
    
    /**
     * 下書き一括削除の実行（改善版）
     */
    public function execute_bulk_delete_drafts() {
        $this->logger->log('下書き一括削除開始');
        
        $results = array(
            'success' => 0,
            'error' => 0,
            'details' => array()
        );
        
        $batch_size = 50;
        
        while (true) {
            $draft_posts = get_posts(array(
                'post_type' => 'grant',
                'post_status' => 'draft',
                'posts_per_page' => $batch_size,
                'fields' => 'ids'
            ));
            
            if (empty($draft_posts)) {
                break;
            }
            
            foreach ($draft_posts as $post_id) {
                $delete_result = wp_delete_post($post_id, true);
                
                if ($delete_result !== false) {
                    $results['success']++;
                    $results['details'][] = array(
                        'id' => $post_id,
                        'status' => 'success'
                    );
                } else {
                    $results['error']++;
                    $results['details'][] = array(
                        'id' => $post_id,
                        'status' => 'error',
                        'message' => '削除に失敗しました'
                    );
                    $this->logger->log('削除エラー (ID: ' . $post_id . ')', 'error');
                }
            }
            
            // メモリクリーンアップ
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $this->logger->log('下書き一括削除完了: 成功 ' . $results['success'] . '件、エラー ' . $results['error'] . '件');
        
        return $results;
    }
    
    /**
     * Cronスケジュールの設定（改善版）
     */
    public function set_cron_schedule($schedule) {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        
        if ($schedule !== 'disabled') {
            $next_run = wp_schedule_event(time(), $schedule, self::CRON_HOOK);
            if ($next_run === false) {
                $this->logger->log('Cronスケジュール設定に失敗: ' . $schedule, 'error');
                return false;
            }
            $this->logger->log('Cronスケジュール設定: ' . $schedule);
        } else {
            $this->logger->log('Cronスケジュール無効化');
        }
        
        update_option('giji_improved_cron_schedule', $schedule);
        return true;
    }
    
    /**
     * 次回実行予定時刻の取得
     */
    public function get_next_scheduled_time() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        
        if ($timestamp) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        return false;
    }
    
    /**
     * 自動インポートが有効かどうかを確認
     */
    private function is_auto_import_enabled() {
        $schedule = get_option('giji_improved_cron_schedule', 'daily');
        return $schedule !== 'disabled';
    }
    
    /**
     * 検索パラメータの取得（改善版）
     */
    private function get_search_parameters() {
        $search_settings = get_option('giji_improved_search_settings', array());
        
        $default_params = array(
            'keyword' => '補助金',
            'sort' => 'created_date',
            'order' => 'DESC',
            'acceptance' => '1',
            'per_page' => 10
        );
        
        $params = wp_parse_args($search_settings, $default_params);
        
        // 手動インポート用の設定を適用
        if (isset($search_settings['min_amount']) && $search_settings['min_amount'] > 0) {
            $params['min_amount'] = $search_settings['min_amount'];
        }
        
        if (isset($search_settings['max_amount']) && $search_settings['max_amount'] > 0) {
            $params['max_amount'] = $search_settings['max_amount'];
        }
        
        if (isset($search_settings['target_areas']) && is_array($search_settings['target_areas'])) {
            $params['target_areas'] = $search_settings['target_areas'];
        }
        
        if (isset($search_settings['use_purposes']) && is_array($search_settings['use_purposes'])) {
            $params['use_purposes'] = $search_settings['use_purposes'];
        }
        
        return $params;
    }
    
    /**
     * 最大処理件数の取得
     */
    private function get_max_process_count() {
        $max_count = intval(get_option('giji_improved_max_process_count', 10));
        return max(1, min(50, $max_count)); // 1-50の範囲に制限
    }
    
    /**
     * インポート結果の保存（改善版）
     */
    private function save_import_result($processed_count, $total_count, $error_count = 0) {
        $result = array(
            'timestamp' => current_time('mysql'),
            'processed_count' => intval($processed_count),
            'total_count' => intval($total_count),
            'error_count' => intval($error_count),
            'status' => 'completed'
        );
        
        update_option('giji_improved_last_import_result', $result);
        
        // 履歴の保存（最新10件）
        $history = get_option('giji_improved_import_history', array());
        array_unshift($history, $result);
        $history = array_slice($history, 0, 10);
        update_option('giji_improved_import_history', $history);
    }
    
    /**
     * 最後のインポート結果の取得
     */
    public function get_last_import_result() {
        return get_option('giji_improved_last_import_result', false);
    }
    
    /**
     * インポート履歴の取得
     */
    public function get_import_history() {
        return get_option('giji_improved_import_history', array());
    }
    
    /**
     * 下書き投稿数の取得
     */
    public function get_draft_count() {
        $count = wp_count_posts('grant');
        return isset($count->draft) ? intval($count->draft) : 0;
    }
    
    /**
     * 公開投稿数の取得
     */
    public function get_published_count() {
        $count = wp_count_posts('grant');
        return isset($count->publish) ? intval($count->publish) : 0;
    }
    
    /**
     * 統計情報の取得
     */
    public function get_statistics() {
        global $wpdb;
        
        // 基本統計
        $stats = array(
            'draft_count' => $this->get_draft_count(),
            'published_count' => $this->get_published_count(),
            'total_count' => 0
        );
        
        $stats['total_count'] = $stats['draft_count'] + $stats['published_count'];
        
        // 今月の統計
        $current_month = date('Y-m');
        $monthly_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as monthly_total,
                SUM(CASE WHEN post_status = 'publish' THEN 1 ELSE 0 END) as monthly_published,
                SUM(CASE WHEN post_status = 'draft' THEN 1 ELSE 0 END) as monthly_draft
             FROM {$wpdb->posts} 
             WHERE post_type = 'grant' 
             AND DATE_FORMAT(post_date, '%%Y-%%m') = %s",
            $current_month
        ));
        
        if ($monthly_stats) {
            $stats['monthly_total'] = intval($monthly_stats->monthly_total);
            $stats['monthly_published'] = intval($monthly_stats->monthly_published);
            $stats['monthly_draft'] = intval($monthly_stats->monthly_draft);
        } else {
            $stats['monthly_total'] = 0;
            $stats['monthly_published'] = 0;
            $stats['monthly_draft'] = 0;
        }
        
        return $stats;
    }
}