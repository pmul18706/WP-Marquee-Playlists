<?php
if (!defined('ABSPATH')) exit;

function wmp2_rest_weather_routes(){
  register_rest_route('wmp/v2', '/weather', [
    [
      'methods' => 'GET',
      'permission_callback' => function(){ return true; },
      'callback' => 'wmp2_rest_weather',
    ],
  ]);
}

function wmp2_rest_weather(WP_REST_Request $req){
  $playlist_id = intval($req->get_param('playlist_id') ?: 0);

  $location = '';
  if ($playlist_id > 0){
    $opts = get_post_meta($playlist_id, '_wmp_options', true);
    if (is_array($opts) && !empty($opts['location'])) $location = sanitize_text_field($opts['location']);
  }
  if (!$location){
    $location = sanitize_text_field($req->get_param('location') ?: 'Wilkes-Barre, PA');
  }

  $cache_key = 'wmp2_weather_' . md5(strtolower(trim($location)));
  $cached = get_transient($cache_key);
  if ($cached) return rest_ensure_response($cached);

  $geo_url = add_query_arg([
    'name' => $location,
    'count' => 1,
    'language' => 'en',
    'format' => 'json',
  ], 'https://geocoding-api.open-meteo.com/v1/search');

  $geo_res = wp_remote_get($geo_url, ['timeout'=>10]);
  if (is_wp_error($geo_res)) {
    return new WP_Error('weather_geo_failed', $geo_res->get_error_message(), ['status'=>502]);
  }

  $geo_body = json_decode(wp_remote_retrieve_body($geo_res), true);
  $first = $geo_body['results'][0] ?? null;
  if (!$first || !isset($first['latitude'], $first['longitude'])){
    return new WP_Error('weather_geo_not_found', 'Location not found', ['status'=>404]);
  }

  $lat = floatval($first['latitude']);
  $lon = floatval($first['longitude']);

  $wx_url = add_query_arg([
    'latitude' => $lat,
    'longitude' => $lon,
    'current' => 'temperature_2m,weather_code,wind_speed_10m',
    'temperature_unit' => 'fahrenheit',
    'wind_speed_unit' => 'mph',
    'timezone' => 'auto',
  ], 'https://api.open-meteo.com/v1/forecast');

  $wx_res = wp_remote_get($wx_url, ['timeout'=>10]);
  if (is_wp_error($wx_res)) {
    return new WP_Error('weather_fetch_failed', $wx_res->get_error_message(), ['status'=>502]);
  }

  $wx_body = json_decode(wp_remote_retrieve_body($wx_res), true);
  $cur = $wx_body['current'] ?? null;
  if (!$cur) return new WP_Error('weather_bad_response', 'Weather response missing current data', ['status'=>502]);

  $out = [
    'location' => [
      'name' => $first['name'] ?? $location,
      'admin1' => $first['admin1'] ?? '',
      'country' => $first['country'] ?? '',
      'latitude' => $lat,
      'longitude' => $lon,
    ],
    'temp_f' => isset($cur['temperature_2m']) ? floatval($cur['temperature_2m']) : null,
    'wind_mph' => isset($cur['wind_speed_10m']) ? floatval($cur['wind_speed_10m']) : null,
    'weather_code' => $cur['weather_code'] ?? null,
    'updated_at' => $cur['time'] ?? gmdate('c'),
  ];

  set_transient($cache_key, $out, 15 * MINUTE_IN_SECONDS);
  return rest_ensure_response($out);
}
