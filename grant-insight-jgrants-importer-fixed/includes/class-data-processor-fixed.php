<?php
/**
 * Fixed Data Processor for Grant Insight JGrants Importer
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
 * Fixed Data Processor class with proper error handling and singleton pattern
 */
class GIJI_Fixed_Data_Processor extends GIJI_Singleton_Base {
    
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
     * Default post status for processed grants
     */
    private const DEFAULT_POST_STATUS = 'draft';
    
    /**
     * Maximum content length for processing
     */
    private const MAX_CONTENT_LENGTH = 50000;
    
    /**
     * Initialize the data processor
     */
    protected function init() {
        $this->logger = GIJI_Fixed_Logger::get_instance();
        $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
        
        $this->logger->log('Data Processor initialized', 'info');
    }
    
    /**
     * Process grant data and create WordPress post
     *
     * @param array $grant_data Grant data from API
     * @param array $options Processing options
     * @return array|WP_Error Processing result or error
     */
    public function process_grant_data($grant_data, $options = array()) {
        try {
            // Validate input data
            $validation_result = $this->validate_grant_data($grant_data);
            if (is_wp_error($validation_result)) {
                return $validation_result;
            }
            
            // Sanitize and prepare data
            $processed_data = $this->sanitize_grant_data($grant_data);
            if (is_wp_error($processed_data)) {
                return $processed_data;
            }
            
            // Check for existing post
            $existing_post = $this->find_existing_post($processed_data);
            
            if ($existing_post && empty($options['force_update'])) {
                $this->logger->log('Grant already exists: ' . $existing_post->ID, 'info');
                return array(
                    'success' => true,
                    'action' => 'skipped',
                    'post_id' => $existing_post->ID,
                    'message' => 'Grant already exists'
                );
            }
            
            // Create or update post
            if ($existing_post && !empty($options['force_update'])) {
                $result = $this->update_existing_post($existing_post->ID, $processed_data, $options);
            } else {
                $result = $this->create_new_post($processed_data, $options);
            }
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            // Process post metadata
            $meta_result = $this->process_post_metadata($result['post_id'], $grant_data, $options);
            if (is_wp_error($meta_result)) {
                $this->logger->log('Metadata processing failed: ' . $meta_result->get_error_message(), 'warning');
            }
            
            // Process taxonomies
            $taxonomy_result = $this->process_taxonomies($result['post_id'], $processed_data, $options);
            if (is_wp_error($taxonomy_result)) {
                $this->logger->log('Taxonomy processing failed: ' . $taxonomy_result->get_error_message(), 'warning');
            }
            
            $this->logger->log('Grant processed successfully: Post ID ' . $result['post_id'], 'info');
            
            return array(
                'success' => true,
                'action' => $result['action'],
                'post_id' => $result['post_id'],
                'post_url' => get_permalink($result['post_id']),
                'timestamp' => current_time('mysql')
            );
            
        } catch (Exception $e) {
            $error_message = 'Data processing exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('data_processing_exception', $error_message);
        }
    }
    
    /**
     * Process multiple grant data entries
     *
     * @param array $grants_data Array of grant data
     * @param array $options Processing options
     * @return array Processing results
     */
    public function process_multiple_grants($grants_data, $options = array()) {
        $results = array(
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => array()
        );
        
        if (!is_array($grants_data) || empty($grants_data)) {
            return new WP_Error('invalid_grants_data', 'Invalid or empty grants data provided');
        }
        
        foreach ($grants_data as $index => $grant_data) {
            $result = $this->process_grant_data($grant_data, $options);
            
            if (is_wp_error($result)) {
                $results['errors']++;
                $results['details'][$index] = array(
                    'success' => false,
                    'error' => $result->get_error_message()
                );
            } else {
                if ($result['action'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['processed']++;
                }
                $results['details'][$index] = $result;
            }
            
            // Prevent memory issues with large datasets
            if ($index > 0 && $index % 10 === 0) {
                wp_cache_flush();
            }
        }
        
        $this->logger->log(sprintf(
            'Bulk processing completed: %d processed, %d errors, %d skipped',
            $results['processed'],
            $results['errors'],
            $results['skipped']
        ), 'info');
        
        return $results;
    }
    
    /**
     * Validate grant data structure
     *
     * @param array $grant_data Grant data to validate
     * @return true|WP_Error True if valid, WP_Error if invalid
     */
    private function validate_grant_data($grant_data) {
        if (!is_array($grant_data)) {
            return new WP_Error('invalid_data_type', 'Grant data must be an array');
        }
        
        // Required fields
        $required_fields = array('title', 'content');
        foreach ($required_fields as $field) {
            if (empty($grant_data[$field])) {
                return new WP_Error('missing_required_field', 'Required field missing: ' . $field);
            }
        }
        
        // Validate content length
        if (strlen($grant_data['content']) > self::MAX_CONTENT_LENGTH) {
            return new WP_Error('content_too_long', 'Content exceeds maximum length limit');
        }
        
        return true;
    }
    
    /**
     * Sanitize grant data for WordPress
     *
     * @param array $grant_data Raw grant data
     * @return array|WP_Error Sanitized data or error
     */
    private function sanitize_grant_data($grant_data) {
        try {
            $sanitized = array();
            
            // Sanitize title
            $sanitized['title'] = sanitize_text_field($grant_data['title']);
            if (empty($sanitized['title'])) {
                return new WP_Error('empty_title', 'Title cannot be empty after sanitization');
            }
            
            // Sanitize content
            $sanitized['content'] = wp_kses_post($grant_data['content']);
            if (empty($sanitized['content'])) {
                return new WP_Error('empty_content', 'Content cannot be empty after sanitization');
            }
            
            // Sanitize optional fields
            $optional_fields = array(
                'excerpt' => 'wp_kses_post',
                'application_period' => 'sanitize_text_field',
                'amount' => 'sanitize_text_field',
                'eligibility' => 'wp_kses_post',
                'url' => 'esc_url_raw',
                'category' => 'sanitize_text_field',
                'tags' => array($this, 'sanitize_tags'),
                'meta_description' => 'sanitize_text_field'
            );
            
            foreach ($optional_fields as $field => $sanitizer) {
                if (!empty($grant_data[$field])) {
                    if (is_callable($sanitizer)) {
                        $sanitized[$field] = call_user_func($sanitizer, $grant_data[$field]);
                    } else {
                        $sanitized[$field] = $grant_data[$field];
                    }
                }
            }
            
            return $sanitized;
            
        } catch (Exception $e) {
            return new WP_Error('sanitization_error', 'Error during data sanitization: ' . $e->getMessage());
        }
    }
    
    /**
     * Sanitize tags array
     *
     * @param array|string $tags Tags to sanitize
     * @return array Sanitized tags
     */
    private function sanitize_tags($tags) {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        
        if (!is_array($tags)) {
            return array();
        }
        
        return array_filter(array_map('sanitize_text_field', $tags));
    }
    
    /**
     * Find existing post by title or meta data
     *
     * @param array $processed_data Processed grant data
     * @return WP_Post|null Existing post or null
     */
    private function find_existing_post($processed_data) {
        // Search by title first
        $existing_posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'pending'),
            'title' => $processed_data['title'],
            'numberposts' => 1,
            'suppress_filters' => false
        ));
        
        if (!empty($existing_posts)) {
            return $existing_posts[0];
        }
        
        // Search by URL if available
        if (!empty($processed_data['url'])) {
            $posts_by_url = get_posts(array(
                'post_type' => 'post',
                'meta_query' => array(
                    array(
                        'key' => 'grant_url',
                        'value' => $processed_data['url'],
                        'compare' => '='
                    )
                ),
                'numberposts' => 1
            ));
            
            if (!empty($posts_by_url)) {
                return $posts_by_url[0];
            }
        }
        
        return null;
    }
    
    /**
     * Create new WordPress post
     *
     * @param array $processed_data Processed grant data
     * @param array $options Processing options
     * @return array|WP_Error Post creation result or error
     */
    private function create_new_post($processed_data, $options) {
        $post_data = array(
            'post_title' => $processed_data['title'],
            'post_content' => $processed_data['content'],
            'post_status' => $options['post_status'] ?? self::DEFAULT_POST_STATUS,
            'post_type' => 'post',
            'post_author' => get_current_user_id() ?: 1,
            'meta_input' => array(
                'grant_imported_at' => current_time('mysql'),
                'grant_importer_version' => '2.0.0'
            )
        );
        
        // Add excerpt if available
        if (!empty($processed_data['excerpt'])) {
            $post_data['post_excerpt'] = $processed_data['excerpt'];
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        return array(
            'post_id' => $post_id,
            'action' => 'created'
        );
    }
    
    /**
     * Update existing WordPress post
     *
     * @param int $post_id Post ID to update
     * @param array $processed_data Processed grant data
     * @param array $options Processing options
     * @return array|WP_Error Update result or error
     */
    private function update_existing_post($post_id, $processed_data, $options) {
        $post_data = array(
            'ID' => $post_id,
            'post_title' => $processed_data['title'],
            'post_content' => $processed_data['content'],
            'post_modified' => current_time('mysql')
        );
        
        // Add excerpt if available
        if (!empty($processed_data['excerpt'])) {
            $post_data['post_excerpt'] = $processed_data['excerpt'];
        }
        
        $result = wp_update_post($post_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Update metadata
        update_post_meta($post_id, 'grant_updated_at', current_time('mysql'));
        
        return array(
            'post_id' => $post_id,
            'action' => 'updated'
        );
    }
    
    /**
     * Process post metadata
     *
     * @param int $post_id Post ID
     * @param array $grant_data Original grant data
     * @param array $options Processing options
     * @return true|WP_Error Processing result or error
     */
    private function process_post_metadata($post_id, $grant_data, $options) {
        try {
            $meta_fields = array(
                'grant_url' => 'url',
                'grant_application_period' => 'application_period',
                'grant_amount' => 'amount',
                'grant_eligibility' => 'eligibility',
                'grant_category' => 'category'
            );
            
            foreach ($meta_fields as $meta_key => $data_key) {
                if (!empty($grant_data[$data_key])) {
                    update_post_meta($post_id, $meta_key, $grant_data[$data_key]);
                }
            }
            
            // Set SEO meta if available
            if (!empty($grant_data['meta_description'])) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $grant_data['meta_description']);
            }
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('metadata_processing_error', 'Error processing metadata: ' . $e->getMessage());
        }
    }
    
    /**
     * Process taxonomies (categories and tags)
     *
     * @param int $post_id Post ID
     * @param array $processed_data Processed grant data
     * @param array $options Processing options
     * @return true|WP_Error Processing result or error
     */
    private function process_taxonomies($post_id, $processed_data, $options) {
        try {
            // Process category
            if (!empty($processed_data['category'])) {
                $category_id = $this->ensure_category_exists($processed_data['category']);
                if (!is_wp_error($category_id)) {
                    wp_set_post_categories($post_id, array($category_id));
                }
            }
            
            // Process tags
            if (!empty($processed_data['tags']) && is_array($processed_data['tags'])) {
                wp_set_post_tags($post_id, $processed_data['tags']);
            }
            
            return true;
            
        } catch (Exception $e) {
            return new WP_Error('taxonomy_processing_error', 'Error processing taxonomies: ' . $e->getMessage());
        }
    }
    
    /**
     * Ensure category exists, create if not
     *
     * @param string $category_name Category name
     * @return int|WP_Error Category ID or error
     */
    private function ensure_category_exists($category_name) {
        $category = get_category_by_slug(sanitize_title($category_name));
        
        if ($category) {
            return $category->term_id;
        }
        
        $result = wp_insert_category(array(
            'cat_name' => $category_name,
            'category_nicename' => sanitize_title($category_name)
        ));
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Get processing statistics
     *
     * @return array Processing statistics
     */
    public function get_processing_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Count imported posts
        $stats['total_imported'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'grant_imported_at'"
        );
        
        // Count recent imports (last 24 hours)
        $stats['recent_imported'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                 WHERE meta_key = 'grant_imported_at' 
                 AND meta_value >= %s",
                date('Y-m-d H:i:s', strtotime('-24 hours'))
            )
        );
        
        return $stats;
    }
}