<?php
/**
 * Advanced Custom Fields設定（改善版）
 * 
 * このファイルは助成金投稿タイプ用のカスタムフィールドを定義します。
 * Advanced Custom Fieldsプラグインがインストールされている場合に使用されます。
 */

// セキュリティ: 直接アクセスを防ぐ
if (!defined('ABSPATH')) {
    exit;
}

// ACFが有効な場合のみ実行
if (function_exists('acf_add_local_field_group')) {
    
    // メイン情報フィールドグループ
    acf_add_local_field_group(array(
        'key' => 'group_grant_main_fields',
        'title' => '助成金情報',
        'fields' => array(
            array(
                'key' => 'field_jgrants_id',
                'label' => 'JグランツID',
                'name' => 'jgrants_id',
                'type' => 'text',
                'instructions' => 'JグランツAPIから取得した助成金のID',
                'required' => 1,
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_deadline_date',
                'label' => '募集終了日',
                'name' => 'deadline_date',
                'type' => 'date_picker',
                'instructions' => '助成金の募集終了日',
                'display_format' => 'Y年m月d日',
                'return_format' => 'Ymd',
                'first_day' => 1,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_deadline_text',
                'label' => '募集終了日（テキスト）',
                'name' => 'deadline_text',
                'type' => 'text',
                'instructions' => '募集終了日の日本語表記',
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_max_amount_numeric',
                'label' => '補助額上限（数値）',
                'name' => 'max_amount_numeric',
                'type' => 'number',
                'instructions' => '補助額上限の数値部分（円）',
                'min' => 0,
                'wrapper' => array(
                    'width' => '25',
                ),
            ),
            array(
                'key' => 'field_max_amount',
                'label' => '補助額上限（表示用）',
                'name' => 'max_amount',
                'type' => 'text',
                'instructions' => '補助額上限の表示用テキスト',
                'wrapper' => array(
                    'width' => '25',
                ),
            ),
            array(
                'key' => 'field_max_amount_raw',
                'label' => '補助額上限（元データ）',
                'name' => 'max_amount_raw',
                'type' => 'text',
                'instructions' => '補助額上限の元のテキスト',
                'wrapper' => array(
                    'width' => '25',
                ),
            ),
            array(
                'key' => 'field_subsidy_rate',
                'label' => '補助率',
                'name' => 'subsidy_rate',
                'type' => 'text',
                'instructions' => '補助率の情報',
                'wrapper' => array(
                    'width' => '25',
                ),
            ),
            array(
                'key' => 'field_organization',
                'label' => '実施組織',
                'name' => 'organization',
                'type' => 'text',
                'instructions' => 'AI抽出された実施組織名',
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_official_url',
                'label' => '公式URL',
                'name' => 'official_url',
                'type' => 'url',
                'instructions' => 'Jグランツの詳細ページURL',
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'grant',
                ),
            ),
        ),
        'menu_order' => 0,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
    ));
    
    // AI生成情報フィールドグループ
    acf_add_local_field_group(array(
        'key' => 'group_grant_ai_fields',
        'title' => 'AI生成情報',
        'fields' => array(
            array(
                'key' => 'field_ai_summary',
                'label' => 'AI生成要約',
                'name' => 'ai_summary',
                'type' => 'textarea',
                'instructions' => 'AIで生成された3行要約',
                'rows' => 3,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_excerpt',
                'label' => 'AI生成抜粋',
                'name' => 'ai_excerpt',
                'type' => 'textarea',
                'instructions' => 'AIによって生成された助成金の抜粋',
                'rows' => 3,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_keywords',
                'label' => 'AI生成キーワード',
                'name' => 'ai_keywords',
                'type' => 'text',
                'instructions' => 'AIで生成された関連キーワード',
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_target_audience',
                'label' => 'AI生成対象者説明',
                'name' => 'ai_target_audience',
                'type' => 'textarea',
                'instructions' => 'AIで生成された対象者の説明',
                'rows' => 2,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_application_tips',
                'label' => 'AI生成申請のコツ',
                'name' => 'ai_application_tips',
                'type' => 'textarea',
                'instructions' => 'AIで生成された申請成功のコツ',
                'rows' => 4,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_requirements',
                'label' => 'AI生成要件整理',
                'name' => 'ai_requirements',
                'type' => 'textarea',
                'instructions' => 'AIで整理された申請要件',
                'rows' => 4,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'grant',
                ),
            ),
        ),
        'menu_order' => 1,
        'position' => 'normal',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
    ));
    
    // 検索・分類用フィールドグループ
    acf_add_local_field_group(array(
        'key' => 'group_grant_classification_fields',
        'title' => '検索・分類情報',
        'fields' => array(
            array(
                'key' => 'field_difficulty_level',
                'label' => '申請難易度',
                'name' => 'difficulty_level',
                'type' => 'select',
                'instructions' => 'AI判定された申請難易度',
                'choices' => array(
                    'easy' => '易しい',
                    'medium' => '普通',
                    'hard' => '難しい',
                ),
                'default_value' => 'medium',
                'wrapper' => array(
                    'width' => '33',
                ),
            ),
            array(
                'key' => 'field_grant_success_rate',
                'label' => '採択率（%）',
                'name' => 'grant_success_rate',
                'type' => 'number',
                'instructions' => 'AI推定された採択率',
                'min' => 0,
                'max' => 100,
                'wrapper' => array(
                    'width' => '33',
                ),
            ),
            array(
                'key' => 'field_application_period',
                'label' => '申請期間',
                'name' => 'application_period',
                'type' => 'select',
                'instructions' => '申請可能な期間の分類',
                'choices' => array(
                    'ongoing' => '通年募集',
                    'quarterly' => '四半期募集',
                    'biannual' => '半年募集',
                    'annual' => '年1回募集',
                    'irregular' => '不定期募集',
                ),
                'default_value' => 'annual',
                'wrapper' => array(
                    'width' => '34',
                ),
            ),
            array(
                'key' => 'field_target_business_type',
                'label' => '対象事業者',
                'name' => 'target_business_type',
                'type' => 'checkbox',
                'instructions' => '助成金の対象となる事業者タイプ',
                'choices' => array(
                    'individual' => '個人事業主',
                    'small_company' => '中小企業',
                    'medium_company' => '中堅企業',
                    'large_company' => '大企業',
                    'startup' => 'スタートアップ',
                    'npo' => 'NPO法人',
                    'general_incorporated' => '一般社団法人',
                    'public_interest' => '公益法人',
                    'cooperative' => '協同組合',
                    'other' => 'その他',
                ),
                'layout' => 'horizontal',
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_required_documents',
                'label' => '必要書類の複雑さ',
                'name' => 'required_documents',
                'type' => 'select',
                'instructions' => '申請に必要な書類の複雑さ',
                'choices' => array(
                    'simple' => 'シンプル（基本書類のみ）',
                    'moderate' => '標準的（事業計画書等）',
                    'complex' => '複雑（詳細な計画書・財務書類等）',
                ),
                'default_value' => 'moderate',
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'grant',
                ),
            ),
        ),
        'menu_order' => 2,
        'position' => 'side',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
    ));
    
    // メタ情報フィールドグループ
    acf_add_local_field_group(array(
        'key' => 'group_grant_meta_fields',
        'title' => 'メタ情報',
        'fields' => array(
            array(
                'key' => 'field_import_date',
                'label' => 'インポート日時',
                'name' => 'import_date',
                'type' => 'date_time_picker',
                'instructions' => 'この助成金がインポートされた日時',
                'display_format' => 'Y年m月d日 H:i',
                'return_format' => 'Y-m-d H:i:s',
                'first_day' => 1,
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_last_updated',
                'label' => '最終更新日時',
                'name' => 'last_updated',
                'type' => 'date_time_picker',
                'instructions' => 'この助成金情報が最後に更新された日時',
                'display_format' => 'Y年m月d日 H:i',
                'return_format' => 'Y-m-d H:i:s',
                'first_day' => 1,
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '50',
                ),
            ),
            array(
                'key' => 'field_ai_generation_status',
                'label' => 'AI生成状況',
                'name' => 'ai_generation_status',
                'type' => 'checkbox',
                'instructions' => 'AI生成が完了した項目',
                'choices' => array(
                    'content' => '本文',
                    'excerpt' => '抜粋',
                    'summary' => '要約',
                    'organization' => '実施組織',
                    'difficulty' => '申請難易度',
                    'success_rate' => '採択率',
                    'keywords' => 'キーワード',
                    'target_audience' => '対象者説明',
                    'application_tips' => '申請のコツ',
                    'requirements' => '要件整理',
                ),
                'layout' => 'horizontal',
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '100',
                ),
            ),
            array(
                'key' => 'field_processing_notes',
                'label' => '処理メモ',
                'name' => 'processing_notes',
                'type' => 'textarea',
                'instructions' => 'インポート・AI生成時のメモや注意事項',
                'rows' => 3,
                'readonly' => 1,
                'wrapper' => array(
                    'width' => '100',
                ),
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'post_type',
                    'operator' => '==',
                    'value' => 'grant',
                ),
            ),
        ),
        'menu_order' => 3,
        'position' => 'side',
        'style' => 'default',
        'label_placement' => 'top',
        'instruction_placement' => 'label',
        'hide_on_screen' => '',
        'active' => true,
        'description' => '',
    ));
}

/**
 * ACFフィールドの値を取得するヘルパー関数（改善版）
 */
function giji_improved_get_field($field_name, $post_id = null) {
    if (function_exists('get_field')) {
        return get_field($field_name, $post_id);
    }
    
    // ACFが無効な場合はカスタムフィールドから取得
    if ($post_id === null) {
        global $post;
        $post_id = $post ? $post->ID : 0;
    }
    
    if ($post_id) {
        return get_post_meta($post_id, $field_name, true);
    }
    
    return '';
}

/**
 * ACFフィールドの値を更新するヘルパー関数（改善版）
 */
function giji_improved_update_field($field_name, $value, $post_id) {
    if (function_exists('update_field')) {
        return update_field($field_name, $value, $post_id);
    }
    
    // ACFが無効な場合はカスタムフィールドを更新
    return update_post_meta($post_id, $field_name, $value);
}

/**
 * ACFフィールドの表示用値を取得するヘルパー関数
 */
function giji_improved_get_field_display($field_name, $post_id = null) {
    $value = giji_improved_get_field($field_name, $post_id);
    
    // 特定フィールドの表示用変換
    switch ($field_name) {
        case 'difficulty_level':
            $difficulty_labels = array(
                'easy' => '易しい',
                'medium' => '普通',
                'hard' => '難しい'
            );
            return isset($difficulty_labels[$value]) ? $difficulty_labels[$value] : $value;
            
        case 'application_period':
            $period_labels = array(
                'ongoing' => '通年募集',
                'quarterly' => '四半期募集',
                'biannual' => '半年募集',
                'annual' => '年1回募集',
                'irregular' => '不定期募集'
            );
            return isset($period_labels[$value]) ? $period_labels[$value] : $value;
            
        case 'required_documents':
            $document_labels = array(
                'simple' => 'シンプル（基本書類のみ）',
                'moderate' => '標準的（事業計画書等）',
                'complex' => '複雑（詳細な計画書・財務書類等）'
            );
            return isset($document_labels[$value]) ? $document_labels[$value] : $value;
            
        case 'target_business_type':
            if (is_array($value)) {
                $type_labels = array(
                    'individual' => '個人事業主',
                    'small_company' => '中小企業',
                    'medium_company' => '中堅企業',
                    'large_company' => '大企業',
                    'startup' => 'スタートアップ',
                    'npo' => 'NPO法人',
                    'general_incorporated' => '一般社団法人',
                    'public_interest' => '公益法人',
                    'cooperative' => '協同組合',
                    'other' => 'その他'
                );
                
                $display_values = array();
                foreach ($value as $type) {
                    if (isset($type_labels[$type])) {
                        $display_values[] = $type_labels[$type];
                    }
                }
                return implode(', ', $display_values);
            }
            break;
            
        case 'grant_success_rate':
            return is_numeric($value) ? $value . '%' : $value;
            
        default:
            return $value;
    }
    
    return $value;
}

/**
 * 助成金の基本情報を配列で取得するヘルパー関数
 */
function giji_improved_get_grant_data($post_id = null) {
    if ($post_id === null) {
        global $post;
        $post_id = $post ? $post->ID : 0;
    }
    
    if (!$post_id) {
        return array();
    }
    
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'grant') {
        return array();
    }
    
    // 基本情報
    $data = array(
        'id' => $post_id,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'excerpt' => $post->post_excerpt,
        'status' => $post->post_status,
        'date' => $post->post_date,
        'modified' => $post->post_modified,
    );
    
    // カスタムフィールド
    $fields = array(
        'jgrants_id', 'deadline_date', 'deadline_text',
        'max_amount_numeric', 'max_amount', 'max_amount_raw',
        'subsidy_rate', 'organization', 'official_url',
        'ai_summary', 'ai_excerpt', 'ai_keywords',
        'ai_target_audience', 'ai_application_tips', 'ai_requirements',
        'difficulty_level', 'grant_success_rate', 'application_period',
        'target_business_type', 'required_documents',
        'import_date', 'last_updated', 'ai_generation_status', 'processing_notes'
    );
    
    foreach ($fields as $field) {
        $data[$field] = giji_improved_get_field($field, $post_id);
    }
    
    // タクソノミー情報
    $data['prefectures'] = wp_get_post_terms($post_id, 'grant_prefecture', array('fields' => 'names'));
    $data['categories'] = wp_get_post_terms($post_id, 'grant_category', array('fields' => 'names'));
    $data['organizations'] = wp_get_post_terms($post_id, 'grant_organization', array('fields' => 'names'));
    
    return $data;
}

// 下位互換性のためのエイリアス関数
function giji_get_field($field_name, $post_id = null) {
    return giji_improved_get_field($field_name, $post_id);
}

function giji_update_field($field_name, $value, $post_id) {
    return giji_improved_update_field($field_name, $value, $post_id);
}

// 関数が正常に定義されたことを確認
if (!function_exists('giji_improved_get_field')) {
    throw new Exception('giji_improved_get_field関数が定義されていません');
}

if (!function_exists('giji_improved_update_field')) {
    throw new Exception('giji_improved_update_field関数が定義されていません');
}