<?php
/**
 * Plugin Name: CS OpenPOS Local Backup
 * Description: Respaldo local (offline/online) de órdenes de OpenPOS: .json en disco (FS Access) + índice en IndexedDB + visor de cierre.
 * Version: 1.0.0
 * Author: ClubSams
 */

if (!defined('ABSPATH')) exit;

define('CSFX_LB_VERSION', '1.0.0');
define('CSFX_LB_URL', plugin_dir_url(__FILE__));
define('CSFX_LB_PATH', plugin_dir_path(__FILE__));

/**
 * Determina si la URL actual corresponde al POS de OpenPOS.
 */
function csfx_lb_is_pos_request() {
  $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
  if (!$uri) return false;

  if (strpos($uri, '/pos/') !== false) return true;
  if (strpos($uri, 'page=openpos') !== false) return true;
  if (strpos($uri, 'openpos') !== false) return true;

  $page = isset($_GET['page']) ? strtolower((string) $_GET['page']) : '';
  if ($page && strpos($page, 'openpos') !== false) return true;

  return false;
}

/**
 * Encola el JS del respaldo SOLO en la pantalla del POS (ajusta la heurística según tu instalación).
 */
function csfx_lb_enqueue_assets() {
  if (!csfx_lb_is_pos_request()) return;

  wp_enqueue_script(
    'csfx-local-backup',
    CSFX_LB_URL . 'assets/csfx-local-backup.js',
    array(),
    CSFX_LB_VERSION,
    true
  );
}
add_action('admin_enqueue_scripts', 'csfx_lb_enqueue_assets');
add_action('wp_enqueue_scripts', 'csfx_lb_enqueue_assets');

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
