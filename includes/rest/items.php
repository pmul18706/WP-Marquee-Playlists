<?php
if (!defined('ABSPATH')) exit;

function wmp2_rest_items_routes(){
  register_rest_route('wmp/v2', '/playlists/(?P<id>\d+)/items', [
    [
      'methods' => 'GET',
      'permission_callback' => function($req){ return wmp2_require_role(intval($req['id']), 'viewer'); },
      'callback' => 'wmp2_rest_list_items',
    ],
    [
      'methods' => 'POST',
      'permission_callback' => function($req){ return wmp2_require_role(intval($req['id']), 'editor'); },
      'callback' => 'wmp2_rest_add_item',
    ],
  ]);

  register_rest_route('wmp/v2', '/items/(?P<item_id>\d+)', [
    [
      'methods' => 'PATCH',
      'permission_callback' => function($req){
        $item = wmp2_db_get_item(intval($req['item_id']));
        return $item ? wmp2_require_role(intval($item['playlist_id']), 'editor') : false;
      },
      'callback' => 'wmp2_rest_update_item',
    ],
    [
      'methods' => 'DELETE',
      'permission_callback' => function($req){
        $item = wmp2_db_get_item(intval($req['item_id']));
        return $item ? wmp2_require_role(intval($item['playlist_id']), 'editor') : false;
      },
      'callback' => 'wmp2_rest_delete_item',
    ],
  ]);

  register_rest_route('wmp/v2', '/playlists/(?P<id>\d+)/items/reorder', [
    [
      'methods' => 'POST',
      'permission_callback' => function($req){ return wmp2_require_role(intval($req['id']), 'editor'); },
      'callback' => 'wmp2_rest_reorder_items',
    ],
  ]);
}

function wmp2_db_get_item($item_id){
  global $wpdb;
  $items = $wpdb->prefix . 'wmp_items';
  return $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE id=%d", $item_id), ARRAY_A);
}

function wmp2_db_list_items($playlist_id){
  global $wpdb;
  $items = $wpdb->prefix . 'wmp_items';

  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $items WHERE playlist_id=%d ORDER BY sort_order ASC, id ASC
  ", $playlist_id), ARRAY_A);

  foreach ($rows as &$r){
    $r['windows'] = $r['windows_json'] ? json_decode($r['windows_json'], true) : [];
    $r['meta']    = $r['meta_json'] ? json_decode($r['meta_json'], true) : [];
    unset($r['windows_json'], $r['meta_json']);
  }
  return $rows;
}

function wmp2_rest_list_items(WP_REST_Request $req){
  $pid = intval($req['id']);
  return rest_ensure_response(wmp2_db_list_items($pid));
}

function wmp2_rest_add_item(WP_REST_Request $req){
  $pid = intval($req['id']);
  $body = $req->get_json_params();
  if (!is_array($body)) $body = [];

  $item_type = sanitize_text_field($body['item_type'] ?? 'media');
  $ref_id = isset($body['ref_id']) ? intval($body['ref_id']) : null;

  if (!in_array($item_type, ['media','slide','fixed','rule'], true)){
    return new WP_Error('bad_type', 'Invalid item_type', ['status'=>400]);
  }

  global $wpdb;
  $items = $wpdb->prefix . 'wmp_items';
  $max = intval($wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM $items WHERE playlist_id=%d", $pid)));
  $order = $max + 10;

  $windows = isset($body['windows']) ? $body['windows'] : [];
  $meta    = isset($body['meta']) ? $body['meta'] : [];

  $wpdb->insert($items, [
    'playlist_id' => $pid,
    'item_type' => $item_type,
    'ref_id' => $ref_id,
    'sort_order' => $order,
    'start_at' => !empty($body['start_at']) ? sanitize_text_field($body['start_at']) : null,
    'end_at' => !empty($body['end_at']) ? sanitize_text_field($body['end_at']) : null,
    'duration_sec' => isset($body['duration_sec']) ? intval($body['duration_sec']) : 0,
    'windows_json' => wp_json_encode($windows),
    'meta_json' => wp_json_encode($meta),
  ]);

  return rest_ensure_response(wmp2_db_list_items($pid));
}

function wmp2_rest_update_item(WP_REST_Request $req){
  $item_id = intval($req['item_id']);
  $item = wmp2_db_get_item($item_id);
  if (!$item) return new WP_Error('not_found', 'Item not found', ['status'=>404]);

  $body = $req->get_json_params();
  if (!is_array($body)) $body = [];

  $fields = [];
  if (array_key_exists('start_at', $body)) $fields['start_at'] = $body['start_at'] ? sanitize_text_field($body['start_at']) : null;
  if (array_key_exists('end_at', $body))   $fields['end_at']   = $body['end_at'] ? sanitize_text_field($body['end_at']) : null;
  if (array_key_exists('duration_sec', $body)) $fields['duration_sec'] = intval($body['duration_sec']);
  if (array_key_exists('windows', $body))  $fields['windows_json'] = wp_json_encode($body['windows']);
  if (array_key_exists('meta', $body))     $fields['meta_json'] = wp_json_encode($body['meta']);

  if (!empty($fields)){
    global $wpdb;
    $items = $wpdb->prefix . 'wmp_items';
    $wpdb->update($items, $fields, ['id'=>$item_id]);
  }

  $pid = intval($item['playlist_id']);
  return rest_ensure_response(wmp2_db_list_items($pid));
}

function wmp2_rest_delete_item(WP_REST_Request $req){
  $item_id = intval($req['item_id']);
  $item = wmp2_db_get_item($item_id);
  if (!$item) return new WP_Error('not_found', 'Item not found', ['status'=>404]);

  global $wpdb;
  $items = $wpdb->prefix . 'wmp_items';
  $wpdb->delete($items, ['id'=>$item_id]);

  $pid = intval($item['playlist_id']);
  return rest_ensure_response(wmp2_db_list_items($pid));
}

function wmp2_rest_reorder_items(WP_REST_Request $req){
  $pid = intval($req['id']);
  $body = $req->get_json_params();
  $order = $body['order'] ?? null;

  if (!is_array($order)) return new WP_Error('bad_order', 'order must be an array of item IDs', ['status'=>400]);

  global $wpdb;
  $items = $wpdb->prefix . 'wmp_items';

  $pos = 10;
  foreach ($order as $item_id){
    $wpdb->update($items, ['sort_order'=>$pos], ['id'=>intval($item_id), 'playlist_id'=>$pid]);
    $pos += 10;
  }

  return rest_ensure_response(wmp2_db_list_items($pid));
}
