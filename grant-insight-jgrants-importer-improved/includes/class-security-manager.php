<?php
/**
 * セキュリティ管理クラス
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Security_Manager {
    
    private $encryption_key;
    
    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
    }
    
    /**
     * 暗号化キーの取得/生成
     */
    private function get_encryption_key() {
        $key = get_option('giji_improved_encryption_key');
        
        if (!$key) {
            // 新しいキーを生成
            if (function_exists('random_bytes')) {
                $key = bin2hex(random_bytes(32));
            } else {
                // フォールバック
                $key = md5(wp_generate_password(64, true));
            }
            update_option('giji_improved_encryption_key', $key);
        }
        
        return hex2bin($key);
    }
    
    /**
     * データの暗号化
     */
    public function encrypt($data) {
        if (empty($data) || !function_exists('openssl_encrypt')) {
            return $data; // 暗号化できない場合はそのまま返す
        }
        
        $cipher = 'AES-256-CBC';
        if (function_exists('random_bytes')) {
            $iv = random_bytes(openssl_cipher_iv_length($cipher));
        } else {
            $iv = substr(md5(wp_generate_password(32, true)), 0, openssl_cipher_iv_length($cipher));
        }
        
        $encrypted = openssl_encrypt($data, $cipher, $this->encryption_key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * データの復号化
     */
    public function decrypt($encrypted_data) {
        if (empty($encrypted_data) || !function_exists('openssl_decrypt')) {
            return $encrypted_data; // 復号化できない場合はそのまま返す
        }
        
        $cipher = 'AES-256-CBC';
        $data = base64_decode($encrypted_data);
        $iv_length = openssl_cipher_iv_length($cipher);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        return openssl_decrypt($encrypted, $cipher, $this->encryption_key, 0, $iv);
    }
    
    /**
     * APIキーの安全な保存
     */
    public function save_api_key($key_name, $api_key) {
        $option_name = 'giji_improved_' . $key_name . '_encrypted';
        
        // 空の場合はキーを削除
        if (empty($api_key)) {
            $delete_result = delete_option($option_name);
            error_log('GIJI Security Manager: APIキー削除 ' . $key_name . ' (結果: ' . ($delete_result ? '削除成功' : '削除対象なし') . ')');
            return $delete_result;
        }
        
        $encrypted_key = $this->encrypt($api_key);
        $previous_value = get_option($option_name);
        
        // 既存の値と同じかチェック
        if ($previous_value === $encrypted_key) {
            error_log('GIJI Security Manager: APIキー変更なし ' . $key_name . ' (既存と同一)');
            return true; // 変更なしは成功として扱う
        }
        
        $result = update_option($option_name, $encrypted_key);
        error_log('GIJI Security Manager: APIキー保存 ' . $key_name . ' -> ' . $option_name . ' (結果: ' . ($result ? '新規保存成功' : '保存失敗') . ')');
        
        return $result;
    }
    
    /**
     * APIキーの安全な取得
     */
    public function get_api_key($key_name) {
        $encrypted_key = get_option('giji_improved_' . $key_name . '_encrypted');
        if (empty($encrypted_key)) {
            // 暗号化されていない古い形式のキーを確認
            return get_option('giji_improved_' . $key_name);
        }
        return $this->decrypt($encrypted_key);
    }
    
    /**
     * 権限チェック
     */
    public function check_admin_permission() {
        if (!current_user_can('manage_options')) {
            wp_die(__('この操作を実行する権限がありません。', 'grant-insight-jgrants-importer-improved'));
        }
    }
    
    /**
     * Nonce検証
     */
    public function verify_nonce($nonce, $action) {
        if (!wp_verify_nonce($nonce, $action)) {
            wp_die(__('セキュリティ検証に失敗しました。', 'grant-insight-jgrants-importer-improved'));
        }
    }
    
    /**
     * 入力値のサニタイズ
     */
    public function sanitize_input($input, $type = 'text') {
        switch ($type) {
            case 'text':
                return sanitize_text_field($input);
            case 'textarea':
                return sanitize_textarea_field($input);
            case 'email':
                return sanitize_email($input);
            case 'url':
                return esc_url_raw($input);
            case 'int':
                return intval($input);
            case 'float':
                return floatval($input);
            case 'array':
                return is_array($input) ? array_map('sanitize_text_field', $input) : array();
            default:
                return sanitize_text_field($input);
        }
    }
    
    /**
     * レート制限チェック
     */
    public function check_rate_limit($action, $user_id = null, $limit = 10, $window = 3600) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $key = 'giji_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            $attempts = 0;
        }
        
        if ($attempts >= $limit) {
            return false;
        }
        
        $attempts++;
        set_transient($key, $attempts, $window);
        
        return true;
    }
    
    /**
     * SQL文のサニタイズ
     */
    public function sanitize_sql($value, $type = 'string') {
        global $wpdb;
        
        switch ($type) {
            case 'int':
                return intval($value);
            case 'float':
                return floatval($value);
            case 'string':
            default:
                return $wpdb->prepare('%s', $value);
        }
    }
}