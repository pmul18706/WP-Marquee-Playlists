<?php
if (!defined('ABSPATH')) exit;

function wmp2_install_rotation_table() {
  global $wpdb;
  $table = $wpdb->prefix . 'wmp_rotation_state';
  $charset = $wpdb->get_charset_collate();

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $sql = "CREATE TABLE $table (
    playlist_id BIGINT(20) UNSIGNED NOT NULL,
    term_id BIGINT(20) UNSIGNED NOT NULL,
    cursor INT(11) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (playlist_id, term_id)
  ) $charset;";

  dbDelta($sql);
}

function wmp2_rotation_get_cursor($playlist_id, $term_id) {
  global $wpdb;
  $table = $wpdb->prefix . 'wmp_rotation_state';
  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT cursor FROM $table WHERE playlist_id=%d AND term_id=%d",
    $playlist_id, $term_id
  ), ARRAY_A);
  return $row ? intval($row['cursor']) : 0;
}

function wmp2_rotation_set_cursor($playlist_id, $term_id, $cursor) {
  global $wpdb;
  $table = $wpdb->prefix . 'wmp_rotation_state';
  $wpdb->replace($table, [
    'playlist_id' => intval($playlist_id),
    'term_id'     => intval($term_id),
    'cursor'      => intval($cursor),
    'updated_at'  => current_time('mysql'),
  ], ['%d','%d','%d','%s']);
}
