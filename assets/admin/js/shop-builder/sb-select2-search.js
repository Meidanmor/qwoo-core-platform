(function ($) {
    'use strict';

    Object.assign(window.ShopBuilder, {

        /**
         * Turns a <select multiple> into an AJAX-searching Select2 picker
         * (products / categories), backed by one of this plugin's
         * wp_ajax_shop_builder_*_search actions. Used by the section builder
         * for every products/categories field.
         */
        initAjaxSelect2: function ($select, ajaxAction) {
            if (!$.fn.select2 || $select.data('select2')) return;
            const esc = ShopBuilder.escHtml;

            $select.select2({
                allowClear: false,
                closeOnSelect: false,
                width: '100%',
                placeholder: $select.data('placeholder'),
                dropdownParent: $select.closest('.custom-select-wrapper'),
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: ajaxAction,
                            term: params.term,
                            security: shopBuilder.nonce
                        };
                    },
                    processResults: function (data) {
                        return {
                            results: (data || []).map(function (item) {
                                return { id: item.id, text: item.text, thumb: item.thumb };
                            })
                        };
                    },
                    cache: true
                },
                templateResult: function (item) {
                    if (!item.id) return esc(item.text);
                    const img = item.thumb
                        ? `<img src="${ShopBuilder.escAttr(item.thumb)}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" />`
                        : '<span style="width:40px; height:40px; border-radius:4px; background:#f0f0f1; display:inline-block;"></span>';
                    return $(`<div style="display:flex; align-items:center; gap:10px;">${img}<span>${esc(item.text)}</span></div>`);
                },
                templateSelection: function (item) { return esc(item.text); },
                escapeMarkup: function (markup) { return markup; }
            });

            this.bindSelect2OpenBehavior($select);
        },

        bindSelect2OpenBehavior: function ($select) {
            const $container = $select.next('.select2-container');

            $container.on('keyup', '.select2-search__field', function () {
                if ($(this).val().length > 0 && !$select.select2('isOpen')) {
                    $select.select2('open');
                }
            });

            $select
                .on('select2:open', function () {
                    const $searchField = $container.find('.select2-search__field');
                    if ($searchField.length > 0 && !$searchField.val()) {
                        $(this).select2('close');
                    }
                })
                .on('select2:unselect', function (e) {
                    const idToRemove = e.params.data.id;
                    $(this).find('option[value="' + idToRemove + '"]').remove();
                    $(this).trigger('change');
                    const self = $(this);
                    setTimeout(function () { self.select2('close'); }, 1);
                });
        }
    });

})(jQuery);
