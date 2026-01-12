<?php
if (!defined('ABSPATH')) exit;

/**
 * GET /wmp/v2/attachments?ids=1,2,3
 * Returns attachment URL + mime for each ID.
 */
function wmp2_register_attachments_route() {
  register_rest_route('wmp/v2', '/attachments', [
    'methods' => 'GET',
    'permission_callback' => function() {
      // Front-end editor requires login; keep this tight.
      return is_user_logged_in();
    },
    'callback' => function(\WP_REST_Request $req) {
      $ids_param = $req->get_param('ids');

      if (is_array($ids_param)) {
        $ids = array_map('intval', $ids_param);
      } else {
        $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$ids_param)));
      }

      $ids = array_values(array_unique(array_filter($ids, fn($n) => $n > 0)));
      if (!$ids) return rest_ensure_response([]);

      $out = [];
      foreach ($ids as $id) {
        $url = wp_get_attachment_url($id);
        if (!$url) continue;

        $mime = get_post_mime_type($id);
        $kind = (is_string($mime) && str_starts_with($mime, 'video/')) ? 'video' : 'image';

        $out[] = [
          'id'   => $id,
          'url'  => $url,
          'mime' => $mime ?: '',
          'kind' => $kind,
        ];
      }

      return rest_ensure_response($out);
    }
  ]);
}
