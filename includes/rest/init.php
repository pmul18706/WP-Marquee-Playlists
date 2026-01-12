<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/playlists.php';
require_once __DIR__ . '/items.php';
require_once __DIR__ . '/slides.php';
require_once __DIR__ . '/members.php';
require_once __DIR__ . '/weather.php';

function wmp2_rest_init(){
  wmp2_rest_playlists_routes();
  wmp2_rest_items_routes();
  wmp2_rest_slides_routes();
  wmp2_rest_members_routes();
  wmp2_rest_weather_routes();
}
