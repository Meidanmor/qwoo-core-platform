(function ($) {
    'use strict';

    $(function () {

        /* ── Localhost toggle ── */
        $('#localhost_enabled').on('change', function () {
            if ($(this).is(':checked')) {
                $('.qwoo-localhost-port').removeClass('qwoo-hidden');
            } else {
                $('.qwoo-localhost-port').addClass('qwoo-hidden');
            }
        });

        /* ── Reset API key ── */
        $(document).on('click', '.qwoo-reset-key', function () {
            const $btn     = $(this);
            const keyName  = $btn.data('key');
            const $field   = $btn.closest('.qwoo-key-field');

            if (!confirm('Reset this key? You will need to re-enter it.')) return;

            $btn.prop('disabled', true).text('Resetting…');

            $.post(qwooTech.ajax_url, {
                action:   'qwoo_reset_api_key',
                nonce:    qwooTech.nonce,
                key_name: keyName,
            }, function (res) {
                if (res.success) {
                    // Replace masked row with a fresh input
                    $field.find('.qwoo-masked-row').replaceWith(
                        '<input type="password"' +
                        ' name="qwoo_api_keys[' + keyName + ']"' +
                        ' class="qwoo-input qwoo-input--key"' +
                        ' placeholder="Enter new value"' +
                        ' autocomplete="new-password" />'
                    );
                    // Update badge
                    $field.find('.qwoo-badge')
                        .removeClass('qwoo-badge--set')
                        .addClass('qwoo-badge--unset')
                        .text('Not set');
                } else {
                    alert('Could not reset key: ' + (res.data || 'Unknown error'));
                    $btn.prop('disabled', false).text('Reset');
                }
            });
        });

        /* ── Install Stripe Gateway ── */
        $(document).on('click', '#qwoo-install-stripe', function () {
            const $btn    = $(this);
            const $text   = $btn.find('.qwoo-btn__text');
            const $loader = $btn.find('.qwoo-btn__loader');
            const $status = $btn.closest('.qwoo-stripe-status');

            $btn.prop('disabled', true);
            $text.hide();
            $loader.show();

            $.post(qwooTech.ajax_url, {
                action: 'qwoo_install_stripe_gateway',
                nonce:  qwooTech.nonce,
            }, function (res) {
                if (res.success) {
                    $status.find('.qwoo-badge')
                        .removeClass('qwoo-badge--unset')
                        .addClass('qwoo-badge--set')
                        .text('Installed & Active');
                    $btn.fadeOut();
                } else {
                    alert('Could not install Stripe gateway: ' + (res.data || 'Unknown error'));
                    $btn.prop('disabled', false);
                    $text.show();
                    $loader.hide();
                }
            }).fail(function () {
                alert('Request failed. Please try again.');
                $btn.prop('disabled', false);
                $text.show();
                $loader.hide();
            });
        });

        /* ── Generate VAPID keys ── */
        $(document).on('click', '#qwoo-generate-vapid', function () {
            const $btn    = $(this);
            const $text   = $btn.find('.qwoo-btn__text');
            const $loader = $btn.find('.qwoo-btn__loader');
            const $field  = $btn.closest('.qwoo-field');
            const hasExisting = $field.find('.qwoo-hint').text().indexOf('replace the existing pair') !== -1;

            if (hasExisting && !confirm('This will replace your existing VAPID keys. Devices already subscribed for web push will need to re-subscribe. Continue?')) {
                return;
            }

            $text.hide();
            $loader.show();
            $btn.prop('disabled', true);

            $.post(qwooTech.ajax_url, {
                action: 'qwoo_generate_vapid_keys',
                nonce:  qwooTech.nonce,
            }, function (res) {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);

                if (!res.success) {
                    showNotice('Could not generate VAPID keys: ' + (res.data || 'Unknown error'), 'error');
                    return;
                }

                showNotice(res.data.message || 'New VAPID keys generated and saved.', 'success');

                // Update the public key field to show the new value, visible + copyable
                const $publicField = $('.qwoo-key-field[data-key="VAPID_API_PUBLIC_KEY"]');
                $publicField.find('.qwoo-masked-row, input.qwoo-input--key').replaceWith(
                    '<div class="qwoo-masked-row">' +
                    '<input type="text" id="qwoo-vapid-public-key" class="qwoo-input qwoo-input--pubkey" value="' +
                    $('<div>').text(res.data.publicKey).html() +
                    '" readonly onclick="this.select();" />' +
                    '<button type="button" class="qwoo-btn qwoo-btn--ghost qwoo-copy-key" data-copy-target="qwoo-vapid-public-key">Copy</button>' +
                    '</div>'
                );
                $publicField.find('label .qwoo-badge')
                    .removeClass('qwoo-badge--unset')
                    .addClass('qwoo-badge--set')
                    .text('Saved');

                // Update the private key field to show it's saved (never expose its value)
                const $privateField = $('.qwoo-key-field[data-key="VAPID_API_PRIVATE_KEY"]');
                $privateField.find('.qwoo-masked-row, input.qwoo-input--key').replaceWith(
                    '<div class="qwoo-masked-row">' +
                    '<input type="text" class="qwoo-input qwoo-input--masked" value="••••••••••••••••" readonly />' +
                    '<button type="button" class="qwoo-btn qwoo-btn--ghost qwoo-reset-key" data-key="VAPID_API_PRIVATE_KEY">Reset</button>' +
                    '</div>'
                );
                $privateField.find('label .qwoo-badge')
                    .removeClass('qwoo-badge--unset')
                    .addClass('qwoo-badge--set')
                    .text('Saved');

                // Future clicks on Generate should warn about overwriting
                $field.find('.qwoo-hint').append(' Generating new keys will replace the existing pair, and any devices already subscribed for web push will need to re-subscribe.');
            }).fail(function () {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);
                showNotice('Request failed. Please try again.', 'error');
            });
        });

        /* ── Copy key to clipboard ── */
        $(document).on('click', '.qwoo-copy-key', function () {
            const $btn = $(this);
            const targetId = $btn.data('copy-target');
            const $input = $('#' + targetId);
            if (!$input.length) return;

            const value = $input.val();
            const done = function () {
                const original = $btn.text();
                $btn.text('Copied!');
                setTimeout(function () {
                    $btn.text(original);
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done).catch(function () {
                    $input.select();
                    document.execCommand('copy');
                    done();
                });
            } else {
                $input.select();
                document.execCommand('copy');
                done();
            }
        });

        /* ── Save settings ── */
        $('#qwoo-save-technical').on('click', function () {
            const $btn    = $(this);
            const $text   = $btn.find('.qwoo-btn__text');
            const $loader = $btn.find('.qwoo-btn__loader');

            $text.hide();
            $loader.show();
            $btn.prop('disabled', true);

            // Collect all named inputs from the page
            const data = {
                action: 'qwoo_save_technical_settings',
                nonce:  qwooTech.nonce,
            };

            // CORS fields
            data['qwoo_technical[frontend_domain]']   = $('#frontend_domain').val();
            data['qwoo_technical[localhost_enabled]'] = $('#localhost_enabled').is(':checked') ? 1 : '';
            data['qwoo_technical[localhost_port]']    = $('#localhost_port').val();
            data['qwoo_technical[push_email]']        = $('#push_email').val();
            data['qwoo_technical[block_frontend]']    = $('#block_frontend').is(':checked') ? 1 : '';
            data['qwoo_technical[abandoned_cart_threshold]']    = $('#abandoned_cart_threshold').val();

            // API key fields (only non-empty, non-masked ones)
            $('input.qwoo-input--key[name^="qwoo_api_keys"]').each(function () {
                const val = $(this).val().trim();
                if (val && val !== '••••••••••••••••') {
                    data[$(this).attr('name')] = val;
                }
            });

            $.post(qwooTech.ajax_url, data, function (res) {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);

                showNotice(
                    res.success ? res.data : (res.data || 'An error occurred.'),
                    res.success ? 'success' : 'error'
                );

                if (res.success) {
                    // Re-mask any newly saved key fields
                    $('input.qwoo-input--key[name^="qwoo_api_keys"]').each(function () {
                        if ($(this).val().trim()) {
                            const keyName = $(this).attr('name').match(/\[([^\]]+)\]/)[1];
                            $(this).closest('.qwoo-key-field').find('label .qwoo-badge')
                                .removeClass('qwoo-badge--unset')
                                .addClass('qwoo-badge--set')
                                .text('Saved');
                            $(this).replaceWith(
                                '<div class="qwoo-masked-row">' +
                                '<input type="text" class="qwoo-input qwoo-input--masked" value="••••••••••••••••" readonly />' +
                                '<button type="button" class="qwoo-btn qwoo-btn--ghost qwoo-reset-key" data-key="' + keyName + '">Reset</button>' +
                                '</div>'
                            );
                        }
                    });
                }
            }).fail(function () {
                $text.show();
                $loader.hide();
                $btn.prop('disabled', false);
                showNotice('Request failed. Please try again.', 'error');
            });
        });

        /* ── Notice helper ── */
        function showNotice(message, type) {
            const $notice = $('#qwoo-save-notice');
            $notice
                .removeClass('qwoo-notice--success qwoo-notice--error')
                .addClass('qwoo-notice--' + type)
                .text(message)
                .show();

            $('html, body').animate({ scrollTop: 0 }, 300);

            setTimeout(function () {
                $notice.fadeOut(400);
            }, 4000);
        }

    });

}(jQuery));

(function () {
    'use strict';

    var btn = document.getElementById('qwoo-regenerate-secret');
    if (btn) {
        btn.addEventListener('click', function () {
            if (!confirm('This immediately invalidates the current secret. Your storefront will stop working until you update PROXY_SHARED_SECRET on your frontend. Continue?')) {
                return;
            }
            btn.disabled = true;
            btn.textContent = 'Regenerating…';

            var body = new URLSearchParams();
            body.append('action', 'qwoo_regenerate_proxy_secret');
            body.append('nonce', qwooTech.nonce);

            fetch(qwooTech.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btn.disabled = false;
                    btn.textContent = 'Regenerate Secret';
                    if (res.success) {
                        document.getElementById('qwoo-proxy-secret-value').value = res.data.secret;
                        alert(res.data.message);
                    } else {
                        alert('Failed to regenerate: ' + (res.data || 'Unknown error'));
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = 'Regenerate Secret';
                    alert('Request failed — please try again.');
                });
        });
    }

    var syncBtn = document.getElementById('qwoo-sync-data');
    if (syncBtn) {
        var textEl = syncBtn.querySelector('.qwoo-btn__text');
        var loaderEl = syncBtn.querySelector('.qwoo-btn__loader');

        syncBtn.addEventListener('click', function () {
            syncBtn.disabled = true;
            if (textEl) textEl.style.display = 'none';
            if (loaderEl) loaderEl.style.display = '';

            var body = new URLSearchParams();
            body.append('action', 'qwoo_sync_data');
            body.append('nonce', qwooTech.nonce);

            fetch(qwooTech.ajax_url, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    syncBtn.disabled = false;
                    if (textEl) textEl.style.display = '';
                    if (loaderEl) loaderEl.style.display = 'none';

                    var data = res.data || {};
                    var results = data.results || {};
                    var summary = Object.keys(results).map(function (key) {
                        return key + ': ' + results[key];
                    }).join('\n');

                    if (res.success) {
                        alert('Sync complete:\n' + summary);
                    } else {
                        alert('Sync finished with errors:\n' + (summary || 'Unknown error'));
                    }
                })
                .catch(function () {
                    syncBtn.disabled = false;
                    if (textEl) textEl.style.display = '';
                    if (loaderEl) loaderEl.style.display = 'none';
                    alert('Request failed — please try again.');
                });
        });
    }

}());