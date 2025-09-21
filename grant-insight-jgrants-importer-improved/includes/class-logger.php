<?php
/**
 * ログ管理クラス（改善版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Logger {
    
    private $table_name;
    private $max_log_entries = 10000;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'giji_improved_logs';
    }
    
    /**
     * ログテーブルの作成
     */
    public function create_log_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context longtext,
            user_id bigint(20),
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY (id),
            KEY level (level),
            KEY timestamp (timestamp),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('giji_improved_db_version', GIJI_IMPROVED_PLUGIN_VERSION);
    }
    
    /**
     * ログの記録
     */
    public function log($message, $level = 'info', $context = array()) {
        global $wpdb;
        
        $valid_levels = array('debug', 'info', 'warning', 'error', 'critical');
        if (!in_array($level, $valid_levels)) {
            $level = 'info';
        }
        
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        
        $context_json = !empty($context) ? wp_json_encode($context) : null;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'level' => $level,
                'message' => $message,
                'context' => $context_json,
                'user_id' => $user_id ? $user_id : null,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if (GIJI_DEBUG && function_exists('error_log')) {
            error_log(sprintf('[GIJI %s] %s', strtoupper($level), $message));
        }
        
        $this->cleanup_old_logs();
        
        return $result !== false;
    }
    
    /**
     * ログの取得
     */
    public function get_logs($limit = 100, $level = null, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $where_conditions = array();
        $where_values = array();
        
        if ($level) {
            $where_conditions[] = "level = %s";
            $where_values[] = $level;
        }
        
        if ($start_date) {
            $where_conditions[] = "timestamp >= %s";
            $where_values[] = $start_date;
        }
        
        if ($end_date) {
            $where_conditions[] = "timestamp <= %s";
            $where_values[] = $end_date;
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        $sql = "SELECT * FROM $this->table_name $where_clause ORDER BY timestamp DESC LIMIT %d";
        $where_values[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $where_values));
    }
    
    /**
     * ログ統計の取得
     */
    public function get_log_stats($days = 7) {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $sql = "SELECT level, COUNT(*) as count FROM $this->table_name 
                WHERE timestamp >= %s 
                GROUP BY level";
        
        return $wpdb->get_results($wpdb->prepare($sql, $start_date));
    }
    
    /**
     * 古いログの削除
     */
    private function cleanup_old_logs() {
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
        
        if ($count > $this->max_log_entries) {
            $delete_count = $count - $this->max_log_entries;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $this->table_name ORDER BY timestamp ASC LIMIT %d",
                $delete_count
            ));
        }
        
        $old_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $this->table_name WHERE timestamp < %s",
            $old_date
        ));
    }
    
    /**
     * クライアントIPアドレスの取得
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }
    
    /**
     * ログの一括削除
     */
    public function clear_logs($level = null, $older_than_days = null) {
        global $wpdb;
        
        $where_conditions = array();
        $where_values = array();
        
        if ($level) {
            $where_conditions[] = "level = %s";
            $where_values[] = $level;
        }
        
        if ($older_than_days) {
            $date_threshold = date('Y-m-d H:i:s', strtotime("-$older_than_days days"));
            $where_conditions[] = "timestamp < %s";
            $where_values[] = $date_threshold;
        }
        
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            $sql = "DELETE FROM $this->table_name $where_clause";
            return $wpdb->query($wpdb->prepare($sql, $where_values));
        } else {
            return $wpdb->query("TRUNCATE TABLE $this->table_name");
        }
    }
}