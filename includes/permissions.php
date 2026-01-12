<?php
if (!defined('ABSPATH')) exit;

function wmp2_role_rank($role){
  $map = [
    'viewer' => 10,
    'editor' => 20,
    'approver' => 30,
    'owner' => 40,
  ];
  return $map[$role] ?? 0;
}

function wmp2_get_user_role_for_playlist($playlist_id, $user_id = null){
  if (!$user_id) $user_id = get_current_user_id();
  if (!$user_id) return null;

  global $wpdb;
  $members = $wpdb->prefix . 'wmp_members';
  $role = $wpdb->get_var($wpdb->prepare(
    "SELECT role FROM $members WHERE playlist_id=%d AND user_id=%d",
    $playlist_id, $user_id
  ));
  return $role ?: null;
}

function wmp2_require_role($playlist_id, $min_role){
  $role = wmp2_get_user_role_for_playlist($playlist_id);
  if (!$role) return false;
  return wmp2_role_rank($role) >= wmp2_role_rank($min_role);
}

function wmp2_is_owner($playlist_id){
  return wmp2_require_role($playlist_id, 'owner');
}
