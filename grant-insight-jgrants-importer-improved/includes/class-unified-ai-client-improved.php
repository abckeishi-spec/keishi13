<?php
/**
 * 統合AI APIクライアントクラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Unified_AI_Client {
    
    private $provider;
    private $logger;
    private $security_manager;
    private $model;
    
    private $openai_base_url = 'https://api.openai.com/v1';
    private $claude_base_url = 'https://api.anthropic.com/v1';
    private $gemini_base_url = 'https://generativelanguage.googleapis.com/v1beta';
    
    public function __construct($logger, $security_manager) {
        $this->logger = $logger;
        $this->security_manager = $security_manager;
        
        // 保存された設定からプロバイダーを読み込み
        $this->provider = get_option('giji_improved_ai_provider', 'gemini');
        
        // プロバイダーに応じて正しいモデルを設定
        switch ($this->provider) {
            case 'openai':
                $this->model = get_option('giji_improved_openai_model', 'gpt-4o-mini');
                break;
            case 'gemini':
                $this->model = get_option('giji_improved_gemini_model', 'gemini-pro');
                break;
            case 'claude':
                $this->model = get_option('giji_improved_claude_model', 'claude-3-sonnet-20240229');
                break;
            default:
                $this->provider = 'gemini';
                $this->model = get_option('giji_improved_gemini_model', 'gemini-pro');
                break;
        }
        
        // ログで確認用
        $this->logger->log("AI Client initialized: provider={$this->provider}, model={$this->model}", 'info');
    }
    
    /**
     * APIキーの取得
     */
    private function get_api_key() {
        switch($this->provider) {
            case 'openai':
                return $this->security_manager->get_api_key('openai_api_key');
            case 'claude':
                return $this->security_manager->get_api_key('claude_api_key');
            case 'gemini':
            default:
                return $this->security_manager->get_api_key('gemini_api_key');
        }
    }
    
    /**
     * テキスト生成（改善版）
     */
    public function generate_text($prompt, $config = array()) {
        if (empty($prompt)) {
            return new WP_Error('empty_prompt', 'プロンプトが空です');
        }
        
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'APIキーが設定されていません');
        }
        
        // 高度な設定の取得
        $advanced_settings = get_option('giji_improved_ai_advanced_settings', array());
        $config = wp_parse_args($config, $advanced_settings);
        
        $max_retries = isset($config['retry_count']) ? intval($config['retry_count']) : 3;
        
        for ($i = 0; $i < $max_retries; $i++) {
            if ($i > 0) {
                $this->logger->log("AI生成リトライ {$i}回目", 'warning');
                sleep(pow(2, $i)); // 指数バックオフ
            }
            
            switch($this->provider) {
                case 'openai':
                    $result = $this->generate_text_openai($prompt, $config, $api_key);
                    break;
                case 'claude':
                    $result = $this->generate_text_claude($prompt, $config, $api_key);
                    break;
                case 'gemini':
                default:
                    $result = $this->generate_text_gemini($prompt, $config, $api_key);
                    break;
            }
            
            if (!is_wp_error($result)) {
                return $result;
            }
            
            $this->logger->log("AI生成エラー (試行{$i}): " . $result->get_error_message(), 'warning');
        }
        
        // フォールバック処理
        if (isset($config['fallback_enabled']) && $config['fallback_enabled']) {
            return $this->get_fallback_content($prompt);
        }
        
        return $result;
    }
    
    /**
     * OpenAI APIでテキスト生成（改善版）
     */
    private function generate_text_openai($prompt, $config, $api_key) {
        $default_config = array(
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'top_p' => 0.9,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0
        );
        
        $config = wp_parse_args($config, $default_config);
        
        $request_body = array(
            'model' => $this->model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => floatval($config['temperature']),
            'max_tokens' => intval($config['max_tokens']),
            'top_p' => floatval($config['top_p']),
            'frequency_penalty' => floatval($config['frequency_penalty']),
            'presence_penalty' => floatval($config['presence_penalty'])
        );
        
        $url = $this->openai_base_url . '/chat/completions';
        
        $this->logger->log('OpenAI APIテキスト生成開始');
        
        $args = array(
            'method' => 'POST',
            'timeout' => isset($config['timeout']) ? intval($config['timeout']) : 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'User-Agent' => 'Grant Insight Jグランツ・インポーター改善版/' . GIJI_IMPROVED_PLUGIN_VERSION
            ),
            'body' => wp_json_encode($request_body)
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTPエラー: ' . $response_code;
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($response_body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            $this->logger->log('OpenAI APIテキスト生成成功');
            return trim($data['choices'][0]['message']['content']);
        }
        
        return new WP_Error('invalid_response', 'APIレスポンスの形式が不正です');
    }
    
    /**
     * Claude APIでテキスト生成（改善版）
     */
    private function generate_text_claude($prompt, $config, $api_key) {
        $default_config = array(
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'top_p' => 0.9
        );
        
        $config = wp_parse_args($config, $default_config);
        
        $request_body = array(
            'model' => $this->model,
            'max_tokens' => intval($config['max_tokens']),
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => floatval($config['temperature']),
            'top_p' => floatval($config['top_p'])
        );
        
        $url = $this->claude_base_url . '/messages';
        
        $this->logger->log('Claude APIテキスト生成開始');
        
        $args = array(
            'method' => 'POST',
            'timeout' => isset($config['timeout']) ? intval($config['timeout']) : 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'User-Agent' => 'Grant Insight Jグランツ・インポーター改善版/' . GIJI_IMPROVED_PLUGIN_VERSION
            ),
            'body' => wp_json_encode($request_body)
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTPエラー: ' . $response_code;
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($response_body, true);
        
        if (isset($data['content'][0]['text'])) {
            $this->logger->log('Claude APIテキスト生成成功');
            return trim($data['content'][0]['text']);
        }
        
        return new WP_Error('invalid_response', 'APIレスポンスの形式が不正です');
    }
    
    /**
     * Gemini APIでテキスト生成（改善版）
     */
    private function generate_text_gemini($prompt, $config, $api_key) {
        $default_config = array(
            'temperature' => 0.7,
            'max_output_tokens' => 2048,
            'top_p' => 0.9,
            'top_k' => 40
        );
        
        $config = wp_parse_args($config, $default_config);
        
        $request_body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => floatval($config['temperature']),
                'maxOutputTokens' => intval($config['max_output_tokens']),
                'topP' => floatval($config['top_p']),
                'topK' => intval($config['top_k'])
            )
        );
        
        $endpoint = $this->gemini_base_url . '/models/' . $this->model . ':generateContent';
        $url = add_query_arg('key', $api_key, $endpoint);
        
        $this->logger->log('Gemini APIテキスト生成開始');
        
        $args = array(
            'method' => 'POST',
            'timeout' => isset($config['timeout']) ? intval($config['timeout']) : 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'Grant Insight Jグランツ・インポーター改善版/' . GIJI_IMPROVED_PLUGIN_VERSION
            ),
            'body' => wp_json_encode($request_body)
        );
        
        $response = wp_remote_post($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'HTTPエラー: ' . $response_code;
            return new WP_Error('api_error', $error_message);
        }
        
        $data = json_decode($response_body, true);
        
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $this->logger->log('Gemini APIテキスト生成成功');
            return trim($data['candidates'][0]['content']['parts'][0]['text']);
        }
        
        return new WP_Error('invalid_response', 'APIレスポンスの形式が不正です');
    }
    
    /**
     * 本文生成（改善版）
     */
    public function generate_content($grant_data) {
        $prompt_template = get_option('giji_improved_content_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '本文生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt);
    }
    
    /**
     * 抜粋生成（改善版）
     */
    public function generate_excerpt($grant_data) {
        $prompt_template = get_option('giji_improved_excerpt_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '抜粋生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 200));
    }
    
    /**
     * 要約生成（改善版）
     */
    public function generate_summary($grant_data) {
        $prompt_template = get_option('giji_improved_summary_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '要約生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 300));
    }
    
    /**
     * キーワード生成
     */
    public function generate_keywords($grant_data) {
        $prompt_template = get_option('giji_improved_keywords_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', 'キーワード生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 100));
    }
    
    /**
     * 対象者説明生成
     */
    public function generate_target_audience($grant_data) {
        $prompt_template = get_option('giji_improved_target_audience_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '対象者説明生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 200));
    }
    
    /**
     * 申請のコツ生成
     */
    public function generate_application_tips($grant_data) {
        $prompt_template = get_option('giji_improved_application_tips_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '申請のコツ生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 300));
    }
    
    /**
     * 要件生成
     */
    public function generate_requirements($grant_data) {
        $prompt_template = get_option('giji_improved_requirements_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '要件生成用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('max_tokens' => 400));
    }
    
    /**
     * 実施組織抽出（改善版）
     */
    public function extract_organization($grant_data) {
        $prompt_template = get_option('giji_improved_organization_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '実施組織抽出用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        return $this->generate_text($prompt, array('temperature' => 0.1, 'max_tokens' => 100));
    }
    
    /**
     * 申請難易度判定（改善版）
     */
    public function judge_difficulty($grant_data) {
        $prompt_template = get_option('giji_improved_difficulty_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '申請難易度判定用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        $response = $this->generate_text($prompt, array('temperature' => 0.3, 'max_tokens' => 50));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // 回答から難易度を抽出
        $response = strtolower(trim($response));
        if (strpos($response, 'easy') !== false || strpos($response, '易しい') !== false) {
            return 'easy';
        } elseif (strpos($response, 'hard') !== false || strpos($response, '難しい') !== false) {
            return 'hard';
        } else {
            return 'medium';
        }
    }
    
    /**
     * 採択率推定（改善版）
     */
    public function estimate_success_rate($grant_data) {
        $prompt_template = get_option('giji_improved_success_rate_prompt', '');
        if (empty($prompt_template)) {
            return new WP_Error('no_prompt', '採択率推定用プロンプトが設定されていません');
        }
        
        $prompt = $this->replace_variables($prompt_template, $grant_data);
        $response = $this->generate_text($prompt, array('temperature' => 0.3, 'max_tokens' => 50));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // 数値を抽出
        preg_match('/(\d+)/', $response, $matches);
        if (!empty($matches)) {
            $rate = intval($matches[0]);
            return max(0, min(100, $rate)); // 0-100の範囲に制限
        }
        
        return 50; // デフォルト値
    }
    
    /**
     * プロンプト内の変数を置換（拡張版）
     */
    private function replace_variables($template, $grant_data) {
        $variables = array(
            '[title]' => isset($grant_data['title']) ? $grant_data['title'] : '',
            '[overview]' => isset($grant_data['overview']) ? $grant_data['overview'] : '',
            '[max_amount]' => isset($grant_data['max_amount']) ? $grant_data['max_amount'] : '',
            '[deadline_text]' => isset($grant_data['deadline_text']) ? $grant_data['deadline_text'] : '',
            '[organization]' => isset($grant_data['organization']) ? $grant_data['organization'] : '',
            '[official_url]' => isset($grant_data['official_url']) ? $grant_data['official_url'] : '',
            '[subsidy_rate]' => isset($grant_data['subsidy_rate']) ? $grant_data['subsidy_rate'] : '',
            '[use_purpose]' => isset($grant_data['use_purpose']) ? $grant_data['use_purpose'] : '',
            '[target_area]' => isset($grant_data['target_area_search']) ? $grant_data['target_area_search'] : '',
            
            // 新しい変数
            '[補助金名]' => isset($grant_data['title']) ? $grant_data['title'] : '',
            '[概要]' => isset($grant_data['overview']) ? $grant_data['overview'] : '',
            '[補助額上限]' => isset($grant_data['max_amount']) ? $grant_data['max_amount'] : '',
            '[募集終了日]' => isset($grant_data['deadline_text']) ? $grant_data['deadline_text'] : '',
            '[実施組織]' => isset($grant_data['organization']) ? $grant_data['organization'] : '',
            '[公式URL]' => isset($grant_data['official_url']) ? $grant_data['official_url'] : '',
            '[補助率]' => isset($grant_data['subsidy_rate']) ? $grant_data['subsidy_rate'] : '',
            '[利用目的]' => isset($grant_data['use_purpose']) ? $grant_data['use_purpose'] : '',
            '[対象地域]' => isset($grant_data['target_area_search']) ? $grant_data['target_area_search'] : ''
        );
        
        return str_replace(array_keys($variables), array_values($variables), $template);
    }
    
    /**
     * フォールバック コンテンツの生成
     */
    private function get_fallback_content($prompt) {
        $this->logger->log('AI生成フォールバック処理を実行', 'warning');
        
        // 簡単なテンプレートベースのフォールバック
        if (strpos($prompt, '本文') !== false || strpos($prompt, 'content') !== false) {
            return "この助成金について詳細な情報は公式サイトをご確認ください。申請をご検討の方は、募集要項をよくお読みになり、期限までにお申し込みください。";
        }
        
        if (strpos($prompt, '要約') !== false || strpos($prompt, 'summary') !== false) {
            return "詳細は公式サイトをご確認ください。";
        }
        
        return "情報については公式サイトをご確認ください。";
    }
    
    /**
     * API接続テスト
     */
    public function test_connection() {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return false;
        }
        
        $test_prompt = "こんにちは";
        $result = $this->generate_text($test_prompt, array('max_tokens' => 50));
        
        if (is_wp_error($result)) {
            $this->logger->log('AI API接続テスト失敗: ' . $result->get_error_message(), 'error');
            return false;
        }
        
        $this->logger->log('AI API接続テスト成功');
        return true;
    }
}