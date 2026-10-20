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
 *   hide_if     [sibling_field => value | [values]] — hidden when the sibling matches.
 *   accept      image: 'svg' = SVG files only (other images are refused).
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

    /**
     * 'default' follows the page's language: the start of the line (left in
     * English, right in Hebrew). Left and right are always that side.
     */
    const ALIGN_OPTIONS = [ 'default' => 'Default', 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ];

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
            'custom'        => 'Custom',
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
     * Product tags on an image ("shop the look"): the owner places products on
     * the photo; the storefront shows an animated dot there that opens the
     * product's name and price (ProductTags.vue). `image_field` /
     * `mobile_image_field` name the sibling image fields the dashboard shows
     * for placing them. Values: sanitize_product_tags().
     */
    const PRODUCT_TAGS_FIELD = [ 'type' => 'product_tags', 'label' => 'Product Tags', 'max_items' => 6,
            'help' => 'Point at products in the photo: shoppers see a small dot that opens the product\'s name and price.' ];

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
                    'options' => [ 'none' => 'None', 'color' => 'Color', 'gradient' => 'Gradient', 'image' => 'Image', 'slideshow' => 'Slideshow (several images)', 'video' => 'Video' ], 'default' => 'none' ],
            'bg_color' => [ 'type' => 'color', 'label' => 'Background Color', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'show_if' => [ 'bg_type' => 'color' ] ],
            'bg_gradient_color1' => [ 'type' => 'color', 'label' => 'Gradient Start', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_gradient_color2' => [ 'type' => 'color', 'label' => 'Gradient End', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_gradient_angle' => [ 'type' => 'number', 'label' => 'Gradient Angle (°)', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'min' => 0, 'max' => 360, 'default' => 180,
                    'show_if' => [ 'bg_type' => 'gradient' ] ],
            'bg_image' => [ 'type' => 'image', 'label' => 'Background Image', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'show_if' => [ 'bg_type' => 'image' ],
                    'help' => 'Pick another image for tablets or phones, or leave it empty to use the bigger screen\'s image.' ],
            'bg_tags' => self::PRODUCT_TAGS_FIELD + [ 'tab' => 'style', 'group' => 'Background', 'image_field' => 'bg_image', 'show_if' => [ 'bg_type' => 'image' ] ],
            // Slideshow: images that change by themselves behind the content (SectionBgSlides.vue).
            'bg_slides' => [ 'type' => 'repeater', 'label' => 'Slideshow Images', 'tab' => 'style', 'group' => 'Background', 'max_items' => 8,
                    'require' => 'image', 'item_label' => 'Image', 'add_label' => '+ Add Image', 'show_if' => [ 'bg_type' => 'slideshow' ],
                    'fields' => [
                            'image'        => [ 'type' => 'image', 'label' => 'Image' ],
                            'mobile_image' => [ 'type' => 'image', 'label' => 'Phone Image (optional)' ],
                            'tags'         => self::PRODUCT_TAGS_FIELD + [ 'image_field' => 'image', 'mobile_image_field' => 'mobile_image' ],
                    ] ],
            'bg_slide_seconds' => [ 'type' => 'number', 'label' => 'Seconds Per Image', 'tab' => 'style', 'group' => 'Background', 'min' => 2, 'max' => 30, 'default' => 6,
                    'show_if' => [ 'bg_type' => 'slideshow' ] ],
            'bg_slide_effect' => [ 'type' => 'select', 'label' => 'Change Effect', 'tab' => 'style', 'group' => 'Background', 'default' => 'zoom',
                    'options' => [ 'fade' => 'Fade', 'zoom' => 'Fade with a slow zoom' ], 'show_if' => [ 'bg_type' => 'slideshow' ] ],
            'bg_slide_counter' => [ 'type' => 'toggle', 'label' => 'Show a counter (01 / 03) and a progress line', 'tab' => 'style', 'group' => 'Background',
                    'show_if' => [ 'bg_type' => 'slideshow' ] ],
            'bg_slide_arrows' => [ 'type' => 'toggle', 'label' => 'Show arrows to change the image', 'tab' => 'style', 'group' => 'Background',
                    'show_if' => [ 'bg_type' => 'slideshow' ] ],
            'bg_slide_controls_side' => [ 'type' => 'select', 'label' => 'Counter and Arrows Position', 'tab' => 'style', 'group' => 'Background', 'default' => 'right',
                    'options' => [ 'right' => 'Bottom right', 'left' => 'Bottom left' ], 'show_if' => [ 'bg_type' => 'slideshow' ] ],
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
                    'options' => self::BG_POSITION_OPTIONS, 'default' => 'center center', 'show_if' => [ 'bg_type' => [ 'image', 'slideshow', 'video' ] ] ],
            'bg_pos_x' => [ 'type' => 'length', 'label' => 'Horizontal Position', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'allow_negative' => true,
                    'placeholder' => 'e.g. 30% or 120px', 'show_if' => [ 'bg_type' => [ 'image', 'slideshow', 'video' ], 'bg_image_position' => 'custom' ],
                    'help' => 'From the left edge. 0% = left, 50% = center, 100% = right.' ],
            'bg_pos_y' => [ 'type' => 'length', 'label' => 'Vertical Position', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'allow_negative' => true,
                    'placeholder' => 'e.g. 70% or 40px', 'show_if' => [ 'bg_type' => [ 'image', 'slideshow', 'video' ], 'bg_image_position' => 'custom' ],
                    'help' => 'From the top edge. 0% = top, 50% = center, 100% = bottom.' ],
            'bg_fixed' => [ 'type' => 'toggle', 'label' => 'Fixed (parallax) on desktop', 'tab' => 'style', 'group' => 'Background', 'show_if' => [ 'bg_type' => 'image' ] ],
            'bg_overlay_color' => [ 'type' => 'color', 'label' => 'Overlay Color', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'show_if' => [ 'bg_type' => [ 'image', 'slideshow', 'video' ] ] ],
            'bg_overlay_opacity' => [ 'type' => 'number', 'label' => 'Overlay Opacity (%)', 'tab' => 'style', 'group' => 'Background', 'responsive' => true, 'screens' => true, 'min' => 0, 'max' => 100, 'default' => 50,
                    'show_if' => [ 'bg_type' => [ 'image', 'slideshow', 'video' ] ] ],
    ];

    /* ------------------------------------------------------------------
       Loop templates (trait-sb-loops.php): the owner's own card designs
       for products, categories, blog posts and reviews, built from
       sections with that kind's blocks (pages 'loop_{type}').
       ------------------------------------------------------------------ */
    const LOOP_TYPES = [
            'product'  => 'Products',
            'category' => 'Categories',
            'post'     => 'Blog posts',
            'review'   => 'Reviews',
    ];
    const MAX_LOOPS = 30;

    /** Text-style parts for a card's texts. */
    const LOOP_TEXT_PARTS = [ 'font', 'size', 'weight', 'line_height', 'color', 'spacing', 'case' ];

    /** Shared by the card blocks that show a text (name, summary, date…). */
    const LOOP_TEXT_FIELDS = [
            'tag' => [ 'type' => 'select', 'label' => 'HTML Tag', 'tab' => 'content', 'default' => 'p',
                    'options' => [ 'h2' => 'Heading 2', 'h3' => 'Heading 3', 'h4' => 'Heading 4', 'p' => 'Paragraph', 'div' => 'Plain' ],
                    'help' => 'For search engines and screen readers; the look comes from the Style tab.' ],
            'max_lines' => [ 'type' => 'number', 'label' => 'Cut after this many lines', 'tab' => 'content', 'min' => 0, 'max' => 6, 'default' => 0,
                    'help' => '0 shows all of it.' ],
            'align' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
    ];

    /** Stars (a product's rating, a review's stars). */
    const LOOP_STARS_FIELDS = [
            'star_color' => [ 'type' => 'color', 'label' => 'Star Color', 'tab' => 'style', 'group' => 'Stars' ],
            'star_size' => [ 'type' => 'length', 'label' => 'Star Size', 'tab' => 'style', 'group' => 'Stars', 'responsive' => true, 'placeholder' => 'e.g. 14px' ],
            'align' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Stars', 'responsive' => true, 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
    ];

    /** The image of a product, category or post card. */
    const LOOP_IMAGE_FIELDS = [
            'display' => [ 'type' => 'select', 'label' => 'Show the Image', 'tab' => 'content', 'default' => 'inline',
                    'options' => [ 'inline' => 'In the card', 'background' => 'Behind the whole card' ],
                    'help' => '"Behind the whole card" puts the other blocks over the image (e.g. a name at the bottom).' ],
            'aspect_ratio' => [ 'type' => 'select', 'label' => 'Shape', 'tab' => 'style', 'group' => 'Image', 'responsive' => true, 'default' => '1/1',
                    'options' => [ 'auto' => 'Original', '1/1' => 'Square (1:1)', '4/5' => 'Portrait (4:5)', '3/4' => 'Portrait (3:4)', '2/3' => 'Portrait (2:3)',
                            '4/3' => 'Landscape (4:3)', '3/2' => 'Landscape (3:2)', '16/9' => 'Wide (16:9)', 'fill' => 'Fill the card\'s height' ],
                    'help' => 'With "Behind the whole card" this is the card\'s shape. "Fill the card\'s height" stretches the image when the card is taller than its content.' ],
            'image_height' => [ 'type' => 'length', 'label' => 'Fixed Height', 'tab' => 'style', 'group' => 'Image', 'responsive' => true,
                    'placeholder' => 'e.g. 320px', 'help' => 'Takes the place of the shape.' ],
            'object_fit' => [ 'type' => 'select', 'label' => 'Fit', 'tab' => 'style', 'group' => 'Image', 'default' => 'cover',
                    'options' => [ 'cover' => 'Cover (crop to fill)', 'contain' => 'Contain (show the whole image)' ] ],
            'image_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Image', 'responsive' => true, 'placeholder' => 'e.g. 8px' ],
            'image_border' => [ 'type' => 'length', 'label' => 'Border Width', 'tab' => 'style', 'group' => 'Image', 'placeholder' => 'e.g. 1px' ],
            'image_border_color' => [ 'type' => 'color', 'label' => 'Border Color', 'tab' => 'style', 'group' => 'Image' ],
            'hover_effect' => [ 'type' => 'select', 'label' => 'When the Mouse Is Over the Card', 'tab' => 'style', 'group' => 'Image', 'default' => 'none',
                    'options' => [ 'none' => 'Nothing', 'zoom' => 'Zoom in', 'darken' => 'Darken', 'swap' => 'Show the second photo (products)' ] ],
            'overlay_color' => [ 'type' => 'color', 'label' => 'Shade Color', 'tab' => 'style', 'group' => 'Shade' ],
            'overlay_style' => [ 'type' => 'select', 'label' => 'Shade', 'tab' => 'style', 'group' => 'Shade', 'default' => 'none',
                    'options' => [ 'none' => 'None', 'full' => 'Even', 'bottom' => 'Darker at the bottom', 'top' => 'Darker at the top' ],
                    'help' => 'Helps text over the image stand out.' ],
            'overlay_opacity' => [ 'type' => 'number', 'label' => 'Shade Strength (%)', 'tab' => 'style', 'group' => 'Shade', 'min' => 0, 'max' => 100, 'default' => 40,
                    'show_if' => [ 'overlay_style' => [ 'full', 'bottom', 'top' ] ] ],
    ];

    /**
     * A border, shared by containers and every block: a width per side (only
     * the bottom for a line under the box), a style and a colour. Rendered by
     * setBorderVars() in the frontend's useSectionStyle.js.
     */
    const BORDER_FIELDS = [
            'border_width' => [ 'type' => 'sides', 'label' => 'Border Width', 'tab' => 'style', 'group' => 'Border', 'responsive' => true,
                    'help' => 'Each side on its own, e.g. 1px. Only the bottom draws a line under the box.' ],
            'border_style' => [ 'type' => 'select', 'label' => 'Border Style', 'tab' => 'style', 'group' => 'Border', 'default' => 'solid',
                    'options' => [ 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted', 'double' => 'Double' ] ],
            'border_color' => [ 'type' => 'color', 'label' => 'Border Color', 'tab' => 'style', 'group' => 'Border', 'responsive' => true, 'screens' => true ],
    ];

    /**
     * Box shadow, full control, shared by containers and every block.
     * Rendered by buildShadow() in the frontend's useSectionStyle.js.
     */
    const SHADOW_FIELDS = [
            'shadow' => [ 'type' => 'toggle', 'label' => 'Add a shadow', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true ],
            'shadow_x' => [ 'type' => 'length', 'label' => 'Horizontal Offset', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true, 'allow_negative' => true,
                    'default' => '0px', 'show_if' => [ 'shadow' => true ] ],
            'shadow_y' => [ 'type' => 'length', 'label' => 'Vertical Offset', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true, 'allow_negative' => true,
                    'default' => '8px', 'show_if' => [ 'shadow' => true ] ],
            'shadow_blur' => [ 'type' => 'length', 'label' => 'Blur', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true,
                    'default' => '24px', 'show_if' => [ 'shadow' => true ] ],
            'shadow_spread' => [ 'type' => 'length', 'label' => 'Spread', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true, 'allow_negative' => true,
                    'default' => '0px', 'show_if' => [ 'shadow' => true ] ],
            'shadow_color' => [ 'type' => 'color', 'label' => 'Shadow Color', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true,
                    'default' => '#0000001f', 'show_if' => [ 'shadow' => true ],
                    'help' => 'Pick a colour with some transparency for a soft shadow.' ],
            'shadow_inset' => [ 'type' => 'toggle', 'label' => 'Inside the box (inset)', 'tab' => 'style', 'group' => 'Shadow', 'responsive' => true, 'screens' => true, 'show_if' => [ 'shadow' => true ] ],
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
            // Was "Space Above" / "Space Below" (margin_top / margin_bottom) until 2026-10: fold_old_margins().
            'margin' => [ 'type' => 'sides', 'label' => 'Margin', 'tab' => 'style', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true,
                    'help' => 'Space outside the section. A negative value pulls it closer to its neighbour.' ],

    ] + self::BACKGROUND_FIELDS + [

            'text_color' => [ 'type' => 'color', 'label' => 'Default Text Color', 'tab' => 'style', 'group' => 'Colors & Border', 'responsive' => true, 'screens' => true ],
            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Colors & Border', 'responsive' => true, 'screens' => true, 'placeholder' => 'e.g. 12px' ],

    ] + self::BORDER_FIELDS + self::SHADOW_FIELDS + [

            // The whole section as one link (buttons and links inside it keep working).
            'link_url' => [ 'type' => 'url', 'label' => 'Make the whole section a link', 'tab' => 'style', 'group' => 'Link & hover',
                    'placeholder' => '/products or https://...', 'help' => 'Leave empty for no link. Buttons and links inside the section still work.' ],
            'link_new_tab' => [ 'type' => 'toggle', 'label' => 'Open the link in a new tab', 'tab' => 'style', 'group' => 'Link & hover' ],
            'link_label' => [ 'type' => 'text', 'label' => 'Link description (for screen readers)', 'tab' => 'style', 'group' => 'Link & hover',
                    'placeholder' => 'e.g. Shop necklaces' ],
            'hover_effect' => [ 'type' => 'select', 'label' => 'Hover Effect', 'tab' => 'style', 'group' => 'Link & hover', 'default' => 'none',
                    'options' => [ 'none' => 'None', 'zoom' => 'Zoom the background', 'darken' => 'Darken', 'lift' => 'Lift' ],
                    'help' => 'Plays when the mouse is over the section, and on phones while a finger touches it.' ],

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
            'box_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Box', 'responsive' => true, 'screens' => true, 'placeholder' => 'e.g. 12px' ],
    ] + self::BORDER_FIELDS + self::SHADOW_FIELDS + self::BACKGROUND_FIELDS + [
            'margin' => [ 'type' => 'sides', 'label' => 'Margin', 'tab' => 'advanced', 'group' => 'Spacing', 'responsive' => true, 'allow_negative' => true,
                    'help' => 'Space outside the block. A negative value pulls it closer to its neighbour.' ],
            'width' => [ 'type' => 'length', 'label' => 'Block Width', 'tab' => 'advanced', 'group' => 'Size', 'responsive' => true,
                    'placeholder' => 'auto, 50%, 320px',
                    'help' => 'Mostly useful when the parent section arranges blocks horizontally.' ],
            'max_width' => [ 'type' => 'length', 'label' => 'Max Width', 'tab' => 'advanced', 'group' => 'Size', 'responsive' => true, 'placeholder' => 'e.g. 640px' ],
            'hide_on' => [ 'type' => 'devices', 'label' => 'Hide On', 'tab' => 'advanced', 'group' => 'Visibility' ],
            'anchor_id' => [ 'type' => 'anchor', 'label' => 'Anchor ID', 'tab' => 'advanced', 'group' => 'Visibility' ],
            // Entrance animation: this block only (never whole sections), once, when it scrolls into view.
            'animation' => [ 'type' => 'select', 'label' => 'Entrance Animation', 'tab' => 'advanced', 'group' => 'Animation', 'default' => 'none',
                    'options' => [ 'none' => 'None', 'fade_up' => 'Fade in and rise', 'fade' => 'Fade in', 'zoom_in' => 'Grow in', 'reveal' => 'Reveal from the bottom' ],
                    'help' => 'Plays once when the block scrolls into view. Not in the page\'s first section, and never for visitors who turned animations off.' ],
            'animation_delay' => [ 'type' => 'number', 'label' => 'Delay (milliseconds)', 'tab' => 'advanced', 'group' => 'Animation', 'min' => 0, 'max' => 1500, 'default' => 0,
                    'show_if' => [ 'animation' => [ 'fade_up', 'fade', 'zoom_in', 'reveal' ] ] ],
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

    /**
     * Icons for the header widgets' buttons (Mini Cart, Mini Wishlist, the Menu's
     * phone button). Same format; mirrored in the frontend's src/utils/builder-icons.js.
     */
    const CART_ICONS = [
            'cart'         => [ 'label' => 'Cart',          'icon' => 'shopping_cart' ],
            'cart_outline' => [ 'label' => 'Cart outline',  'icon' => 'shopping_cart' ],
            'bag'          => [ 'label' => 'Bag',           'icon' => 'shopping_bag' ],
            'bag_outline'  => [ 'label' => 'Bag outline',   'icon' => 'shopping_bag' ],
            'basket'       => [ 'label' => 'Basket',        'icon' => 'shopping_basket' ],
            'tote'         => [ 'label' => 'Tote',          'icon' => 'local_mall' ],
    ];
    const WISHLIST_ICONS = [
            'heart_outline' => [ 'label' => 'Heart outline', 'icon' => 'favorite_border' ],
            'heart'         => [ 'label' => 'Heart',         'icon' => 'favorite' ],
            'bookmark'      => [ 'label' => 'Bookmark',      'icon' => 'bookmark_border' ],
            'star'          => [ 'label' => 'Star',          'icon' => 'star_border' ],
    ];
    const MENU_ICONS = [
            'menu'      => [ 'label' => 'Three lines',  'icon' => 'menu' ],
            'menu_open' => [ 'label' => 'Lines with arrow', 'icon' => 'menu_open' ],
            'short'     => [ 'label' => 'Short lines',  'icon' => 'segment' ],
            'dots'      => [ 'label' => 'Dots',         'icon' => 'more_vert' ],
            'grid'      => [ 'label' => 'Grid',         'icon' => 'apps' ],
    ];

    const SEARCH_ICONS = [
            'search'        => [ 'label' => 'Magnifier',        'icon' => 'search' ],
            'manage_search' => [ 'label' => 'Magnifier & lines', 'icon' => 'manage_search' ],
            'travel'        => [ 'label' => 'Globe',            'icon' => 'travel_explore' ],
    ];

    /** Icon sets selectable by an `icon` field's `icon_set` key. */
    const ICON_SETS = [
            'advantage' => self::ADVANTAGE_ICONS,
            'general'   => self::GENERAL_ICONS,
            'cart'      => self::CART_ICONS,
            'wishlist'  => self::WISHLIST_ICONS,
            'menu'      => self::MENU_ICONS,
            'search'    => self::SEARCH_ICONS,
    ];

    /** Whether a header widget's drawer opens by swiping in from an edge of the screen (phones). */
    const SWIPE_OPTIONS = [ 'auto' => 'Automatic', 'none' => 'No', 'left' => 'From the left edge', 'right' => 'From the right edge' ];

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
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'default' ],
                            'ts:title' => [ 'type' => 'text_style', 'label' => 'Title', 'default_font' => 'headings', 'keys' => [ 'size' => 'font_size', 'weight' => 'font_weight' ], 'placeholders' => [ 'size' => 'e.g. 48px or 3rem' ] ],
                            'ts:subtitle' => [ 'type' => 'text_style', 'label' => 'Subtitle' ],
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
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'default' ],
                            'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => [ 'font', 'size', 'weight', 'line_height', 'color', 'spacing', 'case' ], 'keys' => [ 'font' => 'font', 'size' => 'font_size', 'weight' => 'font_weight', 'line_height' => 'line_height', 'color' => 'text_color' ] ],
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
                                            'hover_image' => [ 'type' => 'image', 'label' => 'Second image (optional)',
                                                    'help' => 'Shown on hover when the Hover Effect is "Show the second image".' ],
                                            'solid_image' => [ 'type' => 'image', 'label' => 'Image on the solid header (optional)',
                                                    'help' => 'Header only: a fixed header that turns solid when the page scrolls shows this one instead (e.g. a dark logo).' ],
                                            'tags' => self::PRODUCT_TAGS_FIELD + [ 'image_field' => 'image' ],
                                    ],
                            ],
                            'hover_effect' => [ 'type' => 'select', 'label' => 'Hover Effect', 'tab' => 'style', 'group' => 'Hover', 'default' => 'none',
                                    'options' => [ 'none' => 'None', 'zoom' => 'Zoom in', 'darken' => 'Darken', 'swap' => 'Show the second image' ],
                                    'help' => 'Plays when the mouse is over an image, and on phones while a finger touches it.' ],
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
                            'fill_height' => [ 'type' => 'select', 'label' => 'Match the Row Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => 'no',
                                    'options' => [ 'no' => 'No', 'yes' => 'Yes' ],
                                    'help' => 'The image takes the height of the row it sits in (set by the blocks beside it) and is cropped to fit. Where blocks stack, as on phones, it uses Image Height, else 240px.' ],
                            'aspect_ratio' => [ 'type' => 'select', 'label' => 'Aspect Ratio', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => 'auto',
                                    'options' => [ 'auto' => 'Original', '1/1' => 'Square (1:1)', '4/3' => '4:3', '3/2' => '3:2', '16/9' => '16:9', '3/4' => 'Portrait (3:4)', '2/3' => 'Portrait (2:3)' ],
                                    'help' => 'Ignored when a fixed Image Height is set.' ],
                            'object_fit'   => [ 'type' => 'select', 'label' => 'Fit', 'tab' => 'style', 'group' => 'Size', 'default' => 'cover',
                                    'options' => [ 'cover' => 'Cover (crop to fill)', 'contain' => 'Contain (show whole image)', 'fill' => 'Stretch', 'none' => 'None' ],
                                    'help' => 'How the image fills its box when both a width and a height/ratio are set.' ],
                            'alignment'    => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'center' ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Border', 'placeholder' => 'e.g. 8px' ],
                            'svg_inline' => [ 'type' => 'toggle', 'label' => 'Draw SVG images as code', 'tab' => 'style', 'group' => 'SVG',
                                    'help' => 'SVG images become part of the page, so they can take a colour. Other images stay as they are.' ],
                            'svg_color' => [ 'type' => 'color', 'label' => 'Recolour SVG', 'tab' => 'style', 'group' => 'SVG', 'show_if' => [ 'svg_inline' => true ],
                                    'help' => 'Leave empty to keep the SVG\'s own colours.' ],
                            'ts:caption' => [ 'type' => 'text_style', 'label' => 'Captions' ],
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
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'default' ],
                            'style'     => [ 'type' => 'select', 'label' => 'Style', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'primary',
                                    'options' => [ 'primary' => 'Primary (filled)', 'secondary' => 'Secondary (filled)', 'outline' => 'Outline', 'flat' => 'Text only', 'link' => 'Underlined link' ] ],
                            'size'      => [ 'type' => 'select', 'label' => 'Size', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'md',
                                    'options' => [ 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large' ] ],
                            'btn_padding' => [ 'type' => 'sides', 'label' => 'Button Padding', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'help' => 'Space inside the button around its text. Replaces the size\'s padding where set (0 works too).' ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Appearance', 'placeholder' => 'e.g. 999px for a pill' ],
                            'bg_color'   => [ 'type' => 'color', 'label' => 'Background Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Button Text', 'default_font' => 'buttons', 'keys' => [ 'font' => 'font', 'size' => 'text_size', 'weight' => 'font_weight', 'color' => 'text_color' ] ],
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
                            'button_size'  => [ 'type' => 'select', 'label' => 'Button Size', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'md',
                                    'options' => [ 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large' ] ],
                            'button_align' => [ 'type' => 'select', 'label' => 'Button Alignment', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'default' ],
                            'max_width'    => [ 'type' => 'length', 'label' => 'Form Max Width', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'placeholder' => 'e.g. 560px' ],
                            'accent_color'      => [ 'type' => 'color', 'label' => 'Accent Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary',
                                    'help' => 'Focused input borders, checked boxes/radios and the default button fill.' ],
                            'button_bg_color'   => [ 'type' => 'color', 'label' => 'Button Background', 'tab' => 'style', 'group' => 'Colors' ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Labels', 'parts' => [ 'font', 'size', 'weight', 'line_height', 'color' ] ],
                            'ts:input' => [ 'type' => 'text_style', 'label' => 'Input Text', 'parts' => [ 'font', 'size', 'line_height', 'color' ] ],
                            'ts:button' => [ 'type' => 'text_style', 'label' => 'Button Text', 'default_font' => 'buttons', 'keys' => [ 'size' => 'button_text_size', 'color' => 'button_text_color' ] ],
                            'ts:message' => [ 'type' => 'text_style', 'label' => 'Thank-You Message', 'parts' => [ 'font', 'size', 'line_height', 'color' ] ],
                    ],
            ],

            /* ---- Data-driven widgets (fetch/render their own items) ---- */

            'featured_products' => [
                    'label'  => 'Featured Products',
                    'category' => 'shop',
                    'icon'   => 'products',
                    'fields' => [
                            'product_ids'    => [ 'type' => 'products', 'label' => 'Products', 'tab' => 'content' ],
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'product', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],
                            'items_per_view' => [ 'type' => 'number', 'label' => 'Products Per Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                    ],
            ],
            /* ---- Dynamic blocks: only on their template page ('pages'), showing that page's content ---- */

            /* ---- Loop template blocks: only in a card template of their kind (LOOP_TYPES), showing that card's item ---- */
            'lp_image' => [
                    'label' => 'Product Image', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'format-image',
                    'fields' => self::LOOP_IMAGE_FIELDS + [
                            'show_badge' => [ 'type' => 'toggle', 'label' => 'Show the Sale / Sold out label', 'tab' => 'content', 'default' => true ],
                            'badge_position' => [ 'type' => 'select', 'label' => 'Label Position', 'tab' => 'content', 'default' => 'top-start', 'show_if' => [ 'show_badge' => true ],
                                    'options' => [ 'top-start' => 'Top, start', 'top-end' => 'Top, end', 'bottom-start' => 'Bottom, start', 'bottom-end' => 'Bottom, end' ] ],
                            'show_wishlist' => [ 'type' => 'toggle', 'label' => 'Show the wishlist heart', 'tab' => 'content' ],
                    ],
            ],
            'lp_title' => [
                    'label' => 'Product Name', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'title',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Name', 'default_font' => 'headings', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lp_price' => [
                    'label' => 'Product Price', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'products',
                    'fields' => [
                            'show_old' => [ 'type' => 'toggle', 'label' => 'Show the old price when on sale', 'tab' => 'content', 'default' => true ],
                            'align' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
                            'ts:price' => [ 'type' => 'text_style', 'label' => 'Price' ],
                            'ts:old' => [ 'type' => 'text_style', 'label' => 'Old Price', 'parts' => [ 'font', 'size', 'line_height', 'color' ] ],
                    ],
            ],
            'lp_excerpt' => [
                    'label' => 'Product Short Description', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'article',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lp_category' => [
                    'label' => 'Product Category', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'category',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lp_rating' => [
                    'label' => 'Product Rating', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'star',
                    'fields' => [
                            'show_count' => [ 'type' => 'toggle', 'label' => 'Show the number of reviews', 'tab' => 'content', 'default' => true ],
                    ] + self::LOOP_STARS_FIELDS,
            ],
            'lp_cart' => [
                    'label' => 'Add to Cart Button', 'category' => 'dynamic', 'pages' => [ 'loop_product' ], 'icon' => 'cart',
                    'fields' => [
                            'text' => [ 'type' => 'text', 'label' => 'Button Text', 'tab' => 'content', 'default' => 'Add to cart' ],
                            'options_text' => [ 'type' => 'text', 'label' => 'Text for products with options', 'tab' => 'content', 'default' => 'Choose options',
                                    'help' => 'Products with sizes or colours open their page instead.' ],
                            'button_style' => [ 'type' => 'select', 'label' => 'Style', 'tab' => 'style', 'group' => 'Button', 'default' => 'primary',
                                    'options' => [ 'primary' => 'Filled (main colour)', 'secondary' => 'Filled (second colour)', 'outline' => 'Outline', 'link' => 'Text link' ] ],
                            'full_width' => [ 'type' => 'toggle', 'label' => 'Full width', 'tab' => 'style', 'group' => 'Button' ],
                            'radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Button', 'placeholder' => 'e.g. 4px' ],
                            'align' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Button', 'responsive' => true, 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Button Text', 'default_font' => 'buttons' ],
                    ],
            ],

            'lc_image' => [
                    'label' => 'Category Image', 'category' => 'dynamic', 'pages' => [ 'loop_category' ], 'icon' => 'format-image',
                    'fields' => self::LOOP_IMAGE_FIELDS,
            ],
            'lc_name' => [
                    'label' => 'Category Name', 'category' => 'dynamic', 'pages' => [ 'loop_category' ], 'icon' => 'title',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Name', 'default_font' => 'headings', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lc_count' => [
                    'label' => 'Number of Products', 'category' => 'dynamic', 'pages' => [ 'loop_category' ], 'icon' => 'products',
                    'fields' => [
                            'format' => [ 'type' => 'text', 'label' => 'Text', 'tab' => 'content', 'default' => '{count} products', 'help' => '{count} becomes the number.' ],
                    ] + self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lc_description' => [
                    'label' => 'Category Description', 'category' => 'dynamic', 'pages' => [ 'loop_category' ], 'icon' => 'article',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],

            'lpo_image' => [
                    'label' => 'Post Cover Image', 'category' => 'dynamic', 'pages' => [ 'loop_post' ], 'icon' => 'format-image',
                    'fields' => self::LOOP_IMAGE_FIELDS,
            ],
            'lpo_title' => [
                    'label' => 'Post Title', 'category' => 'dynamic', 'pages' => [ 'loop_post' ], 'icon' => 'title',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Title', 'default_font' => 'headings', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lpo_date' => [
                    'label' => 'Post Date', 'category' => 'dynamic', 'pages' => [ 'loop_post' ], 'icon' => 'event',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lpo_excerpt' => [
                    'label' => 'Post Summary', 'category' => 'dynamic', 'pages' => [ 'loop_post' ], 'icon' => 'article',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lpo_category' => [
                    'label' => 'Post Category', 'category' => 'dynamic', 'pages' => [ 'loop_post' ], 'icon' => 'category',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],

            'lr_stars' => [
                    'label' => 'Review Stars', 'category' => 'dynamic', 'pages' => [ 'loop_review' ], 'icon' => 'star',
                    'fields' => self::LOOP_STARS_FIELDS,
            ],
            'lr_text' => [
                    'label' => 'Review Text', 'category' => 'dynamic', 'pages' => [ 'loop_review' ], 'icon' => 'format-quote',
                    'fields' => [
                            'quotes' => [ 'type' => 'toggle', 'label' => 'Add quotation marks', 'tab' => 'content' ],
                    ] + self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lr_name' => [
                    'label' => 'Reviewer Name', 'category' => 'dynamic', 'pages' => [ 'loop_review' ], 'icon' => 'title',
                    'fields' => self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Name', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lr_verified' => [
                    'label' => 'Verified Buyer Label', 'category' => 'dynamic', 'pages' => [ 'loop_review' ], 'icon' => 'check',
                    'fields' => [
                            'text' => [ 'type' => 'text', 'label' => 'Text', 'tab' => 'content', 'default' => 'Verified buyer',
                                    'help' => 'Shows only on reviews marked "Verified buyer".' ],
                    ] + self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],
            'lr_product' => [
                    'label' => 'Reviewed Product Link', 'category' => 'dynamic', 'pages' => [ 'loop_review' ], 'icon' => 'products',
                    'fields' => [
                            'suffix' => [ 'type' => 'text', 'label' => 'Text after the name', 'tab' => 'content', 'default' => ' ↗' ],
                    ] + self::LOOP_TEXT_FIELDS + [ 'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => self::LOOP_TEXT_PARTS ] ],
            ],


            'post_title' => [
                    'label'  => 'Post Title',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'title',
                    'fields' => [
                            'tag'   => [ 'type' => 'select', 'label' => 'Heading Level', 'tab' => 'content', 'default' => 'h1',
                                    'options' => [ 'h1' => 'H1 (recommended)', 'h2' => 'H2' ] ],
                            'align' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
                            'ts:title' => [ 'type' => 'text_style', 'label' => 'Title', 'default_font' => 'headings' ],
                    ],
            ],
            'post_meta' => [
                    'label'  => 'Post Date & Categories',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'event',
                    'fields' => [
                            'show_date'       => [ 'type' => 'toggle', 'label' => 'Show the date', 'tab' => 'content', 'default' => true ],
                            'show_categories' => [ 'type' => 'toggle', 'label' => 'Show the categories', 'tab' => 'content', 'default' => true ],
                            'align'           => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
                            'ts:meta' => [ 'type' => 'text_style', 'label' => 'Text', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
                    ],
            ],
            'post_featured_image' => [
                    'label'  => 'Post Cover Image',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'image',
                    'fields' => [
                            'ratio'   => [ 'type' => 'select', 'label' => 'Shape', 'tab' => 'content', 'default' => '',
                                    'options' => [ '' => 'As uploaded', '16/9' => 'Wide (16:9)', '4/3' => '4:3', '1/1' => 'Square' ] ],
                            'rounded' => [ 'type' => 'toggle', 'label' => 'Rounded corners', 'tab' => 'style', 'default' => true ],
                    ],
            ],
            'post_content' => [
                    'label'  => 'Post Content',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'notes',
                    'fields' => [],
            ],
            'post_more' => [
                    'label'  => 'More Posts To Read',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'article',
                    'fields' => [
                            'title' => [ 'type' => 'text', 'label' => 'Heading', 'tab' => 'content', 'default' => 'More to read' ],
                            'count' => [ 'type' => 'number', 'label' => 'Posts', 'tab' => 'content', 'min' => 1, 'max' => 3, 'default' => 3 ],
                            'ts:heading' => [ 'type' => 'text_style', 'label' => 'Heading', 'default_font' => 'headings' ],
                            'ts:card_title' => [ 'type' => 'text_style', 'label' => 'Post Titles', 'default_font' => 'headings' ],
                    ],
            ],
            'post_back' => [
                    'label'  => 'Back To Blog Link',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog_post' ],
                    'icon'   => 'arrow-back',
                    'fields' => [
                            'label' => [ 'type' => 'text', 'label' => 'Text', 'tab' => 'content', 'default' => '← Blog' ],
                            'ts:link' => [ 'type' => 'text_style', 'label' => 'Link Text' ],
                    ],
            ],
            'blog_title' => [
                    'label'  => 'Blog Title',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog' ],
                    'icon'   => 'title',
                    'fields' => [
                            'title'            => [ 'type' => 'text', 'label' => 'Title on /blog', 'tab' => 'content', 'default' => 'Blog',
                                    'help' => 'A category page shows the category\'s name.' ],
                            'show_description' => [ 'type' => 'toggle', 'label' => 'Show the category\'s description', 'tab' => 'content', 'default' => true ],
                            'align'            => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'default' => 'default', 'options' => self::ALIGN_OPTIONS ],
                            'ts:title' => [ 'type' => 'text_style', 'label' => 'Title', 'default_font' => 'headings' ],
                            'ts:desc' => [ 'type' => 'text_style', 'label' => 'Description', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
                    ],
            ],
            'blog_categories' => [
                    'label'  => 'Blog Category Links',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog' ],
                    'icon'   => 'category',
                    'fields' => [
                            'all_label' => [ 'type' => 'text', 'label' => 'Label for all posts', 'tab' => 'content', 'default' => 'All' ],
                            'ts:link' => [ 'type' => 'text_style', 'label' => 'Links', 'parts' => [ 'font', 'size', 'weight', 'line_height' ] ],
                    ],
            ],
            'blog_posts' => [
                    'label'  => 'Blog Posts Grid',
                    'category' => 'dynamic',
                    'pages'  => [ 'blog' ],
                    'icon'   => 'grid',
                    'fields' => [
                            'per_page'     => [ 'type' => 'number', 'label' => 'Posts per page', 'tab' => 'content', 'min' => 1, 'max' => 24, 'default' => 9 ],
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'post', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],
                            'columns'      => [ 'type' => 'number', 'label' => 'Posts Per Row', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 4,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                            'show_excerpt' => [ 'type' => 'toggle', 'label' => 'Show the summary', 'tab' => 'content', 'default' => true ],
                            'show_date'    => [ 'type' => 'toggle', 'label' => 'Show the date', 'tab' => 'content', 'default' => true ],
                            'show_image'   => [ 'type' => 'toggle', 'label' => 'Show the cover image', 'tab' => 'content', 'default' => true ],
                            'ts:card_title' => [ 'type' => 'text_style', 'label' => 'Post Titles', 'default_font' => 'headings' ],
                            'ts:excerpt' => [ 'type' => 'text_style', 'label' => 'Summaries', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
                    ],
            ],

            'latest_posts' => [
                    'label'  => 'Latest Blog Posts',
                    'category' => 'shop',
                    'icon'   => 'article',
                    'fields' => [
                            'count'        => [ 'type' => 'number', 'label' => 'Posts', 'tab' => 'content', 'min' => 1, 'max' => 12, 'default' => 3 ],
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'post', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],
                            'columns'      => [ 'type' => 'number', 'label' => 'Posts Per Row', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 4,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                            'show_excerpt' => [ 'type' => 'toggle', 'label' => 'Show the summary', 'tab' => 'content', 'default' => true ],
                            'show_date'    => [ 'type' => 'toggle', 'label' => 'Show the date', 'tab' => 'content', 'default' => true ],
                            'button_label' => [ 'type' => 'text', 'label' => 'Link to the blog', 'tab' => 'content', 'default' => 'All posts',
                                    'help' => 'Leave empty for no link.' ],
                            'ts:card_title' => [ 'type' => 'text_style', 'label' => 'Post Titles', 'default_font' => 'headings' ],
                            'ts:excerpt' => [ 'type' => 'text_style', 'label' => 'Summaries', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
                    ],
            ],
            'category_grid' => [
                    'label'  => 'Category Grid',
                    'category' => 'shop',
                    'icon'   => 'category',
                    'fields' => [
                            'category_ids' => [ 'type' => 'categories', 'label' => 'Categories', 'tab' => 'content' ],
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'category', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],
                            'columns' => [ 'type' => 'number', 'label' => 'Categories Per Row', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 4, 'tablet' => 2, 'mobile' => 2 ] ],
                            'gap' => [ 'type' => 'length', 'label' => 'Gap Between Categories', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'placeholder' => '16px' ],
                    ],
            ],
            'testimonials' => [
                    'label'  => 'Testimonials',
                    'category' => 'trust',
                    'icon'   => 'format-quote',
                    'fields' => [
                            'display_style'  => [ 'type' => 'select', 'label' => 'Display Style', 'tab' => 'content', 'default' => 'grid',
                                    'options' => [ 'grid' => 'Grid', 'carousel' => 'Carousel' ] ],
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'review', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],

                            'items_per_view' => [ 'type' => 'number', 'label' => 'Items Per Row / Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 6,
                                    'default' => [ 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ] ],
                            'items' => [ 'type' => 'repeater', 'label' => 'Testimonials', 'tab' => 'content', 'max_items' => 20,
                                    'title_field' => 'name', 'item_label' => 'Testimonial', 'add_label' => '+ Add Testimonial',
                                    'fields' => [
                                            'name'        => [ 'type' => 'text', 'label' => 'Name and Title' ],
                                            'rating'      => [ 'type' => 'select', 'label' => 'Rating', 'default' => '5',
                                                    'options' => [ '5' => '★★★★★', '4' => '★★★★', '3' => '★★★', '2' => '★★', '1' => '★' ] ],
                                            'review_text' => [ 'type' => 'textarea', 'label' => 'Review Text' ],
                                            'verified'    => [ 'type' => 'toggle', 'label' => 'Verified buyer', 'help' => 'For loop templates with a "Verified buyer" label.' ],
                                            'product_label' => [ 'type' => 'text', 'label' => 'Product (optional)', 'placeholder' => 'e.g. Noa hoops' ],
                                            'product_url' => [ 'type' => 'url', 'label' => 'Product Link (optional)', 'placeholder' => '/product/noa-hoops' ],
                                    ],
                            ],
                            'star_color' => [ 'type' => 'color', 'label' => 'Star Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'ts:name' => [ 'type' => 'text_style', 'label' => 'Names', 'default_font' => 'headings' ],
                            'ts:review' => [ 'type' => 'text_style', 'label' => 'Reviews', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
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
                            'text_color' => [ 'type' => 'color', 'label' => 'Text Color', 'tab' => 'style', 'group' => 'Colors',
                                    'help' => 'Titles and descriptions, unless they have their own color below.' ],
                            'mobile_divider' => [ 'type' => 'toggle', 'label' => 'Line between cards on phones', 'tab' => 'style', 'group' => 'Colors', 'default' => true ],
                            'ts:title' => [ 'type' => 'text_style', 'label' => 'Card Titles' ],
                            'ts:desc' => [ 'type' => 'text_style', 'label' => 'Descriptions', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
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

            'icon' => [
                    'label'    => 'Icon',
                    'category' => 'basic',
                    'icon'     => 'star-filled',
                    'fields'   => [
                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'tab' => 'content', 'icon_set' => 'general', 'default' => 'star' ],
                            'custom_icon' => [ 'type' => 'image', 'accept' => 'svg', 'label' => 'Your Own Icon (SVG)', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ],
                                    'help' => 'SVG files only. It\'s drawn as code, so it stays sharp and can take the Icon Color.' ],
                            'recolor'     => [ 'type' => 'toggle', 'label' => 'Give your SVG the Icon Color', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ] ],
                            'link_url'    => [ 'type' => 'url', 'label' => 'Link (optional)', 'tab' => 'content', 'placeholder' => '/products or https://…' ],
                            'new_tab'     => [ 'type' => 'toggle', 'label' => 'Open in a new tab', 'tab' => 'content' ],
                            'label'       => [ 'type' => 'text', 'label' => 'Name for screen readers', 'tab' => 'content',
                                    'help' => 'What the icon means or where the link goes, e.g. "Instagram". Leave empty for a decoration.' ],
                            'alignment'   => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'default' ],

                            'size'        => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'default' => '32px',
                                    'hide_if' => [ 'icon' => 'custom' ] ],
                            // Your own SVG: width and height apart. One left empty follows the SVG's proportions; both empty = 32px wide.
                            'icon_width'  => [ 'type' => 'length', 'label' => 'Icon Width', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'default' => '',
                                    'placeholder' => '32px', 'show_if' => [ 'icon' => 'custom' ] ],
                            'icon_height' => [ 'type' => 'length', 'label' => 'Icon Height', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'default' => '',
                                    'placeholder' => 'auto', 'show_if' => [ 'icon' => 'custom' ],
                                    'help' => 'Leave Width or Height empty to keep the SVG\'s proportions.' ],
                            'color'       => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Icon', 'default' => 'global:secondary' ],
                            'hover_color' => [ 'type' => 'color', 'label' => 'Color on Hover', 'tab' => 'style', 'group' => 'Icon' ],
                    ],
            ],

            'icon_list' => [
                    'label'    => 'Icon List',
                    'category' => 'basic',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Items', 'tab' => 'content', 'max_items' => 30,
                                    'title_field' => 'text', 'item_label' => 'Item', 'add_label' => '+ Add Item',
                                    'fields' => [
                                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'icon_set' => 'general', 'default' => 'check' ],
                                            'custom_icon' => [ 'type' => 'image', 'label' => 'Custom Icon', 'show_if' => [ 'icon' => 'custom' ] ],
                                            'text'        => [ 'type' => 'text', 'label' => 'Text (optional)' ],
                                            'link_url'    => [ 'type' => 'url', 'label' => 'Link (optional)', 'placeholder' => '/shipping or https://…' ],
                                            'label'       => [ 'type' => 'text', 'label' => 'Name for screen readers',
                                                    'help' => 'For an icon without text, e.g. "Instagram".' ],
                                    ],
                            ],
                            'layout'    => [ 'type' => 'select', 'label' => 'Layout', 'tab' => 'content', 'responsive' => true, 'default' => 'vertical',
                                    'options' => [ 'vertical' => 'Vertical list', 'horizontal' => 'Horizontal (inline)' ] ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'default' ],

                            'icon_size'  => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'default' => '20px' ],
                            'gap'        => [ 'type' => 'length', 'label' => 'Space Between Items', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => '12px' ],
                            'divider'    => [ 'type' => 'toggle', 'label' => 'Divider between items', 'tab' => 'style', 'group' => 'Size' ],
                            'icon_color' => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                            'svg_recolor' => [ 'type' => 'toggle', 'label' => 'Give your own SVG icons the Icon Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'ts:text' => [ 'type' => 'text_style', 'label' => 'Text', 'keys' => [ 'size' => 'font_size' ] ],
                    ],
            ],

            // A moving line of short texts (a marquee / ticker). Rendered by RunningTextBlock.vue.
            'running_text' => [
                    'label'    => 'Running Text',
                    'category' => 'basic',
                    'summary'  => 'items',
                    'fields'   => [
                            'items' => [ 'type' => 'repeater', 'label' => 'Texts', 'tab' => 'content', 'max_items' => 12,
                                    'require' => 'text', 'title_field' => 'text', 'item_label' => 'Text', 'add_label' => '+ Add Text',
                                    'fields' => [
                                            'text'     => [ 'type' => 'text', 'label' => 'Text' ],
                                            'link_url' => [ 'type' => 'url', 'label' => 'Link (optional)', 'placeholder' => '/products or https://…' ],
                                    ],
                            ],
                            'direction' => [ 'type' => 'select', 'label' => 'Movement', 'tab' => 'content', 'default' => 'left',
                                    'options' => [ 'left' => 'Sideways, to the left', 'right' => 'Sideways, to the right', 'up' => 'Upward, one text at a time' ] ],
                            'speed' => [ 'type' => 'number', 'label' => 'Speed (pixels per second)', 'tab' => 'content', 'min' => 10, 'max' => 300, 'default' => 40,
                                    'show_if' => [ 'direction' => [ 'left', 'right' ] ] ],
                            'seconds' => [ 'type' => 'number', 'label' => 'Seconds per text', 'tab' => 'content', 'min' => 1, 'max' => 30, 'default' => 3,
                                    'show_if' => [ 'direction' => 'up' ] ],
                            'separator' => [ 'type' => 'text', 'label' => 'Separator', 'tab' => 'content', 'default' => '—',
                                    'placeholder' => '—, •, ✦ or leave empty', 'show_if' => [ 'direction' => [ 'left', 'right' ] ] ],
                            'pause_on_hover' => [ 'type' => 'toggle', 'label' => 'Pause while the mouse is over it', 'tab' => 'content', 'default' => true ],
                            'alignment' => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'content', 'options' => self::ALIGN_OPTIONS, 'default' => 'center',
                                    'show_if' => [ 'direction' => 'up' ] ],

                            'gap' => [ 'type' => 'length', 'label' => 'Space Between Texts', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'placeholder' => '32px' ],
                            'padding_y' => [ 'type' => 'length', 'label' => 'Space Above and Below', 'tab' => 'style', 'group' => 'Layout', 'responsive' => true, 'placeholder' => '12px' ],
                            'lines' => [ 'type' => 'toggle', 'label' => 'Thin lines above and below', 'tab' => 'style', 'group' => 'Layout' ],
                            'line_color' => [ 'type' => 'color', 'label' => 'Line Color', 'tab' => 'style', 'group' => 'Layout', 'show_if' => [ 'lines' => true ] ],
                            'uppercase' => [ 'type' => 'toggle', 'label' => 'Capital letters', 'tab' => 'style', 'group' => 'Layout' ],
                            'letter_spacing' => [ 'type' => 'length', 'label' => 'Letter Spacing', 'tab' => 'style', 'group' => 'Layout', 'placeholder' => 'e.g. 0.1em' ],
                            'separator_color' => [ 'type' => 'color', 'label' => 'Separator Color', 'tab' => 'style', 'group' => 'Layout' ],
                            'ts:text' => [ 'type' => 'text_style', 'label' => 'Text' ],
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
                            'accent_color'   => [ 'type' => 'color', 'label' => 'Icon / Divider Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'item_bg'        => [ 'type' => 'color', 'label' => 'Card Background', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'style' => 'boxed' ] ],
                            'ts:question' => [ 'type' => 'text_style', 'label' => 'Questions' ],
                            'ts:answer' => [ 'type' => 'text_style', 'label' => 'Answers', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
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
                                    'options' => self::ALIGN_OPTIONS + [ 'stretch' => 'Full width' ], 'default' => 'default' ],
                            'active_color' => [ 'type' => 'color', 'label' => 'Active Tab Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                            'ts:tab' => [ 'type' => 'text_style', 'label' => 'Tab Titles', 'keys' => [ 'color' => 'text_color' ] ],
                            'ts:content' => [ 'type' => 'text_style', 'label' => 'Content', 'parts' => [ 'font', 'size', 'line_height', 'color', 'spacing', 'case' ] ],
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
                            'alignment'    => [ 'type' => 'select', 'label' => 'Alignment', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true,
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'center' ],
                            'box_bg'       => [ 'type' => 'color', 'label' => 'Box Background', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'style' => 'boxes' ] ],
                            'ts:number' => [ 'type' => 'text_style', 'label' => 'Numbers', 'defaults' => [ 'size' => [ 'desktop' => '40px', 'tablet' => '', 'mobile' => '28px' ] ] ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Labels & Message' ],
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
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'product', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '' ],

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
                                            'tags'            => self::PRODUCT_TAGS_FIELD + [ 'image_field' => 'image', 'mobile_image_field' => 'mobile_image' ],
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
                            'card_template' => [ 'type' => 'loop_template', 'loop' => 'product', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '',
                                    'show_if' => [ 'source' => 'products' ] ],
                            'category_card_template' => [ 'type' => 'loop_template', 'loop' => 'category', 'label' => 'Loop Template', 'tab' => 'content', 'default' => '',
                                    'show_if' => [ 'source' => 'categories' ] ],

                            'row_style' => [ 'type' => 'select', 'label' => 'Carousel Style', 'tab' => 'content', 'responsive' => true, 'default' => 'whole',
                                    'options' => [ 'whole' => 'Whole items only', 'peek' => 'Show part of the next item', 'center' => 'Centred, with parts of both sides' ],
                                    'help' => 'A part of the next item tells visitors there is more to swipe.',
                                    'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'items_per_view' => [ 'type' => 'number', 'label' => 'Items Per Slide', 'tab' => 'content', 'responsive' => true, 'min' => 1, 'max' => 8,
                                    'default' => [ 'desktop' => 4, 'tablet' => 3, 'mobile' => 2 ], 'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'autoplay'         => [ 'type' => 'toggle', 'label' => 'Autoplay', 'tab' => 'content' ],
                            'autoplay_seconds' => [ 'type' => 'number', 'label' => 'Seconds Per Slide', 'tab' => 'content', 'min' => 2, 'max' => 30, 'default' => 5,
                                    'show_if' => [ 'autoplay' => true ] ],
                            'loop'   => [ 'type' => 'toggle', 'label' => 'Loop back to the start', 'tab' => 'content', 'default' => true ],
                            'arrows' => [ 'type' => 'toggle', 'label' => 'Show arrows', 'tab' => 'content', 'default' => true ],
                            'arrows_show' => [ 'type' => 'select', 'label' => 'Arrows to Show', 'tab' => 'content', 'default' => 'both',
                                    'options' => [ 'both' => 'Both arrows', 'next' => 'Only the "next" arrow', 'prev' => 'Only the "previous" arrow' ],
                                    'show_if' => [ 'arrows' => true ] ],
                            'arrows_mobile' => [ 'type' => 'toggle', 'label' => 'Show arrows on phones', 'tab' => 'content', 'default' => true,
                                    'show_if' => [ 'arrows' => true, 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'dots'   => [ 'type' => 'toggle', 'label' => 'Show dots', 'tab' => 'content', 'default' => true ],
                            'dots_position' => [ 'type' => 'select', 'label' => 'Dots Position', 'tab' => 'content', 'default' => 'below',
                                    'options' => [ 'below' => 'Below the row', 'custom' => 'Custom position' ],
                                    'help' => 'Custom: set the place on the Style tab (Dots).',
                                    'show_if' => [ 'dots' => true, 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'counter' => [ 'type' => 'toggle', 'label' => 'Show a counter (01 / 03) and a progress line', 'tab' => 'content',
                                    'show_if' => [ 'source' => 'slides' ] ],
                            'slide_transition' => [ 'type' => 'select', 'label' => 'Change Effect', 'tab' => 'content', 'default' => 'slide',
                                    'options' => [ 'slide' => 'Slide', 'fade' => 'Fade' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'slow_zoom' => [ 'type' => 'toggle', 'label' => 'Slow zoom on each slide', 'tab' => 'content', 'show_if' => [ 'source' => 'slides' ] ],

                            'slide_height'  => [ 'type' => 'length', 'label' => 'Slide Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'default' => [ 'desktop' => '560px', 'tablet' => '440px', 'mobile' => '380px' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'image_height'  => [ 'type' => 'length', 'label' => 'Image Height', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => 'e.g. 60px for logos', 'show_if' => [ 'source' => [ 'images', 'logos' ] ] ],
                            'gap'           => [ 'type' => 'length', 'label' => 'Gap Between Items', 'tab' => 'style', 'group' => 'Size', 'responsive' => true,
                                    'placeholder' => '16px', 'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'grayscale'     => [ 'type' => 'toggle', 'label' => 'Grayscale logos (color on hover)', 'tab' => 'style', 'group' => 'Size',
                                    'show_if' => [ 'source' => 'logos' ] ],
                            'bleed_mobile'  => [ 'type' => 'toggle', 'label' => 'Run to the screen edge on phones', 'tab' => 'style', 'group' => 'Size',
                                    'show_if' => [ 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'arrows_position' => [ 'type' => 'select', 'label' => 'Arrow Position', 'tab' => 'style', 'group' => 'Arrows', 'default' => 'sides',
                                    'options' => [ 'sides' => 'At both sides', 'top_end' => 'Together, above at the end', 'bottom_end' => 'Together, below at the end', 'bottom_center' => 'Together, below in the middle', 'custom' => 'Custom position' ],
                                    'help' => 'Custom: place each arrow on its own (Previous Arrow and Next Arrow below).',
                                    'show_if' => [ 'arrows' => true, 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'arrow_style'   => [ 'type' => 'select', 'label' => 'Arrow Look', 'tab' => 'style', 'group' => 'Arrows', 'default' => 'plain',
                                    'options' => [ 'plain' => 'Plain', 'outline' => 'Round, outlined', 'filled' => 'Round, filled', 'square' => 'Square, outlined' ],
                                    'show_if' => [ 'arrows' => true, 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'arrow_size'    => [ 'type' => 'length', 'label' => 'Arrow Size', 'tab' => 'style', 'group' => 'Arrows', 'placeholder' => 'e.g. 40px',
                                    'show_if' => [ 'arrows' => true, 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'arrow_bg'      => [ 'type' => 'color', 'label' => 'Arrow Background', 'tab' => 'style', 'group' => 'Arrows',
                                    'show_if' => [ 'arrows' => true, 'arrow_style' => 'filled', 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'prev_from_y' => [ 'type' => 'select', 'label' => 'Start From (top / bottom)', 'tab' => 'style', 'group' => 'Previous Arrow', 'responsive' => true, 'default' => 'middle',
                                    'options' => [ 'top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom' ], 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'prev' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'prev_from_x' => [ 'type' => 'select', 'label' => 'Start From (left / right)', 'tab' => 'style', 'group' => 'Previous Arrow', 'responsive' => true, 'default' => 'left',
                                    'options' => [ 'left' => 'Left', 'center' => 'Middle', 'right' => 'Right' ], 'help' => 'Left and right as seen on the screen, in every language.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'prev' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'prev_y' => [ 'type' => 'length', 'label' => 'Vertical Distance', 'tab' => 'style', 'group' => 'Previous Arrow', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ],
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'prev' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'prev_x' => [ 'type' => 'length', 'label' => 'Horizontal Distance', 'tab' => 'style', 'group' => 'Previous Arrow', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ],
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'prev' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'next_from_y' => [ 'type' => 'select', 'label' => 'Start From (top / bottom)', 'tab' => 'style', 'group' => 'Next Arrow', 'responsive' => true, 'default' => 'middle',
                                    'options' => [ 'top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom' ], 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'next' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'next_from_x' => [ 'type' => 'select', 'label' => 'Start From (left / right)', 'tab' => 'style', 'group' => 'Next Arrow', 'responsive' => true, 'default' => 'right',
                                    'options' => [ 'left' => 'Left', 'center' => 'Middle', 'right' => 'Right' ], 'help' => 'Left and right as seen on the screen, in every language.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'next' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'next_y' => [ 'type' => 'length', 'label' => 'Vertical Distance', 'tab' => 'style', 'group' => 'Next Arrow', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ],
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'next' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'next_x' => [ 'type' => 'length', 'label' => 'Horizontal Distance', 'tab' => 'style', 'group' => 'Next Arrow', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ],
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'arrows' => true, 'arrows_position' => 'custom', 'arrows_show' => [ 'both', 'next' ], 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'dots_from_y' => [ 'type' => 'select', 'label' => 'Start From (top / bottom)', 'tab' => 'style', 'group' => 'Dots', 'responsive' => true, 'default' => 'bottom',
                                    'options' => [ 'top' => 'Top', 'middle' => 'Middle', 'bottom' => 'Bottom' ], 'show_if' => [ 'dots' => true, 'dots_position' => 'custom', 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'dots_from_x' => [ 'type' => 'select', 'label' => 'Start From (left / right)', 'tab' => 'style', 'group' => 'Dots', 'responsive' => true, 'default' => 'center',
                                    'options' => [ 'left' => 'Left', 'center' => 'Middle', 'right' => 'Right' ], 'help' => 'Left and right as seen on the screen, in every language.', 'show_if' => [ 'dots' => true, 'dots_position' => 'custom', 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'dots_y' => [ 'type' => 'length', 'label' => 'Vertical Distance', 'tab' => 'style', 'group' => 'Dots', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ], 'default' => '16px',
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'dots' => true, 'dots_position' => 'custom', 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'dots_x' => [ 'type' => 'length', 'label' => 'Horizontal Distance', 'tab' => 'style', 'group' => 'Dots', 'responsive' => true, 'allow_negative' => true, 'units' => [ '%', 'px', 'vw' ],
                                    'help' => 'From the edge you chose. A negative value moves it outward.', 'show_if' => [ 'dots' => true, 'dots_position' => 'custom', 'source' => [ 'images', 'logos', 'products', 'categories' ] ] ],
                            'border_radius' => [ 'type' => 'length', 'label' => 'Corner Radius', 'tab' => 'style', 'group' => 'Size', 'placeholder' => 'e.g. 12px' ],
                            'button_style'  => [ 'type' => 'select', 'label' => 'Slide Button Style', 'tab' => 'style', 'group' => 'Colors', 'default' => 'light',
                                    'options' => [ 'light' => 'Light (white)', 'secondary' => 'Secondary color', 'outline' => 'Outline' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'control_color' => [ 'type' => 'color', 'label' => 'Arrows / Dots Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary' ],
                            'button_size'   => [ 'type' => 'select', 'label' => 'Slide Button Size', 'tab' => 'style', 'group' => 'Colors', 'default' => 'lg',
                                    'options' => [ 'sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'ts:title' => [ 'type' => 'text_style', 'label' => 'Slide Titles', 'parts' => [ 'font', 'size', 'weight', 'line_height' ], 'default_font' => 'headings', 'show_if' => [ 'source' => 'slides' ] ],
                            'ts:text' => [ 'type' => 'text_style', 'label' => 'Slide Text', 'parts' => [ 'font', 'size', 'weight', 'line_height' ], 'show_if' => [ 'source' => 'slides' ] ],
                            'ts:button' => [ 'type' => 'text_style', 'label' => 'Slide Button Text', 'parts' => [ 'font', 'size', 'weight', 'line_height' ], 'default_font' => 'buttons', 'keys' => [ 'size' => 'button_text_size' ], 'show_if' => [ 'source' => 'slides' ] ],
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
                                    'options' => self::ALIGN_OPTIONS, 'default' => 'default' ],

                            'shape'      => [ 'type' => 'select', 'label' => 'Icon Shape', 'tab' => 'style', 'group' => 'Appearance', 'default' => 'circle',
                                    'options' => [ 'none' => 'No background', 'circle' => 'Circle', 'rounded' => 'Rounded square', 'square' => 'Square' ] ],
                            'icon_size'  => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'default' => '18px' ],
                            'gap'        => [ 'type' => 'length', 'label' => 'Space Between', 'tab' => 'style', 'group' => 'Appearance', 'responsive' => true, 'placeholder' => '10px' ],
                            'color_mode' => [ 'type' => 'select', 'label' => 'Colors', 'tab' => 'style', 'group' => 'Colors', 'default' => 'custom',
                                    'options' => [ 'custom' => 'My colors (below)', 'brand' => 'Each network\'s brand color' ] ],
                            'icon_color' => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Colors', 'show_if' => [ 'color_mode' => 'custom' ] ],
                            'bg_color'   => [ 'type' => 'color', 'label' => 'Shape Color', 'tab' => 'style', 'group' => 'Colors', 'default' => 'global:secondary',
                                    'show_if' => [ 'color_mode' => 'custom' ] ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Labels', 'show_if' => [ 'display' => 'icon_label' ] ],
                    ],
            ],

            'menu_widget' => [
                    'label'    => 'Menu',
                    'category' => 'basic',
                    'fields'   => [
                            'menu_id' => [
                                    'type'    => 'menu',
                                    'label'   => 'Menu',
                                    'tab'     => 'content',
                                    'default' => '',
                                    'help'    => 'Choose which menu to display.',
                            ],
                            'layout' => [
                                    'type'        => 'select',
                                    'label'       => 'Layout',
                                    'tab'         => 'content',
                                    'responsive'  => true,
                                    'options'     => [
                                            'horizontal' => 'Horizontal (Navbar)',
                                            'vertical'   => 'Vertical (List)',
                                    ],
                                    'default'     => 'horizontal',
                            ],
                            'alignment' => [
                                    'type'        => 'select',
                                    'label'       => 'Alignment',
                                    'tab'         => 'content',
                                    'responsive'  => true,
                                    'options'     => self::ALIGN_OPTIONS,
                                    'default'     => 'default',
                            ],
                            'gap' => [
                                    'type'        => 'length',
                                    'label'       => 'Space Between Links',
                                    'tab'         => 'content',
                                    'responsive'  => true,
                                    'placeholder' => '16px',
                            ],
                            'enable_mobile_menu' => [
                                    'type'    => 'toggle',
                                    'label'   => 'Enable Mobile Hamburger Button',
                                    'tab'     => 'content',
                                    'default' => true,
                            ],
                            'mobile_menu_breakpoint' => [
                                    'type'    => 'select',
                                    'label'   => 'Show Hamburger Under',
                                    'tab'     => 'content',
                                    'options' => [
                                            '991'    => 'Tablet & Mobile (under 992px)',
                                            '767'    => 'Mobile only (under 768px)',
                                            'custom' => 'Custom width',
                                            'none'   => 'Never (always show full menu)',
                                    ],
                                    'default' => '991',
                                    'show_if' => [ 'enable_mobile_menu' => true ],
                            ],
                            'mobile_menu_custom_breakpoint' => [
                                    'type'        => 'number',
                                    'label'       => 'Custom Breakpoint (px)',
                                    'tab'         => 'content',
                                    'min'         => 320,
                                    'max'         => 2400,
                                    'default'     => 990,
                                    'show_if'     => [ 'enable_mobile_menu' => true, 'mobile_menu_breakpoint' => 'custom' ],
                            ],
                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'tab' => 'content', 'icon_set' => 'menu', 'default' => 'menu', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'custom_icon' => [ 'type' => 'image', 'label' => 'Your Icon', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ] + [ 'enable_mobile_menu' => true ],
                                    'help' => 'A small square image or SVG. It keeps its own colors.' ],
                            'label'       => [ 'type' => 'text', 'label' => 'Text Next to the Icon', 'tab' => 'content', 'placeholder' => 'e.g. Menu', 'show_if' => [ 'enable_mobile_menu' => true ],
                                    'help' => 'Optional. Leave empty to show only the icon.' ],
                            'drawer_title' => [ 'type' => 'text', 'label' => 'Drawer Title', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'Menu', 'show_if' => [ 'enable_mobile_menu' => true ],
                                    'help' => 'Leave empty for no title.' ],
                            'drawer_bg'    => [ 'type' => 'color', 'label' => 'Drawer Background', 'tab' => 'style', 'group' => 'Drawer', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'drawer_text_color' => [ 'type' => 'color', 'label' => 'Drawer Text Color', 'tab' => 'style', 'group' => 'Drawer', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'drawer_show_languages' => [ 'type' => 'toggle', 'label' => 'Show the language switcher in the drawer', 'tab' => 'content', 'group' => 'Drawer', 'default' => true, 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'drawer_show_notifications' => [ 'type' => 'toggle', 'label' => 'Offer push notifications in the drawer', 'tab' => 'content', 'group' => 'Drawer', 'default' => true, 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'swipe'        => [ 'type' => 'select', 'label' => 'Open by Swiping (Phones)', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'auto',
                                    'options' => self::SWIPE_OPTIONS, 'show_if' => [ 'enable_mobile_menu' => true ], 'swipe' => true,
                                    'help' => 'Automatic: the menu from the start side of the screen (left in English, right in Hebrew), the cart and wishlist from the other side. One drawer per edge: the first in the header wins.' ],

                            /* Style tab */
                            'ts:link' => [
                                    'type'         => 'text_style',
                                    'label'        => 'Menu Links',
                                    'parts'        => [ 'font', 'size', 'weight', 'line_height', 'color' ],
                                    'default_font' => 'text',
                            ],
                            'icon_size'   => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Phone Button', 'responsive' => true, 'placeholder' => '14px', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'icon_color'  => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Phone Button', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Phone Button Text', 'show_if' => [ 'enable_mobile_menu' => true ] ],
                            'link_hover_color' => [
                                    'type'    => 'color',
                                    'label'   => 'Link Hover Color',
                                    'tab'     => 'style',
                                    'group'   => 'Colors',
                                    'default' => 'global:primary',
                            ],
                            // The link of the page the visitor is on (empty: the link colour, underlined).
                            'active_link_color' => [ 'type' => 'color', 'label' => 'Current Page Link Color', 'tab' => 'style', 'group' => 'Colors' ],
                            'active_link_bg'    => [ 'type' => 'color', 'label' => 'Current Page Link Background', 'tab' => 'style', 'group' => 'Colors' ],
                            'dropdown_bg_color' => [
                                    'type'    => 'color',
                                    'label'   => 'Dropdown Background',
                                    'tab'     => 'style',
                                    'group'   => 'Colors',
                                    'show_if' => [ 'layout' => 'horizontal' ],
                            ],
                    ],
            ],
        'language_switcher' => [
            'label'    => 'Language Switcher',
            'category' => 'basic',
            'fields'   => [
                            'display'   => [ 'type' => 'select', 'label' => 'Show', 'tab' => 'content', 'default' => 'flag',
                                    'options' => [ 'flag' => 'Flag only', 'flag_name' => 'Flag and name', 'name' => 'Name only' ] ],
                            'flag_size' => [ 'type' => 'length', 'label' => 'Flag Width', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => '22px',
                                    'show_if' => [ 'display' => [ 'flag', 'flag_name' ] ] ],
                            'gap'       => [ 'type' => 'length', 'label' => 'Space Between Languages', 'tab' => 'style', 'group' => 'Size', 'responsive' => true, 'placeholder' => '14px' ],
                            'ts:name'   => [ 'type' => 'text_style', 'label' => 'Language Names', 'show_if' => [ 'display' => [ 'flag_name', 'name' ] ] ],
            ],
        ],
        'mini_cart' => [
            'label'    => 'Mini Cart',
            'category' => 'basic',
            'fields'   => [
                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'tab' => 'content', 'icon_set' => 'cart', 'default' => 'cart' ],
                            'custom_icon' => [ 'type' => 'image', 'label' => 'Your Icon', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ],
                                    'help' => 'A small square image or SVG. It keeps its own colors.' ],
                            'label'       => [ 'type' => 'text', 'label' => 'Text Next to the Icon', 'tab' => 'content', 'placeholder' => 'e.g. Cart',
                                    'help' => 'Optional. Leave empty to show only the icon.' ],
                            'show_total'      => [ 'type' => 'toggle', 'label' => 'Show the cart total', 'tab' => 'content' ],
                            'click_action'    => [ 'type' => 'select', 'label' => 'When Clicked', 'tab' => 'content', 'default' => 'drawer',
                                    'options' => [ 'drawer' => 'Open the cart drawer', 'page' => 'Go to the cart page' ] ],
                            'hide_when_empty' => [ 'type' => 'toggle', 'label' => 'Hide while the cart is empty', 'tab' => 'content' ],
                            'drawer_title' => [ 'type' => 'text', 'label' => 'Drawer Title', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'Cart', 'show_if' => [ 'click_action' => 'drawer' ],
                                    'help' => 'Leave empty for no title.' ],
                            'swipe'        => [ 'type' => 'select', 'label' => 'Open by Swiping (Phones)', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'auto',
                                    'options' => self::SWIPE_OPTIONS, 'show_if' => [ 'click_action' => 'drawer' ], 'swipe' => true,
                                    'help' => 'Automatic: the menu from the start side of the screen (left in English, right in Hebrew), the cart and wishlist from the other side. One drawer per edge: the first in the header wins.' ],
                            'drawer_bg'    => [ 'type' => 'color', 'label' => 'Drawer Background', 'tab' => 'style', 'group' => 'Drawer', 'show_if' => [ 'click_action' => 'drawer' ] ],
                            'drawer_text_color' => [ 'type' => 'color', 'label' => 'Drawer Text Color', 'tab' => 'style', 'group' => 'Drawer', 'show_if' => [ 'click_action' => 'drawer' ] ],
                            'icon_size'   => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'placeholder' => '14px' ],
                            'icon_color'  => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Icon' ],
                            'badge_position' => [ 'type' => 'select', 'label' => 'Count Position', 'tab' => 'style', 'group' => 'Count', 'default' => 'icon',
                                    'options' => [ 'icon' => 'On the icon (top corner)', 'beside' => 'Next to the icon' ] ],
                            'badge_bg'    => [ 'type' => 'color', 'label' => 'Count Background', 'tab' => 'style', 'group' => 'Count' ],
                            'badge_color' => [ 'type' => 'color', 'label' => 'Count Text Color', 'tab' => 'style', 'group' => 'Count' ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Text & Total' ],
            ],
        ],
        'search' => [
            'label'    => 'Search',
            'category' => 'basic',
            'fields'   => [
                            'display'     => [ 'type' => 'select', 'label' => 'Show as', 'tab' => 'content', 'default' => 'icon',
                                    'options' => [ 'icon' => 'An icon that opens a search box', 'field' => 'A search field' ] ],
                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'tab' => 'content', 'icon_set' => 'search', 'default' => 'search' ],
                            'custom_icon' => [ 'type' => 'image', 'label' => 'Your Icon', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ],
                                    'help' => 'A small square image or SVG. It keeps its own colors.' ],
                            'label'       => [ 'type' => 'text', 'label' => 'Text Next to the Icon', 'tab' => 'content', 'placeholder' => 'e.g. Search',
                                    'show_if' => [ 'display' => 'icon' ], 'help' => 'Optional. Leave empty to show only the icon.' ],
                            'placeholder' => [ 'type' => 'text', 'label' => 'Text in the Empty Field', 'tab' => 'content', 'default' => 'Search…' ],
                            'show_products'   => [ 'type' => 'toggle', 'label' => 'Products', 'tab' => 'content', 'group' => 'Results while typing', 'default' => true ],
                            'show_categories' => [ 'type' => 'toggle', 'label' => 'Product categories', 'tab' => 'content', 'group' => 'Results while typing' ],
                            'show_posts'      => [ 'type' => 'toggle', 'label' => 'Blog posts', 'tab' => 'content', 'group' => 'Results while typing' ],
                            'max_results'     => [ 'type' => 'number', 'label' => 'How many of each', 'tab' => 'content', 'group' => 'Results while typing', 'default' => 5, 'min' => 1, 'max' => 10,
                                    'help' => 'Enter, or "See all results", opens the search page with the same kinds of results.' ],
                            'field_width' => [ 'type' => 'length', 'label' => 'Field Width', 'tab' => 'style', 'group' => 'Field', 'responsive' => true, 'placeholder' => '220px',
                                    'show_if' => [ 'display' => 'field' ] ],
                            'field_bg'    => [ 'type' => 'color', 'label' => 'Field Background', 'tab' => 'style', 'group' => 'Field' ],
                            'field_text'  => [ 'type' => 'color', 'label' => 'Field Text Color', 'tab' => 'style', 'group' => 'Field' ],
                            'field_border' => [ 'type' => 'color', 'label' => 'Field Border Color', 'tab' => 'style', 'group' => 'Field' ],
                            'field_radius' => [ 'type' => 'length', 'label' => 'Field Corner Radius', 'tab' => 'style', 'group' => 'Field', 'placeholder' => '99px' ],
                            'icon_size'   => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'placeholder' => '18px' ],
                            'icon_color'  => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Icon' ],
                            'panel_bg'    => [ 'type' => 'color', 'label' => 'Results Background', 'tab' => 'style', 'group' => 'Results' ],
                            'panel_text'  => [ 'type' => 'color', 'label' => 'Results Text Color', 'tab' => 'style', 'group' => 'Results' ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Text', 'show_if' => [ 'display' => 'icon' ] ],
            ],
        ],

        'mini_wishlist' => [
            'label'    => 'Mini Wishlist',
            'category' => 'basic',
            'fields'   => [
                            'icon'        => [ 'type' => 'icon', 'label' => 'Icon', 'tab' => 'content', 'icon_set' => 'wishlist', 'default' => 'heart_outline' ],
                            'custom_icon' => [ 'type' => 'image', 'label' => 'Your Icon', 'tab' => 'content', 'show_if' => [ 'icon' => 'custom' ],
                                    'help' => 'A small square image or SVG. It keeps its own colors.' ],
                            'label'       => [ 'type' => 'text', 'label' => 'Text Next to the Icon', 'tab' => 'content', 'placeholder' => 'e.g. Wishlist',
                                    'help' => 'Optional. Leave empty to show only the icon.' ],
                            'hide_when_empty' => [ 'type' => 'toggle', 'label' => 'Hide while the wishlist is empty', 'tab' => 'content' ],
                            'drawer_title' => [ 'type' => 'text', 'label' => 'Drawer Title', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'Wishlist',
                                    'help' => 'Leave empty for no title.' ],
                            'swipe'        => [ 'type' => 'select', 'label' => 'Open by Swiping (Phones)', 'tab' => 'content', 'group' => 'Drawer', 'default' => 'none',
                                    'options' => self::SWIPE_OPTIONS, 'swipe' => true,
                                    'help' => 'Automatic: the menu from the start side of the screen (left in English, right in Hebrew), the cart and wishlist from the other side. One drawer per edge: the first in the header wins.' ],
                            'drawer_bg'    => [ 'type' => 'color', 'label' => 'Drawer Background', 'tab' => 'style', 'group' => 'Drawer' ],
                            'drawer_text_color' => [ 'type' => 'color', 'label' => 'Drawer Text Color', 'tab' => 'style', 'group' => 'Drawer' ],
                            'icon_size'   => [ 'type' => 'length', 'label' => 'Icon Size', 'tab' => 'style', 'group' => 'Icon', 'responsive' => true, 'placeholder' => '14px' ],
                            'icon_color'  => [ 'type' => 'color', 'label' => 'Icon Color', 'tab' => 'style', 'group' => 'Icon' ],
                            'badge_position' => [ 'type' => 'select', 'label' => 'Count Position', 'tab' => 'style', 'group' => 'Count', 'default' => 'icon',
                                    'options' => [ 'icon' => 'On the icon (top corner)', 'beside' => 'Next to the icon' ] ],
                            'badge_bg'    => [ 'type' => 'color', 'label' => 'Count Background', 'tab' => 'style', 'group' => 'Count' ],
                            'badge_color' => [ 'type' => 'color', 'label' => 'Count Text Color', 'tab' => 'style', 'group' => 'Count' ],
                            'ts:label' => [ 'type' => 'text_style', 'label' => 'Text' ],
            ],
        ]
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

    /** Cart page slots. */
    const CART_SECTION_LOCATIONS = [
            'before_cart'        => 'Before Cart',
            'before_cart_items'  => 'Before Cart Items',
            'after_cart_items'   => 'After Cart Items',
            'before_cart_totals' => 'Before Cart Totals',
            'after_cart_totals'  => 'After Cart Totals',
            'after_cart'         => 'After Cart',
    ];

    /** Checkout page slots. */
    const CHECKOUT_SECTION_LOCATIONS = [
            'before_checkout'         => 'Before Checkout',
            'before_customer_details' => 'Before Customer Details',
            'after_customer_details'  => 'After Customer Details',
            'before_order_review'     => 'Before Order Summary',
            'before_place_order'      => 'Before Place Order',
            'after_checkout'          => 'After Checkout',
    ];

    /**
     * Pages other than 'home' that support location-pinned sections. 'home'
     * is deliberately absent — its sections are a single flat, order-only list.
     */
    const PAGE_SECTION_LOCATIONS = [
            'shop'     => self::ARCHIVE_SECTION_LOCATIONS,
            'category' => self::ARCHIVE_SECTION_LOCATIONS,
            'product'  => self::PRODUCT_SECTION_LOCATIONS,
            'cart'     => self::CART_SECTION_LOCATIONS,
            'checkout' => self::CHECKOUT_SECTION_LOCATIONS,
    ];

    /**
     * Template pages: built entirely from sections (like the homepage), with
     * dynamic blocks for what the page shows ("Post title", "Posts grid"…).
     */
    const TEMPLATE_PAGES = [ 'blog', 'blog_post' ];

    /**
     * Pages that can have extra layouts, each used where its conditions
     * match (the first match wins; otherwise the page's default layout):
     * page => the condition kinds it takes.
     */
    const LAYOUT_PAGES = [
            'product'   => [ 'products', 'categories' ],
            'category'  => [ 'categories' ],
            'blog'      => [ 'blog_categories' ],
            'blog_post' => [ 'blog_categories' ],
            // Header and footer templates: the main one, plus others shown on chosen pages.
            'header'    => [ 'targets' ],
            'footer'    => [ 'targets' ],
    ];
    const MAX_LAYOUTS = 20;

    /**
     * Where a header or footer template can show (conditions.targets): these
     * kinds of pages, or one of the owner's pages by id (pg_…). 'pages' = all
     * the owner's pages except the homepage.
     */
    const CHROME_TARGETS = [ 'home', 'pages', 'shop', 'category', 'product', 'cart', 'checkout', 'blog', 'blog_post', 'search', 'account' ];

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

    /**
     * The parts of a text style ('ts:{prefix}' => [ 'type' => 'text_style' … ] in
     * BLOCK_SCHEMA). Each becomes a plain field, '{prefix}_{part}' unless the
     * entry's `keys` names an older field ('size' => 'question_size').
     */
    const TEXT_STYLE_PARTS = [
            'font'        => [ 'type' => 'font', 'label' => 'Font' ],
            'size'        => [ 'type' => 'length', 'label' => 'Size', 'responsive' => true, 'placeholder' => 'e.g. 18px' ],
            'weight'      => [ 'type' => 'select', 'label' => 'Weight', 'options' => self::FONT_WEIGHT_OPTIONS, 'default' => '' ],
            'line_height' => [ 'type' => 'length', 'label' => 'Line Height', 'responsive' => true, 'unitless' => true, 'placeholder' => 'e.g. 1.6' ],
            'color'       => [ 'type' => 'color', 'label' => 'Color' ],
            'spacing'     => [ 'type' => 'length', 'label' => 'Letter Spacing', 'responsive' => true, 'allow_negative' => true, 'placeholder' => 'e.g. 0.1em or 2px' ],
            'case'        => [ 'type' => 'select', 'label' => 'Letter Case', 'default' => '',
                    'options' => [ '' => 'As typed', 'uppercase' => 'UPPERCASE', 'lowercase' => 'lowercase', 'capitalize' => 'Capitalize Words' ] ],
    ];
    /** The parts a text style gets when it doesn't list its own. */
    const TEXT_STYLE_DEFAULT_PARTS = [ 'font', 'size', 'weight', 'line_height', 'color', 'spacing', 'case' ];

    private static $block_schema = null;

    /**
     * BLOCK_SCHEMA with every text style turned into its fields (Font, Size,
     * Weight, Line Height, Color), grouped under the text's name on the Style
     * tab. Everything that reads block fields goes through this.
     */
    public static function block_schema() {
        if ( self::$block_schema !== null ) return self::$block_schema;
        $schema = self::BLOCK_SCHEMA;
        foreach ( $schema as &$block ) {
            $fields = [];
            foreach ( (array) ( $block['fields'] ?? [] ) as $key => $spec ) {
                if ( ( $spec['type'] ?? '' ) !== 'text_style' ) {
                    $fields[ $key ] = $spec;
                    continue;
                }
                $prefix = substr( $key, 3 );
                foreach ( $spec['parts'] ?? self::TEXT_STYLE_DEFAULT_PARTS as $part ) {
                    $field = self::TEXT_STYLE_PARTS[ $part ] + [ 'tab' => 'style', 'group' => $spec['label'] ];
                    $field['group'] = $spec['label'];
                    // The dashboard folds a text's settings under its name.
                    $field['text_style'] = $prefix;
                    if ( $part === 'font' ) $field['default_font'] = $spec['default_font'] ?? 'text';
                    if ( isset( $spec['defaults'][ $part ] ) ) $field['default'] = $spec['defaults'][ $part ];
                    if ( isset( $spec['placeholders'][ $part ] ) ) $field['placeholder'] = $spec['placeholders'][ $part ];
                    if ( isset( $spec['show_if'] ) ) $field['show_if'] = $spec['show_if'];
                    if ( isset( $spec['hide_if'] ) ) $field['hide_if'] = $spec['hide_if'];
                    $name = $spec['keys'][ $part ] ?? "{$prefix}_{$part}";
                    // A part never replaces a field of its own name (give it another in `keys`).
                    if ( isset( $block['fields'][ $name ] ) || isset( $fields[ $name ] ) ) {
                        error_log( "Qwoo: text style {$key} would replace the field {$name}; skipped." );
                        continue;
                    }
                    $fields[ $name ] = $field;
                }
            }
            $block['fields'] = $fields;
        }
        unset( $block );
        return self::$block_schema = $schema;
    }

    /** Returns the schema entry (label + fields) for a block type, or null. */
    public static function get_type_schema( $type ) {
        return self::block_schema()[ $type ] ?? null;
    }
}
