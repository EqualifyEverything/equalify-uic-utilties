<?php
/*
Plugin Name: Equalify + UIC Network Utilities
Description: Scans for public PDF and site URLs. Network or single-site.
Version: 2.0.1
Author: Blake Bertuccelli-Booth (UIC)
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

    add_menu_page(
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

/**
 * Render the main link scanner page in the network admin
 */
function render_link_scanner_page()
{
    global $wpdb;
    $table_name = $wpdb->base_prefix . 'uic_equalify_scan_results';
    uic_equalify_ensure_schema($table_name);
    $is_network_context = is_multisite() && is_network_admin();
    $current_site_id = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;

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
        if ($is_network_context) {
            $wpdb->delete($table_name, ['scan_id' => $scan_id]);
        } else {
            $wpdb->delete($table_name, ['scan_id' => $scan_id, 'site_id' => $current_site_id]);
        }
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
        if ($is_network_context) {
            $wpdb->query("TRUNCATE TABLE $table_name");
            echo '<div class="notice notice-success"><p>All scan results deleted.</p></div>';
        } else {
            $wpdb->delete($table_name, ['site_id' => $current_site_id]);
            echo '<div class="notice notice-success"><p>All scan results for this site deleted.</p></div>';
        }
    }

    // Handle scan scheduling
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['trigger_all_scans']) && check_admin_referer('uic_trigger_all_scans')) {
            if ($is_network_context) {
                uic_schedule_site_scans();
                echo '<div class="notice notice-success"><p>Scheduled scans for all sites. Results will appear as each site completes.</p></div>';
            } else {
                uic_schedule_single_site_scan($current_site_id);
                echo '<div class="notice notice-success"><p>Scheduled a scan for this site. Results will appear shortly.</p></div>';
            }
        }
    }

    // --- Begin: Scheduled scan detection and stop logic ---
    $total_sites = 1;
    $pending_sites = 0;
    $is_scan_scheduled = false;
    $current_scan_id = uic_get_scan_id_for_site($current_site_id, $is_network_context);
    if ($is_network_context) {
        $sites = uic_get_all_sites_cached();
        $total_sites = is_array($sites) ? count($sites) : 0;
        foreach ($sites as $site) {
            if (wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
                $is_scan_scheduled = true;
                $pending_sites++;
            }
        }
    } else {
        if (wp_next_scheduled('uic_scan_site_links_event', [$current_site_id])) {
            $is_scan_scheduled = true;
            $pending_sites = 1;
        }
    }

    if (isset($_POST['stop_all_scans']) && check_admin_referer('uic_stop_all_scans')) {
        if ($is_network_context) {
            // Cancel all scheduled scans network-wide
            if (!isset($sites)) {
                $sites = uic_get_all_sites_cached();
            }
            foreach ($sites as $site) {
                while ($timestamp = wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
                    wp_unschedule_event($timestamp, 'uic_scan_site_links_event', [$site->blog_id]);
                }
            }
            if ($current_scan_id) {
                $wpdb->delete($table_name, ['scan_id' => $current_scan_id]);
                $upload_dir = wp_upload_dir();
                $csv_path = $upload_dir['basedir'] . "/scan_" . $current_scan_id . ".csv";
                if (file_exists($csv_path)) {
                    unlink($csv_path);
                }
                delete_site_option('uic_equalify_current_scan_id');
                // Clear per-site stored scan IDs
                foreach ($sites as $site) {
                    delete_blog_option($site->blog_id, 'uic_equalify_current_scan_id');
                }
            }
        } else {
            // Cancel scans for this single site
            while ($timestamp = wp_next_scheduled('uic_scan_site_links_event', [$current_site_id])) {
                wp_unschedule_event($timestamp, 'uic_scan_site_links_event', [$current_site_id]);
            }
            if ($current_scan_id) {
                $wpdb->delete($table_name, ['scan_id' => $current_scan_id, 'site_id' => $current_site_id]);
                $upload_dir = wp_upload_dir();
                $csv_path = $upload_dir['basedir'] . "/scan_" . $current_scan_id . ".csv";
                if (file_exists($csv_path)) {
                    unlink($csv_path);
                }
                delete_option('uic_equalify_current_scan_id');
            }
        }
        echo '<div class="notice notice-success"><p>All scheduled scans have been cancelled.</p></div>';
        $is_scan_scheduled = false;
        $pending_sites = 0;
    }
    // --- End: Scheduled scan detection and stop logic ---
    ?>
    <div class="wrap">
        <h1>UIC + Equalify Utilities</h1>
        <h3><?php echo $is_network_context ? 'Network Scan' : 'Site Scan'; ?></h3>
        <p>Scans for public PDFs and site URLs. This will schedule scans to run in the background.</p>
        <?php if ($is_scan_scheduled): ?>
            <div class="notice notice-info">
                <p>
                    A full <?php echo $is_network_context ? 'network' : 'site'; ?> scan is currently scheduled or in progress.
                    <?php if ($total_sites > 0): ?>
                        <?php echo esc_html($pending_sites); ?> of <?php echo esc_html($total_sites); ?> <?php echo $is_network_context ? 'sites' : 'runs'; ?> remain to be scanned.
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg([])); ?>">Refresh this page</a>
                </p>
            </div>
            <form method="post">
                <?php wp_nonce_field('uic_stop_all_scans'); ?>
                <input type="submit" name="stop_all_scans" class="button button-secondary" value="Stop <?php echo $is_network_context ? 'Network' : 'Site'; ?> Scan">
            </form>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('uic_trigger_all_scans'); ?>
                <input type="submit" name="trigger_all_scans" class="button button-primary" value="Run Full <?php echo $is_network_context ? 'Network' : 'Site'; ?> Scan">
            </form>
        <?php endif; ?>

        <h3>Scan Results</h3>
        <?php
        // List all unique scan_id and timestamp
        if ($is_network_context) {
            $scans = $wpdb->get_results("SELECT scan_id, MIN(timestamp) as ts, COUNT(*) as count FROM $table_name GROUP BY scan_id ORDER BY ts DESC", ARRAY_A);
        } else {
            // Single-site view: only show site-specific scans, hide network scans.
            $scans = $wpdb->get_results($wpdb->prepare("SELECT scan_id, MIN(timestamp) as ts, COUNT(*) as count FROM $table_name WHERE site_id = %d AND scan_id NOT LIKE %s GROUP BY scan_id ORDER BY ts DESC", $current_site_id, 'scan_network_%'), ARRAY_A);
        }
        $has_pending_csv = false;
        // Get current scan ID (in-progress scan)
        $current_scan_indicator = $current_scan_id;
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
                        $is_current_scan = ($scan['scan_id'] === $current_scan_indicator && $current_scan_indicator);
                        $csv_job_scheduled = (bool) wp_next_scheduled('uic_generate_csv_event', [$scan['scan_id']]);
                        if ($is_current_scan) {
                            // If currently scanning, only show disabled button
                            echo '<button class="button button-small" disabled>Currently Scanning</button> ';
                            $has_pending_csv = true;
                        } else {
                            // Not currently scanning: show CSV actions and Delete
                            if (file_exists($csv_path)) {
                                $csv_url = $upload_dir['baseurl'] . "/scan_" . $scan['scan_id'] . ".csv";
                                echo '<a class="button button-small" href="' . esc_url($csv_url) . '">Download CSV</a> ';
                            } else {
                                if ($csv_job_scheduled) {
                                    $has_pending_csv = true;
                                }
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
    <?php if ($is_scan_scheduled || $has_pending_csv): ?>
        <script>
            // Auto-refresh while scans or CSV generation are in progress
            setTimeout(function() { location.reload(); }, 10000);
        </script>
    <?php endif; ?>
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

// Schedule scans for all sites, assigning a shared scan ID
function uic_schedule_site_scans() {
    $sites = uic_get_all_sites_cached();
    $shared_scan_id = uic_generate_scan_id(0, true);
    update_site_option('uic_equalify_current_scan_id', $shared_scan_id);
    foreach ($sites as $site) {
        if (!wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            uic_set_scan_id_for_site($site->blog_id, $shared_scan_id);
            wp_schedule_single_event(time() + rand(0, 300), 'uic_scan_site_links_event', [$site->blog_id]);
        }
    }
}

// Schedule a scan for the current site only
function uic_schedule_single_site_scan($site_id) {
    $scan_id = uic_generate_scan_id($site_id, false);
    uic_set_scan_id_for_site($site_id, $scan_id);
    if (!wp_next_scheduled('uic_scan_site_links_event', [$site_id])) {
        wp_schedule_single_event(time(), 'uic_scan_site_links_event', [$site_id]);
    }
}

add_action('uic_scan_site_links_event', 'uic_handle_site_scan', 10, 1);
function uic_handle_site_scan($site_id) {
    $switched = false;
    if (function_exists('switch_to_blog') && is_multisite()) {
        switch_to_blog($site_id);
        $switched = true;
    }
    $scan_id = uic_get_scan_id_for_site($site_id, is_multisite());
    scan_links($scan_id);
    if ($switched) {
        restore_current_blog();
    }

    // After restoring blog, check if any scheduled scans remain across all sites
    if (is_multisite()) {
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
        delete_blog_option($site_id, 'uic_equalify_current_scan_id');
    } else {
        if (!wp_next_scheduled('uic_scan_site_links_event', [$site_id])) {
            delete_option('uic_equalify_current_scan_id');
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
