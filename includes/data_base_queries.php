<?php

// Create a custom database table for storing logs
function wp_activity_logger_install(): void {
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

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
}

// Record user activities
function wp_activity_logger_record_activity(string $activity): void {
  if (!is_user_logged_in()) {
    return;
  }

  global $wpdb;
  $table_name = $wpdb->prefix . 'activity_logs';

  $user_id = get_current_user_id();
  $ip_address = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'Unknown');

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