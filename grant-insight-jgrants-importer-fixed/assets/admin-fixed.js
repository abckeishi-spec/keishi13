jQuery(document).ready(function($) {
    
    console.log('GIJI修正版管理画面スクリプト読み込み完了');
    
    // AIプロバイダー切り替え処理
    $('#giji_fixed_ai_provider').on('change', function() {
        var provider = $(this).val();
        
        // すべてのAPIキー行を非表示
        $('.api-key-row').hide();
        
        // 選択されたプロバイダーの行のみ表示
        $('.' + provider + '-row').show();
        
        console.log('AIプロバイダー変更:', provider);
    });
    
    // 初期化時にプロバイダー設定を実行
    $('#giji_fixed_ai_provider').trigger('change');
    
    // 修正版設定保存処理
    $('#giji-fixed-save-settings').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-fixed-settings-result');
        
        // 現在選択されているプロバイダー
        var provider = $('#giji_fixed_ai_provider').val();
        
        // APIキーの取得
        var apiKeys = {
            ai_provider: provider,
            openai_api_key: $('#giji_fixed_openai_api_key').val().trim(),
            gemini_api_key: $('#giji_fixed_gemini_api_key').val().trim(),
            claude_api_key: $('#giji_fixed_claude_api_key').val().trim()
        };
        
        // 選択されたプロバイダーのAPIキーが入力されているかチェック
        var selectedKey = apiKeys[provider + '_api_key'];
        if (!selectedKey || selectedKey.length < 10) {
            showError(resultDiv, '選択されたプロバイダー（' + provider + '）のAPIキーを入力してください（最低10文字以上）');
            return;
        }
        
        button.prop('disabled', true).text('保存中...');
        showLoading(resultDiv, '設定を保存しています...');
        
        $.ajax({
            url: giji_fixed_ajax.ajax_url,
            type: 'POST',
            data: $.extend({
                action: 'giji_fixed_save_settings',
                nonce: giji_fixed_ajax.nonce
            }, apiKeys),
            success: function(response) {
                console.log('修正版設定保存レスポンス:', response);
                
                if (response.success) {
                    var message = response.data.message || '設定を保存しました';
                    showSuccess(resultDiv, message);
                    
                    // APIキーフィールドをクリア（セキュリティのため）
                    $('#giji_fixed_openai_api_key, #giji_fixed_gemini_api_key, #giji_fixed_claude_api_key').val('');
                } else {
                    var errorMessage = response.data ? response.data.message : '設定保存に失敗しました';
                    showError(resultDiv, errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('修正版設定保存エラー:', xhr, status, error);
                var errorMessage = '通信エラーが発生しました';
                
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                } else if (xhr.status === 403) {
                    errorMessage = 'アクセス権限がありません';
                } else if (xhr.status >= 500) {
                    errorMessage = 'サーバーエラーが発生しました';
                }
                
                showError(resultDiv, errorMessage);
            },
            complete: function() {
                button.prop('disabled', false).text('修正版で設定保存');
            }
        });
    });
    
    // 修正版APIテスト処理
    $('#giji-fixed-test-api-keys').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-fixed-api-test-result');
        
        button.prop('disabled', true).text('テスト中...');
        showLoading(resultDiv, 'APIをテストしています...');
        
        $.ajax({
            url: giji_fixed_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_fixed_test_api_keys',
                nonce: giji_fixed_ajax.nonce
            },
            success: function(response) {
                console.log('修正版APIテスト結果:', response);
                
                if (response.success) {
                    var html = '<div class=\"notice notice-info\"><h4>修正版APIテスト結果:</h4>';
                    html += '<ul style=\"margin: 10px 0;\">';
                    html += '<li><strong>JグランツAPI:</strong> ' + (response.data.jgrants ? 
                        '<span style=\"color: green;\">✓ 接続成功</span>' : 
                        '<span style=\"color: red;\">✗ 接続失敗</span>') + '</li>';
                    html += '<li><strong>選択プロバイダー:</strong> ' + escapeHtml(response.data.provider) + '</li>';
                    html += '<li><strong>APIキー存在:</strong> ' + (response.data.api_key_exists ? 
                        '<span style=\"color: green;\">✓ あり (' + response.data.api_key_length + '文字)</span>' : 
                        '<span style=\"color: red;\">✗ なし</span>') + '</li>';
                    html += '<li><strong>AI API:</strong> ' + (response.data.ai_api ? 
                        '<span style=\"color: green;\">✓ 接続成功</span>' : 
                        '<span style=\"color: red;\">✗ 接続失敗</span>') + ' - ' + escapeHtml(response.data.ai_message) + '</li>';
                    html += '</ul>';
                    
                    if (response.data.jgrants && response.data.ai_api) {
                        html += '<div class=\"notice notice-success\" style=\"margin: 10px 0; padding: 5px;\"><p><strong>すべてのAPIが正常に動作しています！</strong></p></div>';
                    } else {
                        html += '<div class=\"notice notice-warning\" style=\"margin: 10px 0; padding: 5px;\"><p><strong>一部のAPIに問題があります。設定を確認してください。</strong></p></div>';
                    }
                    html += '</div>';
                    
                    resultDiv.html(html);
                } else {
                    showError(resultDiv, 'テストに失敗しました: ' + (response.data?.message || '不明なエラー'));
                }
            },
            error: function(xhr, status, error) {
                console.error('修正版APIテストエラー:', xhr, status, error);
                showError(resultDiv, '通信エラーが発生しました: ' + error);
            },
            complete: function() {
                button.prop('disabled', false).text('修正版でAPIテスト');
            }
        });
    });
    
    // 修正版手動公開処理
    $('#giji-fixed-manual-publish').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-fixed-publish-result');
        var count = parseInt($('#giji-fixed-publish-count').val());
        
        if (!count || count < 1) {
            showError(resultDiv, '公開件数を正しく入力してください（1以上）');
            return;
        }
        
        if (count > 100) {
            showError(resultDiv, '公開件数は最大100件までです');
            return;
        }
        
        if (!confirm('修正版で' + count + '件の下書きを公開しますか？')) {
            return;
        }
        
        button.prop('disabled', true).text('修正版で処理中...');
        showLoading(resultDiv, '修正版で公開処理を実行しています...');
        
        $.ajax({
            url: giji_fixed_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_fixed_manual_publish',
                nonce: giji_fixed_ajax.nonce,
                count: count
            },
            timeout: 120000, // 2分
            success: function(response) {
                console.log('修正版公開レスポンス:', response);
                
                if (response.success) {
                    var results = response.data.results;
                    var html = '<div class=\"notice notice-success\"><p><strong>' + response.data.message + '</strong></p></div>';
                    
                    if (results) {
                        html += '<div class=\"publish-summary\">';
                        html += '<p><strong>結果:</strong> ';
                        html += '成功: <span style=\"color: green; font-weight: bold;\">' + results.success + '件</span>, ';
                        html += 'エラー: <span style=\"color: red; font-weight: bold;\">' + results.error + '件</span></p>';
                        html += '</div>';
                        
                        if (results.details && results.details.length > 0) {
                            html += '<details class=\"publish-details\"><summary><strong>詳細結果 (' + results.details.length + '件)</strong></summary>';
                            html += '<ul style=\"margin: 10px 0; padding-left: 20px;\">';
                            results.details.forEach(function(detail) {
                                var statusClass = detail.status === 'success' ? 'green' : 'red';
                                var statusText = detail.status === 'success' ? '公開成功' : '公開失敗';
                                html += '<li style=\"margin-bottom: 5px;\">';
                                html += '<strong>' + escapeHtml(detail.title) + '</strong> - ';
                                html += '<span style=\"color: ' + statusClass + ';\">' + statusText + '</span>';
                                if (detail.message) {
                                    html += ' (' + escapeHtml(detail.message) + ')';
                                }
                                html += '</li>';
                            });
                            html += '</ul></details>';
                        }
                    }
                    
                    resultDiv.html(html);
                    
                    // 統計の更新
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    
                } else {
                    var errorMessage = response.data ? response.data.message : 'エラーが発生しました';
                    showError(resultDiv, '修正版エラー: ' + errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('修正版公開エラー:', xhr, status, error);
                var errorMessage = '修正版: 通信エラーが発生しました';
                
                if (status === 'timeout') {
                    errorMessage = '修正版: タイムアウトが発生しました。大量のデータ処理には時間がかかる場合があります。';
                } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = '修正版: ' + xhr.responseJSON.data.message;
                } else if (xhr.status === 403) {
                    errorMessage = '修正版: アクセス権限がありません。ログインし直してください。';
                } else if (xhr.status >= 500) {
                    errorMessage = '修正版: サーバーエラーが発生しました。';
                }
                
                showError(resultDiv, errorMessage);
            },
            complete: function() {
                button.prop('disabled', false).text('修正版で公開実行');
            }
        });
    });
    
    // ログクリア処理
    $('#giji-fixed-clear-logs').on('click', function() {
        if (!confirm('ログをすべてクリアしますか？')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('クリア中...');
        
        $.ajax({
            url: giji_fixed_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_fixed_clear_logs',
                nonce: giji_fixed_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('ログのクリアに失敗しました。');
                }
            },
            error: function() {
                alert('通信エラーが発生しました。');
            },
            complete: function() {
                button.prop('disabled', false).text('ログをクリア');
            }
        });
    });
    
    // ログエクスポート処理
    $('#giji-fixed-export-logs').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('エクスポート中...');
        
        // エクスポート用のフォームを作成して送信
        var form = $('<form>', {
            method: 'POST',
            action: giji_fixed_ajax.ajax_url
        });
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'giji_fixed_export_logs'
        }));
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: giji_fixed_ajax.nonce
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
        
        setTimeout(function() {
            button.prop('disabled', false).text('CSVエクスポート');
        }, 2000);
    });
    
    // 入力値の検証
    $('input[type=\"number\"]').on('input', function() {
        var input = $(this);
        var value = parseInt(input.val());
        var min = parseInt(input.attr('min'));
        var max = parseInt(input.attr('max'));
        
        if (value < min) {
            input.val(min);
        } else if (max && value > max) {
            input.val(max);
        }
    });
    
    // ユーティリティ関数
    function showLoading(element, message) {
        var html = '<div class=\"giji-fixed-result loading\">';
        html += '<span class=\"spinner is-active\" style=\"float: left; margin-right: 10px;\"></span>';
        html += escapeHtml(message || '処理中...');
        html += '</div>';
        element.html(html);
    }
    
    function showSuccess(element, message) {
        element.html('<div class=\"giji-fixed-result success notice notice-success\"><p>' + message + '</p></div>');
    }
    
    function showError(element, message) {
        element.html('<div class=\"giji-fixed-result error notice notice-error\"><p>' + escapeHtml(message) + '</p></div>');
    }
    
    function escapeHtml(text) {
        if (typeof text !== 'string') return text;
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // 修正版のデバッグ情報表示
    if (typeof console !== 'undefined') {
        console.log('%c修正版プラグイン情報', 'background: #4CAF50; color: white; padding: 5px; border-radius: 3px;');
        console.log('バージョン: 2.1.0-fixed');
        console.log('特徴: 通信エラー修正、重複初期化防止、正確なAPIテスト');
        console.log('AJAX エンドポイント:', giji_fixed_ajax.ajax_url);
    }
});