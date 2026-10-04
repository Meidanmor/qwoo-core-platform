(function ($) {
    'use strict';

    /**
     * Builder quality-of-life, all working on ShopBuilder.state.pages (the
     * page sections — not the Header/Footer/Branding form fields):
     *
     *   Undo / redo   Snapshots of the sections after each change (typing is
     *                 grouped), buttons in the top bar + Ctrl/Cmd+Z, Ctrl+Y /
     *                 Ctrl+Shift+Z when the cursor isn't in a text field.
     *   Versions      Every Save Draft that changed the sections is stored on
     *                 the server (trait-sb-history.php). Restoring loads a
     *                 version as unsaved changes — undoable, saved only on Save.
     *   Copy / paste  Copy any section or block, paste it on any page or into
     *                 any container (also across browser tabs: localStorage).
     *   Templates     Save a section under a name; insert copies of it on any page.
     */

    const F = () => ShopBuilder.Fields;
    const esc = (s) => ShopBuilder.escHtml(s);

    const HISTORY_LIMIT = 100;
    const HISTORY_DEBOUNCE_MS = 500;
    const CLIPBOARD_KEY = 'qwooSbClipboard';

    const history = { stack: [], index: -1, timer: null, restoring: false };
    let memoryClipboard = null; // fallback when localStorage is unavailable

    function schema() { return shopBuilder.blockSchema || {}; }
    function sectionStyleFields() { return shopBuilder.sectionStyleFields || {}; }
    function blockCommonFields() { return shopBuilder.blockCommonFields || {}; }
    function maxDepth() { return parseInt(shopBuilder.maxNestingDepth, 10) || 4; }
    function maxSections() { return parseInt(shopBuilder.maxSections, 10) || 60; }
    function maxBlocks() { return parseInt(shopBuilder.maxBlocks, 10) || 40; }

    function ajax(action, data) {
        return $.post(ajaxurl, Object.assign({ action: action, nonce: shopBuilder.nonce }, data || {}))
            .then(function (res) {
                if (!res || !res.success) return $.Deferred().reject((res && res.data) || 'Unknown error');
                return res.data;
            }, function () {
                return $.Deferred().reject('Connection error. Please try again.');
            });
    }

    /** Merges media previews + entity names from the server into the builder state. */
    function mergeRefs(payload) {
        Object.assign(ShopBuilder.state.media, (payload && payload.media) || {});
        const labels = (payload && payload.labels) || {};
        ['products', 'categories', 'tags'].forEach(function (k) {
            ShopBuilder.state.labels[k] = Object.assign(ShopBuilder.state.labels[k] || {}, labels[k] || {});
        });
    }

    function isTypingTarget(el) {
        return !!el && ($(el).is('input, textarea, select, [contenteditable], [contenteditable] *'));
    }

    Object.assign(window.ShopBuilder, {

        initHistory: function () {
            if (!ShopBuilder.state) return;
            history.stack = [this.snapshot()];
            history.index = 0;
            this.updateHistoryButtons();
            this.updateClipboardUi();

            $(document).on('qwoo:builder-change', function () {
                if (history.restoring) return;
                clearTimeout(history.timer);
                history.timer = setTimeout(function () { ShopBuilder.commitHistory(); }, HISTORY_DEBOUNCE_MS);
            });

            $(document).on('click', '#qwoo-undo', function (e) { e.preventDefault(); ShopBuilder.undo(); });
            $(document).on('click', '#qwoo-redo', function (e) { e.preventDefault(); ShopBuilder.redo(); });
            $(document).on('click', '#qwoo-versions', function (e) { e.preventDefault(); ShopBuilder.openVersions(); });

            $(document).on('keydown', function (e) {
                if (!(e.ctrlKey || e.metaKey) || e.altKey || isTypingTarget(e.target)) return;
                // Only while a section builder tab is on screen.
                if (!$('.tab-content.active .qwoo-builder').length) return;
                const key = String(e.key).toLowerCase();
                if (key === 'z' && !e.shiftKey) { e.preventDefault(); ShopBuilder.undo(); }
                else if ((key === 'z' && e.shiftKey) || key === 'y') { e.preventDefault(); ShopBuilder.redo(); }
            });

            this.bindClipboardEvents();
            this.bindTemplateEvents();
        },

        /* =================================================================
           Undo / redo
           ================================================================= */

        snapshot: function () {
            return JSON.stringify(ShopBuilder.state.pages);
        },

        commitHistory: function () {
            clearTimeout(history.timer);
            history.timer = null;
            const snap = this.snapshot();
            if (snap === history.stack[history.index]) return;
            history.stack = history.stack.slice(0, history.index + 1);
            history.stack.push(snap);
            if (history.stack.length > HISTORY_LIMIT) history.stack.shift();
            history.index = history.stack.length - 1;
            this.updateHistoryButtons();
        },

        undo: function () {
            if (history.timer) this.commitHistory(); // include the edit still being typed
            if (history.index <= 0) return;
            history.index--;
            this.restorePages(JSON.parse(history.stack[history.index]));
        },

        redo: function () {
            if (history.timer) this.commitHistory();
            if (history.index >= history.stack.length - 1) return;
            history.index++;
            this.restorePages(JSON.parse(history.stack[history.index]));
        },

        /** Replaces the builder's pages and re-renders every builder. */
        restorePages: function (pages) {
            if (window.tinymce) window.tinymce.triggerSave();
            history.restoring = true;
            try {
                ShopBuilder.state.pages = pages;
                $('.qwoo-builder').each(function () { ShopBuilder.renderPage($(this).data('page')); });
                ShopBuilder.builderChanged();
            } finally {
                history.restoring = false;
            }
            this.updateHistoryButtons();
            this.updateClipboardUi();
        },

        updateHistoryButtons: function () {
            $('#qwoo-undo').prop('disabled', history.index <= 0);
            $('#qwoo-redo').prop('disabled', history.index >= history.stack.length - 1);
        },

        /* =================================================================
           Versions
           ================================================================= */

        openModal: function (title, $content) {
            $('.sb-modal-backdrop').remove();
            const $backdrop = $('<div class="sb-modal-backdrop"></div>');
            const $modal = $(`
                <div class="sb-modal" role="dialog" aria-modal="true" aria-label="${esc(title)}">
                    <div class="sb-modal__header">
                        <h2>${esc(title)}</h2>
                        <button type="button" class="button-link sb-modal__close" aria-label="Close">&#10005;</button>
                    </div>
                    <div class="sb-modal__body"></div>
                </div>`);
            $modal.find('.sb-modal__body').append($content);
            $backdrop.append($modal).appendTo('body');

            const close = function () { $backdrop.remove(); $(document).off('keydown.sbmodal'); };
            $backdrop.on('click', function (e) { if (e.target === $backdrop[0]) close(); });
            $modal.on('click', '.sb-modal__close', close);
            $(document).on('keydown.sbmodal', function (e) { if (e.key === 'Escape') close(); });
            $modal.find('.sb-modal__close').trigger('focus');
            return { $modal: $modal, close: close };
        },

        openVersions: function () {
            const $content = $('<div class="sb-versions"><p class="sb-versions__loading">Loading versions…</p></div>');
            const modal = this.openModal('Saved versions', $content);

            ajax('shop_builder_list_revisions').then(function (list) {
                $content.empty().append(`
                    <p class="description">A version is stored every time you click <strong>Save Draft</strong> with changed sections
                    (the ${parseInt(shopBuilder.maxRevisions, 10) || 20} most recent are kept). Restoring loads it into the builder as unsaved changes —
                    you can undo it, and nothing changes on the site until you save and push. Versions cover the page sections
                    (Homepage, Shop, Category, Product), not the Header, Footer or Branding settings.</p>`);
                if (!list.length) {
                    $content.append('<p><em>No saved versions yet — they start with your next Save Draft.</em></p>');
                    return;
                }
                const $list = $('<ul class="sb-versions__list"></ul>');
                list.forEach(function (v, i) {
                    const counts = Object.keys(v.counts || {}).filter(function (p) { return v.counts[p]; })
                        .map(function (p) { return `${p}: ${v.counts[p]}`; }).join(' · ');
                    $list.append(`
                        <li class="sb-versions__item">
                            <div class="sb-versions__meta">
                                <strong>${esc(v.date)}</strong>
                                <span class="sb-versions__ago">${esc(v.ago)}${v.user ? ' by ' + esc(v.user) : ''}</span>
                                ${i === 0 ? '<span class="sb-badge">Latest save</span>' : ''}
                                ${v.pushed ? '<span class="sb-badge sb-badge--live">Pushed live</span>' : ''}
                                <span class="sb-versions__counts">${esc(counts ? counts + ' sections' : 'No sections')}</span>
                            </div>
                            <button type="button" class="button sb-versions__restore" data-id="${esc(v.id)}">Restore</button>
                        </li>`);
                });
                $content.append($list);
            }, function (err) {
                $content.html(`<p class="sb-error">Could not load versions: ${esc(err)}</p>`);
            });

            $content.on('click', '.sb-versions__restore', function () {
                const $btn = $(this);
                if (!confirm('Load this version into the builder? It replaces the current sections on all pages (you can undo this), and nothing is saved until you click Save Draft.')) return;
                $btn.prop('disabled', true).text('Loading…');
                ajax('shop_builder_get_revision', { id: $btn.data('id') }).then(function (data) {
                    mergeRefs(data);
                    if (history.timer) ShopBuilder.commitHistory();
                    const pages = Object.assign({}, ShopBuilder.state.pages, data.pages || {});
                    ShopBuilder.restorePages(JSON.parse(JSON.stringify(pages)));
                    ShopBuilder.commitHistory();
                    modal.close();
                    ShopBuilder.showStatus('Version restored — click Save Draft to keep it.', '#2271b1', 6000);
                }, function (err) {
                    $btn.prop('disabled', false).text('Restore');
                    alert('Could not restore that version: ' + err);
                });
            });
        },

        /* =================================================================
           Copy / paste
           ================================================================= */

        /** Media + entity labels referenced by a node, so a paste elsewhere can show previews. */
        nodeRefs: function (node) {
            const refs = { media: {}, labels: { products: {}, categories: {}, tags: {} } };
            const st = ShopBuilder.state;
            const add = function (type, id) {
                if (!id) return;
                if (type === 'image' || type === 'video') {
                    if (st.media[id]) refs.media[id] = st.media[id];
                } else if (refs.labels[type] && st.labels[type] && st.labels[type][id]) {
                    refs.labels[type][id] = st.labels[type][id];
                }
            };
            const walkFields = function (specs, data) {
                Object.keys(specs || {}).forEach(function (key) {
                    const spec = specs[key];
                    const v = data ? data[key] : undefined;
                    if (spec.type === 'repeater') {
                        (Array.isArray(v) ? v : []).forEach(function (item) { walkFields(spec.fields, item); });
                        return;
                    }
                    const values = spec.responsive && v && typeof v === 'object' && !Array.isArray(v) ? Object.values(v) : [v];
                    values.forEach(function (val) {
                        if (Array.isArray(val)) val.forEach(function (id) { add(spec.type, id); });
                        else add(spec.type, val);
                    });
                });
            };
            const walkNode = function (n) {
                if (!n.type || n.type === 'section') {
                    walkFields(sectionStyleFields(), n.style);
                    (n.blocks || []).forEach(walkNode);
                } else {
                    walkFields(blockCommonFields(), n.style);
                    walkFields((schema()[n.type] || {}).fields, n.data);
                }
            };
            walkNode(node);
            return refs;
        },

        readClipboard: function () {
            try {
                const raw = window.localStorage && localStorage.getItem(CLIPBOARD_KEY);
                if (raw) return JSON.parse(raw);
            } catch (e) { /* storage unavailable or corrupt */ }
            return memoryClipboard;
        },

        copyNode: function ($row) {
            if (window.tinymce) window.tinymce.triggerSave();
            const node = $row.data('node');
            const isSection = $row.hasClass('sb-section-row') || node.type === 'section';
            const title = this.rowTitle(node, isSection).name;
            const clip = Object.assign({
                v: 1,
                kind: isSection ? 'section' : 'block',
                title: title,
                node: JSON.parse(JSON.stringify(node))
            }, this.nodeRefs(node));
            memoryClipboard = clip;
            try { if (window.localStorage) localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(clip)); } catch (e) { /* too big or blocked: memory only */ }
            this.updateClipboardUi();
            this.showStatus(`Copied “${esc(title)}” — use Paste on any page.`, '#2271b1', 4000);
        },

        updateClipboardUi: function () {
            const clip = this.readClipboard();
            $('body').toggleClass('sb-has-clipboard', !!(clip && clip.node));
            $('.sb-paste-btn').attr('title', clip && clip.node ? `Paste “${clip.title || clip.kind}”` : '');
        },

        /** A copy as a top-level section (a copied block gets wrapped in a new section). */
        asTopLevelSection: function (node, kind, location) {
            let section;
            if (kind === 'section') {
                section = this.cloneNode(node, 'sec');
                delete section.type;
            } else {
                section = this.newSection(location);
                section.blocks = [this.cloneNode(node, 'blk')];
            }
            delete section.location;
            if (location) section.location = location;
            return section;
        },

        insertSection: function (page, location, section) {
            const list = ShopBuilder.state.pages[page];
            if (list.length >= maxSections()) {
                alert('This page already has the maximum number of sections.');
                return false;
            }
            list.push(section);
            const $mount = $(`.qwoo-builder[data-page="${page}"]`);
            const $list = location
                ? $mount.find(`.sb-section-list[data-location="${location}"]`)
                : $mount.find('.sb-section-list').first();
            const $row = this.buildSectionRow(section, page);
            $list.append($row);
            this.syncPageFromDom(page);
            $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return true;
        },

        pasteIntoPage: function (page, location) {
            const clip = this.readClipboard();
            if (!clip || !clip.node) return;
            mergeRefs({ media: clip.media, labels: clip.labels });
            this.insertSection(page, location, this.asTopLevelSection(clip.node, clip.kind, location));
        },

        pasteIntoBlockList: function ($list) {
            const clip = this.readClipboard();
            if (!clip || !clip.node) return;
            const owner = $list.data('owner');
            if (owner.blocks.length >= maxBlocks()) {
                alert('This container already has the maximum number of blocks.');
                return;
            }

            let block;
            if (clip.kind === 'section') {
                // A section pasted inside a container becomes a nested section.
                block = this.cloneNode(clip.node, 'blk');
                block.type = 'section';
                delete block.location;
                if (this.listDepth($list) + this.subtreeHeight(block) > maxDepth()) {
                    alert('That section can\'t be nested this deep (max ' + maxDepth() + ' levels).');
                    return;
                }
            } else {
                block = this.cloneNode(clip.node, 'blk');
            }

            mergeRefs({ media: clip.media, labels: clip.labels });
            owner.blocks.push(block);
            const $row = this.buildBlockRow(block);
            $list.append($row).removeClass('is-empty');
            this.refreshRowHeaders($list.closest('.sb-row'));
            this.builderChanged();
            $row[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        bindClipboardEvents: function () {
            $(document).on('click', '.qwoo-builder .sb-row-copy', function (e) {
                e.preventDefault();
                ShopBuilder.copyNode($(this).closest('.sb-row'));
            });
            $(document).on('click', '.qwoo-builder .sb-paste-section', function (e) {
                e.preventDefault();
                ShopBuilder.pasteIntoPage($(this).data('page'), $(this).data('location') || null);
            });
            $(document).on('click', '.qwoo-builder .sb-paste-block', function (e) {
                e.preventDefault();
                ShopBuilder.pasteIntoBlockList($(this).closest('.sb-blocks-panel').children('.sb-block-list'));
            });
            // Copied in another browser tab.
            $(window).on('storage', function (e) {
                if (e.originalEvent && e.originalEvent.key === CLIPBOARD_KEY) ShopBuilder.updateClipboardUi();
            });
        },

        /* =================================================================
           Templates
           ================================================================= */

        saveAsTemplate: function ($row) {
            if (window.tinymce) window.tinymce.triggerSave();
            const node = $row.data('node');
            const name = window.prompt('Template name:', this.rowTitle(node, true).name || 'My section');
            if (name === null) return;
            if (!name.trim()) { alert('Please give the template a name.'); return; }
            ajax('shop_builder_save_template', { name: name.trim(), section: JSON.stringify(node) }).then(function (list) {
                shopBuilder.templates = list;
                ShopBuilder.showStatus(`Saved template “${esc(name.trim())}”.`, 'green', 4000);
            }, function (err) {
                alert('Could not save the template: ' + err);
            });
        },

        openTemplatePicker: function ($btn) {
            $('.sb-template-picker').remove();
            const page = $btn.data('page');
            const location = $btn.data('location') || null;
            const $picker = $('<div class="sb-block-picker sb-template-picker" role="dialog" aria-label="Insert a section template"></div>');

            const render = function () {
                const list = shopBuilder.templates || [];
                $picker.empty().append('<h5 class="sb-block-picker-group-title">Section templates</h5>');
                if (!list.length) {
                    $picker.append('<p class="description">No templates yet. Use the <span class="dashicons dashicons-portfolio"></span> button on any section to save one.</p>');
                    return;
                }
                const $ul = $('<ul class="sb-template-list"></ul>');
                list.forEach(function (t) {
                    $ul.append(`
                        <li>
                            <button type="button" class="sb-template-insert" data-id="${esc(t.id)}">
                                <span class="dashicons dashicons-portfolio"></span>
                                <span class="sb-template-name">${esc(t.name)}</span>
                                <span class="sb-template-meta">${esc(t.blocks)} block${t.blocks === 1 ? '' : 's'} · ${esc(t.date)}</span>
                            </button>
                            <button type="button" class="button-link button-link-delete sb-template-delete" data-id="${esc(t.id)}" title="Delete template"><span class="dashicons dashicons-trash"></span></button>
                        </li>`);
                });
                $picker.append($ul);
            };
            render();

            $picker.on('click', '.sb-template-insert', function () {
                const $b = $(this).prop('disabled', true);
                ajax('shop_builder_get_template', { id: $b.data('id') }).then(function (data) {
                    mergeRefs(data);
                    const section = ShopBuilder.asTopLevelSection(data.section, 'section', location);
                    $picker.remove();
                    ShopBuilder.insertSection(page, location, section);
                }, function (err) {
                    $b.prop('disabled', false);
                    alert('Could not insert the template: ' + err);
                });
            });
            $picker.on('click', '.sb-template-delete', function () {
                const $b = $(this);
                if (!confirm('Delete this template? Sections already inserted from it are not affected.')) return;
                ajax('shop_builder_delete_template', { id: $b.data('id') }).then(function (list) {
                    shopBuilder.templates = list;
                    render();
                }, function (err) {
                    alert('Could not delete the template: ' + err);
                });
            });
            $picker.on('keydown', function (e) { if (e.key === 'Escape') $picker.remove(); });

            $btn.after($picker);
        },

        bindTemplateEvents: function () {
            $(document).on('click', '.qwoo-builder .sb-row-template', function (e) {
                e.preventDefault();
                ShopBuilder.saveAsTemplate($(this).closest('.sb-row'));
            });
            $(document).on('click', '.qwoo-builder .sb-insert-template', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if ($(this).siblings('.sb-template-picker').length) { $('.sb-template-picker').remove(); return; }
                ShopBuilder.openTemplatePicker($(this));
            });
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.sb-template-picker, .sb-insert-template').length) $('.sb-template-picker').remove();
            });
        }
    });

})(jQuery);
