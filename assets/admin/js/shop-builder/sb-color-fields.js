(function ($) {
    'use strict';

    /* =====================================================================
       Color fields

       Each control = a swatch button (opens the picker), an editable hex
       field, and a hidden .qwoo-color-value input holding the real value:
         ''             unset (the frontend uses its own default)
         '#rrggbb'      opaque color
         '#rrggbbaa'    color with opacity (only where data-allow-alpha="1")
         'global:{key}' reference to a Branding palette color
       Every change triggers 'change' on the hidden input — that's what the
       plain form fields and the section builder (sb-builder-fields.js)
       listen to.

       The picker is our own (not <input type="color">): the native one
       has no opacity and its RGB/HEX mode can't be chosen from script. One
       shared popover is attached to <body> and bound to whichever control
       opened it, so it's never clipped by a scrolling/overflow container.
       ===================================================================== */

    const clamp = (n, min, max) => Math.min(max, Math.max(min, n));
    const toHex2 = (n) => Math.round(clamp(n, 0, 255)).toString(16).padStart(2, '0');

    /**
     * Normalizes user/stored hex input. Accepts #rgb, #rgba, #rrggbb,
     * #rrggbbaa (with or without '#'). Returns lowercase #rrggbb, or
     * #rrggbbaa when there's transparency and alpha is allowed; null if
     * invalid. A fully opaque 8-digit value collapses to 6 digits.
     */
    function normalizeHex(raw, allowAlpha) {
        let v = String(raw || '').trim().toLowerCase();
        if (v && v[0] !== '#') v = '#' + v;
        if (/^#[0-9a-f]{3,4}$/.test(v)) v = '#' + v.slice(1).split('').map((c) => c + c).join('');
        if (!/^#([0-9a-f]{6}|[0-9a-f]{8})$/.test(v)) return null;
        if (v.length === 9 && (v.slice(7) === 'ff' || !allowAlpha)) v = v.slice(0, 7);
        return v;
    }

    function hexToRgba(hex) {
        const v = normalizeHex(hex, true);
        if (!v) return null;
        return {
            r: parseInt(v.slice(1, 3), 16),
            g: parseInt(v.slice(3, 5), 16),
            b: parseInt(v.slice(5, 7), 16),
            a: v.length === 9 ? parseInt(v.slice(7, 9), 16) / 255 : 1
        };
    }

    function rgbaToHex(r, g, b, a) {
        return '#' + toHex2(r) + toHex2(g) + toHex2(b) + (a < 1 ? toHex2(a * 255) : '');
    }

    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        let h = 0;
        if (d) {
            if (max === r) h = ((g - b) / d) % 6;
            else if (max === g) h = (b - r) / d + 2;
            else h = (r - g) / d + 4;
            h *= 60;
            if (h < 0) h += 360;
        }
        return { h: h, s: max ? d / max : 0, v: max };
    }

    function hsvToRgb(h, s, v) {
        const c = v * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = v - c;
        let r = 0, g = 0, b = 0;
        if (h < 60) { r = c; g = x; } else if (h < 120) { r = x; g = c; } else if (h < 180) { g = c; b = x; }
        else if (h < 240) { g = x; b = c; } else if (h < 300) { r = x; b = c; } else { r = c; b = x; }
        return { r: (r + m) * 255, g: (g + m) * 255, b: (b + m) * 255 };
    }

    /** The concrete color a value paints with (global refs resolved), or ''. */
    function resolvedColor(value) {
        if (typeof value === 'string' && value.indexOf('global:') === 0) {
            const colors = (window.shopBuilder && shopBuilder.globalColors) || {};
            return colors[value.slice(7)] || '';
        }
        return normalizeHex(value, true) || '';
    }

    /* ---------------------------------------------------------------------
       Shared picker popover
       --------------------------------------------------------------------- */

    const picker = {
        $el: null,
        $control: null,
        state: { h: 0, s: 0, v: 0, a: 1 },
        allowAlpha: true,

        build: function () {
            if (this.$el) return this.$el;
            const hasEyeDropper = typeof window.EyeDropper === 'function';
            this.$el = $(`
                <div class="qwoo-cp" role="dialog" aria-label="Color picker" hidden>
                    <div class="qwoo-cp__sv" tabindex="0" role="slider" aria-label="Saturation and brightness">
                        <div class="qwoo-cp__sv-white"></div><div class="qwoo-cp__sv-black"></div>
                        <span class="qwoo-cp__sv-thumb"></span>
                    </div>
                    <div class="qwoo-cp__row">
                        <div class="qwoo-cp__sliders">
                            <div class="qwoo-cp__hue" tabindex="0" role="slider" aria-label="Hue" aria-valuemin="0" aria-valuemax="360">
                                <span class="qwoo-cp__thumb"></span>
                            </div>
                            <div class="qwoo-cp__alpha" tabindex="0" role="slider" aria-label="Opacity" aria-valuemin="0" aria-valuemax="100">
                                <div class="qwoo-cp__alpha-fill"></div>
                                <span class="qwoo-cp__thumb"></span>
                            </div>
                        </div>
                        <span class="qwoo-cp__preview"><span></span></span>
                    </div>
                    <div class="qwoo-cp__inputs">
                        <label class="qwoo-cp__hex-label">HEX
                            <input type="text" class="qwoo-cp__hex" spellcheck="false" autocomplete="off" maxlength="9" />
                        </label>
                        <label class="qwoo-cp__alpha-label">Opacity
                            <span><input type="number" class="qwoo-cp__alpha-num" min="0" max="100" step="1" />%</span>
                        </label>
                        ${hasEyeDropper ? '<button type="button" class="button qwoo-cp__eyedropper" title="Pick a color from the screen"><span class="dashicons dashicons-admin-customizer"></span></button>' : ''}
                    </div>
                    <div class="qwoo-cp__globals"></div>
                    <div class="qwoo-cp__footer">
                        <button type="button" class="button-link qwoo-cp__clear">Clear (default)</button>
                        <button type="button" class="button button-primary qwoo-cp__done">Done</button>
                    </div>
                </div>`);
            $('body').append(this.$el);
            this.bind();
            return this.$el;
        },

        open: function ($control) {
            this.build();
            if (this.$control && this.$control[0] === $control[0] && !this.$el.prop('hidden')) {
                this.close();
                return;
            }
            this.$control = $control;
            this.allowAlpha = $control.attr('data-allow-alpha') !== '0';
            this.$el.toggleClass('qwoo-cp--no-alpha', !this.allowAlpha);

            // Global chips (only where the control offers them).
            const allowGlobal = $control.attr('data-allow-global') !== '0';
            const colors = (window.shopBuilder && shopBuilder.globalColors) || {};
            const keys = (window.shopBuilder && shopBuilder.globalColorKeys) || {};
            const current = $control.find('.qwoo-color-value').val();
            this.$el.find('.qwoo-cp__globals').html(allowGlobal ? Object.keys(keys).map(function (key) {
                const active = current === 'global:' + key ? ' is-active' : '';
                return `<button type="button" class="qwoo-cp__global${active}" data-global-key="${ShopBuilder.escAttr(key)}"
                            style="--c:${ShopBuilder.escAttr(colors[key] || '#ccc')}" title="${ShopBuilder.escAttr(keys[key].label)} (global — follows the Branding palette)">
                            <span></span>${ShopBuilder.escHtml(keys[key].label)}</button>`;
            }).join('') : '').prop('hidden', !allowGlobal);

            const rgba = hexToRgba(resolvedColor(current)) || hexToRgba($control.attr('data-seed') || '#000000');
            this.state = Object.assign(rgbToHsv(rgba.r, rgba.g, rgba.b), { a: current ? rgba.a : 1 });
            this.render(false);

            this.$el.prop('hidden', false);
            this.position();
            $control.find('.qwoo-color-swatch-btn').attr('aria-expanded', 'true');
            this.$el.find('.qwoo-cp__hex').trigger('focus').trigger('select');
        },

        close: function () {
            if (!this.$el || this.$el.prop('hidden')) return;
            this.$el.prop('hidden', true);
            if (this.$control) {
                this.$control.find('.qwoo-color-swatch-btn').attr('aria-expanded', 'false').trigger('focus');
            }
            this.$control = null;
        },

        position: function () {
            if (!this.$control) return;
            const anchor = this.$control.find('.qwoo-color-swatch-btn')[0].getBoundingClientRect();
            const el = this.$el[0];
            const w = el.offsetWidth, h = el.offsetHeight, gap = 8;
            let top = anchor.bottom + gap;
            if (top + h > window.innerHeight - 8 && anchor.top - gap - h > 8) top = anchor.top - gap - h; // flip up
            const left = clamp(anchor.left, 8, window.innerWidth - w - 8);
            el.style.top = Math.max(8, top) + 'px';
            el.style.left = left + 'px';
        },

        currentHex: function () {
            const rgb = hsvToRgb(this.state.h, this.state.s, this.state.v);
            return rgbaToHex(rgb.r, rgb.g, rgb.b, this.allowAlpha ? this.state.a : 1);
        },

        /**
         * Repaints the popover; `commit` also writes the value to the control.
         * The input currently being typed in is never overwritten.
         */
        render: function (commit) {
            const st = this.state;
            const rgb = hsvToRgb(st.h, st.s, st.v);
            const solid = `rgb(${Math.round(rgb.r)},${Math.round(rgb.g)},${Math.round(rgb.b)})`;
            const hex = this.currentHex();
            const $el = this.$el;

            $el.find('.qwoo-cp__sv').css('background-color', `hsl(${st.h},100%,50%)`)
                .attr('aria-valuetext', `saturation ${Math.round(st.s * 100)}%, brightness ${Math.round(st.v * 100)}%`);
            $el.find('.qwoo-cp__sv-thumb').css({ left: (st.s * 100) + '%', top: ((1 - st.v) * 100) + '%', background: solid });
            $el.find('.qwoo-cp__hue').attr('aria-valuenow', Math.round(st.h)).find('.qwoo-cp__thumb').css('left', (st.h / 360 * 100) + '%');
            $el.find('.qwoo-cp__alpha-fill').css('background', `linear-gradient(to right, rgba(${Math.round(rgb.r)},${Math.round(rgb.g)},${Math.round(rgb.b)},0), ${solid})`);
            $el.find('.qwoo-cp__alpha').attr('aria-valuenow', Math.round(st.a * 100)).find('.qwoo-cp__thumb').css('left', (st.a * 100) + '%');
            $el.find('.qwoo-cp__preview span').css('background', hex);

            const $hex = $el.find('.qwoo-cp__hex');
            if (!(document.activeElement === $hex[0])) $hex.val(hex.toUpperCase());
            const $num = $el.find('.qwoo-cp__alpha-num');
            if (!(document.activeElement === $num[0])) $num.val(Math.round(st.a * 100));

            if (commit && this.$control) {
                ShopBuilder.setColorValue(this.$control, hex);
                $el.find('.qwoo-cp__global').removeClass('is-active');
            }
        },

        setFromHex: function (hex) {
            const rgba = hexToRgba(hex);
            if (!rgba) return false;
            this.state = Object.assign(rgbToHsv(rgba.r, rgba.g, rgba.b), { a: this.allowAlpha ? rgba.a : 1 });
            return true;
        },

        /** Pointer dragging on the SV area / hue / alpha tracks. */
        drag: function (e, target) {
            const el = e.currentTarget;
            const update = (ev) => {
                const r = el.getBoundingClientRect();
                const x = clamp((ev.clientX - r.left) / r.width, 0, 1);
                const y = clamp((ev.clientY - r.top) / r.height, 0, 1);
                if (target === 'sv') { this.state.s = x; this.state.v = 1 - y; }
                if (target === 'hue') this.state.h = x * 360 % 360;
                if (target === 'alpha') this.state.a = Math.round(x * 100) / 100;
                this.render(true);
            };
            el.setPointerCapture(e.pointerId);
            update(e);
            const move = (ev) => update(ev);
            const up = () => { el.removeEventListener('pointermove', move); el.removeEventListener('pointerup', up); el.removeEventListener('pointercancel', up); };
            el.addEventListener('pointermove', move);
            el.addEventListener('pointerup', up);
            el.addEventListener('pointercancel', up);
        },

        bind: function () {
            const self = this;
            const $el = this.$el;

            $el.on('pointerdown', '.qwoo-cp__sv', function (e) { e.preventDefault(); this.focus(); self.drag(e.originalEvent, 'sv'); });
            $el.on('pointerdown', '.qwoo-cp__hue', function (e) { e.preventDefault(); this.focus(); self.drag(e.originalEvent, 'hue'); });
            $el.on('pointerdown', '.qwoo-cp__alpha', function (e) { e.preventDefault(); this.focus(); self.drag(e.originalEvent, 'alpha'); });

            // Keyboard: arrows nudge (Shift = bigger steps).
            $el.on('keydown', '.qwoo-cp__sv, .qwoo-cp__hue, .qwoo-cp__alpha', function (e) {
                const big = e.shiftKey ? 10 : 1;
                const dx = { ArrowLeft: -1, ArrowRight: 1 }[e.key] || 0;
                const dy = { ArrowUp: 1, ArrowDown: -1 }[e.key] || 0;
                if (!dx && !dy) return;
                e.preventDefault();
                const st = self.state;
                if ($(this).is('.qwoo-cp__sv')) { st.s = clamp(st.s + dx * 0.01 * big, 0, 1); st.v = clamp(st.v + dy * 0.01 * big, 0, 1); }
                if ($(this).is('.qwoo-cp__hue')) st.h = (st.h + (dx || dy) * big + 360) % 360;
                if ($(this).is('.qwoo-cp__alpha')) st.a = clamp(Math.round((st.a + (dx || dy) * 0.01 * big) * 100) / 100, 0, 1);
                self.render(true);
            });

            // Hex field: live while typing a complete value, reformat on blur.
            $el.on('input', '.qwoo-cp__hex', function () {
                if (self.setFromHex($(this).val())) self.render(true);
            });
            $el.on('blur', '.qwoo-cp__hex', function () { self.render(false); });
            $el.on('input', '.qwoo-cp__alpha-num', function () {
                const n = parseFloat($(this).val());
                if (!isFinite(n)) return;
                self.state.a = clamp(n, 0, 100) / 100;
                self.render(true);
            });
            $el.on('keydown', 'input', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); self.close(); }
            });

            $el.on('click', '.qwoo-cp__global', function () {
                if (!self.$control) return;
                const key = $(this).data('global-key');
                ShopBuilder.setColorValue(self.$control, 'global:' + key);
                self.setFromHex(resolvedColor('global:' + key));
                self.render(false);
                $el.find('.qwoo-cp__global').removeClass('is-active');
                $(this).addClass('is-active');
            });

            $el.on('click', '.qwoo-cp__eyedropper', function () {
                new window.EyeDropper().open().then(function (result) {
                    const alpha = self.state.a;
                    if (self.setFromHex(result.sRGBHex)) {
                        self.state.a = alpha; // keep the chosen opacity
                        self.render(true);
                    }
                }).catch(function () { /* cancelled */ });
            });

            $el.on('click', '.qwoo-cp__clear', function () {
                if (self.$control) ShopBuilder.setColorValue(self.$control, '');
                self.close();
            });
            $el.on('click', '.qwoo-cp__done', function () { self.close(); });

            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' && !$el.prop('hidden')) { e.preventDefault(); self.close(); }
            });
            $(document).on('pointerdown', function (e) {
                if ($el.prop('hidden')) return;
                if ($(e.target).closest('.qwoo-cp, .qwoo-color-swatch-btn').length) return;
                self.close();
            });
            $(window).on('resize', function () { if (!$el.prop('hidden')) self.position(); });
            // Scrolling the page/panels: keep the popover glued to its swatch.
            document.addEventListener('scroll', function () { if (!$el.prop('hidden')) self.position(); }, true);
        }
    };

    /* ---------------------------------------------------------------------
       Controls
       --------------------------------------------------------------------- */

    Object.assign(window.ShopBuilder, {

        /**
         * Builds one color control (jQuery element). `fullName` may be '' for
         * builder fields (not posted as form inputs); `value` is the initial
         * value. opts.allowGlobal / opts.allowAlpha default to true.
         */
        colorFieldTemplate: function (fullName, label, seed, value, opts) {
            opts = Object.assign({ allowGlobal: true, allowAlpha: true }, opts || {});
            const esc = ShopBuilder.escAttr;
            const nameAttr = fullName ? ` name="${esc(fullName)}"` : '';
            const html = `
        <div class="qwoo-color-field">
            ${label ? `<span class="field-label">${ShopBuilder.escHtml(label)}</span>` : ''}
            <span class="qwoo-color-control" data-global-key="" data-seed="${esc(seed || '#000000')}"
                  data-allow-alpha="${opts.allowAlpha ? '1' : '0'}" data-allow-global="${opts.allowGlobal ? '1' : '0'}">
                <button type="button" class="qwoo-color-swatch-btn" aria-label="${esc(label ? 'Pick ' + label : 'Pick color')}" aria-haspopup="dialog" aria-expanded="false"></button>
                <input type="hidden" class="qwoo-color-value"${nameAttr} value="${esc(value || '')}" />
                <input type="text" class="qwoo-color-hex" value="" placeholder="Default" spellcheck="false" autocomplete="off" maxlength="24" aria-label="Hex color" />
                <button type="button" class="button-link qwoo-color-reset" style="display:none;">Reset</button>
            </span>
        </div>`;
            const $el = $($.parseHTML(html.trim()));
            ShopBuilder.updateColorSwatch($el.find('.qwoo-color-control'), value || '');
            return $el;
        },

        /** Syncs a control's swatch / hex field with `value`. */
        updateColorSwatch: function ($control, value) {
            const keys = (window.shopBuilder && shopBuilder.globalColorKeys) || {};
            const isGlobal = typeof value === 'string' && value.indexOf('global:') === 0;
            const globalKey = isGlobal ? value.slice(7) : '';
            const color = resolvedColor(value);
            const $swatch = $control.find('.qwoo-color-swatch-btn');

            $swatch.toggleClass('qwoo-color-swatch-btn--unset', !color)
                .toggleClass('qwoo-color-swatch-btn--global', isGlobal);
            $swatch[0].style.setProperty('--swatch', color || 'transparent');

            const $hex = $control.find('.qwoo-color-hex');
            if (!(document.activeElement === $hex[0])) {
                $hex.val(isGlobal ? ((keys[globalKey] ? keys[globalKey].label : globalKey) + ' (global)') : (color ? color.toUpperCase() : ''));
            }
            $control.attr('data-global-key', globalKey);
            $control.find('.qwoo-color-reset').toggle(!!value);
        },

        /** Writes a new value into a control and notifies listeners. */
        setColorValue: function ($control, value) {
            $control.find('.qwoo-color-value').val(value).trigger('change');
            ShopBuilder.updateColorSwatch($control, value);

            // If this field IS a global color definition (not a reference to
            // one), update every swatch/chip referencing that key live.
            const name = $control.find('.qwoo-color-value').attr('name');
            const match = name && name.match(/\[branding\]\[global_colors\]\[([^\]]+)\]$/);
            const hex = normalizeHex(value, false);
            if (match && hex) ShopBuilder.refreshGlobalColor(match[1], hex);
        },

        bindColorFieldControls: function () {
            $(document).on('click', '.qwoo-color-swatch-btn', function (e) {
                e.preventDefault();
                picker.open($(this).closest('.qwoo-color-control'));
            });

            // Inline hex field: applied on Enter / leaving the field. Accepts
            // #rgb, #rgba, #rrggbb, #rrggbbaa (with or without '#'); empty
            // resets; anything else snaps back.
            $(document).on('focus', '.qwoo-color-hex', function () {
                const value = $(this).closest('.qwoo-color-control').find('.qwoo-color-value').val();
                if (value.indexOf('global:') === 0) $(this).val('').attr('placeholder', '#RRGGBB or #RRGGBBAA');
                $(this).trigger('select');
            });
            $(document).on('keydown', '.qwoo-color-hex', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('blur'); }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    $(this).val('__cancel__').trigger('blur');
                }
            });
            $(document).on('blur', '.qwoo-color-hex', function () {
                const $control = $(this).closest('.qwoo-color-control');
                const current = $control.find('.qwoo-color-value').val();
                const typed = String($(this).val() || '').trim();
                $(this).attr('placeholder', 'Default');

                let next = null;
                if (typed === '') next = current.indexOf('global:') === 0 ? current : '';
                else if (typed !== '__cancel__') next = normalizeHex(typed, $control.attr('data-allow-alpha') !== '0');

                if (next === null || next === current) {
                    ShopBuilder.updateColorSwatch($control, current); // snap back / reformat
                    return;
                }
                ShopBuilder.setColorValue($control, next);
            });

            $(document).on('click', '.qwoo-color-reset', function (e) {
                e.preventDefault();
                ShopBuilder.setColorValue($(this).closest('.qwoo-color-control'), '');
            });

            // Server-rendered controls (render_color_field() in PHP) start
            // with an empty hex field — fill them in from their stored value.
            $('.qwoo-color-control').each(function () {
                ShopBuilder.updateColorSwatch($(this), $(this).find('.qwoo-color-value').val());
            });
        },

        refreshGlobalColor: function (key, hex) {
            if (!window.shopBuilder) return;
            shopBuilder.globalColors = shopBuilder.globalColors || {};
            shopBuilder.globalColors[key] = hex;
            // Every control currently set to this global color.
            $(`.qwoo-color-control[data-global-key="${key}"]`).each(function () {
                this.querySelector('.qwoo-color-swatch-btn').style.setProperty('--swatch', hex);
            });
        }
    });

})(jQuery);
