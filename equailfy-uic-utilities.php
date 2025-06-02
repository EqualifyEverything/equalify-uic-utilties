<?php
/*
Plugin Name: Equalify + UIC Network Utilities
Description: Scans content for PDF and Box.com links and exports CSV. Network-enabled.
Version: 1.3
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
    // Ensure this is accessed only from the Network Admin dashboard
    if (!is_network_admin()) {
        echo '<div class="notice notice-error"><p>This tool can only be used from the Network Admin dashboard.</p></div>';
        return;
    }

    // Removed setcookie call here as per instructions

    // Handle deletion of reports via nonce-verified request
    if (isset($_GET['delete_report']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'delete_report')) {
        $target_file = basename($_GET['delete_report']);
        $deleted = false;

        // Search all public sites for the file and delete it if found
        foreach (get_sites(['public' => 1]) as $site) {
            switch_to_blog($site->blog_id);
            $upload_dir = wp_upload_dir();
            $file_path = trailingslashit($upload_dir['basedir']) . $target_file;

            if (file_exists($file_path)) {
                error_log("Deleting from site ID: " . $site->blog_id . ", file: " . $file_path);
                unlink($file_path);
                $deleted = true;
                restore_current_blog();
                break;
            }

            restore_current_blog();
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
                $sites = get_sites(['public' => 1]);
                $found_any_files = false;
                foreach ($sites as $site) {
                    switch_to_blog($site->blog_id);
                    $site_name = get_blog_details($site->blog_id)->blogname;
                    $site_id = $site->blog_id;
                    $files = array_filter(
                        glob(trailingslashit(wp_upload_dir()['basedir']) . '*.csv') ?: [],
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
                    restore_current_blog();
                }
                fclose($output);
                echo '<div class="notice notice-success"><p>Reports sucessfully combined. <a href="' . esc_url($merged_csv_url) . '" download>Download CSV</a></p></div>';
            }
            if (isset($_POST['delete_all_reports'])) {
                foreach (get_sites(['public' => 1]) as $site) {
                    switch_to_blog($site->blog_id);
                    $files = glob(trailingslashit(wp_upload_dir()['basedir']) . '*.csv') ?: [];
                    foreach ($files as $file) {
                        unlink($file);
                    }
                    restore_current_blog();
                }
                echo '<div class="notice notice-success"><p>All reports deleted.</p></div>';
            }
        } elseif ((isset($_POST['find_pdf_links']) || isset($_POST['find_box_links'])) && check_admin_referer('run_link_scan')) {
            switch_to_blog($selected_site);
            if (isset($_POST['find_pdf_links'])) {
                $download_url = scan_links_and_generate_csv('pdf');
            } elseif (isset($_POST['find_box_links'])) {
                $download_url = scan_links_and_generate_csv('box');
            }
            restore_current_blog();
        }
    }

    // Retrieve all public sites for dropdown and report listing
    $sites = get_sites(['public' => 1]);
    ?>
    <div class="wrap">
        <h1>UIC + Equalify Utilities</h1>
        <h2>Generate Link Reports</h2>

        <!-- Site selection and scan form -->
        <form method="post" action="" name="link_report_form">
            <label for="site_id">Select Site:</label>
            <select name="site_id" id="site_id">
                <?php foreach ($sites as $site) {
                    $blog_details = get_blog_details($site->blog_id);
                    $selected = ((int) $site->blog_id === (int) $selected_site) ? 'selected' : '';
                    echo "<option value='{$site->blog_id}' $selected>" . esc_html($blog_details->blogname) . "</option>";
                } ?>
            </select>
            <?php wp_nonce_field('run_link_scan'); ?>
            <br><br>
            <input type="submit" name="find_pdf_links" class="button button-primary" value="Find PDF Links">
            <input type="submit" name="find_box_links" class="button button-secondary" value="Find Box Links">
        </form>

        <h3>Reports</h3>
        <ul>
        <?php
        // Aggregate all reports from all public sites
        $all_reports = [];

        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            $upload_dir = wp_upload_dir();
            $files = array_filter(
                glob(trailingslashit($upload_dir['basedir']) . '*.csv') ?: [],
                fn($file) => strpos(basename($file), 'combined-link-reports.csv') === false
            );

            foreach ($files as $file_path) {
                $all_reports[] = [
                    'site'       => get_blog_details($site->blog_id)->blogname,
                    'file_name'  => basename($file_path),
                    'file_url'   => trailingslashit($upload_dir['baseurl']) . basename($file_path),
                    'delete_url' => wp_nonce_url(add_query_arg(['delete_report' => basename($file_path)]), 'delete_report'),
                ];
            }
            restore_current_blog();
        }
        
        // Display each report with download and delete links
        foreach ($all_reports as $report) {
            echo '<li>' . esc_html($report['file_name']) . ' (' . esc_html($report['site']) . ') - <a href="' . esc_url($report['file_url']) . '">Download</a> | <a href="' . esc_url($report['delete_url']) . '">Delete</a></li>';
        }
        ?>
        </ul>
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
 * Scan posts and menus for links of a given type and generate a CSV report
 *
 * @param string $type 'pdf' or 'box'
 * @return string URL to the generated CSV file
 */
function scan_links_and_generate_csv($type)
{
    $results = [];

    // Get current site details for naming the CSV
    $site = get_blog_details();
    error_log("Scanning site: " . $site->blogname . " (ID: " . get_current_blog_id() . ")");

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

            // Extract all href links from post content
            preg_match_all('/href=[\'"]([^\'"]+)[\'"]/i', $content, $matches);

            foreach ($matches[1] as $link) {
                $matches_type = ($type === 'pdf' && preg_match('/\.pdf(\?.*)?$/i', $link)) ||
                                ($type === 'box' && strpos($link, 'box.com') !== false);

                if ($matches_type) {
                    $results[] = [
                        'Location Type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name : ucfirst($post->post_type),
                        'Title'         => get_the_title($post),
                        'Link'          => $link,
                        'URL'           => get_permalink($post),
                    ];
                }
            }

            // Check ACF fields for matching links
            if (function_exists('get_fields')) {
                $fields = get_fields($post->ID);
                if ($fields && is_array($fields)) {
                    foreach ($fields as $field_key => $field_value) {
                        if (is_string($field_value)) {
                            preg_match_all('/https?:\/\/[^\s"\']+/i', $field_value, $acf_matches);
                            foreach ($acf_matches[0] as $link) {
                                $matches_type = ($type === 'pdf' && preg_match('/\.pdf(\?.*)?$/i', $link)) ||
                                                ($type === 'box' && strpos($link, 'box.com') !== false);
                                if ($matches_type) {
                                    $results[] = [
                                        'Location Type' => ($obj = get_post_type_object($post->post_type)) ? $obj->labels->singular_name . ' (ACF Field)' : ucfirst($post->post_type) . ' (ACF Field)',
                                        'Title'         => get_the_title($post) . " (Field: $field_key)",
                                        'Link'          => $link,
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
    error_log("Menu count found: " . count($menus));

    foreach ($menus as $menu) {
        $items = wp_get_nav_menu_items($menu);

        if (!empty($items)) {
            foreach ($items as $item) {
                $link = $item->url;
                $matches_type = ($type === 'pdf' && preg_match('/\.pdf(\?.*)?$/i', $link)) ||
                                ($type === 'box' && strpos($link, 'box.com') !== false);

                if ($matches_type) {
                    $results[] = [
                        'Location Type' => 'Menu',
                        'Title'         => $item->title,
                        'Link'          => $link,
                        'URL'           => '',
                    ];
                }
            }
        }
    }

    // Prepare CSV file path and URL
    $upload_dir = wp_upload_dir();
    $site_slug = sanitize_title($site->blogname);
    $file_name = $site_slug . '-' . $type . '-' . time() . '.csv';
    $file_path = trailingslashit($upload_dir['basedir']) . $file_name;
    $file_url = trailingslashit($upload_dir['baseurl']) . $file_name;

    // Write results to CSV
    $f = fopen($file_path, 'w');
    fputcsv($f, ['Location Type', 'Title', 'Link', 'URL']);

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