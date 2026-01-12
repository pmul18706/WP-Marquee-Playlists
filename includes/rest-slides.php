<?php
if (!defined('ABSPATH')) exit;

/**
 * Slides are stored as CPT "wmp_slide"
 * - HTML => post_content (wp_kses_post)
 * - Style => post meta "wmp2_slide_style" (array)
 */

function wmp2_register_slides_routes() {

  // LIST slides (optional but handy)
  register_rest_route('wmp/v2', '/slides', [
    'methods' => 'GET',
    'permission_callback' => fn() => is_user_logged_in(),
    'callback' => function(\WP_REST_Request $req) {
      $q = new WP_Query([
        'post_type' => 'wmp_slide',
        'post_status' => 'publish',
        'posts_per_page' => 200,
        'orderby' => 'date',
        'order' => 'DESC',
      ]);

      $out = [];
      foreach ($q->posts as $p) {
        $style = get_post_meta($p->ID, 'wmp2_slide_style', true);
        if (!is_array($style)) $style = [];
        $out[] = [
          'id' => $p->ID,
          'title' => get_the_title($p->ID),
          'content_html' => (string) get_post_field('post_content', $p->ID),
          'style' => $style,
        ];
      }
      return rest_ensure_response($out);
    }
  ]);

  // CREATE slide
  register_rest_route('wmp/v2', '/slides', [
    'methods' => 'POST',
    'permission_callback' => fn() => is_user_logged_in() && current_user_can('edit_posts'),
    'callback' => function(\WP_REST_Request $req) {
      $title = sanitize_text_field($req->get_param('title'));
      $content_html = wp_kses_post($req->get_param('content_html'));

      $style = $req->get_param('style');
      if (!is_array($style)) $style = [];

      $slide_id = wp_insert_post([
        'post_type' => 'wmp_slide',
        'post_status' => 'publish',
        'post_title' => $title ?: 'Slide',
        'post_content' => $content_html,
      ], true);

      if (is_wp_error($slide_id)) return $slide_id;

      update_post_meta($slide_id, 'wmp2_slide_style', $style);

      return rest_ensure_response([
        'id' => $slide_id,
        'title' => get_the_title($slide_id),
        'content_html' => (string) get_post_field('post_content', $slide_id),
        'style' => $style,
      ]);
    }
  ]);

  // GET slide
  register_rest_route('wmp/v2', '/slides/(?P<id>\d+)', [
    'methods' => 'GET',
    'permission_callback' => fn() => is_user_logged_in(),
    'callback' => function(\WP_REST_Request $req) {
      $id = intval($req['id']);
      $p = get_post($id);
      if (!$p || $p->post_type !== 'wmp_slide') {
        return new WP_Error('not_found', 'Slide not found', ['status'=>404]);
      }

      $style = get_post_meta($id, 'wmp2_slide_style', true);
      if (!is_array($style)) $style = [];

      return rest_ensure_response([
        'id' => $id,
        'title' => get_the_title($id),
        'content_html' => (string) get_post_field('post_content', $id),
        'style' => $style,
      ]);
    }
  ]);

  // UPDATE slide
  register_rest_route('wmp/v2', '/slides/(?P<id>\d+)', [
    'methods' => 'PATCH',
    'permission_callback' => fn() => is_user_logged_in() && current_user_can('edit_posts'),
    'callback' => function(\WP_REST_Request $req) {
      $id = intval($req['id']);
      $p = get_post($id);
      if (!$p || $p->post_type !== 'wmp_slide') {
        return new WP_Error('not_found', 'Slide not found', ['status'=>404]);
      }

      $title_param = $req->get_param('title');
      $html_param  = $req->get_param('content_html');
      $style_param = $req->get_param('style');

      $update = ['ID' => $id];

      if ($title_param !== null) {
        $update['post_title'] = sanitize_text_field($title_param);
      }
      if ($html_param !== null) {
        $update['post_content'] = wp_kses_post($html_param);
      }

      $r = wp_update_post($update, true);
      if (is_wp_error($r)) return $r;

      if ($style_param !== null) {
        $style = is_array($style_param) ? $style_param : [];
        update_post_meta($id, 'wmp2_slide_style', $style);
      }

      $style = get_post_meta($id, 'wmp2_slide_style', true);
      if (!is_array($style)) $style = [];

      return rest_ensure_response([
        'id' => $id,
        'title' => get_the_title($id),
        'content_html' => (string) get_post_field('post_content', $id),
        'style' => $style,
      ]);
    }
  ]);
}
