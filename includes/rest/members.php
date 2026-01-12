<?php
if (!defined('ABSPATH')) exit;

function wmp2_rest_members_routes(){
  register_rest_route('wmp/v2', '/playlists/(?P<id>\d+)/members', [
    [
      'methods' => 'GET',
      'permission_callback' => function($req){ return wmp2_require_role(intval($req['id']), 'viewer'); },
      'callback' => 'wmp2_rest_list_members',
    ],
    [
      'methods' => 'POST',
      'permission_callback' => function($req){ return wmp2_require_role(intval($req['id']), 'owner'); },
      'callback' => 'wmp2_rest_add_member',
    ],
  ]);
}

function wmp2_rest_list_members(WP_REST_Request $req){
  $pid = intval($req['id']);
  global $wpdb;
  $members = $wpdb->prefix . 'wmp_members';
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $members WHERE playlist_id=%d ORDER BY created_at DESC
  ", $pid), ARRAY_A);

  foreach ($rows as &$r){
    $u = get_user_by('id', intval($r['user_id']));
    $r['user'] = $u ? ['id'=>$u->ID,'display'=>$u->display_name,'email'=>$u->user_email] : null;
  }

  return rest_ensure_response($rows);
}

function wmp2_rest_add_member(WP_REST_Request $req){
  $pid = intval($req['id']);
  $email = sanitize_email($req->get_param('email') ?: '');
  $role = sanitize_text_field($req->get_param('role') ?: 'viewer');
  if (!in_array($role, ['viewer','editor','approver','owner'], true)) $role = 'viewer';

  $u = get_user_by('email', $email);
  if (!$u) return new WP_Error('no_user', 'No user with that email', ['status'=>404]);

  global $wpdb;
  $members = $wpdb->prefix . 'wmp_members';
  $wpdb->replace($members, [
    'playlist_id' => $pid,
    'user_id' => $u->ID,
    'role' => $role,
    'added_by' => get_current_user_id(),
  ]);

  return wmp2_rest_list_members($req);
}
