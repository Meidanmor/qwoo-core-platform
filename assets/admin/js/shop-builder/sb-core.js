(function ($) {
    'use strict';

    /**
     * This file must load FIRST (see the enqueue order in
     * trait-sb-admin-page.php). It creates the shared `window.ShopBuilder`
     * object and owns init()/the step list. Every other sb-*.js file adds
     * more methods onto this same object via `Object.assign(ShopBuilder, {...})`
     * — they don't create their own namespace — so `this[step]()` inside
     * init() keeps working no matter which file a given method physically
     * lives in.
     */
    window.ShopBuilder = {

        init: function () {
            // Each setup step is isolated: if one throws (e.g. a selector
            // that doesn't match this page's markup, or a dependency that
            // didn't load), it's logged to the console instead of silently
            // aborting every step that runs after it — bindFormSubmit is
            // the most important one to never lose.
            const steps = [
                'bindEvents',
                'initHeroImageUpload',
                'initLogoUpload',
                'initAppIconUpload',
                'bindColorFieldControls',
                'initBuilder',
                'initHistory',
                'bindDirtyTracking',
                'bindFormSubmit',
                'bindGithubPush',
                'bindGenerateIcons',
                'bindContactMethods',
                'bindEntityPreviewLinks',
                'bindDeviceViewToggle',
                'initLivePreview'
            ];

            steps.forEach((step) => {
                try {
                    this[step]();
                } catch (err) {
                    console.error('ShopBuilder.' + step + '() failed:', err);
                }
            });
        },

        bindEntityPreviewLinks: function () {
            $(document).on('click', '.qwoo-entity-preview-btn', function (e) {
                e.preventDefault();

                const $btn = $(this);
                const $input = $btn.siblings('.qwoo-entity-preview-input');
                const slug = ($input.val() || '').trim();

                if (!slug) {
                    $input.trigger('focus');
                    return;
                }

                const domain = (window.shopBuilder && shopBuilder.frontendDomain) || '';
                if (!domain) {
                    alert('Set a Frontend Domain in Technical Settings first.');
                    return;
                }

                const route = $btn.data('route-template').replace('{slug}', encodeURIComponent(slug));
                window.open(domain + route, '_blank', 'noopener,noreferrer');
            });
        },

        /* -------------------------
           Tab Switching & Preview Link
           ------------------------- */
        bindEvents: function () {
            $(document).on('click', '.nav-tab', function (e) {
                e.preventDefault();
                const target = $(this).data('target');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide().removeClass('active');
                $('#' + target).show().addClass('active');

                ShopBuilder.updatePreviewLink(target);
            });

            $(document).on('click', '#preview-url-link.disabled', function (e) {
                e.preventDefault();
            });
        },

        // Swaps the "Open Preview" link + displayed URL to match whichever
        // tab is now active. Falls back to a disabled state with a hint
        // when no Frontend Domain is configured yet in Technical Settings.
        updatePreviewLink: function (target) {
            const urls = (shopBuilder && shopBuilder.previewUrls) || {};
            const url = urls[target] || '';
            const $link = $('#preview-url-link');
            const $display = $('#preview-url-display');

            if (url) {
                $display.text(url);
                $link.attr('href', url).removeClass('disabled');
            } else {
                $display.text('Set a Frontend Domain in Technical Settings first.');
                $link.attr('href', '#').addClass('disabled');
            }
        },

        /* -------------------------
           Desktop / Tablet / Mobile editing mode (top-bar device switcher)
           ------------------------- */
        // Every responsive builder field renders one pane per device
        // (.qwoo-device-only-desktop/-tablet/-mobile, see sb-builder-fields.js);
        // this flips which pane is visible via `data-device-view` on the
        // wrapper (CSS in shop-builder.css), and sizes the live preview
        // iframe to match via `data-qwoo-device` on <body>.
        bindDeviceViewToggle: function () {
            const $wrapper = $('.shop-builder-wrapper');
            const $switch = $('#qwoo-device-switch');
            if (!$wrapper.length || !$switch.length) return;

            let stored = null;
            try { stored = window.localStorage ? localStorage.getItem('qwooShopBuilderDeviceView') : null; } catch (e) { /* ignore */ }
            const initial = (stored === 'mobile' || stored === 'tablet') ? stored : 'desktop';
            this.setDeviceView($wrapper, $switch, initial);

            $switch.on('click', '.qwoo-device-switch-btn', function () {
                const device = $(this).data('device');
                ShopBuilder.setDeviceView($wrapper, $switch, device);
                if (window.localStorage) {
                    try { localStorage.setItem('qwooShopBuilderDeviceView', device); } catch (e) { /* ignore */ }
                }
            });
        },

        setDeviceView: function ($wrapper, $switch, device) {
            $wrapper.attr('data-device-view', device);
            $('body').attr('data-qwoo-device', device);
            // Rich-text editors in panes that just became visible need starting.
            if (ShopBuilder.Fields) ShopBuilder.Fields.initEditors($wrapper.find('.sb-tab-panel.is-active'));
            $switch.find('.qwoo-device-switch-btn')
                .removeClass('is-active')
                .filter(`[data-device="${device}"]`)
                .addClass('is-active');
        },

        /* -------------------------
           Helpers
           ------------------------- */
        escHtml: function (value) {
            return String(value === undefined || value === null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        escAttr: function (value) {
            return ShopBuilder.escHtml(value);
        },

        showStatus: function (html, color, autohide, selector) {
            const $status = $(selector || '#sync-status');
            $status.stop(true).show().html(html).css('color', color);
            if (autohide) {
                setTimeout(function () {
                    $status.fadeOut(400, function () { $status.show(); });
                }, autohide);
            }
        }
    };

    $(function () {
        ShopBuilder.init();
    });

})(jQuery);