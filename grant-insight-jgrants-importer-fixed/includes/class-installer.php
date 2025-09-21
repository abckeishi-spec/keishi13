<?php
/**
 * Plugin Installer for Grant Insight JGrants Importer Fixed
 * 
 * @package Grant_Insight_JGrants_Importer_Fixed
 * @version 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin installer class
 * 
 * Handles plugin activation, deactivation, and uninstall processes
 */
class GIJI_Fixed_Installer {
    
    /**
     * Plugin activation hook
     */
    public static function activate() {
        try {
            // Create database tables
            self::create_database_tables();
            
            // Set default options
            self::set_default_options();
            
            // Create required directories
            self::create_directories();
            
            // Schedule default cron events
            self::schedule_default_events();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            // Log activation
            error_log('Grant Insight JGrants Importer Fixed: Plugin activated successfully');
            
        } catch (Exception $e) {
            error_log('Grant Insight JGrants Importer Fixed activation error: ' . $e->getMessage());
            
            // Don't deactivate during activation - just log the error
            // User can check logs for details
        }
    }
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        try {
            // Clear scheduled events
            self::clear_scheduled_events();
            
            // Clear transients
            self::clear_transients();
            
            // Cleanup singletons
            self::cleanup_singletons();
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            // Log deactivation
            error_log('Grant Insight JGrants Importer Fixed: Plugin deactivated');
            
        } catch (Exception $e) {
            error_log('Grant Insight JGrants Importer Fixed deactivation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin uninstall hook
     */
    public static function uninstall() {
        try {
            // Remove database tables (if configured to do so)
            if (get_option('giji_fixed_remove_data_on_uninstall', false)) {
                self::remove_database_tables();
            }
            
            // Remove all plugin options
            self::remove_plugin_options();
            
            // Remove uploaded files (if any)
            self::remove_plugin_files();
            
            // Clear all transients
            self::clear_all_transients();
            
            // Log uninstall
            error_log('Grant Insight JGrants Importer Fixed: Plugin uninstalled');
            
        } catch (Exception $e) {
            error_log('Grant Insight JGrants Importer Fixed uninstall error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create required database tables
     */
    private static function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create log table
        $log_table_name = $wpdb->prefix . 'giji_fixed_logs';
        $log_table_sql = "CREATE TABLE $log_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY level (level),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // Create import history table
        $history_table_name = $wpdb->prefix . 'giji_fixed_import_history';
        $history_table_sql = "CREATE TABLE $history_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            source varchar(100) NOT NULL DEFAULT 'jgrants',
            total_processed int(11) NOT NULL DEFAULT 0,
            successful int(11) NOT NULL DEFAULT 0,
            errors int(11) NOT NULL DEFAULT 0,
            skipped int(11) NOT NULL DEFAULT 0,
            execution_time float DEFAULT NULL,
            memory_usage bigint(20) DEFAULT NULL,
            trigger_type varchar(50) NOT NULL DEFAULT 'manual',
            user_id bigint(20) unsigned DEFAULT NULL,
            details longtext,
            PRIMARY KEY (id),
            KEY import_date (import_date),
            KEY source (source),
            KEY trigger_type (trigger_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($log_table_sql);
        dbDelta($history_table_sql);
        
        // Update database version
        update_option('giji_fixed_db_version', '2.0.0');
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $default_options = array(
            'giji_fixed_api_settings' => array(
                'jgrants_endpoint' => 'https://www.jgrants-portal.go.jp/api/public',
                'request_timeout' => 30,
                'max_retries' => 3,
                'rate_limit_per_minute' => 30
            ),
            'giji_fixed_import_settings' => array(
                'batch_size' => 10,
                'default_post_status' => 'draft',
                'enable_duplicate_check' => true,
                'auto_categorization' => true,
                'enable_ai_processing' => false
            ),
            'giji_fixed_security_settings' => array(
                'enable_nonce_verification' => true,
                'encrypt_api_keys' => true,
                'log_user_actions' => true,
                'rate_limit_ajax' => true
            ),
            'giji_fixed_automation_settings' => array(
                'enable_cron' => false,
                'cron_frequency' => 'daily',
                'max_execution_time' => 300,
                'prevent_parallel_execution' => true
            ),
            'giji_fixed_logging_settings' => array(
                'enable_logging' => true,
                'log_level' => 'info',
                'max_log_entries' => 1000,
                'auto_cleanup_days' => 30
            ),
            'giji_fixed_version' => '2.0.0',
            'giji_fixed_installation_date' => current_time('mysql'),
            'giji_fixed_remove_data_on_uninstall' => false
        );
        
        foreach ($default_options as $option_name => $option_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $option_value);
            }
        }
    }
    
    /**
     * Create required directories
     */
    private static function create_directories() {
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/grant-insight-jgrants-fixed';
        
        if (!file_exists($plugin_upload_dir)) {
            wp_mkdir_p($plugin_upload_dir);
            
            // Create .htaccess for security
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($plugin_upload_dir . '/.htaccess', $htaccess_content);
            
            // Create index.php for additional security
            file_put_contents($plugin_upload_dir . '/index.php', '<?php // Silence is golden');
        }
        
        // Create logs subdirectory
        $logs_dir = $plugin_upload_dir . '/logs';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
            file_put_contents($logs_dir . '/.htaccess', $htaccess_content);
            file_put_contents($logs_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Schedule default cron events
     */
    private static function schedule_default_events() {
        // Schedule log cleanup
        if (!wp_next_scheduled('giji_fixed_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'giji_fixed_cleanup_logs');
        }
        
        // Schedule transient cleanup
        if (!wp_next_scheduled('giji_fixed_cleanup_transients')) {
            wp_schedule_event(time(), 'daily', 'giji_fixed_cleanup_transients');
        }
    }
    
    /**
     * Clear all scheduled events
     */
    private static function clear_scheduled_events() {
        // Clear main import event
        $timestamp = wp_next_scheduled('giji_fixed_auto_import');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'giji_fixed_auto_import');
        }
        
        // Clear cleanup events
        $cleanup_events = array(
            'giji_fixed_cleanup_logs',
            'giji_fixed_cleanup_transients'
        );
        
        foreach ($cleanup_events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
            }
        }
    }
    
    /**
     * Clear plugin transients
     */
    private static function clear_transients() {
        $transients = array(
            'giji_fixed_import_running',
            'giji_fixed_api_categories',
            'giji_fixed_api_test_result'
        );
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
    }
    
    /**
     * Clear all plugin transients (for uninstall)
     */
    private static function clear_all_transients() {
        global $wpdb;
        
        // Delete all plugin-related transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_giji_fixed_%',
                '_transient_timeout_giji_fixed_%'
            )
        );
    }
    
    /**
     * Cleanup singleton instances
     */
    private static function cleanup_singletons() {
        if (class_exists('GIJI_Singleton_Base')) {
            // Get all singleton classes and call cleanup
            $singleton_classes = array(
                'GIJI_Fixed_Logger',
                'GIJI_Fixed_Security_Manager',
                'GIJI_Fixed_Data_Processor',
                'GIJI_Fixed_AI_Client',
                'GIJI_Fixed_JGrants_API_Client',
                'GIJI_Fixed_Automation_Controller',
                'GIJI_Fixed_Admin_Manager'
            );
            
            foreach ($singleton_classes as $class_name) {
                if (class_exists($class_name) && method_exists($class_name, 'instance_exists')) {
                    if ($class_name::instance_exists()) {
                        $instance = $class_name::get_instance();
                        if (method_exists($instance, 'cleanup')) {
                            $instance->cleanup();
                        }
                    }
                }
            }
            
            // Reset all instances
            GIJI_Singleton_Base::reset_instances();
        }
    }
    
    /**
     * Remove database tables
     */
    private static function remove_database_tables() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'giji_fixed_logs',
            $wpdb->prefix . 'giji_fixed_import_history'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        delete_option('giji_fixed_db_version');
    }
    
    /**
     * Remove all plugin options
     */
    private static function remove_plugin_options() {
        global $wpdb;
        
        // Remove all plugin options
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                'giji_fixed_%'
            )
        );
    }
    
    /**
     * Remove plugin files
     */
    private static function remove_plugin_files() {
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/grant-insight-jgrants-fixed';
        
        if (file_exists($plugin_upload_dir)) {
            self::recursive_delete($plugin_upload_dir);
        }
    }
    
    /**
     * Recursively delete directory and its contents
     *
     * @param string $dir Directory path
     */
    private static function recursive_delete($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                self::recursive_delete($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
    
    /**
     * Check and update database if needed
     */
    public static function maybe_update_database() {
        $current_version = get_option('giji_fixed_db_version', '0');
        
        if (version_compare($current_version, '2.0.0', '<')) {
            self::create_database_tables();
        }
    }
    
    /**
     * Get installation info
     *
     * @return array Installation information
     */
    public static function get_installation_info() {
        return array(
            'version' => get_option('giji_fixed_version', 'unknown'),
            'installation_date' => get_option('giji_fixed_installation_date', 'unknown'),
            'database_version' => get_option('giji_fixed_db_version', 'unknown'),
            'tables_exist' => self::check_tables_exist(),
            'directories_exist' => self::check_directories_exist()
        );
    }
    
    /**
     * Check if database tables exist
     *
     * @return bool True if tables exist, false otherwise
     */
    private static function check_tables_exist() {
        global $wpdb;
        
        $tables = array(
            $wpdb->prefix . 'giji_fixed_logs',
            $wpdb->prefix . 'giji_fixed_import_history'
        );
        
        foreach ($tables as $table) {
            $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($result !== $table) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if required directories exist
     *
     * @return bool True if directories exist, false otherwise
     */
    private static function check_directories_exist() {
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/grant-insight-jgrants-fixed';
        $logs_dir = $plugin_upload_dir . '/logs';
        
        return file_exists($plugin_upload_dir) && file_exists($logs_dir);
    }
}