<?php
/**
 * Plugin Name: WP Marquee Playlists V2
 * Description: V2 signage playlist CMS with front-end editor, roles, categories, slides, and player.
 * Version: 2.0.0-alpha.1
 * Author: You
 */

if (!defined('ABSPATH')) exit;

define('WMP2_VERSION', '2.0.0-alpha.1');
define('WMP2_PATH', plugin_dir_path(__FILE__));
define('WMP2_URL', plugin_dir_url(__FILE__));
define('WMP2_PLUGIN_FILE', __FILE__);
define('WMP2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WMP2_PLUGIN_URL', plugin_dir_url(__FILE__));


require_once WMP2_PATH . 'includes/db-schema.php';
require_once WMP2_PATH . 'includes/cpt-playlist.php';
require_once WMP2_PATH . 'includes/taxonomy-media-category.php';
require_once WMP2_PATH . 'includes/permissions.php';
require_once WMP2_PATH . 'includes/rest/init.php';
require_once WMP2_PATH . 'includes/render/player.php';
require_once WMP2_PATH . 'includes/rest-attachments.php';
require_once WMP2_PATH . 'includes/media-categories.php';
require_once WMP2_PATH . 'includes/rest-categories.php';
require_once WMP2_PATH . 'includes/rotation.php';
require_once WMP2_PATH . 'includes/rest-slides.php';

add_action('rest_api_init', function () {
  if (function_exists('wmp2_register_slides_routes')) {
    wmp2_register_slides_routes();
  }
});


register_activation_hook(__FILE__, function(){
  wmp2_install_db();
  wmp2_register_cpts();
  wmp2_register_taxonomies();
  flush_rewrite_rules();
});

// 1) Admin menu page
add_action('admin_menu', function () {
  add_menu_page(
    'Marquee Playlists',
    'Marquee Playlists',
    'manage_options',
    'wmp2',
    'wmp2_admin_page',
    'dashicons-images-alt2',
    58
  );
});

// 2) Admin page output (MUST include #wmp2-app)
function wmp2_admin_page() {
  echo '<div class="wrap wmp2-app">';
  echo '  <div class="wmp2-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">';
  echo '    <div class="wmp2-title" style="font-size:18px;font-weight:800">Marquee Playlists</div>';
  echo '    <button id="wmp2-create" class="button button-primary">New Playlist</button>';
  echo '  </div>';

  echo '  <div class="wmp2-main" style="display:grid;grid-template-columns: 360px 1fr;gap:14px">';
  echo '    <div class="wmp2-col">';
  echo '      <div class="wmp2-card">';
  echo '        <h3 style="margin:0 0 8px 0">Playlists</h3>';
  echo '        <div id="wmp2-list"></div>';
  echo '      </div>';
  echo '    </div>';

  echo '    <div class="wmp2-col">';
  echo '      <div class="wmp2-card">';
  echo '        <div id="wmp2-detail">Select a playlist…</div>';
  echo '      </div>';
  echo '    </div>';
  echo '  </div>';

  echo '</div>';
}


add_action('admin_enqueue_scripts', function ($hook) {
  if ($hook !== 'toplevel_page_wmp2') return;

  wp_enqueue_media();

  wp_enqueue_style(
    'wmp2-app',
    WMP2_PLUGIN_URL . 'assets/app/app.css',
    [],
    '2.0.0'
  );

  wp_enqueue_script(
    'wmp2-app',
    WMP2_PLUGIN_URL . 'assets/app/app.js',
    ['jquery'],
    '2.0.0',
    true
  );

  wp_localize_script('wmp2-app', 'WMP2', [
    'rest'     => esc_url_raw(rest_url('wmp/v2/')),
    'nonce'    => wp_create_nonce('wp_rest'),
    'site_url' => get_site_url(),
  ]);

  // DEBUG: write the URL into page source so we can verify it instantly
  wp_add_inline_script('wmp2-app', 'console.log("WMP2 app.js url:", ' . json_encode(WMP2_PLUGIN_URL . 'assets/app/app.js') . ');', 'before');
});

register_activation_hook(__FILE__, function(){
  if (function_exists('wmp2_install_rotation_table')) {
    wmp2_install_rotation_table();
  }
});

register_deactivation_hook(__FILE__, function(){
  flush_rewrite_rules();
});

add_action('init', 'wmp2_register_media_taxonomy');

add_action('rest_api_init', function() {
  if (function_exists('wmp2_register_categories_routes')) {
    wmp2_register_categories_routes();
  }
});

add_action('init', function(){
  wmp2_register_cpts();
  wmp2_register_taxonomies();
  wmp2_register_player_route();
});

add_action('rest_api_init', function(){
  wmp2_rest_init();
});

add_action('rest_api_init', function () {
  if (function_exists('wmp2_register_attachments_route')) {
    wmp2_register_attachments_route();
  }
});

/**
 * Front-end editor shell shortcode
 * Put [wmp_app] on a WP page and restrict it to logged-in users.
 */
add_shortcode('wmp_app', function(){
  if (!is_user_logged_in()) {
    return '<div style="padding:16px;font-family:system-ui">Please log in.</div>';
  }

  // Needed for the WP media modal on the front-end
  wp_enqueue_media();

  wp_enqueue_style('wmp2-app', WMP2_URL.'assets/app/app.css', [], WMP2_VERSION);
  wp_enqueue_script('wmp2-app', WMP2_URL.'assets/app/app.js', ['jquery'], WMP2_VERSION, true);

  wp_localize_script('wmp2-app', 'WMP2', [
    'rest' => esc_url_raw(rest_url('wmp/v2/')),
    'nonce' => wp_create_nonce('wp_rest'),
    'site_url' => home_url('/'),
  ]);

  return '<div id="wmp2-app" class="wmp2-app">
    <div class="wmp2-header">
      <div class="wmp2-title">WP Marquee Playlists v2</div>
      <div class="wmp2-actions">
        <button id="wmp2-create" class="wmp2-btn">New Playlist</button>
      </div>
    </div>
    <div id="wmp2-main" class="wmp2-main">
      <div class="wmp2-col">
        <h3>Your Playlists</h3>
        <div id="wmp2-list" class="wmp2-card"></div>
      </div>
      <div class="wmp2-col">
        <h3>Details</h3>
        <div id="wmp2-detail" class="wmp2-card">Select a playlistâ€¦</div>
      </div>
    </div>
  </div>';
});
