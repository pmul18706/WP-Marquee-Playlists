<?php
if (!defined('ABSPATH')) exit;

function wmp2_install_db(){
  global $wpdb;
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  $charset = $wpdb->get_charset_collate();

  $members = $wpdb->prefix . 'wmp_members';
  $items   = $wpdb->prefix . 'wmp_items';
  $state   = $wpdb->prefix . 'wmp_rotation_state';

  $sql1 = "CREATE TABLE $members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    added_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY playlist_id (playlist_id),
    KEY user_id (user_id),
    UNIQUE KEY uniq_playlist_user (playlist_id, user_id)
  ) $charset;";

  $sql2 = "CREATE TABLE $items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(20) NOT NULL,         /* media | slide | fixed | rule */
    ref_id BIGINT UNSIGNED NULL,            /* attachment_id or slide post_id */
    sort_order INT NOT NULL DEFAULT 0,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    duration_sec INT NOT NULL DEFAULT 0,
    windows_json LONGTEXT NULL,
    meta_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY playlist_id (playlist_id),
    KEY sort_order (sort_order)
  ) $charset;";

  $sql3 = "CREATE TABLE $state (
    playlist_id BIGINT UNSIGNED NOT NULL,
    state_json LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (playlist_id)
  ) $charset;";

  dbDelta($sql1);
  dbDelta($sql2);
  dbDelta($sql3);
}
