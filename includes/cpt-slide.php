<?php
if (!defined('ABSPATH')) exit;

function wmp2_register_cpt_slide(){
  register_post_type('wmp_slide', [
    'label' => 'WMP Slides',
    'public' => false,
    'show_ui' => false,
    'supports' => ['title', 'editor'],
  ]);
}

add_action('init', 'wmp2_register_cpt_slide');
