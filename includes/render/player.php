<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/variables.php';

function wmp2_register_player_route(){
  add_rewrite_rule('^simp2/([^/]+)/?$', 'index.php?wmp2_token=$matches[1]', 'top');

  add_filter('query_vars', function($vars){
    $vars[] = 'wmp2_token';
    return $vars;
  });

  add_action('template_redirect', function(){
    $token = get_query_var('wmp2_token');
    if (!$token) return;

    $playlist_id = wmp2_find_playlist_by_token($token);
    if (!$playlist_id){
      status_header(404);
      echo 'Playlist not found.';
      exit;
    }

    wp_enqueue_style('wmp2-player', WMP2_URL.'assets/front/player.css', [], WMP2_VERSION);
    wp_enqueue_script('wmp2-player', WMP2_URL.'assets/front/player.js', [], WMP2_VERSION, true);

    $opts = get_post_meta($playlist_id, '_wmp_options', true);
    if (!is_array($opts)) $opts = [];

    $items = wmp2_load_active_items($playlist_id);

    status_header(200);
    nocache_headers();

    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc_html(get_the_title($playlist_id)) . '</title>';
    wp_head();
    echo '</head><body style="margin:0;">';

    echo wmp2_render_player($playlist_id, $opts, $items);

    wp_footer();
    echo '</body></html>';
    exit;
  });
}

function wmp2_get_category_media_ids($term_id) {
  $q = new WP_Query([
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'posts_per_page' => 500,
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => 'ids',
    'tax_query' => [[
      'taxonomy' => 'wmp_media_cat',
      'field' => 'term_id',
      'terms' => [$term_id],
    ]]
  ]);
  return $q->posts ?: [];
}

function wmp2_expand_category_rule($playlist_id, $term_id, $count) {
  $ids = wmp2_get_category_media_ids($term_id);
  if (!$ids) return [];

  $count = max(1, min(50, intval($count)));
  $cursor = function_exists('wmp2_rotation_get_cursor') ? wmp2_rotation_get_cursor($playlist_id, $term_id) : 0;

  $picked = [];
  $n = count($ids);
  for ($i=0; $i<$count; $i++){
    $picked[] = $ids[ ($cursor + $i) % $n ];
  }

  $new_cursor = ($cursor + $count) % $n;
  if (function_exists('wmp2_rotation_set_cursor')) {
    wmp2_rotation_set_cursor($playlist_id, $term_id, $new_cursor);
  }

  // Convert attachments into item rows compatible with renderer
  $out = [];
  foreach ($picked as $att_id){
    $url = wp_get_attachment_url($att_id);
    if (!$url) continue;
    $mime = get_post_mime_type($att_id);
    $kind = (is_string($mime) && str_starts_with($mime, 'video/')) ? 'video' : 'image';

    $out[] = [
      'item_type' => 'media',
      'ref_id' => $att_id,
      'media_url' => $url,
      'media_kind' => $kind,
      'duration_sec' => 0,
      'windows' => [],   // category items default to "all windows" (same as normal items)
      'meta' => [],
    ];
  }

  return $out;
}


function wmp2_find_playlist_by_token($token){
  $q = new WP_Query([
    'post_type' => 'wmp_playlist',
    'post_status' => 'publish',
    'posts_per_page' => 1,
    'meta_key' => '_wmp_token',
    'meta_value' => sanitize_text_field($token),
    'fields' => 'ids',
  ]);
  return $q->posts[0] ?? 0;
}

function wmp2_is_active_now($start_at, $end_at){
  $now = current_time('timestamp');
  if ($start_at){
    $s = strtotime($start_at);
    if ($s && $now < $s) return false;
  }
  if ($end_at){
    $e = strtotime($end_at);
    if ($e && $now > $e) return false;
  }
  return true;
}

function wmp2_load_active_items($playlist_id){
  global $wpdb;
  $items_table = $wpdb->prefix . 'wmp_items';
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT * FROM $items_table WHERE playlist_id=%d ORDER BY sort_order ASC, id ASC
  ", $playlist_id), ARRAY_A);

  $out = [];
  foreach ($rows as $r){
    if (!wmp2_is_active_now($r['start_at'], $r['end_at'])) continue;

    $r['windows'] = $r['windows_json'] ? json_decode($r['windows_json'], true) : [];
    $r['meta']    = $r['meta_json'] ? json_decode($r['meta_json'], true) : [];

    if ($r['item_type'] === 'media' && $r['ref_id']){
      $url = wp_get_attachment_url(intval($r['ref_id']));
      if (!$url) continue;
      $mime = get_post_mime_type(intval($r['ref_id']));
      $r['media_url'] = $url;
      $r['media_kind'] = (is_string($mime) && str_starts_with($mime, 'video/')) ? 'video' : 'image';
    }

    if ($r['item_type'] === 'slide' && $r['ref_id']){
      $slide = get_post(intval($r['ref_id']));
      if (!$slide || $slide->post_type !== 'wmp_slide') continue;
      $r['slide_html'] = $slide->post_content;
      $r['slide_style'] = get_post_meta(intval($r['ref_id']), '_wmp_slide_style', true);
      if (!is_array($r['slide_style'])) $r['slide_style'] = [];
    }
    
    // CATEGORY RULE expansion: item_type=category, ref_id=term_id, meta.count=N
    if ($r['item_type'] === 'category' && $r['ref_id']) {
      $count = 1;
      if (is_array($r['meta']) && isset($r['meta']['count'])) $count = intval($r['meta']['count']);
      $expanded = wmp2_expand_category_rule($playlist_id, intval($r['ref_id']), $count);
    
      // preserve scheduling + window targeting from the rule item
      foreach ($expanded as &$e) {
        $e['start_at'] = $r['start_at'];
        $e['end_at'] = $r['end_at'];
        $e['duration_sec'] = intval($r['duration_sec'] ?? 0);
        $e['windows'] = $r['windows'];
      }
    
      // push expanded and skip normal handling
      foreach ($expanded as $e) $out[] = $e;
      continue;
    }

    $out[] = $r;
  }
  return $out;
}

function wmp2_render_player($playlist_id, $opts, $items){
  $mode = ($opts['mode'] ?? 'marquee') === 'static' ? 'static' : 'marquee';

  $direction = ($opts['direction'] ?? 'left');
  $direction = in_array($direction, ['left','right'], true) ? $direction : 'left';

  // speed is pixels/second (marquee)
  $speed = isset($opts['speed']) ? floatval($opts['speed']) : 60;
  if ($speed < 5) $speed = 5;
  if ($speed > 2000) $speed = 2000;

  $pause_on_hover = !empty($opts['pause_on_hover']) ? 1 : 0;

  $bg = $opts['stage_bg'] ?? '';
  $gap = isset($opts['item_gap']) ? intval($opts['item_gap']) : 18;

  $num_windows = max(1, min(12, intval($opts['num_windows'] ?? 1)));
  $windows = $opts['windows'] ?? [['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain']];
  if (!is_array($windows)) $windows = [['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain']];
  $windows = array_slice($windows, 0, $num_windows);

  // Group items per window (default all windows)
  $by_window = [];
  for ($w=1;$w<=$num_windows;$w++) $by_window[$w]=[];
  foreach ($items as $it){
    $targets = (is_array($it['windows']) && count($it['windows'])>0) ? $it['windows'] : range(1,$num_windows);
    foreach ($targets as $w){
      $w = intval($w);
      if (isset($by_window[$w])) $by_window[$w][] = $it;
    }
  }

  $wrap_id = 'wmp2_' . substr(md5($playlist_id . microtime(true)), 0, 10);
  $style = [];
  if ($bg && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bg)) $style[] = '--wmp-bg:' . $bg;
  $style[] = '--wmp-gap:' . $gap . 'px';

  ob_start();
  ?>
  <div id="<?php echo esc_attr($wrap_id); ?>"
       class="wmp2-player"
       data-mode="<?php echo esc_attr($mode); ?>"
       data-direction="<?php echo esc_attr($direction); ?>"
       data-speed="<?php echo esc_attr($speed); ?>"
       data-pausehover="<?php echo esc_attr($pause_on_hover); ?>"
       style="<?php echo esc_attr(implode(';', $style)); ?>">
    <div class="wmp2-stage">
      <?php for ($i=0;$i<$num_windows;$i++):
        $w = $i+1;
        $g = $windows[$i] ?? ['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain'];
        $fit = in_array(($g['fit'] ?? 'contain'), ['contain','fill','cover','stretch'], true) ? $g['fit'] : 'contain';
        ?>
        <div class="wmp2-window" data-fit="<?php echo esc_attr($fit); ?>"
             style="left:<?php echo intval($g['x']??0); ?>px;top:<?php echo intval($g['y']??0); ?>px;width:<?php echo intval($g['width']??800); ?>px;height:<?php echo intval($g['height']??200); ?>px;">
          <div class="wmp2-seq">
            <?php foreach (($by_window[$w] ?? []) as $it): ?>
              <div class="wmp2-item" data-duration="<?php echo intval($it['duration_sec'] ?? 0); ?>">
                <?php echo wmp2_render_item_html($playlist_id, $opts, $it); ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php
  return ob_get_clean();
}

function wmp2_render_item_html($playlist_id, $opts, $it) {

  $type = $it['item_type'] ?? 'media';

  // ----------------------------
  // SLIDE
  // ----------------------------
  if ($type === 'slide') {

    $slide_id = intval($it['ref_id'] ?? 0);

    // Prefer hydrated slide HTML/style on the item (your item dump shows these exist)
    $slide_html = (string)($it['slide_html'] ?? '');

    // Fallback to CPT post_content if not hydrated
    if ($slide_html === '' && $slide_id) {
      $slide_html = (string) get_post_field('post_content', $slide_id);
    }

    // Style: prefer hydrated style on item, else CPT meta
    $slide_style = $it['slide_style'] ?? null;
    if (!is_array($slide_style) && $slide_id) {
      $slide_style = get_post_meta($slide_id, 'wmp2_slide_style', true);
    }
    if (!is_array($slide_style)) $slide_style = [];

    // Defaults
    $slide_style = array_merge([
      'bg' => '',
      'color' => '',
      'fontFamily' => '',
      'fontSize' => '',
      'fontWeight' => '',
      'italic' => false,
      'underline' => false,
      'align' => '',
      'marquee' => ['enabled' => false, 'direction' => 'left', 'speed' => 80],
    ], $slide_style);

    // Playlist context for variable replacement
    $playlist_ctx = [
      'id'      => intval($playlist_id),
      'name'    => (string)($opts['name'] ?? ''), // optional; safe if not set
      'options' => is_array($opts) ? $opts : [],
    ];

    if (function_exists('wmp2_apply_variables')) {
      $slide_html = wmp2_apply_variables($playlist_ctx, $slide_html);
    }

    // If still empty, show something obvious (no silent blanks)
    if ($slide_html === '') {
      $slide_html = '<div style="padding:20px;font-weight:800">[Empty slide content]</div>';
    }

    // Inline styles
    $inline = [];
    if (!empty($slide_style['bg'])) $inline[] = 'background:' . esc_attr($slide_style['bg']);
    if (!empty($slide_style['color'])) $inline[] = 'color:' . esc_attr($slide_style['color']);
    if (!empty($slide_style['fontFamily'])) $inline[] = 'font-family:' . esc_attr($slide_style['fontFamily']);
    if (!empty($slide_style['fontSize'])) $inline[] = 'font-size:' . esc_attr($slide_style['fontSize']);
    if (!empty($slide_style['fontWeight'])) $inline[] = 'font-weight:' . esc_attr($slide_style['fontWeight']);
    if (!empty($slide_style['align'])) $inline[] = 'text-align:' . esc_attr($slide_style['align']);
    if (!empty($slide_style['italic'])) $inline[] = 'font-style:italic';
    if (!empty($slide_style['underline'])) $inline[] = 'text-decoration:underline';

    // Safe default visibility
    if (empty($slide_style['bg']) && empty($slide_style['color'])) {
      $inline[] = 'background:#000';
      $inline[] = 'color:#fff';
    }

    $wrapStyle = $inline ? ' style="' . implode(';', $inline) . '"' : '';

    // Slide marquee (inside slide)
    $marq = is_array($slide_style['marquee'] ?? null) ? $slide_style['marquee'] : [];
    $enabled = !empty($marq['enabled']);

    if ($enabled) {
      $dir = (($marq['direction'] ?? 'left') === 'right') ? 'right' : 'left';
      $speed = max(5, min(2000, intval($marq['speed'] ?? 80)));
      $duration = max(3, min(60, intval(12000 / $speed)));
      $anim = ($dir === 'right') ? 'wmp2_slide_marquee_right' : 'wmp2_slide_marquee_left';

      return '<div class="wmp2-slide wmp2-slide-marquee"' . $wrapStyle . '>'
        . '<div class="wmp2-slide-marquee-track" style="animation:' . $anim . ' ' . $duration . 's linear infinite;">'
        . $slide_html
        . '</div></div>';
    }

    return '<div class="wmp2-slide"' . $wrapStyle . '>' . $slide_html . '</div>';
  }

  // ----------------------------
  // MEDIA (image/gif/video)
  // ----------------------------
  $att_id = intval($it['ref_id'] ?? 0);
  if (!$att_id) return '';

  // Prefer hydrated URL if present
  $url = (string)($it['url'] ?? '');
  if ($url === '') {
    $url = wp_get_attachment_url($att_id);
  }
  if (!$url) return '';

  $mime = (string)($it['mime'] ?? '');
  if ($mime === '') {
    $mime = (string) get_post_mime_type($att_id);
  }

  $is_video = (strpos($mime, 'video/') === 0);
  $is_gif = (stripos($mime, 'image/gif') === 0);

  // Fit mode handled by CSS on the parent window: data-fit="contain|cover|fill|stretch"
  // Here we just output the media element.

  if ($is_video) {
    $muted = !empty($opts['video_muted']) ? ' muted' : ' muted'; // default muted for autoplay
    $loop  = ' loop';
    $plays = ' playsinline';
    $auto  = ' autoplay';

    return '<video class="wmp2-media wmp2-video" src="' . esc_url($url) . '"'
      . $auto . $loop . $muted . $plays
      . ' preload="auto"></video>';
  }

  // Images + GIFs (GIFs animate naturally)
  $alt = '';
  $alt_meta = get_post_meta($att_id, '_wp_attachment_image_alt', true);
  if (is_string($alt_meta)) $alt = $alt_meta;

  return '<img class="wmp2-media wmp2-image" src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" loading="lazy" />';
}
