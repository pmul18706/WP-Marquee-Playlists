<?php
/**
 * Plugin Name:       WP Marquee Playlists
 * Description:       Create scheduled image playlists and display them as a marquee scroll or static gallery via public link or shortcode. Ideal for graphics playback (e.g., billboards).
 * Version:           1.0.8
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Patrick Mullin Jr
 * Author URI:        https://github.com/pmul18706/WP-Marquee-Playlists
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-marquee-playlists
 */



if (!defined('ABSPATH')) exit;

class WMP_Marquee_Playlists {
  const OPT_KEY = 'wmp_playlists_v108';

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

    add_action('wp_ajax_wmp_save_playlist', [$this, 'ajax_save_playlist']);
    add_action('wp_ajax_wmp_delete_playlist', [$this, 'ajax_delete_playlist']);

    add_shortcode('wmp_playlist', [$this, 'shortcode_playlist']);

    add_action('init', [$this, 'add_rewrite']);
    add_filter('query_vars', function($vars){ $vars[]='wmp_token'; return $vars; });
    add_action('template_redirect', [$this, 'token_route']);
  }

  private function get_data() {
    $data = get_option(self::OPT_KEY, null);
    if ($data === null) $data = get_option('wmp_playlists_v107', null);
    if ($data === null) $data = get_option('wmp_playlists_v106', null);
    if ($data === null) $data = get_option('wmp_playlists_v105', null);
    if ($data === null) $data = get_option('wmp_playlists_v104', null);
    if ($data === null) $data = get_option('wmp_playlists_v103', ['playlists'=>[]]);
    if (!is_array($data) || !isset($data['playlists'])) $data = ['playlists'=>[]];
    return $data;
  }

  private function save_data($data) {
    update_option(self::OPT_KEY, $data, false);
  }

  private function new_token() {
    return substr(str_replace(['=','/','+'], '', base64_encode(random_bytes(9))), 0, 12);
  }

  public function admin_menu() {
    add_menu_page(
      'Marquee Playlists',
      'Marquee Playlists',
      'manage_options',
      'wmp_playlists_items',
      [$this, 'admin_items_page'],
      'dashicons-images-alt2'
    );

    add_submenu_page(
      'wmp_playlists_items',
      'Playlist Items',
      'Items',
      'manage_options',
      'wmp_playlists_items',
      [$this, 'admin_items_page']
    );

    add_submenu_page(
      'wmp_playlists_items',
      'Playlist Options',
      'Options',
      'manage_options',
      'wmp_playlists_options',
      [$this, 'admin_options_page']
    );
  }

  public function admin_assets($hook) {
    // Only load on our pages
    $is_items = ($hook === 'toplevel_page_wmp_playlists_items' || $hook === 'marquee-playlists_page_wmp_playlists_items');
    $is_opts  = ($hook === 'marquee-playlists_page_wmp_playlists_options');

    if (!$is_items && !$is_opts) return;

    wp_enqueue_media();
    wp_enqueue_style('wmp-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '1.0.8');

    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_script('wmp-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery','jquery-ui-sortable'], '1.0.8', true);

    $playlist_id = isset($_GET['playlist_id']) ? sanitize_text_field($_GET['playlist_id']) : '';

    wp_localize_script('wmp-admin', 'WMP', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('wmp_nonce'),
      'site_url' => site_url('/'),
      'page' => $is_opts ? 'options' : 'items',
      'playlist_id' => $playlist_id,
    ]);
  }

  public function frontend_assets() {
    wp_enqueue_style('wmp-front', plugin_dir_url(__FILE__) . 'assets/front.css', [], '1.0.8');
    wp_enqueue_script('wmp-front', plugin_dir_url(__FILE__) . 'assets/front.js', [], '1.0.8', true);
  }

  public function add_rewrite() {
    add_rewrite_rule('^simp/([^/]+)/?$', 'index.php?wmp_token=$matches[1]', 'top');
  }

  public function token_route() {
    $token = get_query_var('wmp_token');
    if (!$token) return;

    $data = $this->get_data();
    foreach ($data['playlists'] as $pl) {
      if (!empty($pl['token']) && $pl['token'] === $token) {
        status_header(200);
        nocache_headers();

        $title = !empty($pl['name']) ? esc_html($pl['name']) : 'Playlist';
        $shortcode = '[wmp_playlist id="' . esc_attr($pl['id']) . '"]';

        echo '<!doctype html><html><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $title . '</title>';
        wp_head();
        echo '</head><body style="margin:0;">';
        echo do_shortcode($shortcode);
        wp_footer();
        echo '</body></html>';
        exit;
      }
    }

    status_header(404);
    echo 'Playlist not found.';
    exit;
  }

  private function admin_shell($title, $page_slug) {
    if (!current_user_can('manage_options')) return;

    $data = $this->get_data();
    $playlists = $data['playlists'];

    echo '<div class="wrap wmp-wrap">';
    echo '<h1>' . esc_html($title) . ' <span class="wmp-ver">v1.0.8</span></h1>';

    // two-column layout: editor left, playlists right
    echo '<div class="wmp-grid">';
    echo '<div class="wmp-left"><div class="wmp-card"><div id="wmp-editor" data-page="' . esc_attr($page_slug) . '"></div></div></div>';
    echo '<div class="wmp-right"><div class="wmp-card">';
    echo '<h2>Playlists</h2>';
    echo '<div class="wmp-help">Use Items/Options buttons per playlist.</div>';
    echo '<div id="wmp-playlist-list" data-playlists="' . esc_attr(wp_json_encode($playlists)) . '"></div>';
    echo '</div></div>';
    echo '</div></div>';
  }

  public function admin_items_page() {
    $this->admin_shell('Marquee Playlists — Items', 'items');
  }

  public function admin_options_page() {
    $this->admin_shell('Marquee Playlists — Options', 'options');
  }

  public function ajax_delete_playlist() {
    check_ajax_referer('wmp_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('No permission');

    $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
    if (!$id) wp_send_json_error('Missing id');

    $data = $this->get_data();
    $data['playlists'] = array_values(array_filter($data['playlists'], function($pl) use ($id){
      return (string)$pl['id'] !== (string)$id;
    }));
    $this->save_data($data);

    wp_send_json_success(['playlists'=>$data['playlists']]);
  }

  public function ajax_save_playlist() {
    check_ajax_referer('wmp_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('No permission');

    $raw = isset($_POST['playlist']) ? wp_unslash($_POST['playlist']) : '';
    if (!$raw) wp_send_json_error('Missing payload');

    $pl = json_decode($raw, true);
    if (!is_array($pl)) wp_send_json_error('Bad JSON');

    $id = !empty($pl['id']) ? sanitize_text_field($pl['id']) : '';
    $name = !empty($pl['name']) ? sanitize_text_field($pl['name']) : 'Untitled Playlist';

    $mode = (!empty($pl['mode']) && $pl['mode'] === 'static') ? 'static' : 'marquee';
    $direction = (!empty($pl['direction']) && $pl['direction'] === 'right') ? 'right' : 'left';

    $speed = isset($pl['speed']) ? floatval($pl['speed']) : 60;
    if ($speed < 10) $speed = 10;
    if ($speed > 500) $speed = 500;

    $pause_on_hover = !empty($pl['pause_on_hover']) ? 1 : 0;

    $num_windows = isset($pl['num_windows']) ? intval($pl['num_windows']) : 1;
    if ($num_windows < 1) $num_windows = 1;
    if ($num_windows > 12) $num_windows = 12;

    // NEW: playlist appearance options
    $stage_bg = isset($pl['stage_bg']) ? sanitize_text_field($pl['stage_bg']) : '';
    // allow empty/transparent, or something like #000000
    if ($stage_bg !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $stage_bg)) {
      $stage_bg = ''; // fallback
    }

    $item_gap = isset($pl['item_gap']) ? intval($pl['item_gap']) : 18;
    if ($item_gap < 0) $item_gap = 0;
    if ($item_gap > 200) $item_gap = 200;

    $allowed_fit = ['contain','fill','cover','stretch'];

    $windows = [];
    if (!empty($pl['windows']) && is_array($pl['windows'])) {
      foreach ($pl['windows'] as $w) {
        $fit = isset($w['fit']) ? sanitize_text_field($w['fit']) : 'contain';
        if (!in_array($fit, $allowed_fit, true)) $fit = 'contain';

        $windows[] = [
          'x' => isset($w['x']) ? intval($w['x']) : 0,
          'y' => isset($w['y']) ? intval($w['y']) : 0,
          'width' => isset($w['width']) ? intval($w['width']) : 800,
          'height' => isset($w['height']) ? intval($w['height']) : 200,
          'fit' => $fit,
        ];
      }
    }

    for ($i = 0; $i < $num_windows; $i++) {
      if (!isset($windows[$i])) {
        $prev_h = ($i > 0 && isset($windows[$i-1]['height'])) ? intval($windows[$i-1]['height']) : 200;
        $windows[$i] = ['x'=>0, 'y'=>($i*($prev_h+10)), 'width'=>800, 'height'=>$prev_h, 'fit'=>'contain'];
      }

      $fit = isset($windows[$i]['fit']) ? $windows[$i]['fit'] : 'contain';
      if (!in_array($fit, $allowed_fit, true)) $fit = 'contain';
      $windows[$i]['fit'] = $fit;

      $windows[$i]['width']  = max(100, min(3000, intval($windows[$i]['width'])));
      $windows[$i]['height'] = max(50,  min(2000, intval($windows[$i]['height'])));
      $windows[$i]['x']      = max(0,   min(8000, intval($windows[$i]['x'])));
      $windows[$i]['y']      = max(0,   min(8000, intval($windows[$i]['y'])));
    }
    $windows = array_slice($windows, 0, $num_windows);

    $items = [];
    if (!empty($pl['items']) && is_array($pl['items'])) {
      $order = 0;
      foreach ($pl['items'] as $it) {
        $order++;

        $url = !empty($it['url']) ? esc_url_raw($it['url']) : '';
        if (!$url) continue;

        $type = !empty($it['type']) ? sanitize_text_field($it['type']) : 'image';
        if ($type !== 'video') $type = 'image';

        $start = !empty($it['start']) ? sanitize_text_field($it['start']) : '';
        $end   = !empty($it['end'])   ? sanitize_text_field($it['end'])   : '';

        $duration = isset($it['duration']) ? intval($it['duration']) : 0;
        if ($duration < 0) $duration = 0;
        if ($duration > 86400) $duration = 86400;

        $windows_target = [];
        if (!empty($it['windows']) && is_array($it['windows'])) {
          foreach ($it['windows'] as $wi) {
            $wi = intval($wi);
            if ($wi >= 1 && $wi <= $num_windows) $windows_target[] = $wi;
          }
          $windows_target = array_values(array_unique($windows_target));
        }

        $items[] = [
          'id' => !empty($it['id']) ? sanitize_text_field($it['id']) : ('it_' . substr(md5($url . microtime(true)), 0, 10)),
          'url' => $url,
          'type' => $type,
          'start' => $start,
          'end' => $end,
          'duration' => $duration,
          'windows' => $windows_target,
          'order' => isset($it['order']) ? intval($it['order']) : $order,
        ];
      }
    }

    usort($items, function($a,$b){
      return intval($a['order']) <=> intval($b['order']);
    });

    $data = $this->get_data();

    if (!$id) {
      $id = 'pl_' . substr(md5($name . microtime(true) . rand()), 0, 10);
      $token = $this->new_token();
    } else {
      $token = $this->new_token();
      foreach ($data['playlists'] as $existing) {
        if ((string)$existing['id'] === (string)$id && !empty($existing['token'])) {
          $token = $existing['token'];
          break;
        }
      }
    }

    $new_pl = [
      'id' => $id,
      'token' => $token,
      'name' => $name,

      // options
      'mode' => $mode,
      'direction' => $direction,
      'speed' => $speed,
      'pause_on_hover' => $pause_on_hover,
      'num_windows' => $num_windows,
      'windows' => $windows,
      'stage_bg' => $stage_bg,
      'item_gap' => $item_gap,

      // items
      'items' => $items,

      'updated_at' => current_time('mysql'),
    ];

    $found = false;
    foreach ($data['playlists'] as $idx => $existing) {
      if ((string)$existing['id'] === (string)$id) {
        $data['playlists'][$idx] = $new_pl;
        $found = true;
        break;
      }
    }
    if (!$found) $data['playlists'][] = $new_pl;

    $this->save_data($data);

    wp_send_json_success([
      'playlist' => $new_pl,
      'playlists' => $data['playlists'],
      'preview_url' => site_url('/simp/' . $token . '/'),
    ]);
  }

  private function is_item_active($it, $now_ts) {
    $start_ts = 0;
    $end_ts = 0;

    if (!empty($it['start'])) {
      $start_ts = strtotime($it['start']);
      if ($start_ts === false) $start_ts = 0;
    }
    if (!empty($it['end'])) {
      $end_ts = strtotime($it['end']);
      if ($end_ts === false) $end_ts = 0;
    }

    if ($start_ts && $now_ts < $start_ts) return false;
    if ($end_ts && $now_ts > $end_ts) return false;
    return true;
  }

  private function render_media($it) {
    $url = esc_url($it['url']);
    $type = !empty($it['type']) ? $it['type'] : 'image';

    if ($type === 'video') {
      return '<video class="wmp-media wmp-video" src="' . $url . '" muted playsinline autoplay loop preload="metadata"></video>';
    }
    return '<img class="wmp-media wmp-img" src="' . $url . '" alt="" loading="lazy" />';
  }

  public function shortcode_playlist($atts) {
    $atts = shortcode_atts(['id' => ''], $atts);
    $id = sanitize_text_field($atts['id']);
    if (!$id) return '';

    $data = $this->get_data();
    $pl = null;
    foreach ($data['playlists'] as $p) {
      if ((string)$p['id'] === (string)$id) { $pl = $p; break; }
    }
    if (!$pl) return '<div class="wmp-notfound">Playlist not found.</div>';

    $mode = (!empty($pl['mode']) && $pl['mode'] === 'static') ? 'static' : 'marquee';
    $direction = (!empty($pl['direction']) && $pl['direction'] === 'right') ? 'right' : 'left';
    $speed = isset($pl['speed']) ? floatval($pl['speed']) : 60;
    $pause = !empty($pl['pause_on_hover']) ? '1' : '0';

    $num_windows = isset($pl['num_windows']) ? intval($pl['num_windows']) : 1;
    if ($num_windows < 1) $num_windows = 1;

    $stage_bg = isset($pl['stage_bg']) ? $pl['stage_bg'] : '';
    $item_gap = isset($pl['item_gap']) ? intval($pl['item_gap']) : 18;

    $windows = (!empty($pl['windows']) && is_array($pl['windows'])) ? $pl['windows'] : [['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain']];

    for ($i=0; $i<$num_windows; $i++){
      if (!isset($windows[$i])) $windows[$i] = ['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain'];
      $windows[$i]['x'] = intval($windows[$i]['x'] ?? 0);
      $windows[$i]['y'] = intval($windows[$i]['y'] ?? 0);
      $windows[$i]['width'] = intval($windows[$i]['width'] ?? 800);
      $windows[$i]['height'] = intval($windows[$i]['height'] ?? 200);
      $fit = sanitize_text_field($windows[$i]['fit'] ?? 'contain');
      if (!in_array($fit, ['contain','fill','cover','stretch'], true)) $fit = 'contain';
      $windows[$i]['fit'] = $fit;
    }
    $windows = array_slice($windows, 0, $num_windows);

    $now = current_time('timestamp');
    $items = (!empty($pl['items']) && is_array($pl['items'])) ? $pl['items'] : [];
    $active = [];
    foreach ($items as $it) {
      if ($this->is_item_active($it, $now)) $active[] = $it;
    }

    $by_window = [];
    for ($w=1; $w <= $num_windows; $w++) $by_window[$w] = [];

    foreach ($active as $it) {
      $targets = (!empty($it['windows']) && is_array($it['windows']) && count($it['windows'])>0) ? $it['windows'] : range(1, $num_windows);
      foreach ($targets as $w) {
        if (isset($by_window[$w])) $by_window[$w][] = $it;
      }
    }

    $wrap_id = 'wmp_' . substr(md5($id . microtime(true)), 0, 10);

    ob_start();
    ?>
    <div
      id="<?php echo esc_attr($wrap_id); ?>"
      class="wmp-wrap-front"
      data-mode="<?php echo esc_attr($mode); ?>"
      data-direction="<?php echo esc_attr($direction); ?>"
      data-speed="<?php echo esc_attr($speed); ?>"
      data-pause="<?php echo esc_attr($pause); ?>"
      style="<?php
        // CSS vars for front-end styling
        $vars = [];
        if ($stage_bg !== '') $vars[] = '--wmp-bg:' . esc_attr($stage_bg);
        $vars[] = '--wmp-gap:' . intval($item_gap) . 'px';
        echo esc_attr(implode(';', $vars));
      ?>"
    >
      <div class="wmp-stage">
        <?php for ($w=1; $w <= $num_windows; $w++):
          $geom = $windows[$w-1] ?? ['x'=>0,'y'=>0,'width'=>800,'height'=>200,'fit'=>'contain'];
          $x = intval($geom['x']); $y=intval($geom['y']); $ww=intval($geom['width']); $hh=intval($geom['height']);
          $fit = esc_attr($geom['fit'] ?? 'contain');
          $window_items = $by_window[$w];
          ?>
          <div class="wmp-window" data-window="<?php echo esc_attr($w); ?>" data-fit="<?php echo $fit; ?>"
               style="left:<?php echo $x; ?>px; top:<?php echo $y; ?>px; width:<?php echo $ww; ?>px; height:<?php echo $hh; ?>px;">
            <?php if ($mode === 'static'): ?>
              <div class="wmp-static" data-static="1">
                <?php
                  if (count($window_items) === 0) {
                    echo '<div class="wmp-empty">No active items</div>';
                  } else {
                    foreach ($window_items as $idx => $it) {
                      $dur = isset($it['duration']) ? intval($it['duration']) : 0;
                      $hidden = ($idx === 0) ? '' : ' style="display:none"';
                      echo '<div class="wmp-static-item" data-duration="' . esc_attr($dur) . '"' . $hidden . '>';
                      echo $this->render_media($it);
                      echo '</div>';
                    }
                  }
                ?>
              </div>
            <?php else: ?>
              <div class="wmp-marquee" data-marquee="1">
                <div class="wmp-track">
                  <?php
                    if (count($window_items) === 0) {
                      echo '<div class="wmp-empty">No active items</div>';
                    } else {
                      echo '<div class="wmp-seq">';
                      foreach ($window_items as $it) {
                        echo '<div class="wmp-item">';
                        echo $this->render_media($it);
                        echo '</div>';
                      }
                      echo '</div>';

                      echo '<div class="wmp-seq wmp-seq-clone" aria-hidden="true">';
                      foreach ($window_items as $it) {
                        echo '<div class="wmp-item">';
                        echo $this->render_media($it);
                        echo '</div>';
                      }
                      echo '</div>';
                    }
                  ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
}

new WMP_Marquee_Playlists();

register_activation_hook(__FILE__, function(){
  (new WMP_Marquee_Playlists())->add_rewrite();
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){
  flush_rewrite_rules();
});
