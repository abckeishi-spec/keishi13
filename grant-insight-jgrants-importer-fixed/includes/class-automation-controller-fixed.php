<?php
/**
 * Fixed Automation Controller for Grant Insight JGrants Importer
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
 * Fixed Automation Controller class with proper error handling and singleton pattern
 */
class GIJI_Fixed_Automation_Controller extends GIJI_Singleton_Base {
    
    /**
     * Cron hook name for automated import
     */
    private const CRON_HOOK = 'giji_fixed_auto_import';
    
    /**
     * Transient key for import lock
     */
    private const IMPORT_LOCK_KEY = 'giji_fixed_import_running';
    
    /**
     * Maximum import execution time (in seconds)
     */
    private const MAX_EXECUTION_TIME = 300; // 5 minutes
    
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
     * JGrants API client instance
     *
     * @var GIJI_Fixed_JGrants_API_Client
     */
    private $api_client;
    
    /**
     * Data processor instance
     *
     * @var GIJI_Fixed_Data_Processor
     */
    private $data_processor;
    
    /**
     * Initialize the automation controller
     */
    protected function init() {
        $this->logger = GIJI_Fixed_Logger::get_instance();
        $this->security_manager = GIJI_Fixed_Security_Manager::get_instance();
        
        // Initialize components lazily to avoid circular dependencies
        add_action('init', array($this, 'init_components'), 20);
        
        // Register cron hook
        add_action(self::CRON_HOOK, array($this, 'execute_automated_import'));
        
        // Add custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        $this->logger->log('Automation Controller initialized', 'info');
    }
    
    /**
     * Initialize components after WordPress is fully loaded
     */
    public function init_components() {
        if (class_exists('GIJI_Fixed_JGrants_API_Client')) {
            $this->api_client = GIJI_Fixed_JGrants_API_Client::get_instance();
        }
        
        if (class_exists('GIJI_Fixed_Data_Processor')) {
            $this->data_processor = GIJI_Fixed_Data_Processor::get_instance();
        }
    }
    
    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_custom_cron_schedules($schedules) {
        // Add hourly schedule
        if (!isset($schedules['giji_hourly'])) {
            $schedules['giji_hourly'] = array(
                'interval' => HOUR_IN_SECONDS,
                'display' => __('Every Hour (GIJI)', 'grant-insight-jgrants-importer-fixed')
            );
        }
        
        // Add 6-hour schedule
        if (!isset($schedules['giji_6hours'])) {
            $schedules['giji_6hours'] = array(
                'interval' => 6 * HOUR_IN_SECONDS,
                'display' => __('Every 6 Hours (GIJI)', 'grant-insight-jgrants-importer-fixed')
            );
        }
        
        // Add 12-hour schedule
        if (!isset($schedules['giji_12hours'])) {
            $schedules['giji_12hours'] = array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display' => __('Every 12 Hours (GIJI)', 'grant-insight-jgrants-importer-fixed')
            );
        }
        
        return $schedules;
    }
    
    /**
     * Schedule automated import
     *
     * @param string $frequency Cron frequency
     * @return array|WP_Error Scheduling result or error
     */
    public function schedule_automated_import($frequency = 'daily') {
        try {
            // Validate frequency
            $valid_frequencies = array('hourly', 'giji_6hours', 'giji_12hours', 'daily');
            if (!in_array($frequency, $valid_frequencies)) {
                return new WP_Error('invalid_frequency', 'Invalid cron frequency specified');
            }
            
            // Clear existing schedule
            $this->unschedule_automated_import();
            
            // Schedule new event
            $scheduled = wp_schedule_event(time(), $frequency, self::CRON_HOOK);
            
            if ($scheduled === false) {
                return new WP_Error('schedule_failed', 'Failed to schedule automated import');
            }
            
            // Save the frequency for reference
            update_option('giji_fixed_auto_import_frequency', $frequency);
            
            $this->logger->log('Automated import scheduled: ' . $frequency, 'info');
            
            return array(
                'success' => true,
                'frequency' => $frequency,
                'next_run' => wp_next_scheduled(self::CRON_HOOK),
                'message' => 'Automated import scheduled successfully'
            );
            
        } catch (Exception $e) {
            $error_message = 'Schedule exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('schedule_exception', $error_message);
        }
    }
    
    /**
     * Unschedule automated import
     *
     * @return bool True on success, false on failure
     */
    public function unschedule_automated_import() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $this->logger->log('Automated import unscheduled', 'info');
        }
        
        delete_option('giji_fixed_auto_import_frequency');
        
        return true;
    }
    
    /**
     * Execute automated import (cron callback)
     */
    public function execute_automated_import() {
        try {
            $this->logger->log('Starting automated import execution', 'info');
            
            // Check if import is already running
            if ($this->is_import_running()) {
                $this->logger->log('Import already running, skipping execution', 'warning');
                return;
            }
            
            // Set import lock
            $this->set_import_lock();
            
            // Increase time limit
            if (!ini_get('safe_mode')) {
                @set_time_limit(self::MAX_EXECUTION_TIME);
            }
            
            // Execute the import
            $result = $this->run_import_process();
            
            // Log results
            if (is_wp_error($result)) {
                $this->logger->log('Automated import failed: ' . $result->get_error_message(), 'error');
            } else {
                $this->logger->log(sprintf(
                    'Automated import completed: %d processed, %d errors',
                    $result['processed'] ?? 0,
                    $result['errors'] ?? 0
                ), 'info');
            }
            
        } catch (Exception $e) {
            $this->logger->log('Automated import exception: ' . $e->getMessage(), 'error');
        } finally {
            // Always clear the lock
            $this->clear_import_lock();
        }
    }
    
    /**
     * Run the import process
     *
     * @return array|WP_Error Import results or error
     */
    private function run_import_process() {
        // Check if API client is available
        if (!$this->api_client) {
            return new WP_Error('no_api_client', 'JGrants API client not available');
        }
        
        // Check if data processor is available
        if (!$this->data_processor) {
            return new WP_Error('no_data_processor', 'Data processor not available');
        }
        
        // Get automation settings
        $settings = $this->get_automation_settings();
        
        // Fetch grants from API
        $api_result = $this->api_client->fetch_grants(array(
            'limit' => $settings['batch_size'] ?? 10,
            'offset' => 0
        ));
        
        if (is_wp_error($api_result)) {
            return $api_result;
        }
        
        if (empty($api_result['grants'])) {
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'No grants found to process'
            );
        }
        
        // Process the grants
        $processing_options = array(
            'post_status' => $settings['post_status'] ?? 'draft',
            'force_update' => $settings['force_update'] ?? false
        );
        
        $result = $this->data_processor->process_multiple_grants(
            $api_result['grants'],
            $processing_options
        );
        
        return $result;
    }
    
    /**
     * Check if import is currently running
     *
     * @return bool True if running, false otherwise
     */
    private function is_import_running() {
        $lock_time = get_transient(self::IMPORT_LOCK_KEY);
        
        if ($lock_time === false) {
            return false;
        }
        
        // Check if lock has expired (safety mechanism)
        if (time() - $lock_time > self::MAX_EXECUTION_TIME) {
            $this->clear_import_lock();
            return false;
        }
        
        return true;
    }
    
    /**
     * Set import lock to prevent concurrent executions
     */
    private function set_import_lock() {
        set_transient(self::IMPORT_LOCK_KEY, time(), self::MAX_EXECUTION_TIME + 60);
    }
    
    /**
     * Clear import lock
     */
    private function clear_import_lock() {
        delete_transient(self::IMPORT_LOCK_KEY);
    }
    
    /**
     * Get automation settings
     *
     * @return array Automation settings
     */
    private function get_automation_settings() {
        $default_settings = array(
            'batch_size' => 10,
            'post_status' => 'draft',
            'force_update' => false,
            'enable_ai_processing' => false
        );
        
        $saved_settings = get_option('giji_fixed_automation_settings', array());
        
        return wp_parse_args($saved_settings, $default_settings);
    }
    
    /**
     * Update automation settings
     *
     * @param array $settings New settings
     * @return bool True on success, false on failure
     */
    public function update_automation_settings($settings) {
        if (!is_array($settings)) {
            return false;
        }
        
        // Sanitize settings
        $sanitized_settings = array();
        
        if (isset($settings['batch_size'])) {
            $sanitized_settings['batch_size'] = max(1, min(50, intval($settings['batch_size'])));
        }
        
        if (isset($settings['post_status'])) {
            $valid_statuses = array('draft', 'publish', 'pending');
            if (in_array($settings['post_status'], $valid_statuses)) {
                $sanitized_settings['post_status'] = $settings['post_status'];
            }
        }
        
        if (isset($settings['force_update'])) {
            $sanitized_settings['force_update'] = (bool) $settings['force_update'];
        }
        
        if (isset($settings['enable_ai_processing'])) {
            $sanitized_settings['enable_ai_processing'] = (bool) $settings['enable_ai_processing'];
        }
        
        $result = update_option('giji_fixed_automation_settings', $sanitized_settings);
        
        if ($result) {
            $this->logger->log('Automation settings updated', 'info');
        }
        
        return $result;
    }
    
    /**
     * Trigger manual import
     *
     * @param array $options Import options
     * @return array|WP_Error Import result or error
     */
    public function trigger_manual_import($options = array()) {
        try {
            // Check if import is already running
            if ($this->is_import_running()) {
                return new WP_Error('import_running', 'Import is already in progress');
            }
            
            // Validate user capability
            if (!current_user_can('manage_options')) {
                return new WP_Error('insufficient_permissions', 'Insufficient permissions to trigger import');
            }
            
            $this->logger->log('Manual import triggered by user: ' . get_current_user_id(), 'info');
            
            // Set import lock
            $this->set_import_lock();
            
            // Run import process
            $result = $this->run_import_process();
            
            // Clear lock
            $this->clear_import_lock();
            
            return $result;
            
        } catch (Exception $e) {
            $this->clear_import_lock();
            $error_message = 'Manual import exception: ' . $e->getMessage();
            $this->logger->log($error_message, 'error');
            return new WP_Error('manual_import_exception', $error_message);
        }
    }
    
    /**
     * Get automation status
     *
     * @return array Automation status information
     */
    public function get_automation_status() {
        $next_scheduled = wp_next_scheduled(self::CRON_HOOK);
        $frequency = get_option('giji_fixed_auto_import_frequency', 'none');
        $is_running = $this->is_import_running();
        
        return array(
            'is_scheduled' => (bool) $next_scheduled,
            'next_run' => $next_scheduled ? date('Y-m-d H:i:s', $next_scheduled) : null,
            'frequency' => $frequency,
            'is_running' => $is_running,
            'settings' => $this->get_automation_settings()
        );
    }
    
    /**
     * Get import history
     *
     * @param int $limit Number of records to retrieve
     * @return array Import history
     */
    public function get_import_history($limit = 10) {
        // This would typically query a custom table or use the logger
        // For now, return a simple structure
        return array(
            'recent_imports' => array(),
            'total_count' => 0,
            'last_import' => null
        );
    }
    
    /**
     * Clean up automation data on plugin deactivation
     */
    public function cleanup() {
        // Unschedule events
        $this->unschedule_automated_import();
        
        // Clear locks
        $this->clear_import_lock();
        
        // Clean up options
        delete_option('giji_fixed_automation_settings');
        
        $this->logger->log('Automation controller cleanup completed', 'info');
    }
}