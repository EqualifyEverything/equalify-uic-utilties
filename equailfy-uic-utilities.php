<?php
/*
Plugin Name: Equalify + UIC Network Utilities
Description: Scans content for PDF and Box.com links and exports CSV. Network-enabled.
Version: 1.5
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

/**
 * Render the main link scanner page in the network admin
 */
function render_link_scanner_page()
{
global $uic_blog_details_cache, $uic_upload_dir_cache;

/**
 * Preload blog details cache for all sites.
 */
function uic_preload_blog_details_cache() {
    global $uic_blog_details_cache;
    $uic_blog_details_cache = [];
    $sites = uic_get_all_sites_cached();
    foreach ($sites as $site) {
        $uic_blog_details_cache[$site->blog_id] = get_blog_details($site->blog_id);
    }
}
    // Ensure this is accessed only from the Network Admin dashboard
    if (!is_network_admin()) {
        echo '<div class="notice notice-error"><p>This tool can only be used from the Network Admin dashboard.</p></div>';
        return;
    }

    // Handle deletion of reports via nonce-verified request
    if (isset($_GET['delete_report']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_report')) {
        $target_file = basename($_GET['delete_report']);
        $deleted = false;

        // Search all public sites for the file and delete it if found
        $sites = uic_get_all_sites_cached();
        foreach ($sites as $site) {
            if (!isset($uic_upload_dir_cache[$site->blog_id])) {
                switch_to_blog($site->blog_id);
                $uic_upload_dir_cache[$site->blog_id] = wp_upload_dir();
                restore_current_blog();
            }
            $upload_dir = $uic_upload_dir_cache[$site->blog_id];
            $file_path = trailingslashit($upload_dir['basedir']) . $target_file;

            if (file_exists($file_path)) {
                error_log("Deleting from site ID: " . $site->blog_id . ", file: " . $file_path);
                unlink($file_path);
                $deleted = true;
                break;
            }
        }

        if (!$deleted) {
            error_log("File not found in any site: " . $target_file);
        }
    }

    $selected_site = get_current_blog_id();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_id'])) {
        $selected_site = (int) $_POST['site_id'];
        if (!headers_sent()) {
            setcookie('uic_selected_site', $selected_site, time() + 3600 * 24 * 30, COOKIEPATH, COOKIE_DOMAIN, is_ssl());
        }
    } elseif (isset($_COOKIE['uic_selected_site'])) {
        $selected_site = (int) $_COOKIE['uic_selected_site'];
    }
    
    // Unified POST and nonce logic for bulk and scan actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle trigger all scans
        if (isset($_POST['trigger_all_scans']) && check_admin_referer('uic_trigger_all_scans')) {
            uic_schedule_site_scans();
            echo '<div class="notice notice-success"><p>Scheduled scans for all sites. Results will appear as each site completes.</p></div>';
        }
        if ((isset($_POST['combine_all_reports']) || isset($_POST['delete_all_reports'])) && check_admin_referer('bulk_report_action')) {
            if (isset($_POST['combine_all_reports'])) {
                $upload_dir = wp_upload_dir();
                $merged_csv_path = trailingslashit($upload_dir['basedir']) . 'combined-link-reports.csv';
                $merged_csv_url = trailingslashit($upload_dir['baseurl']) . 'combined-link-reports.csv';
                $output = @fopen($merged_csv_path, 'w');
                if ($output === false) {
                    echo '<div class="notice notice-error"><p>Failed to create the combined report file.</p></div>';
                    return;
                }
                register_shutdown_function(function () use ($output) {
                    if (is_resource($output)) {
                        fclose($output);
                    }
                });
                fputcsv($output, ['Site Name', 'Site ID', 'Link Type', 'Location Type', 'Title', 'Link', 'URL']);
                $sites = uic_get_all_sites_cached();
                $found_any_files = false;
                foreach ($sites as $site) {
                    if (!isset($uic_blog_details_cache[$site->blog_id])) {
                        $uic_blog_details_cache[$site->blog_id] = get_blog_details($site->blog_id);
                    }
                    $blog_details = $uic_blog_details_cache[$site->blog_id];
                    $site_name = $blog_details->blogname;
                    $site_id = $site->blog_id;
                    if (!isset($uic_upload_dir_cache[$site->blog_id])) {
                        switch_to_blog($site->blog_id);
                        $uic_upload_dir_cache[$site->blog_id] = wp_upload_dir();
                        restore_current_blog();
                    }
                    $upload_dir = $uic_upload_dir_cache[$site->blog_id];
                    $files = array_filter(
                        glob(trailingslashit($upload_dir['basedir']) . '*.csv') ?: [],
                        fn($file) => strpos(basename($file), 'combined-link-reports-') === false
                    );
                    if (!empty($files)) {
                        $found_any_files = true;
                        foreach ($files as $file) {
                            $handle = fopen($file, 'r');
                            if ($handle !== false) {
                                $header = fgetcsv($handle); // skip original header
                                while (($data = fgetcsv($handle)) !== false) {
                                    $report_type = (stripos($file, '-pdf-') !== false) ? 'PDF' : ((stripos($file, '-box-') !== false) ? 'Box' : 'Unknown');
                                    fputcsv($output, array_merge([$site_name, $site_id, $report_type], $data));
                                }
                                fclose($handle);
                            }
                        }
                    }
                }
                fclose($output);
                echo '<div class="notice notice-success"><p>Reports sucessfully combined. <a href="' . esc_url($merged_csv_url) . '" download>Download CSV</a></p></div>';
            }
            if (isset($_POST['delete_all_reports'])) {
                $sites = uic_get_all_sites_cached();
                foreach ($sites as $site) {
                    if (!isset($uic_upload_dir_cache[$site->blog_id])) {
                        switch_to_blog($site->blog_id);
                        $uic_upload_dir_cache[$site->blog_id] = wp_upload_dir();
                        restore_current_blog();
                    }
                    $upload_dir = $uic_upload_dir_cache[$site->blog_id];
                    $files = glob(trailingslashit($upload_dir['basedir']) . '*.csv') ?: [];
                    foreach ($files as $file) {
                        unlink($file);
                    }
                }
                echo '<div class="notice notice-success"><p>All reports deleted.</p></div>';
            }
        } elseif (isset($_POST['run_combined_scan']) && check_admin_referer('run_link_scan')) {
            switch_to_blog($selected_site);
            $download_url = scan_links_and_generate_combined_csv();
            restore_current_blog();
        }
    }

    // Preload blog details cache before retrieving sites
    uic_preload_blog_details_cache();
    // Retrieve all public sites for dropdown and report listing
    $sites = uic_get_all_sites_cached();

    // --- Begin: Scheduled scan detection and stop logic ---
    $is_scan_scheduled = false;
    foreach ($sites as $site) {
        if (wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            $is_scan_scheduled = true;
            break;
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
        <h2>Invidual Site Scans</h2>

        <!-- Site selection and scan form -->
        <form method="post" action="" name="link_report_form">
            <label for="site_id">Select Site:</label>
            <select name="site_id" id="site_id">
                <?php foreach ($sites as $site) {
                    if (!isset($uic_blog_details_cache[$site->blog_id])) {
                        $uic_blog_details_cache[$site->blog_id] = get_blog_details($site->blog_id);
                    }
                    $blog_details = $uic_blog_details_cache[$site->blog_id];
                    $selected = ((int) $site->blog_id === (int) $selected_site) ? 'selected' : '';
                    echo "<option value='{$site->blog_id}' $selected>" . esc_html($blog_details->blogname) . "</option>";
                } ?>
            </select>
            <?php wp_nonce_field('run_link_scan'); ?>
            <br><br>
            <input type="submit" name="run_combined_scan" class="button button-primary" value="Scan for PDF + Box Links">
        </form>

        <h3>Network Scan</h3>
        <p>Scan all sites for PDF and Box links. This will schedule scans to run in the background.</p>
        <?php if ($is_scan_scheduled): ?>
            <div class="notice notice-info"><p>A full network scan is currently scheduled or in progress.</p></div>
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

        <h3>Reports</h3>
        <?php
        // Aggregate all reports from all public sites
        $all_reports = [];

        foreach ($sites as $site) {
            if (!isset($uic_upload_dir_cache[$site->blog_id])) {
                switch_to_blog($site->blog_id);
                $uic_upload_dir_cache[$site->blog_id] = wp_upload_dir();
                restore_current_blog();
            }
            $upload_dir = $uic_upload_dir_cache[$site->blog_id];
            $files = array_filter(
                glob(trailingslashit($upload_dir['basedir']) . '*.csv') ?: [],
                fn($file) => strpos(basename($file), 'combined-link-reports.csv') === false
            );

            if (!isset($uic_blog_details_cache[$site->blog_id])) {
                $uic_blog_details_cache[$site->blog_id] = get_blog_details($site->blog_id);
            }
            $blog_details = $uic_blog_details_cache[$site->blog_id];

            foreach ($files as $file_path) {
                $all_reports[] = [
                    'site'       => $blog_details->blogname,
                    'file_name'  => basename($file_path),
                    'file_url'   => trailingslashit($upload_dir['baseurl']) . basename($file_path),
                    'delete_url' => wp_nonce_url(add_query_arg(['delete_report' => basename($file_path)]), 'delete_report'),
                ];
            }
        }

        // Pagination logic
        $reports_per_page = 25;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_reports = count($all_reports);
        $total_pages = ceil($total_reports / $reports_per_page);
        $start_index = ($current_page - 1) * $reports_per_page;
        $paged_reports = array_slice($all_reports, $start_index, $reports_per_page);
        ?>
        <ul>
        <?php
        // Display each report with download and delete links (paginated)
        foreach ($paged_reports as $report) {
            echo '<li>' . esc_html($report['file_name']) . ' (' . esc_html($report['site']) . ') - <a href="' . esc_url($report['file_url']) . '">Download</a> | <a href="' . esc_url($report['delete_url']) . '">Delete</a></li>';
        }
        ?>
        </ul>
        <?php
        // Pagination controls
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages" style="float:none"><span class="displaying-num">' . esc_html($total_reports) . ' items</span>';
            echo '<span class="pagination-links">';

            $base_url = remove_query_arg('paged');
            $prev_disabled = $current_page <= 1 ? ' disabled' : '';
            $next_disabled = $current_page >= $total_pages ? ' disabled' : '';
            $prev_page = max(1, $current_page - 1);
            $next_page = min($total_pages, $current_page + 1);

            if ($current_page > 1) {
                echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '"><span class="screen-reader-text">First page</span><span aria-hidden="true">«</span></a> ';
                echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $prev_page, $base_url)) . '"><span class="screen-reader-text">Previous page</span><span aria-hidden="true">‹</span></a> ';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span> ';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span> ';
            }

            echo '<span class="screen-reader-text">Current Page</span>';
            echo '<span id="table-paging" class="paging-input">';
            echo '<span class="tablenav-paging-text">' . esc_html($current_page) . ' of <span class="total-pages">' . esc_html($total_pages) . '</span></span>';
            echo '</span>';

            if ($current_page < $total_pages) {
                echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $next_page, $base_url)) . '"><span class="screen-reader-text">Next page</span><span aria-hidden="true">›</span></a> ';
                echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '"><span class="screen-reader-text">Last page</span><span aria-hidden="true">»</span></a>';
            } else {
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span> ';
                echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
            }

            echo '</span></div></div><hr>';
        }
        ?>
        <?php if (!empty($all_reports)) : ?>
            <form method="post" style="margin-top: 1em;">
                <?php wp_nonce_field('bulk_report_action'); ?>
                <input type="submit" name="combine_all_reports" class="button" value="Combine All Reports">
                <input type="submit" name="delete_all_reports" class="button" value="Delete All Reports" onclick="return confirm('Are you sure you want to delete all reports?');">
            </form>
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
        $cached_sites = get_sites(['number' => 1000]);
        set_transient('uic_all_sites', $cached_sites, 5 * MINUTE_IN_SECONDS);
    }
    return $cached_sites;
}

/**
 * Internal helper to gather link scan results.
 *
 * @return array
 */
function gather_link_scan_results()
{
    $results = [];
    $global_seen_links = [];

    // Paginated loop for posts using WP_Query
    $paged = 1;
    $args = [
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'paged'          => $paged,
    ];

    $query = new WP_Query($args);
    while ($query->have_posts()) {
        foreach ($query->posts as $post) {
            $content = $post->post_content;
            preg_match_all('/href=[\'"]([^\'"]+)[\'"]/i', $content, $matches);

            foreach ($matches[1] as $link) {
                $normalized = trim($link);
                if (preg_match('/\.pdf(\?.*)?$/i', $normalized)) {
                    $link_type = 'PDF';
                } elseif (strpos($normalized, 'box.com') !== false) {
                    $link_type = 'Box';
                } else {
                    continue;
                }
                if (!isset($global_seen_links[$normalized])) {
                    $global_seen_links[$normalized] = true;
                    $results[] = [
                        'Link Type'     => $link_type,
                        'Location Type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name : ucfirst($post->post_type),
                        'Title'         => get_the_title($post),
                        'Link'          => $normalized,
                        'URL'           => get_permalink($post),
                    ];
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
                                } elseif (strpos($normalized, 'box.com') !== false) {
                                    $link_type = 'Box';
                                } else {
                                    continue;
                                }
                                if (!isset($global_seen_links[$normalized])) {
                                    $global_seen_links[$normalized] = true;
                                    $results[] = [
                                        'Link Type'     => $link_type,
                                        'Location Type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name . ' (ACF Field)' : ucfirst($post->post_type) . ' (ACF Field)',
                                        'Title'         => get_the_title($post) . " (Field: $field_key)",
                                        'Link'          => $normalized,
                                        'URL'           => get_permalink($post),
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        $paged++;
        $args['paged'] = $paged;
        $query = new WP_Query($args);
    }
    wp_reset_postdata();

    // Search menu items for matching links
    $menus = wp_get_nav_menus();
    $seen_links = [];

    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu);
        if (!empty($items)) {
            foreach ($items as $item) {
                $link = $item->url;
                $normalized = trim($link);
                if (preg_match('/\.pdf(\?.*)?$/i', $normalized)) {
                    $link_type = 'PDF';
                } elseif (strpos($normalized, 'box.com') !== false) {
                    $link_type = 'Box';
                } else {
                    continue;
                }
                if (!isset($seen_links[$normalized])) {
                    $seen_links[$normalized] = true;
                    $results[] = [
                        'Link Type'     => $link_type,
                        'Location Type' => 'Menu',
                        'Title'         => $item->title,
                        'Link'          => $normalized,
                        'URL'           => '',
                    ];
                }
            }
        }
    }

    return $results;
}

/**
 * Scan posts and menus for PDF and Box links and generate a CSV report.
 *
 * @return string URL to the generated CSV file
 */
function scan_links_and_generate_csv()
{
    $results = gather_link_scan_results();

    // Get current site details for naming the CSV
    $site = get_blog_details();
    error_log("Scanning site: " . $site->blogname . " (ID: " . get_current_blog_id() . ")");

    $upload_dir = wp_upload_dir();
    $site_slug = sanitize_title($site->blogname);
    $file_name = $site_slug . '-' . time() . '.csv';
    $file_path = trailingslashit($upload_dir['basedir']) . $file_name;
    $file_url = trailingslashit($upload_dir['baseurl']) . $file_name;

    // Write results to CSV
    $f = fopen($file_path, 'w');
    // Write headers with 'Link Type' as the first column
    fputcsv($f, ['Link Type', 'Location Type', 'Title', 'Link', 'URL']);

    if (!empty($results)) {
        foreach ($results as $row) {
            fputcsv($f, $row);
        }
    }

    fclose($f);

    error_log("CSV generated: " . $file_path);

    return $file_url;
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

// Schedule scans for all sites
function uic_schedule_site_scans() {
    $sites = uic_get_all_sites_cached();
    foreach ($sites as $site) {
        if (!wp_next_scheduled('uic_scan_site_links_event', [$site->blog_id])) {
            wp_schedule_single_event(time() + rand(0, 300), 'uic_scan_site_links_event', [$site->blog_id]);
        }
    }
}

add_action('uic_scan_site_links_event', 'uic_handle_site_scan');
function uic_handle_site_scan($site_id) {
    switch_to_blog($site_id);
    scan_links_and_generate_csv();
    restore_current_blog();
}

/**
 * Scan for PDF and Box links, generate a combined CSV, and return the download URL.
 */
function scan_links_and_generate_combined_csv() {
    $results = gather_link_scan_results();

    $site = get_blog_details();
    $upload_dir = wp_upload_dir();
    $site_slug = sanitize_title($site->blogname);
    $file_name = $site_slug . '-' . time() . '.csv';
    $file_path = trailingslashit($upload_dir['basedir']) . $file_name;
    $file_url = trailingslashit($upload_dir['baseurl']) . $file_name;

    $f = fopen($file_path, 'w');
    // Write headers with 'Link Type' as the first column
    fputcsv($f, ['Link Type', 'Location Type', 'Title', 'Link', 'URL']);

    foreach ($results as $row) {
        fputcsv($f, $row);
    }

    fclose($f);
    error_log("Combined CSV generated: " . $file_path);
    return $file_url;
}