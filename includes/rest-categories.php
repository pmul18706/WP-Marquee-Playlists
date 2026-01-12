<?php
if (!defined('ABSPATH')) exit;

function wmp2_register_categories_routes() {

  // GET categories
  register_rest_route('wmp/v2', '/categories', [
    'methods' => 'GET',
    'permission_callback' => fn() => is_user_logged_in(),
    'callback' => function() {
      $terms = get_terms([
        'taxonomy' => 'wmp_media_cat',
        'hide_empty' => false,
      ]);
      if (is_wp_error($terms)) return rest_ensure_response([]);
      return rest_ensure_response(array_map(fn($t) => [
        'id' => $t->term_id,
        'name' => $t->name,
        'count' => intval($t->count),
      ], $terms));
    }
  ]);

  // POST create category {name}
  register_rest_route('wmp/v2', '/categories', [
    'methods' => 'POST',
    'permission_callback' => fn() => is_user_logged_in() && current_user_can('upload_files'),
    'callback' => function(\WP_REST_Request $req) {
      $name = sanitize_text_field($req->get_param('name'));
      if (!$name) return new WP_Error('bad_request', 'Missing name', ['status'=>400]);

      $r = wp_insert_term($name, 'wmp_media_cat');
      if (is_wp_error($r)) return $r;

      return rest_ensure_response([
        'id' => intval($r['term_id']),
        'name' => $name,
      ]);
    }
  ]);

  // DELETE category
  register_rest_route('wmp/v2', '/categories/(?P<id>\d+)', [
    'methods' => 'DELETE',
    'permission_callback' => fn() => is_user_logged_in() && current_user_can('upload_files'),
    'callback' => function(\WP_REST_Request $req) {
      $id = intval($req['id']);
      $r = wp_delete_term($id, 'wmp_media_cat');
      if (is_wp_error($r)) return $r;
      return rest_ensure_response(['ok'=>true]);
    }
  ]);

  // POST set attachment categories: {attachment_id, term_ids:[...]}
  register_rest_route('wmp/v2', '/media/(?P<id>\d+)/categories', [
    'methods' => 'POST',
    'permission_callback' => fn() => is_user_logged_in() && current_user_can('upload_files'),
    'callback' => function(\WP_REST_Request $req) {
      $id = intval($req['id']);
      $term_ids = $req->get_param('term_ids');
      if (!is_array($term_ids)) $term_ids = [];
      $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids), fn($n)=>$n>0)));

      wp_set_object_terms($id, $term_ids, 'wmp_media_cat', false);

      $terms = wp_get_object_terms($id, 'wmp_media_cat', ['fields'=>'ids']);
      if (is_wp_error($terms)) $terms = [];

      return rest_ensure_response(['attachment_id'=>$id, 'term_ids'=>array_map('intval',$terms)]);
    }
  ]);

// POST set slide categories: {term_ids:[...]}
register_rest_route('wmp/v2', '/slides/(?P<id>\d+)/categories', [
  'methods' => 'POST',
  'permission_callback' => fn() => is_user_logged_in() && current_user_can('edit_posts'),
  'callback' => function(\WP_REST_Request $req) {
    $id = intval($req['id']);
    $term_ids = $req->get_param('term_ids');
    if (!is_array($term_ids)) $term_ids = [];
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids), fn($n)=>$n>0)));

    wp_set_object_terms($id, $term_ids, 'wmp_media_cat', false);

    $terms = wp_get_object_terms($id, 'wmp_media_cat', ['fields'=>'ids']);
    if (is_wp_error($terms)) $terms = [];

    return rest_ensure_response(['slide_id'=>$id, 'term_ids'=>array_map('intval',$terms)]);
  }
]);

// POST set slide categories: {term_ids:[...]}
register_rest_route('wmp/v2', '/slides/(?P<id>\d+)/categories', [
  'methods' => 'POST',
  'permission_callback' => fn() => is_user_logged_in() && current_user_can('edit_posts'),
  'callback' => function(\WP_REST_Request $req) {
    $id = intval($req['id']);
    $term_ids = $req->get_param('term_ids');
    if (!is_array($term_ids)) $term_ids = [];
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids), fn($n)=>$n>0)));

    wp_set_object_terms($id, $term_ids, 'wmp_media_cat', false);

    $terms = wp_get_object_terms($id, 'wmp_media_cat', ['fields'=>'ids']);
    if (is_wp_error($terms)) $terms = [];

    return rest_ensure_response(['slide_id'=>$id, 'term_ids'=>array_map('intval',$terms)]);
  }
]);


  // GET attachments in a category
  register_rest_route('wmp/v2', '/categories/(?P<id>\d+)/media', [
    'methods' => 'GET',
    'permission_callback' => fn() => is_user_logged_in(),
    'callback' => function(\WP_REST_Request $req) {
      $term_id = intval($req['id']);

      $q = new WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 200,
        'orderby' => 'ID',
        'order' => 'ASC',
        'tax_query' => [[
          'taxonomy' => 'wmp_media_cat',
          'field' => 'term_id',
          'terms' => [$term_id],
        ]]
      ]);

      $out = [];
      foreach ($q->posts as $p) {
        $url = wp_get_attachment_url($p->ID);
        if (!$url) continue;
        $mime = get_post_mime_type($p->ID);
        $kind = (is_string($mime) && str_starts_with($mime, 'video/')) ? 'video' : 'image';
        $out[] = ['id'=>$p->ID, 'url'=>$url, 'kind'=>$kind, 'mime'=>$mime ?: ''];
      }
      return rest_ensure_response($out);
    }
  ]);
}
