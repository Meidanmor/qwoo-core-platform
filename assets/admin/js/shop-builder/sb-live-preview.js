(function ($) {
    'use strict';

    /**
     * PROTOCOL (postMessage), namespaced with `source` on both ends so it
     * can't be confused with other postMessage traffic on either page:
     *
     *   iframe -> admin   { source: 'qwoo-frontend', type: 'ready' }
     *       Sent once the frontend's editor bridge has mounted its message
     *       listener. Triggers an immediate state push and flips the status
     *       indicator to "Live".
     *
     *   admin -> iframe   { source: 'qwoo-admin', type: 'state', payload }
     *       `payload` is the full `shop_builder_options` tree as it
     *       currently stands in the form — i.e. NOT yet saved to the DB —
     *       shaped exactly like get_option('shop_builder_options') would
     *       return it. Sent on open, on tab switch, and (debounced) on every
     *       field change while the panel is open.
     *
     * The iframe is loaded from the real frontend (same URLs as the
     * existing per-tab "Preview" links) with two query params added:
     *   - qwoo_editor=1        tells the frontend to skip its normal
     *                          REST/GitHub-JSON fetch and wait for `state`
     *                          messages instead.
     *   - admin_origin=<...>   the wp-admin origin, so the frontend knows
     *                          which origin to trust for incoming messages
     *                          and which origin to target when replying —
     *                          without hardcoding it on either side.
     *
     * Until the frontend implements its half of this bridge, the iframe
     * still works: it just shows the normal published/preview page (same
     * as the existing "Preview ↗" link), and state pushes are silently
     * ignored by whatever is on the other end.
     */

    const QWOO_EDITOR_PARAM = 'qwoo_editor';
    const STATE_DEBOUNCE_MS = 200;

    // Media fields are stored in the form as a hidden attachment-ID input
    // (e.g. "home[hero_image_id]") — the frontend has no way to resolve an
    // ID to a URL itself (only wp-admin can talk to the media library), and
    // the published JSON gets URLs from the GitHub push.
    // Since the WP media picker (sb-media-uploads.js) already keeps a
    // preview <img> in sync with whichever attachment is currently chosen
    // (on upload, on remove, and server-rendered on page load), that <img>
    // is a reliable live source for "what URL does the current ID resolve
    // to" — no extra REST calls needed. Each entry adds `resultKey`
    // (matching what the frontend components actually read, e.g.
    // `home.hero_image`) alongside the existing raw `*_id` field.
    const MEDIA_PREVIEW_FIELDS = [
        { parentPath: [ 'home' ], idSelector: '#hero-image-id', previewSelector: '#hero-image-preview', resultKey: 'hero_image' },
        { parentPath: [ 'branding' ], idSelector: '#logo-id', previewSelector: '#logo-preview', resultKey: 'logo' }
    ];

    // Human-readable labels for the blocking loading overlay (e.g. "Loading
    // product page…"), keyed the same as shopBuilder.previewUrls.
    const TAB_LABELS = {
        'tab-header':   'header',
        'tab-footer':   'footer',
        'tab-home':     'home page',
        'tab-shop':     'shop page',
        'tab-category': 'category page',
        'tab-product':  'product page',
        'tab-checkout': 'checkout page',
        'tab-branding': 'branding preview',
        'tab-pwa':      'PWA settings preview',
        'tab-contact':  'contact button preview'
    };

    let panelOpen = false;
    let iframeReady = false;
    let debounceTimer = null;

    Object.assign(window.ShopBuilder, {

        initLivePreview: function () {
            const self = this;
            const $panel  = $('#qwoo-live-preview-panel');
            const $iframe = $('#qwoo-live-preview-iframe');
            const $toggle = $('#toggle-live-preview');

            if (!$panel.length || !$iframe.length || !$toggle.length) return;

            $toggle.on('click', function (e) {
                e.preventDefault();
                if ($toggle.hasClass('disabled')) {
                    alert('Set a Frontend Domain in Technical Settings first.');
                    return;
                }
                self.openLivePreview();
            });

            $('#close-live-preview').on('click', function (e) {
                e.preventDefault();
                self.closeLivePreview();
            });

            // Keep the preview pointed at whichever tab is active, same
            // domain-of-truth as updatePreviewLink() in sb-core.js — this
            // just also reloads the iframe to the matching URL when the
            // panel happens to be open.
            $(document).on('click', '.nav-tab', function () {
                if (!panelOpen) return;
                const target = $(this).data('target');
                self.loadLivePreviewForTab(target);
            });

            $iframe.on('load', function () {
                // Fallback for a frontend that hasn't implemented the
                // 'ready' handshake yet: push state once anyway shortly
                // after load. Harmless no-op if nothing is listening.
                iframeReady = false;
                self.setLivePreviewStatus('Connecting…');
                self.hideLivePreviewLoading();
                setTimeout(function () {
                    self.postLivePreviewState();
                }, 400);
            });

            $(window).on('message', function (e) {
                const evt = e.originalEvent;
                const expectedOrigin = self.frontendOrigin();
                if (!expectedOrigin || evt.origin !== expectedOrigin) return;

                const data = evt.data;
                if (!data || data.source !== 'qwoo-frontend') return;

                if (data.type === 'ready') {
                    iframeReady = true;
                    self.setLivePreviewStatus('Live');
                    self.postLivePreviewState();
                }
            });

            // Debounced re-send on any field change while the panel is open —
            // plain form fields, and every section builder edit (which fires
            // 'qwoo:builder-change', see builderChanged() in sb-builder.js).
            const schedule = function () {
                if (!panelOpen) return;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    self.postLivePreviewState();
                }, STATE_DEBOUNCE_MS);
            };
            $(document).on('input change', '.shop-builder-form :input', schedule);
            $(document).on('qwoo:builder-change', schedule);
        },

        /* -------------------------
           Panel open/close
           ------------------------- */
        openLivePreview: function () {
            const $panel = $('#qwoo-live-preview-panel');
            panelOpen = true;
            $panel.addClass('is-open').attr('aria-hidden', 'false');
            $('body').addClass('qwoo-live-preview-open');

            const target = $('.nav-tab.nav-tab-active').data('target') || 'tab-header';
            this.loadLivePreviewForTab(target);
        },

        closeLivePreview: function () {
            const $panel = $('#qwoo-live-preview-panel');
            panelOpen = false;
            $panel.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('qwoo-live-preview-open');
        },

        loadLivePreviewForTab: function (target) {
            const urls = (window.shopBuilder && shopBuilder.previewUrls) || {};
            const baseUrl = urls[target] || urls['tab-header'] || '';
            if (!baseUrl) return;

            const nextSrc = this.buildLivePreviewSrc(baseUrl);
            const $iframe = $('#qwoo-live-preview-iframe');

            // Avoid an unnecessary reload (and the resulting brief flash /
            // loss of scroll position) if we're already showing this page.
            if ($iframe.attr('data-base-url') === baseUrl) return;

            iframeReady = false;
            const label = TAB_LABELS[target] || 'preview';
            this.setLivePreviewStatus('Loading…');
            this.showLivePreviewLoading('Loading ' + label + '…');
            $iframe.attr('data-base-url', baseUrl).attr('src', nextSrc);
        },

        showLivePreviewLoading: function (text) {
            $('#qwoo-live-preview-loading-text').text(text);
            $('#qwoo-live-preview-loading').prop('hidden', false);
        },

        hideLivePreviewLoading: function () {
            $('#qwoo-live-preview-loading').prop('hidden', true);
        },

        buildLivePreviewSrc: function (baseUrl) {
            const separator = baseUrl.indexOf('?') === -1 ? '?' : '&';
            const adminOrigin = encodeURIComponent(window.location.origin);
            return baseUrl + separator + QWOO_EDITOR_PARAM + '=1&admin_origin=' + adminOrigin;
        },

        frontendOrigin: function () {
            const domain = (window.shopBuilder && shopBuilder.frontendDomain) || '';
            if (!domain) return '';
            try {
                return new URL(domain).origin;
            } catch (err) {
                return '';
            }
        },

        setLivePreviewStatus: function (text) {
            $('#qwoo-live-preview-status').text(text);
        },

        /* -------------------------
           Draft state serialization + postMessage
           ------------------------- */
        postLivePreviewState: function () {
            if (!panelOpen) return;

            const $iframe = $('#qwoo-live-preview-iframe');
            const win = $iframe.get(0) && $iframe.get(0).contentWindow;
            const targetOrigin = this.frontendOrigin();
            if (!win || !targetOrigin) return;

            win.postMessage(
                { source: 'qwoo-admin', type: 'state', payload: this.serializeDraftState() },
                targetOrigin
            );
        },

        /**
         * Reads the ENTIRE form (every tab, not just the visible one — hidden
         * tabs' inputs are still present in the DOM) and rebuilds the same
         * nested shape PHP's bracket-notation names would produce, e.g.
         * "shop_builder_options[home][sections][0][data][title]" becomes
         * { home: { sections: [ { data: { title: ... } } ] } }.
         *
         * This mirrors what $_POST parsing does server-side closely enough
         * for live-preview purposes: jQuery's serializeArray() already only
         * includes checked/selected controls, matching how unchecked
         * checkboxes and non-selected options are omitted from a real submit.
         */
        serializeDraftState: function () {
            const root = {};
            $('form.shop-builder-form').serializeArray().forEach(function (field) {
                if (field.name.indexOf('shop_builder_options[') !== 0) return;
                ShopBuilder._setDraftPath(root, ShopBuilder._parseFieldName(field.name), field.value);
            });
            const options = ShopBuilder._normalizeDraftArrays(root).shop_builder_options || {};
            ShopBuilder._applyMediaPreviewUrls(options);

            // Section builder pages, already in the exact public shape the
            // published JSON will have (see getPublicPages() in sb-builder.js).
            if (ShopBuilder.getPublicPages) {
                const pages = ShopBuilder.getPublicPages();
                Object.keys(pages).forEach(function (page) {
                    if (typeof options[page] !== 'object' || options[page] === null) options[page] = {};
                    options[page].sections = pages[page];
                });
            }
            return options;
        },

        // Adds a resolved-URL key (e.g. options.home.hero_image) next to
        // each raw *_id field, read straight from that field's preview
        // <img>. Keyed off the HIDDEN ID INPUT's value rather than the
        // preview <img>'s DOM visibility — jQuery's :visible check returns
        // false for anything inside a display:none ancestor, which is
        // exactly the state of every tab's content except whichever one is
        // currently active. Editing a field on the Header tab, for
        // instance, would otherwise silently drop `home.hero_image` from
        // every payload sent while the Homepage tab isn't the visible one,
        // since Home's own preview <img> reads as "not visible" the entire
        // time. The <img>'s `src` attribute itself is unaffected by an
        // ancestor's display:none, so it's still safe to read directly.
        _applyMediaPreviewUrls: function (options) {
            MEDIA_PREVIEW_FIELDS.forEach(function (field) {
                let parent = options;
                for (let i = 0; i < field.parentPath.length; i++) {
                    const key = field.parentPath[i];
                    if (typeof parent[key] !== 'object' || parent[key] === null) parent[key] = {};
                    parent = parent[key];
                }

                const hasImage = !!$(field.idSelector).val();
                if (!hasImage) return; // no attachment selected — leave the key unset (see note above about src="")

                const src = $(field.previewSelector).attr('src');
                if (src) parent[field.resultKey] = src;
            });
            // Section/block images are resolved by the builder itself
            // (ShopBuilder.Fields.output()), not here.
        },

        // "a[b][c][0][d][]" -> ['a','b','c','0','d','']
        _parseFieldName: function (name) {
            const parts = [];
            const re = /^([^\[\]]+)|\[([^\]]*)\]/g;
            let m;
            while ((m = re.exec(name)) !== null) {
                parts.push(m[1] !== undefined ? m[1] : m[2]);
            }
            return parts;
        },

        // Writes `value` into `root` along `parts`, creating plain objects
        // along the way. A trailing '' segment (from a "[]" suffix) becomes
        // an auto-incrementing string index within its own fresh object, so
        // repeated multi-select entries stack up as 0,1,2... — later
        // normalized into a real array by _normalizeDraftArrays().
        _setDraftPath: function (root, parts, value) {
            let cur = root;
            for (let i = 0; i < parts.length; i++) {
                let key = parts[i];
                const isLast = i === parts.length - 1;
                if (key === '') key = String(Object.keys(cur).length);

                if (isLast) {
                    cur[key] = value;
                } else {
                    if (typeof cur[key] !== 'object' || cur[key] === null) cur[key] = {};
                    cur = cur[key];
                }
            }
        },

        // Recursively converts any object whose keys are exactly "0".."n-1"
        // (dense, sequential from zero — i.e. everything sections[], its
        // items[], and any [] multi-select built) into a real JS array, so
        // the frontend receives arrays where it expects arrays.
        _normalizeDraftArrays: function (node) {
            if (Array.isArray(node)) {
                return node.map(ShopBuilder._normalizeDraftArrays);
            }
            if (node && typeof node === 'object') {
                const keys = Object.keys(node);
                const isDenseArray = keys.length > 0 && keys.every(function (k, idx) { return k === String(idx); });
                const entries = keys.map(function (k) { return [k, ShopBuilder._normalizeDraftArrays(node[k])]; });

                if (isDenseArray) {
                    return entries.map(function (pair) { return pair[1]; });
                }
                const out = {};
                entries.forEach(function (pair) { out[pair[0]] = pair[1]; });
                return out;
            }
            return node;
        }
    });

})(jQuery);