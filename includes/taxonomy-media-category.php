<?php
if (!defined('ABSPATH')) exit;

function wmp2_register_taxonomies(){
  register_taxonomy('wmp_media_category', ['attachment'], [
    'label' => 'WMP Media Categories',
    'public' => false,
    'show_ui' => false,
    'hierarchical' => false,
  ]);
}
