<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shop Builder — split into this folder to keep any single file readable.
 * Everything still behaves as ONE class (Shop_Settings_Builder): constants
 * live on the Shop_Builder_Constants base class it extends, and every method
 * lives in a trait grouped by concern, `use`'d below.
 *
 * File map (all in this folder):
 *   class-sb-constants.php        Field schema (BLOCK_SCHEMA, SECTION_STYLE_FIELDS, ...) + shared constants
 *   trait-sb-color-helpers.php    Global color palette + render_color_field()
 *   trait-sb-contact.php          Contact Button (hrefs, sanitizing, REST payload)
 *   trait-sb-sanitizers.php       Schema-driven sanitizing + public JSON output
 *   trait-sb-migration.php        Schema migrations (v1 -> v2 -> v3), run once on admin_init
 *   trait-sb-forms.php            Form block submissions (REST endpoint + Form Entries post type)
 *   trait-sb-ajax.php             wp_ajax_* callbacks (search, icons, save draft)
 *   trait-sb-rest.php             Frontend preview-URL builder
 *   trait-sb-github-push.php      "Push to Live Website" GitHub batch commit
 *   trait-sb-history.php          Saved versions (restore) + section templates
 *   trait-sb-template-import.php  Starter-template import from published files (platform stores)
 *   trait-sb-platform.php         The builder for the platform's owner dashboard (Design section)
 *   trait-sb-admin-fields.php     Builder mount points + data the JS builder needs
 *   trait-sb-admin-page.php       Menu, enqueue, and settings_page_html()
 */

require_once __DIR__ . '/class-sb-constants.php';
require_once __DIR__ . '/trait-sb-color-helpers.php';
require_once __DIR__ . '/trait-sb-contact.php';
require_once __DIR__ . '/trait-sb-sanitizers.php';
require_once __DIR__ . '/trait-sb-migration.php';
require_once __DIR__ . '/trait-sb-forms.php';
require_once __DIR__ . '/trait-sb-ajax.php';
require_once __DIR__ . '/trait-sb-rest.php';
require_once __DIR__ . '/trait-sb-github-push.php';
require_once __DIR__ . '/trait-sb-history.php';
require_once __DIR__ . '/trait-sb-template-import.php';
require_once __DIR__ . '/trait-sb-platform.php';
require_once __DIR__ . '/trait-sb-admin-fields.php';
require_once __DIR__ . '/trait-sb-admin-page.php';

class Shop_Settings_Builder extends Shop_Builder_Constants {

    use SB_Color_Helpers;
    use SB_Contact;
    use SB_Sanitizers;
    use SB_Migration;
    use SB_Forms;
    use SB_Ajax;
    use SB_Rest;
    use SB_Github_Push;
    use SB_History;
    use SB_Template_Import;
    use SB_Platform;
    use SB_Admin_Fields;
    use SB_Admin_Page;

    /** @var self|null The instance created below (hooks are registered once). */
    private static $instance = null;

    public static function instance() {
        return self::$instance ?? new self();
    }

    public function __construct() {
        self::$instance = $this;
        add_action( 'admin_menu',            [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        // Priority 5: must run BEFORE register_shop_settings() (default
        // priority 10, also on admin_init) so any settings page render or
        // save this request already sees current-schema data.
        add_action( 'admin_init',            [ $this, 'maybe_migrate_sections' ], 5 );
        add_action( 'admin_init',            [ $this, 'register_shop_settings' ] );
        add_action( 'init',                  [ $this, 'register_form_entry_post_type' ] );
        add_action( 'rest_api_init',         [ $this, 'register_form_submit_endpoint' ] );
        add_action( 'wp_ajax_save_shop_builder_draft',      [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_push_to_github',               [ $this, 'handle_github_push' ] );
        add_action( 'wp_ajax_shop_builder_product_search',  [ $this, 'add_featured_products_search_ajax' ] );
        add_action( 'wp_ajax_shop_builder_category_search', [ $this, 'ajax_category_search' ] );
        add_action( 'wp_ajax_shop_builder_tag_search',      [ $this, 'ajax_tag_search' ] );
        add_action( 'wp_ajax_shop_builder_generate_icons',  [ $this, 'ajax_generate_and_sync_icons' ] );
        add_action( 'wp_ajax_shop_builder_list_revisions',  [ $this, 'ajax_list_revisions' ] );
        add_action( 'wp_ajax_shop_builder_get_revision',    [ $this, 'ajax_get_revision' ] );
        add_action( 'wp_ajax_shop_builder_save_template',   [ $this, 'ajax_save_template' ] );
        add_action( 'wp_ajax_shop_builder_get_template',    [ $this, 'ajax_get_template' ] );
        add_action( 'wp_ajax_shop_builder_delete_template', [ $this, 'ajax_delete_template' ] );
        add_filter( 'manage_' . self::FORM_ENTRY_POST_TYPE . '_posts_columns',       [ $this, 'form_entry_columns' ] );
        add_action( 'manage_' . self::FORM_ENTRY_POST_TYPE . '_posts_custom_column', [ $this, 'form_entry_column_content' ], 10, 2 );
    }
}

new Shop_Settings_Builder();
