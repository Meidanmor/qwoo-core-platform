(function ($) {
    'use strict';

    Object.assign(window.ShopBuilder, {

        /* -------------------------
           Contact Button: add / remove method rows, toggle custom fields
           ------------------------- */
        bindContactMethods: function () {
            let rowSeq = $('.qwoo-contact-method-row').length;

            const placeholders = {
                whatsapp: 'Phone number, no + or spaces (e.g. 15551234567)',
                phone:    'Phone number (e.g. +1 555 123 4567)',
                email:    'name@yourstore.com',
                telegram: '@yourusername',
                custom:   'Full URL (e.g. https://m.me/yourpage)'
            };

            function rowTemplate(index) {
                return '' +
                    '<div class="qwoo-contact-method-row">' +
                        '<div class="qwoo-contact-method-row__top">' +
                            '<select class="qwoo-contact-type" name="shop_builder_options[contact][methods][' + index + '][type]">' +
                                '<option value="whatsapp">WhatsApp</option>' +
                                '<option value="phone">Phone call</option>' +
                                '<option value="email">Email</option>' +
                                '<option value="telegram">Telegram</option>' +
                                '<option value="custom">Custom</option>' +
                            '</select>' +
                            '<label class="qwoo-contact-enabled-label">' +
                                '<input type="checkbox" class="qwoo-switch qwoo-switch--sm" name="shop_builder_options[contact][methods][' + index + '][enabled]" value="1" /> Enabled' +
                            '</label>' +
                            '<button type="button" class="button button-link-delete qwoo-remove-contact-method">Remove</button>' +
                        '</div>' +
                        '<input type="text" class="large-text qwoo-contact-value" name="shop_builder_options[contact][methods][' + index + '][value]" placeholder="' + placeholders.whatsapp + '" />' +
                        '<div class="qwoo-contact-custom-fields qwoo-hidden">' +
                            '<input type="text" class="large-text" name="shop_builder_options[contact][methods][' + index + '][label]" placeholder="Label (e.g. Live Chat)" />' +
                            '<input type="url" class="large-text" name="shop_builder_options[contact][methods][' + index + '][icon]" placeholder="Icon image URL" />' +
                        '</div>' +
                    '</div>';
            }

            $('#qwoo-add-contact-method').on('click', function () {
                rowSeq += 1;
                $('#qwoo-contact-methods').append(rowTemplate(rowSeq));
            });

            $(document).on('click', '.qwoo-remove-contact-method', function () {
                $(this).closest('.qwoo-contact-method-row').remove();
            });

            $(document).on('change', '.qwoo-contact-type', function () {
                const $row = $(this).closest('.qwoo-contact-method-row');
                const type = $(this).val();

                $row.find('.qwoo-contact-custom-fields').toggleClass('qwoo-hidden', type !== 'custom');
                $row.find('.qwoo-contact-value').attr('placeholder', placeholders[type] || '');
            });
        }
    });

})(jQuery);
