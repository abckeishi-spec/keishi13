<?php
/**
 * Uninstall script for Grant Insight JGrants Importer Fixed
 * 
 * This file is called when the plugin is uninstalled via WordPress admin.
 * It handles the complete removal of all plugin data, settings, and database tables.
 * 
 * @package Grant_Insight_JGrants_Importer_Fixed
 * @version 2.0.0
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Include the installer class
if (file_exists(plugin_dir_path(__FILE__) . 'includes/class-installer.php')) {
    require_once plugin_dir_path(__FILE__) . 'includes/class-installer.php';
}

// Security check - make sure we're in the correct context
if (!current_user_can('activate_plugins')) {
    return;
}

// Check if user has confirmed data removal
$remove_data = get_option('giji_fixed_remove_data_on_uninstall', false);

try {
    // Log the uninstall attempt
    error_log('Grant Insight JGrants Importer Fixed: Starting uninstall process');
    
    // If installer class exists, use it for clean uninstall
    if (class_exists('GIJI_Fixed_Installer')) {
        GIJI_Fixed_Installer::uninstall();
    } else {
        // Fallback uninstall process
        fallback_uninstall();
    }
    
    // Log successful uninstall
    error_log('Grant Insight JGrants Importer Fixed: Uninstall completed successfully');
    
} catch (Exception $e) {
    // Log any errors during uninstall
    error_log('Grant Insight JGrants Importer Fixed uninstall error: ' . $e->getMessage());
}

/**
 * Fallback uninstall function if installer class is not available
 */
function fallback_uninstall() {
    global $wpdb;
    
    // Remove scheduled cron events
    wp_clear_scheduled_hook('giji_fixed_auto_import');
    wp_clear_scheduled_hook('giji_fixed_cleanup_logs');
    wp_clear_scheduled_hook('giji_fixed_cleanup_transients');
    
    // Remove all transients
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_giji_fixed_%',
            '_transient_timeout_giji_fixed_%'
        )
    );
    
    // Remove plugin options if configured to do so
    $remove_data = get_option('giji_fixed_remove_data_on_uninstall', false);
    
    if ($remove_data) {
        // Remove database tables
        $tables = array(
            $wpdb->prefix . 'giji_fixed_logs',
            $wpdb->prefix . 'giji_fixed_import_history'
        );
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        // Remove all plugin options
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                'giji_fixed_%'
            )
        );
        
        // Remove uploaded files
        $upload_dir = wp_upload_dir();
        $plugin_upload_dir = $upload_dir['basedir'] . '/grant-insight-jgrants-fixed';
        
        if (file_exists($plugin_upload_dir)) {
            fallback_recursive_delete($plugin_upload_dir);
        }
    }
}

/**
 * Fallback recursive delete function
 *
 * @param string $dir Directory to delete
 */
function fallback_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($path)) {
            fallback_recursive_delete($path);
        } else {
            unlink($path);
        }
    }
    
    rmdir($dir);
}