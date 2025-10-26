<?php
/**
 * Plugin Name: CS OpenPOS Local Backup
 * Description: Respaldo local (offline/online) de órdenes de OpenPOS: .json en disco (FS Access) + índice en IndexedDB + visor de cierre.
 * Version: 1.2.0
 * Author: ClubSams
 */

if (!defined('ABSPATH')) exit;

define('CSFX_LB_VERSION', '1.2.0');
define('CSFX_LB_URL', plugin_dir_url(__FILE__));
define('CSFX_LB_PATH', plugin_dir_path(__FILE__));

/**
 * Determina si la URL actual corresponde al POS de OpenPOS.
 */
function csfx_lb_is_pos_request() {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $uri  = strtolower($_SERVER['REQUEST_URI'] ?? '');
  $page = isset($_GET['page']) ? strtolower((string) $_GET['page']) : '';

  if (isset($_GET['openpos']) && $_GET['openpos'] !== '') {
    return $cached = true;
  }

  if ($page && strpos($page, 'openpos') !== false) {
    return $cached = true;
  }

  $slugs = array('openpos', 'openpos_sw', 'openpos_kitchen', 'openpos_bill', 'pos');
  $configured = get_option('openpos_base');
  if (is_string($configured)) {
    $configured = trim(strtolower($configured));
    if ($configured !== '' && !in_array($configured, $slugs, true)) {
      $slugs[] = $configured;
    }
  }

  if ($uri) {
    foreach ($slugs as $slug) {
      if ($slug === '') {
        continue;
      }

      if (preg_match('#/' . preg_quote($slug, '#') . '(?:/|\\?|$)#', $uri)) {
        return $cached = true;
      }

      if (strpos($uri, 'page=' . $slug) !== false) {
        return $cached = true;
      }

      if (strpos($uri, '?' . $slug . '=') !== false || strpos($uri, '&' . $slug . '=') !== false) {
        return $cached = true;
      }
    }
  }

  return $cached = false;
}

/**
 * Encola el JS del respaldo SOLO en la pantalla del POS (ajusta la heurística según tu instalación).
 */
function csfx_lb_enqueue_assets() {
  if (!csfx_lb_is_pos_request()) return;

  $asset_path = CSFX_LB_PATH . 'assets/csfx-local-backup.js';
  $version = file_exists($asset_path) ? (string) filemtime($asset_path) : CSFX_LB_VERSION;

  wp_enqueue_script(
    'csfx-local-backup',
    CSFX_LB_URL . 'assets/csfx-local-backup.js',
    array(),
    $version,
    true
  );

  static $localized = false;
  if (!$localized) {
  $checkout_actions = array(
    'new-order',
    'pending-order',
    'payment-order',
    'payment-cc-order',
    'order',
    'order/create',
    'transaction',
    'transaction/create'
  );
    $current_user     = wp_get_current_user();
    $staff_login      = $current_user && $current_user->exists() ? $current_user->user_login : '';
    $staff_display    = $current_user && $current_user->exists() ? $current_user->display_name : '';
    $staff_email      = $current_user && $current_user->exists() ? $current_user->user_email : '';

    $restful_enabled  = apply_filters('pos_enable_rest_ful', true);
    $strict_endpoints = apply_filters('csfx_lb_strict_endpoints', true);
    $network_hooks    = apply_filters('csfx_lb_network_hooks', 'none');
    $confirm_response = apply_filters('csfx_lb_confirm_on_response', null);
    $write_pending_fs = apply_filters('csfx_lb_write_pending_fs', 'always');
    $require_folder   = apply_filters('csfx_lb_require_backup_folder', true);
    $require_fs       = apply_filters('csfx_lb_require_backup_fs', false);
    $prune_every_puts = (int) apply_filters('csfx_lb_prune_every_n_puts', 50);
    $debounce_ms      = (int) apply_filters('csfx_lb_debounce_ms', 180);

    wp_localize_script(
      'csfx-local-backup',
      'CSFX_LB_SETTINGS',
      array(
        'checkout_actions' => $checkout_actions,
        'restful_enabled'  => $restful_enabled,
        'strict_endpoints' => (bool) $strict_endpoints,
        'network_hooks'    => $network_hooks,
        'confirm_on_response' => is_null($confirm_response) ? null : (bool) $confirm_response,
        'write_pending_fs' => $write_pending_fs,
        'require_backup_folder' => (bool) $require_folder,
        'require_backup_fs' => (bool) $require_fs,
        'prune_every_n_puts' => max(1, $prune_every_puts),
        'debounce_ms' => max(0, $debounce_ms),
        'staff_login' => $staff_login,
        'staff_display' => $staff_display,
        'staff_email' => $staff_email,
      )
    );
    $localized = true;
  }
}
add_action('admin_enqueue_scripts', 'csfx_lb_enqueue_assets');
add_action('wp_enqueue_scripts', 'csfx_lb_enqueue_assets');

/**
 * Inyecta el shim de IndexedDB antes del main.js del POS.
 */
add_filter('openpos_pos_header_js', function($handles){
  $tap_path = CSFX_LB_PATH . 'assets/csfx-idb-tap.js';
  $tap_ver  = file_exists($tap_path) ? (string) filemtime($tap_path) : CSFX_LB_VERSION;

  if (!wp_script_is('csfx-idb-tap', 'registered')) {
    wp_register_script('csfx-idb-tap', CSFX_LB_URL . 'assets/csfx-idb-tap.js', array(), $tap_ver, false);
  }

  if (!wp_script_is('csfx-idb-tap', 'enqueued')) {
    wp_enqueue_script('csfx-idb-tap');
  }

  if (!in_array('csfx-idb-tap', $handles, true)) {
    array_unshift($handles, 'csfx-idb-tap');
  }

  return $handles;
}, 1);

/**
 * Añade el script al listado de OpenPOS (POS template) para asegurar carga en todas las variantes.
 */
add_filter('openpos_pos_footer_js', function($handles){
  csfx_lb_enqueue_assets();
  if (!in_array('csfx-local-backup', $handles, true)) {
    $handles[] = 'csfx-local-backup';
  }
  return $handles;
});

/**
 * Admin Menu: Estado/Checklist + Cierre Diario (abre visor HTML en nueva pestaña).
 */
add_action('admin_menu', function(){
  add_menu_page(
    'CSFX Local Backup', 'CSFX Local Backup', 'manage_options', 'csfx-lb', 'csfx_lb_render_status', 'dashicons-backup'
  );
  add_submenu_page('csfx-lb', 'Estado / Checklist', 'Estado / Checklist', 'manage_options', 'csfx-lb', 'csfx_lb_render_status');
  add_submenu_page('csfx-lb', 'Cierre Diario', 'Cierre Diario', 'manage_options', 'csfx-lb-cierre', 'csfx_lb_render_cierre');
});

/**
 * Estado/Checklist: guardado en wp_options para marcar avance por fases.
 */
function csfx_lb_default_status() {
  return [
    'phase1' => [
      'fs_access'  => 1,
      'indexeddb'  => 1,
      'intercept'  => 1,
      'dedupe'     => 1,
      'day_folder' => 1,
      'device_id'  => 1,
      'ui_badge'   => 1,
      'viewer'     => 1,
      'node_script'=> 1
    ],
    'phase2' => [
      'compare_wc'  => 0,
      'encryption'  => 0,
      'reprint'     => 0,
      'reimport'    => 0,
      'broadcast'   => 0
    ]
  ];
}

function csfx_lb_get_status(){
  $s = get_option('csfx_lb_status');
  if (!$s) { $s = csfx_lb_default_status(); update_option('csfx_lb_status', $s); }
  return $s;
}

function csfx_lb_render_status(){
  if (!current_user_can('manage_options')) return;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('csfx_lb_save')) {
    $s = csfx_lb_get_status();
    foreach (['phase1','phase2'] as $ph) {
      foreach ($s[$ph] as $k=>$v) {
        $s[$ph][$k] = isset($_POST["$ph-$k"]) ? 1 : 0;
      }
    }
    update_option('csfx_lb_status', $s);
    echo '<div class="updated"><p>Checklist guardado.</p></div>';
  }

  $s = csfx_lb_get_status();
  echo '<div class="wrap"><h1>CSFX Local Backup – Estado</h1>';
  echo '<p>Versión ' . esc_html(CSFX_LB_VERSION) . '</p>';
  echo '<form method="post">';
  wp_nonce_field('csfx_lb_save');

  echo '<h2>Fase 1 (MVP)</h2><table class="form-table">';
  foreach ($s['phase1'] as $k=>$v){
    echo '<tr><th>'.esc_html($k).'</th><td><label><input type="checkbox" name="phase1-'.$k.'" '.checked($v,1,false).'> Completado</label></td></tr>';
  }
  echo '</table>';

  echo '<h2>Fase 2 (Extensiones)</h2><table class="form-table">';
  foreach ($s['phase2'] as $k=>$v){
    echo '<tr><th>'.esc_html($k).'</th><td><label><input type="checkbox" name="phase2-'.$k.'" '.checked($v,1,false).'> Completado</label></td></tr>';
  }
  echo '</table>';

  echo '<p><button class="button button-primary">Guardar</button></p>';
  echo '</form>';

  echo '<hr>';
  echo '<p><a class="button button-secondary" target="_blank" href="'.esc_url(CSFX_LB_URL.'assets/csfx-cierre.html').'">Abrir visor de Cierre (nueva pestaña)</a></p>';
  echo '<p class="description">Sugerido: Chrome/Edge en escritorio. File System Access requiere HTTPS o localhost.</p>';
  echo '</div>';
}

function csfx_lb_render_cierre(){
  if (!current_user_can('manage_options')) return;
  echo '<div class="wrap"><h1>Cierre Diario</h1>';
  echo '<p>Abre el visor en una nueva pestaña para habilitar File System Access:</p>';
  echo '<p><a class="button button-primary" target="_blank" href="'.esc_url(CSFX_LB_URL.'assets/csfx-cierre.html').'">Abrir visor</a></p>';
  echo '</div>';
}
