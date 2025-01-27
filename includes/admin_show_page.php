<?php
// Display logs in a custom admin page
function wp_activity_logger_admin_page(): void {
  if (!current_user_can('manage_options')) {
    return;
  }

  global $wpdb;
  $table_name = $wpdb->prefix . 'activity_logs';

  // Handle filtering by date and username
  $start_date = sanitize_text_field($_GET['start_date'] ?? '');
  $end_date = sanitize_text_field($_GET['end_date'] ?? '');
  $username = sanitize_text_field($_GET['username'] ?? '');
  $date_filter = '';
  $user_filter = '';

  if ($start_date && $end_date) {
    $date_filter = $wpdb->prepare("AND DATE(created_at) BETWEEN %s AND %s", $start_date, $end_date);
  } elseif ($start_date) {
    $date_filter = $wpdb->prepare("AND DATE(created_at) >= %s", $start_date);
  } elseif ($end_date) {
    $date_filter = $wpdb->prepare("AND DATE(created_at) <= %s", $end_date);
  }

  if ($username) {
    $user = get_user_by('login', $username);
    $user_filter = $user ? $wpdb->prepare("AND user_id = %d", $user->ID) : "AND user_id = 0";
  }

  // Pagination setup
  $logs_per_page = 20;
  $current_page = max(1, intval($_GET['paged'] ?? 1));
  $offset = ($current_page - 1) * $logs_per_page;

  // Fetch logs and total count
  $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE 1=1 $date_filter $user_filter");
  $logs = $wpdb->get_results(
    $wpdb->prepare(
      "SELECT * FROM $table_name WHERE 1=1 $date_filter $user_filter ORDER BY created_at DESC LIMIT %d OFFSET %d",
      $logs_per_page,
      $offset
    )
  );

  // Base URL for filter form
  $base_url = admin_url('admin.php?page=wp-activity-logs');

  echo <<<HTML
        <div class="wrap">
          <h1>Activity Logs</h1>
        
          <!-- Filter form -->
          <form method="get" action="{$base_url}">
            <input type="hidden" name="page" value="wp-activity-logs">
            <label for="start_date">Start Date:</label>
            <input type="date" id="start_date" name="start_date" value="{$start_date}">
            <label for="end_date">End Date:</label>
            <input type="date" id="end_date" name="end_date" value="{$end_date}">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" value="{$username}" placeholder="Enter username">
            <button type="submit" class="button button-primary">Filter</button>
          </form>
        
          <!-- Logs table -->
          <table class="widefat fixed">
            <thead>
              <tr>
                <th>ID</th>
                <th>User</th>
                <th>Activity</th>
                <th>IP Address</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
        HTML;
  if ($logs) {
    foreach ($logs as $log) {
      $user_info = get_userdata($log->user_id);
      $user_name = $user_info ? $user_info->user_login : 'Unknown';

      $utc_time = new DateTime($log->created_at, new DateTimeZone('UTC'));
      $mountain_time = $utc_time->setTimezone(new DateTimeZone('America/Edmonton'));
      $formatted_time = $mountain_time->format('F d, Y g:i A');

      echo <<<HTML
            <tr>
              <td>{$log->id}</td>
              <td>{$user_name}</td>
              <td>{$log->activity}</td>
              <td>{$log->ip_address}</td>
              <td>{$formatted_time}</td>
            </tr>
            HTML;
    }
  } else {
    echo '<tr><td colspan="5">No logs found.</td></tr>';
  }

  echo '</tbody></table>';

  // Pagination
  $total_pages = ceil($total_logs / $logs_per_page);
  if ($total_pages > 1) {
    echo '<div class="tablenav-pages">';
    echo paginate_links([
      'base'    => add_query_arg('paged', '%#%'),
      'format'  => '',
      'current' => $current_page,
      'total'   => $total_pages,
    ]);
    echo '</div>';
  }

  echo '</div>';
}