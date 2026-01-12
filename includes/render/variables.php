<?php
if (!defined('ABSPATH')) exit;

function wmp2_weather_code_label($code) {
  $code = intval($code);
  $map = [
    0=>'Clear', 1=>'Mostly clear', 2=>'Partly cloudy', 3=>'Overcast',
    45=>'Fog', 48=>'Fog',
    51=>'Light drizzle', 53=>'Drizzle', 55=>'Heavy drizzle',
    61=>'Light rain', 63=>'Rain', 65=>'Heavy rain',
    71=>'Light snow', 73=>'Snow', 75=>'Heavy snow',
    80=>'Rain showers', 81=>'Rain showers', 82=>'Violent showers',
    95=>'Thunderstorm', 96=>'Thunderstorm hail', 99=>'Thunderstorm hail',
  ];
  return $map[$code] ?? 'Weather';
}

function wmp2_fetch_weather_cached($lat, $lon) {
  $lat = floatval($lat);
  $lon = floatval($lon);
  if (!$lat || !$lon) return null;

  $key = 'wmp2_weather_' . md5($lat . ',' . $lon);
  $cached = get_transient($key);
  if (is_array($cached)) return $cached;

  $url = add_query_arg([
    'latitude' => $lat,
    'longitude' => $lon,
    'current_weather' => 'true',
    'temperature_unit' => 'fahrenheit',
    'windspeed_unit' => 'mph',
    'timezone' => 'auto',
  ], 'https://api.open-meteo.com/v1/forecast');

  $resp = wp_remote_get($url, ['timeout' => 8]);
  if (is_wp_error($resp)) return null;

  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);
  if (!is_array($json) || empty($json['current_weather'])) return null;

  $cw = $json['current_weather'];
  $temp_f = isset($cw['temperature']) ? floatval($cw['temperature']) : null;
  $temp_c = ($temp_f !== null) ? (($temp_f - 32) * 5/9) : null;

  $out = [
    'temp_f' => ($temp_f !== null) ? round($temp_f) : '',
    'temp_c' => ($temp_c !== null) ? round($temp_c) : '',
    'summary' => wmp2_weather_code_label($cw['weathercode'] ?? null),
    'updated' => isset($cw['time']) ? (string)$cw['time'] : '',
  ];

  set_transient($key, $out, 10 * MINUTE_IN_SECONDS);
  return $out;
}

/**
 * Apply variables into slide HTML.
 * Expects $playlist to be an array like ['id'=>..,'name'=>..,'options'=>[..]]
 */
function wmp2_apply_variables($playlist, $html) {
  $html = (string)$html;
  $now = current_time('timestamp');

  $playlist_name = '';
  $options = [];

  if (is_array($playlist)) {
    $playlist_name = isset($playlist['name']) ? (string)$playlist['name'] : '';
    $options = (isset($playlist['options']) && is_array($playlist['options'])) ? $playlist['options'] : [];
  }

  $vars = [
    '{{date}}' => wp_date('F j, Y', $now),
    '{{time}}' => wp_date('g:i A', $now),
    '{{datetime}}' => wp_date('F j, Y g:i A', $now),
    '{{playlist.name}}' => $playlist_name,
  ];

  // weather_location: "lat,lon"
  $weather = ['temp_f'=>'','temp_c'=>'','summary'=>'','updated'=>''];
  $loc = isset($options['weather_location']) ? trim((string)$options['weather_location']) : '';
  if ($loc && preg_match('/^\s*(-?\d+(\.\d+)?)\s*,\s*(-?\d+(\.\d+)?)\s*$/', $loc, $m)) {
    $w = wmp2_fetch_weather_cached($m[1], $m[3]);
    if (is_array($w)) $weather = array_merge($weather, $w);
  }

  $vars['{{weather.temp_f}}'] = (string)$weather['temp_f'];
  $vars['{{weather.temp_c}}'] = (string)$weather['temp_c'];
  $vars['{{weather.summary}}'] = (string)$weather['summary'];
  $vars['{{weather.updated}}'] = (string)$weather['updated'];

  return strtr($html, $vars);
}
