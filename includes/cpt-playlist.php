<?php
if (!defined('ABSPATH')) exit;

function wmp2_register_cpts(){
  register_post_type('wmp_playlist', [
    'label' => 'WMP Playlists',
    'public' => false,
    'show_ui' => false,
    'supports' => ['title'],
  ]);

  register_post_type('wmp_slide', [
    'label' => 'WMP Slides',
    'public' => false,
    'show_ui' => false,
    'supports' => ['title', 'editor'],
  ]);
}
