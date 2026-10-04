<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The admin screen itself: menu registration, register_setting(), asset
 * enqueueing (+ the data localized to shop-builder.js), and the full
 * settings_page_html() tabbed form.
 */
trait SB_Admin_Page {

    /* -------------------------
       Enqueue Assets
       ------------------------- */
    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_qwoo-settings' !== $hook ) return;

        if ( class_exists( 'WooCommerce' ) ) {
            wp_enqueue_script( 'select2' );
            wp_enqueue_style( 'select2' );
            wp_enqueue_script( 'wc-enhanced-select' );
        }

        wp_enqueue_media();
        // TinyMCE + Quicktags for the Text block's rich-text editor
        // (initialized per block via wp.editor.initialize() in sb-builder-fields.js).
        wp_enqueue_editor();
        wp_enqueue_script( 'jquery-ui-sortable' );

        // Same font family used on the storefront
        // (@quasar/extras/material-icons), loaded here ONLY on this admin
        // screen, so the advantage-icon preview in wp-admin is the literal
        // same glyph the customer will see — not an approximation via a
        // different icon set that can silently diverge from the frontend.
        wp_enqueue_style(
                'qwoo-admin-material-icons',
                'https://fonts.googleapis.com/icon?family=Material+Icons',
                [],
                null
        );

        wp_enqueue_style(
                'shop-builder-style',
                QWOO_URL . 'assets/admin/css/shop-builder.css',
                [],
                QWOO_VERSION
        );

        // Live Preview panel styling, kept inline rather than added to
        // shop-builder.css so this feature's styles are self-contained and
        // can't drift out of sync with (or accidentally clobber) whatever
        // is already in that file.
        wp_add_inline_style( 'shop-builder-style', '
                .qwoo-live-preview-panel{position:fixed;top:32px;right:0;bottom:0;width:70vw;min-width:420px;max-width:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.15);z-index:100000;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .2s ease;}
                .qwoo-live-preview-panel.is-open{transform:translateX(0);}
                .qwoo-live-preview-panel__header{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid #dcdcde;background:#f6f7f7;}
                .qwoo-live-preview-panel__header strong{display:flex;align-items:center;gap:6px;}
                .qwoo-live-preview-status{margin-left:auto;font-size:12px;color:#666;}
                .qwoo-live-preview-close{margin-left:8px;text-decoration:none;font-size:16px;line-height:1;color:#666;}
                .qwoo-live-preview-panel__body{flex:1;position:relative;background:#e9eaec;overflow:auto;}
                .qwoo-live-preview-panel__body iframe{width:100%;height:100%;border:0;display:block;margin:0 auto;background:#fff;transition:width .2s ease;}
                body[data-qwoo-device="tablet"] .qwoo-live-preview-panel__body iframe{width:820px;max-width:100%;box-shadow:0 0 0 1px #c3c4c7;}
                body[data-qwoo-device="mobile"] .qwoo-live-preview-panel__body iframe{width:390px;max-width:100%;box-shadow:0 0 0 1px #c3c4c7;}
                .qwoo-live-preview-loading{position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;background:rgba(255,255,255,.92);font-size:14px;color:#1d2327;}
                .qwoo-live-preview-loading[hidden]{display:none;}
                .qwoo-live-preview-spinner{width:28px;height:28px;border-radius:50%;border:3px solid #dcdcde;border-top-color:#2271b1;animation:qwoo-live-preview-spin .8s linear infinite;}
                @keyframes qwoo-live-preview-spin{to{transform:rotate(360deg);}}

                /* Focused editing mode: while the panel is open, get the WP
                   admin menu and our own internal tab sidebar out of the
                   way entirely, and reflow the form so nothing sits under
                   the fixed panel — rather than just floating the panel
                   over content that does not otherwise move. */
                body.qwoo-live-preview-open #adminmenumain,
                body.qwoo-live-preview-open #adminmenuback,
                body.qwoo-live-preview-open #adminmenuwrap{display:none !important;}
                body.qwoo-live-preview-open #wpcontent{margin-left:0 !important;}
                /* Move the internal tab nav ABOVE the content as a compact,
                   wrapping row of chips, so the (narrow) editing column gets
                   its full width and tabs stay one click away while previewing. */
                body.qwoo-live-preview-open .shop-builder-wrapper{flex-direction:column;align-items:stretch;gap:12px;}
                body.qwoo-live-preview-open .shop-builder-nav{width:auto;min-width:0;position:static;flex-direction:row;flex-wrap:wrap;gap:4px;padding:6px;}
                body.qwoo-live-preview-open .shop-builder-nav .nav-tab{width:auto;padding:6px 10px;font-size:12px;border-left:0;border-bottom:2px solid transparent;border-radius:6px;}
                body.qwoo-live-preview-open .shop-builder-nav .nav-tab-active{border-bottom-color:var(--sb-accent);}
                body.qwoo-live-preview-open .shop-builder-nav .nav-tab .dashicons{margin-right:5px;}
                body.qwoo-live-preview-open .shop-builder-content{padding:20px;}
                body.qwoo-live-preview-open .shop-builder-wrapper,
                body.qwoo-live-preview-open .wrap{max-width:none !important;}
                body.qwoo-live-preview-open #wpbody-content{padding-right:70vw;box-sizing:border-box;}
        ' );

        // The JS is split into small per-concern files under
        // assets/admin/js/shop-builder/, all attaching to the same global
        // `ShopBuilder` object. Order matters: each handle declares the
        // previous one as a dependency so WordPress loads/executes them in
        // this exact sequence. `sb-core` must be first (it creates
        // `window.ShopBuilder` and calls `.init()` on document ready) and
        // is also where wp_localize_script attaches `shopBuilder`, so every
        // later file can rely on that data already being defined.
        $js_base = QWOO_URL . 'assets/admin/js/shop-builder/';
        $js_files = [
                'sb-core'             => [],
                'sb-touch-drag'       => [],
                'sb-color-fields'     => [ 'sb-core' ],
                'sb-media-uploads'    => [ 'sb-core' ],
                'sb-select2-search'   => [ 'sb-core' ],
                'sb-builder-fields'   => [ 'sb-core', 'sb-color-fields', 'sb-select2-search' ],
                'sb-builder'          => [ 'sb-core', 'sb-builder-fields' ],
                'sb-history'          => [ 'sb-core', 'sb-builder' ],
                'sb-contact-methods'  => [ 'sb-core' ],
                'sb-save-push'        => [ 'sb-core', 'sb-builder' ],
                'sb-live-preview'     => [ 'sb-core', 'sb-builder' ],
        ];

        $base_deps = [ 'jquery', 'select2', 'jquery-ui-sortable' ];

        foreach ( $js_files as $handle => $extra_deps ) {
            wp_enqueue_script(
                    'shop-builder-' . $handle,
                    $js_base . $handle . '.js',
                    array_merge( $base_deps, array_map( function ( $h ) { return 'shop-builder-' . $h; }, $extra_deps ) ),
                    QWOO_VERSION,
                    true
            );
        }

        $tech_settings   = get_option( 'qwoo_technical_settings', [] );
        $frontend_domain = ! empty( $tech_settings['frontend_domain'] )
                ? untrailingslashit( $tech_settings['frontend_domain'] )
                : '';

        // Printed before sb-core (the first-loaded handle) so `shopBuilder`
        // exists before any file in the chain runs. Not wp_localize_script():
        // that stringifies top-level numbers/booleans.
        $bootstrap = self::builder_bootstrap_data();
        $data = [
                'nonce'                => wp_create_nonce( 'shop_builder_nonce' ),
                'ajax_url'             => admin_url( 'admin-ajax.php' ),
                'blockSchema'          => self::BLOCK_SCHEMA,
                'sectionStyleFields'   => self::SECTION_STYLE_FIELDS,
                'blockCommonFields'    => self::BLOCK_COMMON_FIELDS,
                'devices'              => self::DEVICES,
                'maxNestingDepth'      => self::MAX_NESTING_DEPTH,
                'maxSections'          => self::MAX_SECTIONS,
                'maxBlocks'            => self::MAX_BLOCKS,
                'previewUrls'          => self::build_preview_urls( $frontend_domain ),
                'iconSets'             => self::ICON_SETS,
                // IANA name (e.g. 'Asia/Jerusalem') or a fixed offset ('+02:00').
                'siteTimezone'         => wp_timezone_string(),
                'pageSectionLocations' => self::PAGE_SECTION_LOCATIONS,
                'globalColors'         => self::get_global_colors(),
                'globalColorKeys'      => self::GLOBAL_COLOR_KEYS,
                'frontendDomain'       => $frontend_domain,
                'pages'                => $bootstrap['pages'],
                'media'                => $bootstrap['media'],
                'labels'               => $bootstrap['labels'],
                'templates'            => self::template_summaries(),
                'maxTemplates'         => self::MAX_TEMPLATES,
                'maxRevisions'         => self::MAX_REVISIONS,
        ];
        wp_add_inline_script(
                'shop-builder-sb-core',
                'window.shopBuilder = ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
                'before'
        );
    }

    /* -------------------------
       Admin Menu
       ------------------------- */
    public function add_admin_menu() {
        add_menu_page(
                'Q-Woo Settings',
                'Q-Woo Settings',
                'manage_options',
                'qwoo-settings',
                [ $this, 'settings_page_html' ],
                'dashicons-store',
                60
        );

        add_submenu_page(
                'qwoo-settings',
                'Shop Builder',
                'Shop Builder',
                'manage_options',
                'qwoo-settings',
                [ $this, 'settings_page_html' ]
        );

        add_submenu_page(
                'qwoo-settings',
                'Technical Settings',
                'Technical Settings',
                'manage_options',
                'qwoo-technical-settings',
                [ $this, 'render_technical_settings' ]
        );
    }

    public function render_technical_settings() {
        if ( class_exists( 'Qwoo_Technical_Settings' ) ) {
            ( new Qwoo_Technical_Settings() )->render_page();
        }
    }

    /* -------------------------
       Register Settings
       ------------------------- */
    public function register_shop_settings() {
        register_setting( 'shop_builder_group', 'shop_builder_options', [
                'sanitize_callback' => [ $this, 'sanitize_options' ]
        ] );
    }

    /* -------------------------
       Settings Page HTML
       ------------------------- */
    public function settings_page_html() {
        $options        = get_option( 'shop_builder_options', [] );
        $header         = $options['header'] ?? [];
        $footer         = $options['footer'] ?? [];
        $branding       = $options['branding'] ?? [];
        $tech_settings  = get_option( 'qwoo_technical_settings', [] );
        $frontend_domain = ! empty( $tech_settings['frontend_domain'] )
                ? untrailingslashit( $tech_settings['frontend_domain'] )
                : '';

        // Per-tab preview links: most pages preview against the storefront
        // root (they render global chrome — header/footer — or the homepage
        // itself), while Checkout and Shop Archive have their own routes.
        $preview_urls = self::build_preview_urls( $frontend_domain );

        $logo_id     = intval( $branding['logo_id'] ?? 0 );
        $logo_url    = $logo_id ? wp_get_attachment_url( $logo_id ) : '';
        $icon_id     = intval( $branding['app_icon_id'] ?? 0 );
        $icon_url    = $icon_id ? wp_get_attachment_url( $icon_id ) : '';
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-store"></span> Shop Builder</h1>

            <form action="options.php" method="post" class="shop-builder-form">
                <?php settings_fields( 'shop_builder_group' ); ?>

                <!-- ===================== TOP BAR ===================== -->
                <div class="shop-builder-topbar">
                    <div class="shop-builder-preview-link">
                        <span class="dashicons dashicons-admin-links"></span>
                        <code id="preview-url-display"><?php
                            echo esc_html( $preview_urls['tab-header'] ?: 'Set a Frontend Domain in Technical Settings first.' );
                            ?></code>
                        <a id="preview-url-link"
                           href="<?php echo esc_url( $preview_urls['tab-header'] ?: '#' ); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="button button-secondary<?php echo $preview_urls['tab-header'] ? '' : ' disabled'; ?>">
                            View live site &#8599;
                        </a>
                    </div>

                    <div id="qwoo-device-switch" class="qwoo-device-switch" role="group" aria-label="Editing device">
                        <button type="button" class="qwoo-device-switch-btn is-active" data-device="desktop" title="Desktop (1024px and up)"><span class="dashicons dashicons-desktop"></span><span class="qwoo-device-switch-label">Desktop</span></button>
                        <button type="button" class="qwoo-device-switch-btn" data-device="tablet" title="Tablet (768–1023px)"><span class="dashicons dashicons-tablet"></span><span class="qwoo-device-switch-label">Tablet</span></button>
                        <button type="button" class="qwoo-device-switch-btn" data-device="mobile" title="Mobile (767px and below)"><span class="dashicons dashicons-smartphone"></span><span class="qwoo-device-switch-label">Mobile</span></button>
                    </div>

                    <div class="qwoo-history-controls" role="group" aria-label="Section history">
                        <button type="button" id="qwoo-undo" class="button" title="Undo (Ctrl+Z)" aria-label="Undo" disabled><span class="dashicons dashicons-undo"></span></button>
                        <button type="button" id="qwoo-redo" class="button" title="Redo (Ctrl+Shift+Z)" aria-label="Redo" disabled><span class="dashicons dashicons-redo"></span></button>
                        <button type="button" id="qwoo-versions" class="button" title="Saved versions"><span class="dashicons dashicons-backup"></span><span class="qwoo-history-label">Versions</span></button>
                    </div>

                    <div class="shop-builder-actions">
                        <span id="qwoo-unsaved-indicator" class="qwoo-unsaved-indicator" hidden>Unsaved changes</span>
                        <?php submit_button( 'Save Draft', 'primary', 'submit', false ); ?>
                        <button type="button" id="push-to-github" class="button button-secondary">
                            Push to Live Website
                        </button>
                        <button type="button"
                                id="toggle-live-preview"
                                class="button button-secondary<?php echo $preview_urls['tab-header'] ? '' : ' disabled'; ?>">
                            <span class="dashicons dashicons-visibility"></span> Live Preview
                        </button>
                        <span id="sync-status"></span>
                    </div>
                </div>

                <!-- ===================== LIVE PREVIEW PANEL ===================== -->
                <!-- A slide-over iframe kept in sync with the in-progress (unsaved)
                     draft via postMessage — see sb-live-preview.js. It shows the
                     REAL frontend, not a re-implementation of it, so it can never
                     visually drift from what customers actually see. -->
                <div id="qwoo-live-preview-panel" class="qwoo-live-preview-panel" aria-hidden="true">
                    <div class="qwoo-live-preview-panel__header">
                        <strong><span class="dashicons dashicons-visibility"></span> Live Preview</strong>
                        <span id="qwoo-live-preview-status" class="qwoo-live-preview-status"></span>
                        <button type="button" id="close-live-preview" class="button-link qwoo-live-preview-close" aria-label="Close live preview">&#10005;</button>
                    </div>
                    <div class="qwoo-live-preview-panel__body">
                        <iframe id="qwoo-live-preview-iframe" src="about:blank" title="Live Preview"></iframe>
                        <div id="qwoo-live-preview-loading" class="qwoo-live-preview-loading" hidden>
                            <span class="qwoo-live-preview-spinner"></span>
                            <span id="qwoo-live-preview-loading-text">Loading…</span>
                        </div>
                    </div>
                </div>

                <div class="shop-builder-wrapper">

                    <nav class="shop-builder-nav">
                        <a class="nav-tab nav-tab-active" data-target="tab-header" title="Header"><span class="dashicons dashicons-align-left"></span><span class="nav-tab-label">Header</span></a>
                        <a class="nav-tab" data-target="tab-footer" title="Footer"><span class="dashicons dashicons-editor-insertmore"></span><span class="nav-tab-label">Footer</span></a>
                        <a class="nav-tab" data-target="tab-home" title="Homepage"><span class="dashicons dashicons-admin-home"></span><span class="nav-tab-label">Homepage</span></a>
                        <a class="nav-tab" data-target="tab-shop" title="Shop Archive"><span class="dashicons dashicons-grid-view"></span><span class="nav-tab-label">Shop Archive</span></a>
                        <a class="nav-tab" data-target="tab-category" title="Category Archive"><span class="dashicons dashicons-category"></span><span class="nav-tab-label">Category Archive</span></a>
                        <a class="nav-tab" data-target="tab-product" title="Product Page"><span class="dashicons dashicons-tag"></span><span class="nav-tab-label">Product Page</span></a>
                        <a class="nav-tab" data-target="tab-checkout" title="Checkout"><span class="dashicons dashicons-cart"></span><span class="nav-tab-label">Checkout</span></a>
                        <a class="nav-tab" data-target="tab-branding" title="Branding"><span class="dashicons dashicons-art"></span><span class="nav-tab-label">Branding</span></a>
                        <a class="nav-tab" data-target="tab-pwa" title="PWA Settings"><span class="dashicons dashicons-smartphone"></span><span class="nav-tab-label">PWA Settings</span></a>
                        <a class="nav-tab" data-target="tab-contact" title="Contact Button"><span class="dashicons dashicons-email-alt"></span><span class="nav-tab-label">Contact Button</span></a>
                    </nav>

                    <div class="shop-builder-content">

                        <!-- ===================== HEADER TAB ===================== -->
                        <div id="tab-header" class="tab-content active">
                            <h2>Header Settings</h2>

                            <div class="qwoo-section-block">
                                <h3>Announcement Bar</h3>

                                <label class="qwoo-switch-row">
                                    <input type="checkbox"
                                           class="qwoo-switch"
                                           name="shop_builder_options[header][announcement][enabled]"
                                           value="1"
                                            <?php checked( $header['announcement']['enabled'] ?? false, true ); ?> />
                                    <span class="qwoo-switch-label">Enable Announcement Bar</span>
                                </label>

                                <div class="field-group" style="margin-top:16px;">
                                    <span class="field-label">Announcement Text</span>
                                    <input type="text"
                                           name="shop_builder_options[header][announcement][text]"
                                           value="<?php echo esc_attr( $header['announcement']['text'] ?? '' ); ?>"
                                           class="large-text"
                                           placeholder="Enter announcement text..." />
                                </div>

                                <div class="qwoo-color-pair">
                                    <?php
                                    self::render_color_field( 'shop_builder_options[header][announcement][bg_color]', 'Background Color', $header['announcement']['bg_color'] ?? '', '#000000' );
                                    self::render_color_field( 'shop_builder_options[header][announcement][text_color]', 'Text Color', $header['announcement']['text_color'] ?? '', '#ffffff' );
                                    ?>
                                </div>
                            </div>

                            <hr>

                            <div class="qwoo-section-block">
                                <h3>General Settings</h3>

                                <label class="qwoo-switch-row">
                                    <input type="checkbox"
                                           class="qwoo-switch"
                                           name="shop_builder_options[header][settings][sticky]"
                                           value="1"
                                            <?php checked( $header['settings']['sticky'] ?? true, true ); ?> />
                                    <span class="qwoo-switch-label">Sticky Header</span>
                                </label>

                                <label class="qwoo-switch-row" style="margin-top:10px;">
                                    <input type="checkbox"
                                           class="qwoo-switch"
                                           name="shop_builder_options[header][settings][show_search]"
                                           value="1"
                                            <?php checked( $header['settings']['show_search'] ?? false, true ); ?> />
                                    <span class="qwoo-switch-label">Show Search Icon</span>
                                </label>
                            </div>
                        </div>

                        <!-- ===================== FOOTER TAB ===================== -->
                        <div id="tab-footer" class="tab-content">
                            <h2>Footer Settings</h2>

                            <div class="field-group">
                                <span class="field-label">Footer Text</span>
                                <textarea name="shop_builder_options[footer][footer_text]"
                                          class="large-text"
                                          rows="5"
                                          placeholder="<?php echo esc_attr( 'e.g. © 2026 My Shop. All rights reserved.' ); ?>"><?php echo esc_textarea( $footer['footer_text'] ?? '' ); ?></textarea>
                                <p class="description">Rendered dynamically in the storefront footer. Basic inline HTML is allowed, e.g. <code>Made with &lt;strong&gt;love&lt;/strong&gt;</code>.</p>
                            </div>
                        </div>

                        <!-- ===================== HOME TAB ===================== -->
                        <div id="tab-home" class="tab-content">
                            <h2>Homepage Settings</h2>

                            <div class="field-group">
                                <span class="field-label">Hero Title</span>
                                <input type="text"
                                       name="shop_builder_options[home][hero_title]"
                                       value="<?php echo esc_attr( $options['home']['hero_title'] ?? '' ); ?>"
                                       class="large-text" />
                                <p class="description">Basic inline HTML is allowed, e.g. <code>Your new &lt;span&gt;Home&lt;/span&gt;</code>.</p>
                            </div>

                            <div class="field-group">
                                <span class="field-label">Hero Description</span>
                                <textarea name="shop_builder_options[home][hero_description]" class="large-text" rows="3"><?php echo esc_textarea( $options['home']['hero_description'] ?? '' ); ?></textarea>
                            </div>

                            <div class="field-group">
                                <span class="field-label">Hero Button</span>
                                <span>Hero Button Text</span>
                                <input type="text"
                                       name="shop_builder_options[home][hero_btn][text]"
                                       value="<?php echo esc_attr( $options['home']['hero_btn']['text'] ?? '' ); ?>"
                                       class="large-text" />
                                <span>Hero Button Url</span>
                                <input type="text"
                                       name="shop_builder_options[home][hero_btn][url]"
                                       value="<?php echo esc_attr( $options['home']['hero_btn']['url'] ?? '' ); ?>"
                                       class="large-text" />
                            </div>

                            <div class="field-group">
                                <label><strong>Hero Image</strong></label>
                                <p>
                                    <?php
                                    $hero_image_id = $options['home']['hero_image_id'] ?? 0;
                                    $hero_image_url = $hero_image_id ? wp_get_attachment_url( $hero_image_id ) : '';
                                    ?>
                                    <img id="hero-image-preview"
                                         src="<?php echo esc_url( $hero_image_url ); ?>"
                                         style="max-width:200px; max-height:150px; display:<?php echo $hero_image_url ? 'block' : 'none'; ?>; margin-bottom:10px;" />
                                </p>
                                <input type="hidden"
                                       id="hero-image-id"
                                       name="shop_builder_options[home][hero_image_id]"
                                       value="<?php echo esc_attr( $hero_image_id ); ?>" />
                                <button type="button" id="hero-image-upload-btn" class="button button-secondary">
                                    <?php echo $hero_image_url ? 'Change Image' : 'Select Image'; ?>
                                </button>
                                <button type="button" id="hero-image-remove-btn" class="button" style="<?php echo $hero_image_url ? '' : 'display:none;'; ?>">
                                    Remove
                                </button>
                            </div>

                            <hr class="qwoo-hr">

                            <div class="field-group">
                                <span class="field-label">Homepage Sections &amp; Blocks</span>
                                <p class="description">These render below the hero on the homepage, in this order. Drag to reorder.</p>
                                <?php $this->render_builder_mount( 'home' ); ?>
                            </div>
                        </div>

                        <!-- ===================== SHOP ARCHIVE TAB ===================== -->
                        <div id="tab-shop" class="tab-content">
                            <?php $this->render_hooks_tab_content(
                                    'shop',
                                    'Shop Archive Sections',
                                    'Add sections to any of the slots below to inject them into the products archive page. Drag a section between slots to move it there; drag within a slot to reorder.'
                            ); ?>
                        </div>

                        <!-- ===================== CATEGORY ARCHIVE TAB ===================== -->
                        <div id="tab-category" class="tab-content">
                            <?php $this->render_hooks_tab_content(
                                    'category',
                                    'Category Archive Sections',
                                    'Same hook slots as the Shop Archive — this powers individual product category pages (e.g. <code>/product-category/hoodies</code>), which share the exact same archive layout on the frontend.',
                                    '/product-category/{slug}',
                                    'Category slug to preview, e.g. hoodies'
                            ); ?>
                        </div>

                        <!-- ===================== PRODUCT PAGE TAB ===================== -->
                        <div id="tab-product" class="tab-content">
                            <?php $this->render_hooks_tab_content(
                                    'product',
                                    'Product Page Sections',
                                    'Hook slots modeled on WooCommerce\'s own single-product action hooks, adapted to this theme\'s actual layout (image gallery + summary column, related products slider, no tabs/sidebar).',
                                    '/product/{slug}',
                                    'Product slug to preview, e.g. classic-hoodie'
                            ); ?>
                        </div>

                        <!-- ===================== CHECKOUT TAB ===================== -->
                        <div id="tab-checkout" class="tab-content">
                            <h2>Checkout Settings</h2>

                            <div class="field-group">
                                <span class="field-label">Checkout Notice</span>
                                <textarea name="shop_builder_options[checkout][checkout_notice]"
                                          class="large-text"
                                          rows="5"><?php echo esc_textarea( $options['checkout']['checkout_notice'] ?? '' ); ?></textarea>
                            </div>
                        </div>

                        <!-- ===================== BRANDING TAB ===================== -->
                        <div id="tab-branding" class="tab-content">
                            <h2>Branding</h2>

                            <div class="field-group">
                                <label><strong>Global Colors</strong></label>
                                <p class="description">
                                    Your shared palette (<code>--q-primary</code>, <code>--q-secondary</code>, <code>--q-accent</code>,
                                    <code>--q-text</code>). Any color field in Shop Builder can be linked to one of these instead of a
                                    one-off hex value — changing it here updates every linked section instantly, with nothing to re-push.
                                </p>
                                <div class="qwoo-color-pair">
                                    <?php
                                    $global_colors = self::get_global_colors();
                                    foreach ( self::GLOBAL_COLOR_KEYS as $key => $meta ) {
                                        self::render_color_field(
                                                "shop_builder_options[branding][global_colors][{$key}]",
                                                $meta['label'] . ' (' . $meta['var'] . ')',
                                                $global_colors[ $key ],
                                                $global_colors[ $key ],
                                                false // no chips inside the definitions themselves
                                        );
                                    }
                                    ?>
                                </div>
                            </div>

                            <hr class="qwoo-hr">

                            <div class="field-group">
                                <label><strong>Header Logo</strong></label>
                                <p class="description">Any aspect ratio — this is used in the site header only.</p>
                                <p>
                                    <img id="logo-preview" src="<?php echo esc_url( $logo_url ); ?>"
                                         style="max-width:240px; max-height:100px; display:<?php echo $logo_url ? 'block' : 'none'; ?>; margin-bottom:10px;" />
                                </p>
                                <input type="hidden" id="logo-id" name="shop_builder_options[branding][logo_id]" value="<?php echo esc_attr( $logo_id ); ?>" />
                                <button type="button" id="logo-upload-btn" class="button button-secondary"><?php echo $logo_url ? 'Change Logo' : 'Select Logo'; ?></button>
                                <button type="button" id="logo-remove-btn" class="button" style="<?php echo $logo_url ? '' : 'display:none;'; ?>">Remove</button>
                            </div>

                            <hr class="qwoo-hr">

                            <div class="field-group">
                                <label><strong>App Icon</strong></label>
                                <p class="description">
                                    Square image, <strong>at least 512×512px</strong>. This generates the favicon, iOS home-screen icon,
                                    and the full Android/PWA icon set (including the maskable variant) used when a customer installs
                                    the app to their home screen. Keep the important part of the logo away from the very edges —
                                    Android may crop up to ~20% off each side on some devices.
                                </p>
                                <p>
                                    <img id="app-icon-preview" src="<?php echo esc_url( $icon_url ); ?>"
                                         style="max-width:150px; max-height:150px; display:<?php echo $icon_url ? 'block' : 'none'; ?>; margin-bottom:10px; border:1px solid #ddd;" />
                                </p>
                                <input type="hidden" id="app-icon-id" name="shop_builder_options[branding][app_icon_id]" value="<?php echo esc_attr( $icon_id ); ?>" />
                                <button type="button" id="app-icon-upload-btn" class="button button-secondary"><?php echo $icon_url ? 'Change App Icon' : 'Select App Icon'; ?></button>
                                <button type="button" id="app-icon-remove-btn" class="button" style="<?php echo $icon_url ? '' : 'display:none;'; ?>">Remove</button>
                                <br><br>
                                <button type="button" id="generate-icons-btn" class="button button-secondary">Generate &amp; Push Icon Set to GitHub</button>
                                <span id="icon-gen-status" style="margin-left:10px; font-weight:500;"></span>
                                <p class="description">Save your draft first if you just changed the App Icon, then click this to (re)generate all sizes and push them.</p>
                            </div>
                        </div>

                        <!-- ===================== PWA SETTINGS TAB ===================== -->
                        <?php
                        $pwa = $options['pwa'] ?? [];
                        ?>
                        <div id="tab-pwa" class="tab-content">
                            <h2>PWA Settings</h2>
                            <p class="description">
                                Controls the values written to <code>manifest.json</code> for the installed
                                app (Add to Home Screen). The App Icon itself is managed on the
                                <strong>Branding</strong> tab.
                            </p>

                            <div class="field-group">
                                <label><strong>App Name</strong></label>
                                <p class="description">Shown under the icon on the home screen and in the app switcher.</p>
                                <input type="text"
                                       name="shop_builder_options[pwa][name]"
                                       value="<?php echo esc_attr( $pwa['name'] ?? '' ); ?>"
                                       class="large-text"
                                       maxlength="45"
                                       placeholder="My Shop" />
                            </div>
                            <div class="field-group">
                                <label><strong>App Short Name</strong></label>
                                <p class="description">Shown under the icon on the home screen and in the app switcher.</p>
                                <input type="text"
                                       name="shop_builder_options[pwa][short_name]"
                                       value="<?php echo esc_attr( $pwa['short_name'] ?? '' ); ?>"
                                       class="large-text"
                                       maxlength="45"
                                       placeholder="My Shop" />
                            </div>

                            <div class="field-group">
                                <label><strong>Description</strong></label>
                                <p class="description">A short description of the app, used by app stores/install prompts.</p>
                                <textarea name="shop_builder_options[pwa][description]"
                                          class="large-text"
                                          rows="3"
                                          placeholder="Shop the latest products, right from your home screen."><?php echo esc_textarea( $pwa['description'] ?? '' ); ?></textarea>
                            </div>

                            <hr class="qwoo-hr">

                            <div class="qwoo-color-pair">
                                <div class="qwoo-color-field-wrap">
                                    <?php self::render_color_field( 'shop_builder_options[pwa][theme_color]', 'Theme Color', $pwa['theme_color'] ?? '', '#000000', true, false ); ?>
                                    <p class="description">Colors the browser UI (address bar / status bar) while the app is open.</p>
                                </div>
                                <div class="qwoo-color-field-wrap">
                                    <?php self::render_color_field( 'shop_builder_options[pwa][background_color]', 'Background Color', $pwa['background_color'] ?? '', '#ffffff', true, false ); ?>
                                    <p class="description">Shown as a placeholder background on the splash screen while the app loads.</p>
                                </div>
                            </div>
                        </div>

                        <!-- ===================== CONTACT BUTTON TAB ===================== -->
                        <?php
                        $contact         = $options['contact'] ?? [];
                        $contact_methods = $contact['methods'] ?? [];
                        if ( empty( $contact_methods ) ) {
                            $contact_methods = [ [] ]; // start with one blank row
                        }
                        ?>
                        <div id="tab-contact" class="tab-content">
                            <h2>Contact Button</h2>
                            <p class="description">
                                Adds a floating contact button to your storefront. It only appears once this is
                                enabled <strong>and</strong> at least one method below is individually enabled with
                                a value filled in — leaving this on with no valid methods just keeps the button hidden.
                            </p>

                            <label class="qwoo-switch-row">
                                <input type="checkbox"
                                       id="contact_enabled"
                                       class="qwoo-switch"
                                       name="shop_builder_options[contact][enabled]"
                                       value="1"
                                        <?php checked( ! empty( $contact['enabled'] ) ); ?> />
                                <span class="qwoo-switch-label">Enable contact button</span>
                            </label>

                            <hr class="qwoo-hr">

                            <div id="qwoo-contact-methods">
                                <?php foreach ( $contact_methods as $i => $method ) :
                                    $c_type    = $method['type'] ?? 'whatsapp';
                                    $c_value   = $method['value'] ?? '';
                                    $c_label   = $method['label'] ?? '';
                                    $c_icon    = $method['icon'] ?? '';
                                    $c_enabled = ! empty( $method['enabled'] );
                                    ?>
                                    <div class="qwoo-contact-method-row">
                                        <div class="qwoo-contact-method-row__top">
                                            <select class="qwoo-contact-type" name="shop_builder_options[contact][methods][<?php echo esc_attr( $i ); ?>][type]">
                                                <option value="whatsapp" <?php selected( $c_type, 'whatsapp' ); ?>>WhatsApp</option>
                                                <option value="phone" <?php selected( $c_type, 'phone' ); ?>>Phone call</option>
                                                <option value="email" <?php selected( $c_type, 'email' ); ?>>Email</option>
                                                <option value="telegram" <?php selected( $c_type, 'telegram' ); ?>>Telegram</option>
                                                <option value="custom" <?php selected( $c_type, 'custom' ); ?>>Custom</option>
                                            </select>

                                            <label class="qwoo-contact-enabled-label">
                                                <input type="checkbox" class="qwoo-switch qwoo-switch--sm" name="shop_builder_options[contact][methods][<?php echo esc_attr( $i ); ?>][enabled]" value="1" <?php checked( $c_enabled ); ?> />
                                                Enabled
                                            </label>

                                            <button type="button" class="button button-link-delete qwoo-remove-contact-method">Remove</button>
                                        </div>

                                        <input
                                                type="text"
                                                class="large-text qwoo-contact-value"
                                                name="shop_builder_options[contact][methods][<?php echo esc_attr( $i ); ?>][value]"
                                                value="<?php echo esc_attr( $c_value ); ?>"
                                                placeholder="<?php echo esc_attr( Shop_Settings_Builder::contact_placeholder( $c_type ) ); ?>"
                                        />

                                        <div class="qwoo-contact-custom-fields <?php echo $c_type === 'custom' ? '' : 'qwoo-hidden'; ?>">
                                            <input
                                                    type="text"
                                                    class="large-text"
                                                    name="shop_builder_options[contact][methods][<?php echo esc_attr( $i ); ?>][label]"
                                                    value="<?php echo esc_attr( $c_label ); ?>"
                                                    placeholder="Label (e.g. Live Chat)"
                                            />
                                            <input
                                                    type="text"
                                                    class="large-text"
                                                    name="shop_builder_options[contact][methods][<?php echo esc_attr( $i ); ?>][icon]"
                                                    value="<?php echo esc_attr( $c_icon ); ?>"
                                                    placeholder="Icon image URL"
                                            />
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="button" id="qwoo-add-contact-method" class="button button-secondary">+ Add Method</button>
                            <p class="description">
                                WhatsApp: number only, digits with country code, no <code>+</code> or spaces.
                                Phone: any dialable number. Telegram: your <code>@username</code>.
                                Custom: a full URL plus your own label and icon image.
                            </p>
                        </div>

                    </div><!-- /.shop-builder-content -->
                </div><!-- /.shop-builder-wrapper -->
            </form>
        </div>
        <?php
    }
}