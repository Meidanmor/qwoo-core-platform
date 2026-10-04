(function ($) {
    'use strict';

    Object.assign(window.ShopBuilder, {

        /* -------------------------
           Save Draft (shared: used by the form submit AND by "Push to Live",
           which now saves first so a draft save is no longer a required
           separate step before pushing).
           ------------------------- */
        saveDraft: function ( onSuccess, onError ) {
            // Make sure any open rich-text editor has flushed into state.
            if (window.tinymce) window.tinymce.triggerSave();

            // Regular fields (header, footer, branding, ...) post as form
            // inputs; the section builder's whole state goes as one JSON
            // string, so a large page can never hit PHP's max_input_vars.
            const $form = $('form.shop-builder-form');
            const data = $form.serialize()
                + '&sb_sections=' + encodeURIComponent(JSON.stringify(ShopBuilder.getBuilderPages()))
                + '&action=save_shop_builder_draft'
                + '&nonce=' + encodeURIComponent(shopBuilder.nonce);

            $.post(ajaxurl, data, function (response) {
                if (response.success) {
                    ShopBuilder.markClean();
                    if (typeof onSuccess === 'function') onSuccess(response);
                } else {
                    if (typeof onError === 'function') onError(response.data || 'Unknown error');
                }
            }).fail(function () {
                if (typeof onError === 'function') onError('Connection error. Please try again.');
            });
        },

        /* -------------------------
           Save Draft (AJAX form submit)
           ------------------------- */
        bindFormSubmit: function () {
            $('form.shop-builder-form').on('submit', function (e) {
                e.preventDefault();

                const $submitBtn = $(this).find('input[type="submit"]');

                $submitBtn.prop('disabled', true).val('Saving...');
                ShopBuilder.showStatus('⏳ Saving draft...', '#666', false);

                ShopBuilder.saveDraft(
                    function () {
                        $submitBtn.prop('disabled', false).val('Save Draft');
                        ShopBuilder.showStatus('✅ Draft saved!', 'green', 3000);
                    },
                    function (error) {
                        $submitBtn.prop('disabled', false).val('Save Draft');
                        ShopBuilder.showStatus('❌ Error: ' + error, 'red', 5000);
                    }
                );
            });
        },

        /* -------------------------
           Push to GitHub / Live
           ------------------------- */
        bindGithubPush: function () {
            $('#push-to-github').on('click', function (e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to push these settings to the LIVE website?')) return;

                const $btn = $(this);
                $btn.prop('disabled', true).text('Saving...');
                ShopBuilder.showStatus('⏳ Saving draft...', '#666', false);

                // Save whatever is currently in the form first — pushing used
                // to only publish whatever was last explicitly saved as a
                // draft, so any edits made since then (including ones on tabs
                // like Contact Button) were silently skipped unless "Save
                // Draft" was clicked first. Now push always publishes the
                // current on-screen state.
                ShopBuilder.saveDraft(
                    function () {
                        $btn.text('Pushing...');
                        ShopBuilder.showStatus('⏳ Syncing with GitHub...', '#666', false);

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'push_to_github',
                                nonce: shopBuilder.nonce
                            },
                            success: function (response) {
                                if (response.success) {
                                    const d = response.data;
                                    let html = '✅ Live website updated! ' + d.summary;

                                    if (d.updated_labels && d.updated_labels.length) {
                                        html += '<br><span style="font-weight:400;">Updated: ' + d.updated_labels.join(', ') + '</span>';
                                    }
                                    if (d.skipped_labels && d.skipped_labels.length) {
                                        html += '<br><span style="font-weight:400; color:#666;">No changes: ' + d.skipped_labels.join(', ') + '</span>';
                                    }
                                    if (d.failed_labels && d.failed_labels.length) {
                                        html += '<br><span style="font-weight:400; color:#b32d2e;">Failed: ' + d.failed_labels.join(', ') + '</span>';
                                    }

                                    ShopBuilder.showStatus(html, 'green', 8000);
                                } else {
                                    ShopBuilder.showStatus('❌ Error: ' + (response.data || 'Unknown error'), 'red', 0);
                                }
                            },
                            error: function () {
                                ShopBuilder.showStatus('❌ Connection error. Please try again.', 'red', 0);
                            },
                            complete: function () {
                                $btn.prop('disabled', false).text('Push to Live Website');
                            }
                        });
                    },
                    function (error) {
                        ShopBuilder.showStatus('❌ Could not save draft before pushing: ' + error, 'red', 0);
                        $btn.prop('disabled', false).text('Push to Live Website');
                    }
                );
            });
        }
    });

})(jQuery);