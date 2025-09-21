<?php
/**
 * セキュリティ管理クラス（修正版）
 */

if (!defined('ABSPATH')) {
    exit;
}

class GIJI_Fixed_Security_Manager extends GIJI_Singleton_Base {
    
    private $encryption_key;
    
    protected function __construct() {
        $this->encryption_key = $this->get_encryption_key();
    }
    
    /**
     * 暗号化キーの取得/生成
     */
    private function get_encryption_key() {
        $key = get_option('giji_fixed_encryption_key');
        
        if (!$key) {
            if (function_exists('random_bytes')) {
                $key = bin2hex(random_bytes(32));
            } else {
                $key = md5(wp_generate_password(64, true));
            }
            update_option('giji_fixed_encryption_key', $key);
        }
        
        return hex2bin($key);
    }
    
    /**
     * データの暗号化
     */
    public function encrypt($data) {
        if (empty($data) || !function_exists('openssl_encrypt')) {
            return $data;
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
            return $encrypted_data;
        }
        
        $cipher = 'AES-256-CBC';
        $data = base64_decode($encrypted_data);
        $iv_length = openssl_cipher_iv_length($cipher);
        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);
        
        return openssl_decrypt($encrypted, $cipher, $this->encryption_key, 0, $iv);
    }
    
    /**
     * APIキーの安全な保存（修正版）
     */
    public function save_api_key($key_name, $api_key) {
        $option_name = 'giji_fixed_' . $key_name . '_encrypted';
        
        // 空の場合はキーを削除
        if (empty($api_key)) {
            $delete_result = delete_option($option_name);
            error_log('GIJI FIXED Security: APIキー削除 ' . $key_name . ' (結果: ' . ($delete_result ? '削除成功' : '削除対象なし') . ')');
            return $delete_result;
        }
        
        // 既存の値を取得
        $previous_encrypted = get_option($option_name);
        
        // 新しい値を暗号化
        $new_encrypted = $this->encrypt($api_key);
        
        // 既存の値と比較（復号化して比較）
        $is_same = false;
        if ($previous_encrypted) {
            $previous_decrypted = $this->decrypt($previous_encrypted);
            $is_same = ($previous_decrypted === $api_key);
        }
        
        if ($is_same) {
            error_log('GIJI FIXED Security: APIキー変更なし ' . $key_name . ' (既存と同一)');
            return true;
        }
        
        // 新しい値を保存
        $result = update_option($option_name, $new_encrypted);
        
        // 保存確認のため値を読み直して検証
        $saved_encrypted = get_option($option_name);
        $saved_decrypted = $this->decrypt($saved_encrypted);
        $verification_success = ($saved_decrypted === $api_key);
        
        error_log('GIJI FIXED Security: APIキー保存 ' . $key_name . ' -> ' . $option_name . ' (結果: ' . ($result ? '保存成功' : '保存失敗') . ', 検証: ' . ($verification_success ? '成功' : '失敗') . ')');
        
        return $result && $verification_success;
    }
    
    /**
     * APIキーの安全な取得（修正版）
     */
    public function get_api_key($key_name) {
        $encrypted_key = get_option('giji_fixed_' . $key_name . '_encrypted');
        
        if (empty($encrypted_key)) {
            // 旧形式（暗号化されていない）のキーを確認
            $old_key = get_option('giji_improved_' . $key_name);
            if (!empty($old_key)) {
                // 旧形式のキーを修正版形式で保存し直す
                $this->save_api_key($key_name, $old_key);
                return $old_key;
            }
            return '';
        }
        
        $decrypted_key = $this->decrypt($encrypted_key);
        return $decrypted_key ? $decrypted_key : '';
    }
    
    /**
     * APIキーの存在確認（修正版）
     */
    public function has_api_key($key_name) {
        $api_key = $this->get_api_key($key_name);
        return !empty($api_key);
    }
    
    /**
     * 権限チェック（修正版・wp_dieを使わない）
     */
    public function check_admin_permission() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', 'この操作を実行する権限がありません。');
        }
        return true;
    }
    
    /**
     * Nonce検証（修正版・wp_dieを使わない）
     */
    public function verify_nonce($nonce, $action) {
        if (!wp_verify_nonce($nonce, $action)) {
            return new WP_Error('nonce_verification_failed', 'セキュリティ検証に失敗しました。');
        }
        return true;
    }
    
    /**
     * 安全なAJAXセキュリティチェック
     */
    public function validate_ajax_request($nonce, $action) {
        // Nonce検証
        $nonce_check = $this->verify_nonce($nonce, $action);
        if (is_wp_error($nonce_check)) {
            return $nonce_check;
        }
        
        // 権限チェック
        $permission_check = $this->check_admin_permission();
        if (is_wp_error($permission_check)) {
            return $permission_check;
        }
        
        return true;
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
     * レート制限チェック（修正版）
     */
    public function check_rate_limit($action, $user_id = null, $limit = 10, $window = 3600) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $key = 'giji_fixed_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient($key);
        
        if ($attempts === false) {
            $attempts = 0;
        }
        
        if ($attempts >= $limit) {
            return new WP_Error('rate_limit_exceeded', 'レート制限に達しました。しばらく待ってから再度お試しください。');
        }
        
        $attempts++;
        set_transient($key, $attempts, $window);
        
        return true;
    }
    
    /**
     * 設定値の検証
     */
    public function validate_settings($settings, $validation_rules = array()) {
        $validated = array();
        $errors = array();
        
        foreach ($settings as $key => $value) {
            if (isset($validation_rules[$key])) {
                $rule = $validation_rules[$key];
                
                switch ($rule['type']) {
                    case 'required':
                        if (empty($value)) {
                            $errors[$key] = $rule['message'] ?? $key . ' は必須です。';
                        } else {
                            $validated[$key] = $this->sanitize_input($value, $rule['sanitize'] ?? 'text');
                        }
                        break;
                        
                    case 'optional':
                        if (!empty($value)) {
                            $validated[$key] = $this->sanitize_input($value, $rule['sanitize'] ?? 'text');
                        }
                        break;
                        
                    case 'enum':
                        if (!in_array($value, $rule['values'])) {
                            $errors[$key] = $rule['message'] ?? $key . ' の値が無効です。';
                        } else {
                            $validated[$key] = $value;
                        }
                        break;
                }
            } else {
                // ルールが定義されていない場合はデフォルトでサニタイズ
                $validated[$key] = $this->sanitize_input($value);
            }
        }
        
        if (!empty($errors)) {
            return new WP_Error('validation_failed', '設定値の検証に失敗しました。', $errors);
        }
        
        return $validated;
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