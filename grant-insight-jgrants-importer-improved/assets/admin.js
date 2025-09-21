jQuery(document).ready(function($) {
    
    // AIプロバイダー切り替え処理
    $('#giji_improved_ai_provider').on('change', function() {
        var provider = $(this).val();
        
        // すべてのAPIキー行を非表示
        $('.api-key-row').hide();
        
        // 選択されたプロバイダーの行のみ表示
        $('.' + provider + '-row').show();
        
        // プロバイダーに応じてヒントを表示
        var hintText = '';
        switch(provider) {
            case 'gemini':
                hintText = 'Gemini API: 日本語対応良好、コストパフォーマンス良好';
                break;
            case 'openai':
                hintText = 'OpenAI API: GPT-4使用、高品質な生成、やや高コスト';
                break;
            case 'claude':
                hintText = 'Claude API: 自然な日本語、長文生成得意、高品質';
                break;
        }
        
        if (hintText) {
            if (!$('.ai-provider-hint').length) {
                $('#giji_improved_ai_provider').after('<div class="ai-provider-hint"></div>');
            }
            $('.ai-provider-hint').text(hintText);
        }
    });
    
    // 初期化時にプロバイダー設定を実行
    $('#giji_improved_ai_provider').trigger('change');
    
    // 手動インポート処理
    $('#giji-improved-manual-import').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-improved-import-result');
        
        // 入力値の取得と検証
        var keyword = $('#giji-improved-import-keyword').val().trim();
        var count = parseInt($('#giji-improved-import-count').val());
        
        // バリデーション
        if (keyword.length < 2) {
            showError(resultDiv, 'キーワードは2文字以上で入力してください。');
            return;
        }
        if (!count || count < 1 || count > 50) {
            showError(resultDiv, '取得件数は1～50の間で入力してください。');
            return;
        }
        
        // UI状態の更新
        button.prop('disabled', true);
        showLoading(resultDiv, 'インポートを実行しています...');
        
        // プログレスバーの表示
        var progressHtml = '<div class="giji-improved-progress"><div class="giji-improved-progress-bar" id="import-progress"></div></div>';
        resultDiv.append(progressHtml);
        
        // 進捗シミュレーション
        var progress = 0;
        var progressInterval = setInterval(function() {
            progress += Math.random() * 10;
            if (progress > 90) progress = 90;
            $('#import-progress').css('width', progress + '%');
        }, 500);
        
        // AJAX リクエスト
        $.ajax({
            url: giji_improved_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_improved_manual_import',
                nonce: giji_improved_ajax.nonce,
                keyword: keyword,
                count: count
            },
            timeout: 300000, // 5分
            success: function(response) {
                clearInterval(progressInterval);
                $('#import-progress').css('width', '100%');
                
                if (response.success) {
                    var results = response.results;
                    var html = '<div class="notice notice-success"><p><strong>インポートが完了しました！</strong></p></div>';
                    html += '<div class="import-summary">';
                    html += '<p><strong>検索条件:</strong> キーワード「' + escapeHtml(keyword) + '」, 取得件数: ' + count + '件</p>';
                    html += '<p><strong>結果:</strong> ';
                    html += '成功: <span class="success">' + results.success + '件</span>, ';
                    html += 'エラー: <span class="error">' + results.error + '件</span>, ';
                    html += '重複: <span class="duplicate">' + results.duplicate + '件</span></p>';
                    html += '</div>';
                    
                    if (results.details && results.details.length > 0) {
                        html += '<details class="import-details"><summary><strong>詳細結果 (' + results.details.length + '件)</strong></summary>';
                        html += '<ul class="import-detail-list">';
                        results.details.forEach(function(detail) {
                            var statusClass = detail.status === 'success' ? 'success' : 
                                            detail.status === 'duplicate' ? 'duplicate' : 'error';
                            var statusText = detail.status === 'success' ? '成功' :
                                           detail.status === 'duplicate' ? '重複' : 'エラー';
                            html += '<li class="' + statusClass + '">';
                            html += '<strong>' + escapeHtml(detail.title) + '</strong> - ' + statusText;
                            if (detail.message) {
                                html += ' (' + escapeHtml(detail.message) + ')';
                            }
                            if (detail.post_id) {
                                html += ' <small>[投稿ID: ' + detail.post_id + ']</small>';
                            }
                            html += '</li>';
                        });
                        html += '</ul></details>';
                    }
                    
                    showSuccess(resultDiv, html);
                    
                    // 統計の更新
                    updateStatistics();
                    
                } else {
                    showError(resultDiv, 'エラー: ' + (response.message || '不明なエラーが発生しました'));
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                var errorMessage = 'サーバーエラーが発生しました';
                if (status === 'timeout') {
                    errorMessage = 'タイムアウトが発生しました。大量のデータ処理には時間がかかる場合があります。';
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                }
                showError(resultDiv, errorMessage);
            },
            complete: function() {
                button.prop('disabled', false);
                setTimeout(function() {
                    $('.giji-improved-progress').fadeOut();
                }, 2000);
            }
        });
    });
    
    // 手動公開処理
    $('#giji-improved-manual-publish').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-improved-publish-result');
        var count = parseInt($('#giji-improved-publish-count').val());
        
        if (!count || count < 1) {
            showError(resultDiv, '公開件数を正しく入力してください。');
            return;
        }
        
        // 確認ダイアログ
        if (!confirm(count + '件の下書きを公開しますか？')) {
            return;
        }
        
        button.prop('disabled', true);
        showLoading(resultDiv, '公開処理を実行しています...');
        
        $.ajax({
            url: giji_improved_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_improved_manual_publish',
                nonce: giji_improved_ajax.nonce,
                count: count
            },
            success: function(response) {
                if (response.success) {
                    var results = response.data;
                    var html = '<div class="notice notice-success"><p><strong>公開処理が完了しました！</strong></p></div>';
                    html += '<p>成功: <span class="success">' + results.success + '件</span>, ';
                    html += 'エラー: <span class="error">' + results.error + '件</span></p>';
                    
                    if (results.details && results.details.length > 0) {
                        html += '<details class="publish-details"><summary><strong>詳細結果</strong></summary>';
                        html += '<ul>';
                        results.details.forEach(function(detail) {
                            var statusClass = detail.status === 'success' ? 'success' : 'error';
                            var statusText = detail.status === 'success' ? '公開成功' : '公開失敗';
                            html += '<li class="' + statusClass + '">';
                            html += escapeHtml(detail.title) + ' - ' + statusText;
                            if (detail.message) {
                                html += ' (' + escapeHtml(detail.message) + ')';
                            }
                            html += '</li>';
                        });
                        html += '</ul></details>';
                    }
                    
                    showSuccess(resultDiv, html);
                    updateStatistics();
                } else {
                    showError(resultDiv, 'エラーが発生しました。');
                }
            },
            error: function() {
                showError(resultDiv, '通信エラーが発生しました。');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // 下書き一括削除処理
    $('#giji-improved-bulk-delete').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-improved-delete-result');
        
        // 二重確認
        if (!confirm('本当に下書きをすべて削除しますか？この操作は取り消せません。')) {
            return;
        }
        
        if (!confirm('削除されたデータは復元できません。本当に実行しますか？')) {
            return;
        }
        
        button.prop('disabled', true);
        showLoading(resultDiv, '削除処理を実行しています...');
        
        $.ajax({
            url: giji_improved_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_improved_bulk_delete_drafts',
                nonce: giji_improved_ajax.nonce
            },
            timeout: 120000, // 2分
            success: function(response) {
                if (response.success) {
                    var results = response.data;
                    var html = '<div class="notice notice-success"><p><strong>削除処理が完了しました。</strong></p></div>';
                    html += '<p>削除成功: <span class="success">' + results.success + '件</span>, ';
                    html += 'エラー: <span class="error">' + results.error + '件</span></p>';
                    
                    showSuccess(resultDiv, html);
                    updateStatistics();
                } else {
                    showError(resultDiv, 'エラーが発生しました。');
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = '通信エラーが発生しました。';
                if (status === 'timeout') {
                    errorMessage = 'タイムアウトが発生しました。大量のデータ削除には時間がかかる場合があります。';
                }
                showError(resultDiv, errorMessage);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // APIキーテスト処理
    $('#giji-improved-test-api-keys').on('click', function() {
        var button = $(this);
        var resultDiv = $('#giji-improved-api-test-result');
        
        button.prop('disabled', true).text('テスト中...');
        showLoading(resultDiv, 'APIキーをテストしています...');
        
        $.ajax({
            url: giji_improved_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_improved_test_api_keys',
                nonce: giji_improved_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var html = '<div class="notice notice-info"><p><strong>APIキーテスト結果:</strong></p></div>';
                    html += '<ul>';
                    html += '<li>JグランツAPI: ' + (response.data.jgrants ? 
                        '<span class="success">✓ 接続成功</span>' : 
                        '<span class="error">✗ 接続失敗</span>') + '</li>';
                    html += '<li>選択されたAI API (' + escapeHtml(response.data.provider) + '): ' + 
                        (response.data.ai_api ? 
                        '<span class="success">✓ 接続成功</span>' : 
                        '<span class="error">✗ 接続失敗</span>') + '</li>';
                    html += '</ul>';
                    
                    if (response.data.jgrants && response.data.ai_api) {
                        html += '<div class="notice notice-success"><p>すべてのAPIキーが正常に動作しています。</p></div>';
                    } else {
                        html += '<div class="notice notice-warning"><p>一部のAPIキーに問題があります。設定を確認してください。</p></div>';
                    }
                    
                    resultDiv.html(html);
                } else {
                    showError(resultDiv, 'テストに失敗しました: ' + (response.data?.message || '不明なエラー'));
                }
            },
            error: function() {
                showError(resultDiv, '通信エラーが発生しました。');
            },
            complete: function() {
                button.prop('disabled', false).text('APIキーをテスト');
            }
        });
    });
    
    // ログクリア処理
    $('#giji-improved-clear-logs').on('click', function() {
        if (!confirm('ログをすべてクリアしますか？')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true);
        
        $.ajax({
            url: giji_improved_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'giji_improved_clear_logs',
                nonce: giji_improved_ajax.nonce
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
                button.prop('disabled', false);
            }
        });
    });
    
    // ログエクスポート処理
    $('#giji-improved-export-logs').on('click', function() {
        var button = $(this);
        button.prop('disabled', true).text('エクスポート中...');
        
        // エクスポート用のフォームを作成して送信
        var form = $('<form>', {
            method: 'POST',
            action: giji_improved_ajax.ajax_url
        });
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'action',
            value: 'giji_improved_export_logs'
        }));
        
        form.append($('<input>', {
            type: 'hidden',
            name: 'nonce',
            value: giji_improved_ajax.nonce
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
        
        setTimeout(function() {
            button.prop('disabled', false).text('CSVエクスポート');
        }, 2000);
    });
    
    // 公開件数の最大値を動的に設定
    function updatePublishCountMax() {
        var draftCount = parseInt($('.giji-improved-stat-number').first().text()) || 0;
        $('#giji-improved-publish-count').attr('max', draftCount);
        if (draftCount === 0) {
            $('#giji-improved-manual-publish').prop('disabled', true);
        } else {
            $('#giji-improved-manual-publish').prop('disabled', false);
        }
    }
    
    updatePublishCountMax();
    
    // フォーム送信時の処理
    $('form').on('submit', function() {
        var form = $(this);
        var submitButton = form.find('input[type="submit"]');
        
        // 二重送信防止
        submitButton.prop('disabled', true);
        
        setTimeout(function() {
            submitButton.prop('disabled', false);
        }, 3000);
    });
    
    // 文字数カウンター
    function setupCharacterCounter(inputSelector, maxLength) {
        var input = $(inputSelector);
        if (input.length > 0) {
            var counter = $('<div class="character-counter" style="font-size: 12px; color: #666; margin-top: 5px; text-align: right;"></div>');
            input.after(counter);
            
            function updateCounter() {
                var length = input.val().length;
                counter.text(length + '/' + maxLength + ' 文字');
                
                if (length > maxLength) {
                    counter.css('color', '#dc3232');
                    input.css('border-color', '#dc3232');
                } else if (length > maxLength * 0.9) {
                    counter.css('color', '#f57c00');
                    input.css('border-color', '#f57c00');
                } else {
                    counter.css('color', '#666');
                    input.css('border-color', '#ddd');
                }
            }
            
            input.on('input keyup', updateCounter);
            updateCounter();
        }
    }
    
    // プロンプト入力の文字数カウンター
    setupCharacterCounter('textarea[name*="prompt"]', 2000);
    setupCharacterCounter('#giji-improved-import-keyword', 50);
    
    // 自動保存機能（プロンプト用）
    var autoSaveTimeout;
    $('textarea[name*="prompt"]').on('input', function() {
        clearTimeout(autoSaveTimeout);
        var textarea = $(this);
        
        autoSaveTimeout = setTimeout(function() {
            // 自動保存のロジック（必要に応じて実装）
            console.log('Auto-saving prompt: ' + textarea.attr('name'));
        }, 5000);
    });
    
    // リアルタイム統計更新
    function updateStatistics() {
        // 統計の更新（ページリロードなしで更新する場合）
        setTimeout(function() {
            location.reload();
        }, 2000);
    }
    
    // ユーティリティ関数
    function showLoading(element, message) {
        var html = '<div class="giji-improved-result loading">';
        html += '<span class="giji-improved-loading"></span>';
        html += escapeHtml(message || '処理中...');
        html += '</div>';
        element.html(html);
    }
    
    function showSuccess(element, message) {
        element.html('<div class="giji-improved-result success">' + message + '</div>');
    }
    
    function showError(element, message) {
        element.html('<div class="giji-improved-result error">' + escapeHtml(message) + '</div>');
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
    
    // ツールチップの初期化
    $('[title]').each(function() {
        $(this).tooltip({
            position: { my: "left+15 center", at: "right center" },
            show: { duration: 200 },
            hide: { duration: 100 }
        });
    });
    
    // アコーディオンの実装
    $(document).on('click', 'details summary', function() {
        var details = $(this).parent();
        setTimeout(function() {
            if (details.prop('open')) {
                details.find('ul, div').hide().slideDown(300);
            }
        }, 10);
    });
    
    // 入力値の検証
    $('input[type="number"]').on('input', function() {
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
    
    // キーボードショートカット
    $(document).on('keydown', function(e) {
        // Ctrl+S で設定保存
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            var submitButton = $('input[type="submit"]:visible').first();
            if (submitButton.length) {
                submitButton.click();
            }
        }
    });
    
    // ページ離脱時の確認（未保存の変更がある場合）
    var hasUnsavedChanges = false;
    
    $('input, textarea, select').on('change', function() {
        hasUnsavedChanges = true;
    });
    
    $('form').on('submit', function() {
        hasUnsavedChanges = false;
    });
    
    $(window).on('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            var confirmationMessage = '未保存の変更があります。ページを離れますか？';
            e.returnValue = confirmationMessage;
            return confirmationMessage;
        }
    });
    
    // 初期化完了のログ
    console.log('Grant Insight Jグランツ・インポーター改善版 管理画面スクリプト初期化完了');
});