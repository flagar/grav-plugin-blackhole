<?php

// flatten a multidimensional array
function array_flatten($array = null) {
  $result = array();
  if (!is_array($array)) {
    $array = func_get_args();
  }
  foreach ($array as $key => $value) {
    if (is_array($value)) {
      $result = array_merge($result, array_flatten($value));
    } else {
      $result = array_merge($result, array($key => $value));
    }
  }
  return $result;
}

// curl
function pull($light) {
  $pull = curl_init();
  curl_setopt($pull, CURLOPT_URL, $light);
  curl_setopt($pull, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($pull, CURLOPT_FOLLOWLOCATION, 1);
  $emit = curl_exec($pull);
  curl_close($pull);
  return $emit;
}

// swap links
function portal($in, $out, $content) {
  // input_url -> output_url
  $content = (!empty($out)
    ? str_ireplace($in, $out, $content)
    : $content
  );
  // taxonomy:child -> taxonomy/child
  /*$content = preg_replace_callback(
    '~href=".*?"~',
    function ($matches) { return preg_replace('~(.*?/.*?):(.*?)~', "$1/$2", $matches)[0]; },
    $content
  );*/
  return $content;
}

// get links to assets
function tidal_disruption($data, $elements, $attribute) {
  $doc = new \DOMDocument();
  @$doc->loadHTML($data);
  $links = array();
  foreach($doc->getElementsByTagName($elements) as $element) {
    if ($element->getAttribute('rel') !== 'canonical') {
      $links[] = $element->getAttribute($attribute);
    }
  }
  return $links;
}

// generate files
function generate($route, $path, $data) {
  if (!is_dir($route)) { mkdir($route, 0755, true); }
  file_put_contents($path, $data);
}

// generate pages
function pages($that, $route, $path, $path_origin, $data, $force) {
  // page exists
  if (file_exists($path) && !$force) {
    switch (true) {
      // page was changed: copy the new one
      case filemtime($path_origin) > filemtime($path):
        generate($route, $path, $data);
        $that->writeln('<green>REGENERATING</green> ➜ ' . realpath($route));
        break;
      // no page changes: skip it
      default:
        $that->writeln('<cyan>SKIPPING</cyan> no changes ➜ ' . realpath($route));
        break;
    }
  // page doesn't exist or force option is enabled
  } else {
    // copy the page
    generate($route, $path, $data);
    $that->writeln('<green>GENERATING</green> ➜ ' . realpath($route));
  }
  if (empty($data)) {
    $that->writeln('<red>ATTENTION! EMPTY CONTENTS</red> ➜ ' . realpath($route));
  }
}

// generate assets
function assets($that, $event_horizon, $input_url, $data) {
  //print_r($data);
  $asset_links = array();
  $asset_links[] = tidal_disruption($data, 'link', 'href');
  $asset_links[] = tidal_disruption($data, 'script', 'src');
  $asset_links[] = tidal_disruption($data, 'img', 'src');
  $input_url_parts = parse_url($input_url);
  foreach (array_flatten($asset_links) as $asset) {
    //print_r($asset);
    if (
      strpos($asset, 'data:') !== 0 && // exclude data URIs
      (strpos($asset, '/') === 0 || $input_url_parts['host'] === parse_url($asset)['host']) // continue if asset is local
    ) {
      $asset_path = ltrim(str_ireplace($input_url_parts['path'], '', str_ireplace($input_url, '', $asset)),'/');
      //$asset_file_origin = GRAV_ROOT . substr($asset, strpos($asset, basename(GRAV_ROOT)) + strlen(basename(GRAV_ROOT)));
      //$asset_file_destination = $event_horizon . substr($asset, strpos($asset, basename(GRAV_ROOT)) + strlen(basename(GRAV_ROOT)));
      $asset_file_origin = rtrim(GRAV_ROOT, '/').'/'.$asset_path;
      $asset_file_destination = rtrim($event_horizon, '/').'/'.$asset_path;
      //print_r($asset_file_origin).PHP_EOL;
      //print_r($asset_file_destination).PHP_EOL;
      $asset_route = str_replace(basename($asset_file_destination), '', $asset_file_destination);
      $query_string_pos = strpos($asset_file_destination, '?');
      if ($query_string_pos > 0) {
          $asset_file_destination = substr($asset_file_destination, 0, $query_string_pos);
      }
      // asset exists
      if (file_exists($asset_file_destination)) {
        switch (true) {
          // asset was changed: copy the new one
          case filemtime($asset_file_origin) > filemtime($asset_file_destination):
            generate($asset_route, $asset_file_destination, file_get_contents($asset));
            break;
          // no asset changes: skip it
          default:
            break;
        }
      // asset doesn't exist
      } else {
        generate($asset_route, $asset_file_destination, file_get_contents($asset));
      }
    }
  }
}

// generate taxonomies
function taxonomies($that, $route, $path, $data) {
  generate($route, $path, $data);
  $that->writeln('<green>GENERATING</green> ➜ ' . realpath($route));
}
