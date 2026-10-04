(function ($) {
    'use strict';

    Object.assign(window.ShopBuilder, {

        /* -------------------------
        Generic single-image upload (wp.media) — used for hero image,
        header logo, and app icon. Keeps one implementation instead of
        three near-identical copies.
        ------------------------- */
        bindMediaUpload: function ( opts ) {
            // opts: { uploadBtn, removeBtn, hiddenInput, previewImg, title, changeLabel, selectLabel }
            let uploader;
            const $uploadBtn = $(opts.uploadBtn);
            const $removeBtn = $(opts.removeBtn);
            const $hidden = $(opts.hiddenInput);
            const $preview = $(opts.previewImg);

            $uploadBtn.on('click', function (e) {
                e.preventDefault();

                if (uploader) {
                    uploader.open();
                    return;
                }

                uploader = wp.media({
                    title: opts.title,
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                uploader.on('select', function () {
                    const attachment = uploader.state().get('selection').first().toJSON();
                    $hidden.val(attachment.id).trigger('change');
                    $preview.attr('src', attachment.url).show();
                    $uploadBtn.text(opts.changeLabel);
                    $removeBtn.show();
                });

                uploader.open();
            });

            $removeBtn.on('click', function (e) {
                e.preventDefault();
                $hidden.val('').trigger('change');
                $preview.hide();
                $uploadBtn.text(opts.selectLabel);
                $(this).hide();
            });
        },

        initHeroImageUpload: function () {
            this.bindMediaUpload({
                uploadBtn: '#hero-image-upload-btn',
                removeBtn: '#hero-image-remove-btn',
                hiddenInput: '#hero-image-id',
                previewImg: '#hero-image-preview',
                title: 'Select Hero Image',
                changeLabel: 'Change Image',
                selectLabel: 'Select Image'
            });
        },

        initLogoUpload: function () {
            this.bindMediaUpload({
                uploadBtn: '#logo-upload-btn',
                removeBtn: '#logo-remove-btn',
                hiddenInput: '#logo-id',
                previewImg: '#logo-preview',
                title: 'Select Header Logo',
                changeLabel: 'Change Logo',
                selectLabel: 'Select Logo'
            });
        },

        initAppIconUpload: function () {
            const self = this;

            $('#app-icon-upload-btn').on('click', function (e) {
                e.preventDefault();

                const uploader = wp.media({
                    title: 'Select App Icon (square, 512x512 or larger)',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                uploader.on('select', function () {
                    const attachment = uploader.state().get('selection').first().toJSON();
                    const w = attachment.width || 0;
                    const h = attachment.height || 0;

                    let warning = '';
                    if (w && h) {
                        const ratio = w / h;
                        if (ratio < 0.9 || ratio > 1.1) {
                            warning = 'Heads up: this image isn\'t square, it will be center-cropped.';
                        } else if (w < 512 || h < 512) {
                            warning = 'Heads up: image is smaller than the recommended 512x512 minimum.';
                        }
                    }

                    $('#app-icon-id').val(attachment.id);
                    $('#app-icon-preview').attr('src', attachment.url).show();
                    $('#app-icon-upload-btn').text('Change App Icon');
                    $('#app-icon-remove-btn').show();

                    if (warning) {
                        ShopBuilder.showStatus('⚠️ ' + warning, '#b45309', 6000, '#icon-gen-status');
                    }
                });

                uploader.open();
            });

            $('#app-icon-remove-btn').on('click', function (e) {
                e.preventDefault();
                $('#app-icon-id').val('');
                $('#app-icon-preview').hide();
                $('#app-icon-upload-btn').text('Select App Icon');
                $(this).hide();
            });
        },

        bindGenerateIcons: function () {
            $('#generate-icons-btn').on('click', function (e) {
                e.preventDefault();

                if (!$('#app-icon-id').val()) {
                    ShopBuilder.showStatus('❌ Select and save an App Icon first.', 'red', 5000, '#icon-gen-status');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('Generating...');
                ShopBuilder.showStatus('⏳ Generating icon set...', '#666', false, '#icon-gen-status');

                $.post(ajaxurl, {
                    action: 'shop_builder_generate_icons',
                    nonce: shopBuilder.nonce
                }, function (response) {
                    if (response.success) {
                        ShopBuilder.showStatus('✅ ' + response.data, 'green', 8000, '#icon-gen-status');
                    } else {
                        ShopBuilder.showStatus('❌ ' + (response.data || 'Unknown error'), 'red', 8000, '#icon-gen-status');
                    }
                }).fail(function () {
                    ShopBuilder.showStatus('❌ Connection error. Please try again.', 'red', 8000, '#icon-gen-status');
                }).always(function () {
                    $btn.prop('disabled', false).text('Generate & Push Icon Set to GitHub');
                });
            });
        }
    });

})(jQuery);
