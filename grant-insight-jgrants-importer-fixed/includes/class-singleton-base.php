<?php
/**
 * Singleton Base Class for Grant Insight JGrants Importer Fixed
 * 
 * @package Grant_Insight_JGrants_Importer_Fixed
 * @version 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract singleton base class
 * 
 * Provides singleton pattern implementation for all plugin classes
 * to prevent duplicate initialization and ensure consistent instance management.
 */
abstract class GIJI_Singleton_Base {
    
    /**
     * Store instances of child classes
     *
     * @var array
     */
    private static $instances = array();
    
    /**
     * Track initialization counts for debugging
     *
     * @var array
     */
    private static $init_counts = array();
    
    /**
     * Protected constructor to prevent direct instantiation
     */
    protected function __construct() {
        $class_name = get_called_class();
        
        // Increment initialization count
        if (!isset(self::$init_counts[$class_name])) {
            self::$init_counts[$class_name] = 0;
        }
        self::$init_counts[$class_name]++;
        
        // Call the child class init method if it exists
        if (method_exists($this, 'init')) {
            $this->init();
        }
    }
    
    /**
     * Prevent cloning of the instance
     */
    private function __clone() {
        // Intentionally left blank to prevent cloning
    }
    
    /**
     * Prevent unserialization of the instance
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
    
    /**
     * Get the singleton instance
     *
     * @return static The singleton instance
     */
    public static function get_instance() {
        $class_name = get_called_class();
        
        if (!isset(self::$instances[$class_name])) {
            self::$instances[$class_name] = new static();
        }
        
        return self::$instances[$class_name];
    }
    
    /**
     * Check if an instance exists
     *
     * @return bool True if instance exists, false otherwise
     */
    public static function instance_exists() {
        $class_name = get_called_class();
        return isset(self::$instances[$class_name]);
    }
    
    /**
     * Get initialization count for debugging
     *
     * @return int Number of times this class was initialized
     */
    public static function get_init_count() {
        $class_name = get_called_class();
        return isset(self::$init_counts[$class_name]) ? self::$init_counts[$class_name] : 0;
    }
    
    /**
     * Get all initialization counts for debugging
     *
     * @return array Array of class names and their init counts
     */
    public static function get_all_init_counts() {
        return self::$init_counts;
    }
    
    /**
     * Reset singleton instances (for testing purposes)
     * 
     * @param string|null $class_name Specific class to reset, or null for all
     */
    public static function reset_instances($class_name = null) {
        if ($class_name !== null) {
            unset(self::$instances[$class_name]);
            unset(self::$init_counts[$class_name]);
        } else {
            self::$instances = array();
            self::$init_counts = array();
        }
    }
    
    /**
     * Abstract init method to be implemented by child classes
     * 
     * This method will be called during instance creation
     * Child classes should override this method to perform initialization
     */
    abstract protected function init();
    
    /**
     * Cleanup method for plugin deactivation
     * 
     * Child classes can override this method to perform cleanup
     */
    public function cleanup() {
        // Default implementation does nothing
        // Child classes should override if cleanup is needed
    }
    
    /**
     * Get debug information about the singleton
     *
     * @return array Debug information
     */
    public function get_debug_info() {
        $class_name = get_class($this);
        
        return array(
            'class_name' => $class_name,
            'instance_exists' => isset(self::$instances[$class_name]),
            'init_count' => self::get_init_count(),
            'memory_usage' => memory_get_usage(),
            'object_id' => spl_object_id($this)
        );
    }
    
    /**
     * Log singleton activity for debugging
     *
     * @param string $message Log message
     * @param string $level Log level
     */
    protected function log_singleton_activity($message, $level = 'debug') {
        // Only log if WordPress debug is enabled or in development environment
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $class_name = get_class($this);
            $full_message = sprintf('[%s Singleton] %s', $class_name, $message);
            
            // Try to use the logger if available, otherwise use error_log
            if (class_exists('GIJI_Fixed_Logger') && method_exists('GIJI_Fixed_Logger', 'get_instance')) {
                try {
                    $logger = GIJI_Fixed_Logger::get_instance();
                    if (method_exists($logger, 'log')) {
                        $logger->log($full_message, $level);
                        return;
                    }
                } catch (Exception $e) {
                    // Fall back to error_log if logger fails
                }
            }
            
            // Fallback to PHP error log
            error_log($full_message);
        }
    }
    
    /**
     * Validate singleton state
     *
     * @return bool True if singleton state is valid, false otherwise
     */
    public function validate_singleton_state() {
        $class_name = get_class($this);
        
        // Check if this instance is the registered singleton
        if (!isset(self::$instances[$class_name])) {
            return false;
        }
        
        // Check if this is the same instance
        if (self::$instances[$class_name] !== $this) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Magic method to provide read-only access to singleton properties
     *
     * @param string $name Property name
     * @return mixed Property value or null
     */
    public function __get($name) {
        // Allow access to debug information
        if ($name === 'singleton_debug') {
            return $this->get_debug_info();
        }
        
        // For other properties, check if a getter method exists
        $getter_method = 'get_' . $name;
        if (method_exists($this, $getter_method)) {
            return $this->$getter_method();
        }
        
        // Return null for undefined properties
        return null;
    }
    
    /**
     * Magic method to check if singleton properties exist
     *
     * @param string $name Property name
     * @return bool True if property exists, false otherwise
     */
    public function __isset($name) {
        if ($name === 'singleton_debug') {
            return true;
        }
        
        $getter_method = 'get_' . $name;
        return method_exists($this, $getter_method);
    }
    
    /**
     * Get singleton instance statistics
     *
     * @return array Statistics about all singleton instances
     */
    public static function get_singleton_stats() {
        return array(
            'total_classes' => count(self::$instances),
            'active_instances' => array_keys(self::$instances),
            'init_counts' => self::$init_counts,
            'total_inits' => array_sum(self::$init_counts),
            'memory_usage' => memory_get_usage(true)
        );
    }
}