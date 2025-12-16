<?php
/**
 * Plugin Name: WP Marquee Playlists
 * Description: Create scheduled image playlists and display them as a marquee scroll or static gallery via public link or shortcode. Ideal for use to playback graphics on billboards.
 * @wordpress-plugin
 * @package wp-marquee-playlists
 * @author pmul18706 <pmul18706@gmail.com>
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Version: 1.0.0
 * Author: Patrick Mullin Jr
 * Author URI: https://https://github.com/pmul18706/WP-Marquee-Playlists
 */

if (!defined('ABSPATH')) exit;

class WP_Marquee_Playlists {
  const CPT = 'wpmq_playlist';
  const QV  = 'wpmq_token';

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('init', [$this, 'add_rewrite']);
    add_filter('query_vars', [$this, 'query_vars']);

    add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
    add_action('save_post', [$this, 'save_post'], 10, 2);

    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

    add_shortcode('wp_marquee', [$this, 'shortcode']);

    add_action('template_redirect', [$this, 'maybe_render_public']);
  }
  
  private function asset_url($file){
  return plugin_dir_url(__FILE__) . 'assets/' . ltrim($file,'/');
}


  public static function activate() {
    $self = new self();
    $self->register_cpt();
    $self->add_rewrite();
    flush_rewrite_rules();
  }

  public static function deactivate() {
    flush_rewrite_rules();
  }

  public function register_cpt() {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Marquee Playlists',
        'singular_name' => 'Marquee Playlist',
        'add_new_item' => 'Add New Playlist',
        'edit_item' => 'Edit Playlist',
      ],
      'public' => false,
      'show_ui' => true,
      'menu_icon' => 'dashicons-images-alt2',
      'supports' => ['title'],
    ]);
  }

  public function add_rewrite() {
    // Public link pattern: /simp/{token}/
    add_rewrite_rule('^simp/([^/]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top');
  }

  public function query_vars($vars) {
    $vars[] = self::QV;
    return $vars;
  }

  public function add_meta_boxes() {
    add_meta_box('wpmq_items', 'Playlist Items (Images + Scheduling)', [$this, 'mb_items'], self::CPT, 'normal', 'high');
    add_meta_box('wpmq_settings', 'Playlist Settings', [$this, 'mb_settings'], self::CPT, 'side', 'high');
    add_meta_box('wpmq_public', 'Public Link', [$this, 'mb_public'], self::CPT, 'side', 'default');
  }

public function admin_assets($hook) {
  $screen = function_exists('get_current_screen') ? get_current_screen() : null;
  if (!$screen || $screen->post_type !== self::CPT) return;

  wp_enqueue_media();
  wp_enqueue_script('jquery');

  $base = plugin_dir_url(__FILE__) . 'assets/';

  wp_enqueue_style('wpmq-admin', $base . 'admin.css', array(), '1.0.2');
  wp_enqueue_script('wpmq-admin', $base . 'admin.js', array('jquery'), '1.0.2', true);
}


  private function get_items($post_id) {
    $items = get_post_meta($post_id, '_wpmq_items', true);
    if (!is_array($items)) $items = [];
    return $items;
  }

  private function get_settings($post_id) {
    $defaults = [
      'mode' => 'marquee',          // marquee | static
      'direction' => 'left',        // left | right
      'speed' => 80,                // px per second
      'pause_on_hover' => 1,        // 1|0
      'pause_time' => 0,            // ms (optional extra pause between loops)
      'window_height' => 160,       // px
      'gap' => 24,                  // px gap between images
      'bg' => '#000000',            // background
    ];
    $s = get_post_meta($post_id, '_wpmq_settings', true);
    if (!is_array($s)) $s = [];
    return array_merge($defaults, $s);
  }

  public function mb_items($post) {
      
    wp_nonce_field('wpmq_save', 'wpmq_nonce');
    $items = $this->get_items($post->ID);

    echo '<div class="wpmq-small">Add images to this playlist. Each image can have a schedule window (start/end). If end is blank, it runs indefinitely after start.</div>';
    echo '<div id="wpmq-items">';

    if (empty($items)) {
      // start empty; user will click add
    } else {
      foreach ($items as $i => $it) {
        $this->render_item_row($i, $it);
      }
    }

    echo '</div>';

    echo '<button type="button" class="button button-primary" id="wpmq-add-item">+ Add Image</button>';


    // Hidden template
    echo '<script type="text/html" id="wpmq-item-template">';
    $this->render_item_row('__INDEX__', [
      'image_id' => '',
      'start' => '',
      'end' => '',
      'alt' => '',
      'link' => '',
    ], true);
    echo '</script>';
  }

  private function render_item_row($index, $it, $is_template = false) {
    $image_id = isset($it['image_id']) ? intval($it['image_id']) : 0;
    $active = isset($it['active']) ? (int)$it['active'] : 1;
    $start    = isset($it['start']) ? esc_attr($it['start']) : '';
    $end      = isset($it['end']) ? esc_attr($it['end']) : '';
    $alt      = isset($it['alt']) ? esc_attr($it['alt']) : '';
    $link     = isset($it['link']) ? esc_url($it['link']) : '';

    $thumb = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
    $title = $image_id ? get_the_title($image_id) : '';
    $inactive_class = ($active ? '' : ' is-inactive');
echo '<div class="wpmq-item'.$inactive_class.'" data-index="'.esc_attr($index).'">';

    echo '<div class="wpmq-item" data-index="'.esc_attr($index).'">';
    echo '<div class="wpmq-item-row">';

    echo '<div>';
    echo '<img class="wpmq-thumb" src="'.esc_url($thumb ?: '').'" alt="" />';
    echo '<div class="wpmq-small wpmq-image-title">'.esc_html($title ?: '').'</div>';
    echo '<input class="wpmq-image-id" type="hidden" data-name="wpmq_items[__INDEX__][image_id]" value="'.esc_attr($image_id ?: '').'"/>';
    echo '<div class="wpmq-actions">';
    echo '<button class="button wpmq-btn wpmq-pick">Choose Image</button>';
    echo '<button class="button wpmq-btn wpmq-remove wpmq-danger">Remove</button>';
    echo '</div>';
    echo '</div>';
    
    echo '<p style="margin:8px 0 0 0;">
  <label>
    <input type="checkbox" class="wpmq-active"
      data-name="wpmq_items[__INDEX__][active]"
      value="1" '.checked($active, 1, false).' />
    <strong>Active</strong>
  </label>
</p>';


    echo '<div>';
    echo '<label><strong>Start (default = now)</strong><br/>';
    echo '<input class="wpmq-start" type="datetime-local" data-name="wpmq_items[__INDEX__][start]" value="'.esc_attr($start).'"/></label>';
    echo '<p class="wpmq-small">If blank: starts immediately.</p>';
    echo '</div>';

    echo '<div>';
    echo '<label><strong>End</strong><br/>';
    echo '<input type="datetime-local" data-name="wpmq_items[__INDEX__][end]" value="'.esc_attr($end).'"/></label>';
    echo '<p class="wpmq-small">If blank: no end.</p>';
    echo '</div>';

    echo '<div>';
    echo '<label><strong>Alt Text (optional)</strong><br/>';
    echo '<input type="text" style="width:100%" data-name="wpmq_items[__INDEX__][alt]" value="'.esc_attr($alt).'"/></label>';
    echo '<p class="wpmq-small">Used for accessibility.</p>';
    echo '</div>';

    echo '<div>';
    echo '<label><strong>Click Link (optional)</strong><br/>';
    echo '<input type="url" style="width:100%" data-name="wpmq_items[__INDEX__][link]" value="'.esc_attr($link).'"/></label>';
    echo '<p class="wpmq-small">If set, image becomes a link.</p>';
    echo '</div>';

    echo '</div>'; // row
    echo '</div>'; // item
  }

  public function mb_settings($post) {
    $s = $this->get_settings($post->ID);

    echo '<p><label><strong>Mode</strong><br/>';
    echo '<select name="wpmq_settings[mode]">';
    echo '<option value="marquee" '.selected($s['mode'],'marquee',false).'>Marquee (scroll)</option>';
    echo '<option value="static" '.selected($s['mode'],'static',false).'>Static (no scroll)</option>';
    echo '</select></label></p>';

    echo '<p><label><strong>Direction</strong><br/>';
    echo '<select name="wpmq_settings[direction]">';
    echo '<option value="left" '.selected($s['direction'],'left',false).'>Left</option>';
    echo '<option value="right" '.selected($s['direction'],'right',false).'>Right</option>';
    echo '</select></label></p>';

    echo '<p><label><strong>Speed (px/sec)</strong><br/>';
    echo '<input type="number" min="10" max="1000" name="wpmq_settings[speed]" value="'.esc_attr(intval($s['speed'])).'" style="width:100%"/></label></p>';

    echo '<p><label><strong>Pause on hover</strong><br/>';
    echo '<select name="wpmq_settings[pause_on_hover]">';
    echo '<option value="1" '.selected($s['pause_on_hover'],1,false).'>Yes</option>';
    echo '<option value="0" '.selected($s['pause_on_hover'],0,false).'>No</option>';
    echo '</select></label></p>';

    echo '<p><label><strong>Pause time between loops (ms)</strong><br/>';
    echo '<input type="number" min="0" max="600000" name="wpmq_settings[pause_time]" value="'.esc_attr(intval($s['pause_time'])).'" style="width:100%"/></label></p>';

    echo '<p><label><strong>Window height (px)</strong><br/>';
    echo '<input type="number" min="40" max="2000" name="wpmq_settings[window_height]" value="'.esc_attr(intval($s['window_height'])).'" style="width:100%"/></label></p>';

    echo '<p><label><strong>Gap between images (px)</strong><br/>';
    echo '<input type="number" min="0" max="400" name="wpmq_settings[gap]" value="'.esc_attr(intval($s['gap'])).'" style="width:100%"/></label></p>';

    echo '<p><label><strong>Background color</strong><br/>';
    echo '<input type="text" name="wpmq_settings[bg]" value="'.esc_attr($s['bg']).'" style="width:100%" placeholder="#000000"/></label></p>';

    echo '<p class="wpmq-small">Tip: Speed is pixels per second. Higher = faster.</p>';
  }

  public function mb_public($post) {
    $token = get_post_meta($post->ID, '_wpmq_token', true);
    if (!$token) {
      $token = $this->generate_token();
      update_post_meta($post->ID, '_wpmq_token', $token);
    }

    $url = home_url('/simp/' . rawurlencode($token) . '/');

    echo '<p><strong>Public link</strong></p>';
    echo '<p><input type="text" readonly style="width:100%" value="'.esc_attr($url).'" onclick="this.select();"/></p>';
    echo '<p class="wpmq-small">Anyone with this link can view the playlist. If your link 404s after activation, go to <em>Settings → Permalinks</em> and click Save.</p>';
    echo '<hr/>';
    echo '<p><strong>Shortcode</strong></p>';
    echo '<p><code>[wp_marquee playlist="'.intval($post->ID).'"]</code></p>';
  }

  private function generate_token() {
    // Short, URL-safe token
    return wp_generate_password(14, false, false);
  }

  public function save_post($post_id, $post) {
    if ($post->post_type !== self::CPT) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!isset($_POST['wpmq_nonce']) || !wp_verify_nonce($_POST['wpmq_nonce'], 'wpmq_save')) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Items
    $items_in = isset($_POST['wpmq_items']) && is_array($_POST['wpmq_items']) ? $_POST['wpmq_items'] : [];
    $items_out = [];

    foreach ($items_in as $it) {
      $image_id = isset($it['image_id']) ? intval($it['image_id']) : 0;
      if (!$image_id) continue;

      $start = isset($it['start']) ? sanitize_text_field($it['start']) : '';
      $end   = isset($it['end']) ? sanitize_text_field($it['end']) : '';
      $alt   = isset($it['alt']) ? sanitize_text_field($it['alt']) : '';
      $link  = isset($it['link']) ? esc_url_raw($it['link']) : '';

      // Basic validation: end cannot be before start (if both exist)
      if ($start && $end) {
        $tsS = strtotime($start);
        $tsE = strtotime($end);
        if ($tsS && $tsE && $tsE < $tsS) {
          // swap or drop end; safer to drop end
          $end = '';
        }
      }

      $items_out[] = [
        'image_id' => $image_id,
        'start' => $start,
        'end' => $end,
        'alt' => $alt,
        'link' => $link,
      ];
    }

    update_post_meta($post_id, '_wpmq_items', $items_out);

    // Settings
    $s_in = isset($_POST['wpmq_settings']) && is_array($_POST['wpmq_settings']) ? $_POST['wpmq_settings'] : [];
    $mode = isset($s_in['mode']) && in_array($s_in['mode'], ['marquee','static'], true) ? $s_in['mode'] : 'marquee';
    $dir  = isset($s_in['direction']) && in_array($s_in['direction'], ['left','right'], true) ? $s_in['direction'] : 'left';

    $s_out = [
      'mode' => $mode,
      'direction' => $dir,
      'speed' => max(10, intval($s_in['speed'] ?? 80)),
      'pause_on_hover' => !empty($s_in['pause_on_hover']) ? 1 : 0,
      'pause_time' => max(0, intval($s_in['pause_time'] ?? 0)),
      'window_height' => max(40, intval($s_in['window_height'] ?? 160)),
      'gap' => max(0, intval($s_in['gap'] ?? 24)),
      'bg' => sanitize_text_field($s_in['bg'] ?? '#000000'),
    ];

    update_post_meta($post_id, '_wpmq_settings', $s_out);

    // Ensure token exists
    $token = get_post_meta($post_id, '_wpmq_token', true);
    if (!$token) update_post_meta($post_id, '_wpmq_token', $this->generate_token());
  }

  private function filter_scheduled_items($items) {
    $now = time();
    $out = [];
    foreach ($items as $it) {
      $start = !empty($it['start']) ? strtotime($it['start']) : 0;
      $end   = !empty($it['end']) ? strtotime($it['end']) : 0;

      if ($start && $now < $start) continue;
      if ($end && $now > $end) continue;

      $out[] = $it;
    }
    return $out;
  }

  private function render_playlist_html($post_id) {
    $items = $this->filter_scheduled_items($this->get_items($post_id));
    $s = $this->get_settings($post_id);

    $mode = $s['mode'];
    $dir  = $s['direction'];
    $speed = intval($s['speed']);
    $pauseHover = intval($s['pause_on_hover']);
    $pauseTime = intval($s['pause_time']);
    $h = intval($s['window_height']);
    $gap = intval($s['gap']);
    $bg = $s['bg'];

    $uid = 'wpmq_' . $post_id . '_' . wp_generate_password(6, false, false);

    ob_start();
    ?>
    <div class="wpmq-wrap" id="<?php echo esc_attr($uid); ?>"
         data-mode="<?php echo esc_attr($mode); ?>"
         data-direction="<?php echo esc_attr($dir); ?>"
         data-speed="<?php echo esc_attr($speed); ?>"
         data-pause-hover="<?php echo esc_attr($pauseHover); ?>"
         data-pause-time="<?php echo esc_attr($pauseTime); ?>"
         data-gap="<?php echo esc_attr($gap); ?>"
         style="height: <?php echo esc_attr($h); ?>px; background: <?php echo esc_attr($bg); ?>; overflow:hidden; position:relative; width:100%;">
      <?php if (empty($items)): ?>
        <div style="color:#fff; opacity:.7; padding:12px; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial;">No scheduled images right now.</div>
      <?php else: ?>
        <div class="wpmq-track" style="display:flex; align-items:center; height:100%; gap: <?php echo esc_attr($gap); ?>px; will-change: transform;">
          <?php foreach ($items as $it):
            $img_id = intval($it['image_id']);
            $src = wp_get_attachment_image_url($img_id, 'large');
            if (!$src) continue;
            $alt = !empty($it['alt']) ? $it['alt'] : get_post_meta($img_id, '_wp_attachment_image_alt', true);
            $alt = $alt ? $alt : get_the_title($img_id);
            $link = !empty($it['link']) ? $it['link'] : '';
            ?>
            <div class="wpmq-item" style="height:100%; display:flex; align-items:center; flex:0 0 auto;">
              <?php if ($link): ?>
                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer" style="display:flex; align-items:center; height:100%;">
                  <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($alt); ?>" style="height:100%; width:auto; display:block;" />
                </a>
              <?php else: ?>
                <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($alt); ?>" style="height:100%; width:auto; display:block;" />
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <style>
      /* Scoped styling */
      #<?php echo esc_attr($uid); ?> .wpmq-track.is-paused { animation-play-state: paused !important; }
      #<?php echo esc_attr($uid); ?>[data-mode="static"] .wpmq-track { justify-content: center; }
    </style>

   <?php
    return ob_get_clean();
  }

  public function shortcode($atts) {
    $atts = shortcode_atts(['playlist' => 0], $atts, 'wp_marquee');
    $post_id = intval($atts['playlist']);
    if (!$post_id || get_post_type($post_id) !== self::CPT) return '';
    return $this->render_playlist_html($post_id);
  }

  public function maybe_render_public() {
    $token = get_query_var(self::QV);
    if (!$token) return;

    $token = sanitize_text_field($token);
    $post_id = $this->find_playlist_by_token($token);
    if (!$post_id) {
      status_header(404);
      echo 'Playlist not found.';
      exit;
    }

    // Render a minimal standalone page (no theme) for signage use
    $title = get_the_title($post_id);
    $html = $this->render_playlist_html($post_id);

    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    ?>
    <!doctype html>
    <html>
      <head>
        <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($title); ?></title>
        <link rel="stylesheet" href="<?php echo esc_url($this->asset_url('public.css')); ?>">
      </head>
      <body>
        <div class="wpmq-page">
          <?php echo $html; // already escaped within ?>
        </div>
        <script src="<?php echo esc_url($this->asset_url('public.js')); ?>"></script>
      </body>
    </html>
    <?php
    exit;
  }

  private function find_playlist_by_token($token) {
    $q = new WP_Query([
      'post_type' => self::CPT,
      'posts_per_page' => 1,
      'fields' => 'ids',
      'meta_query' => [
        [
          'key' => '_wpmq_token',
          'value' => $token,
          'compare' => '=',
        ]
      ]
    ]);
    if (!empty($q->posts)) return intval($q->posts[0]);
    return 0;
  }
}

register_activation_hook(__FILE__, ['WP_Marquee_Playlists', 'activate']);
register_deactivation_hook(__FILE__, ['WP_Marquee_Playlists', 'deactivate']);

new WP_Marquee_Playlists();
