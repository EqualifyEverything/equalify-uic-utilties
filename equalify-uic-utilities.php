<?php
/*
Plugin Name: Equalify + UIC Network Utilities
Description: Scans for public PDF and site URLs. Network or single-site.
Version: 3.0.0
Author: Blake Bertuccelli-Booth (UIC)
*/

if (!defined('UIC_EQUALIFY_OPTION_PUBLIC_CSV_ENABLED')) {
    define('UIC_EQUALIFY_OPTION_PUBLIC_CSV_ENABLED', 'uic_equalify_public_csv_enabled');
}
if (!defined('UIC_EQUALIFY_OPTION_SCAN_STATUS')) {
    define('UIC_EQUALIFY_OPTION_SCAN_STATUS', 'uic_equalify_scan_status');
}
if (!defined('UIC_EQUALIFY_SCAN_WEEKLY_EVENT')) {
    define('UIC_EQUALIFY_SCAN_WEEKLY_EVENT', 'uic_equalify_weekly_scan_event');
}
if (!defined('UIC_EQUALIFY_SCAN_MANUAL_EVENT')) {
    define('UIC_EQUALIFY_SCAN_MANUAL_EVENT', 'uic_equalify_manual_scan_event');
}

/**
 * Add the network admin menu page for Equalify + UIC Utilities
 */
add_action('network_admin_menu', 'uic_equalify_add_network_admin_menu');
function uic_equalify_add_network_admin_menu()
{
    add_submenu_page(
        'settings.php',
        'Equalify + UIC Utilities',
        'Equalify + UIC Utilities',
        'manage_network_options',
        'uic-equalify-utilities',
        'render_link_scanner_page'
    );
}

/**
 * Add the site admin menu page for Equalify + UIC Utilities (single site or subsite)
 */
add_action('admin_menu', 'uic_equalify_add_site_admin_menu');
function uic_equalify_add_site_admin_menu()
{
    // Avoid duplicating the page in the network admin context
    if (is_multisite() && is_network_admin()) {
        return;
    }

    add_submenu_page(
        'tools.php',
        'Equalify + UIC Utilities',
        'Equalify + UIC Utilities',
        'manage_options',
        'uic-equalify-utilities',
        'render_link_scanner_page'
    );
}

/**
 * Helpers to store and retrieve the current scan ID for a given site context.
 */
function uic_get_scan_id_for_site($site_id, $is_network_context = false) {
    if (is_multisite()) {
        $id = get_blog_option($site_id, 'uic_equalify_current_scan_id');
        if (!$id && $is_network_context) {
            $id = get_site_option('uic_equalify_current_scan_id');
        }
        return $id;
    }
    return get_option('uic_equalify_current_scan_id');
}

function uic_set_scan_id_for_site($site_id, $scan_id) {
    if (is_multisite()) {
        update_blog_option($site_id, 'uic_equalify_current_scan_id', $scan_id);
        return;
    }
    update_option('uic_equalify_current_scan_id', $scan_id);
}

function uic_clear_scan_id_for_site($site_id) {
    if (is_multisite()) {
        delete_blog_option($site_id, 'uic_equalify_current_scan_id');
        return;
    }
    delete_option('uic_equalify_current_scan_id');
}

/**
 * Generate a scan ID with a site-identifying slug (or network label).
 *
 * @param int  $site_id
 * @param bool $is_network_scan
 * @return string
 */
function uic_generate_scan_id($site_id, $is_network_scan = false) {
    $label = 'network';
    if (!$is_network_scan) {
        if (function_exists('get_blog_details') && ($details = get_blog_details($site_id))) {
            $label = sanitize_title($details->blogname);
        } elseif (function_exists('get_bloginfo')) {
            $label = sanitize_title(get_bloginfo('name'));
        } else {
            $label = 'site-' . (int) $site_id;
        }
        if ($label === '') {
            $label = 'site-' . (int) $site_id;
        }
    }
    return 'scan_' . $label . '_' . uniqid();
}

/**
 * Ensure schema is up to date (adds site_id column/index if missing).
 *
 * @param string $table_name
 */
function uic_equalify_ensure_schema($table_name) {
    global $wpdb;
    $column = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_name LIKE %s", 'site_id'));
    if (empty($column)) {
        $wpdb->query("ALTER TABLE $table_name ADD site_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER scan_id");
        $wpdb->query("ALTER TABLE $table_name ADD INDEX site_id (site_id)");
    }
}

// --- DB Table for scan results ---
register_activation_hook(__FILE__, 'uic_equalify_create_scan_results_table');
function uic_equalify_create_scan_results_table() {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $sql = "CREATE TABLE $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        scan_id VARCHAR(64) NOT NULL,
        site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        timestamp DATETIME NOT NULL,
        link_type VARCHAR(32) NOT NULL,
        location_type VARCHAR(128) NOT NULL,
        title TEXT,
        link TEXT,
        url TEXT,
        PRIMARY KEY  (id),
        KEY scan_id (scan_id),
        KEY site_id (site_id)
    ) $charset_collate;";
    dbDelta($sql);
}

register_deactivation_hook(__FILE__, 'uic_equalify_on_deactivation');
function uic_equalify_on_deactivation() {
    $site_ids = [function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0];
    if (is_multisite()) {
        $site_ids = get_sites([
            'number' => 0,
            'fields' => 'ids',
        ]);
    }

    foreach ($site_ids as $site_id) {
        uic_unschedule_site_scans((int) $site_id);
    }
}

add_filter('cron_schedules', 'uic_equalify_add_weekly_cron_schedule');
function uic_equalify_add_weekly_cron_schedule($schedules) {
    if (!isset($schedules['uic_equalify_weekly'])) {
        $schedules['uic_equalify_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __('Once Weekly', 'uic-equalify-utilities'),
        ];
    }
    return $schedules;
}

function uic_get_scan_status_defaults() {
    return [
        'state'          => 'idle',
        'last_started'   => 0,
        'last_completed' => 0,
        'last_error'     => '',
        'last_scan_id'   => '',
        'last_item_count'=> 0,
        'progress_processed' => 0,
        'progress_total'     => 0,
        'progress_message'   => '',
    ];
}

function uic_get_option_for_site($site_id, $option_name, $default = null) {
    $site_id = (int) $site_id;
    if (is_multisite() && function_exists('get_current_blog_id') && $site_id !== (int) get_current_blog_id()) {
        return get_blog_option($site_id, $option_name, $default);
    }
    return get_option($option_name, $default);
}

function uic_update_option_for_site($site_id, $option_name, $value) {
    $site_id = (int) $site_id;
    if (is_multisite() && function_exists('get_current_blog_id') && $site_id !== (int) get_current_blog_id()) {
        return update_blog_option($site_id, $option_name, $value);
    }
    return update_option($option_name, $value);
}

function uic_is_public_csv_enabled_for_site($site_id) {
    return true;
}

function uic_get_scan_status_for_site($site_id) {
    $status = uic_get_option_for_site($site_id, UIC_EQUALIFY_OPTION_SCAN_STATUS, []);
    if (!is_array($status)) {
        $status = [];
    }
    return array_merge(uic_get_scan_status_defaults(), $status);
}

function uic_update_scan_status_for_site($site_id, array $updates) {
    $status = array_merge(uic_get_scan_status_for_site($site_id), $updates);
    uic_update_option_for_site($site_id, UIC_EQUALIFY_OPTION_SCAN_STATUS, $status);
    return $status;
}

function uic_get_public_csv_filename($site_id) {
    return 'equalify-uic-public-links-site-' . (int) $site_id . '.csv';
}

function uic_get_public_csv_location($site_id) {
    $upload_dir = wp_upload_dir();
    $filename = uic_get_public_csv_filename($site_id);

    return [
        'path' => trailingslashit($upload_dir['basedir']) . $filename,
        'url'  => trailingslashit($upload_dir['baseurl']) . $filename,
    ];
}

function uic_unschedule_site_scans($site_id) {
    while ($timestamp = wp_next_scheduled(UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id])) {
        wp_unschedule_event($timestamp, UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id]);
    }
    while ($timestamp = wp_next_scheduled(UIC_EQUALIFY_SCAN_MANUAL_EVENT, [$site_id])) {
        wp_unschedule_event($timestamp, UIC_EQUALIFY_SCAN_MANUAL_EVENT, [$site_id]);
    }
}

function uic_schedule_weekly_scan_for_site($site_id) {
    if (!wp_next_scheduled(UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id])) {
        wp_schedule_event(time() + WEEK_IN_SECONDS, 'uic_equalify_weekly', UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id]);
    }
}

function uic_queue_manual_scan_for_site($site_id) {
    if (!wp_next_scheduled(UIC_EQUALIFY_SCAN_MANUAL_EVENT, [$site_id])) {
        wp_schedule_single_event(time() + 5, UIC_EQUALIFY_SCAN_MANUAL_EVENT, [$site_id]);
    }
    uic_update_scan_status_for_site($site_id, [
        'state'              => 'queued',
        'last_error'         => '',
        'progress_processed' => 0,
        'progress_total'     => 0,
        'progress_message'   => 'Waiting for WP-Cron to start.',
    ]);
}

function uic_format_admin_time($timestamp) {
    $timestamp = (int) $timestamp;
    if ($timestamp <= 0) {
        return 'Never';
    }
    return wp_date('Y-m-d H:i:s T', $timestamp);
}

function uic_get_status_label($state) {
    switch ((string) $state) {
        case 'queued':
            return 'Queued';
        case 'running':
            return 'Running';
        case 'success':
            return 'Complete';
        case 'error':
            return 'Failed';
        default:
            return 'Idle';
    }
}

function uic_count_scan_work_units() {
    $public_post_count = 0;
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $type) {
        $counts = wp_count_posts($type);
        if ($counts && isset($counts->publish)) {
            $public_post_count += (int) $counts->publish;
        }
    }

    $menu_item_count = 0;
    $menus = wp_get_nav_menus();
    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu);
        if (!empty($items)) {
            $menu_item_count += count($items);
        }
    }

    return max(1, ($public_post_count * 2) + $menu_item_count);
}

/**
 * Render plugin settings and scan status.
 */
function render_link_scanner_page()
{
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    uic_equalify_ensure_schema($table_name);
    $site_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    $notices = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['uic_run_scan_now']) && check_admin_referer('uic_run_scan_now')) {
        uic_queue_manual_scan_for_site($site_id);
        $notices[] = ['type' => 'success', 'message' => 'Scan queued and will run in the background shortly.'];
    }

    $status = uic_get_scan_status_for_site($site_id);
    $csv_location = uic_get_public_csv_location($site_id);
    $csv_exists = file_exists($csv_location['path']);
    $next_run = wp_next_scheduled(UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id]);
    $is_processing = in_array($status['state'], ['queued', 'running'], true);
    $remaining_units = max(0, (int) $status['progress_total'] - (int) $status['progress_processed']);

    if (!$next_run) {
        uic_schedule_weekly_scan_for_site($site_id);
        $next_run = wp_next_scheduled(UIC_EQUALIFY_SCAN_WEEKLY_EVENT, [$site_id]);
    }

    if ((int) $status['last_completed'] === 0 && !$is_processing) {
        uic_queue_manual_scan_for_site($site_id);
        $status = uic_get_scan_status_for_site($site_id);
        $is_processing = in_array($status['state'], ['queued', 'running'], true);
    }
    ?>
    <div class="wrap">
        <h1>Equalify + UIC Utilities</h1>
        <p>Schedule weekly background scans that publish a single CSV containing PDFs and public pages.</p>

        <?php foreach ($notices as $notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
        <?php endforeach; ?>

        <?php if (!empty($status['last_error'])): ?>
            <div class="notice notice-error"><p><?php echo esc_html($status['last_error']); ?></p></div>
        <?php endif; ?>

        <h2>Scan Status</h2>
        <table class="widefat striped" style="max-width: 900px;">
            <tbody>
                <tr>
                    <th>Status</th>
                    <td><?php echo esc_html(uic_get_status_label($status['state'])); ?></td>
                </tr>
                <tr>
                    <th>CSV URL</th>
                    <td>
                        <?php if ($csv_exists): ?>
                            <a href="<?php echo esc_url($csv_location['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($csv_location['url']); ?></a>
                        <?php else: ?>
                            Not generated yet.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Next Weekly Run</th>
                    <td><?php echo $next_run ? esc_html(uic_format_admin_time($next_run)) : 'Not scheduled'; ?></td>
                </tr>
                <tr>
                    <th>Last Started</th>
                    <td><?php echo esc_html(uic_format_admin_time($status['last_started'])); ?></td>
                </tr>
                <tr>
                    <th>Last Completed</th>
                    <td><?php echo esc_html(uic_format_admin_time($status['last_completed'])); ?></td>
                </tr>
                <tr>
                    <th>Rows in Latest CSV</th>
                    <td><?php echo esc_html((string) (int) $status['last_item_count']); ?></td>
                </tr>
                <tr>
                    <th>Progress</th>
                    <td>
                        <?php echo esc_html((string) (int) $status['progress_processed']); ?> / <?php echo esc_html((string) (int) $status['progress_total']); ?>
                        <?php if ($is_processing): ?>
                            (<?php echo esc_html((string) $remaining_units); ?> remaining)
                        <?php endif; ?>
                        <?php if (!empty($status['progress_message'])): ?>
                            <br><?php echo esc_html($status['progress_message']); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <form method="post" style="margin-top: 1rem;">
            <?php wp_nonce_field('uic_run_scan_now'); ?>
            <input type="submit" name="uic_run_scan_now" class="button button-secondary" value="Run Scan Now">
        </form>
    </div>
    <?php if ($is_processing): ?>
        <script>
            setTimeout(function () { window.location.reload(); }, 5000);
        </script>
    <?php endif; ?>
    <?php
}

/**
 * Scan posts and menus for PDFs and store results in DB.
 *
 * @return string scan_id
 */
function scan_links($scan_id = null, $progress_callback = null)
{
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    if (!$scan_id) {
        $site_id_for_scan = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
        $scan_id = uic_generate_scan_id($site_id_for_scan, false);
    }
    $site_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
    $timestamp = current_time('mysql');

    $global_seen_links = [];
    $batch_rows = [];
    $batch_size = 100;
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $type) {
        $paged = 1;
        $args = [
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'fields'         => 'ids',
        ];
        do {
            $query = new WP_Query($args);
            foreach ($query->posts as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                    if (is_callable($progress_callback)) {
                        $progress_callback('Scanning posts and ACF fields');
                    }
                    continue;
                }
                $content = $post->post_content;
                preg_match_all('/href=[\'"]([^\'"]+)[\'"]/i', $content, $matches);
                foreach ($matches[1] as $link) {
                    $normalized = trim($link);
                    if (preg_match('/\.pdf(\?.*)?$/i', $normalized)) {
                        $link_type = 'PDF';
                    } else {
                        continue;
                    }
                    if (!isset($global_seen_links[$normalized])) {
                        $global_seen_links[$normalized] = true;
                        $row = [
                            'scan_id'       => $scan_id,
                            'site_id'       => $site_id,
                            'timestamp'     => $timestamp,
                            'link_type'     => $link_type,
                            'location_type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name : ucfirst($post->post_type),
                            'title'         => get_the_title($post),
                            'link'          => $normalized,
                            'url'           => get_permalink($post),
                        ];
                        $batch_rows[] = $row;
                        if (count($batch_rows) >= $batch_size) {
                            $values = [];
                            $placeholders = [];
                            foreach ($batch_rows as $b_row) {
                                $values = array_merge($values, array_values($b_row));
                                $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
                            }
                            $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                            $wpdb->query($wpdb->prepare($sql, ...$values));
                            $batch_rows = [];
                        }
                    }
                }
                // Check ACF fields for matching links
                if (function_exists('get_fields')) {
                    $post_fields = wp_cache_get($post->ID, 'uic_acf_fields');
                    if ($post_fields === false) {
                        $post_fields = get_fields($post->ID);
                        wp_cache_set($post->ID, $post_fields, 'uic_acf_fields');
                    }
                    $fields = $post_fields;
                    if ($fields && is_array($fields)) {
                        foreach ($fields as $field_key => $field_value) {
                            if (is_string($field_value)) {
                                preg_match_all('/https?:\/\/[^\s"\']+/i', $field_value, $acf_matches);
                                foreach ($acf_matches[0] as $link) {
                                    $normalized = trim($link);
                                    if (preg_match('/\.pdf(\?.*)?$/i', $normalized)) {
                                        $link_type = 'PDF';
                                    } else {
                                        continue;
                                    }
                                    if (!isset($global_seen_links[$normalized])) {
                                        $global_seen_links[$normalized] = true;
                                        $row = [
                                            'scan_id'       => $scan_id,
                                            'site_id'       => $site_id,
                                            'timestamp'     => $timestamp,
                                            'link_type'     => $link_type,
                                            'location_type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name . ' (ACF Field)' : ucfirst($post->post_type) . ' (ACF Field)',
                                            'title'         => get_the_title($post) . " (Field: $field_key)",
                                            'link'          => $normalized,
                                            'url'           => get_permalink($post),
                                        ];
                                        $batch_rows[] = $row;
                                        if (count($batch_rows) >= $batch_size) {
                                            $values = [];
                                            $placeholders = [];
                                            foreach ($batch_rows as $b_row) {
                                                $values = array_merge($values, array_values($b_row));
                                                $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
                                            }
                                            $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                                            $wpdb->query($wpdb->prepare($sql, ...$values));
                                            $batch_rows = [];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if (is_callable($progress_callback)) {
                    $progress_callback('Scanning posts and ACF fields');
                }
            }
            $paged++;
            $args['paged'] = $paged;
        } while ($query->have_posts());
        wp_reset_postdata();
    }
    // Insert any remaining batch rows for posts/ACF
    if (!empty($batch_rows)) {
        $values = [];
        $placeholders = [];
        foreach ($batch_rows as $b_row) {
            $values = array_merge($values, array_values($b_row));
            $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
        }
        $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, ...$values));
        $batch_rows = [];
    }

    // Menu scan
    $menus = wp_get_nav_menus();
    $seen_links = [];
    $batch_rows = [];
    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu);
        if (!empty($items)) {
            foreach ($items as $item) {
                $link = $item->url;
                $normalized = trim($link);
                if (preg_match('/\.pdf(\?.*)?$/i', $normalized)) {
                    $link_type = 'PDF';
                } else {
                    continue;
                }
                if (!isset($seen_links[$normalized])) {
                    $seen_links[$normalized] = true;
                    $row = [
                        'scan_id'       => $scan_id,
                        'site_id'       => $site_id,
                        'timestamp'     => $timestamp,
                        'link_type'     => $link_type,
                        'location_type' => 'Menu',
                        'title'         => $item->title,
                        'link'          => $normalized,
                        'url'           => '',
                    ];
                    $batch_rows[] = $row;
                    if (count($batch_rows) >= 100) {
                        $values = [];
                        $placeholders = [];
                        foreach ($batch_rows as $b_row) {
                            $values = array_merge($values, array_values($b_row));
                            $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
                        }
                        $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                        $wpdb->query($wpdb->prepare($sql, ...$values));
                        $batch_rows = [];
                    }
                }
                if (is_callable($progress_callback)) {
                    $progress_callback('Scanning navigation menus');
                }
            }
        }
    }
    // Insert any remaining menu batch rows
    if (!empty($batch_rows)) {
        $values = [];
        $placeholders = [];
        foreach ($batch_rows as $b_row) {
            $values = array_merge($values, array_values($b_row));
            $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
        }
        $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, ...$values));
    }

    // --- Add "Public URL" rows for all public posts, even if no PDF links ---
    $public_rows = [];
    $batch_size = 100;
    $post_types = get_post_types(['public' => true]);
    foreach ($post_types as $type) {
        $paged = 1;
        $args = [
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'fields'         => 'ids',
        ];
        do {
            $query = new WP_Query($args);
            foreach ($query->posts as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_status !== 'publish' || !empty($post->post_password)) {
                    if (is_callable($progress_callback)) {
                        $progress_callback('Building public URL entries');
                    }
                    continue;
                }
                $row = [
                    'scan_id'       => $scan_id,
                    'site_id'       => $site_id,
                    'timestamp'     => $timestamp,
                    'link_type'     => 'Public URL',
                    'location_type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name : ucfirst($post->post_type),
                    'title'         => get_the_title($post),
                    'link'          => '',
                    'url'           => get_permalink($post),
                ];
                $public_rows[] = $row;
                if (count($public_rows) >= $batch_size) {
                    $values = [];
                    $placeholders = [];
                    foreach ($public_rows as $b_row) {
                        $values = array_merge($values, array_values($b_row));
                        $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
                    }
                    $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                    $wpdb->query($wpdb->prepare($sql, ...$values));
                    $public_rows = [];
                }
                if (is_callable($progress_callback)) {
                    $progress_callback('Building public URL entries');
                }
            }
            $paged++;
            $args['paged'] = $paged;
        } while ($query->have_posts());
        wp_reset_postdata();
    }
    // Insert any remaining public rows
    if (!empty($public_rows)) {
        $values = [];
        $placeholders = [];
        foreach ($public_rows as $b_row) {
            $values = array_merge($values, array_values($b_row));
            $placeholders[] = '(' . implode(',', array_fill(0, count($b_row), '%s')) . ')';
        }
        $sql = "INSERT INTO $table_name (scan_id, site_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, ...$values));
    }

    return $scan_id;
}

add_action(UIC_EQUALIFY_SCAN_WEEKLY_EVENT, 'uic_process_scheduled_scan', 10, 1);
add_action(UIC_EQUALIFY_SCAN_MANUAL_EVENT, 'uic_process_scheduled_scan', 10, 1);

function uic_process_scheduled_scan($site_id) {
    global $wpdb;

    $site_id = (int) $site_id;
    if ($site_id <= 0) {
        $site_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
    }

    $switched = false;
    if (is_multisite() && function_exists('switch_to_blog') && function_exists('get_current_blog_id') && $site_id !== (int) get_current_blog_id()) {
        switch_to_blog($site_id);
        $switched = true;
    }

    $scan_started = false;
    try {
        if (!uic_is_public_csv_enabled_for_site($site_id)) {
            uic_update_scan_status_for_site($site_id, [
                'state' => 'idle',
            ]);
        } else {
            $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
            uic_equalify_ensure_schema($table_name);
            $total_work_units = uic_count_scan_work_units();
            $progress_processed = 0;
            $last_progress_update = microtime(true);

            uic_update_scan_status_for_site($site_id, [
                'state'              => 'running',
                'last_started'       => current_time('timestamp'),
                'last_error'         => '',
                'progress_processed' => 0,
                'progress_total'     => $total_work_units,
                'progress_message'   => 'Starting scan...',
            ]);

            $scan_id = uic_generate_scan_id($site_id, false);
            uic_set_scan_id_for_site($site_id, $scan_id);
            $scan_started = true;

            // Keep only latest scan results for this site.
            $wpdb->delete($table_name, ['site_id' => $site_id], ['%d']);

            $progress_callback = function ($message = '') use ($site_id, $total_work_units, &$progress_processed, &$last_progress_update) {
                $progress_processed++;
                $should_update = ($progress_processed % 25) === 0 || (microtime(true) - $last_progress_update) >= 2;
                if (!$should_update && $progress_processed < $total_work_units) {
                    return;
                }
                $last_progress_update = microtime(true);
                uic_update_scan_status_for_site($site_id, [
                    'state'              => 'running',
                    'progress_processed' => min($progress_processed, $total_work_units),
                    'progress_total'     => $total_work_units,
                    'progress_message'   => (string) $message,
                ]);
            };

            scan_links($scan_id, $progress_callback);
            uic_update_scan_status_for_site($site_id, [
                'state'              => 'running',
                'progress_processed' => $total_work_units,
                'progress_total'     => $total_work_units,
                'progress_message'   => 'Generating CSV...',
            ]);
            $row_count = uic_generate_csv_for_site_scan($site_id, $scan_id);

            uic_update_scan_status_for_site($site_id, [
                'state'              => 'success',
                'last_completed'     => current_time('timestamp'),
                'last_error'         => '',
                'last_scan_id'       => $scan_id,
                'last_item_count'    => $row_count,
                'progress_processed' => $total_work_units,
                'progress_total'     => $total_work_units,
                'progress_message'   => 'Scan complete.',
            ]);
        }
    } catch (Throwable $e) {
        uic_update_scan_status_for_site($site_id, [
            'state'            => 'error',
            'last_error'       => 'Scan failed: ' . $e->getMessage(),
            'progress_message' => 'Scan failed before completion.',
        ]);
    } finally {
        if ($scan_started) {
            uic_clear_scan_id_for_site($site_id);
        }
        if ($switched) {
            restore_current_blog();
        }
    }
}

/**
 * Normalize a stored scan row into the simplified CSV shape.
 *
 * @param array $row
 * @return array|null
 */
function uic_format_scan_row_for_csv(array $row) {
    $link_type = strtolower($row['link_type'] ?? '');
    if ($link_type === 'pdf') {
        $url = isset($row['link']) ? trim($row['link']) : '';
        $sanitized_url = esc_url_raw($url);
        if ($sanitized_url === '') {
            return null;
        }
        return ['url' => $sanitized_url, 'type' => 'pdf'];
    }

    if ($link_type === 'public url') {
        $url = isset($row['url']) ? trim($row['url']) : '';
        $sanitized_url = esc_url_raw($url);
        if ($sanitized_url === '') {
            return null;
        }
        return ['url' => $sanitized_url, 'type' => 'html'];
    }

    // Skip private or unsupported link types.
    return null;
}
function uic_generate_csv_for_site_scan($site_id, $scan_id) {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    $csv_location = uic_get_public_csv_location($site_id);
    $csv_path = $csv_location['path'];
    $temp_path = $csv_path . '.tmp';
    $out = fopen($temp_path, 'w');
    if ($out === false) {
        throw new RuntimeException('Unable to open CSV file for writing.');
    }
    fputcsv($out, ['url', 'type']);

    $offset = 0;
    $limit = 500;
    $written_rows = 0;
    while (true) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE site_id = %d AND scan_id = %s ORDER BY id ASC LIMIT %d OFFSET %d",
                $site_id,
                $scan_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
        if (empty($rows)) {
            break;
        }
        foreach ($rows as $row) {
            $csv_row = uic_format_scan_row_for_csv($row);
            if ($csv_row === null) {
                continue;
            }
            fputcsv($out, array_values($csv_row));
            $written_rows++;
        }
        $offset += $limit;
    }

    fclose($out);
    if (!@rename($temp_path, $csv_path)) {
        if (file_exists($csv_path)) {
            @unlink($csv_path);
        }
        if (!@rename($temp_path, $csv_path)) {
            @unlink($temp_path);
            throw new RuntimeException('Unable to replace the public CSV file.');
        }
    }

    return $written_rows;
}
