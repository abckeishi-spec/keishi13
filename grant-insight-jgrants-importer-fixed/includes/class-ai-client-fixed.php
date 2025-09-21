<?php
/**
 * Fixed AI Client for Grant Insight JGrants Importer
 * 
 * @package Grant_Insight_JGrants_Importer_Fixed
 * @version 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the singleton base class
require_once plugin_dir_path(__FILE__) . 'class-singleton-base.php';

/**
 * Fixed AI Client class with proper error handling and singleton pattern
 */
class GIJI_Fixed_AI_Client extends GIJI_Singleton_Base {
    
    /**
     * OpenAI API endpoint
     */
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    
    /**
     * Security manager instance
     *
     * @var GIJI_Fixed_Security_Manager
     */
    private $security_manager;
    
    /**
     * Logger instance
     *
     * @var GIJI_Fixed_Logger
     */
    private $logger;
    
    /**
     * Initialize the AI client
     */
    protected function init() {
        $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
        $this->logger = GIJI_Fixed_Logger::get_instance();
        
        $this->logger->log('AI Client initialized', 'info');
    }
    
    /**
     * Analyze grant content using AI
     *
     * @param string $content Grant content to analyze
     * @param array $options Analysis options
     * @return array|WP_Error Analysis result or error
     */
    public function analyze_grant_content($content, $options = array()) {
        try {
            // Validate content
            if (empty($content) || !is_string($content)) {
                return new WP_Error('invalid_content', 'Invalid content provided for AI analysis');
            }
            
            // Get API key
            $api_key = $this->security_manager->get_decrypted_api_key('openai_api_key');
            if (is_wp_error($api_key)) {
                return $api_key;
            }
            
            if (empty($api_key)) {
                return new WP_Error('no_api_key', 'OpenAI API key not configured');
            }
            
            // Prepare analysis prompt
            $prompt = $this->build_analysis_prompt($content, $options);
            
            // Make API request
            $response = $this->make_openai_request($api_key, $prompt);
            
            if (is_wp_error($response)) {
                $this->logger->log('AI analysis failed: ' . $response->get_error_message(), 'error');
                return $response;
            }
            
            // Parse and validate response
            $analysis = $this->parse_ai_response($response);
            
            if (is_wp_error($analysis)) {
                return $analysis;
            }
            
            $this->logger->log('AI analysis completed successfully', 'info');
            
            return array(
                'success' => true,
                'analysis' => $analysis,
                'timestamp' => current_time('mysql'),
                'content_length' => strlen($content)
            );
            
        } catch (Exception $e) {
            $error_message = 'AI analysis exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('ai_analysis_exception', $error_message);
        }
    }
    
    /**
     * Generate content suggestions using AI
     *
     * @param array $grant_data Grant data
     * @return array|WP_Error Content suggestions or error
     */
    public function generate_content_suggestions($grant_data) {
        try {
            if (empty($grant_data) || !is_array($grant_data)) {
                return new WP_Error('invalid_grant_data', 'Invalid grant data provided');
            }
            
            // Get API key
            $api_key = $this->security_manager->get_decrypted_api_key('openai_api_key');
            if (is_wp_error($api_key) || empty($api_key)) {
                return new WP_Error('no_api_key', 'OpenAI API key not configured');
            }
            
            // Build suggestion prompt
            $prompt = $this->build_suggestion_prompt($grant_data);
            
            // Make API request
            $response = $this->make_openai_request($api_key, $prompt);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Parse suggestions
            $suggestions = $this->parse_suggestions_response($response);
            
            if (is_wp_error($suggestions)) {
                return $suggestions;
            }
            
            $this->logger->log('Content suggestions generated successfully', 'info');
            
            return array(
                'success' => true,
                'suggestions' => $suggestions,
                'timestamp' => current_time('mysql')
            );
            
        } catch (Exception $e) {
            $error_message = 'Content suggestion exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('content_suggestion_exception', $error_message);
        }
    }
    
    /**
     * Build analysis prompt for AI
     *
     * @param string $content Content to analyze
     * @param array $options Analysis options
     * @return string Formatted prompt
     */
    private function build_analysis_prompt($content, $options) {
        $default_options = array(
            'language' => 'japanese',
            'focus' => 'general',
            'detail_level' => 'medium'
        );
        
        $options = wp_parse_args($options, $default_options);
        
        $prompt = "以下の助成金情報を分析してください:\n\n";
        $prompt .= "【分析対象】\n" . $content . "\n\n";
        $prompt .= "【分析項目】\n";
        $prompt .= "1. 主要なキーワードの抽出\n";
        $prompt .= "2. 対象分野・業界の特定\n";
        $prompt .= "3. 申請条件の要約\n";
        $prompt .= "4. 重要度・優先度の評価\n";
        $prompt .= "5. 注意すべき点の指摘\n\n";
        $prompt .= "JSON形式で回答してください。";
        
        return $prompt;
    }
    
    /**
     * Build suggestion prompt for AI
     *
     * @param array $grant_data Grant data
     * @return string Formatted prompt
     */
    private function build_suggestion_prompt($grant_data) {
        $prompt = "以下の助成金データに基づいて、効果的なコンテンツ作成の提案をしてください:\n\n";
        
        if (!empty($grant_data['title'])) {
            $prompt .= "【タイトル】\n" . $grant_data['title'] . "\n\n";
        }
        
        if (!empty($grant_data['content'])) {
            $prompt .= "【内容】\n" . $grant_data['content'] . "\n\n";
        }
        
        $prompt .= "【提案項目】\n";
        $prompt .= "1. SEO最適化されたタイトル案（3つ）\n";
        $prompt .= "2. メタディスクリプション案\n";
        $prompt .= "3. 関連タグの提案\n";
        $prompt .= "4. コンテンツ改善提案\n";
        $prompt .= "5. ターゲット読者の特定\n\n";
        $prompt .= "JSON形式で回答してください。";
        
        return $prompt;
    }
    
    /**
     * Make OpenAI API request
     *
     * @param string $api_key API key
     * @param string $prompt Analysis prompt
     * @return array|WP_Error API response or error
     */
    private function make_openai_request($api_key, $prompt) {
        $headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        );
        
        $body = array(
            'model' => 'gpt-3.5-turbo',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'max_tokens' => 2000,
            'temperature' => 0.7
        );
        
        $args = array(
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'timeout' => 30,
            'method' => 'POST'
        );
        
        $response = wp_remote_request(self::OPENAI_API_URL, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'OpenAI API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', 'OpenAI API error: HTTP ' . $response_code . ' - ' . $response_body);
        }
        
        $decoded_response = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_decode_error', 'Failed to decode OpenAI API response');
        }
        
        return $decoded_response;
    }
    
    /**
     * Parse AI analysis response
     *
     * @param array $response OpenAI API response
     * @return array|WP_Error Parsed analysis or error
     */
    private function parse_ai_response($response) {
        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Invalid OpenAI API response structure');
        }
        
        $content = trim($response['choices'][0]['message']['content']);
        
        // Try to parse JSON response
        $analysis = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // If not JSON, return as plain text
            return array(
                'raw_content' => $content,
                'parsed' => false
            );
        }
        
        return array(
            'analysis_data' => $analysis,
            'raw_content' => $content,
            'parsed' => true
        );
    }
    
    /**
     * Parse content suggestions response
     *
     * @param array $response OpenAI API response
     * @return array|WP_Error Parsed suggestions or error
     */
    private function parse_suggestions_response($response) {
        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', 'Invalid OpenAI API response structure');
        }
        
        $content = trim($response['choices'][0]['message']['content']);
        
        // Try to parse JSON response
        $suggestions = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_parse_error', 'Failed to parse AI suggestions response');
        }
        
        return $suggestions;
    }
    
    /**
     * Test AI client connection
     *
     * @return array|WP_Error Test result or error
     */
    public function test_connection() {
        try {
            $api_key = $this->security_manager->get_decrypted_api_key('openai_api_key');
            
            if (is_wp_error($api_key) || empty($api_key)) {
                return new WP_Error('no_api_key', 'OpenAI API key not configured');
            }
            
            // Simple test prompt
            $test_prompt = "こんにちは。この接続テストに「成功」と返答してください。";
            
            $response = $this->make_openai_request($api_key, $test_prompt);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $this->logger->log('AI client connection test successful', 'info');
            
            return array(
                'success' => true,
                'message' => 'OpenAI API connection successful',
                'timestamp' => current_time('mysql')
            );
            
        } catch (Exception $e) {
            $error_message = 'AI connection test exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('connection_test_exception', $error_message);
        }
    }
}