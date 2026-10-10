<?php

add_action('rest_api_init', function () {

    register_rest_route('qwoo/v1', '/export-products', [
        'methods'  => 'GET',
        'callback' => 'qwoo_export_products_json',
        'permission_callback' => '__return_true'
    ]);

});
function qwoo_export_products_json(WP_REST_Request $request)
{
    // Make sure no caching interferes
    nocache_headers();

    // If your function already returns JSON string:
    $json = aps_generate_products_json();

    // Safety: ensure valid JSON response
    $decoded = json_decode($json, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        return rest_ensure_response($decoded);
    }

    // fallback: return raw string if generator already outputs JSON text
    return new WP_REST_Response($json, 200, [
        'Content-Type' => 'application/json'
    ]);
}

/*
 * The storefront's products backup (data/products.json, categories.json,
 * price-meta.json: only used when the store's API can't be reached) is
 * refreshed at most once a day, started by the storefront's own traffic
 * (no cron) and run after the answer is sent. It used to be published on
 * every product save (a stock change after an order too), and each of those
 * rebuilt the storefront.
 */
add_action( 'rest_api_init', 'aps_maybe_refresh_data_files' );

function aps_maybe_refresh_data_files() {
    // The hourly job of older versions.
    if ( wp_next_scheduled( 'auto_sync_products_to_github' ) ) {
        wp_clear_scheduled_hook( 'auto_sync_products_to_github' );
    }
    if ( get_transient( 'qwoo_data_refreshed' ) || ! aps_publishing_configured() ) {
        return;
    }
    // Never on the storefront's "which version is live?" check: it's waited for
    // (where the server can't answer before the work is done).
    if ( strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/qwoo/v1/site' ) !== false ) {
        return;
    }
    set_transient( 'qwoo_data_refreshed', time(), DAY_IN_SECONDS );
    add_action( 'shutdown', static function () {
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        } elseif ( function_exists( 'litespeed_finish_request' ) ) {
            litespeed_finish_request();
        }
        ignore_user_abort( true );
        $result = aps_sync_all_data_to_github();
        if ( empty( $result['ok'] ) ) {
            // Try again in an hour rather than tomorrow.
            set_transient( 'qwoo_data_refreshed', time(), HOUR_IN_SECONDS );
        }
    } );
}

/** Whether this site publishes anywhere (on the store, or a platform / Technical Settings repo). */
function aps_publishing_configured() {
    return ( class_exists( 'Qwoo_Site_Content' ) && Qwoo_Site_Content::enabled() )
        || Qwoo_Platform_Connection::get()
        || Qwoo_Technical_Settings::get_key( 'GITHUB_TOKEN' );
}

/**
 * Normalize a JSON string to a canonical pretty-printed form, so two
 * semantically-identical payloads (different key order, whitespace, etc.)
 * hash and compare as equal.
 */
function aps_normalize_json( $json ) {
    $decoded = json_decode( $json, true );
    return $decoded ? json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : $json;
}

/**
 * ────────────────────────────────────────────────────────────────
 * Batched GitHub commits
 * ────────────────────────────────────────────────────────────────
 * Lets several files (JSON configs, images, etc.) be written to the repo
 * in ONE commit instead of one commit per file. Usage:
 *
 *   $batch = aps_github_start_batch();
 *   if ( ! $batch ) { ... not configured / repo unreachable ... }
 *
 *   aps_github_batch_put_file( $batch, 'public/data/products.json', $json1 );
 *   aps_github_batch_put_file( $batch, 'public/data/categories.json', $json2 );
 *
 *   $result = aps_github_finish_batch( $batch, 'Update products + categories' );
 *   // $result: true (committed), 'no_changes' (nothing differed), or false (API error)
 *
 * aps_github_start_batch() does ONE read of the branch ref/commit/tree.
 * aps_github_batch_put_file() only creates a blob (and stages a tree entry)
 * when the content actually differs from what's already in the repo —
 * unchanged files are skipped and never touch the commit. Nothing is
 * written to GitHub until aps_github_finish_batch() creates the tree,
 * commit, and moves the branch ref — all in a single round trip each.
 */
function aps_github_start_batch() {
    // Stores whose storefront reads published files from the store (see Qwoo_Site_Content).
    if ( class_exists( 'Qwoo_Site_Content' ) && Qwoo_Site_Content::enabled() ) {
        return Qwoo_Site_Content::start_batch();
    }

    // Platform stores get a short-lived token for their own content repo;
    // other sites use the GitHub settings from Technical Settings.
    $gh = Qwoo_Platform_Connection::github_settings();
    if ( ! $gh ) {
        return false;
    }
    $owner  = $gh['owner'];
    $repo   = $gh['repo'];
    $token  = $gh['token'];
    $branch = $gh['branch'];

    $api = function( $method, $endpoint, $body = null ) use ( $owner, $repo, $token ) {
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => "token {$token}",
                'User-Agent'    => 'WordPress-GitHub-Client',
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/vnd.github+json',
            ],
            'timeout' => 30,
        ];
        if ( $body !== null ) {
            $args['body'] = json_encode( $body );
        }

        $url      = "https://api.github.com/repos/{$owner}/{$repo}{$endpoint}";
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            error_log( 'APS Batch Sync Error: ' . $response->get_error_message() );
            return false;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            error_log( "APS Batch Sync Failed. Status: {$code}. Endpoint: {$endpoint}." );
            return false;
        }

        return $decoded;
    };

    $ref = $api( 'GET', "/git/ref/heads/{$branch}" );
    if ( ! $ref ) return false;
    $base_commit_sha = $ref['object']['sha'];

    $commit = $api( 'GET', "/git/commits/{$base_commit_sha}" );
    if ( ! $commit ) return false;
    $base_tree_sha = $commit['tree']['sha'];

    $tree = $api( 'GET', "/git/trees/{$base_tree_sha}?recursive=1" );
    if ( ! $tree || empty( $tree['tree'] ) ) return false;

    $existing = [];
    foreach ( $tree['tree'] as $entry ) {
        if ( $entry['type'] === 'blob' ) {
            $existing[ $entry['path'] ] = $entry['sha'];
        }
    }

    return [
        'api'             => $api,
        'branch'          => $branch,
        'base_commit_sha' => $base_commit_sha,
        'base_tree_sha'   => $base_tree_sha,
        'existing'        => $existing,
        'tree_updates'    => [],
        'updated'         => [],
        'skipped'         => [],
        'deleted'         => [],
        'failed'          => [],
    ];
}

/**
 * Stage a file write in an in-progress batch. Skips (no-op, no API call)
 * when $content is byte-identical to what's already in the repo at $path.
 *
 * @param array  &$batch   Batch state from aps_github_start_batch()
 * @param string $path     Repo-relative path, e.g. 'public/data/products.json'
 * @param string $content  Raw file bytes (already normalized if it's JSON)
 * @return bool  true if staged or skipped as unchanged, false on API error
 */
function aps_github_batch_put_file( &$batch, $path, $content ) {
    if ( ( $batch['store'] ?? '' ) === 'local' ) {
        return Qwoo_Site_Content::batch_put( $batch, $path, $content );
    }
    $local_sha = sha1( "blob " . strlen( $content ) . "\0" . $content );

    if ( isset( $batch['existing'][ $path ] ) && $batch['existing'][ $path ] === $local_sha ) {
        $batch['skipped'][] = $path;
        return true;
    }

    $blob = ( $batch['api'] )( 'POST', '/git/blobs', [
        'content'  => base64_encode( $content ),
        'encoding' => 'base64',
    ] );

    if ( ! $blob ) {
        $batch['failed'][] = $path;
        return false;
    }

    $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['sha'] ];
    $batch['updated'][]      = $path;
    return true;
}

/**
 * Stage removal of every existing blob whose path starts with $prefix,
 * except $keep_path. Pass a folder (e.g. 'public/homepage-hero/') to clean
 * up an entire folder, or a specific target path (e.g. the exact file about
 * to be written) to scope cleanup narrowly — the latter matters when a
 * folder is shared between two independent fields (like branding's logo
 * and app icon) so cleaning up one never deletes the other.
 */
function aps_github_batch_delete_stale_prefix( &$batch, $prefix, $keep_path ) {
    foreach ( $batch['existing'] as $path => $sha ) {
        if ( strpos( $path, $prefix ) !== 0 ) continue;
        if ( $path === $keep_path ) continue;

        $batch['tree_updates'][] = [ 'path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => null ];
        $batch['deleted'][] = $path;
    }
}

/**
 * Commit everything staged in the batch in ONE commit + ONE ref update.
 * Returns true (committed), 'no_changes' (nothing was staged), or false
 * (a GitHub API call failed).
 */
function aps_github_finish_batch( $batch, $message ) {
    if ( empty( $batch['tree_updates'] ) ) {
        return 'no_changes';
    }
    if ( ( $batch['store'] ?? '' ) === 'local' ) {
        return Qwoo_Site_Content::finish_batch( $batch, $message );
    }

    $api = $batch['api'];

    $new_tree = $api( 'POST', '/git/trees', [
        'base_tree' => $batch['base_tree_sha'],
        'tree'      => $batch['tree_updates'],
    ] );
    if ( ! $new_tree ) return false;

    $new_commit = $api( 'POST', '/git/commits', [
        'message' => $message,
        'tree'    => $new_tree['sha'],
        'parents' => [ $batch['base_commit_sha'] ],
    ] );
    if ( ! $new_commit ) return false;

    $updated_ref = $api( 'PATCH', "/git/refs/heads/{$batch['branch']}", [
        'sha'   => $new_commit['sha'],
        'force' => false,
    ] );
    if ( ! $updated_ref ) return false;
    return true;
}

/**
 * Regenerates products.json, categories.json, and price-meta.json and
 * pushes ALL of them to GitHub in a SINGLE commit — only files that
 * actually changed are included, and if none changed, nothing is
 * committed at all. Used by the "Sync Data Now" button.
 *
 * @return array {
 *     @type bool     $ok          Whether the operation completed without error.
 *     @type string[] $updated     Labels ('products'/'categories'/'price-meta') that were committed.
 *     @type string[] $skipped     Labels that were identical to GitHub already.
 *     @type string[] $failed      Labels that failed (local generation or API error).
 *     @type bool     $no_changes  True if nothing needed to be committed.
 *     @type string   $error       Set when $ok is false.
 * }
 */
function aps_sync_all_data_to_github() {
    $generators = [
        'products'   => [ 'aps_generate_products_json',   'public/data/products.json' ],
        'categories' => [ 'aps_generate_categories_json', 'public/data/categories.json' ],
        'price-meta' => [ 'aps_generate_price_meta_json', 'public/data/price-meta.json' ],
    ];

    $to_write = [];
    $generation_failed = [];

    foreach ( $generators as $label => $def ) {
        [ $fn, $path ] = $def;
        $json = call_user_func( $fn );

        if ( ! $json ) {
            $generation_failed[] = $label;
            continue;
        }

        $to_write[ $label ] = [ 'path' => $path, 'content' => aps_normalize_json( $json ) ];
    }

    if ( empty( $to_write ) ) {
        return [ 'ok' => false, 'error' => 'generation_failed', 'updated' => [], 'skipped' => [], 'failed' => $generation_failed ];
    }

    $batch = aps_github_start_batch();
    if ( ! $batch ) {
        return [ 'ok' => false, 'error' => 'not_configured_or_unreachable', 'updated' => [], 'skipped' => [], 'failed' => $generation_failed ];
    }

    $path_to_label = [];
    foreach ( $to_write as $label => $file ) {
        $path_to_label[ $file['path'] ] = $label;
        aps_github_batch_put_file( $batch, $file['path'], $file['content'] );
    }

    if ( ! empty( $batch['failed'] ) ) {
        return [ 'ok' => false, 'error' => 'blob_upload_failed', 'updated' => [], 'skipped' => [], 'failed' => array_merge( $generation_failed, $batch['failed'] ) ];
    }

    $result = aps_github_finish_batch( $batch, 'Update products, categories, and price meta from WP' );

    if ( $result === false ) {
        return [ 'ok' => false, 'error' => 'commit_failed', 'updated' => [], 'skipped' => [], 'failed' => $generation_failed ];
    }

    $to_labels = function( $paths ) use ( $path_to_label ) {
        return array_map( function( $p ) use ( $path_to_label ) { return $path_to_label[ $p ] ?? $p; }, $paths );
    };

    return [
        'ok'         => true,
        'updated'    => $to_labels( $batch['updated'] ),
        'skipped'    => $to_labels( $batch['skipped'] ),
        'failed'     => $generation_failed,
        'no_changes' => ( $result === 'no_changes' ),
    ];
}

function aps_sync_products_to_github() {

    $json = aps_generate_products_json();

    if (!$json) {
        return 'json_failed';
    }

    // Note: no local hash short-circuit here. aps_commit_to_github() already
    // fetches the current file from GitHub and compares normalized JSON
    // against it, returning 'no_changes' when the remote is truly identical.
    // A local WP-option hash check here would be repo-blind and can drift
    // from reality (e.g. after switching GITHUB_REPO_OWNER/NAME, restoring
    // a DB backup, or someone editing the file directly on GitHub), causing
    // false "up to date" results. Let the remote comparison be the only
    // source of truth.
    return aps_commit_to_github($json, 'public/data/products.json', 'Update products from WP');
}
function aps_sync_categories_to_github() {

    $json = aps_generate_categories_json();

    if (!$json) {
        return 'json failed';
    }

    // See note in aps_sync_products_to_github(): rely on aps_commit_to_github()'s
    // remote-content comparison instead of a local, repo-blind hash cache.
    return aps_commit_to_github(
        $json,
        'public/data/categories.json',
        'Update categories from WP'
    );
}

function aps_generate_price_meta_json() {

    $url = site_url('/wp-json/qwoo/v1/products-meta?category=all');

    $response = wp_remote_get($url, [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'WP-StoreAPI-Sync'
        ]
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);

    if (!$body) {
        return false;
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        return false;
    }

    return json_encode(
        $decoded,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

function aps_sync_price_meta_to_github() {

    $json = aps_generate_price_meta_json();

    if (!$json) {
        return 'json failed';
    }

    // See note in aps_sync_products_to_github(): rely on aps_commit_to_github()'s
    // remote-content comparison instead of a local, repo-blind hash cache.
    return aps_commit_to_github(
        $json,
        'public/data/price-meta.json',
        'Update price meta from WP'
    );
}

function aps_fetch_store_api($endpoint, $params = []) {

    $page = 1;

    $all_items = [];

    do {

        $query_args = array_merge([
            'page' => $page,
            'per_page' => 100,
            'include_sort_meta' => "false"
        ], $params);

        $url = add_query_arg(
            $query_args,
            site_url("/wp-json/wc/store/{$endpoint}")
        );

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'WP-StoreAPI-Sync'
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);

        if (!$body) {
            return false;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return false;
        }

        $all_items = array_merge($all_items, $decoded);

        $total_pages = max(
            1,
            (int)wp_remote_retrieve_header(
                $response,
                'x-wp-totalpages'
            )
        );

        $page++;

    } while ($page <= $total_pages);

    return json_encode(
        $all_items,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
}

function aps_generate_products_json() {
    return aps_fetch_store_api('products', ['per_page' => 6, 'include_sort_meta' => 'true']);
}
function aps_generate_categories_json() {
    return aps_fetch_store_api('products/categories', ['per_page' => 6]);
}

/**
 * Commit JSON to GitHub via REST API.
 */
function aps_commit_to_github($content, $path = null, $message = 'Auto-sync from WordPress') {

    // Published on the store itself: one file is a batch of one.
    if ( $path !== null && class_exists( 'Qwoo_Site_Content' ) && Qwoo_Site_Content::enabled() ) {
        $batch = Qwoo_Site_Content::start_batch();
        if ( ! $batch ) return false;
        if ( ! Qwoo_Site_Content::batch_put( $batch, $path, aps_normalize_json( $content ) ) ) return false;
        return aps_github_finish_batch( $batch, $message );
    }

    $gh = Qwoo_Platform_Connection::github_settings();
    if ( ! $gh ) {
        return 'not_configured';
    }
    $owner = $gh['owner'];
    $repo  = $gh['repo'];
    $token = $gh['token'];


    if ($path === null) {
        if (!defined('GITHUB_FILE_PATH')) {
            return 'missing_path';
        }

        $path = GITHUB_FILE_PATH;
    }

    $api_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}";

    // 1. Get current file (if exists)
    $existing = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => "token $token",
            'User-Agent'    => 'WordPress-GitHub-Client'
        ]
    ]);

    $sha = null;
    $existing_content = null;

    if (is_wp_error($existing)) {
        error_log('APS Sync Error (GET): ' . $existing->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($existing);

    if ($response_code === 200) {
        error_log("APS Sync success. Status: $response_code. Path: $path.");

        $existing_body = json_decode(wp_remote_retrieve_body($existing), true);

        $sha = $existing_body['sha'] ?? null;

        if (!empty($existing_body['content'])) {
            $existing_content = base64_decode($existing_body['content']);
        }
    } elseif ($response_code !== 404) {
        error_log("APS Sync GET Failed. Status: $response_code. Path: $path.");
        return false;
    }

    // 2. 🔥 Normalize JSON to prevent false diffs
    $normalize_json = function($json) {
        $decoded = json_decode($json, true);
        return $decoded ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $json;
    };

    $normalized_new = $normalize_json($content);
    $normalized_existing = $existing_content ? $normalize_json($existing_content) : null;

    // 3. 🚨 Skip if identical (ONLY if file exists)
    if ($sha && $normalized_existing === $normalized_new) {
        // Optional debug:
        // error_log("APS Sync Skipped (no changes): $path");
        return 'no_changes';
    }

    // 4. Prepare data
    $data = [
        'message' => $message,
        'content' => base64_encode($normalized_new),
    ];

    if ($sha) {
        $data['sha'] = $sha;
    }

    // 5. Send request
    $response = wp_remote_request($api_url, [
        'method'  => 'PUT',
        'headers' => [
            'Authorization' => "token $token",
            'User-Agent'    => 'WordPress-GitHub-Client',
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode($data)
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    if ($code < 200 || $code >= 300) {
        return false;
    }
    return true;
}

add_action('woocommerce_product_object_updated_props', 'handle_product_sale_change', 10, 2);

function handle_product_sale_change($product, $updated_props) {
    // Only proceed if sale_price was one of the props that actually changed
    if (!in_array('sale_price', $updated_props, true)) return;

    if (!$product->is_type('simple')) return;

    $sale_price = $product->get_sale_price();

    // No active sale (removed, or never had one) — nothing to announce
    if ($sale_price === '' || $sale_price === null) return;

    send_sale_push_notification($product);
}

add_action('woocommerce_product_object_updated_props', 'handle_scheduled_sale_start', 10, 2);

function handle_scheduled_sale_start($product, $updated_props) {
    if (!in_array('price', $updated_props, true)) return;
    if (in_array('sale_price', $updated_props, true)) return; // already handled above, avoid double-send
    if (!$product->is_type('simple')) return;
    if (!$product->is_on_sale()) return;

    // price changed, no explicit sale_price edit, and now on sale =
    // very likely a scheduled sale activating via cron
    send_sale_push_notification($product);
}