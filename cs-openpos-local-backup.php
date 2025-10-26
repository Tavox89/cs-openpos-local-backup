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

  $viewer_url = admin_url('admin-post.php?action=csfx_lb_viewer');

  echo '<div class="wrap csfx-lb-viewer-wrap" style="margin:0;padding:0;max-width:none">';
  echo '<h1 style="margin:20px 0 12px">Cierre Diario</h1>';
  echo '<p class="description" style="margin-bottom:16px">El visor se carga dentro del panel para reutilizar tu sesión actual. Si prefieres abrirlo en una pestaña aparte, usa el botón:</p>';
  echo '<p><a class="button button-primary" target="_blank" href="'.esc_url($viewer_url).'">Abrir visor en pestaña nueva</a></p>';
  echo '<div style="margin:20px -20px -20px; height:calc(100vh - 200px); border-top:1px solid #ccd0d4">';
  echo '<iframe src="'.esc_url($viewer_url).'" style="border:0;width:100%;height:100%" title="CSFX Cierre"></iframe>';
  echo '</div>';
  echo '</div>';
}

add_action('admin_post_csfx_lb_viewer', 'csfx_lb_stream_cierre_viewer');

function csfx_lb_stream_cierre_viewer(){
  if (!is_user_logged_in()) {
    auth_redirect();
  }

  if (!current_user_can('manage_options')) {
    wp_die(__('No tienes permisos suficientes para ver el cierre.', 'csfx-lb'));
  }

  $path = CSFX_LB_PATH . 'assets/csfx-cierre.html';
  if (!file_exists($path)) {
    wp_die(__('No se encontró el visor de cierre.', 'csfx-lb'));
  }

  header('Content-Type: text/html; charset=UTF-8');
  header('X-Frame-Options: SAMEORIGIN');

  $html = file_get_contents($path);
  if ($html === false) {
    wp_die(__('No se pudo cargar el visor de cierre.', 'csfx-lb'));
  }

  $context = array(
    'nonce'   => wp_create_nonce('wp_rest'),
    'user'    => array(
      'id'      => get_current_user_id(),
      'login'   => wp_get_current_user()->user_login,
      'display' => wp_get_current_user()->display_name,
      'email'   => wp_get_current_user()->user_email,
    ),
    'restRoot' => esc_url_raw(rest_url('csfx-lb/v1')),
    'siteUrl'  => esc_url_raw(site_url()),
  );

  $bootstrap = '<script>window.CSFX_LB_EMBED_CONTEXT = ' . wp_json_encode($context) . ';</script>';
  $html = preg_replace('/<head>/', '<head>' . $bootstrap, $html, 1);
  echo $html;
  exit;
}

add_action('rest_api_init', 'csfx_lb_register_rest_routes');

function csfx_lb_register_rest_routes(){
  register_rest_route(
    'csfx-lb/v1',
    '/context',
    array(
      'methods'             => WP_REST_Server::READABLE,
      'permission_callback' => 'csfx_lb_rest_permission',
      'callback'            => 'csfx_lb_rest_context'
    )
  );

  register_rest_route(
    'csfx-lb/v1',
    '/verify-order',
    array(
      'methods'             => WP_REST_Server::CREATABLE,
      'permission_callback' => 'csfx_lb_rest_permission',
      'callback'            => 'csfx_lb_rest_verify_order'
    )
  );

  register_rest_route(
    'csfx-lb/v1',
    '/resync-order',
    array(
      'methods'             => WP_REST_Server::CREATABLE,
      'permission_callback' => 'csfx_lb_rest_permission',
      'callback'            => 'csfx_lb_rest_resync_order'
    )
  );
}

function csfx_lb_rest_permission(){
  return current_user_can('manage_woocommerce') || current_user_can('manage_options');
}

function csfx_lb_rest_context(){
  $user = wp_get_current_user();
  return rest_ensure_response(array(
    'nonce' => wp_create_nonce('wp_rest'),
    'user'  => array(
      'id'      => $user ? $user->ID : 0,
      'login'   => $user ? $user->user_login : '',
      'display' => $user ? $user->display_name : '',
      'email'   => $user ? $user->user_email : ''
    )
  ));
}

function csfx_lb_rest_verify_order( WP_REST_Request $request ){
  return csfx_lb_handle_verify_request( $request, 'verify' );
}

function csfx_lb_rest_resync_order( WP_REST_Request $request ){
  return csfx_lb_handle_verify_request( $request, 'resync' );
}

function csfx_lb_handle_verify_request( WP_REST_Request $request, $mode = 'verify' ){
  if ( ! function_exists( 'wc_get_order' ) ) {
    return new WP_Error( 'csfx_lb_missing_wc', 'WooCommerce no está activo.', array( 'status' => 500 ) );
  }

  $payload = $request->get_json_params();
  if ( ! is_array( $payload ) ) {
    return new WP_Error( 'csfx_lb_invalid_body', 'Cuerpo de solicitud inválido.', array( 'status' => 400 ) );
  }

  $doc = array();
  if ( isset( $payload['doc'] ) && is_array( $payload['doc'] ) ) {
    $doc = $payload['doc'];
  }

  $order_id     = isset( $payload['orderId'] ) ? absint( $payload['orderId'] ) : 0;
  $order_number = isset( $payload['orderNumber'] ) ? sanitize_text_field( $payload['orderNumber'] ) : '';

  if ( ! $order_id && ! $order_number ) {
    $order_id     = isset( $doc['orderId'] ) ? absint( $doc['orderId'] ) : 0;
    $order_number = isset( $doc['orderNumber'] ) ? sanitize_text_field( $doc['orderNumber'] ) : '';
    if ( ! $order_number && isset( $doc['order_id'] ) ) {
      $order_number = sanitize_text_field( $doc['order_id'] );
    }
  }

  if ( ! $order_id && ! $order_number ) {
    return new WP_Error( 'csfx_lb_missing_order', 'No se pudo determinar el número de pedido.', array( 'status' => 400 ) );
  }

  $order = csfx_lb_find_order( $order_id, $order_number, $doc );

  $created_during_resync = false;

  if ( ! $order ) {
    if ( $mode === 'resync' ) {
      $created = csfx_lb_create_order_from_doc( $doc );
      if ( is_wp_error( $created ) ) {
        return rest_ensure_response( array(
          'status'      => 'error',
          'message'     => $created->get_error_message(),
          'orderId'     => 0,
          'orderNumber' => $order_number,
          'differences' => array(),
          'duplicates'  => array(),
          'warnings'    => array( 'Resync fallido: no se pudo crear el pedido.' ),
          'note_added'  => false,
          'resync'      => true,
        ) );
      }
      $order = $created;
      $created_during_resync = true;
      $order_id     = $order->get_id();
      $order_number = $order->get_order_number();
    } else {
      return rest_ensure_response( array(
        'status'        => 'missing',
        'message'       => 'Pedido no encontrado en WooCommerce.',
        'orderId'       => $order_id,
        'orderNumber'   => $order_number,
        'differences'   => array(),
        'duplicates'    => array(),
        'warnings'      => array(),
        'note_added'    => false,
        'resync'        => false
      ) );
    }
  }

  $comparison = csfx_lb_compare_order_with_doc( $order, $doc );

  csfx_lb_sync_cashier_meta( $order, $doc );

  $status = 'verified';
  if ( ! $comparison['matches'] ) {
    $status = 'mismatch';
  } elseif ( ! empty( $comparison['warnings'] ) ) {
    $status = 'verified_with_warnings';
  }

  $note_added = false;
  $should_add_note = ! empty( $payload['addNote'] ) || ( $mode === 'verify' && ! empty( $payload['autoNote'] ) );
  if ( $should_add_note && ( $status === 'verified' || $status === 'verified_with_warnings' ) ) {
    $origin  = isset( $doc['action'] ) && $doc['action'] === 'offline-idb' ? 'offline' : 'online';
    if ( isset( $doc['rawRequest']['action'] ) && $doc['rawRequest']['action'] === 'offline-idb' ) {
      $origin = 'offline';
    }
    $note_parts = array();
    $note_parts[] = sprintf( 'CSFX Local Backup: Cierre verificado %s.', $origin );
    if ( ! empty( $comparison['warnings'] ) ) {
      $note_parts[] = 'Advertencias: ' . implode( '; ', $comparison['warnings'] );
    }
    $order->add_order_note( implode( ' ', $note_parts ) );
    $note_added = true;
  }

  $resync_note_added = false;
  if ( $mode === 'resync' ) {
    $order->add_order_note( 'CSFX Local Backup: Resync ejecutado desde el visor de cierre.' );
    $resync_note_added = true;
  }

  return rest_ensure_response( array(
    'status'        => $status,
    'orderId'       => $order->get_id(),
    'orderNumber'   => $order->get_order_number(),
    'adminUrl'      => $order->get_edit_order_url(),
    'differences'   => $comparison['differences'],
    'totals'        => $comparison['totals'],
    'items'         => $comparison['items'],
    'payments'      => $comparison['payments'],
    'duplicates'    => $comparison['duplicates'],
    'warnings'      => $comparison['warnings'],
    'note_added'    => $note_added,
    'resync'        => $mode === 'resync',
    'resync_note'   => $resync_note_added,
    'created_order' => $created_during_resync,
  ) );
}

function csfx_lb_find_order( $order_id, $order_number, $doc = array() ){
  $order = null;
  if ( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order instanceof WC_Order ) {
      return $order;
    }
  }

  if ( $order_number ) {
    $order_candidate = wc_get_order( $order_number );
    if ( $order_candidate instanceof WC_Order ) {
      return $order_candidate;
    }
  }

  $meta_values = array();
  if ( $order_number ) {
    $meta_values[] = $order_number;
  }
  if ( isset( $doc['orderNumber'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['orderNumber'] );
  }
  if ( isset( $doc['orderNumberFormat'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['orderNumberFormat'] );
  }
  if ( isset( $doc['order_number_format'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['order_number_format'] );
  }
  if ( isset( $doc['orderData']['order_number'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['orderData']['order_number'] );
  }
  if ( isset( $doc['orderData']['order_number_format'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['orderData']['order_number_format'] );
  }
  if ( isset( $doc['orderLocalId'] ) ) {
    $meta_values[] = sanitize_text_field( $doc['orderLocalId'] );
  }

  $meta_keys = array(
    '_openpos_order_number',
    '_order_number',
    '_order_number_formatted',
    '_op_order_number',
    '_op_local_order_id',
    '_op_local_order_number'
  );

  foreach ( $meta_values as $value ) {
    if ( $value === '' ) {
      continue;
    }
    foreach ( $meta_keys as $key ) {
      $orders = wc_get_orders( array(
        'limit'      => 1,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'meta_key'   => $key,
        'meta_value' => $value,
      ) );
      if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
        return $orders[0];
      }
    }
  }

  if ( $order_number && ! ctype_digit( (string) $order_number ) ) {
    $orders = wc_get_orders( array(
      'limit'          => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'search'         => '*' . $order_number . '*',
      'search_columns' => array( 'order_number' ),
    ) );
    if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
      return $orders[0];
    }
  }

  return null;
}

function csfx_lb_compare_order_with_doc( $order, $doc ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array(
      'matches'     => false,
      'differences' => array( 'Pedido de WooCommerce inválido.' ),
      'totals'      => array(),
      'items'       => array(),
      'payments'    => array(),
      'duplicates'  => array(),
      'warnings'    => array( 'No fue posible cargar la orden en WooCommerce.' ),
    );
  }
  $doc = is_array( $doc ) ? $doc : array();

  $result = array(
    'matches'     => true,
    'differences' => array(),
    'totals'      => array(),
    'items'       => array(),
    'payments'    => array(),
    'duplicates'  => array(),
    'warnings'    => array(),
  );

  $doc_totals  = csfx_lb_collect_doc_totals( $doc );
  $wc_totals   = csfx_lb_collect_wc_totals( $order );
  $totals_diff = array();

  foreach ( $doc_totals as $key => $local_value ) {
    $remote_value = isset( $wc_totals[ $key ] ) ? $wc_totals[ $key ] : null;
    $diff         = csfx_lb_numbers_differs( $local_value, $remote_value );
    $totals_diff[ $key ] = array(
      'local'           => $local_value,
      'remote'          => $remote_value,
      'match'           => $diff < 0.01,
      'difference'      => $diff,
      'localFormatted'  => csfx_lb_format_number( $local_value ),
      'remoteFormatted' => csfx_lb_format_number( $remote_value ),
    );
    if ( $diff >= 0.01 ) {
      $result['matches'] = false;
      $label             = ucfirst( str_replace( '_', ' ', $key ) );
      $result['differences'][] = sprintf(
        '%s difiere (local %s vs Woo %s)',
        $label,
        csfx_lb_format_number( $local_value ),
        csfx_lb_format_number( $remote_value )
      );
    }
  }
  $result['totals'] = $totals_diff;

  $items_comparison = csfx_lb_compare_items( $order, $doc );
  if ( ! empty( $items_comparison['differences'] ) ) {
    $result['matches']      = false;
    $result['differences']  = array_merge( $result['differences'], $items_comparison['differences'] );
  }
  $result['items']      = $items_comparison['report'];
  $result['duplicates'] = $items_comparison['duplicates'];
  $result['warnings']   = array_merge( $result['warnings'], $items_comparison['warnings'] );

  $payments_comparison = csfx_lb_compare_payments( $order, $doc );
  if ( ! empty( $payments_comparison['differences'] ) ) {
    $result['matches']     = false;
    $result['differences'] = array_merge( $result['differences'], $payments_comparison['differences'] );
  }
  $result['payments'] = $payments_comparison['report'];
  $result['warnings'] = array_merge( $result['warnings'], $payments_comparison['warnings'] );

  return $result;
}

function csfx_lb_collect_doc_totals( $doc ){
  $totals = array();
  $sources = array();
  if ( isset( $doc['totals'] ) && is_array( $doc['totals'] ) ) {
    $sources[] = $doc['totals'];
  }
  if ( isset( $doc['orderData']['totals'] ) && is_array( $doc['orderData']['totals'] ) ) {
    $sources[] = $doc['orderData']['totals'];
  }
  foreach ( $sources as $source ) {
    foreach ( array( 'sub_total', 'grand_total', 'total_paid', 'remain_paid', 'discount_amount', 'tax_amount' ) as $field ) {
      if ( isset( $source[ $field ] ) && $source[ $field ] !== '' ) {
        $totals[ $field ] = csfx_lb_to_number( $source[ $field ] );
      }
    }
  }
  return $totals;
}

function csfx_lb_collect_wc_totals( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }
  $subtotal   = csfx_lb_to_number( $order->get_subtotal() );
  $total      = csfx_lb_to_number( $order->get_total() );
  $refunded   = csfx_lb_to_number( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 );
  $total_paid = max( 0, $total - $refunded );

  $discount_total = csfx_lb_to_number( $order->get_discount_total() );
  foreach ( $order->get_items( 'fee' ) as $fee_item ) {
    $fee_total = csfx_lb_to_number( $fee_item->get_total() );
    if ( $fee_total < 0 ) {
      $discount_total += abs( $fee_total );
    }
  }

  return array(
    'sub_total'       => $subtotal,
    'grand_total'     => $total,
    'total_paid'      => $total_paid,
    'remain_paid'     => max( 0, $total - $total_paid ),
    'discount_amount' => $discount_total,
    'tax_amount'      => csfx_lb_to_number( $order->get_total_tax() ),
  );
}

function csfx_lb_compare_items( $order, $doc ){
  $report = array();
  $differences = array();
  $warnings = array();
  $duplicates = array();

  $local_items = array();
  $doc_items = array();

  if ( isset( $doc['cart']['items'] ) && is_array( $doc['cart']['items'] ) ) {
    $doc_items = $doc['cart']['items'];
  } elseif ( isset( $doc['items'] ) && is_array( $doc['items'] ) ) {
    $doc_items = $doc['items'];
  }

  foreach ( $doc_items as $item ) {
    $key = csfx_lb_item_compare_key( $item['sku'] ?? '', $item['name'] ?? '' );
    if ( ! isset( $local_items[ $key ] ) ) {
      $local_items[ $key ] = array( 'qty' => 0, 'total' => 0, 'name' => $item['name'], 'sku' => isset( $item['sku'] ) ? $item['sku'] : '' );
    }
    $local_items[ $key ]['qty'] += isset( $item['qty'] ) ? floatval( $item['qty'] ) : 0;
    $local_items[ $key ]['total'] += csfx_lb_to_number( isset( $item['total'] ) ? $item['total'] : ( ( $item['price'] ?? 0 ) * ( $item['qty'] ?? 1 ) ) );
  }

  $wc_items = array();
  if ( class_exists( 'WC_Order' ) && $order instanceof WC_Order ) {
    foreach ( $order->get_items() as $order_item ) {
      if ( ! $order_item instanceof WC_Order_Item_Product ) {
        continue;
      }
      $sku = $order_item->get_meta( '_sku', true );
      if ( ! $sku ) {
        $product = $order_item->get_product();
        if ( $product ) {
          $sku = $product->get_sku();
        }
      }
      $key = csfx_lb_item_compare_key( $sku, $order_item->get_name() );
      if ( ! isset( $wc_items[ $key ] ) ) {
        $wc_items[ $key ] = array( 'qty' => 0, 'total' => 0, 'name' => $order_item->get_name(), 'sku' => $sku );
      }
      $wc_items[ $key ]['qty'] += floatval( $order_item->get_quantity() );
      $wc_items[ $key ]['total'] += csfx_lb_to_number( $order_item->get_total() + $order_item->get_total_tax() );
    }
  }

  $all_keys = array_unique( array_merge( array_keys( $local_items ), array_keys( $wc_items ) ) );
  foreach ( $all_keys as $key ) {
    $local  = isset( $local_items[ $key ] ) ? $local_items[ $key ] : null;
    $remote = isset( $wc_items[ $key ] ) ? $wc_items[ $key ] : null;
    $qty_diff = csfx_lb_numbers_differs( $local['qty'] ?? 0, $remote['qty'] ?? 0 );
    $total_diff = csfx_lb_numbers_differs( $local['total'] ?? 0, $remote['total'] ?? 0 );
    $report[] = array(
      'key'    => $key,
      'name'   => $local['name'] ?? $remote['name'] ?? $key,
      'sku'    => $local['sku'] ?? $remote['sku'] ?? '',
      'local'  => $local,
      'remote' => $remote,
      'match'  => $qty_diff < 0.01 && $total_diff < 0.01,
    );
    if ( $qty_diff >= 0.01 || $total_diff >= 0.01 ) {
      $differences[] = sprintf(
        'Producto %s difiere: cantidades local %s / Woo %s, totales local %s / Woo %s',
        $local['name'] ?? $remote['name'] ?? $key,
        csfx_lb_format_number( $local['qty'] ?? 0 ),
        csfx_lb_format_number( $remote['qty'] ?? 0 ),
        csfx_lb_format_number( $local['total'] ?? 0 ),
        csfx_lb_format_number( $remote['total'] ?? 0 )
      );
    }
  }

  $duplicates = csfx_lb_detect_duplicate_discounts( $doc_items );
  if ( ! empty( $duplicates ) ) {
    foreach ( $duplicates as $dup ) {
      $warnings[] = 'Posible duplicación de descuento: ' . $dup;
    }
  }

  return array(
    'report'      => $report,
    'differences' => $differences,
    'warnings'    => $warnings,
    'duplicates'  => $duplicates,
  );
}

function csfx_lb_compare_payments( $order, $doc ){
  $report = array();
  $differences = array();
  $warnings = array();

  $local = csfx_lb_extract_doc_payments( $doc );
  $remote = csfx_lb_extract_wc_payments( $order );

  $map_local = array();
  foreach ( $local as $payment ) {
    $key = strtolower( $payment['code'] ?? $payment['label'] ?? 'pago' );
    $map_local[ $key ] = $payment;
  }
  $map_remote = array();
  foreach ( $remote as $payment ) {
    $key = strtolower( $payment['code'] ?? $payment['label'] ?? 'pago' );
    $map_remote[ $key ] = $payment;
  }

  $keys = array_unique( array_merge( array_keys( $map_local ), array_keys( $map_remote ) ) );
  foreach ( $keys as $key ) {
    $local_payment  = isset( $map_local[ $key ] ) ? $map_local[ $key ] : null;
    $remote_payment = isset( $map_remote[ $key ] ) ? $map_remote[ $key ] : null;
    $local_amount   = csfx_lb_to_number( $local_payment['amount'] ?? 0 );
    $remote_amount  = csfx_lb_to_number( $remote_payment['amount'] ?? 0 );
    $local_change   = csfx_lb_to_number( $local_payment['change'] ?? 0 );
    $remote_change  = csfx_lb_to_number( $remote_payment['change'] ?? 0 );
    $amount_diff    = csfx_lb_numbers_differs( $local_amount, $remote_amount );
    $change_diff    = csfx_lb_numbers_differs( $local_change, $remote_change );
    $match          = $amount_diff < 0.01 && $change_diff < 0.01;

    if ( ! $match ) {
      $local_net  = $local_amount - $local_change;
      $remote_net = $remote_amount - $remote_change;
      if ( csfx_lb_numbers_differs( $local_net, $remote_net ) < 0.01 ) {
        $match = true;
      }
    }

    $report[]       = array(
      'key'    => $key,
      'label'  => $local_payment['label'] ?? $remote_payment['label'] ?? $key,
      'local'  => $local_payment,
      'remote' => $remote_payment,
      'match'  => $match,
    );
    if ( ! $match ) {
      $differences[] = sprintf(
        'Método %s difiere: monto local %s / Woo %s, vuelto local %s / Woo %s',
        $local_payment['label'] ?? $remote_payment['label'] ?? $key,
        csfx_lb_format_number( $local_amount ),
        csfx_lb_format_number( $remote_amount ),
        csfx_lb_format_number( $local_change ),
        csfx_lb_format_number( $remote_change )
      );
    }
  }

  if ( empty( $remote ) ) {
    $warnings[] = 'No se encontraron transacciones en WooCommerce para comparar.';
  }

  return array(
    'report'      => $report,
    'differences' => $differences,
    'warnings'    => $warnings,
  );
}

function csfx_lb_extract_doc_payments( $doc ){
  $list = array();
  if ( isset( $doc['payments'] ) && is_array( $doc['payments'] ) ) {
    $list = $doc['payments'];
  } elseif ( isset( $doc['orderData']['payments'] ) && is_array( $doc['orderData']['payments'] ) ) {
    $list = $doc['orderData']['payments'];
  }
  $normalized = array();
  foreach ( $list as $payment ) {
    $normalized[] = csfx_lb_normalize_payment_row( $payment );
  }
  return csfx_lb_group_payments( $normalized );
}

function csfx_lb_extract_wc_payments( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }

  $payments = csfx_lb_payments_from_openpos_transactions( $order );
  if ( ! empty( $payments ) ) {
    return $payments;
  }

  $candidates = array(
    '_openpos_transactions',
    '_op_transactions',
    '_op_payment_transactions',
    'op_transactions',
    '_openpos_payment_transactions'
  );
  $payments = array();
  foreach ( $candidates as $key ) {
    $value = $order->get_meta( $key, true );
    if ( empty( $value ) ) {
      continue;
    }
    if ( is_string( $value ) ) {
      $json = json_decode( $value, true );
      if ( json_last_error() === JSON_ERROR_NONE ) {
        $value = $json;
      }
    }
    if ( is_string( $value ) ) {
      $value = maybe_unserialize( $value );
    }
    if ( ! is_array( $value ) ) {
      continue;
    }
    foreach ( $value as $payment ) {
      if ( ! is_array( $payment ) ) {
        continue;
      }
      $payments[] = csfx_lb_normalize_payment_row( $payment );
    }
  }

  if ( empty( $payments ) ) {
    $payments[] = array(
      'code'   => sanitize_key( $order->get_payment_method() ?: 'pago' ),
      'label'  => sanitize_text_field( $order->get_payment_method_title() ?: 'Pago' ),
      'amount' => csfx_lb_to_number( $order->get_total() ),
      'change' => 0,
    );
  }

  return csfx_lb_group_payments( $payments );
}

function csfx_lb_detect_duplicate_discounts( $items ){
  $duplicates = array();
  if ( ! is_array( $items ) ) {
    return $duplicates;
  }
  $count = array();
  foreach ( $items as $item ) {
    $name = isset( $item['name'] ) ? strtolower( trim( $item['name'] ) ) : '';
    if ( $name === '' ) {
      continue;
    }
    if ( strpos( $name, 'descuento' ) === false && ( ! isset( $item['discount'] ) || $item['discount'] == 0 ) && ( ! isset( $item['total'] ) || $item['total'] >= 0 ) ) {
      continue;
    }
    if ( ! isset( $count[ $name ] ) ) {
      $count[ $name ] = 0;
    }
    $count[ $name ]++;
  }
  foreach ( $count as $name => $times ) {
    if ( $times > 1 ) {
      $duplicates[] = $name;
    }
  }
  return $duplicates;
}

function csfx_lb_format_number( $value ){
  if ( $value === null || $value === '' ) {
    return '—';
  }
  $number = floatval( $value );
  if ( function_exists( 'number_format_i18n' ) ) {
    return number_format_i18n( $number, 2 );
  }
  return number_format( $number, 2, ',', '.' );
}

function csfx_lb_create_order_from_doc( $doc ){
  if ( ! function_exists( 'wc_create_order' ) ) {
    return new WP_Error( 'csfx_lb_missing_wc', 'WooCommerce no está activo.' );
  }

  $doc = is_array( $doc ) ? $doc : array();
  $items    = isset( $doc['cart']['items'] ) && is_array( $doc['cart']['items'] ) ? $doc['cart']['items'] : array();
  $payments = isset( $doc['payments'] ) && is_array( $doc['payments'] ) ? $doc['payments'] : array();
  $totals   = isset( $doc['totals'] ) && is_array( $doc['totals'] ) ? $doc['totals'] : array();

  if ( empty( $items ) ) {
    return new WP_Error( 'csfx_lb_missing_items', 'No hay productos en el respaldo para crear el pedido.' );
  }

  $order_args = array(
    'status'      => apply_filters( 'csfx_lb_resync_default_status', 'completed', $doc ),
    'customer_id' => 0,
    'created_via' => 'csfx-resync',
  );

  $order = wc_create_order( $order_args );
  if ( is_wp_error( $order ) ) {
    return $order;
  }

  $order_number = isset( $doc['orderNumber'] ) ? sanitize_text_field( $doc['orderNumber'] ) : '';

  if ( $order_number !== '' ) {
    $order->update_meta_data( '_op_wc_custom_order_number', $order_number );
    $order->update_meta_data( '_op_wc_custom_order_number_formatted', $order_number );
    $order->update_meta_data( '_op_order_number_format', $order_number );
    $order->set_order_key( 'csfx-' . $order_number . '-' . wp_generate_password( 6, false ) );
  }

  $order->update_meta_data( '_op_order_source', 'openpos' );
  if ( isset( $doc['orderLocalId'] ) ) {
    $order->update_meta_data( '_op_local_id', sanitize_text_field( $doc['orderLocalId'] ) );
  }
  if ( isset( $doc['orderId'] ) ) {
    $order->update_meta_data( '_op_order_id', sanitize_text_field( $doc['orderId'] ) );
  }

  $cashier_user_id = csfx_lb_find_cashier_user_id( $doc );
  if ( $cashier_user_id ) {
    $order->update_meta_data( '_op_sale_by_cashier_id', $cashier_user_id );
    $order->update_meta_data( '_op_sale_by_person_id', $cashier_user_id );
  }

  foreach ( $items as $row ) {
    $qty        = isset( $row['qty'] ) ? max( 0, floatval( $row['qty'] ) ) : 0;
    $line_total = isset( $row['total'] ) ? csfx_lb_to_number( $row['total'] ) : 0;
    $price      = isset( $row['price'] ) ? csfx_lb_to_number( $row['price'] ) : ( $qty > 0 ? $line_total / $qty : $line_total );
    $name       = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : __( 'Artículo POS', 'csfx-lb' );

    $product = csfx_lb_find_product_for_item( $row );

    $item = new WC_Order_Item_Product();
    if ( $product ) {
      if ( $product->is_type( 'variation' ) ) {
        $item->set_product_id( $product->get_parent_id() );
        $item->set_variation_id( $product->get_id() );
      } else {
        $item->set_product_id( $product->get_id() );
      }
      $item->set_name( $product->get_name() );
    } else {
      $item->set_name( $name );
    }

    $item->set_quantity( $qty > 0 ? $qty : 1 );
    $item->set_subtotal( $line_total );
    $item->set_total( $line_total );
    $item->set_subtotal_tax( 0 );
    $item->set_total_tax( 0 );

    $order->add_item( $item );
  }

  $currency = get_option( 'woocommerce_currency', 'USD' );

  $order->set_currency( $currency );

  $discount_total = csfx_lb_to_number( $totals['discount_amount'] ?? 0 );
  if ( $discount_total > 0 ) {
    $fee = new WC_Order_Item_Fee();
    $fee->set_name( 'POS Cart Discount' );
    $fee->set_amount( -$discount_total );
    $fee->set_total( -$discount_total );
    $fee->set_tax_class( '' );
    $fee->set_tax_status( 'none' );
    $order->add_item( $fee );
  }
  $order->set_discount_total( 0 );
  $order->set_shipping_total( 0 );
  $order->set_cart_tax( 0 );
  $order->set_shipping_tax( 0 );
  $order_total = csfx_lb_to_number( $totals['grand_total'] ?? $totals['total_paid'] ?? 0 );
  $order->calculate_totals( false );
  $order->set_total( $order_total );

  $primary_payment = isset( $payments[0] ) ? $payments[0] : array();
  $method_id = sanitize_key( $primary_payment['code'] ?? 'csfx-offline' );
  $method_title = sanitize_text_field( $primary_payment['label'] ?? $primary_payment['name'] ?? 'CSFX Resync' );

  $order->set_payment_method( $method_id );
  $order->set_payment_method_title( $method_title );

  if ( $order_number !== '' ) {
    $order->update_meta_data( '_openpos_order_number', $order_number );
    $order->update_meta_data( '_op_order_number', $order_number );
    $order->update_meta_data( '_order_number', $order_number );
  }

  if ( isset( $doc['orderLocalId'] ) ) {
    $order->update_meta_data( '_op_local_order_id', sanitize_text_field( $doc['orderLocalId'] ) );
  }

  $created_at = csfx_lb_parse_doc_datetime( $doc );
  if ( $created_at ) {
    $order->set_date_created( $created_at );
    $order->set_date_paid( $created_at );
  }

  $transactions_meta = csfx_lb_build_transactions_meta( $payments, $order->get_order_number(), $doc );
  if ( ! empty( $transactions_meta ) ) {
    $order->update_meta_data( '_openpos_transactions', $transactions_meta );
    $order->update_meta_data( '_op_transactions', $transactions_meta );
    $order->update_meta_data( '_op_payment_transactions', $transactions_meta );
    $order->update_meta_data( '_openpos_payment_transactions', $transactions_meta );
  }

  $order->save();

  $order->add_order_note( 'CSFX Local Backup: Pedido creado mediante resync automático.' );

  do_action( 'csfx_lb_resync_order_created', $order, $doc );

  return $order;
}

function csfx_lb_parse_doc_datetime( $doc ){
  if ( isset( $doc['createdAt'] ) && is_numeric( $doc['createdAt'] ) ) {
    $seconds = intval( floor( floatval( $doc['createdAt'] ) / 1000 ) );
    if ( $seconds > 0 ) {
      try {
        $dt = new WC_DateTime( '@' . $seconds );
        $dt->setTimezone( new DateTimeZone( wc_timezone_string() ) );
        return $dt;
      } catch ( Exception $e ) {
        // continue fallbacks
      }
    }
  }
  if ( ! empty( $doc['createdAtText'] ) ) {
    try {
      $dt = new WC_DateTime( $doc['createdAtText'] );
      $dt->setTimezone( new DateTimeZone( wc_timezone_string() ) );
      return $dt;
    } catch ( Exception $e ) {}
  }
  if ( isset( $doc['createdAtParts'] ) && is_array( $doc['createdAtParts'] ) ) {
    $p = $doc['createdAtParts'];
    $iso = sprintf(
      '%04d-%02d-%02d %02d:%02d:%02d',
      isset( $p['y'] ) ? (int) $p['y'] : gmdate( 'Y' ),
      isset( $p['m'] ) ? (int) $p['m'] : gmdate( 'm' ),
      isset( $p['d'] ) ? (int) $p['d'] : gmdate( 'd' ),
      isset( $p['H'] ) ? (int) $p['H'] : 0,
      isset( $p['M'] ) ? (int) $p['M'] : 0,
      isset( $p['S'] ) ? (int) $p['S'] : 0
    );
    try {
      $dt = new WC_DateTime( $iso );
      $dt->setTimezone( new DateTimeZone( wc_timezone_string() ) );
      return $dt;
    } catch ( Exception $e ) {}
  }

  return null;
}

function csfx_lb_build_transactions_meta( $payments, $order_number, $doc ){
  if ( ! is_array( $payments ) || empty( $payments ) ) {
    return array();
  }

  $now = current_time( 'mysql' );
  $current_user = wp_get_current_user();
  $user_login   = ( $current_user && isset( $current_user->user_login ) && $current_user->user_login ) ? $current_user->user_login : 'csfx';
  $transactions = array();
  foreach ( $payments as $payment ) {
    $amount = csfx_lb_to_number( $payment['amount'] ?? 0 );
    $change = csfx_lb_to_number( $payment['change'] ?? 0 );
    $transactions[] = array(
      'code'           => $payment['code'] ?? 'pago',
      'name'           => $payment['name'] ?? $payment['label'] ?? 'Pago',
      'payment_name'   => $payment['label'] ?? $payment['name'] ?? 'Pago',
      'payment_code'   => $payment['code'] ?? 'pago',
      'in_amount'      => $amount,
      'paid'           => $amount,
      'amount'         => $amount,
      'total'          => $amount,
      'out_amount'     => $change,
      'change'         => $change,
      'return'         => $change,
      'return_amount'  => $change,
      'created_at'     => $now,
      'created_at_utc' => $now,
      'user'           => $user_login,
      'note'           => sprintf( 'Pedido #%s', $order_number ),
      'ref'            => $order_number,
      'method'         => $payment['code'] ?? 'pago',
      'method_title'   => $payment['label'] ?? $payment['name'] ?? 'Pago',
    );
  }

  return $transactions;
}

function csfx_lb_to_number( $value ){
  if ( $value === null || $value === '' ) {
    return 0.0;
  }
  if ( is_numeric( $value ) ) {
    return floatval( $value );
  }
  if ( is_string( $value ) ) {
    $normalized = preg_replace( '/[^0-9.,-]/', '', $value );
    if ( strpos( $normalized, ',' ) !== false && strpos( $normalized, '.' ) !== false ) {
      $normalized = str_replace( array( '.', ',' ), array( '', '.' ), $normalized );
    } elseif ( strpos( $normalized, ',' ) !== false && strpos( $normalized, '.' ) === false ) {
      $normalized = str_replace( ',', '.', $normalized );
    }
    $float      = floatval( $normalized );
    return $float;
  }
  return floatval( $value );
}

function csfx_lb_numbers_differs( $a, $b ){
  return abs( floatval( $a ) - floatval( $b ) );
}

function csfx_lb_item_compare_key( $sku, $name ){
  $name_key = $name ? sanitize_title( $name ) : '';
  if ( $name_key ) {
    return $name_key;
  }
  $sku_key = $sku !== '' ? strtolower( trim( (string) $sku ) ) : '';
  if ( $sku_key ) {
    return $sku_key;
  }
  $raw = $name ?: $sku;
  return strtolower( trim( (string) $raw ) );
}

function csfx_lb_pick_numeric( $source, $keys ){
  if ( ! is_array( $source ) ) {
    return null;
  }
  foreach ( $keys as $key ) {
    if ( isset( $source[ $key ] ) && $source[ $key ] !== '' && $source[ $key ] !== null ) {
      return csfx_lb_to_number( $source[ $key ] );
    }
  }
  return null;
}

function csfx_lb_normalize_payment_row( $payment ) {
  $code  = $payment['code'] ?? $payment['method'] ?? $payment['payment_code'] ?? 'pago';
  $label = $payment['label'] ?? $payment['name'] ?? $payment['payment_name'] ?? $payment['payment_title'] ?? $payment['method'] ?? 'Pago';
  $amount = csfx_lb_pick_numeric( $payment, array( 'in_amount', 'amount', 'paid', 'total', 'total_paid' ) );
  $change = csfx_lb_pick_numeric( $payment, array( 'out_amount', 'change', 'return', 'change_amount', 'return_amount' ) );

  if ( $amount === null && $change !== null && isset( $payment['amount'] ) ) {
    $amount = csfx_lb_to_number( $payment['amount'] );
  }

  return array(
    'code'   => sanitize_key( $code ),
    'label'  => sanitize_text_field( $label ),
    'amount' => $amount !== null ? $amount : 0,
    'change' => $change !== null ? $change : 0,
  );
}

function csfx_lb_group_payments( $payments ){
  $map = array();
  foreach ( $payments as $payment ) {
    $code  = $payment['code'] ?? 'pago';
    $label = $payment['label'] ?? 'Pago';
    $key = strtolower( $code ?: $label );
    if ( ! isset( $map[ $key ] ) ) {
      $map[ $key ] = array(
        'code'   => $code,
        'label'  => $label,
        'amount' => 0,
        'change' => 0,
      );
    }
    $map[ $key ]['amount'] += csfx_lb_to_number( $payment['amount'] ?? 0 );
    $map[ $key ]['change'] += csfx_lb_to_number( $payment['change'] ?? 0 );
  }
  return array_values( $map );
}

function csfx_lb_payments_from_openpos_transactions( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }

  global $op_transaction;
  if ( ! is_object( $op_transaction ) || ! method_exists( $op_transaction, 'getOrderTransactions' ) ) {
    return array();
  }

  $transactions = $op_transaction->getOrderTransactions( $order->get_id(), array( 'order' ) );
  if ( empty( $transactions ) ) {
    $transactions = $op_transaction->getOrderTransactions( $order->get_order_number(), array( 'order' ) );
  }

  if ( empty( $transactions ) || ! is_array( $transactions ) ) {
    return array();
  }

  $payments = array();
  foreach ( $transactions as $transaction ) {
    if ( ! is_array( $transaction ) ) {
      continue;
    }
    $payments[] = array(
      'code'   => sanitize_key( $transaction['payment_code'] ?? $transaction['method'] ?? 'pago' ),
      'label'  => sanitize_text_field( $transaction['payment_name'] ?? $transaction['payment_code'] ?? 'Pago' ),
      'amount' => csfx_lb_to_number( $transaction['in_amount'] ?? $transaction['amount'] ?? 0 ),
      'change' => csfx_lb_to_number( $transaction['out_amount'] ?? $transaction['change'] ?? 0 ),
    );
  }

  return csfx_lb_group_payments( $payments );
}

function csfx_lb_find_product_for_item( $row ){
  $product = null;

  $candidates = array();
  if ( isset( $row['product_id'] ) ) {
    $candidates[] = array( 'type' => 'id', 'value' => $row['product_id'] );
  }
  if ( isset( $row['product']['id'] ) ) {
    $candidates[] = array( 'type' => 'id', 'value' => $row['product']['id'] );
  }
  if ( isset( $row['sku'] ) ) {
    $candidates[] = array( 'type' => 'code', 'value' => $row['sku'] );
  }
  if ( isset( $row['barcode'] ) ) {
    $candidates[] = array( 'type' => 'code', 'value' => $row['barcode'] );
  }
  if ( isset( $row['product']['sku'] ) ) {
    $candidates[] = array( 'type' => 'code', 'value' => $row['product']['sku'] );
  }
  if ( isset( $row['product']['barcode'] ) ) {
    $candidates[] = array( 'type' => 'code', 'value' => $row['product']['barcode'] );
  }

  foreach ( $candidates as $candidate ) {
    if ( $candidate['type'] === 'id' && $candidate['value'] ) {
      $product = wc_get_product( absint( $candidate['value'] ) );
      if ( $product && csfx_lb_product_matches_row( $product, $row ) ) {
        return $product;
      }
    }

    if ( $candidate['type'] === 'code' ) {
      $code = sanitize_text_field( (string) $candidate['value'] );
      if ( $code === '' ) {
        continue;
      }
      $variants = array( $code );
      if ( ctype_digit( $code ) ) {
        $trimmed = ltrim( $code, '0' );
        if ( $trimmed !== '' && $trimmed !== $code ) {
          $variants[] = $trimmed;
        }
      }
      foreach ( array_unique( $variants ) as $variant ) {
        $product_id = wc_get_product_id_by_sku( $variant );
        if ( $product_id ) {
          $product = wc_get_product( $product_id );
          if ( $product && csfx_lb_product_matches_row( $product, $row ) ) {
            return $product;
          }
        }

        $meta_keys = array( '_op_barcode', '_barcode', '_op_disable_barcode' );
        foreach ( $meta_keys as $meta_key ) {
          $products = wc_get_products( array(
            'limit'     => 1,
            'return'    => 'objects',
            'meta_key'  => $meta_key,
            'meta_value'=> $variant,
          ) );
          if ( ! empty( $products ) && $products[0] instanceof WC_Product && csfx_lb_product_matches_row( $products[0], $row ) ) {
            return $products[0];
          }
        }
      }
    }
  }

  if ( ! empty( $row['name'] ) ) {
    $products = wc_get_products( array(
      'limit'  => 1,
      'return' => 'objects',
      'search' => $row['name'],
    ) );
    if ( ! empty( $products ) && $products[0] instanceof WC_Product && csfx_lb_product_matches_row( $products[0], $row ) ) {
      return $products[0];
    }

    $post = get_page_by_title( $row['name'], OBJECT, 'product' );
    if ( $post ) {
      $product = wc_get_product( $post->ID );
      if ( $product && csfx_lb_product_matches_row( $product, $row ) ) {
        return $product;
      }
    }
  }

  return null;
}

function csfx_lb_product_matches_row( $product, $row ){
  if ( ! $product ) {
    return false;
  }
  $doc_key = csfx_lb_item_compare_key( $row['sku'] ?? '', $row['name'] ?? '' );
  $product_key = csfx_lb_item_compare_key( $product->get_sku(), $product->get_name() );
  if ( $doc_key && $product_key && $doc_key === $product_key ) {
    return true;
  }

  $barcode_keys = array( '_op_barcode', '_barcode', '_op_disable_barcode' );
  foreach ( $barcode_keys as $meta_key ) {
    $meta_val = $product->get_meta( $meta_key, true );
    if ( $meta_val && isset( $row['sku'] ) && trim( $meta_val ) === trim( (string) $row['sku'] ) ) {
      return true;
    }
  }

  return false;
}

function csfx_lb_find_cashier_user_id( $doc ){
  $candidates = array();
  $fields = array( 'cashier', 'cashier_name', 'salesperson', 'sale_person_name', 'cashier_code' );

  foreach ( $fields as $field ) {
    if ( isset( $doc[ $field ] ) ) {
      $candidates[] = $doc[ $field ];
    }
  }

  if ( isset( $doc['cart'] ) && is_array( $doc['cart'] ) ) {
    foreach ( $fields as $field ) {
      if ( isset( $doc['cart'][ $field ] ) ) {
        $candidates[] = $doc['cart'][ $field ];
      }
    }
  }

  $candidates = array_filter( array_map( 'trim', array_map( 'strval', $candidates ) ) );

  foreach ( $candidates as $candidate ) {
    if ( $candidate === '' || strtolower( $candidate ) === 'unknown' ) {
      continue;
    }
    $user = get_user_by( 'login', $candidate );
    if ( $user ) {
      return $user->ID;
    }
    $user = get_user_by( 'slug', sanitize_title( $candidate ) );
    if ( $user ) {
      return $user->ID;
    }
    $user = get_user_by( 'email', $candidate );
    if ( $user ) {
      return $user->ID;
    }
    $query = new WP_User_Query( array(
      'search'         => '*' . $candidate . '*',
      'search_columns' => array( 'user_login', 'display_name', 'user_email' ),
      'number'         => 1,
      'fields'         => array( 'ID' ),
    ) );
    $results = $query->get_results();
    if ( ! empty( $results ) ) {
      return $results[0]->ID;
    }
  }

  return 0;
}

function csfx_lb_sync_cashier_meta( $order, $doc ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return;
  }

  $cashier_user_id = csfx_lb_find_cashier_user_id( $doc );
  if ( ! $cashier_user_id ) {
    return;
  }

  $dirty = false;
  if ( intval( $order->get_meta( '_op_sale_by_cashier_id' ) ) !== $cashier_user_id ) {
    $order->update_meta_data( '_op_sale_by_cashier_id', $cashier_user_id );
    $dirty = true;
  }
  if ( intval( $order->get_meta( '_op_sale_by_person_id' ) ) !== $cashier_user_id ) {
    $order->update_meta_data( '_op_sale_by_person_id', $cashier_user_id );
    $dirty = true;
  }

  if ( $dirty ) {
    $order->save();
  }
}
