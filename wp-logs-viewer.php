<?php
/*
Plugin Name: WP Activity Logger
Plugin URI:
Description: A plugin to record all user activities and display them in a custom admin page.
Version: 1.1
Author: Dmytro Kovalenko
Author URI: https://dmytro-kovalenko.com
License: GPL2
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
  exit;
}

class WPActivityLogger
{
  public function __construct()
  {
    require_once __DIR__ . '/includes/admin_show_page.php';
    require_once __DIR__ . '/includes/data_base_queries.php';

    // Register activation hook
    register_activation_hook(__FILE__, [$this, 'install']);

    // Add WordPress actions
    $this->register_hooks();
  }

  public function install(): void
  {
    wp_activity_logger_install();
  }

  private function register_hooks(): void
  {
    // Log user login
    add_action('wp_login', [$this, 'log_login']);

    // Log user logout
    add_action('wp_logout', [$this, 'log_logout']);

    // Log post updates
    add_action('post_updated', [$this, 'log_post_update'], 10, 3);

    // Log new post creation
    add_action('wp_insert_post', [$this, 'log_post_creation'], 10, 3);

    // Log post move to trash
    add_action('wp_trash_post', [$this, 'log_post_trash']);

    // Log post permanent deletion
    add_action('before_delete_post', [$this, 'log_post_deletion']);

    // Log plugin activation
    add_action('activated_plugin', [$this, 'log_plugin_activation']);

    // Log plugin deactivation
    add_action('deactivated_plugin', [$this, 'log_plugin_deactivation']);

    // Log plugin deletion
    add_action('upgrader_process_complete', [$this, 'log_plugin_deletion'], 10, 2);

    // Add custom admin menu
    add_action('admin_menu', [$this, 'add_admin_menu']);
  }

  public function log_login(string $user_login): void
  {
    $this->record_activity("User '$user_login' logged in.");
  }

  public function log_logout(): void
  {
    $current_user = wp_get_current_user();
    $this->record_activity("User '{$current_user->user_login}' logged out.");
  }

  public function log_post_update(int $post_id, object $post_after, object $post_before): void
  {
    $current_user = wp_get_current_user();
    $changes = [];

    if ($post_before->post_title !== $post_after->post_title) {
      $changes[] = "Title changed from '{$post_before->post_title}' to '{$post_after->post_title}'";
    }
    if ($post_before->post_content !== $post_after->post_content) {
      $changes[] = "Content updated.";
    }

    if (!empty($changes)) {
      $activity = sprintf(
        "User '%s' updated post ID %d (%s). Changes: %s",
        $current_user->user_login,
        $post_id,
        get_permalink($post_id),
        implode(', ', $changes)
      );
      $this->record_activity($activity);
    }
  }

  public function log_post_creation(int $post_id, WP_Post $post, bool $update): void
  {
    if ($update) {
      return;
    }
    $current_user = wp_get_current_user();
    $activity = sprintf(
      "User '%s' created a new post ID %d (%s) with the title '%s'.",
      $current_user->user_login,
      $post_id,
      get_permalink($post_id),
      $post->post_title
    );
    $this->record_activity($activity);
  }

  public function log_post_trash(int $post_id): void
  {
    $current_user = wp_get_current_user();
    $post = get_post($post_id);

    if ($post) {
      $activity = sprintf(
        "User '%s' moved post ID %d with the title '%s' to the trash.",
        $current_user->user_login,
        $post_id,
        $post->post_title
      );
      $this->record_activity($activity);
    }
  }

  public function log_post_deletion(int $post_id): void
  {
    $current_user = wp_get_current_user();
    $post = get_post($post_id);

    if ($post && $post->post_status !== 'trash') {
      $permalink = get_permalink($post_id) ?: home_url("/?p={$post_id}");
      $activity = sprintf(
        "User '%s' permanently deleted post ID %d (%s) with the title '%s'.",
        $current_user->user_login,
        $post_id,
        $permalink,
        $post->post_title
      );
      $this->record_activity($activity);
    }
  }

  public function log_plugin_activation(string $plugin): void
  {
    $current_user = wp_get_current_user();
    $plugin_name = plugin_basename($plugin);
    $this->record_activity("User '{$current_user->user_login}' activated the plugin '{$plugin_name}'.");
  }

  public function log_plugin_deactivation(string $plugin): void
  {
    $current_user = wp_get_current_user();
    $plugin_name = plugin_basename($plugin);
    $this->record_activity("User '{$current_user->user_login}' deactivated the plugin '{$plugin_name}'.");
  }

  public function log_plugin_deletion(object $upgrader, array $options): void
  {
    if ($options['type'] === 'plugin' && $options['action'] === 'delete') {
      $current_user = wp_get_current_user();
      $deleted_plugins = isset($options['plugins']) ? implode(', ', $options['plugins']) : 'Unknown plugins';
      $this->record_activity("User '{$current_user->user_login}' deleted the plugin(s): '{$deleted_plugins}'.");
    }
  }

  public function add_admin_menu(): void
  {
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

  private function record_activity(string $message): void
  {
    wp_activity_logger_record_activity($message);
  }
}

// Initialize the plugin
new WPActivityLogger();