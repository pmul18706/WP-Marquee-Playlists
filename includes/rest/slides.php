<?php
if (!defined('ABSPATH')) exit;

function wmp2_rest_slides_routes(){
  register_rest_route('wmp/v2', '/slides', [
    [
      'methods' => 'POST',
      'permission_callback' => function(){ return is_user_logged_in(); },
      'callback' => 'wmp2_rest_create_slide',
    ],
  ]);

  register_rest_route('wmp/v2', '/slides/(?P<id>\d+)', [
    [
      'methods' => 'GET',
      'permission_callback' => function(){ return is_user_logged_in(); },
      'callback' => 'wmp2_rest_get_slide',
    ],
    [
      'methods' => 'PATCH',
      'permission_callback' => function(){ return is_user_logged_in(); },
      'callback' => 'wmp2_rest_update_slide',
    ],
  ]);
}

function wmp2_slide_allowed_html(){
  return [
    'div' => ['class'=>true,'style'=>true],
    'span' => ['class'=>true,'style'=>true],
    'p' => ['class'=>true,'style'=>true],
    'br' => [],
    'strong' => [],
    'em' => [],
    'ul' => ['class'=>true],
    'ol' => ['class'=>true],
    'li' => ['class'=>true],
    'h1' => ['class'=>true,'style'=>true],
    'h2' => ['class'=>true,'style'=>true],
    'h3' => ['class'=>true,'style'=>true],
  ];
}

function wmp2_rest_create_slide(WP_REST_Request $req){
  $title = sanitize_text_field($req->get_param('title') ?: 'New Slide');
  $content = $req->get_param('content_html') ?: '';
  $content = wp_kses($content, wmp2_slide_allowed_html());

  $sid = wp_insert_post([
    'post_type' => 'wmp_slide',
    'post_title' => $title,
    'post_content' => $content,
    'post_status' => 'publish',
  ], true);

  if (is_wp_error($sid)) return new WP_Error('create_failed', $sid->get_error_message(), ['status'=>400]);

  $style = $req->get_param('style') ?: [];
  if (!is_array($style)) $style = [];
  update_post_meta($sid, '_wmp_slide_style', $style);

  return rest_ensure_response(['id'=>$sid,'title'=>$title,'content_html'=>$content,'style'=>$style]);
}

function wmp2_rest_get_slide(WP_REST_Request $req){
  $sid = intval($req['id']);
  $post = get_post($sid);
  if (!$post || $post->post_type !== 'wmp_slide') return new WP_Error('not_found','Slide not found',['status'=>404]);

  return rest_ensure_response([
    'id' => $sid,
    'title' => $post->post_title,
    'content_html' => $post->post_content,
    'style' => get_post_meta($sid, '_wmp_slide_style', true),
  ]);
}

function wmp2_rest_update_slide(WP_REST_Request $req){
  $sid = intval($req['id']);
  $post = get_post($sid);
  if (!$post || $post->post_type !== 'wmp_slide') return new WP_Error('not_found','Slide not found',['status'=>404]);

  $patch = $req->get_json_params();
  if (!is_array($patch)) $patch = [];

  if (isset($patch['title'])){
    wp_update_post(['ID'=>$sid,'post_title'=>sanitize_text_field($patch['title'])]);
  }
  if (isset($patch['content_html'])){
    $content = wp_kses($patch['content_html'], wmp2_slide_allowed_html());
    wp_update_post(['ID'=>$sid,'post_content'=>$content]);
  }
  if (isset($patch['style']) && is_array($patch['style'])){
    update_post_meta($sid, '_wmp_slide_style', $patch['style']);
  }

  return wmp2_rest_get_slide($req);
}
