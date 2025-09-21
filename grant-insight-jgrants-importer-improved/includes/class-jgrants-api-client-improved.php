<?php
/**
 * JグランツAPI連携クラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_JGrants_API_Client {
    
    private $base_url = 'https://api.jgrants-portal.go.jp/exp/v1/public';
    private $logger;
    private $cache_duration = 3600; // 1時間
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    /**
     * 助成金一覧を取得（改善版）
     */
    public function get_subsidies($params = array()) {
        $default_params = array(
            'keyword' => '補助金',
            'sort' => 'created_date',
            'order' => 'DESC',
            'acceptance' => '1',
            'per_page' => 10
        );
        
        $params = wp_parse_args($params, $default_params);
        
        // キーワード検索の修正（明示的なフォールバック）
        if (isset($params['keyword'])) {
            $keyword = trim($params['keyword']);
            if (mb_strlen($keyword) < 2) {
                $this->logger->log('キーワードが短すぎるため広範囲検索に変更: "' . $keyword . '" → "' . $default_params['keyword'] . '"', 'warning');
                $keyword = $default_params['keyword'];
            }
            $params['keyword'] = $keyword;
        }
        
        // 件数制限の正確な実装
        if (isset($params['per_page'])) {
            $per_page = intval($params['per_page']);
            if ($per_page < 1) {
                $per_page = 1;
            } elseif ($per_page > 100) {
                $per_page = 100; // API制限
            }
            $params['per_page'] = $per_page;
        }
        
        // 金額フィルターの追加
        if (isset($params['min_amount']) && $params['min_amount'] > 0) {
            $params['subsidy_max_limit_from'] = intval($params['min_amount']);
        }
        
        if (isset($params['max_amount']) && $params['max_amount'] > 0) {
            $params['subsidy_max_limit_to'] = intval($params['max_amount']);
        }
        
        // 地域フィルターの追加
        if (isset($params['target_areas']) && is_array($params['target_areas']) && !empty($params['target_areas'])) {
            $params['target_area'] = implode(',', $params['target_areas']);
        }
        
        // 利用目的フィルターの追加
        if (isset($params['use_purposes']) && is_array($params['use_purposes']) && !empty($params['use_purposes'])) {
            $params['use_purpose'] = implode(',', $params['use_purposes']);
        }
        
        // 募集中のみフィルター
        if (isset($params['acceptance_only']) && $params['acceptance_only']) {
            $params['acceptance'] = '1';
        }
        
        $endpoint = $this->base_url . '/subsidies';
        $url = add_query_arg($params, $endpoint);
        
        $this->logger->log('JグランツAPI一覧取得開始: ' . $url . ' (キーワード: ' . $params['keyword'] . ', 件数: ' . $params['per_page'] . ')');
        
        // キャッシュチェック
        $cache_key = 'giji_subsidies_' . md5($url);
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            $this->logger->log('キャッシュから取得: ' . count($cached_result['result']) . '件');
            return $cached_result;
        }
        
        $response = $this->make_request($url);
        
        if (is_wp_error($response)) {
            $this->logger->log('JグランツAPI一覧取得エラー: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        // 取得件数の正確な制限
        if (isset($response['result']) && is_array($response['result'])) {
            $max_count = intval($params['per_page']);
            if (count($response['result']) > $max_count) {
                $response['result'] = array_slice($response['result'], 0, $max_count);
            }
        }
        
        // キャッシュに保存
        set_transient($cache_key, $response, $this->cache_duration);
        
        $this->logger->log('JグランツAPI一覧取得成功: ' . count($response['result']) . '件');
        
        return $response;
    }
    
    /**
     * 助成金詳細を取得（改善版）
     */
    public function get_subsidy_detail($id) {
        if (empty($id)) {
            return new WP_Error('invalid_id', '助成金IDが指定されていません');
        }
        
        $endpoint = $this->base_url . '/subsidies/id/' . $id;
        
        $this->logger->log('JグランツAPI詳細取得開始: ' . $endpoint);
        
        // キャッシュチェック
        $cache_key = 'giji_subsidy_detail_' . $id;
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            $this->logger->log('詳細データをキャッシュから取得: ID ' . $id);
            return $cached_result;
        }
        
        $response = $this->make_request($endpoint);
        
        if (is_wp_error($response)) {
            $this->logger->log('JグランツAPI詳細取得エラー: ' . $response->get_error_message(), 'error');
            return $response;
        }
        
        // キャッシュに保存（詳細データは長期保存）
        set_transient($cache_key, $response, $this->cache_duration * 4);
        
        $this->logger->log('JグランツAPI詳細取得成功: ID ' . $id);
        
        return $response;
    }
    
    /**
     * APIリクエストを実行（改善版）
     */
    private function make_request($url, $args = array()) {
        if (!function_exists('wp_remote_get')) {
            return new WP_Error('wp_not_loaded', 'WordPressが完全に読み込まれていません');
        }
        
        $default_args = array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Grant Insight Jグランツ・インポーター改善版/' . GIJI_IMPROVED_PLUGIN_VERSION,
                'Accept' => 'application/json',
                'Accept-Language' => 'ja,en;q=0.9'
            ),
            'sslverify' => true
        );
        
        $args = wp_parse_args($args, $default_args);
        
        try {
            // リトライ機能付きリクエスト
            $max_retries = 3;
            $retry_delay = 1;
            
            for ($i = 0; $i < $max_retries; $i++) {
                if ($i > 0) {
                    sleep($retry_delay);
                    $retry_delay *= 2; // 指数バックオフ
                    $this->logger->log("APIリクエスト再試行 {$i}回目: {$url}", 'warning');
                }
                
                $response = wp_remote_get($url, $args);
                
                if (is_wp_error($response)) {
                    if ($i == $max_retries - 1) {
                        return $response;
                    }
                    continue;
                }
                
                $response_code = wp_remote_retrieve_response_code($response);
                
                // 一時的なエラーの場合はリトライ
                if (in_array($response_code, [429, 502, 503, 504])) {
                    if ($i == $max_retries - 1) {
                        return new WP_Error('http_error', sprintf('HTTPエラー: %d (最大試行回数に達しました)', $response_code));
                    }
                    continue;
                }
                
                if ($response_code !== 200) {
                    $response_body = wp_remote_retrieve_body($response);
                    $error_data = json_decode($response_body, true);
                    $error_message = sprintf('HTTPエラー: %d', $response_code);
                    
                    if ($error_data && isset($error_data['message'])) {
                        $error_message .= ' - ' . $error_data['message'];
                    }
                    
                    return new WP_Error('http_error', $error_message, array('status' => $response_code));
                }
                
                $response_body = wp_remote_retrieve_body($response);
                $data = json_decode($response_body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return new WP_Error('json_error', 'JSONデコードエラー: ' . json_last_error_msg());
                }
                
                return $data;
            }
            
        } catch (Exception $e) {
            return new WP_Error('request_error', 'APIリクエストエラー: ' . $e->getMessage());
        }
        
        return new WP_Error('request_failed', 'APIリクエストが失敗しました');
    }
    
    /**
     * API接続テスト（改善版）
     */
    public function test_connection() {
        $test_params = array(
            'keyword' => 'テスト',
            'sort' => 'created_date',
            'order' => 'DESC',
            'acceptance' => '0',
            'per_page' => 1
        );
        
        $response = $this->get_subsidies($test_params);
        
        if (is_wp_error($response)) {
            $this->logger->log('API接続テスト失敗: ' . $response->get_error_message(), 'error');
            return false;
        }
        
        $this->logger->log('API接続テスト成功');
        return true;
    }
    
    /**
     * キャッシュクリア
     */
    public function clear_cache() {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_giji_subsidies_%' OR option_name LIKE '_transient_giji_subsidy_detail_%'");
        
        $this->logger->log('APIキャッシュをクリアしました');
        
        return true;
    }
    
    /**
     * 利用目的の選択肢を取得（拡張版）
     */
    public function get_use_purposes() {
        return array(
            'new_business' => '新たな事業を行いたい',
            'sales_expansion' => '販路拡大・海外展開をしたい',
            'event_support' => 'イベント・事業運営支援がほしい',
            'business_succession' => '事業を引き継ぎたい',
            'research_development' => '研究開発・実証事業を行いたい',
            'human_resources' => '人材育成を行いたい',
            'cash_flow' => '資金繰りを改善したい',
            'equipment_it' => '設備整備・IT導入をしたい',
            'employment' => '雇用・職場環境を改善したい',
            'eco_sdgs' => 'エコ・SDGs活動支援がほしい',
            'disaster_support' => '災害（自然災害、感染症等）支援がほしい',
            'education_childcare' => '教育・子育て・少子化支援がほしい',
            'sports_culture' => 'スポーツ・文化支援がほしい',
            'safety_disaster_prevention' => '安全・防災対策支援がほしい',
            'community_development' => 'まちづくり・地域振興支援がほしい'
        );
    }
    
    /**
     * 補助対象地域の選択肢を取得（拡張版）
     */
    public function get_target_areas() {
        return array(
            'national' => '全国',
            'hokkaido_region' => '北海道地方',
            'tohoku_region' => '東北地方',
            'kanto_koshinetsu_region' => '関東・甲信越地方',
            'tokai_hokuriku_region' => '東海・北陸地方',
            'kinki_region' => '近畿地方',
            'chugoku_region' => '中国地方',
            'shikoku_region' => '四国地方',
            'kyushu_okinawa_region' => '九州・沖縄地方',
            'hokkaido' => '北海道',
            'aomori' => '青森県',
            'iwate' => '岩手県',
            'miyagi' => '宮城県',
            'akita' => '秋田県',
            'yamagata' => '山形県',
            'fukushima' => '福島県',
            'ibaraki' => '茨城県',
            'tochigi' => '栃木県',
            'gunma' => '群馬県',
            'saitama' => '埼玉県',
            'chiba' => '千葉県',
            'tokyo' => '東京都',
            'kanagawa' => '神奈川県',
            'niigata' => '新潟県',
            'toyama' => '富山県',
            'ishikawa' => '石川県',
            'fukui' => '福井県',
            'yamanashi' => '山梨県',
            'nagano' => '長野県',
            'gifu' => '岐阜県',
            'shizuoka' => '静岡県',
            'aichi' => '愛知県',
            'mie' => '三重県',
            'shiga' => '滋賀県',
            'kyoto' => '京都府',
            'osaka' => '大阪府',
            'hyogo' => '兵庫県',
            'nara' => '奈良県',
            'wakayama' => '和歌山県',
            'tottori' => '鳥取県',
            'shimane' => '島根県',
            'okayama' => '岡山県',
            'hiroshima' => '広島県',
            'yamaguchi' => '山口県',
            'tokushima' => '徳島県',
            'kagawa' => '香川県',
            'ehime' => '愛媛県',
            'kochi' => '高知県',
            'fukuoka' => '福岡県',
            'saga' => '佐賀県',
            'nagasaki' => '長崎県',
            'kumamoto' => '熊本県',
            'oita' => '大分県',
            'miyazaki' => '宮崎県',
            'kagoshima' => '鹿児島県',
            'okinawa' => '沖縄県'
        );
    }
}