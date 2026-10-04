(function ($) {
    'use strict';

    /**
     * Schema-driven field engine for the section builder.
     *
     * Every field control in the builder (section style, block content,
     * block style, repeater items) is rendered from the field specs defined
     * in PHP (class-sb-constants.php, localized as shopBuilder.blockSchema /
     * sectionStyleFields / blockCommonFields). Controls are bound to plain
     * JS objects — the builder's state — not to form input names:
     *
     *   buildScope(specs, obj, onChange, filter)
     *     returns a `.sbf-scope` element whose controls read from and write
     *     to `obj`. `onChange(key)` is called after every edit.
     *
     * Responsive fields render one pane per device (desktop / tablet /
     * mobile); CSS shows only the pane for the device currently selected in
     * the top bar (see .shop-builder-wrapper[data-device-view] in
     * shop-builder.css). An empty tablet/mobile value inherits from the next
     * larger device, and the control's placeholder shows what it inherits.
     *
     * output() mirrors output_fields() in trait-sb-sanitizers.php so the
     * live preview receives exactly the shape the published JSON will have.
     */

    const DEVICES = ['desktop', 'tablet', 'mobile'];
    const DEVICE_LABELS = { desktop: 'Desktop', tablet: 'Tablet', mobile: 'Mobile' };
    const DEVICE_ICONS = { desktop: 'dashicons-desktop', tablet: 'dashicons-tablet', mobile: 'dashicons-smartphone' };
    const SIDES = ['top', 'right', 'bottom', 'left'];

    let uidCounter = 0;
    const uid = (prefix) => (prefix || 'sbf') + '-' + (++uidCounter) + '-' + Math.random().toString(36).slice(2, 7);

    const esc = (s) => ShopBuilder.escHtml(s);
    const escA = (s) => ShopBuilder.escAttr(s);

    const F = {

        DEVICES: DEVICES,

        /* =================================================================
           Values & defaults
           ================================================================= */

        isDeviceMap: function (v) {
            return !!v && typeof v === 'object' && !Array.isArray(v) &&
                ('desktop' in v || 'tablet' in v || 'mobile' in v);
        },

        emptySides: function () {
            return { top: '', right: '', bottom: '', left: '' };
        },

        deviceDefault: function (spec, device) {
            const d = spec.default;
            if (F.isDeviceMap(d)) return d[device] !== undefined ? d[device] : '';
            return device === 'desktop' ? d : '';
        },

        scalarDefault: function (spec, raw) {
            switch (spec.type) {
                case 'toggle': return !!raw;
                case 'image':
                case 'video': return raw || 0;
                case 'products':
                case 'tags':
                case 'categories': return [];
                case 'repeater': return [];
                case 'sides': return F.emptySides();
                case 'devices': return { desktop: false, tablet: false, mobile: false };
                case 'number': return (raw === undefined || raw === null) ? '' : raw;
                case 'select': {
                    if (raw !== undefined && raw !== null) return String(raw);
                    const keys = Object.keys(spec.options || {});
                    return keys.length ? keys[0] : '';
                }
                default: return (raw === undefined || raw === null) ? '' : raw;
            }
        },

        defaultValue: function (spec) {
            if (spec.responsive) {
                const out = {};
                DEVICES.forEach(function (device) {
                    const raw = F.deviceDefault(spec, device);
                    if (device !== 'desktop' && (raw === '' || raw === undefined || raw === null)) {
                        out[device] = spec.type === 'sides' ? F.emptySides() : '';
                    } else {
                        out[device] = F.scalarDefault(spec, raw);
                    }
                });
                return out;
            }
            return F.scalarDefault(spec, spec.default);
        },

        defaults: function (specs) {
            const obj = {};
            Object.keys(specs || {}).forEach(function (key) {
                obj[key] = F.defaultValue(specs[key]);
            });
            return obj;
        },

        /** Makes sure obj[key] exists in the right shape (older/partial data). */
        ensureValue: function (obj, key, spec) {
            let v = obj[key];
            if (v === undefined || v === null) {
                obj[key] = F.defaultValue(spec);
                return obj[key];
            }
            if (spec.responsive && !F.isDeviceMap(v)) {
                const def = F.defaultValue(spec);
                def.desktop = v;
                obj[key] = def;
            } else if (spec.responsive) {
                DEVICES.forEach(function (d) {
                    if (v[d] === undefined || v[d] === null) v[d] = spec.type === 'sides' ? F.emptySides() : '';
                });
            }
            return obj[key];
        },

        /** Fills empty tablet/mobile values from the next larger device (mirror of PHP resolve_responsive()). */
        resolveResponsive: function (value, type) {
            if (!F.isDeviceMap(value)) return { desktop: value, tablet: value, mobile: value };
            const out = {};
            let prev = null;
            DEVICES.forEach(function (device) {
                let v = value[device];
                if (type === 'sides') {
                    v = Object.assign(F.emptySides(), v || {});
                    SIDES.forEach(function (side) {
                        if ((v[side] === '' || v[side] === undefined) && prev) v[side] = prev[side];
                    });
                } else if ((v === '' || v === undefined || v === null) && prev !== null) {
                    v = prev;
                }
                out[device] = v;
                prev = v;
            });
            return out;
        },

        /** What an empty value on `device` currently inherits. */
        inheritedValue: function (value, device, type) {
            const idx = DEVICES.indexOf(device);
            if (idx <= 0) return '';
            const resolved = F.resolveResponsive(value, type);
            return resolved[DEVICES[idx - 1]];
        },

        /* =================================================================
           Public output (mirror of PHP output_fields())
           ================================================================= */

        /** Minutes east of UTC for an IANA timezone at a given instant. */
        tzOffsetMinutes: function (timeZone, utcMs) {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: timeZone, hourCycle: 'h23',
                year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
            }).formatToParts(new Date(utcMs));
            const get = (t) => Number((parts.find(function (p) { return p.type === t; }) || {}).value);
            const asUtc = Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second'));
            return Math.round((asUtc - utcMs) / 60000);
        },

        /**
         * "2026-12-31T23:59" in the store's timezone -> ISO 8601 with offset.
         * Mirror of datetime_to_iso() in trait-sb-sanitizers.php, so the live
         * preview counts down to exactly the published moment.
         */
        datetimeToIso: function (value) {
            const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value || '');
            if (!m) return '';
            const tz = (window.shopBuilder && shopBuilder.siteTimezone) || 'UTC';
            let offset;
            if (/^[+-]\d{2}:\d{2}$/.test(tz)) {
                offset = (tz[0] === '-' ? -1 : 1) * (Number(tz.slice(1, 3)) * 60 + Number(tz.slice(4, 6)));
            } else {
                try {
                    const wall = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
                    offset = F.tzOffsetMinutes(tz, wall);
                    offset = F.tzOffsetMinutes(tz, wall - offset * 60000);
                } catch (e) {
                    offset = 0;
                }
            }
            const sign = offset < 0 ? '-' : '+';
            const abs = Math.abs(offset);
            const pad = (n) => String(n).padStart(2, '0');
            return value + ':00' + sign + pad(Math.floor(abs / 60)) + ':' + pad(abs % 60);
        },

        imagePayload: function (id) {
            const m = (ShopBuilder.state && ShopBuilder.state.media[id]) || null;
            if (!m || !m.url) return null;
            return { url: m.url, width: m.width || null, height: m.height || null };
        },

        output: function (specs, data) {
            const out = {};
            data = data || {};
            Object.keys(specs || {}).forEach(function (key) {
                const spec = specs[key];
                if (spec.private) return;
                if (!(key in data)) return;
                let value = data[key];

                if (spec.type === 'repeater') {
                    out[key] = (Array.isArray(value) ? value : []).map(function (item) {
                        return F.output(spec.fields || {}, item);
                    });
                    return;
                }
                if (spec.type === 'image' || spec.type === 'video') value = value ? F.imagePayload(value) : null;
                if (spec.type === 'datetime') value = F.datetimeToIso(value);
                if (spec.responsive) value = F.resolveResponsive(value, spec.type);
                out[key] = value;
            });
            return out;
        },

        /* =================================================================
           Rendering
           ================================================================= */

        /**
         * Builds a `.sbf-scope` for `obj` using `specs` (optionally only the
         * specs for which `filter(spec, key)` returns true). Returns null if
         * no field passes the filter.
         */
        buildScope: function (specs, obj, onChange, filter) {
            const $scope = $('<div class="sbf-scope"></div>');
            $scope.data('obj', obj).data('specs', specs).data('onChange', onChange || function () {});

            let currentGroup = null;
            let $target = $scope;
            let count = 0;

            Object.keys(specs || {}).forEach(function (key) {
                const spec = specs[key];
                if (spec.type === 'hidden') return;
                if (filter && !filter(spec, key)) return;

                F.ensureValue(obj, key, spec);

                if (spec.group && spec.group !== currentGroup) {
                    currentGroup = spec.group;
                    $target = $('<div class="sbf-group"></div>').append(`<h4 class="sbf-group-title">${esc(spec.group)}</h4>`);
                    $scope.append($target);
                } else if (!spec.group && currentGroup) {
                    currentGroup = null;
                    $target = $scope;
                }

                $target.append(F.buildField(key, spec, obj, onChange));
                count++;
            });

            if (!count) return null;
            F.applyVisibility($scope);
            return $scope;
        },

        buildField: function (key, spec, obj, onChange) {
            const $field = $('<div class="sbf-field"></div>')
                .attr('data-key', key)
                .addClass('sbf-type-' + spec.type);

            if (spec.show_if) $field.attr('data-show-if', JSON.stringify(spec.show_if));

            if (spec.type !== 'toggle' && spec.type !== 'repeater') {
                let badges = '';
                if (spec.responsive) {
                    badges = DEVICES.map(function (d) {
                        return `<span class="sbf-device-badge qwoo-device-only-${d} dashicons ${DEVICE_ICONS[d]}" title="Value for ${DEVICE_LABELS[d]} — switch device in the top bar"></span>`;
                    }).join('');
                }
                $field.append(`<div class="sbf-label"><span>${esc(spec.label || key)}</span>${badges}</div>`);
            }

            if (spec.type === 'repeater') {
                $field.append(F.buildRepeater(key, spec, obj, onChange));
            } else if (spec.responsive) {
                $field.addClass('sbf-responsive');
                DEVICES.forEach(function (device) {
                    const $pane = $(`<div class="sbf-device-pane qwoo-device-only-${device}"></div>`);
                    $pane.append(F.buildControl(spec, obj[key][device], device, key));
                    $field.append($pane);
                });
                F.refreshInherit($field, obj, key, spec);
            } else {
                $field.append(F.buildControl(spec, obj[key], null, key));
            }

            if (spec.help) $field.append(`<p class="description">${esc(spec.help)}</p>`);
            return $field;
        },

        /** One control for one (device) value. */
        buildControl: function (spec, value, device, key) {
            const dev = device ? ` data-device="${device}"` : '';
            const ph = spec.placeholder ? ` placeholder="${escA(spec.placeholder)}"` : '';
            value = value === undefined || value === null ? '' : value;

            switch (spec.type) {
                case 'text':
                case 'rich_text':
                case 'anchor':
                    return `<input type="text" class="sbf-input widefat"${dev}${ph} value="${escA(value)}" />`;

                case 'url':
                    return `<input type="text" class="sbf-input widefat"${dev}${ph} value="${escA(value)}" />`;

                case 'email':
                    return `<input type="email" class="sbf-input widefat"${dev}${ph} value="${escA(value)}" />`;

                case 'number': {
                    const min = spec.min !== undefined ? ` min="${spec.min}"` : '';
                    const max = spec.max !== undefined ? ` max="${spec.max}"` : '';
                    return `<input type="number" class="sbf-input small-text"${dev}${min}${max}${ph} value="${escA(value)}" />`;
                }

                case 'length':
                    return `<input type="text" class="sbf-input sbf-length"${dev}${ph} value="${escA(value)}" />`;

                case 'textarea':
                    return `<textarea class="sbf-input widefat" rows="3"${dev}${ph}>${esc(value)}</textarea>`;

                case 'html':
                    return `<textarea id="${uid('sbfed')}" class="sbf-input sbf-html-textarea widefat" rows="8"${dev}>${esc(value)}</textarea>`;

                case 'select': {
                    let opts = '';
                    if (device && device !== 'desktop') opts += '<option value="" class="sbf-inherit-option">Inherit</option>';
                    Object.keys(spec.options || {}).forEach(function (val) {
                        const sel = String(value) === String(val) ? ' selected' : '';
                        opts += `<option value="${escA(val)}"${sel}>${esc(spec.options[val])}</option>`;
                    });
                    return `<select class="sbf-input"${dev}>${opts}</select>`;
                }

                case 'toggle':
                    return `<label class="sbf-toggle"><input type="checkbox" class="sbf-input qwoo-switch qwoo-switch--sm"${dev}${value ? ' checked' : ''} /> <span>${esc(spec.label || key)}</span></label>`;

                case 'color':
                    return ShopBuilder.colorFieldTemplate('', '', '#000000', value || '');

                case 'image':
                    return F.imageControlHtml(value, dev, 'image');

                case 'video':
                    return F.imageControlHtml(value, dev, 'video');

                case 'datetime':
                    return `<input type="datetime-local" class="sbf-input"${dev} value="${escA(value)}" />`;

                case 'products':
                case 'categories':
                case 'tags': {
                    const labels = (ShopBuilder.state.labels[spec.type] || {});
                    const options = (Array.isArray(value) ? value : []).map(function (id) {
                        return `<option value="${escA(id)}" selected>${esc(labels[id] || ('#' + id))}</option>`;
                    }).join('');
                    const placeholder = 'Type to search ' + spec.type + '…';
                    return `<div class="custom-select-wrapper"><select class="sbf-entity-select" multiple="multiple" data-entity="${spec.type}" data-placeholder="${placeholder}">${options}</select></div>`;
                }

                case 'icon':
                    return F.iconPickerHtml(value, spec);

                case 'sides': {
                    const v = Object.assign(F.emptySides(), value || {});
                    return '<div class="sbf-sides">' + SIDES.map(function (side) {
                        return `<label><span>${side.charAt(0).toUpperCase() + side.slice(1)}</span><input type="text" class="sbf-input sbf-length"${dev} data-sub="${side}" value="${escA(v[side])}" /></label>`;
                    }).join('') + '</div>';
                }

                case 'devices': {
                    const v = value || {};
                    return '<div class="sbf-devices">' + DEVICES.map(function (d) {
                        return `<label><input type="checkbox" class="sbf-input" data-sub="${d}"${v[d] ? ' checked' : ''} /> <span class="dashicons ${DEVICE_ICONS[d]}"></span> ${DEVICE_LABELS[d]}</label>`;
                    }).join('') + '</div>';
                }
            }
            return '';
        },

        /** Media picker control. `kind` is 'image' or 'video' (which library wp.media opens). */
        imageControlHtml: function (id, dev, kind) {
            kind = kind || 'image';
            const media = id ? (ShopBuilder.state.media[id] || null) : null;
            const fileName = media && media.url ? media.url.split('/').pop() : '';
            let preview;
            if (media && media.thumb) {
                preview = `<img src="${escA(media.thumb)}" alt="" />`;
            } else if (media && kind === 'video') {
                preview = `<span class="sbf-image-empty"><span class="dashicons dashicons-video-alt3"></span><br>${esc(fileName)}</span>`;
            } else if (id) {
                preview = '<span class="sbf-image-empty">' + (kind === 'video' ? 'Video' : 'Image') + ' #' + esc(id) + '</span>';
            } else {
                preview = `<span class="sbf-image-empty dashicons ${kind === 'video' ? 'dashicons-video-alt3' : 'dashicons-format-image'}"></span>`;
            }
            const meta = media && media.width ? `${media.width} × ${media.height}px` : '';
            return `
                <div class="sbf-image" data-kind="${kind}"${dev || ''}>
                    <div class="sbf-image-preview">${preview}</div>
                    <div class="sbf-image-actions">
                        <button type="button" class="button sbf-image-select">${id ? 'Replace' : (kind === 'video' ? 'Select video' : 'Select image')}</button>
                        <button type="button" class="button-link button-link-delete sbf-image-remove"${id ? '' : ' hidden'}>Remove</button>
                        <span class="sbf-image-meta">${esc(meta)}</span>
                    </div>
                </div>`;
        },

        iconPickerHtml: function (value, spec) {
            const name = uid('sbf-icon');
            const sets = (window.shopBuilder && shopBuilder.iconSets) || {};
            const icons = sets[(spec && spec.icon_set) || 'advantage'] || {};
            let html = '<div class="qwoo-icon-picker">';
            Object.keys(icons).forEach(function (key) {
                const sel = value === key;
                html += `
                    <label class="qwoo-icon-option${sel ? ' qwoo-icon-option--selected' : ''}">
                        <input type="radio" class="sbf-input sbf-icon-radio" name="${name}" value="${escA(key)}"${sel ? ' checked' : ''} />
                        <span class="material-icons qwoo-icon-option__glyph">${esc(icons[key].icon)}</span>
                        <span class="qwoo-icon-option__label">${esc(icons[key].label)}</span>
                    </label>`;
            });
            const custom = value === 'custom';
            html += `
                    <label class="qwoo-icon-option qwoo-icon-option--custom${custom ? ' qwoo-icon-option--selected' : ''}">
                        <input type="radio" class="sbf-input sbf-icon-radio" name="${name}" value="custom"${custom ? ' checked' : ''} />
                        <span class="material-icons qwoo-icon-option__glyph">file_upload</span>
                        <span class="qwoo-icon-option__label">Custom image</span>
                    </label>`;
            return html + '</div>';
        },

        /* ---------- Repeaters ---------- */

        buildRepeater: function (key, spec, obj, onChange) {
            if (!Array.isArray(obj[key])) obj[key] = [];
            const list = obj[key];

            const $rep = $(`
                <div class="sbf-repeater">
                    <div class="sbf-repeater-head">
                        <span class="field-label">${esc(spec.label || key)}</span>
                        <span class="sbf-repeater-count"></span>
                    </div>
                    <div class="sbf-repeater-items"></div>
                    <button type="button" class="button sbf-repeater-add">${esc(spec.add_label || '+ Add')}</button>
                </div>`);
            $rep.data('list', list).data('spec', spec).data('onChange', onChange);

            const $items = $rep.find('.sbf-repeater-items');
            list.forEach(function (item, i) {
                $items.append(F.buildRepeaterItem($rep, item, i, false));
            });
            F.updateRepeaterMeta($rep);
            return $rep;
        },

        repeaterItemTitle: function (spec, item, index) {
            let title = '';
            if (spec.title_field && item[spec.title_field]) {
                const fieldSpec = (spec.fields || {})[spec.title_field] || {};
                const raw = item[spec.title_field];
                title = fieldSpec.type === 'select' && fieldSpec.options && fieldSpec.options[raw]
                    ? fieldSpec.options[raw]
                    : String(raw).replace(/<[^>]*>/g, '').trim();
            }
            if (!title && item.image) {
                const m = ShopBuilder.state.media[item.image];
                if (m && m.url) title = m.url.split('/').pop();
            }
            return title || `${spec.item_label || 'Item'} ${index + 1}`;
        },

        buildRepeaterItem: function ($rep, item, index, open) {
            const spec = $rep.data('spec');
            const onChange = $rep.data('onChange');
            const $item = $(`
                <div class="sbf-repeater-item${open ? '' : ' is-collapsed'}">
                    <div class="sbf-repeater-item-header">
                        <span class="sbf-repeater-handle dashicons dashicons-menu" title="Drag to reorder"></span>
                        <span class="sbf-repeater-thumb"></span>
                        <button type="button" class="sbf-repeater-toggle"><span class="sbf-repeater-title"></span><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                        <button type="button" class="button-link sbf-repeater-duplicate" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>
                        <button type="button" class="button-link button-link-delete sbf-repeater-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                    <div class="sbf-repeater-item-body"></div>
                </div>`);
            $item.data('item', item);

            const $scope = F.buildScope(spec.fields || {}, item, function (changedKey) {
                F.updateRepeaterItemHeader($item, spec);
                onChange(changedKey);
            });
            if ($scope) $item.find('.sbf-repeater-item-body').append($scope);
            F.updateRepeaterItemHeader($item, spec, index);
            return $item;
        },

        updateRepeaterItemHeader: function ($item, spec, index) {
            const item = $item.data('item');
            if (index === undefined) index = $item.index();
            $item.find('> .sbf-repeater-item-header .sbf-repeater-title').first().text(F.repeaterItemTitle(spec, item, index));
            const imgKey = Object.keys(spec.fields || {}).find(function (k) { return spec.fields[k].type === 'image'; });
            const m = imgKey && item[imgKey] ? ShopBuilder.state.media[item[imgKey]] : null;
            $item.find('> .sbf-repeater-item-header .sbf-repeater-thumb').first()
                .html(m ? `<img src="${escA(m.thumb || m.url)}" alt="" />` : '');
        },

        updateRepeaterMeta: function ($rep) {
            const spec = $rep.data('spec');
            const list = $rep.data('list');
            const max = spec.max_items || 20;
            $rep.find('> .sbf-repeater-head .sbf-repeater-count').text(list.length ? `${list.length} / ${max}` : '');
            $rep.find('> .sbf-repeater-add').prop('disabled', list.length >= max);
        },

        /** Re-reads a repeater's item order from the DOM into its (same) array. */
        syncRepeaterFromDom: function ($rep) {
            const list = $rep.data('list');
            const order = $rep.find('> .sbf-repeater-items > .sbf-repeater-item').map(function () {
                return $(this).data('item');
            }).get();
            list.splice(0, list.length, ...order);
            const spec = $rep.data('spec');
            $rep.find('> .sbf-repeater-items > .sbf-repeater-item').each(function (i) {
                F.updateRepeaterItemHeader($(this), spec, i);
            });
            F.updateRepeaterMeta($rep);
        },

        /* =================================================================
           Visibility (show_if) & inherited placeholders
           ================================================================= */

        matchesShowIf: function (showIf, obj) {
            return Object.keys(showIf).every(function (k) {
                const expected = [].concat(showIf[k]).map(String);
                let v = obj[k];
                const values = F.isDeviceMap(v) ? Object.values(F.resolveResponsive(v)) : [v];
                return values.some(function (val) { return expected.indexOf(String(val)) !== -1; });
            });
        },

        applyVisibility: function ($scope) {
            const obj = $scope.data('obj');
            const $own = $scope.find('.sbf-field').filter(function () {
                return $(this).closest('.sbf-scope')[0] === $scope[0];
            });
            $own.each(function () {
                const showIf = $(this).data('show-if');
                $(this).toggleClass('sbf-hidden', !!showIf && !F.matchesShowIf(showIf, obj));
            });
            $scope.find('.sbf-group').filter(function () {
                return $(this).closest('.sbf-scope')[0] === $scope[0];
            }).each(function () {
                const anyVisible = $(this).children('.sbf-field').not('.sbf-hidden').length > 0;
                $(this).toggleClass('sbf-hidden', !anyVisible);
            });
        },

        displayValue: function (spec, v) {
            if (v === '' || v === undefined || v === null) return 'default';
            if (spec.type === 'select') return (spec.options || {})[v] || v;
            return String(v);
        },

        /** Updates tablet/mobile placeholders to show what they inherit. */
        refreshInherit: function ($field, obj, key, spec) {
            if (!spec.responsive) return;
            const value = obj[key];
            ['tablet', 'mobile'].forEach(function (device) {
                const inherited = F.inheritedValue(value, device, spec.type);
                const $pane = $field.children('.qwoo-device-only-' + device);
                if (spec.type === 'select') {
                    $pane.find('option.sbf-inherit-option').text('Inherit (' + F.displayValue(spec, inherited) + ')');
                } else if (spec.type === 'sides') {
                    const iv = inherited || {};
                    $pane.find('input[data-sub]').each(function () {
                        const side = $(this).data('sub');
                        $(this).attr('placeholder', iv[side] ? iv[side] : 'inherit');
                    });
                } else {
                    $pane.find('.sbf-input').attr('placeholder', inherited !== '' && inherited !== undefined && inherited !== null
                        ? 'Inherits: ' + inherited
                        : (spec.placeholder || 'inherit'));
                }
            });
        },

        /* =================================================================
           Widgets that need to be in the DOM first
           ================================================================= */

        /** Select2 pickers, sortable repeaters and (visible) rich-text editors inside $root. */
        initWidgets: function ($root) {
            $root.find('.sbf-entity-select').each(function () {
                const $select = $(this);
                if ($select.data('select2')) return;
                const entity = $select.data('entity');
                const actions = { products: 'shop_builder_product_search', categories: 'shop_builder_category_search', tags: 'shop_builder_tag_search' };
                ShopBuilder.initAjaxSelect2($select, actions[entity] || actions.categories);

                $select.on('select2:select', function (e) {
                    ShopBuilder.state.labels[entity] = ShopBuilder.state.labels[entity] || {};
                    ShopBuilder.state.labels[entity][e.params.data.id] = e.params.data.text;
                });
                $select.on('change', function () {
                    const $field = $select.closest('.sbf-field');
                    const $scope = $field.closest('.sbf-scope');
                    const obj = $scope.data('obj');
                    obj[$field.data('key')] = ($select.val() || []).map(Number).filter(Boolean);
                    $scope.data('onChange')($field.data('key'));
                });
            });

            if ($.fn.sortable) {
                $root.find('.sbf-repeater-items').each(function () {
                    const $items = $(this);
                    if ($items.data('ui-sortable')) return;
                    $items.sortable({
                        handle: '.sbf-repeater-handle',
                        axis: 'y',
                        placeholder: 'sbf-repeater-placeholder',
                        start: function (e, ui) { F.destroyEditors(ui.item); },
                        stop: function (e, ui) { F.initEditors(ui.item); },
                        update: function () {
                            const $rep = $items.closest('.sbf-repeater');
                            F.syncRepeaterFromDom($rep);
                            $rep.data('onChange')('reorder');
                        }
                    });
                });
            }

            F.initEditors($root);
        },

        /** Starts TinyMCE on visible, not-yet-initialized html fields in $root. */
        initEditors: function ($root) {
            if (!window.wp || !wp.editor || typeof wp.editor.initialize !== 'function') return;

            $root.find('.sbf-html-textarea').each(function () {
                const $ta = $(this);
                if ($ta.data('editorReady') || !$ta.is(':visible')) return;
                const id = $ta.attr('id');
                $ta.data('editorReady', true);

                wp.editor.initialize(id, {
                    tinymce: {
                        wpautop: false,
                        height: 180,
                        menubar: false,
                        plugins: 'lists,link,paste,wordpress,wplink,wptextpattern',
                        toolbar1: 'formatselect,bold,italic,underline,strikethrough,bullist,numlist,blockquote,link,unlink,removeformat,undo,redo',
                        block_formats: 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
                        setup: function (editor) {
                            editor.on('change keyup input undo redo NodeChange', function () {
                                const html = editor.getContent();
                                if ($ta.val() !== html) $ta.val(html).trigger('input');
                            });
                        }
                    },
                    quicktags: { buttons: 'strong,em,link,ul,ol,li,close' },
                    mediaButtons: false
                });
            });
        },

        destroyEditors: function ($root) {
            if (!window.wp || !wp.editor || typeof wp.editor.remove !== 'function') return;
            $root.find('.sbf-html-textarea').each(function () {
                const $ta = $(this);
                if (!$ta.data('editorReady')) return;
                if (window.tinymce) {
                    const ed = tinymce.get($ta.attr('id'));
                    if (ed) $ta.val(ed.getContent());
                }
                wp.editor.remove($ta.attr('id'));
                $ta.data('editorReady', false);
            });
        },

        /* =================================================================
           Event handling (delegated once, on document)
           ================================================================= */

        readInput: function ($input, spec) {
            if ($input.is(':checkbox')) return $input.is(':checked');
            const v = $input.val();
            if (spec.type === 'number') return v === '' ? '' : Number(v);
            return v;
        },

        /** Writes one edited control's value into its scope object. */
        handleInput: function ($input) {
            const $field = $input.closest('.sbf-field');
            const $scope = $field.closest('.sbf-scope');
            if (!$field.length || !$scope.length) return;

            const obj = $scope.data('obj');
            const key = $field.data('key');
            const spec = ($scope.data('specs') || {})[key];
            if (!obj || !spec) return;

            const device = $input.data('device');
            const sub = $input.data('sub');
            const value = F.readInput($input, spec);
            const current = F.ensureValue(obj, key, spec);

            if (spec.type === 'devices') {
                current[sub] = value;
            } else if (spec.responsive) {
                if (spec.type === 'sides') {
                    current[device] = Object.assign(F.emptySides(), current[device] || {});
                    current[device][sub] = value;
                } else {
                    current[device] = value;
                }
            } else if (spec.type === 'sides') {
                obj[key] = Object.assign(F.emptySides(), current || {});
                obj[key][sub] = value;
            } else {
                obj[key] = value;
            }

            F.afterChange($scope, $field, key, spec);
        },

        afterChange: function ($scope, $field, key, spec) {
            F.refreshInherit($field, $scope.data('obj'), key, spec);
            F.applyVisibility($scope);
            F.initWidgets($scope); // fields that just became visible (e.g. an editor)
            $scope.data('onChange')(key);
        },

        bindEvents: function () {
            if (F._bound) return;
            F._bound = true;

            $(document).on('input change', '.sbf-scope .sbf-input', function () {
                F.handleInput($(this));
            });

            // Color controls: the hidden value input is the source of truth.
            $(document).on('change', '.sbf-scope .qwoo-color-value', function () {
                const $field = $(this).closest('.sbf-field');
                const $scope = $field.closest('.sbf-scope');
                const key = $field.data('key');
                $scope.data('obj')[key] = $(this).val();
                F.afterChange($scope, $field, key, $scope.data('specs')[key]);
            });

            // Icon picker highlight.
            $(document).on('change', '.sbf-icon-radio', function () {
                const $picker = $(this).closest('.qwoo-icon-picker');
                $picker.find('.qwoo-icon-option').removeClass('qwoo-icon-option--selected');
                $(this).closest('.qwoo-icon-option').addClass('qwoo-icon-option--selected');
            });

            // Image fields.
            $(document).on('click', '.sbf-image-select', function (e) {
                e.preventDefault();
                const $field = $(this).closest('.sbf-field');
                const $scope = $field.closest('.sbf-scope');
                const key = $field.data('key');
                const kind = $(this).closest('.sbf-image').data('kind') || 'image';

                const frame = wp.media({
                    title: kind === 'video' ? 'Select Video' : 'Select Image',
                    button: { text: kind === 'video' ? 'Use this video' : 'Use this image' },
                    multiple: false,
                    library: { type: kind }
                });
                frame.on('select', function () {
                    const a = frame.state().get('selection').first().toJSON();
                    const sizes = a.sizes || {};
                    ShopBuilder.state.media[a.id] = {
                        url: a.url,
                        thumb: kind === 'video' ? '' : ((sizes.medium && sizes.medium.url) || (sizes.thumbnail && sizes.thumbnail.url) || a.url),
                        width: a.width || null,
                        height: a.height || null
                    };
                    $scope.data('obj')[key] = a.id;
                    $field.find('.sbf-image').replaceWith(F.imageControlHtml(a.id, '', kind));
                    // Pre-fill empty alt text from the media library.
                    const obj = $scope.data('obj');
                    if ('alt' in obj && !obj.alt && a.alt) {
                        obj.alt = a.alt;
                        $scope.find('.sbf-field[data-key="alt"] .sbf-input').val(a.alt);
                    }
                    F.afterChange($scope, $field, key, $scope.data('specs')[key]);
                });
                frame.open();
            });

            $(document).on('click', '.sbf-image-remove', function (e) {
                e.preventDefault();
                const $field = $(this).closest('.sbf-field');
                const $scope = $field.closest('.sbf-scope');
                const key = $field.data('key');
                const kind = $(this).closest('.sbf-image').data('kind') || 'image';
                $scope.data('obj')[key] = 0;
                $field.find('.sbf-image').replaceWith(F.imageControlHtml(0, '', kind));
                F.afterChange($scope, $field, key, $scope.data('specs')[key]);
            });

            // Repeaters.
            $(document).on('click', '.sbf-repeater-add', function (e) {
                e.preventDefault();
                const $rep = $(this).closest('.sbf-repeater');
                const spec = $rep.data('spec');
                const list = $rep.data('list');
                if (list.length >= (spec.max_items || 20)) return;

                const item = F.defaults(spec.fields || {});
                list.push(item);
                const $item = F.buildRepeaterItem($rep, item, list.length - 1, true);
                $rep.find('> .sbf-repeater-items').append($item);
                F.initWidgets($item);
                F.updateRepeaterMeta($rep);
                $rep.data('onChange')('add');
                $item.find('.sbf-input').first().trigger('focus');
            });

            $(document).on('click', '.sbf-repeater-remove', function (e) {
                e.preventDefault();
                if (!confirm('Remove this item?')) return;
                const $item = $(this).closest('.sbf-repeater-item');
                const $rep = $item.closest('.sbf-repeater');
                F.destroyEditors($item);
                $item.remove();
                F.syncRepeaterFromDom($rep);
                $rep.data('onChange')('remove');
            });

            $(document).on('click', '.sbf-repeater-duplicate', function (e) {
                e.preventDefault();
                const $item = $(this).closest('.sbf-repeater-item');
                const $rep = $item.closest('.sbf-repeater');
                const spec = $rep.data('spec');
                const list = $rep.data('list');
                if (list.length >= (spec.max_items || 20)) return;

                const copy = JSON.parse(JSON.stringify($item.data('item')));
                if ('key' in copy) copy.key = ''; // form fields: get a fresh key on save
                const $copy = F.buildRepeaterItem($rep, copy, 0, true);
                $item.after($copy);
                F.initWidgets($copy);
                F.syncRepeaterFromDom($rep);
                $rep.data('onChange')('duplicate');
            });

            $(document).on('click', '.sbf-repeater-toggle', function (e) {
                e.preventDefault();
                const $item = $(this).closest('.sbf-repeater-item');
                $item.toggleClass('is-collapsed');
                if (!$item.hasClass('is-collapsed')) F.initWidgets($item);
            });
        }
    };

    ShopBuilder.Fields = F;

})(jQuery);
