<?php
/*
Plugin Name: Equalify + UIC Network Utilities
Description: Scans for public PDF and site URLs. Network-enabled.
Version: 1.9
Author: Blake Bertuccelli-Booth (UIC)
Network: true
*/

/**
 * Add the network admin menu page for Equalify + UIC Utilities
 */
add_action('network_admin_menu', 'uic_equalify_add_network_admin_menu');
function uic_equalify_add_network_admin_menu()
{
    add_menu_page(
        'Equalify + UIC Utilities',
        'Equalify + UIC Utilities',
        'manage_network_options',
        'uic-equalify-utilities',
        'render_link_scanner_page'
    );
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
        timestamp DATETIME NOT NULL,
        link_type VARCHAR(32) NOT NULL,
        location_type VARCHAR(128) NOT NULL,
        title TEXT,
        link TEXT,
        url TEXT,
        PRIMARY KEY  (id),
        KEY scan_id (scan_id)
    ) $charset_collate;";
    dbDelta($sql);
}

/**
 * Render the main link scanner page in the network admin
 */
function render_link_scanner_page()
{
    // Ensure this is accessed only from the Network Admin dashboard
    if (!is_network_admin()) {
        echo '<div class="notice notice-error"><p>This tool can only be used from the Network Admin dashboard.</p></div>';
        return;
    }

    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';

    // --- CSV GENERATION BACKGROUND: handle csv generation request ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_csv']) && check_admin_referer('generate_csv_' . $_POST['generate_csv'])) {
        $scan_id = sanitize_text_field($_POST['generate_csv']);
        if (!wp_next_scheduled('uic_generate_csv_event', [$scan_id])) {
            wp_schedule_single_event(time(), 'uic_generate_csv_event', [$scan_id]);
            echo '<div class="notice notice-info"><p>CSV generation scheduled. Refresh shortly to download.</p></div>';
        }
    }

    // Handle deletion of a scan
    if (isset($_GET['delete_scan']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_scan_' . $_GET['delete_scan'])) {
        $scan_id = sanitize_text_field($_GET['delete_scan']);
        $wpdb->delete($table_name, ['scan_id' => $scan_id]);
        // Delete associated CSV file
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . "/scan_" . $scan_id . ".csv";
        if (file_exists($csv_path)) {
            unlink($csv_path);
        }
        echo '<div class="notice notice-success"><p>Scan deleted.</p></div>';
    }

    // Bulk delete all scans
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_reports']) && check_admin_referer('bulk_report_action')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="notice notice-success"><p>All scan results deleted.</p></div>';
    }

    // Handle scan scheduling
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['trigger_all_scans']) && check_admin_referer('uic_trigger_all_scans')) {
            uic_schedule_site_scans();
            echo '<div class="notice notice-success"><p>Scheduled scans for all sites. Results will appear as each site completes.</p></div>';
        }
    }

    // --- Begin: Scheduled scan detection and stop logic ---
    $sites = uic_get_all_sites_cached();
    $total_sites = is_array($sites) ? count($sites) : 0;
    $pending_sites = 0;
    $is_scan_scheduled = false;
    foreach ($sites as $site) {
        if (wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            $is_scan_scheduled = true;
            $pending_sites++;
        }
    }
    if (isset($_POST['stop_all_scans']) && check_admin_referer('uic_stop_all_scans')) {
        foreach ($sites as $site) {
            $timestamp = wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id]);
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'uic_scan_site_links_event', [$site->blog_id]);
            }
        }
        echo '<div class="notice notice-success"><p>All scheduled scans have been cancelled.</p></div>';
        $is_scan_scheduled = false;
    }
    // --- End: Scheduled scan detection and stop logic ---
    ?>
    <div class="wrap">
        <h1>UIC + Equalify Utilities</h1>
        <h3>Network Scan</h3>
        <p>Scans for public PDF, Box.com, and site URLs. This will schedule scans to run in the background.</p>
        <?php if ($is_scan_scheduled): ?>
            <div class="notice notice-info">
                <p>
                    A full network scan is currently scheduled or in progress.
                    <?php if ($total_sites > 0): ?>
                        <?php echo esc_html($pending_sites); ?> of <?php echo esc_html($total_sites); ?> sites remain to be scanned.
                    <?php endif; ?>
                    Refresh this page to see the latest progress.
                </p>
            </div>
            <form method="post">
                <?php wp_nonce_field('uic_stop_all_scans'); ?>
                <input type="submit" name="stop_all_scans" class="button button-secondary" value="Stop Network Scan">
            </form>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('uic_trigger_all_scans'); ?>
                <input type="submit" name="trigger_all_scans" class="button button-primary" value="Run Full Network Scan">
            </form>
        <?php endif; ?>

        <h3>Scan Results</h3>
        <?php
        // List all unique scan_id and timestamp
        $scans = $wpdb->get_results("SELECT scan_id, MIN(timestamp) as ts, COUNT(*) as count FROM $table_name GROUP BY scan_id ORDER BY ts DESC", ARRAY_A);
        // Get current network scan ID (in-progress scan)
        $current_network_scan_id = get_site_option('uic_equalify_current_scan_id');
        if ($scans): ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Scan ID</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scans as $scan): ?>
                    <tr>
                        <td><?php echo esc_html($scan['scan_id']); ?></td>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($scan['ts']))); ?></td>
                        <td><?php echo intval($scan['count']); ?></td>
                        <td>
                        <?php
                        // CSV generation/download button logic
                        $upload_dir = wp_upload_dir();
                        $csv_path = $upload_dir['basedir'] . "/scan_" . $scan['scan_id'] . ".csv";
                        if ($scan['scan_id'] === $current_network_scan_id && $current_network_scan_id) {
                            // If currently scanning, only show disabled button
                            echo '<button class="button button-small" disabled>Currently Scanning</button> ';
                        } else {
                            // Not currently scanning: show CSV actions and Delete
                            if (file_exists($csv_path)) {
                                $csv_url = $upload_dir['baseurl'] . "/scan_" . $scan['scan_id'] . ".csv";
                                echo '<a class="button button-small" href="' . esc_url($csv_url) . '">Download CSV</a> ';
                            } else {
                                echo '<form method="post" style="display:inline;">';
                                wp_nonce_field('generate_csv_' . $scan['scan_id']);
                                echo '<input type="hidden" name="generate_csv" value="' . esc_attr($scan['scan_id']) . '">';
                                echo '<input type="submit" class="button button-small" value="Generate CSV">';
                                echo '</form>';
                            }
                            echo '<a class="button button-small" href="' . esc_url(wp_nonce_url(add_query_arg(['delete_scan' => $scan['scan_id']]), 'delete_scan_' . $scan['scan_id'])) . '" onclick="return confirm(\'Delete this scan and all results?\');">Delete</a>';
                        }
                        ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top: 1em;">
                <?php wp_nonce_field('bulk_report_action'); ?>
                <input type="submit" name="delete_all_reports" class="button" value="Delete All Scans" onclick="return confirm('Are you sure you want to delete ALL scan results?');">
            </form>
        <?php else: ?>
            <p>No scan results found.</p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Cached wrapper for get_sites using the Transients API to avoid redundant calls.
 *
 * @return array
 */
function uic_get_all_sites_cached() {
    $cached_sites = get_transient('uic_all_sites');
    if ($cached_sites === false) {
        $cached_sites = get_sites(['number' => 0]);
        set_transient('uic_all_sites', $cached_sites, 5 * MINUTE_IN_SECONDS);
    }
    return $cached_sites;
}

/**
 * Scan posts and menus for PDFs and store results in DB.
 *
 * @return string scan_id
 */
function scan_links($scan_id = null)
{
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    if (!$scan_id) {
        $scan_id = uniqid('scan_', true);
    }
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
                            $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
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
                                            $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                                            $wpdb->query($wpdb->prepare($sql, ...$values));
                                            $batch_rows = [];
                                        }
                                    }
                                }
                            }
                        }
                    }
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
        $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
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
                        $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                        $wpdb->query($wpdb->prepare($sql, ...$values));
                        $batch_rows = [];
                    }
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
        $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, ...$values));
    }

    // --- Add "Public URL" rows for all public posts, even if no PDF/Box links ---
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
                    continue;
                }
                $row = [
                    'scan_id'       => $scan_id,
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
                    $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
                    $wpdb->query($wpdb->prepare($sql, ...$values));
                    $public_rows = [];
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
        $sql = "INSERT INTO $table_name (scan_id, timestamp, link_type, location_type, title, link, url) VALUES " . implode(',', $placeholders);
        $wpdb->query($wpdb->prepare($sql, ...$values));
    }

    return $scan_id;
}

/**
 * Remove the plugin menu and hide plugin from subsite plugin lists
 * This prevents activation or usage on subsites
 */
if (!is_network_admin()) {
    add_action('admin_menu', 'uic_equalify_remove_subsite_menu');
    add_filter('all_plugins', 'uic_equalify_hide_plugin_on_subsites');
}

/**
 * Remove the plugin menu page on subsites
 */
function uic_equalify_remove_subsite_menu()
{
    remove_menu_page('uic-equalify-utilities');
}

/**
 * Hide this plugin from plugin list on subsites
 *
 * @param array $plugins
 * @return array
 */
function uic_equalify_hide_plugin_on_subsites($plugins)
{
    if (!is_network_admin()) {
        $plugin_file = plugin_basename(__FILE__);
        if (isset($plugins[$plugin_file])) {
            unset($plugins[$plugin_file]);
        }
    }
    return $plugins;
}

// Schedule scans for all sites, assigning a shared scan ID
function uic_schedule_site_scans() {
    $sites = uic_get_all_sites_cached();
    $shared_scan_id = uniqid('scan_', true);
    update_site_option('uic_equalify_current_scan_id', $shared_scan_id);
    foreach ($sites as $site) {
        if (!wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            wp_schedule_single_event(time() + rand(0, 300), 'uic_scan_site_links_event', [$site->blog_id]);
        }
    }
}

add_action('uic_scan_site_links_event', 'uic_handle_site_scan');
function uic_handle_site_scan($site_id) {
    switch_to_blog($site_id);
    $scan_id = get_site_option('uic_equalify_current_scan_id');
    scan_links($scan_id);
    restore_current_blog();

    // After restoring blog, check if any scheduled scans remain across all sites
    $sites = uic_get_all_sites_cached();
    $any_scheduled = false;
    foreach ($sites as $site) {
        if (wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            $any_scheduled = true;
            break;
        }
    }
    if (!$any_scheduled) {
        // No more scheduled scans, clear scan status
        delete_site_option('uic_equalify_current_scan_id');
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

    // Skip private or unsupported link types (e.g., Box or admin URLs).
    return null;
}
// -- CSV generation event for scan results --
add_action('uic_generate_csv_event', 'uic_generate_csv_for_scan');

function uic_generate_csv_for_scan($scan_id) {
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    $upload_dir = wp_upload_dir();
    $csv_path = $upload_dir['basedir'] . "/scan_$scan_id.csv";
    $out = fopen($csv_path, 'w');
    if ($out === false) {
        return;
    }
    fputcsv($out, ['url', 'type']);

    $offset = 0;
    $limit = 500;
    while (true) {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table_name WHERE scan_id = %s ORDER BY id ASC LIMIT %d OFFSET %d", $scan_id, $limit, $offset),
            ARRAY_A
        );
        if (empty($rows)) break;
        foreach ($rows as $row) {
            $csv_row = uic_format_scan_row_for_csv($row);
            if ($csv_row === null) {
                continue;
            }
            fputcsv($out, array_values($csv_row));
        }
        $offset += $limit;
    }

    fclose($out);
}
