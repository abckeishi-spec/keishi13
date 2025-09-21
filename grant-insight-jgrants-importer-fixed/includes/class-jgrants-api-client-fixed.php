<?php
/**
 * Fixed JGrants API Client for Grant Insight JGrants Importer
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
 * Fixed JGrants API Client class with proper error handling and singleton pattern
 */
class GIJI_Fixed_JGrants_API_Client extends GIJI_Singleton_Base {
    
    /**
     * JGrants API base URL
     */
    private const API_BASE_URL = 'https://www.jgrants-portal.go.jp/';
    
    /**
     * API endpoints
     */
    private const ENDPOINTS = array(
        'search' => 'api/public/search',
        'detail' => 'api/public/detail',
        'categories' => 'api/public/categories'
    );
    
    /**
     * Request timeout in seconds
     */
    private const REQUEST_TIMEOUT = 30;
    
    /**
     * Maximum retry attempts
     */
    private const MAX_RETRIES = 3;
    
    /**
     * Logger instance
     *
     * @var GIJI_Fixed_Logger
     */
    private $logger;
    
    /**
     * Security manager instance
     *
     * @var GIJI_Fixed_Security_Manager
     */
    private $security_manager;
    
    /**
     * Rate limiting cache
     *
     * @var array
     */
    private $rate_limit_cache = array();
    
    /**
     * Initialize the API client
     */
    protected function init() {
        $this->logger = GIJI_Fixed_Logger::get_instance();
        $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
        
        $this->logger->log('JGrants API Client initialized', 'info');
    }
    
    /**
     * Fetch grants from JGrants API
     *
     * @param array $params Search parameters
     * @return array|WP_Error API response or error
     */
    public function fetch_grants($params = array()) {
        try {
            // Validate parameters
            $validation_result = $this->validate_search_params($params);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }
            
            // Check rate limiting
            $rate_check = $this->check_rate_limit('search');
            if (is_wp_error($rate_check)) {
                return $rate_check;
            }
            
            // Prepare search parameters
            $search_params = $this->prepare_search_params($params);
            
            // Make API request
            $response = $this->make_api_request('search', 'GET', $search_params);
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Process response
            $processed_response = $this->process_grants_response($response);
            
            if (is_wp_error($processed_response)) {
                return $processed_response;
            }
            
            $this->logger->log('Successfully fetched grants: ' . count($processed_response['grants']), 'info');
            
            return $processed_response;
            
        } catch (Exception $e) {
            $error_message = 'Grants fetch exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('fetch_grants_exception', $error_message);
        }
    }
    
    /**
     * Fetch grant details by ID
     *
     * @param string $grant_id Grant ID
     * @return array|WP_Error Grant details or error
     */
    public function fetch_grant_details($grant_id) {
        try {
            // Validate grant ID
            if (empty($grant_id) || !is_string($grant_id)) {
                return new WP_Error('invalid_grant_id', 'Invalid grant ID provided');
            }
            
            // Check rate limiting
            $rate_check = $this->check_rate_limit('detail');
            if (is_wp_error($rate_check)) {
                return $rate_check;
            }
            
            // Make API request
            $response = $this->make_api_request('detail', 'GET', array('id' => $grant_id));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Process response
            $processed_response = $this->process_detail_response($response);
            
            if (is_wp_error($processed_response)) {
                return $processed_response;
            }
            
            $this->logger->log('Successfully fetched grant details: ' . $grant_id, 'info');
            
            return $processed_response;
            
        } catch (Exception $e) {
            $error_message = 'Grant details fetch exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('fetch_details_exception', $error_message);
        }
    }
    
    /**
     * Fetch available categories
     *
     * @return array|WP_Error Categories or error
     */
    public function fetch_categories() {
        try {
            // Check cache first
            $cached_categories = get_transient('giji_fixed_api_categories');
            if ($cached_categories !== false) {
                return $cached_categories;
            }
            
            // Check rate limiting
            $rate_check = $this->check_rate_limit('categories');
            if (is_wp_error($rate_check)) {
                return $rate_check;
            }
            
            // Make API request
            $response = $this->make_api_request('categories', 'GET');
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            // Process response
            $processed_response = $this->process_categories_response($response);
            
            if (is_wp_error($processed_response)) {
                return $processed_response;
            }
            
            // Cache for 1 hour
            set_transient('giji_fixed_api_categories', $processed_response, HOUR_IN_SECONDS);
            
            $this->logger->log('Successfully fetched categories', 'info');
            
            return $processed_response;
            
        } catch (Exception $e) {
            $error_message = 'Categories fetch exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('fetch_categories_exception', $error_message);
        }
    }
    
    /**
     * Validate search parameters
     *
     * @param array $params Search parameters
     * @return true|WP_Error True if valid, WP_Error if invalid
     */
    private function validate_search_params($params) {
        // Validate limit
        if (isset($params['limit'])) {
            $limit = intval($params['limit']);
            if ($limit < 1 || $limit > 100) {
                return new WP_Error('invalid_limit', 'Limit must be between 1 and 100');
            }
        }
        
        // Validate offset
        if (isset($params['offset'])) {
            $offset = intval($params['offset']);
            if ($offset < 0) {
                return new WP_Error('invalid_offset', 'Offset must be non-negative');
            }
        }
        
        return true;
    }
    
    /**
     * Prepare search parameters for API request
     *
     * @param array $params Raw parameters
     * @return array Prepared parameters
     */
    private function prepare_search_params($params) {
        $default_params = array(
            'limit' => 10,
            'offset' => 0,
            'sort' => 'updated_desc'
        );
        
        $prepared_params = wp_parse_args($params, $default_params);
        
        // Sanitize parameters
        $prepared_params['limit'] = max(1, min(100, intval($prepared_params['limit'])));
        $prepared_params['offset'] = max(0, intval($prepared_params['offset']));
        
        // Add optional filters
        if (!empty($params['keyword'])) {
            $prepared_params['keyword'] = sanitize_text_field($params['keyword']);
        }
        
        if (!empty($params['category'])) {
            $prepared_params['category'] = sanitize_text_field($params['category']);
        }
        
        if (!empty($params['status'])) {
            $prepared_params['status'] = sanitize_text_field($params['status']);
        }
        
        return $prepared_params;
    }
    
    /**
     * Make API request with retry logic
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $params Request parameters
     * @return array|WP_Error API response or error
     */
    private function make_api_request($endpoint, $method = 'GET', $params = array()) {
        if (!isset(self::ENDPOINTS[$endpoint])) {
            return new WP_Error('invalid_endpoint', 'Invalid API endpoint');
        }
        
        $url = self::API_BASE_URL . self::ENDPOINTS[$endpoint];
        
        // Prepare request arguments
        $args = array(
            'method' => $method,
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => array(
                'User-Agent' => 'Grant-Insight-JGrants-Importer-Fixed/2.0.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            )
        );
        
        // Add parameters based on method
        if ($method === 'GET' && !empty($params)) {
            $url = add_query_arg($params, $url);
        } elseif ($method === 'POST' && !empty($params)) {
            $args['body'] = wp_json_encode($params);
        }
        
        // Retry logic
        $attempt = 0;
        $last_error = null;
        
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            
            $response = wp_remote_request($url, $args);
            
            if (is_wp_error($response)) {
                $last_error = $response;
                $this->logger->log('API request attempt ' . $attempt . ' failed: ' . $response->get_error_message(), 'warning');
                
                if ($attempt < self::MAX_RETRIES) {
                    sleep(1); // Wait 1 second before retry
                }
                continue;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            
            // Handle different response codes
            if ($response_code === 200) {
                $decoded_response = json_decode($response_body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $last_error = new WP_Error('json_decode_error', 'Failed to decode API response');
                    if ($attempt < self::MAX_RETRIES) {
                        sleep(1);
                    }
                    continue;
                }
                
                // Update rate limit tracking
                $this->update_rate_limit($endpoint);
                
                return $decoded_response;
                
            } elseif ($response_code === 429) {
                // Rate limit hit
                $last_error = new WP_Error('rate_limit_exceeded', 'API rate limit exceeded');
                if ($attempt < self::MAX_RETRIES) {
                    sleep(5); // Wait longer for rate limit
                }
                continue;
                
            } elseif ($response_code >= 500) {
                // Server error - retry
                $last_error = new WP_Error('server_error', 'API server error: ' . $response_code);
                if ($attempt < self::MAX_RETRIES) {
                    sleep(2);
                }
                continue;
                
            } else {
                // Client error - don't retry
                return new WP_Error('api_client_error', 'API client error: ' . $response_code . ' - ' . $response_body);
            }
        }
        
        // All retries failed
        return $last_error ?: new WP_Error('api_request_failed', 'API request failed after ' . self::MAX_RETRIES . ' attempts');
    }
    
    /**
     * Process grants search response
     *
     * @param array $response API response
     * @return array|WP_Error Processed response or error
     */
    private function process_grants_response($response) {
        if (!is_array($response)) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        // Check for API errors
        if (isset($response['error'])) {
            return new WP_Error('api_error', 'API returned error: ' . $response['error']);
        }
        
        // Extract grants data
        $grants = isset($response['data']) ? $response['data'] : array();
        
        if (!is_array($grants)) {
            return new WP_Error('invalid_grants_data', 'Invalid grants data in response');
        }
        
        // Process each grant
        $processed_grants = array();
        foreach ($grants as $grant) {
            $processed_grant = $this->process_single_grant($grant);
            if (!is_wp_error($processed_grant)) {
                $processed_grants[] = $processed_grant;
            }
        }
        
        return array(
            'grants' => $processed_grants,
            'total' => isset($response['total']) ? intval($response['total']) : count($processed_grants),
            'page_info' => array(
                'current_page' => isset($response['current_page']) ? intval($response['current_page']) : 1,
                'per_page' => isset($response['per_page']) ? intval($response['per_page']) : 10
            )
        );
    }
    
    /**
     * Process single grant data
     *
     * @param array $grant_data Raw grant data
     * @return array|WP_Error Processed grant data or error
     */
    private function process_single_grant($grant_data) {
        if (!is_array($grant_data)) {
            return new WP_Error('invalid_grant_data', 'Invalid grant data format');
        }
        
        // Map API fields to our structure
        $processed = array(
            'id' => $grant_data['id'] ?? '',
            'title' => $grant_data['title'] ?? '',
            'content' => $grant_data['description'] ?? $grant_data['content'] ?? '',
            'excerpt' => $grant_data['summary'] ?? '',
            'url' => $grant_data['url'] ?? $grant_data['link'] ?? '',
            'category' => $grant_data['category'] ?? '',
            'tags' => isset($grant_data['tags']) ? (is_array($grant_data['tags']) ? $grant_data['tags'] : explode(',', $grant_data['tags'])) : array(),
            'application_period' => $grant_data['application_period'] ?? $grant_data['deadline'] ?? '',
            'amount' => $grant_data['amount'] ?? $grant_data['funding_amount'] ?? '',
            'eligibility' => $grant_data['eligibility'] ?? $grant_data['target'] ?? '',
            'updated_at' => $grant_data['updated_at'] ?? $grant_data['last_modified'] ?? '',
            'published_at' => $grant_data['published_at'] ?? $grant_data['created_at'] ?? ''
        );
        
        // Validate required fields
        if (empty($processed['title']) || empty($processed['content'])) {
            return new WP_Error('missing_required_data', 'Grant missing required title or content');
        }
        
        return $processed;
    }
    
    /**
     * Process grant details response
     *
     * @param array $response API response
     * @return array|WP_Error Processed response or error
     */
    private function process_detail_response($response) {
        if (!is_array($response)) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        if (isset($response['error'])) {
            return new WP_Error('api_error', 'API returned error: ' . $response['error']);
        }
        
        $grant_data = isset($response['data']) ? $response['data'] : $response;
        
        return $this->process_single_grant($grant_data);
    }
    
    /**
     * Process categories response
     *
     * @param array $response API response
     * @return array|WP_Error Processed response or error
     */
    private function process_categories_response($response) {
        if (!is_array($response)) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        if (isset($response['error'])) {
            return new WP_Error('api_error', 'API returned error: ' . $response['error']);
        }
        
        $categories = isset($response['data']) ? $response['data'] : $response;
        
        if (!is_array($categories)) {
            return new WP_Error('invalid_categories_data', 'Invalid categories data in response');
        }
        
        return array(
            'categories' => $categories,
            'total' => count($categories)
        );
    }
    
    /**
     * Check rate limit for endpoint
     *
     * @param string $endpoint Endpoint name
     * @return true|WP_Error True if OK, WP_Error if rate limited
     */
    private function check_rate_limit($endpoint) {
        $cache_key = 'rate_limit_' . $endpoint;
        $current_time = time();
        
        if (!isset($this->rate_limit_cache[$cache_key])) {
            $this->rate_limit_cache[$cache_key] = array(
                'requests' => 0,
                'window_start' => $current_time
            );
        }
        
        $cache_data = &$this->rate_limit_cache[$cache_key];
        
        // Reset window if needed (1 minute windows)
        if ($current_time - $cache_data['window_start'] >= 60) {
            $cache_data['requests'] = 0;
            $cache_data['window_start'] = $current_time;
        }
        
        // Check limits (max 30 requests per minute per endpoint)
        if ($cache_data['requests'] >= 30) {
            return new WP_Error('rate_limit_exceeded', 'Rate limit exceeded for endpoint: ' . $endpoint);
        }
        
        return true;
    }
    
    /**
     * Update rate limit tracking
     *
     * @param string $endpoint Endpoint name
     */
    private function update_rate_limit($endpoint) {
        $cache_key = 'rate_limit_' . $endpoint;
        
        if (isset($this->rate_limit_cache[$cache_key])) {
            $this->rate_limit_cache[$cache_key]['requests']++;
        }
    }
    
    /**
     * Test API connection
     *
     * @return array|WP_Error Test result or error
     */
    public function test_connection() {
        try {
            $result = $this->fetch_categories();
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            $this->logger->log('API connection test successful', 'info');
            
            return array(
                'success' => true,
                'message' => 'JGrants API connection successful',
                'categories_count' => count($result['categories'] ?? array()),
                'timestamp' => current_time('mysql')
            );
            
        } catch (Exception $e) {
            $error_message = 'API connection test exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('connection_test_exception', $error_message);
        }
    }
}