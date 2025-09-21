<?php
/**
 * ログ管理クラス（修正版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Fixed_Logger extends GIJI_Singleton_Base {
    
    private $table_name;
    private $max_logs = 10000; // 最大ログ数
    
    protected function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'giji_fixed_logs';
        
        // テーブル作成
        $this->create_log_tables();
    }
    
    /**
     * ログテーブルの作成
     */
    public function create_log_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context text,
            user_id bigint(20),
            ip_address varchar(45),
            user_agent text,
            PRIMARY KEY (id),
            KEY level_index (level),
            KEY timestamp_index (timestamp),
            KEY user_id_index (user_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        if ($result) {
            error_log('GIJI FIXED Logger: ログテーブル作成完了');
        }
        
        return $result;
    }
    
    /**
     * ログの記録（修正版）
     */
    public function log($message, $level = 'info', $context = array()) {
        global $wpdb;
        
        // レベルの正規化
        $level = strtolower($level);
        $allowed_levels = array('debug', 'info', 'warning', 'error', 'critical');
        if (!in_array($level, $allowed_levels)) {
            $level = 'info';
        }
        
        // 追加情報の取得
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // コンテキストの準備
        $context_json = !empty($context) ? wp_json_encode($context) : null;
        
        try {
            // データベースに挿入
            $result = $wpdb->insert(
                $this->table_name,
                array(
                    'timestamp' => current_time('mysql'),
                    'level' => $level,
                    'message' => $message,
                    'context' => $context_json,
                    'user_id' => $user_id > 0 ? $user_id : null,
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent
                ),
                array(
                    '%s', // timestamp
                    '%s', // level
                    '%s', // message
                    '%s', // context
                    '%d', // user_id
                    '%s', // ip_address
                    '%s'  // user_agent
                )
            );
            
            if ($result === false) {
                // データベース挿入に失敗した場合はerror_logにフォールバック
                error_log("GIJI FIXED LOG [{$level}] {$message}" . (!empty($context) ? ' | Context: ' . wp_json_encode($context) : ''));
            }
            
            // 古いログのクリーンアップ（定期的に実行）
            if (rand(1, 100) <= 5) { // 5%の確率で実行
                $this->cleanup_old_logs();
            }
            
        } catch (Exception $e) {
            // 例外が発生した場合もerror_logにフォールバック
            error_log("GIJI FIXED LOG ERROR: " . $e->getMessage());
            error_log("GIJI FIXED LOG [{$level}] {$message}");
        }
    }
    
    /**
     * ログの取得
     */
    public function get_logs($limit = 100, $offset = 0, $level = null) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if ($level) {
            $where = 'WHERE level = %s';
            $params[] = $level;
        }
        
        $sql = "SELECT * FROM {$this->table_name} {$where} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $params[] = intval($limit);
        $params[] = intval($offset);
        
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * ログ統計の取得
     */
    public function get_log_stats($days = 7) {
        global $wpdb;
        
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $sql = $wpdb->prepare(
            "SELECT level, COUNT(*) as count 
             FROM {$this->table_name} 
             WHERE timestamp >= %s 
             GROUP BY level 
             ORDER BY count DESC",
            $date_from
        );
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * ログのクリア
     */
    public function clear_logs($level = null) {
        global $wpdb;
        
        if ($level) {
            $result = $wpdb->delete(
                $this->table_name,
                array('level' => $level),
                array('%s')
            );
        } else {
            $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        }
        
        if ($result !== false) {
            $this->log('ログをクリアしました' . ($level ? " (レベル: {$level})" : ''), 'info');
        }
        
        return $result !== false;
    }
    
    /**
     * ログのエクスポート
     */
    public function export_logs($format = 'csv', $limit = 1000) {
        global $wpdb;
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT timestamp, level, message, context, user_id, ip_address 
                 FROM {$this->table_name} 
                 ORDER BY timestamp DESC 
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        
        if (empty($logs)) {
            return false;
        }
        
        switch ($format) {
            case 'csv':
                return $this->export_logs_csv($logs);
            case 'json':
                return wp_json_encode($logs);
            default:
                return false;
        }
    }
    
    /**
     * CSV形式でのエクスポート
     */
    private function export_logs_csv($logs) {
        $csv_content = '';
        
        // ヘッダー行
        $headers = array('日時', 'レベル', 'メッセージ', 'コンテキスト', 'ユーザーID', 'IPアドレス');
        $csv_content .= '"' . implode('","', $headers) . '"' . "\n";
        
        // データ行
        foreach ($logs as $log) {
            $row = array(
                $log['timestamp'],
                $log['level'],
                str_replace('"', '""', $log['message']), // CSVエスケープ
                str_replace('"', '""', $log['context'] ?? ''),
                $log['user_id'] ?? '',
                $log['ip_address'] ?? ''
            );
            $csv_content .= '"' . implode('","', $row) . '"' . "\n";
        }
        
        return $csv_content;
    }
    
    /**
     * 古いログのクリーンアップ
     */
    private function cleanup_old_logs() {
        global $wpdb;
        
        // 最大ログ数を超えている場合、古いログを削除
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        if ($count > $this->max_logs) {
            $delete_count = $count - $this->max_logs + 1000; // 余裕を持って削除
            
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table_name} 
                     ORDER BY timestamp ASC 
                     LIMIT %d",
                    $delete_count
                )
            );
            
            $this->log("古いログをクリーンアップしました (削除数: {$delete_count})", 'info');
        }
        
        // 30日以上古いログを削除
        $old_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} 
                 WHERE timestamp < %s",
                $old_date
            )
        );
        
        if ($deleted > 0) {
            $this->log("30日以上古いログを削除しました (削除数: {$deleted})", 'info');
        }
    }
    
    /**
     * クライアントIPアドレスの取得
     */
    private function get_client_ip() {
        $ip_keys = array(
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
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * エラーレベルのログ記録（ヘルパーメソッド）
     */
    public function error($message, $context = array()) {
        $this->log($message, 'error', $context);
    }
    
    /**
     * 警告レベルのログ記録（ヘルパーメソッド）
     */
    public function warning($message, $context = array()) {
        $this->log($message, 'warning', $context);
    }
    
    /**
     * 情報レベルのログ記録（ヘルパーメソッド）
     */
    public function info($message, $context = array()) {
        $this->log($message, 'info', $context);
    }
    
    /**
     * デバッグレベルのログ記録（ヘルパーメソッド）
     */
    public function debug($message, $context = array()) {
        if (WP_DEBUG) {
            $this->log($message, 'debug', $context);
        }
    }
}