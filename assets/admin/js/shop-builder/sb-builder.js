(function ($) {
    'use strict';

    /**
     * The section builder.
     *
     * State lives in plain JS objects (ShopBuilder.state.pages), seeded from
     * the stored options localized by PHP (builder_bootstrap_data()). The
     * DOM is rendered from that state; field edits write straight back into
     * it (sb-builder-fields.js), and drag & drop re-reads the new order from
     * the DOM into the same arrays. Saving sends the state as one JSON
     * payload (sb-save-push.js); the live preview sends getPublicPages()
     * (same shape as the published JSON).
     *
     *   section  { id, label, enabled, style{...SECTION_STYLE_FIELDS}, blocks[], location? }
     *   block    { id, type, label, enabled, style{...BLOCK_COMMON_FIELDS}, data{...BLOCK_SCHEMA[type].fields} }
     *   section block (type 'section') { id, type, label, enabled, style{...SECTION_STYLE_FIELDS}, blocks[] }
     */

    const F = () => ShopBuilder.Fields;
    const esc = (s) => ShopBuilder.escHtml(s);

    const BLOCK_ICONS = {
        section: 'dashicons-columns',
        heading: 'dashicons-heading',
        text_block: 'dashicons-editor-paragraph',
        image_block: 'dashicons-format-image',
        button: 'dashicons-button',
        spacer: 'dashicons-image-flip-vertical',
        form: 'dashicons-feedback',
        featured_products: 'dashicons-products',
        category_grid: 'dashicons-category',
        testimonials: 'dashicons-format-quote',
        advantages: 'dashicons-awards',
        video: 'dashicons-video-alt3',
        icon_list: 'dashicons-editor-ul',
        faq: 'dashicons-editor-help',
        tabs: 'dashicons-index-card',
        countdown: 'dashicons-clock',
        product_grid: 'dashicons-grid-view',
        carousel: 'dashicons-images-alt2',
        social_links: 'dashicons-share'
    };

    const QUERY_LABELS = {
        newest: 'Newest', on_sale: 'On sale', featured: 'Featured', best_selling: 'Best selling',
        top_rated: 'Top rated', category: 'From categories', tag: 'With tags', manual: 'Hand-picked'
    };

    /** Block picker sections, in display order (keys = BLOCK_SCHEMA `category`). */
    const PICKER_CATEGORIES = {
        basic: 'Basic',
        layout: 'Layout',
        interactive: 'Interactive',
        shop: 'Shop & sliders',
        trust: 'Trust & social'
    };

    const ui = {
        open: new Set(),   // ids of expanded sections/blocks
        tabs: {}           // id -> active tab
    };

    function newId(prefix) {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let s = '';
        for (let i = 0; i < 10; i++) s += chars[Math.floor(Math.random() * chars.length)];
        return prefix + '_' + s;
    }

    function schema() { return (window.shopBuilder && shopBuilder.blockSchema) || {}; }
    function sectionStyleFields() { return shopBuilder.sectionStyleFields || {}; }
    function blockCommonFields() { return shopBuilder.blockCommonFields || {}; }
    function maxDepth() { return parseInt(shopBuilder.maxNestingDepth, 10) || 4; }

    Object.assign(window.ShopBuilder, {

        /* =================================================================
           Init & state
           ================================================================= */

        initBuilder: function () {
            const src = window.shopBuilder || {};
            ShopBuilder.state = {
                pages: JSON.parse(JSON.stringify(src.pages || {})),
                media: Object.assign({}, src.media || {}),
                labels: {
                    products: Object.assign({}, (src.labels && src.labels.products) || {}),
                    categories: Object.assign({}, (src.labels && src.labels.categories) || {}),
                    tags: Object.assign({}, (src.labels && src.labels.tags) || {})
                }
            };

            F().bindEvents();
            this.bindBuilderEvents();

            $('.qwoo-builder').each(function () {
                ShopBuilder.renderPage($(this).data('page'));
            });
        },

        /** Raw builder state per page — what Save Draft sends. */
        getBuilderPages: function () {
            return ShopBuilder.state ? ShopBuilder.state.pages : {};
        },

        /** Public shape per page (mirror of output_sections() in PHP) — what the live preview sends. */
        getPublicPages: function () {
            const out = {};
            const pages = this.getBuilderPages();
            Object.keys(pages).forEach(function (page) {
                out[page] = ShopBuilder.outputSections(pages[page] || []);
            });
            return out;
        },

        outputSections: function (sections) {
            return sections.filter(function (s) { return s.enabled; }).map(function (s) {
                const row = {
                    id: s.id,
                    enabled: true,
                    style: F().output(sectionStyleFields(), s.style || {}),
                    blocks: ShopBuilder.outputBlocks(s.blocks || [])
                };
                if (s.location !== undefined) row.location = s.location;
                return row;
            });
        },

        outputBlocks: function (blocks) {
            return blocks.filter(function (b) { return b.enabled && schema()[b.type]; }).map(function (b) {
                if (b.type === 'section') {
                    return {
                        id: b.id, type: 'section', enabled: true,
                        style: F().output(sectionStyleFields(), b.style || {}),
                        blocks: ShopBuilder.outputBlocks(b.blocks || [])
                    };
                }
                return {
                    id: b.id, type: b.type, enabled: true,
                    style: F().output(blockCommonFields(), b.style || {}),
                    data: F().output(schema()[b.type].fields || {}, b.data || {})
                };
            });
        },

        newSection: function (location) {
            const s = {
                id: newId('sec'),
                label: '',
                enabled: true,
                style: F().defaults(sectionStyleFields()),
                blocks: []
            };
            if (location) s.location = location;
            return s;
        },

        newBlock: function (type) {
            if (type === 'section') {
                const style = F().defaults(sectionStyleFields());
                style.padding_preset = 'none';
                style.width_mode = 'full';
                return { id: newId('blk'), type: 'section', label: '', enabled: true, style: style, blocks: [] };
            }
            const conf = schema()[type];
            return {
                id: newId('blk'),
                type: type,
                label: '',
                enabled: true,
                style: F().defaults(blockCommonFields()),
                data: F().defaults(conf.fields || {})
            };
        },

        /** Deep copy with fresh ids everywhere (form field keys are kept). */
        cloneNode: function (node, prefix) {
            const copy = JSON.parse(JSON.stringify(node));
            (function reId(n, p) {
                n.id = newId(p);
                (n.blocks || []).forEach(function (b) { reId(b, 'blk'); });
            })(copy, prefix);
            return copy;
        },

        /** Change notification from anywhere in the builder. */
        builderChanged: function () {
            ShopBuilder.markDirty();
            $(document).trigger('qwoo:builder-change');
        },

        /* =================================================================
           Page rendering
           ================================================================= */

        renderPage: function (page) {
            const $mount = $(`.qwoo-builder[data-page="${page}"]`);
            if (!$mount.length) return;

            const pages = ShopBuilder.state.pages;
            if (!Array.isArray(pages[page])) pages[page] = [];
            const locations = (shopBuilder.pageSectionLocations || {})[page] || null;

            F().destroyEditors($mount);
            $mount.empty();

            const $toolbar = $(`
                <div class="sb-toolbar">
                    <span class="sb-toolbar-count"></span>
                    <button type="button" class="button-link sb-collapse-all">Collapse all</button>
                    <span aria-hidden="true">|</span>
                    <button type="button" class="button-link sb-expand-all">Expand all</button>
                </div>`);
            $mount.append($toolbar);

            if (!locations) {
                const $list = $('<div class="sb-section-list"></div>').attr('data-page', page);
                pages[page].forEach(function (section) {
                    $list.append(ShopBuilder.buildSectionRow(section, page));
                });
                $mount.append($list);
                $mount.append(`
                    <div class="sb-add-section-row">
                        <button type="button" class="button button-primary sb-add-section" data-page="${page}">
                            <span class="dashicons dashicons-plus-alt2"></span> Add Section
                        </button>
                        <button type="button" class="button sb-insert-template" data-page="${page}"><span class="dashicons dashicons-portfolio"></span> From Template</button>
                        <button type="button" class="button sb-paste-btn sb-paste-section" data-page="${page}"><span class="dashicons dashicons-clipboard"></span> Paste</button>
                    </div>`);
            } else {
                // Sections without a known location go to the first slot.
                const firstLoc = Object.keys(locations)[0];
                pages[page].forEach(function (s) { if (!locations[s.location]) s.location = firstLoc; });

                Object.keys(locations).forEach(function (loc) {
                    const $group = $(`
                        <div class="qwoo-location-group">
                            <div class="qwoo-location-group-header">
                                <h4>${esc(locations[loc])} <span class="sb-location-count"></span></h4>
                                <div class="sb-location-actions">
                                    <button type="button" class="button button-secondary sb-add-section" data-page="${page}" data-location="${loc}">+ Add Section</button>
                                    <button type="button" class="button sb-insert-template" data-page="${page}" data-location="${loc}" title="Insert a section template"><span class="dashicons dashicons-portfolio"></span></button>
                                    <button type="button" class="button sb-paste-btn sb-paste-section" data-page="${page}" data-location="${loc}"><span class="dashicons dashicons-clipboard"></span> Paste</button>
                                </div>
                            </div>
                        </div>`);
                    const $list = $('<div class="sb-section-list qwoo-location-dropzone"></div>')
                        .attr('data-page', page).attr('data-location', loc);
                    pages[page].filter(function (s) { return s.location === loc; }).forEach(function (section) {
                        $list.append(ShopBuilder.buildSectionRow(section, page));
                    });
                    $group.append($list);
                    $mount.append($group);
                });
            }

            this.initSectionSortables($mount, page);
            $mount.find('.sb-section-row.is-open').each(function () {
                ShopBuilder.ensureBody($(this));
            });
            this.updatePageMeta(page);
        },

        updatePageMeta: function (page) {
            const $mount = $(`.qwoo-builder[data-page="${page}"]`);
            const sections = ShopBuilder.state.pages[page] || [];
            $mount.find('.sb-toolbar-count').text(sections.length
                ? `${sections.length} section${sections.length === 1 ? '' : 's'}`
                : 'No sections yet');
            $mount.find('.qwoo-location-dropzone').each(function () {
                const n = $(this).children('.sb-section-row').length;
                $(this).closest('.qwoo-location-group').find('.sb-location-count').text(n ? `(${n})` : '');
                $(this).toggleClass('is-empty', n === 0);
            });
            $mount.find('.sb-section-list').not('.qwoo-location-dropzone').each(function () {
                $(this).toggleClass('is-empty', $(this).children('.sb-section-row').length === 0);
            });
        },

        initSectionSortables: function ($mount, page) {
            if (!$.fn.sortable) return;
            $mount.find('.sb-section-list').sortable({
                handle: '> .sb-row-header .sb-drag-handle',
                connectWith: `.sb-section-list[data-page="${page}"]`,
                placeholder: 'sb-row-placeholder',
                forcePlaceholderSize: true,
                scrollSensitivity: 60, // auto-scroll the page when dragging near an edge (touch)
                start: function (e, ui) { F().destroyEditors(ui.item); },
                stop: function (e, ui) {
                    ShopBuilder.syncPageFromDom(page);
                    F().initEditors(ui.item);
                }
            });
        },

        /** Re-reads section order (and location) for a page from the DOM. */
        syncPageFromDom: function (page) {
            const list = ShopBuilder.state.pages[page];
            const order = [];
            $(`.qwoo-builder[data-page="${page}"] .sb-section-list`).each(function () {
                const loc = $(this).data('location');
                $(this).children('.sb-section-row').each(function () {
                    const node = $(this).data('node');
                    if (loc) node.location = loc;
                    order.push(node);
                });
            });
            list.splice(0, list.length, ...order);
            this.updatePageMeta(page);
            this.builderChanged();
        },

        /* =================================================================
           Rows (sections & blocks share the same chrome)
           ================================================================= */

        rowTitle: function (node, isSection) {
            if (isSection) {
                const count = (node.blocks || []).length;
                const types = (node.blocks || []).slice(0, 4).map(function (b) {
                    return (schema()[b.type] || {}).label || b.type;
                });
                const summary = count
                    ? `${count} block${count === 1 ? '' : 's'}: ${types.join(', ')}${count > 4 ? '…' : ''}`
                    : 'Empty — add a block';
                return { name: node.label || 'Section', summary: summary };
            }
            const conf = schema()[node.type] || {};
            return { name: node.label || conf.label || node.type, summary: this.blockSummary(node) };
        },

        blockSummary: function (block) {
            const d = block.data || {};
            const strip = (s) => String(s || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            const cut = (s) => s.length > 60 ? s.slice(0, 60) + '…' : s;
            switch (block.type) {
                case 'section': {
                    const n = (block.blocks || []).length;
                    return n ? `${n} block${n === 1 ? '' : 's'}` : 'Empty';
                }
                case 'heading': return cut(strip(d.title));
                case 'text_block': return cut(strip(d.text));
                case 'button': return cut([d.text, d.url].filter(Boolean).join(' → '));
                case 'image_block': {
                    const n = (d.images || []).length;
                    return n ? `${n} image${n === 1 ? '' : 's'}` : '';
                }
                case 'spacer': return d.height && d.height.desktop ? d.height.desktop : '';
                case 'form': {
                    const types = { newsletter: 'Newsletter', contact: 'Contact', custom: 'Custom' };
                    return types[d.form_type] || '';
                }
                case 'featured_products': {
                    const n = (d.product_ids || []).length;
                    return n ? `${n} product${n === 1 ? '' : 's'}` : '';
                }
                case 'category_grid': {
                    const n = (d.category_ids || []).length;
                    return n ? `${n} categor${n === 1 ? 'y' : 'ies'}` : '';
                }
                case 'testimonials':
                case 'advantages':
                case 'icon_list':
                case 'faq':
                case 'tabs':
                case 'social_links': {
                    const n = (d.items || []).length;
                    return n ? `${n} item${n === 1 ? '' : 's'}` : '';
                }
                case 'video': {
                    const sources = { youtube: 'YouTube', vimeo: 'Vimeo', file: 'Uploaded video', url: 'Video URL' };
                    return sources[d.source] || '';
                }
                case 'countdown':
                    return d.end_at ? 'Ends ' + String(d.end_at).replace('T', ' ') : '';
                case 'product_grid':
                    return QUERY_LABELS[d.query_type] || '';
                case 'carousel': {
                    const labels = { slides: 'Hero slides', images: 'Images', logos: 'Logos', products: 'Products', categories: 'Categories' };
                    const list = d.source === 'slides' ? d.slides : (d.source === 'images' || d.source === 'logos') ? d.images : null;
                    return (labels[d.source] || '') + (list ? ` · ${list.length}` : '');
                }
            }
            return '';
        },

        /** Content problems worth flagging in the row header. */
        blockWarnings: function (block) {
            const d = block.data || {};
            const w = [];
            switch (block.type) {
                case 'heading':
                    if (!String(d.title || '').trim()) w.push('The heading has no title.');
                    break;
                case 'text_block':
                    if (!String(d.text || '').replace(/<[^>]*>/g, '').trim()) w.push('The text block is empty.');
                    break;
                case 'button':
                    if (!d.text) w.push('The button has no text.');
                    if (!d.url) w.push('The button has no link.');
                    break;
                case 'image_block': {
                    const withImage = (d.images || []).filter(function (i) { return i.image; });
                    if (!withImage.length) w.push('No images selected.');
                    if (withImage.length < (d.images || []).length) w.push('An image slot has no image picked — it will be dropped on save.');
                    if (withImage.some(function (i) { return !i.alt; })) w.push('Some images have no alt text.');
                    break;
                }
                case 'form':
                    if (d.form_type === 'custom' && !(d.fields || []).length) w.push('The custom form has no fields.');
                    break;
                case 'featured_products':
                    if (!(d.product_ids || []).length) w.push('No products selected.');
                    break;
                case 'category_grid':
                    if (!(d.category_ids || []).length) w.push('No categories selected.');
                    break;
                case 'video':
                    if (d.source === 'file' ? !d.video_file : !d.url) w.push('No video selected.');
                    if ((d.source === 'youtube' || d.source === 'vimeo') && d.url && !new RegExp(d.source === 'youtube' ? 'youtu' : 'vimeo').test(d.url)) {
                        w.push(`That doesn't look like a ${d.source === 'youtube' ? 'YouTube' : 'Vimeo'} link.`);
                    }
                    break;
                case 'countdown':
                    if (!d.end_at) w.push('No end date set.');
                    break;
                case 'product_grid':
                case 'carousel': {
                    const products = block.type === 'product_grid' || d.source === 'products';
                    if (products && d.query_type === 'category' && !(d.category_ids || []).length) w.push('No categories selected.');
                    if (products && d.query_type === 'tag' && !(d.tag_ids || []).length) w.push('No tags selected.');
                    if (products && d.query_type === 'manual' && !(d.product_ids || []).length) w.push('No products selected.');
                    if (block.type === 'carousel' && d.source === 'slides' && !(d.slides || []).some(function (s) { return s.image; })) w.push('No slides with an image.');
                    if (block.type === 'carousel' && (d.source === 'images' || d.source === 'logos') && !(d.images || []).some(function (s) { return s.image; })) w.push('No images added.');
                    break;
                }
                case 'testimonials':
                case 'advantages':
                case 'icon_list':
                case 'faq':
                case 'tabs':
                case 'social_links':
                    if (!(d.items || []).length) w.push('No items added.');
                    break;
            }
            return w;
        },

        rowHeaderHtml: function (node, isSection, typeLabel) {
            const icon = isSection ? 'dashicons-layout' : (BLOCK_ICONS[node.type] || 'dashicons-screenoptions');
            return `
                <div class="sb-row-header">
                    <span class="sb-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>
                    <button type="button" class="sb-row-toggle" aria-expanded="false">
                        <span class="sb-row-icon dashicons ${icon}"></span>
                        <span class="sb-row-name"></span>
                        ${typeLabel ? `<span class="sb-row-type">${esc(typeLabel)}</span>` : ''}
                        <span class="sb-row-summary"></span>
                        <span class="sb-row-warning dashicons dashicons-warning" hidden></span>
                        <span class="sb-row-hidden-on"></span>
                    </button>
                    <div class="sb-row-actions">
                        <label class="sb-row-enabled" title="Show on the site"><input type="checkbox" class="qwoo-switch qwoo-switch--sm sb-enabled-toggle"${node.enabled ? ' checked' : ''} /></label>
                        <button type="button" class="button-link sb-row-rename" title="Rename (admin only)"><span class="dashicons dashicons-edit"></span></button>
                        <button type="button" class="button-link sb-row-duplicate" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>
                        <button type="button" class="button-link sb-row-copy" title="Copy (paste on any page)"><span class="dashicons dashicons-clipboard"></span></button>
                        ${isSection || node.type === 'section' ? '<button type="button" class="button-link sb-row-template" title="Save as template"><span class="dashicons dashicons-portfolio"></span></button>' : ''}
                        <button type="button" class="button-link button-link-delete sb-row-remove" title="Remove"><span class="dashicons dashicons-trash"></span></button>
                        <span class="sb-row-chevron dashicons dashicons-arrow-down-alt2"></span>
                    </div>
                </div>`;
        },

        refreshRowHeader: function ($row) {
            const node = $row.data('node');
            const isSection = $row.hasClass('sb-section-row') || node.type === 'section';
            const t = this.rowTitle(node, isSection);
            const $h = $row.children('.sb-row-header');
            $h.find('.sb-row-name').first().text(t.name);
            $h.find('.sb-row-summary').first().text(t.summary);
            if (!isSection || node.type === 'section') {
                // Type label only needed when a custom name hides it.
                $h.find('.sb-row-type').first().text(node.label ? ((schema()[node.type] || {}).label || '') : '');
            }

            const warnings = isSection ? [] : this.blockWarnings(node);
            $h.find('.sb-row-warning').first().prop('hidden', !warnings.length).attr('title', warnings.join('\n'));

            const hide = (node.style && node.style.hide_on) || {};
            const hiddenOn = ['desktop', 'tablet', 'mobile'].filter(function (d) { return hide[d]; });
            $h.find('.sb-row-hidden-on').first().text(hiddenOn.length ? 'Hidden on ' + hiddenOn.join(', ') : '');

            $row.toggleClass('is-disabled', !node.enabled);
        },

        /** Refreshes the header of this row and every ancestor row (their summaries count children). */
        refreshRowHeaders: function ($row) {
            $row.parents('.sb-row').addBack().each(function () {
                ShopBuilder.refreshRowHeader($(this));
            });
        },

        buildSectionRow: function (section, page) {
            const $row = $('<div class="sb-row sb-section-row"></div>').attr('data-id', section.id);
            $row.data('node', section).data('page', page);
            $row.append(this.rowHeaderHtml(section, true, ''));
            $row.append('<div class="sb-row-body"></div>');
            this.refreshRowHeader($row);
            if (ui.open.has(section.id)) this.setOpen($row, true);
            return $row;
        },

        buildBlockRow: function (block) {
            const conf = schema()[block.type];
            const isContainer = block.type === 'section';
            const $row = $('<div class="sb-row sb-block-row"></div>')
                .attr('data-id', block.id).attr('data-type', block.type)
                .toggleClass('sb-block-container', isContainer);
            $row.data('node', block);
            $row.append(this.rowHeaderHtml(block, false, conf.label));
            $row.append('<div class="sb-row-body"></div>');
            this.refreshRowHeader($row);
            if (ui.open.has(block.id)) this.setOpen($row, true);
            return $row;
        },

        setOpen: function ($row, open) {
            const id = $row.data('node').id;
            if (open) ui.open.add(id); else ui.open.delete(id);
            $row.toggleClass('is-open', open);
            $row.children('.sb-row-header').find('.sb-row-toggle').attr('aria-expanded', open ? 'true' : 'false');
            if (open && $row.closest('body').length) this.ensureBody($row);
        },

        /* =================================================================
           Row bodies (built lazily on first open)
           ================================================================= */

        ensureBody: function ($row) {
            const $body = $row.children('.sb-row-body');
            if (!$body.data('built')) {
                $body.data('built', true);
                this.buildRowBody($row, $body);
            }
            F().initWidgets($body.children('.sb-tab-panel.is-active'));
            $body.children('.sb-tab-panel.is-active').find('.sb-row.is-open').each(function () {
                ShopBuilder.ensureBody($(this));
            });
        },

        buildRowBody: function ($row, $body) {
            const node = $row.data('node');
            const isContainer = $row.hasClass('sb-section-row') || node.type === 'section';
            const onChange = function () {
                ShopBuilder.refreshRowHeaders($row);
                ShopBuilder.builderChanged();
            };

            const tabs = [];
            if (isContainer) {
                const depth = ShopBuilder.containerDepth($row);
                tabs.push({ key: 'blocks', label: 'Blocks', $panel: this.buildBlocksPanel(node, depth) });
                ['layout', 'style', 'advanced'].forEach(function (tab) {
                    const $scope = F().buildScope(sectionStyleFields(), node.style, onChange, function (spec) { return spec.tab === tab; });
                    if ($scope) tabs.push({ key: tab, label: tab.charAt(0).toUpperCase() + tab.slice(1), $panel: $scope });
                });
            } else {
                const fields = (schema()[node.type] || {}).fields || {};
                node.data = node.data || {};
                node.style = node.style || {};
                ['content', 'style'].forEach(function (tab) {
                    let $scope = F().buildScope(fields, node.data, onChange, function (spec) { return (spec.tab || 'content') === tab; });
                    if (tab === 'style') {
                        // The wrapper's own Box & Background fields (stored in
                        // node.style) follow the block's style fields.
                        const $box = F().buildScope(blockCommonFields(), node.style, onChange, function (spec) { return spec.tab === 'style'; });
                        if ($box) $scope = $scope ? $('<div class="sb-tab-scopes"></div>').append($scope, $box) : $box;
                    }
                    if ($scope) tabs.push({ key: tab, label: tab.charAt(0).toUpperCase() + tab.slice(1), $panel: $scope });
                });
                const $adv = F().buildScope(blockCommonFields(), node.style, onChange, function (spec) { return spec.tab !== 'style'; });
                if ($adv) tabs.push({ key: 'advanced', label: 'Advanced', $panel: $adv });
            }

            const active = ui.tabs[node.id] && tabs.some(function (t) { return t.key === ui.tabs[node.id]; })
                ? ui.tabs[node.id]
                : tabs[0].key;

            const $nav = $('<div class="sb-tabs" role="tablist"></div>');
            tabs.forEach(function (t) {
                $nav.append(`<button type="button" role="tab" class="sb-tab${t.key === active ? ' is-active' : ''}" data-tab="${t.key}">${esc(t.label)}</button>`);
            });
            $body.append($nav);

            tabs.forEach(function (t) {
                const $panel = $('<div class="sb-tab-panel"></div>').attr('data-tab', t.key).append(t.$panel);
                if (t.key === active) $panel.addClass('is-active');
                $body.append($panel);
            });
        },

        /** The "Blocks" tab of a container: its sortable block list + add-block control. */
        buildBlocksPanel: function (container, depth) {
            if (!Array.isArray(container.blocks)) container.blocks = [];
            const $wrap = $('<div class="sb-blocks-panel"></div>');
            const $list = $('<div class="sb-block-list"></div>').data('owner', container);

            container.blocks.forEach(function (block) {
                if (!schema()[block.type]) return;
                $list.append(ShopBuilder.buildBlockRow(block));
            });
            $list.toggleClass('is-empty', !container.blocks.length);

            $wrap.append($list);
            $wrap.append(`
                <div class="sb-add-block">
                    <button type="button" class="button sb-add-block-btn"><span class="dashicons dashicons-plus-alt2"></span> Add Block</button>
                    <button type="button" class="button sb-paste-btn sb-paste-block"><span class="dashicons dashicons-clipboard"></span> Paste</button>
                </div>`);

            this.initBlockSortable($list);
            return $wrap;
        },

        initBlockSortable: function ($list) {
            if (!$.fn.sortable) return;
            $list.sortable({
                handle: '> .sb-row-header .sb-drag-handle',
                connectWith: '.sb-block-list',
                placeholder: 'sb-row-placeholder',
                forcePlaceholderSize: true,
                scrollSensitivity: 60, // auto-scroll the page when dragging near an edge (touch)
                tolerance: 'pointer',
                start: function (e, ui) {
                    F().destroyEditors(ui.item);
                    ui.item.data('sb-from', $list);
                },
                receive: function (e, ui) {
                    const node = ui.item.data('node');
                    const targetDepth = ShopBuilder.listDepth($list);
                    // Reject drops that would exceed the max nesting depth
                    // (or drop a container inside itself).
                    if (node.type === 'section' &&
                        (targetDepth + ShopBuilder.subtreeHeight(node) > maxDepth() || $.contains(ui.item[0], $list[0]))) {
                        $(ui.sender).sortable('cancel');
                        alert('That section can\'t be nested this deep (max ' + maxDepth() + ' levels).');
                    }
                },
                stop: function (e, ui) {
                    const $from = ui.item.data('sb-from');
                    const $to = ui.item.parent();
                    ShopBuilder.syncBlockList($from);
                    if ($to[0] !== $from[0]) {
                        ShopBuilder.syncBlockList($to);
                        ShopBuilder.refreshRowHeaders($from.closest('.sb-row'));
                    }
                    ShopBuilder.refreshRowHeaders(ui.item);
                    F().initEditors(ui.item);
                    ShopBuilder.builderChanged();
                }
            });
        },

        /** Re-reads a block list's order from the DOM into its owner's `blocks` array. */
        syncBlockList: function ($list) {
            const owner = $list.data('owner');
            if (!owner) return;
            const order = $list.children('.sb-block-row').map(function () { return $(this).data('node'); }).get();
            owner.blocks.splice(0, owner.blocks.length, ...order);
            $list.toggleClass('is-empty', !order.length);
        },

        /** Depth of the container a block list belongs to (top-level section = 1). */
        listDepth: function ($list) {
            return $list.parents('.sb-section-row, .sb-block-container').length;
        },

        /** Depth of a container row itself (top-level section = 1). */
        containerDepth: function ($row) {
            return $row.parents('.sb-section-row, .sb-block-container').length + 1;
        },

        /** Levels of containers a section block adds (itself + deepest nested section). */
        subtreeHeight: function (node) {
            if (node.type !== 'section') return 0;
            let max = 0;
            (node.blocks || []).forEach(function (b) { max = Math.max(max, ShopBuilder.subtreeHeight(b)); });
            return 1 + max;
        },

        /* =================================================================
           Block picker
           ================================================================= */

        openBlockPicker: function ($btn) {
            $('.sb-block-picker').remove();
            const $list = $btn.closest('.sb-blocks-panel').children('.sb-block-list');
            const depth = ShopBuilder.listDepth($list);
            const owner = $list.data('owner');

            const $picker = $('<div class="sb-block-picker" role="dialog" aria-label="Add a block"></div>');
            $picker.append('<input type="search" class="sb-block-picker-search" placeholder="Search blocks…" />');
            const $grid = $('<div class="sb-block-picker-groups"></div>');

            // Grouped by each block's `category` (BLOCK_SCHEMA), in this order.
            const groups = {};
            Object.keys(schema()).forEach(function (type) {
                if (type === 'section' && depth >= maxDepth()) return;
                const cat = schema()[type].category || 'basic';
                (groups[cat] = groups[cat] || []).push(type);
            });
            Object.keys(PICKER_CATEGORIES).concat(Object.keys(groups)).filter(function (cat, i, all) {
                return groups[cat] && all.indexOf(cat) === i;
            }).forEach(function (cat) {
                const $group = $('<div class="sb-block-picker-group"></div>')
                    .append(`<h5 class="sb-block-picker-group-title">${esc(PICKER_CATEGORIES[cat] || cat)}</h5>`);
                const $items = $('<div class="sb-block-picker-grid"></div>');
                groups[cat].forEach(function (type) {
                    $items.append(`
                        <button type="button" class="sb-block-picker-item" data-type="${type}">
                            <span class="dashicons ${BLOCK_ICONS[type] || 'dashicons-screenoptions'}"></span>
                            <span>${esc(schema()[type].label)}</span>
                        </button>`);
                });
                $grid.append($group.append($items));
            });
            $picker.append($grid);

            if (owner.blocks.length >= (parseInt(shopBuilder.maxBlocks, 10) || 40)) {
                $grid.html('<p class="description">This container already has the maximum number of blocks.</p>');
            }

            $btn.after($picker);
            $picker.data('list', $list);
            $picker.find('.sb-block-picker-search').trigger('focus');
        },

        addBlock: function ($list, type) {
            const owner = $list.data('owner');
            const block = this.newBlock(type);
            owner.blocks.push(block);
            ui.open.add(block.id);

            const $row = this.buildBlockRow(block);
            $list.append($row).removeClass('is-empty');
            this.ensureBody($row);
            this.refreshRowHeaders($list.closest('.sb-row'));
            this.builderChanged();
            $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        /* =================================================================
           Events
           ================================================================= */

        bindBuilderEvents: function () {
            const self = this;

            // Expand / collapse a row.
            $(document).on('click', '.qwoo-builder .sb-row-toggle', function (e) {
                e.preventDefault();
                const $row = $(this).closest('.sb-row');
                self.setOpen($row, !$row.hasClass('is-open'));
            });

            // Tabs.
            $(document).on('click', '.qwoo-builder .sb-tab', function (e) {
                e.preventDefault();
                const $body = $(this).closest('.sb-row-body');
                const tab = $(this).data('tab');
                const node = $body.closest('.sb-row').data('node');
                ui.tabs[node.id] = tab;
                $body.children('.sb-tabs').children('.sb-tab').removeClass('is-active');
                $(this).addClass('is-active');
                $body.children('.sb-tab-panel').removeClass('is-active');
                const $panel = $body.children(`.sb-tab-panel[data-tab="${tab}"]`).addClass('is-active');
                F().initWidgets($panel);
                $panel.find('.sb-row.is-open').each(function () { self.ensureBody($(this)); });
            });

            // Enabled toggle.
            $(document).on('change', '.qwoo-builder .sb-enabled-toggle', function () {
                const $row = $(this).closest('.sb-row');
                $row.data('node').enabled = $(this).is(':checked');
                self.refreshRowHeader($row);
                self.builderChanged();
            });

            // Rename (inline).
            $(document).on('click', '.qwoo-builder .sb-row-rename', function (e) {
                e.preventDefault();
                const $row = $(this).closest('.sb-row');
                const node = $row.data('node');
                const $name = $row.children('.sb-row-header').find('.sb-row-name').first();
                const $input = $('<input type="text" class="sb-row-name-input" />').val(node.label || '')
                    .attr('placeholder', $name.text());
                $name.replaceWith($input);
                $input.trigger('focus').trigger('select');

                const finish = function (save) {
                    if (save) node.label = $input.val().trim();
                    $input.replaceWith('<span class="sb-row-name"></span>');
                    self.refreshRowHeader($row);
                    if (save) self.builderChanged();
                };
                $input.on('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                    if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
                });
                $input.on('blur', function () { finish(true); });
                $input.on('click', function (ev) { ev.stopPropagation(); });
            });

            // Duplicate.
            $(document).on('click', '.qwoo-builder .sb-row-duplicate', function (e) {
                e.preventDefault();
                const $row = $(this).closest('.sb-row');
                const node = $row.data('node');

                if ($row.hasClass('sb-section-row')) {
                    const page = $row.data('page');
                    const list = ShopBuilder.state.pages[page];
                    if (list.length >= (parseInt(shopBuilder.maxSections, 10) || 60)) return;
                    const copy = self.cloneNode(node, 'sec');
                    list.splice(list.indexOf(node) + 1, 0, copy);
                    ui.open.add(copy.id);
                    const $copy = self.buildSectionRow(copy, page);
                    $row.after($copy);
                    self.ensureBody($copy);
                    self.syncPageFromDom(page);
                } else {
                    const $list = $row.parent();
                    const owner = $list.data('owner');
                    if (owner.blocks.length >= (parseInt(shopBuilder.maxBlocks, 10) || 40)) return;
                    const copy = self.cloneNode(node, 'blk');
                    owner.blocks.splice(owner.blocks.indexOf(node) + 1, 0, copy);
                    ui.open.add(copy.id);
                    const $copy = self.buildBlockRow(copy);
                    $row.after($copy);
                    self.ensureBody($copy);
                    self.refreshRowHeaders($list.closest('.sb-row'));
                    self.builderChanged();
                }
            });

            // Remove.
            $(document).on('click', '.qwoo-builder .sb-row-remove', function (e) {
                e.preventDefault();
                const $row = $(this).closest('.sb-row');
                const isSection = $row.hasClass('sb-section-row');
                const node = $row.data('node');
                const what = isSection ? 'section and all its blocks' : ((schema()[node.type] || {}).label || 'block') + ' block';
                if (!confirm(`Remove this ${what}?`)) return;

                F().destroyEditors($row);
                if (isSection) {
                    const page = $row.data('page');
                    $row.remove();
                    self.syncPageFromDom(page);
                } else {
                    const $list = $row.parent();
                    $row.remove();
                    self.syncBlockList($list);
                    self.refreshRowHeaders($list.closest('.sb-row'));
                    self.builderChanged();
                }
            });

            // Add section.
            $(document).on('click', '.qwoo-builder .sb-add-section', function (e) {
                e.preventDefault();
                const page = $(this).data('page');
                const location = $(this).data('location') || null;
                const list = ShopBuilder.state.pages[page];
                if (list.length >= (parseInt(shopBuilder.maxSections, 10) || 60)) {
                    alert('This page already has the maximum number of sections.');
                    return;
                }
                const section = self.newSection(location);
                list.push(section);
                ui.open.add(section.id);

                const $mount = $(`.qwoo-builder[data-page="${page}"]`);
                const $list = location
                    ? $mount.find(`.sb-section-list[data-location="${location}"]`)
                    : $mount.find('.sb-section-list').first();
                const $row = self.buildSectionRow(section, page);
                $list.append($row);
                self.ensureBody($row);
                self.syncPageFromDom(page);
                $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });

            // Collapse / expand all (per page).
            $(document).on('click', '.qwoo-builder .sb-collapse-all, .qwoo-builder .sb-expand-all', function (e) {
                e.preventDefault();
                const open = $(this).hasClass('sb-expand-all');
                $(this).closest('.qwoo-builder').find('.sb-section-row').each(function () {
                    self.setOpen($(this), open);
                });
            });

            // Block picker.
            $(document).on('click', '.qwoo-builder .sb-add-block-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $existing = $(this).siblings('.sb-block-picker');
                if ($existing.length) { $existing.remove(); return; }
                self.openBlockPicker($(this));
            });

            $(document).on('click', '.sb-block-picker-item', function (e) {
                e.preventDefault();
                const $picker = $(this).closest('.sb-block-picker');
                const $list = $picker.data('list');
                const type = $(this).data('type');
                $picker.remove();
                self.addBlock($list, type);
            });

            $(document).on('input', '.sb-block-picker-search', function () {
                const q = $(this).val().toLowerCase();
                const $groups = $(this).siblings('.sb-block-picker-groups');
                $groups.find('.sb-block-picker-item').each(function () {
                    $(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
                });
                $groups.find('.sb-block-picker-group').each(function () {
                    $(this).toggle($(this).find('.sb-block-picker-item').filter(function () { return this.style.display !== 'none'; }).length > 0);
                });
            });

            $(document).on('keydown', '.sb-block-picker', function (e) {
                if (e.key === 'Escape') $(this).remove();
                if (e.key === 'Enter' && $(e.target).is('.sb-block-picker-search')) {
                    e.preventDefault();
                    $(this).find('.sb-block-picker-item:visible').first().trigger('click');
                }
            });

            $(document).on('click', function (e) {
                if (!$(e.target).closest('.sb-block-picker, .sb-add-block-btn').length) $('.sb-block-picker').remove();
            });
        },

        /* =================================================================
           Unsaved-changes tracking
           ================================================================= */

        markDirty: function () {
            ShopBuilder.dirty = true;
            $('#qwoo-unsaved-indicator').prop('hidden', false);
        },

        markClean: function () {
            ShopBuilder.dirty = false;
            $('#qwoo-unsaved-indicator').prop('hidden', true);
        },

        bindDirtyTracking: function () {
            // Regular (non-builder) fields on the other tabs.
            $(document).on('input change', 'form.shop-builder-form :input:not(.sbf-input):not(.sb-enabled-toggle):not(.sb-block-picker-search):not(.sb-row-name-input)', function () {
                if ($(this).closest('.qwoo-builder').length) return;
                ShopBuilder.markDirty();
            });

            $(window).on('beforeunload', function (e) {
                if (!ShopBuilder.dirty) return undefined;
                e.preventDefault();
                e.returnValue = '';
                return '';
            });
        }
    });

})(jQuery);
