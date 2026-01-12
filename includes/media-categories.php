<?php
if (!defined('ABSPATH')) exit;

function wmp2_register_media_taxonomy() {
  register_taxonomy('wmp_media_cat', ['attachment','wmp_slide'], [
    'label' => 'WMP Categories',
    'public' => false,
    'show_ui' => true,
    'show_in_rest' => true,
    'hierarchical' => false,
    'rewrite' => false,
    'capabilities' => [
      'manage_terms' => 'upload_files',
      'edit_terms'   => 'upload_files',
      'delete_terms' => 'upload_files',
      'assign_terms' => 'upload_files',
    ],
  ]);
}
