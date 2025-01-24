<?php
/*
Plugin Name: WP Activity Logger
Plugin URI: https://example.com
Description: A plugin to record all user activities and display them in a custom admin page.
Version: 1.1
Author: Your Name
Author URI: https://example.com
License: GPL2
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Create a custom database table for storing logs
function wp_activity_logger_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'activity_logs';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        activity TEXT NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'wp_activity_logger_install');

// Record user activities
function wp_activity_logger_record_activity($activity) {
    if (!is_user_logged_in()) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'activity_logs';

    $user_id = get_current_user_id();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

    $wpdb->insert(
        $table_name,
        [
            'user_id'    => $user_id,
            'activity'   => $activity,
            'ip_address' => $ip_address,
        ],
        [
            '%d',
            '%s',
            '%s',
        ]
    );
}

// Hook into specific WordPress actions

// Log user login
add_action('wp_login', function($user_login) {
    wp_activity_logger_record_activity("User '$user_login' logged in.");
});

// Log user logout
add_action('wp_logout', function() {
    $current_user = wp_get_current_user();
    wp_activity_logger_record_activity("User '{$current_user->user_login}' logged out.");
});

// Log post updates with details
add_action('post_updated', function($post_id, $post_after, $post_before) {
    $current_user = wp_get_current_user();
    $changes = [];

    // Check for changes in the post
    if ($post_before->post_title !== $post_after->post_title) {
        $changes[] = "Title changed from '{$post_before->post_title}' to '{$post_after->post_title}'";
    }
    if ($post_before->post_content !== $post_after->post_content) {
        $changes[] = "Content updated.";
    }
    if (empty($changes)) {
        return; // Skip logging if there are no changes
    }

    $activity = sprintf(
        "User '%s' updated post ID %d (%s). Changes: %s",
        $current_user->user_login,
        $post_id,
        get_permalink($post_id),
        implode(', ', $changes)
    );

    wp_activity_logger_record_activity($activity);
}, 10, 3);

// Log new post creation
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($update) {
        return; // Skip if it's an update (handled by `post_updated`)
    }
    $current_user = wp_get_current_user();
    $activity = sprintf(
        "User '%s' created a new post ID %d (%s) with the title '%s'.",
        $current_user->user_login,
        $post_id,
        get_permalink($post_id),
        $post->post_title
    );
    wp_activity_logger_record_activity($activity);
}, 10, 3);

// Log post move to trash
add_action('wp_trash_post', function($post_id) {
    $current_user = wp_get_current_user();
    $post = get_post($post_id);

    if (!$post) {
        return;
    }

    $activity = sprintf(
        "User '%s' moved post ID %d with the title '%s' to the trash.",
        $current_user->user_login,
        $post_id,
        $post->post_title
    );

    wp_activity_logger_record_activity($activity);
});

// Log post permanent deletion
add_action('before_delete_post', function($post_id) {
    $current_user = wp_get_current_user();
    $post = get_post($post_id);

    if (!$post || $post->post_status === 'trash') {
        return;
    }

    // Manually construct the permalink if unavailable
    $permalink = get_permalink($post_id);
    if (!$permalink) {
        $permalink = home_url("/?p={$post_id}");
    }

    $activity = sprintf(
        "User '%s' permanently deleted post ID %d (%s) with the title '%s'.",
        $current_user->user_login,
        $post_id,
        $permalink,
        $post->post_title
    );

    wp_activity_logger_record_activity($activity);
});

// Log plugin activation
add_action('activated_plugin', function($plugin) {
    $current_user = wp_get_current_user();
    $plugin_name = plugin_basename($plugin);
    wp_activity_logger_record_activity("User '{$current_user->user_login}' activated the plugin '{$plugin_name}'.");
});

// Log plugin deactivation
add_action('deactivated_plugin', function($plugin) {
    $current_user = wp_get_current_user();
    $plugin_name = plugin_basename($plugin);
    wp_activity_logger_record_activity("User '{$current_user->user_login}' deactivated the plugin '{$plugin_name}'.");
});

// Log plugin deletion
add_action('upgrader_process_complete', function($upgrader, $options) {
    if ($options['type'] === 'plugin' && $options['action'] === 'delete') {
        $current_user = wp_get_current_user();
        $deleted_plugins = isset($options['plugins']) ? implode(', ', $options['plugins']) : 'Unknown plugins';
        wp_activity_logger_record_activity("User '{$current_user->user_login}' deleted the plugin(s): '{$deleted_plugins}'.");
    }
}, 10, 2);


// Add custom admin menu
function wp_activity_logger_admin_menu() {
    add_menu_page(
        'Activity Logs',
        'Activity Logs',
        'manage_options',
        'wp-activity-logs',
        'wp_activity_logger_admin_page',
        'dashicons-list-view',
        90
    );
}
add_action('admin_menu', 'wp_activity_logger_admin_menu');

// Display logs in a custom admin page
function wp_activity_logger_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'activity_logs';

    // Handle filtering by date and username
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $username = isset($_GET['username']) ? sanitize_text_field($_GET['username']) : '';
    $date_filter = '';
    $user_filter = '';

    if (!empty($start_date) && !empty($end_date)) {
        $date_filter = $wpdb->prepare("AND DATE(created_at) BETWEEN %s AND %s", $start_date, $end_date);
    } elseif (!empty($start_date)) {
        $date_filter = $wpdb->prepare("AND DATE(created_at) >= %s", $start_date);
    } elseif (!empty($end_date)) {
        $date_filter = $wpdb->prepare("AND DATE(created_at) <= %s", $end_date);
    }

    if (!empty($username)) {
        $user = get_user_by('login', $username);
        if ($user) {
            $user_filter = $wpdb->prepare("AND user_id = %d", $user->ID);
        } else {
            $user_filter = "AND user_id = 0"; // No user found, return no results
        }
    }

    // Pagination setup
    $logs_per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $logs_per_page;

    // Get total logs and logs for the current page
    $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE 1=1 $date_filter $user_filter");
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE 1=1 $date_filter $user_filter ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $logs_per_page,
        $offset
    ));

    // Define base URL for filtering
    $base_url = admin_url('admin.php?page=wp-activity-logs');

    echo '<div class="wrap">';
    echo '<h1>Activity Logs</h1>';

    // Add a filter form for date and username
    echo '<form method="get" action="' . esc_url($base_url) . '">';
    echo '<input type="hidden" name="page" value="wp-activity-logs" />';
    echo '<label for="start_date">Start Date:</label> ';
    echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($start_date) . '" />';
    echo ' <label for="end_date">End Date:</label> ';
    echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($end_date) . '" />';
    echo ' <label for="username">Username:</label> ';
    echo '<input type="text" id="username" name="username" placeholder="Enter username" value="' . esc_attr($username) . '" />';
    echo ' <button type="submit" class="button button-primary">Filter</button>';
    echo '</form>';

    // Display the logs table
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>ID</th>';
    echo '<th>User</th>';
    echo '<th>Activity</th>';
    echo '<th>IP Address</th>';
    echo '<th>Timestamp</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    if (!empty($logs)) {
        foreach ($logs as $log) {
            $user_info = get_userdata($log->user_id);
            $user_name = $user_info ? $user_info->user_login : 'Unknown';

            // Convert UTC timestamp to Mountain Time
            $utc_time = new DateTime($log->created_at, new DateTimeZone('UTC'));
            $mountain_time = $utc_time->setTimezone(new DateTimeZone('America/Edmonton'));
            $formatted_time = $mountain_time->format('F d Y, g:i A');

            echo '<tr>';
            echo '<td>' . esc_html($log->id) . '</td>';
            echo '<td>' . esc_html($user_name) . '</td>';
            echo '<td>' . esc_html($log->activity) . '</td>';
            echo '<td>' . esc_html($log->ip_address) . '</td>';
            echo '<td>' . esc_html($formatted_time) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="5">No logs found.</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';

    // Display pagination links
    $total_pages = ceil($total_logs / $logs_per_page);
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $current_page,
            'total'   => $total_pages,
        ]);
        echo '</div></div>';
    }

    echo '</div>';
}