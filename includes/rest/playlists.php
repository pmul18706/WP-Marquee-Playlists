<?php
if (!defined('ABSPATH')) exit;

function wmp2_rest_playlists_routes(){
  register_rest_route('wmp/v2', '/playlists', [
    [
      'methods' => 'GET',
      'permission_callback' => function(){ return is_user_logged_in(); },
      'callback' => 'wmp2_rest_list_playlists',
    ],
    [
      'methods' => 'POST',
      'permission_callback' => function(){ return is_user_logged_in(); },
      'callback' => 'wmp2_rest_create_playlist',
    ],
  ]);

  register_rest_route('wmp/v2', '/playlists/(?P<id>\d+)', [
    [
      'methods' => 'GET',
      'permission_callback' => function($req){
        return wmp2_require_role(intval($req['id']), 'viewer');
      },
      'callback' => 'wmp2_rest_get_playlist',
    ],
    [
      'methods' => 'PATCH',
      'permission_callback' => function($req){
        return wmp2_require_role(intval($req['id']), 'editor');
      },
      'callback' => 'wmp2_rest_update_playlist',
    ],
  ]);
}

function wmp2_rest_list_playlists(){
  $user_id = get_current_user_id();
  global $wpdb;
  $members = $wpdb->prefix . 'wmp_members';

  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT playlist_id, role FROM $members WHERE user_id=%d ORDER BY created_at DESC
  ", $user_id), ARRAY_A);

  $out = [];
  foreach ($rows as $r){
    $pid = intval($r['playlist_id']);
    $post = get_post($pid);
    if (!$post || $post->post_type !== 'wmp_playlist') continue;

    $out[] = [
      'id' => $pid,
      'name' => $post->post_title,
      'role' => $r['role'],
      'token' => get_post_meta($pid, '_wmp_token', true),
      'options' => get_post_meta($pid, '_wmp_options', true),
    ];
  }

  return rest_ensure_response($out);
}

function wmp2_rest_create_playlist(WP_REST_Request $req){
  $name = sanitize_text_field($req->get_param('name') ?: 'Untitled Playlist');

  $pid = wp_insert_post([
    'post_type' => 'wmp_playlist',
    'post_title' => $name,
    'post_status' => 'publish',
  ], true);

  if (is_wp_error($pid)) {
    return new WP_Error('create_failed', $pid->get_error_message(), ['status'=>400]);
  }

  $token = substr(str_replace(['=','/','+'], '', base64_encode(random_bytes(9))), 0, 12);
  update_post_meta($pid, '_wmp_token', $token);

  $defaults = [
    'mode' => 'marquee',
    'direction' => 'left',
    'speed' => 60,
    'pause_on_hover' => 1,
    'num_windows' => 1,
    'windows' => [['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain']],
    'stage_bg' => '',
    'item_gap' => 18,
    'location' => 'Wilkes-Barre, PA',
    'pattern' => [],
  ];
  update_post_meta($pid, '_wmp_options', $defaults);

  global $wpdb;
  $members = $wpdb->prefix . 'wmp_members';
  $wpdb->insert($members, [
    'playlist_id' => $pid,
    'user_id' => get_current_user_id(),
    'role' => 'owner',
    'added_by' => get_current_user_id(),
  ]);

  return rest_ensure_response([
    'id' => $pid,
    'name' => $name,
    'token' => $token,
    'role' => 'owner',
    'options' => $defaults,
  ]);
}

function wmp2_rest_get_playlist(WP_REST_Request $req){
  $pid = intval($req['id']);
  $post = get_post($pid);
  if (!$post || $post->post_type !== 'wmp_playlist') {
    return new WP_Error('not_found', 'Playlist not found', ['status'=>404]);
  }

  return rest_ensure_response([
    'id' => $pid,
    'name' => $post->post_title,
    'token' => get_post_meta($pid, '_wmp_token', true),
    'options' => get_post_meta($pid, '_wmp_options', true),
    'role' => wmp2_get_user_role_for_playlist($pid),
  ]);
}

function wmp2_rest_update_playlist(WP_REST_Request $req){
  $pid = intval($req['id']);

  $patch = $req->get_json_params();
  if (!is_array($patch)) $patch = [];

  if (isset($patch['name'])){
    wp_update_post(['ID'=>$pid, 'post_title'=>sanitize_text_field($patch['name'])]);
  }

  $opts = get_post_meta($pid, '_wmp_options', true);
  if (!is_array($opts)) $opts = [];
  if (isset($patch['options']) && is_array($patch['options'])){
    $opts = array_merge($opts, $patch['options']);
  }
  update_post_meta($pid, '_wmp_options', $opts);

  return wmp2_rest_get_playlist($req);
}
