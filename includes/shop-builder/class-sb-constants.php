<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * All constants shared across the Shop Builder trait files. Split out into
 * its own base class (rather than a trait) because class constants can only
 * live on a class/interface on the PHP versions this plugin targets — every
 * other Shop Builder file is a trait that gets `use`'d onto
 * Shop_Settings_Builder, which extends this class so `self::WHATEVER`
 * resolves the same way everywhere.
 *
 * Nothing in here should ever need WordPress functions at load time — it's
 * pure data. See class-shop-settings-builder.php for the require order.
 *
 * ---------------------------------------------------------------------
 * SCHEMA V3 — one field schema drives everything
 * ---------------------------------------------------------------------
 * A page's `sections[]` is a list of styled containers; each container
 * holds an ordered `blocks[]` of typed content atoms (BLOCK_SCHEMA) and a
 * `section` block is itself a nested container. That part is unchanged
 * from v2.
 *
 * What v3 changes is that every field — container style, block content,
 * block style, repeater items — is described ONCE, in the field-spec
 * format below, and that single description drives:
 *   - the wp-admin builder UI (localized to JS, rendered by sb-builder-fields.js),
 *   - server-side sanitizing (sanitize_fields() in trait-sb-sanitizers.php),
 *   - the public JSON output (output_fields(): image IDs -> {url,width,height},
 *     responsive values fully resolved, private fields stripped).
 * So adding a field is a one-line change here plus reading it in the Vue
 * component — no PHP template, JS template, or sanitizer case to keep in sync.
 *
 * Field spec keys:
 *   type        text | textarea | rich_text | html | url | email | number |
 *               length | color | select | toggle | image | products |
 *               categories | icon | sides | devices | anchor | hidden | repeater
 *   label       Admin label.
 *   tab         Which tab of the row it appears on (content / style / layout / advanced).
 *   group       Optional sub-heading inside the tab.
 *   default     Default value (for a responsive field: the desktop value, or
 *               a full [desktop, tablet, mobile] array).
 *   responsive  true = value is [desktop, tablet, mobile]. An empty tablet/
 *               mobile value means "inherit from the next larger device".
 *   options     select: [value => label]
 *   min/max     number bounds
 *   show_if     [sibling_field => value | [values]] — only shown (and only
 *               meaningful) when the sibling matches.
 *   private     Never leaves WordPress (stripped from preview + GitHub JSON).
 *   fields      repeater: item field specs
 *   max_items   repeater: cap
 *   require     repeater: an item missing this field is dropped on save
 *   title_field repeater: which item field labels the collapsed item row
 */
abstract class Shop_Builder_Constants {

    const SCHEMA_VERSION = 3;

    /** Post type storing Form block submissions (see trait-sb-forms.php). */
    const FORM_ENTRY_POST_TYPE = 'qwoo_form_entry';

    /** Device keys for responsive values, largest first (inheritance order). */
    const DEVICES = [ 'desktop', 'tablet', 'mobile' ];

    /**
     * Breakpoints, mirrored in the frontend CSS (src/css/app.css):
     * desktop >= 1024px, tablet 768–1023px, mobile <= 767px.
     */
    const BREAKPOINTS = [ 'tablet_max' => 1023, 'mobile_max' => 767 ];

    /**
     * Site-wide color palette, editable on the Branding tab. Any color field
     * anywhere in Shop Builder can store "global:{key}" instead of a literal
     * hex value — the frontend maps it to the matching CSS var at render
     * time, so changing a global color updates every section instantly.
     */
    const GLOBAL_COLOR_KEYS = [
            'primary'   => [ 'label' => 'Primary',   'var' => '--q-primary' ],
            'secondary' => [ 'label' => 'Secondary', 'var' => '--q-secondary' ],
            'accent'    => [ 'label' => 'Accent',    'var' => '--q-accent' ],
            'text'      => [ 'label' => 'Text',      'var' => '--q-text' ],
    ];

    /* ------------------------------------------------------------------
       Shared option lists
       ------------------------------------------------------------------ */

    const ALIGN_OPTIONS = [ 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ];

    const FLEX_DIRECTION_OPTIONS = [
            'column'         => 'Vertical (stacked)',
            'row'            => 'Horizontal (side by side)',
            'column-reverse' => 'Vertical, reversed',
            'row-reverse'    => 'Horizontal, reversed',
    ];

    const JUSTIFY_OPTIONS = [
            'flex-start'    => 'Start',
            'center'        => 'Center',
            'flex-end'      => 'End',
            'space-between' => 'Space between',
            'space-around'  => 'Space around',
            'space-evenly'  => 'Space evenly',
    ];

    const ALIGN_ITEMS_OPTIONS = [
            'stretch'    => 'Stretch',
            'flex-start' => 'Start',
            'center'     => 'Center',
            'flex-end'   => 'End',
    ];

    const FONT_WEIGHT_OPTIONS = [
            ''    => 'Default',
            '300' => 'Light (300)',
            '400' => 'Regular (400)',
            '500' => 'Medium (500)',
            '600' => 'Semi-bold (600)',
            '700' => 'Bold (700)',
            '800' => 'Extra-bold (800)',
    ];

    const BG_POSITION_OPTIONS = [
            'center center' => 'Center',
            'center top'    => 'Top',
            'center bottom' => 'Bottom',
            'left center'   => 'Left',
            'right center'  => 'Right',
            'left top'      => 'Top left',
            'right top'     => 'Top right',
            'left bottom'   => 'Bottom left',
            'right bottom'  => 'Bottom right',
    ];

    /**
     * Padding presets per device (px, vertical — horizontal is always the
     * 16px page gutter). Shown in the admin labels; the actual values live in
     * the frontend CSS (.sb-pad-*) and must match these.
     */
    const SECTION_PADDING_PRESETS = [
            'small'  => [ 'desktop' => 90,  'tablet' => 60,  'mobile' => 24 ],
            'medium' => [ 'desktop' => 120, 'tablet' => 80,  'mobile' => 48 ],
            'large'  => [ 'desktop' => 150, 'tablet' => 110, 'mobile' => 80 ],
    ];

    /**
     * Background fields shared by containers (SECTION_STYLE_FIELDS) and
     * every block (BLOCK_COMMON_FIELDS). Rendered by buildBackground() in
     * the frontend's useSectionStyle.js.
     *
     * Video backgrounds play muted and looped behind the content; the
     * poster image shows while the video loads, for visitors who prefer
     * reduced motion, and (optionally) on phones instead of the video.
     * Uploaded videos stay on WordPress (see github_image_resolver()).
     */
    const BACKGROUND_FIELDS = [
            'bg_type' => [ 'type' => 'select', 'label' => 'Background', 'tab' => 'style', 'group' => 'Background',
                    'options' => [ 'none' => 'None', 'color' => 'Color', 'gradient' => 'Gradient', 'image' => 'Image', 'video' => 'Video' ], 'default' => 'none' ],
            'bg_color' => [ 'type' => 'color', 'label' => 'Background Color', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'color' ] ],
            'bg_gradient_color1' => [ 'type' => 'color', 'label' => 'Gradient Start', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_gradient_color2' => [ 'type' => 'color', 'label' => 'Gradient End', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_gradient_angle' => [ 'type' => 'number', 'label' => 'Gradient Angle (°)', 'tab' => 'style', 'group' => 'Background', 'min' => 0, 'max' => 360, 'default' => 180,
                    'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_image' => [ 'type' => 'image', 'label' => 'Background Image', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'image' ] ],
            'bg_video_source' => [ 'type' => 'select', 'label' => 'Video Source', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'video' ],
                    'options' => [ 'upload' => 'Upload (media library)', 'youtube' => 'YouTube', 'vimeo' => 'Vimeo' ], 'default' => 'upload' ],
            'bg_video' => [ 'type' => 'video', 'label' => 'Background Video', 'tab' => 'style', 'group' => 'Background',
                    'show_if' => [ 'bg_type' => 'video', 'bg_video_source' => 'upload' ],
                    'help' => 'A short, compressed MP4 (a few MB) works best. It plays muted and loops.' ],
            'bg_video_link' => [ 'type' => 'url', 'label' => 'Video Link', 'tab' => 'style', 'group' => 'Background',
                    'show_if' => [ 'bg_type' => 'video', 'bg_video_source' => [ 'youtube', 'vimeo' ] ],
                    'placeholder' => 'https://www.youtube.com/watch?v=…',
                    'url_hosts' => [ 'field' => 'bg_video_source', 'map' => [ 'youtube' => 'youtube', 'vimeo' => 'vimeo' ] ],
                    'help' => 'Plays muted, looped and without controls. Uploads look cleanest — YouTube may briefly show its title when the video starts.' ],
            'bg_video_poster' => [ 'type' => 'image', 'label' => 'Poster Image', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'video' ],
                    'help' => 'Shown while the video loads, and to visitors who turned off animations.' ],
            'bg_video_mobile_poster' => [ 'type' => 'toggle', 'label' => 'Show the poster instead of the video on mobile (saves data)', 'tab' => 'style', 'group' => 'Background',
                    'show_if' => [ 'bg_type' => 'video' ] ],
            'bg_image_size' => [ 'type' => 'select', 'label' => 'Image Size', 'tab' => 'style', 'group' => 'Background', 'responsive' => true,
                    'options' => [ 'cover' => 'Cover', 'contain' => 'Contain', 'auto' => 'Original size' ], 'default' => 'cover', 'show_if' => [ 'bg_type' => 'image' ] ],
            'bg_image_position' => [ 'type' => 'select', 'label' => 'Image / Video Position', 'tab' => 'style', 'group' => 'Background', 'responsive' => true,
                    'options' => self::BG_POSITION_OPTIONS, 'default' => 'center center', 'show_if' => [ 'bg_type' => [ 'image', 'video' ] ] ],
            'bg_fixed' => [ 'type' => 'toggle', 'label' => 'Fixed (parallax) on desktop', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'image' ] ],
            'bg_overlay_color' => [ 'type' => 'color', 'label' => 'Overlay Color', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => [ 'image', 'video' ] ] ],
            'bg_overlay_opacity' => [ 'type' => 'number', 'label' => 'Overlay Opacity (%)', 'tab' => 'style', 'group' => 'Background', 'min' => 0, 'max' => 100, 'default' => 50,
                    'show_if' => [ 'bg_type' => [ 'image', 'video' ] ] ],
    ];

    /* ------------------------------------------------------------------
       Container (section) style fields — top-level sections AND nested
       `section` blocks share this exact list.
       ------------------------------------------------------------------ */
    const SECTION_STYLE_FIELDS = [
            // --- Layout tab ---
            'width_mode' => [ 'type' => 'select', 'label' => 'Content Width', 'tab' => 'layout', 'group' => 'Size',
                    'options' => [ 'full' => 'Full width', 'contained' => 'Contained (1200px)', 'custom' => 'Custom' ], 'default' => 'contained' ],
            'width' => [ 'type' => 'length', 'label' => 'Custom Width', 'tab' => 'layout', 'group' => 'Size', 'responsive' => true,
                    'placeholder' => 'e.g. 800px or 50%', 'show_if' => [ 'width_mode' => 'custom' ] ],
            'min_height' => [ 'type' => 'length', 'label' => 'Min Height', 'tab' => 'layout', 'group' => 'Size', 'responsive' => true,
                    'placeholder' => 'e.g. 400px or 60vh' ],

            'direction' => [ 'type' => 'select', 'label' => 'Block Direction', 'tab' => 'layout', 'group' => 'Arrange blocks', 'responsive' => true,
                    'options' => self::FLEX_DIRECTION_OPTIONS, 'default' => 'column' ],
            'wrap' => [ 'type' => 'select', 'label' => 'Wrap', 'tab' => 'layout', 'group' => 'Arrange blocks', 'responsive' => true,
                    'options' => [ 'nowrap' => 'No wrap', 'wrap' => 'Wrap onto new lines' ], 'default' => 'nowrap' ],
            'justify' => [ 'type' => 'select', 'label' => 'Justify', 'tab' => 'layout', 'group' => 'Arrange blocks', 'responsive' => true,
                    'options' => self::JUSTIFY_OPTIONS, 'default' => 'flex-start' ],
            'align_items' => [ 'type' => 'select', 'label' => 'Align Items', 'tab' => 'layout', 'group' => 'Arrange blocks', 'responsive' => true,
                    'options' => self::ALIGN_ITEMS_OPTIONS, 'default' => 'stretch' ],
            'align_content' => [ 'type' => 'select', 'label' => 'Align Lines (when wrapping)', 'tab' => 'layout', 'group' => 'Arrange blocks',
                    'options' => [ 'normal' => 'Default', 'flex-start' => 'Start', 'center' => 'Center', 'flex-end' => 'End', 'space-between' => 'Space between', 'space-around' => 'Space around', 'stretch' => 'Stretch' ],
                    'default' => 'normal' ],
            'gap' => [ 'type' => 'length', 'label' => 'Gap Between Blocks', 'tab' => 'layout', 'group' => 'Arrange blocks', 'responsive' => true,
                    'placeholder' => 'e.g. 24px' ],

            // --- Style tab ---
            'padding_preset' => [ 'type' => 'select', 'label' => 'Padding', 'tab' => 'style', 'group' => 'Spacing',
                    'options' => [ 'none' => 'None', 'small' => 'Small (90 / 60 / 24px)', 'medium' => 'Medium (120 / 80 / 48px)', 'large' => 'Large (150 / 110 / 80px)', 'custom' => 'Custom' ],
                    'default' => 'medium' ],
            'padding' => [ 'type' => 'sides', 'label' => 'Custom Padding', 'tab' => 'style', 'group' => 'Spacing', 'responsive' => true,
                    'show_if' => [ 'padding_preset' => 'custom' ] ],
            'margin_top' => [ 'type' => 'length', 'label' => 'Space Above', 'tab' => 'style', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true ],
            'margin_bottom' => [ 'type' => 'length', 'label' => 'Space Below', 'tab' => 'style', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true ],

    ] + self::BACKGROUND_FIELDS + [

            'text_color' => [ 'type' => 'color', 'label' => 'Default Text Color', 'tab' => 'style', 'group' => 'Colors & Border' ],
            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Colors & Border', 'placeholder' => 'e.g. 12px' ],

            // --- Advanced tab ---
            'hide_on' => [ 'type' => 'devices', 'label' => 'Hide On', 'tab' => 'advanced' ],
            'anchor_id' => [ 'type' => 'anchor', 'label' => 'Anchor ID', 'tab' => 'advanced',
                    'help' => 'Lets a button link to this section with #your-id.' ],
    ];

    /**
     * Wrapper fields every non-section block gets. `tab => style` ones
     * (box + background) are appended to the block's Style tab; the rest
     * form its Advanced tab: spacing around it, its width inside a
     * horizontal container, visibility per device, and an anchor.
     */
    const BLOCK_COMMON_FIELDS = [
            'box_padding' => [ 'type' => 'sides', 'label' => 'Inner Padding', 'tab' => 'style', 'group' => 'Box', 'responsive' => true,
                    'help' => 'Space between the block\'s edge (or background) and its content.' ],
            'box_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Box', 'placeholder' => 'e.g. 12px' ],
    ] + self::BACKGROUND_FIELDS + [
            'margin_top' => [ 'type' => 'length', 'label' => 'Space Above', 'tab' => 'advanced', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true ],
            'margin_bottom' => [ 'type' => 'length', 'label' => 'Space Below', 'tab' => 'advanced', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true ],
            'width' => [ 'type' => 'length', 'label' => 'Block Width', 'tab' => 'advanced', 'group' => 'Size', 'responsive' => true,
                    'placeholder' => 'auto, 50%, 320px',
                    'help' => 'Mostly useful when the parent section arranges blocks horizontally.' ],
            'max_width' => [ 'type' => 'length', 'label' => 'Max Width', 'tab' => 'advanced', 'group' => 'Size', 'responsive' => true, 'placeholder' => 'e.g. 640px' ],
            'hide_on' => [ 'type' => 'devices', 'label' => 'Hide On', 'tab' => 'advanced', 'group' => 'Visibility' ],
            'anchor_id' => [ 'type' => 'anchor', 'label' => 'Anchor ID', 'tab' => 'advanced', 'group' => 'Visibility' ],
    ];

    /**
     * Allowed icon keys for Advantages cards. Each entry carries the Material
     * Icons ligature name — mirrored in the frontend's AdvantagesSection.vue
     * iconMap, which MUST be kept in sync with this list.
     */
    const ADVANTAGE_ICONS = [
            'shipping'  => [ 'label' => 'Shipping / Delivery',  'icon' => 'local_shipping' ],
            'organic'   => [ 'label' => 'Organic / Eco',        'icon' => 'spa' ],
            'guarantee' => [ 'label' => 'Guarantee / Warranty', 'icon' => 'verified_user' ],
            'returns'   => [ 'label' => 'Easy Returns',         'icon' => 'assignment_return' ],
            'support'   => [ 'label' => 'Customer Support',     'icon' => 'headset_mic' ],
            'quality'   => [ 'label' => 'Quality',              'icon' => 'grade' ],
    ];

    /**
     * General-purpose icons (Icon List block). Same format as
     * ADVANTAGE_ICONS; mirrored in the frontend's src/utils/builder-icons.js,
     * which MUST contain every key listed here.
     */
    const GENERAL_ICONS = [
            'check'        => [ 'label' => 'Check',         'icon' => 'check' ],
            'check_circle' => [ 'label' => 'Check circle',  'icon' => 'check_circle' ],
            'star'         => [ 'label' => 'Star',          'icon' => 'star' ],
            'favorite'     => [ 'label' => 'Heart',         'icon' => 'favorite' ],
            'shipping'     => [ 'label' => 'Shipping',      'icon' => 'local_shipping' ],
            'verified'     => [ 'label' => 'Verified',      'icon' => 'verified' ],
            'lock'         => [ 'label' => 'Secure',        'icon' => 'lock' ],
            'support'      => [ 'label' => 'Support',       'icon' => 'support_agent' ],
            'phone'        => [ 'label' => 'Phone',         'icon' => 'phone' ],
            'email'        => [ 'label' => 'Email',         'icon' => 'email' ],
            'location'     => [ 'label' => 'Location',      'icon' => 'location_on' ],
            'schedule'     => [ 'label' => 'Clock',         'icon' => 'schedule' ],
            'bolt'         => [ 'label' => 'Fast',          'icon' => 'bolt' ],
            'spa'          => [ 'label' => 'Natural',       'icon' => 'spa' ],
            'recycling'    => [ 'label' => 'Recycling',     'icon' => 'recycling' ],
            'gift'         => [ 'label' => 'Gift',          'icon' => 'card_giftcard' ],
            'loyalty'      => [ 'label' => 'Loyalty',       'icon' => 'loyalty' ],
            'sell'         => [ 'label' => 'Tag / Sale',    'icon' => 'sell' ],
            'payments'     => [ 'label' => 'Payments',      'icon' => 'payments' ],
            'card'         => [ 'label' => 'Credit card',   'icon' => 'credit_card' ],
            'returns'      => [ 'label' => 'Returns',       'icon' => 'autorenew' ],
            'thumb_up'     => [ 'label' => 'Thumbs up',     'icon' => 'thumb_up' ],
            'award'        => [ 'label' => 'Award',         'icon' => 'emoji_events' ],
            'inventory'    => [ 'label' => 'Stock',         'icon' => 'inventory' ],
            'bag'          => [ 'label' => 'Shopping bag',  'icon' => 'shopping_bag' ],
            'info'         => [ 'label' => 'Info',          'icon' => 'info' ],
            'help'         => [ 'label' => 'Help',          'icon' => 'help' ],
            'arrow'        => [ 'label' => 'Arrow',         'icon' => 'arrow_forward' ],
            'globe'        => [ 'label' => 'Worldwide',     'icon' => 'public' ],
    ];

    /** Icon sets selectable by an `icon` field's `icon_set` key. */
    const ICON_SETS = [
            'advantage' => self::ADVANTAGE_ICONS,
            'general'   => self::GENERAL_ICONS,
    ];

    /** Social Links networks — mirrored in the frontend's src/utils/builder-icons.js. */
    const SOCIAL_NETWORKS = [
            'facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
            'x'         => 'X (Twitter)',
            'youtube'   => 'YouTube',
            'linkedin'  => 'LinkedIn',
            'pinterest' => 'Pinterest',
            'threads'   => 'Threads',
            'snapchat'  => 'Snapchat',
            'whatsapp'  => 'WhatsApp',
            'telegram'  => 'Telegram',
            'email'     => 'Email',
            'phone'     => 'Phone',
            'website'   => 'Website',
    ];

    /** Product Grid / Carousel product queries (resolved by the frontend via the Store API). */
    const PRODUCT_QUERY_TYPES = [
            'newest'       => 'Newest products',
            'on_sale'      => 'On sale',
            'featured'     => 'Featured (WooCommerce "featured" star)',
            'best_selling' => 'Best selling',
            'top_rated'    => 'Top rated',
            'category'     => 'From categories',
            'tag'          => 'With tags',
            'manual'       => 'Hand-picked products',
    ];

    /** Field types a custom form can ask for (form block, form_type = custom). */
    const FORM_FIELD_TYPES = [
            'text'       => 'Text',
            'email'      => 'Email',
            'tel'        => 'Phone',
            'number'     => 'Number',
            'url'        => 'Website / URL',
            'date'       => 'Date',
            'textarea'   => 'Paragraph',
            'select'     => 'Dropdown',
            'radio'      => 'Radio buttons',
            'checkboxes' => 'Checkboxes (multiple)',
            'checkbox'   => 'Single checkbox (e.g. consent)',
    ];

    /** Form field types that need an `options` list. */
    const FORM_FIELD_TYPES_WITH_OPTIONS = [ 'select', 'radio', 'checkboxes' ];

    /**
     * Whitelisted content-block types. `fields` describes the block's `data`
     * object (content + style tabs); BLOCK_COMMON_FIELDS describe its `style`
     * object. `section` is special-cased: no data, its `style` uses
     * SECTION_STYLE_FIELDS and it has its own `blocks[]`.
     *
     * Adding a block type = an entry here + a Vue component registered in the
     * frontend's SectionTemplate.vue `blockComponents` map.
     */
    const BLOCK_SCHEMA = [

            'section' => [
                    'label'  => 'Section (nested)',
                    'category' => 'layout',
                    'icon'   => 'columns',
                    'fields' => [],
            ],

            'heading' => [
                    'label'  => 'Heading',
                    'category' => 'basic',
                    'icon'   => 'heading',
                    'summary' => 'title',
                    'fields' => [
                            'title'    => [ 'type' => 'rich_text', 'label' => 'Title', 'tab' => 'content',
                                    'help' => 'Inline HTML allowed: <strong>, <em>, <span>, <br>.' ],
                            'subtitle' => [ 'type' => 'text', 'label' => 'Subtitle (optional)', 'tab' => 'content' ],
                            'tag'      => [ 'type' => 'select', 'label' => 'HTML Tag / Size', 'tab' => 'content', 'default' => 'h2',
                                    'options' => [ 'h1' => 'H1 — page title (use once per page)', 'h2' => 'H2 — large', 'h3' => 'H3 — medium', 'h4' => 'H4 — small', 'h5' => 'H5', 'h6' => 'H6' ] ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'left' ],
                            'title_color'    => [ 'type' => 'color', 'label' => 'Title Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'subtitle_color' => [ 'type' => 'color', 'label' => 'Subtitle Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'font_size'      => [ 'type' => 'length', 'label' => 'Title Size', 'tab' => 'style', 'group' => 'Typography', 'responsive' => true, 'placeholder' => 'e.g. 48px or 3rem' ],
                            'font_weight'    => [ 'type' => 'select', 'label' => 'Title Weight', 'tab' => 'style', 'group' => 'Typography', 'options' => self::FONT_WEIGHT_OPTIONS, 'default' => '' ],
                            'subtitle_size'  => [ 'type' => 'length', 'label' => 'Subtitle Size', 'tab' => 'style', 'group' => 'Typography', 'responsive' => true ],
                    ],
            ],

            'text_block' => [
                    'label'  => 'Text',
                    'category' => 'basic',
                    'icon'   => 'editor-paragraph',
                    'summary' => 'text',
                    'fields' => [
                            'text'       => [ 'type' => 'html', 'label' => 'Text', 'tab' => 'content' ],
                            'alignment'  => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'left' ],
                            'text_color' => [ 'type' => 'color', 'label' => 'Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'font_size'  => [ 'type' => 'length', 'label' => 'Font Size', 'tab' => 'style', 'group' => 'Typography', 'responsive' => true, 'placeholder' => 'e.g. 18px' ],
                            'line_height' => [ 'type' => 'length', 'label' => 'Line Height', 'tab' => 'style', 'group' => 'Typography', 'responsive' => true, 'placeholder' => 'e.g. 1.6', 'unitless' => true ],
                    ],
            ],

            'image_block' => [
                    'label'  => 'Image(s)',
                    'category' => 'basic',
                    'icon'   => 'format-image',
                    'fields' => [
                            'images' => [ 'type' => 'repeater', 'label' => 'Images', 'tab' => 'content', 'max_items' => 12,
                                    'require' => 'image', 'title_field' => 'alt', 'item_label' => 'Image', 'add_label' => '+ Add Image',
                                    'fields' => [
                                            'image'    => [ 'type' => 'image', 'label' => 'Image' ],
                                            'alt'      => [ 'type' => 'text', 'label' => 'Alt Text', 'help' => 'Describe the image for screen readers and SEO.' ],
                                            'caption'  => [ 'type' => 'text', 'label' => 'Caption (optional)' ],
                                            'link_url' => [ 'type' => 'url', 'label' => 'Link URL (optional)', 'placeholder' => '/products or https://...' ],
                                            'new_tab'  => [ 'type' => 'toggle', 'label' => 'Open link in a new tab' ],
                                    ],
                            ],
                            'layout'  => [ 'type' => 'select', 'label' => 'Layout', 'tab' => 'content', 'responsive' => true, 'default' => 'row',
                                    'options' => [ 'row' => 'Row (side by side)', 'grid' => 'Grid', 'stacked' => 'Stacked' ] ],
                            'columns' => [ 'type' => 'number', 'label' => 'Grid Columns', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ], 'show_if' => [ 'layout' => 'grid' ] ],
                            'gap'     => [ 'type' => 'length', 'label' => 'Gap Between Images', 'tab' => 'content', 'responsive' => true, 'placeholder' => '16px' ],

                            'image_width'  => [ 'type' => 'length', 'label' => 'Image Width', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => 'auto, 300px or 100%' ],
                            'image_max_width' => [ 'type' => 'length', 'label' => 'Image Max Width', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => '100%' ],
                            'image_height' => [ 'type' => 'length', 'label' => 'Image Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => 'auto, 240px or 40vh' ],
                            'aspect_ratio' => [ 'type' => 'select', 'label' => 'Aspect Ratio', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => 'auto',
                                    'options' => [ 'auto' => 'Original', '1/1' => 'Square (1:1)', '4/3' => '4:3', '3/2' => '3:2', '16/9' => '16:9', '3/4' => 'Portrait (3:4)', '2/3' => 'Portrait (2:3)' ],
                                    'help' => 'Ignored when a fixed Image Height is set.' ],
                            'object_fit'   => [ 'type' => 'select', 'label' => 'Fit', 'tab' => 'style', 'group' => 'Size', 'default' => 'cover',
                                    'options' => [ 'cover' => 'Cover (crop to fill)', 'contain' => 'Contain (show whole image)', 'fill' => 'Stretch', 'none' => 'None' ],
                                    'help' => 'How the image fills its box when both a width and a height/ratio are set.' ],
                            'alignment'    => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'center' ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Border', 'placeholder' => 'e.g. 8px' ],
                    ],
            ],

            'button' => [
                    'label'  => 'Button',
                    'category' => 'basic',
                    'icon'   => 'button',
                    'summary' => 'text',
                    'fields' => [
                            'text'      => [ 'type' => 'text', 'label' => 'Button Text', 'tab' => 'content' ],
                            'url'       => [ 'type' => 'url', 'label' => 'Link', 'tab' => 'content', 'placeholder' => '/products, #section-id or https://...' ],
                            'new_tab'   => [ 'type' => 'toggle', 'label' => 'Open in a new tab', 'tab' => 'content' ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'left' ],
                            'style'     => [ 'type' => 'select', 'label' => 'Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'primary',
                                    'options' => [ 'primary' => 'Primary (filled)', 'secondary' => 'Secondary (filled)', 'outline' => 'Outline', 'flat' => 'Text only', 'link' => 'Underlined link' ] ],
                            'size'      => [ 'type' => 'select', 'label' => 'Size', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'md',
                                    'options' => [ 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large' ] ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Appearance', 'placeholder' => 'e.g. 999px for a pill' ],
                            'bg_color'   => [ 'type' => 'color', 'label' => 'Background Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'text_color' => [ 'type' => 'color', 'label' => 'Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                    ],
            ],

            'spacer' => [
                    'label'  => 'Spacer',
                    'category' => 'layout',
                    'icon'   => 'image-flip-vertical',
                    'fields' => [
                            'height' => [ 'type' => 'length', 'label' => 'Height', 'tab' => 'content', 'responsive' => true, 'default' => '48px' ],
                    ],
            ],

            'form' => [
                    'label'  => 'Form',
                    'category' => 'interactive',
                    'icon'   => 'feedback',
                    'summary' => 'form_type',
                    'fields' => [
                            'form_type' => [ 'type' => 'select', 'label' => 'Form Type', 'tab' => 'content', 'default' => 'newsletter',
                                    'options' => [ 'newsletter' => 'Newsletter signup', 'contact' => 'Contact form', 'custom' => 'Custom fields' ] ],
                            'collect_name' => [ 'type' => 'toggle', 'label' => 'Also ask for a name', 'tab' => 'content', 'show_if' => [ 'form_type' => 'newsletter' ] ],
                            'fields' => [ 'type' => 'repeater', 'label' => 'Fields', 'tab' => 'content', 'max_items' => 20,
                                    'require' => 'label', 'title_field' => 'label', 'item_label' => 'Field', 'add_label' => '+ Add Field',
                                    'show_if' => [ 'form_type' => 'custom' ],
                                    'fields' => [
                                            'key'         => [ 'type' => 'hidden' ],
                                            'label'       => [ 'type' => 'text', 'label' => 'Label' ],
                                            'field_type'  => [ 'type' => 'select', 'label' => 'Type', 'options' => self::FORM_FIELD_TYPES, 'default' => 'text' ],
                                            'placeholder' => [ 'type' => 'text', 'label' => 'Placeholder', 'show_if' => [ 'field_type' => [ 'text', 'email', 'tel', 'number', 'url', 'textarea' ] ] ],
                                            'options'     => [ 'type' => 'textarea', 'label' => 'Options (one per line)', 'show_if' => [ 'field_type' => self::FORM_FIELD_TYPES_WITH_OPTIONS ] ],
                                            'required'    => [ 'type' => 'toggle', 'label' => 'Required' ],
                                            'width'       => [ 'type' => 'select', 'label' => 'Width', 'options' => [ 'full' => 'Full row', 'half' => 'Half row (desktop/tablet)' ], 'default' => 'full' ],
                                    ],
                            ],
                            'recipient_email' => [ 'type' => 'email', 'label' => 'Send Submissions To', 'tab' => 'content', 'private' => true,
                                    'help' => 'Leave empty to use the site admin email. Never published to the storefront.',
                                    'show_if' => [ 'form_type' => [ 'contact', 'custom' ] ] ],
                            'email_subject' => [ 'type' => 'text', 'label' => 'Email Subject', 'tab' => 'content', 'private' => true, 'default' => 'New form submission',
                                    'show_if' => [ 'form_type' => [ 'contact', 'custom' ] ] ],
                            'submit_text' => [ 'type' => 'text', 'label' => 'Submit Button Text', 'tab' => 'content', 'default' => 'Submit' ],
                            'success_message' => [ 'type' => 'text', 'label' => 'Success Message', 'tab' => 'content', 'default' => 'Thanks! We got your message.' ],

                            'field_style'  => [ 'type' => 'select', 'label' => 'Input Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'outlined',
                                    'options' => [ 'outlined' => 'Outlined', 'filled' => 'Filled', 'standard' => 'Underlined' ] ],
                            'layout'       => [ 'type' => 'select', 'label' => 'Layout', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'default' => 'stacked',
                                    'options' => [ 'stacked' => 'Stacked', 'inline' => 'Inline (fields + button on one row)' ] ],
                            'button_style' => [ 'type' => 'select', 'label' => 'Button Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'primary',
                                    'options' => [ 'primary' => 'Primary (filled)', 'secondary' => 'Secondary (filled)', 'outline' => 'Outline' ] ],
                            'button_align' => [ 'type' => 'select', 'label' => 'Button Alignment', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'left' ],
                            'max_width'    => [ 'type' => 'length', 'label' => 'Form Max Width', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'placeholder' => 'e.g. 560px' ],
                            'accent_color'      => [ 'type' => 'color', 'label' => 'Accent Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary',
                                    'help' => 'Focused input borders, checked boxes/radios and the default button fill.' ],
                            'button_bg_color'   => [ 'type' => 'color', 'label' => 'Button Background', 'tab' => 'style', 'group' => 'Colors' ],
                            'button_text_color' => [ 'type' => 'color', 'label' => 'Button Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                    ],
            ],

            /* ---- Data-driven widgets (fetch/render their own items) ---- */

            'featured_products' => [
                    'label'  => 'Featured Products',
                    'category' => 'shop',
                    'icon'   => 'products',
                    'fields' => [
                            'product_ids'    => [ 'type' => 'products', 'label' => 'Products', 'tab' => 'content' ],
                            'items_per_view' => [ 'type' => 'number', 'label' => 'Products Per Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                    ],
            ],
            'category_grid' => [
                    'label'  => 'Category Grid',
                    'category' => 'shop',
                    'icon'   => 'category',
                    'fields' => [
                            'category_ids' => [ 'type' => 'categories', 'label' => 'Categories', 'tab' => 'content' ],
                    ],
            ],
            'testimonials' => [
                    'label'  => 'Testimonials',
                    'category' => 'trust',
                    'icon'   => 'format-quote',
                    'fields' => [
                            'display_style'  => [ 'type' => 'select', 'label' => 'Display Style', 'tab' => 'content', 'default' => 'grid',
                                    'options' => [ 'grid' => 'Grid', 'carousel' => 'Carousel' ] ],
                            'items_per_view' => [ 'type' => 'number', 'label' => 'Items Per Row / Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                            'items' => [ 'type' => 'repeater', 'label' => 'Testimonials', 'tab' => 'content', 'max_items' => 20,
                                    'title_field' => 'name', 'item_label' => 'Testimonial', 'add_label' => '+ Add Testimonial',
                                    'fields' => [
                                            'name'        => [ 'type' => 'text', 'label' => 'Name and Title' ],
                                            'rating'      => [ 'type' => 'select', 'label' => 'Rating', 'default' => '5',
                                                    'options' => [ '5' => '★★★★★', '4' => '★★★★', '3' => '★★★', '2' => '★★', '1' => '★' ] ],
                                            'review_text' => [ 'type' => 'textarea', 'label' => 'Review Text' ],
                                    ],
                            ],
                    ],
            ],
            'advantages' => [
                    'label'  => 'Advantages / Trust Badges',
                    'category' => 'trust',
                    'icon'   => 'awards',
                    'fields' => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Cards', 'tab' => 'content', 'max_items' => 8,
                                    'title_field' => 'title', 'item_label' => 'Card', 'add_label' => '+ Add Card',
                                    'fields' => [
                                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'icon_set' => 'advantage', 'default' => 'shipping' ],
                                            'custom_icon' => [ 'type' => 'image', 'label' => 'Custom Icon', 'show_if' => [ 'icon' => 'custom' ] ],
                                            'title'       => [ 'type' => 'text', 'label' => 'Title' ],
                                            'text'        => [ 'type' => 'textarea', 'label' => 'Description' ],
                                    ],
                            ],
                            'icon_color' => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'text_color' => [ 'type' => 'color', 'label' => 'Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                    ],
            ],

            /* ---- Media ---- */

            'video' => [
                    'label'    => 'Video / Embed',
                    'category' => 'basic',
                    'fields'   => [
                            'source'     => [ 'type' => 'select', 'label' => 'Source', 'tab' => 'content', 'default' => 'youtube',
                                    'options' => [ 'youtube' => 'YouTube', 'vimeo' => 'Vimeo', 'file' => 'Upload (media library)', 'url' => 'Video file URL (.mp4 / .webm)' ] ],
                            'url'        => [ 'type' => 'url', 'label' => 'Video Link', 'tab' => 'content', 'placeholder' => 'https://www.youtube.com/watch?v=…',
                                    'show_if' => [ 'source' => [ 'youtube', 'vimeo', 'url' ] ],
                                    // YouTube/Vimeo links must point at those sites; a file URL
                                    // must be on this WordPress site or the storefront (the
                                    // storefront's CSP only loads media from those).
                                    'url_hosts' => [ 'field' => 'source', 'map' => [ 'youtube' => 'youtube', 'vimeo' => 'vimeo', 'url' => 'own' ] ],
                                    'help' => 'For "Video file URL": a file on this WordPress site or your storefront.' ],
                            'video_file' => [ 'type' => 'video', 'label' => 'Video File', 'tab' => 'content', 'show_if' => [ 'source' => 'file' ],
                                    'help' => 'Served from WordPress (videos are too large for the frontend repository).' ],
                            'poster'     => [ 'type' => 'image', 'label' => 'Cover Image (optional)', 'tab' => 'content',
                                    'help' => 'Shown until the visitor presses play. YouTube uses its own thumbnail when empty.' ],
                            'title'      => [ 'type' => 'text', 'label' => 'Accessible Title', 'tab' => 'content', 'placeholder' => 'e.g. How our products are made' ],
                            'autoplay'   => [ 'type' => 'toggle', 'label' => 'Autoplay (muted)', 'tab' => 'content' ],
                            'loop'       => [ 'type' => 'toggle', 'label' => 'Loop', 'tab' => 'content' ],
                            'controls'   => [ 'type' => 'toggle', 'label' => 'Show player controls', 'tab' => 'content', 'default' => true ],

                            'aspect_ratio' => [ 'type' => 'select', 'label' => 'Aspect Ratio', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => '16/9',
                                    'options' => [ '16/9' => '16:9 (widescreen)', '4/3' => '4:3', '1/1' => 'Square', '9/16' => '9:16 (vertical / reels)', '21/9' => '21:9 (cinema)' ] ],
                            'max_width'    => [ 'type' => 'length', 'label' => 'Max Width', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => 'e.g. 960px' ],
                            'alignment'    => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'center' ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Size', 'placeholder' => 'e.g. 12px' ],
                    ],
            ],

            'icon_list' => [
                    'label'    => 'Icon List',
                    'category' => 'basic',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Items', 'tab' => 'content', 'max_items' => 30,
                                    'require' => 'text', 'title_field' => 'text', 'item_label' => 'Item', 'add_label' => '+ Add Item',
                                    'fields' => [
                                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'icon_set' => 'general', 'default' => 'check' ],
                                            'custom_icon' => [ 'type' => 'image', 'label' => 'Custom Icon', 'show_if' => [ 'icon' => 'custom' ] ],
                                            'text'        => [ 'type' => 'text', 'label' => 'Text' ],
                                            'link_url'    => [ 'type' => 'url', 'label' => 'Link (optional)', 'placeholder' => '/shipping or https://…' ],
                                    ],
                            ],
                            'layout'    => [ 'type' => 'select', 'label' => 'Layout', 'tab' => 'content', 'responsive' => true, 'default' => 'vertical',
                                    'options' => [ 'vertical' => 'Vertical list', 'horizontal' => 'Horizontal (inline)' ] ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'left' ],

                            'icon_size'  => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => '20px' ],
                            'font_size'  => [ 'type' => 'length', 'label' => 'Text Size', 'tab' => 'style', 'group' => 'Size', 'responsive' => true ],
                            'gap'        => [ 'type' => 'length', 'label' => 'Space Between Items', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => '12px' ],
                            'divider'    => [ 'type' => 'toggle', 'label' => 'Divider between items', 'tab' => 'style', 'group' => 'Size' ],
                            'icon_color' => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                            'text_color' => [ 'type' => 'color', 'label' => 'Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                    ],
            ],

            /* ---- Interactive ---- */

            'faq' => [
                    'label'    => 'FAQ / Accordion',
                    'category' => 'interactive',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Questions', 'tab' => 'content', 'max_items' => 40,
                                    'require' => 'question', 'title_field' => 'question', 'item_label' => 'Question', 'add_label' => '+ Add Question',
                                    'fields' => [
                                            'question' => [ 'type' => 'text', 'label' => 'Question' ],
                                            'answer'   => [ 'type' => 'html', 'label' => 'Answer' ],
                                    ],
                            ],
                            'first_open'     => [ 'type' => 'toggle', 'label' => 'Open the first question by default', 'tab' => 'content' ],
                            'allow_multiple' => [ 'type' => 'toggle', 'label' => 'Allow several open at once', 'tab' => 'content' ],
                            'add_schema'     => [ 'type' => 'toggle', 'label' => 'Add FAQ structured data (Google rich results)', 'tab' => 'content', 'default' => true,
                                    'help' => 'Use it once per page, only for real questions and answers.' ],

                            'icon_style'     => [ 'type' => 'select', 'label' => 'Toggle Icon', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'chevron',
                                    'options' => [ 'chevron' => 'Chevron', 'plus' => 'Plus / minus' ] ],
                            'style'          => [ 'type' => 'select', 'label' => 'Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'lines',
                                    'options' => [ 'lines' => 'Divider lines', 'boxed' => 'Boxed cards' ] ],
                            'question_size'  => [ 'type' => 'length', 'label' => 'Question Size', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true ],
                            'question_color' => [ 'type' => 'color', 'label' => 'Question Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'answer_color'   => [ 'type' => 'color', 'label' => 'Answer Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'accent_color'   => [ 'type' => 'color', 'label' => 'Icon / Divider Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'item_bg'        => [ 'type' => 'color', 'label' => 'Card Background', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'style' => 'boxed' ] ],
                    ],
            ],

            'tabs' => [
                    'label'    => 'Tabs',
                    'category' => 'interactive',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Tabs', 'tab' => 'content', 'max_items' => 10,
                                    'require' => 'title', 'title_field' => 'title', 'item_label' => 'Tab', 'add_label' => '+ Add Tab',
                                    'fields' => [
                                            'title'   => [ 'type' => 'text', 'label' => 'Tab Title' ],
                                            'content' => [ 'type' => 'html', 'label' => 'Content' ],
                                    ],
                            ],
                            'mobile_display' => [ 'type' => 'select', 'label' => 'On Mobile', 'tab' => 'content', 'default' => 'accordion',
                                    'options' => [ 'accordion' => 'Show as an accordion', 'tabs' => 'Keep tabs (scrollable)' ] ],

                            'style'        => [ 'type' => 'select', 'label' => 'Tab Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'underline',
                                    'options' => [ 'underline' => 'Underline', 'pills' => 'Pills', 'boxed' => 'Boxed' ] ],
                            'alignment'    => [ 'type' => 'select', 'label' => 'Tab Alignment', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'left' ],
                            'active_color' => [ 'type' => 'color', 'label' => 'Active Tab Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                            'text_color'   => [ 'type' => 'color', 'label' => 'Tab Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'content_color' => [ 'type' => 'color', 'label' => 'Content Text Color', 'tab' => 'style', 'group' => 'Colors' ],
                    ],
            ],

            'countdown' => [
                    'label'    => 'Countdown',
                    'category' => 'interactive',
                    'fields'   => [
                            'end_at'          => [ 'type' => 'datetime', 'label' => 'Ends At', 'tab' => 'content',
                                    'help' => 'In the store\'s timezone (Settings → General in WordPress).' ],
                            'show_days'       => [ 'type' => 'toggle', 'label' => 'Show days', 'tab' => 'content', 'default' => true ],
                            'show_seconds'    => [ 'type' => 'toggle', 'label' => 'Show seconds', 'tab' => 'content', 'default' => true ],
                            'show_labels'     => [ 'type' => 'toggle', 'label' => 'Show labels', 'tab' => 'content', 'default' => true ],
                            'label_days'      => [ 'type' => 'text', 'label' => 'Days Label', 'tab' => 'content', 'default' => 'Days', 'show_if' => [ 'show_labels' => true ] ],
                            'label_hours'     => [ 'type' => 'text', 'label' => 'Hours Label', 'tab' => 'content', 'default' => 'Hours', 'show_if' => [ 'show_labels' => true ] ],
                            'label_minutes'   => [ 'type' => 'text', 'label' => 'Minutes Label', 'tab' => 'content', 'default' => 'Minutes', 'show_if' => [ 'show_labels' => true ] ],
                            'label_seconds'   => [ 'type' => 'text', 'label' => 'Seconds Label', 'tab' => 'content', 'default' => 'Seconds', 'show_if' => [ 'show_labels' => true ] ],
                            'expired_action'  => [ 'type' => 'select', 'label' => 'When It Ends', 'tab' => 'content', 'default' => 'message',
                                    'options' => [ 'message' => 'Show a message', 'zero' => 'Keep showing 00:00:00', 'hide' => 'Hide the block' ] ],
                            'expired_message' => [ 'type' => 'text', 'label' => 'Ended Message', 'tab' => 'content', 'default' => 'This offer has ended.',
                                    'show_if' => [ 'expired_action' => 'message' ] ],

                            'style'        => [ 'type' => 'select', 'label' => 'Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'boxes',
                                    'options' => [ 'boxes' => 'Boxes', 'plain' => 'Plain numbers' ] ],
                            'number_size'  => [ 'type' => 'length', 'label' => 'Number Size', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'default' => [ 'desktop' => '40px', 'tablet' => '', 'mobile' => '28px' ] ],
                            'alignment'    => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'center' ],
                            'number_color' => [ 'type' => 'color', 'label' => 'Number Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'label_color'  => [ 'type' => 'color', 'label' => 'Label Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'box_bg'       => [ 'type' => 'color', 'label' => 'Box Background', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'style' => 'boxes' ] ],
                    ],
            ],

            /* ---- Shop ---- */

            'product_grid' => [
                    'label'    => 'Product Grid',
                    'category' => 'shop',
                    'fields'   => [
                            'query_type'   => [ 'type' => 'select', 'label' => 'Show', 'tab' => 'content', 'default' => 'newest', 'options' => self::PRODUCT_QUERY_TYPES ],
                            'category_ids' => [ 'type' => 'categories', 'label' => 'Categories', 'tab' => 'content', 'show_if' => [ 'query_type' => 'category' ] ],
                            'tag_ids'      => [ 'type' => 'tags', 'label' => 'Tags', 'tab' => 'content', 'show_if' => [ 'query_type' => 'tag' ] ],
                            'product_ids'  => [ 'type' => 'products', 'label' => 'Products', 'tab' => 'content', 'show_if' => [ 'query_type' => 'manual' ] ],
                            'limit'        => [ 'type' => 'number', 'label' => 'Number of Products', 'tab' => 'content', 'min' => 1, 'max' => 24, 'default' => 8,
                                    'show_if' => [ 'query_type' => [ 'newest', 'on_sale', 'featured', 'best_selling', 'top_rated', 'category', 'tag' ] ] ],
                            'hide_out_of_stock' => [ 'type' => 'toggle', 'label' => 'Hide out-of-stock products', 'tab' => 'content' ],
                            'columns'      => [ 'type' => 'number', 'label' => 'Columns', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 4, 'tablet' => 3, 'mobile' => 2 ] ],
                            'show_view_all' => [ 'type' => 'toggle', 'label' => 'Show a "View all" button', 'tab' => 'content' ],
                            'view_all_text' => [ 'type' => 'text', 'label' => 'Button Text', 'tab' => 'content', 'default' => 'View all', 'show_if' => [ 'show_view_all' => true ] ],
                            'view_all_url'  => [ 'type' => 'url', 'label' => 'Button Link', 'tab' => 'content', 'default' => '/products', 'show_if' => [ 'show_view_all' => true ] ],

                            'gap' => [ 'type' => 'length', 'label' => 'Gap Between Products', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'placeholder' => '16px' ],
                    ],
            ],

            'carousel' => [
                    'label'    => 'Carousel / Slider',
                    'category' => 'shop',
                    'fields'   => [
                            'source' => [ 'type' => 'select', 'label' => 'Slides From', 'tab' => 'content', 'default' => 'slides',
                                    'options' => [ 'slides' => 'Hero slides (image + text + button)', 'images' => 'Images', 'logos' => 'Logos / brands', 'products' => 'Products', 'categories' => 'Categories' ] ],

                            'slides' => [ 'type' => 'repeater', 'label' => 'Slides', 'tab' => 'content', 'max_items' => 10,
                                    'require' => 'image', 'title_field' => 'title', 'item_label' => 'Slide', 'add_label' => '+ Add Slide',
                                    'show_if' => [ 'source' => 'slides' ],
                                    'fields' => [
                                            'image'           => [ 'type' => 'image', 'label' => 'Image' ],
                                            'mobile_image'    => [ 'type' => 'image', 'label' => 'Mobile Image (optional)' ],
                                            'alt'             => [ 'type' => 'text', 'label' => 'Alt Text' ],
                                            'title'           => [ 'type' => 'rich_text', 'label' => 'Title' ],
                                            'text'            => [ 'type' => 'textarea', 'label' => 'Text' ],
                                            'button_text'     => [ 'type' => 'text', 'label' => 'Button Text' ],
                                            'button_url'      => [ 'type' => 'url', 'label' => 'Button Link', 'placeholder' => '/products' ],
                                            'content_align'   => [ 'type' => 'select', 'label' => 'Text Position', 'default' => 'center',
                                                    'options' => [ 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ] ],
                                            'text_color'      => [ 'type' => 'color', 'label' => 'Text Color', 'default' => '#ffffff' ],
                                            'overlay_color'   => [ 'type' => 'color', 'label' => 'Overlay Color', 'default' => '#000000' ],
                                            'overlay_opacity' => [ 'type' => 'number', 'label' => 'Overlay Opacity (%)', 'min' => 0, 'max' => 90, 'default' => 30 ],
                                    ],
                            ],

                            'images' => [ 'type' => 'repeater', 'label' => 'Images', 'tab' => 'content', 'max_items' => 40,
                                    'require' => 'image', 'title_field' => 'alt', 'item_label' => 'Image', 'add_label' => '+ Add Image',
                                    'show_if' => [ 'source' => [ 'images', 'logos' ] ],
                                    'fields' => [
                                            'image'    => [ 'type' => 'image', 'label' => 'Image' ],
                                            'alt'      => [ 'type' => 'text', 'label' => 'Alt Text / Brand Name' ],
                                            'link_url' => [ 'type' => 'url', 'label' => 'Link (optional)' ],
                                            'new_tab'  => [ 'type' => 'toggle', 'label' => 'Open link in a new tab' ],
                                    ],
                            ],

                            'query_type'   => [ 'type' => 'select', 'label' => 'Products To Show', 'tab' => 'content', 'default' => 'newest', 'options' => self::PRODUCT_QUERY_TYPES,
                                    'show_if' => [ 'source' => 'products' ] ],
                            'category_ids' => [ 'type' => 'categories', 'label' => 'Product Categories', 'tab' => 'content',
                                    'show_if' => [ 'source' => 'products', 'query_type' => 'category' ] ],
                            'slide_category_ids' => [ 'type' => 'categories', 'label' => 'Categories To Show', 'tab' => 'content',
                                    'show_if' => [ 'source' => 'categories' ],
                                    'help' => 'Leave empty to show all top-level categories (that have products).' ],
                            'tag_ids'      => [ 'type' => 'tags', 'label' => 'Tags', 'tab' => 'content', 'show_if' => [ 'source' => 'products', 'query_type' => 'tag' ] ],
                            'product_ids'  => [ 'type' => 'products', 'label' => 'Products', 'tab' => 'content', 'show_if' => [ 'source' => 'products', 'query_type' => 'manual' ] ],
                            'limit'        => [ 'type' => 'number', 'label' => 'Number of Products', 'tab' => 'content', 'min' => 1, 'max' => 24, 'default' => 8,
                                    'show_if' => [ 'source' => 'products', 'query_type' => [ 'newest', 'on_sale', 'featured', 'best_selling', 'top_rated', 'category', 'tag' ] ] ],
                            'hide_out_of_stock' => [ 'type' => 'toggle', 'label' => 'Hide out-of-stock products', 'tab' => 'content', 'show_if' => [ 'source' => 'products' ] ],

                            'items_per_view' => [ 'type' => 'number', 'label' => 'Items Per Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 8,
                                    'default' => [ 'desktop' => 4, 'tablet' => 3, 'mobile' => 2 ], 'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'autoplay'         => [ 'type' => 'toggle', 'label' => 'Autoplay', 'tab' => 'content' ],
                            'autoplay_seconds' => [ 'type' => 'number', 'label' => 'Seconds Per Slide', 'tab' => 'content', 'min' => 2, 'max' => 30, 'default' => 5,
                                    'show_if' => [ 'autoplay' => true ] ],
                            'loop'   => [ 'type' => 'toggle', 'label' => 'Loop back to the start', 'tab' => 'content', 'default' => true ],
                            'arrows' => [ 'type' => 'toggle', 'label' => 'Show arrows', 'tab' => 'content', 'default' => true ],
                            'dots'   => [ 'type' => 'toggle', 'label' => 'Show dots', 'tab' => 'content', 'default' => true ],

                            'slide_height'  => [ 'type' => 'length', 'label' => 'Slide Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'default' => [ 'desktop' => '560px', 'tablet' => '440px', 'mobile' => '380px' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'title_size'    => [ 'type' => 'length', 'label' => 'Slide Title Size', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'show_if' => [ 'source' => 'slides' ] ],
                            'image_height'  => [ 'type' => 'length', 'label' => 'Image Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => 'e.g. 60px for logos', 'show_if' => [ 'source' => [ 'images', 'logos' ] ] ],
                            'gap'           => [ 'type' => 'length', 'label' => 'Gap Between Items', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => '16px', 'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'grayscale'     => [ 'type' => 'toggle', 'label' => 'Grayscale logos (color on hover)', 'tab' => 'style', 'group' => 'Size',
                                    'show_if' => [ 'source' => 'logos' ] ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Size', 'placeholder' => 'e.g. 12px' ],
                            'button_style'  => [ 'type' => 'select', 'label' => 'Slide Button Style', 'tab' => 'style', 'group' => 'Colors', 'default' => 'light',
                                    'options' => [ 'light' => 'Light (white)', 'secondary' => 'Secondary color', 'outline' => 'Outline' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'control_color' => [ 'type' => 'color', 'label' => 'Arrows / Dots Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                    ],
            ],

            /* ---- Trust & social ---- */

            'social_links' => [
                    'label'    => 'Social Links',
                    'category' => 'trust',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Links', 'tab' => 'content', 'max_items' => 14,
                                    'require' => 'url', 'title_field' => 'network', 'item_label' => 'Link', 'add_label' => '+ Add Link',
                                    'fields' => [
                                            'network' => [ 'type' => 'select', 'label' => 'Network', 'options' => self::SOCIAL_NETWORKS, 'default' => 'instagram' ],
                                            'url'     => [ 'type' => 'text', 'label' => 'Profile Link / Number / Email',
                                                    'placeholder' => 'https://instagram.com/yourshop',
                                                    'help' => 'Email: just the address. Phone / WhatsApp: the number with country code.' ],
                                            'label'   => [ 'type' => 'text', 'label' => 'Label (optional)', 'placeholder' => 'Defaults to the network name' ],
                                    ],
                            ],
                            'display'   => [ 'type' => 'select', 'label' => 'Show', 'tab' => 'content', 'default' => 'icon',
                                    'options' => [ 'icon' => 'Icons only', 'icon_label' => 'Icons + labels' ] ],
                            'new_tab'   => [ 'type' => 'toggle', 'label' => 'Open links in a new tab', 'tab' => 'content', 'default' => true ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'left' ],

                            'shape'      => [ 'type' => 'select', 'label' => 'Icon Shape', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'circle',
                                    'options' => [ 'none' => 'No background', 'circle' => 'Circle', 'rounded' => 'Rounded square', 'square' => 'Square' ] ],
                            'icon_size'  => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'default' => '18px' ],
                            'gap'        => [ 'type' => 'length', 'label' => 'Space Between', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'placeholder' => '10px' ],
                            'color_mode' => [ 'type' => 'select', 'label' => 'Colors', 'tab' => 'style', 'group' => 'Colors', 'default' => 'custom',
                                    'options' => [ 'custom' => 'My colors (below)', 'brand' => 'Each network\'s brand color' ] ],
                            'icon_color' => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'color_mode' => 'custom' ] ],
                            'bg_color'   => [ 'type' => 'color', 'label' => 'Shape Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary',
                                    'show_if' => [ 'color_mode' => 'custom' ] ],
                    ],
            ],
    ];

    /**
     * Location slots shared by any WooCommerce archive-style page (Shop and
     * Category), since they render the exact same frontend layout.
     */
    const ARCHIVE_SECTION_LOCATIONS = [
            'before_breadcrumbs'   => 'Before Breadcrumbs',
            'after_breadcrumbs'    => 'After Breadcrumbs',
            'before_filters'       => 'Before Filters',
            'before_products_grid' => 'Before Products Grid',
            'after_products_grid'  => 'After Products Grid',
            'after_pagination'     => 'After Pagination',
    ];

    /** Single Product page hook slots (mirrors WooCommerce's own hook names). */
    const PRODUCT_SECTION_LOCATIONS = [
            'before_breadcrumbs'      => 'Before Breadcrumbs',
            'after_breadcrumbs'       => 'After Breadcrumbs',
            'before_product_images'   => 'Before Product Images',
            'after_product_images'    => 'After Product Images',
            'before_add_to_cart_form' => 'Before Add to Cart Form',
            'after_add_to_cart_form'  => 'After Add to Cart Form',
            'after_product_summary'   => 'After Product Summary',
            'before_related_products' => 'Before Related Products',
            'after_related_products'  => 'After Related Products',
    ];

    /**
     * Pages other than 'home' that support location-pinned sections. 'home'
     * is deliberately absent — its sections are a single flat, order-only list.
     */
    const PAGE_SECTION_LOCATIONS = [
            'shop'     => self::ARCHIVE_SECTION_LOCATIONS,
            'category' => self::ARCHIVE_SECTION_LOCATIONS,
            'product'  => self::PRODUCT_SECTION_LOCATIONS,
    ];

    /** Max container depth (top-level section = 1). */
    const MAX_NESTING_DEPTH = 4;

    /** Max top-level sections per page / blocks per container (sanity caps). */
    const MAX_SECTIONS = 60;
    const MAX_BLOCKS   = 40;

    /** Saved versions of the builder (one per Save that changed something) and section templates. */
    const REVISIONS_OPTION = 'shop_builder_revisions';
    const MAX_REVISIONS    = 20;
    const TEMPLATES_OPTION = 'shop_builder_templates';
    const MAX_TEMPLATES    = 50;

    /** Inline-only HTML for short rich-text fields (headings, hero title, footer). */
    const RICH_TEXT_ALLOWED_TAGS = [
            'span'   => [ 'class' => [], 'style' => [] ],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            'br'     => [],
    ];

    /** HTML allowed in the Text block's editor: paragraphs, lists and links. */
    const HTML_ALLOWED_TAGS = [
            'p'      => [ 'style' => [] ],
            'br'     => [],
            'span'   => [ 'style' => [] ],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            's'      => [],
            'del'    => [],
            'a'      => [ 'href' => [], 'target' => [], 'rel' => [], 'title' => [] ],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
            'blockquote' => [],
            'h2'     => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
            'hr'     => [],
    ];

    const CONTACT_METHOD_TYPES = [ 'whatsapp', 'phone', 'email', 'telegram', 'custom' ];

    /** Returns the schema entry (label + fields) for a block type, or null. */
    public static function get_type_schema( $type ) {
        return self::BLOCK_SCHEMA[ $type ] ?? null;
    }
}
