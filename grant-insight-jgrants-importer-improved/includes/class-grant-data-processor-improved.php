<?php
/**
 * 助成金データ処理クラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Grant_Data_Processor {
    
    private $jgrants_client;
    private $ai_client;
    private $logger;
    
    public function __construct($jgrants_client, $ai_client, $logger) {
        $this->jgrants_client = $jgrants_client;
        $this->ai_client = $ai_client;
        $this->logger = $logger;
    }
    
    /**
     * 助成金データを処理してWordPressに保存（改善版）
     */
    public function process_and_save_grant($subsidy_data) {
        $this->logger->log('助成金データ処理開始: ID ' . $subsidy_data['id'], 'info', $subsidy_data);
        
        // 重複チェック
        if ($this->is_duplicate($subsidy_data['id'])) {
            $this->logger->log('重複データのためスキップ: ID ' . $subsidy_data['id']);
            return new WP_Error('duplicate_grant', '既に登録済みの助成金です', array('id' => $subsidy_data['id']));
        }
        
        // 除外条件チェック
        $exclusion_check = $this->check_exclusion_criteria($subsidy_data);
        if (is_wp_error($exclusion_check)) {
            $this->logger->log('除外条件に該当: ' . $exclusion_check->get_error_message() . ' (ID: ' . $subsidy_data['id'] . ')');
            return $exclusion_check;
        }
        
        // データマッピング
        $mapped_data = $this->map_jgrants_data($subsidy_data);
        
        // AI生成データの追加
        $ai_data = $this->generate_ai_content($mapped_data);
        if (!is_wp_error($ai_data)) {
            $mapped_data = array_merge($mapped_data, $ai_data);
        } else {
            $this->logger->log('AI生成でエラーが発生しましたが処理を継続: ' . $ai_data->get_error_message(), 'warning');
        }
        
        // WordPressに投稿として保存
        $post_id = $this->save_as_wordpress_post($mapped_data);
        
        if (is_wp_error($post_id)) {
            $this->logger->log('投稿保存エラー: ' . $post_id->get_error_message(), 'error');
            return $post_id;
        }
        
        $this->logger->log('助成金データ保存成功: 投稿ID ' . $post_id . ' (JグランツID: ' . $subsidy_data['id'] . ')');
        
        return $post_id;
    }
    
    /**
     * 除外条件のチェック（改善版）
     */
    private function check_exclusion_criteria($subsidy_data) {
        $search_settings = get_option('giji_improved_search_settings', array());
        
        // 補助額上限チェック
        if (isset($search_settings['exclude_zero_amount']) && $search_settings['exclude_zero_amount']) {
            if (isset($subsidy_data['subsidy_max_limit'])) {
                $max_amount = $subsidy_data['subsidy_max_limit'];
                if (empty($max_amount) || $max_amount === '0' || $max_amount === 0 || $max_amount === '未定') {
                    return new WP_Error('invalid_amount', '補助額上限が0または不明な助成金です');
                }
            }
        }
        
        // 金額範囲チェック
        if (isset($search_settings['min_amount']) && $search_settings['min_amount'] > 0) {
            if (isset($subsidy_data['subsidy_max_limit'])) {
                $amount = intval($subsidy_data['subsidy_max_limit']);
                if ($amount < intval($search_settings['min_amount'])) {
                    return new WP_Error('amount_too_low', '最小金額条件を満たしていません');
                }
            }
        }
        
        if (isset($search_settings['max_amount']) && $search_settings['max_amount'] > 0) {
            if (isset($subsidy_data['subsidy_max_limit'])) {
                $amount = intval($subsidy_data['subsidy_max_limit']);
                if ($amount > intval($search_settings['max_amount'])) {
                    return new WP_Error('amount_too_high', '最大金額条件を超えています');
                }
            }
        }
        
        // 募集状況チェック
        if (isset($search_settings['acceptance_only']) && $search_settings['acceptance_only']) {
            if (isset($subsidy_data['acceptance_end_datetime'])) {
                $end_date = strtotime($subsidy_data['acceptance_end_datetime']);
                if ($end_date && $end_date < time()) {
                    return new WP_Error('application_closed', '募集が終了している助成金です');
                }
            }
        }
        
        return true;
    }
    
    /**
     * 重複チェック（改善版）
     */
    private function is_duplicate($jgrants_id) {
        global $wpdb;
        
        // 効率的なクエリで重複チェック
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = 'jgrants_id' 
             AND pm.meta_value = %s 
             AND p.post_type = 'grant' 
             AND p.post_status IN ('publish', 'draft', 'private')",
            $jgrants_id
        ));
        
        return intval($count) > 0;
    }
    
    /**
     * JグランツAPIデータをWordPress形式にマッピング（改善版）
     */
    private function map_jgrants_data($subsidy_data) {
        $mapped_data = array();
        
        // 基本情報
        $mapped_data['jgrants_id'] = $subsidy_data['id'];
        $mapped_data['title'] = isset($subsidy_data['title']) ? sanitize_text_field($subsidy_data['title']) : '';
        
        // 概要データの取得（複数フィールドから取得）
        $overview_fields = array('detail', 'overview', 'description', 'summary');
        $overview = '';
        foreach ($overview_fields as $field) {
            if (isset($subsidy_data[$field]) && !empty($subsidy_data[$field])) {
                $overview = $subsidy_data[$field];
                break;
            }
        }
        $mapped_data['overview'] = wp_kses_post($overview);
        
        // 募集終了日時の処理
        if (isset($subsidy_data['acceptance_end_datetime'])) {
            $datetime = $subsidy_data['acceptance_end_datetime'];
            $mapped_data['deadline_date'] = $this->format_date_ymd($datetime);
            $mapped_data['deadline_text'] = $this->format_date_japanese($datetime);
        }
        
        // 補助額上限の処理（改善版）
        if (isset($subsidy_data['subsidy_max_limit'])) {
            $amount_raw = $subsidy_data['subsidy_max_limit'];
            $amount_numeric = $this->extract_numeric_amount($amount_raw);
            
            $mapped_data['max_amount_numeric'] = $amount_numeric;
            $mapped_data['max_amount'] = $this->format_amount_display($amount_numeric);
            $mapped_data['max_amount_raw'] = sanitize_text_field($amount_raw);
        }
        
        // その他の情報
        $mapped_data['subsidy_rate'] = isset($subsidy_data['subsidy_rate']) ? sanitize_text_field($subsidy_data['subsidy_rate']) : '';
        $mapped_data['official_url'] = isset($subsidy_data['front_subsidy_detail_page_url']) ? esc_url_raw($subsidy_data['front_subsidy_detail_page_url']) : '';
        $mapped_data['use_purpose'] = isset($subsidy_data['use_purpose']) ? sanitize_text_field($subsidy_data['use_purpose']) : '';
        $mapped_data['target_area_search'] = isset($subsidy_data['target_area_search']) ? sanitize_text_field($subsidy_data['target_area_search']) : '';
        
        // 新しいフィールド
        $mapped_data['applicant_type'] = isset($subsidy_data['applicant_type']) ? sanitize_text_field($subsidy_data['applicant_type']) : '';
        $mapped_data['application_method'] = isset($subsidy_data['application_method']) ? sanitize_text_field($subsidy_data['application_method']) : '';
        $mapped_data['contact_info'] = isset($subsidy_data['contact_info']) ? wp_kses_post($subsidy_data['contact_info']) : '';
        
        return $mapped_data;
    }
    
    /**
     * 金額の数値抽出（改善版）
     */
    private function extract_numeric_amount($amount_text) {
        if (empty($amount_text) || $amount_text === '未定' || $amount_text === 'なし') {
            return 0;
        }
        
        // 数値のみを抽出
        $amount_text = preg_replace('/[^\d]/', '', $amount_text);
        $numeric_amount = intval($amount_text);
        
        // 万円単位の検出
        $original_text = strtolower($amount_text);
        if (strpos($original_text, '万') !== false) {
            $numeric_amount *= 10000;
        } elseif (strpos($original_text, '億') !== false) {
            $numeric_amount *= 100000000;
        } elseif (strpos($original_text, '千') !== false) {
            $numeric_amount *= 1000;
        }
        
        return $numeric_amount;
    }
    
    /**
     * 金額の表示形式
     */
    private function format_amount_display($amount_numeric) {
        if ($amount_numeric == 0) {
            return '未定';
        }
        
        if ($amount_numeric >= 100000000) { // 1億以上
            return number_format($amount_numeric / 100000000, 1) . '億円';
        } elseif ($amount_numeric >= 10000) { // 1万以上
            return number_format($amount_numeric / 10000, 0) . '万円';
        } else {
            return number_format($amount_numeric) . '円';
        }
    }
    
    /**
     * AI生成コンテンツの作成（改善版）
     */
    private function generate_ai_content($grant_data) {
        $ai_enabled = get_option('giji_improved_ai_generation_enabled', array());
        $ai_data = array();
        
        $this->logger->log('AI生成開始: ' . $grant_data['title']);
        
        $generation_methods = array(
            'content' => 'generate_content',
            'excerpt' => 'generate_excerpt',
            'summary' => 'generate_summary',
            'organization' => 'extract_organization',
            'difficulty' => 'judge_difficulty',
            'success_rate' => 'estimate_success_rate',
            'keywords' => 'generate_keywords',
            'target_audience' => 'generate_target_audience',
            'application_tips' => 'generate_application_tips',
            'requirements' => 'generate_requirements'
        );
        
        foreach ($generation_methods as $key => $method) {
            if (isset($ai_enabled[$key]) && $ai_enabled[$key]) {
                try {
                    $this->logger->log("AI生成実行: {$key} ({$method})");
                    
                    $result = $this->ai_client->$method($grant_data);
                    
                    if (!is_wp_error($result)) {
                        switch ($key) {
                            case 'content':
                                $ai_data['post_content'] = $result;
                                break;
                            case 'excerpt':
                                $ai_data['ai_excerpt'] = $result;
                                break;
                            case 'summary':
                                $ai_data['ai_summary'] = $result;
                                break;
                            case 'organization':
                                $ai_data['organization'] = $result;
                                break;
                            case 'difficulty':
                                $ai_data['difficulty_level'] = $result;
                                break;
                            case 'success_rate':
                                $ai_data['grant_success_rate'] = $result;
                                break;
                            case 'keywords':
                                $ai_data['ai_keywords'] = $result;
                                break;
                            case 'target_audience':
                                $ai_data['ai_target_audience'] = $result;
                                break;
                            case 'application_tips':
                                $ai_data['ai_application_tips'] = $result;
                                break;
                            case 'requirements':
                                $ai_data['ai_requirements'] = $result;
                                break;
                        }
                        $this->logger->log("AI生成成功: {$key}");
                    } else {
                        $this->logger->log("AI生成エラー ({$key}): " . $result->get_error_message(), 'warning');
                    }
                } catch (Exception $e) {
                    $this->logger->log("AI生成で例外発生 ({$key}): " . $e->getMessage(), 'warning');
                }
                
                // API制限を考慮した待機
                sleep(1);
            }
        }
        
        $this->logger->log('AI生成完了: 生成アイテム数 ' . count($ai_data));
        
        return $ai_data;
    }
    
    /**
     * WordPressの投稿として保存（改善版）
     */
    private function save_as_wordpress_post($grant_data) {
        // 本文の準備
        $post_content = isset($grant_data['post_content']) ? $grant_data['post_content'] : $grant_data['overview'];
        
        // 抜粋の準備
        $post_excerpt = isset($grant_data['ai_excerpt']) ? $grant_data['ai_excerpt'] : '';
        if (empty($post_excerpt) && !empty($grant_data['overview'])) {
            $post_excerpt = wp_trim_words($grant_data['overview'], 30);
        }
        
        // 投稿データの準備
        $post_data = array(
            'post_title' => $grant_data['title'],
            'post_content' => wp_kses_post($post_content),
            'post_excerpt' => wp_kses_post($post_excerpt),
            'post_status' => 'draft',
            'post_type' => 'grant',
            'post_author' => 1,
            'meta_input' => array()
        );
        
        // カスタムフィールドの準備
        $custom_fields = array(
            'jgrants_id',
            'ai_summary',
            'ai_excerpt',
            'deadline_date',
            'deadline_text',
            'max_amount_numeric',
            'max_amount',
            'max_amount_raw',
            'organization',
            'difficulty_level',
            'grant_success_rate',
            'official_url',
            'subsidy_rate',
            'ai_keywords',
            'ai_target_audience',
            'ai_application_tips',
            'ai_requirements'
        );
        
        foreach ($custom_fields as $field) {
            if (isset($grant_data[$field])) {
                $post_data['meta_input'][$field] = $grant_data[$field];
            }
        }
        
        // 投稿の作成
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // タクソノミーの設定
        $this->set_taxonomies($post_id, $grant_data);
        
        return $post_id;
    }
    
    /**
     * タクソノミーの設定（改善版）
     */
    private function set_taxonomies($post_id, $grant_data) {
        // 補助対象地域の設定
        if (isset($grant_data['target_area_search']) && !empty($grant_data['target_area_search'])) {
            $this->set_prefecture_taxonomy($post_id, $grant_data['target_area_search']);
        }
        
        // 利用目的の設定
        if (isset($grant_data['use_purpose']) && !empty($grant_data['use_purpose'])) {
            $this->set_category_taxonomy($post_id, $grant_data['use_purpose']);
        }
        
        // 実施組織の設定
        if (isset($grant_data['organization']) && !empty($grant_data['organization'])) {
            $this->set_organization_taxonomy($post_id, $grant_data['organization']);
        }
    }
    
    /**
     * 都道府県タクソノミーの設定
     */
    private function set_prefecture_taxonomy($post_id, $target_areas) {
        $areas = array_map('trim', explode('/', $target_areas));
        $term_ids = array();
        
        foreach ($areas as $area) {
            if (empty($area)) continue;
            
            // 「全国」の場合はすべての都道府県を追加
            if ($area === '全国') {
                $all_prefectures = $this->get_all_prefectures();
                foreach ($all_prefectures as $prefecture) {
                    $term = $this->get_or_create_term($prefecture, 'grant_prefecture');
                    if ($term && !is_wp_error($term)) {
                        $term_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
                    }
                }
            } else {
                $term = $this->get_or_create_term($area, 'grant_prefecture');
                if ($term && !is_wp_error($term)) {
                    $term_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
                }
            }
        }
        
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'grant_prefecture');
        }
    }
    
    /**
     * カテゴリタクソノミーの設定
     */
    private function set_category_taxonomy($post_id, $use_purposes) {
        $purposes = array_map('trim', explode(',', $use_purposes));
        $term_ids = array();
        
        foreach ($purposes as $purpose) {
            if (empty($purpose)) continue;
            
            $term = $this->get_or_create_term($purpose, 'grant_category');
            if ($term && !is_wp_error($term)) {
                $term_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
            }
        }
        
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'grant_category');
        }
    }
    
    /**
     * 実施組織タクソノミーの設定
     */
    private function set_organization_taxonomy($post_id, $organization) {
        $term = $this->get_or_create_term($organization, 'grant_organization');
        if ($term && !is_wp_error($term)) {
            $term_id = is_array($term) ? $term['term_id'] : $term->term_id;
            wp_set_object_terms($post_id, array($term_id), 'grant_organization');
        }
    }
    
    /**
     * タームの取得または作成
     */
    private function get_or_create_term($term_name, $taxonomy) {
        $term = get_term_by('name', $term_name, $taxonomy);
        
        if (!$term) {
            $result = wp_insert_term($term_name, $taxonomy);
            if (!is_wp_error($result)) {
                return $result;
            }
        } else {
            return $term;
        }
        
        return false;
    }
    
    /**
     * 全都道府県のリスト
     */
    private function get_all_prefectures() {
        return array(
            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県',
            '岐阜県', '静岡県', '愛知県', '三重県',
            '滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県',
            '鳥取県', '島根県', '岡山県', '広島県', '山口県',
            '徳島県', '香川県', '愛媛県', '高知県',
            '福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
        );
    }
    
    /**
     * 日付フォーマット（Ymd形式）
     */
    private function format_date_ymd($datetime_string) {
        $timestamp = strtotime($datetime_string);
        return $timestamp ? date('Ymd', $timestamp) : '';
    }
    
    /**
     * 日付フォーマット（日本語形式）
     */
    private function format_date_japanese($datetime_string) {
        $timestamp = strtotime($datetime_string);
        return $timestamp ? date('Y年n月j日', $timestamp) : '';
    }
}