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

function csfx_lb_signature_secret() {
  static $secret = null;
  if (null !== $secret) {
    return $secret;
  }

  $stored = get_option('csfx_lb_signature_secret_v1');
  if (is_string($stored) && strlen($stored) >= 32) {
    $secret = $stored;
    return $secret;
  }

  $candidates = array(
    'AUTH_KEY',
    'SECURE_AUTH_KEY',
    'LOGGED_IN_KEY',
    'NONCE_KEY',
    'AUTH_SALT',
    'SECURE_AUTH_SALT',
    'LOGGED_IN_SALT',
    'NONCE_SALT',
  );

  $parts = array();
  foreach ($candidates as $const) {
    if (defined($const)) {
      $value = constant($const);
      if (is_string($value) && $value !== '') {
        $parts[] = $value;
      }
    }
  }

  if (empty($parts)) {
    $parts[] = (string) get_option('siteurl', '');
    $parts[] = (string) get_option('home', '');
    $parts[] = (string) get_current_blog_id();
  }

  $raw = implode('|', array_filter(array_unique($parts)));
  if ($raw === '') {
    $raw = wp_generate_password(64, true, true);
  }

  $secret = hash('sha256', $raw);
  update_option('csfx_lb_signature_secret_v1', $secret, false);

  $legacy_option = get_option('csfx_lb_signature_secret_legacy_v1');
  if (empty($legacy_option)) {
    update_option('csfx_lb_signature_secret_legacy_v1', array($secret), false);
  } elseif (is_string($legacy_option) && strlen($legacy_option) >= 32) {
    update_option('csfx_lb_signature_secret_legacy_v1', array_unique(array($legacy_option, $secret)), false);
  } elseif (is_array($legacy_option)) {
    $legacy_option[] = $secret;
    update_option('csfx_lb_signature_secret_legacy_v1', array_values(array_unique($legacy_option)), false);
  }

  return $secret;
}

function csfx_lb_signature_secret_legacy() {
  static $legacy = null;
  if (null !== $legacy) {
    return $legacy;
  }

  $candidates = csfx_lb_signature_secret_candidates();
  foreach ($candidates as $key => $candidate) {
    if ($key === 'primary') {
      continue;
    }
    $legacy = $candidate;
    return $legacy;
  }

  $legacy = '';
  return $legacy;
}

function csfx_lb_signature_secret_candidates() {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $candidates = array();
  $primary    = csfx_lb_signature_secret();
  if (is_string($primary) && strlen($primary) >= 32) {
    $candidates['primary'] = $primary;
  }

  $stored_legacy = get_option('csfx_lb_signature_secret_legacy_v1');
  if (is_string($stored_legacy) && strlen($stored_legacy) >= 32) {
    $candidates['legacy_stored'] = $stored_legacy;
  } elseif (is_array($stored_legacy)) {
    foreach ($stored_legacy as $index => $value) {
      if (is_string($value) && strlen($value) >= 32) {
        $candidates['legacy_' . $index] = $value;
      }
    }
  }

  $fallback = csfx_lb_signature_secret_fallback();
  if (is_string($fallback) && strlen($fallback) >= 32) {
    $candidates['fallback'] = $fallback;
  }

  $candidates = apply_filters('csfx_lb_signature_secret_candidates', $candidates);

  $unique = array();
  foreach ($candidates as $key => $value) {
    if (!is_string($value) || strlen($value) < 32) {
      continue;
    }
    if (in_array($value, $unique, true)) {
      continue;
    }
    $unique[$key] = $value;
  }

  $cached = $unique;
  return $cached;
}

function csfx_lb_signature_secret_fallback() {
  $parts = array(
    defined('AUTH_KEY') ? AUTH_KEY : '',
    defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
    defined('NONCE_SALT') ? NONCE_SALT : '',
    defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '',
  );

  if (function_exists('wp_salt')) {
    $parts[] = wp_salt();
  }

  if (empty(array_filter($parts))) {
    $parts[] = (string) get_option('siteurl', '');
    $parts[] = (string) get_option('home', '');
    $parts[] = (string) get_current_blog_id();
  }

  $raw = implode('|', array_filter(array_unique(array_map('strval', $parts))));
  if ($raw === '') {
    $raw = 'csfx-lb-fallback:' . site_url('/');
  }

  return hash('sha256', $raw);
}

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
        'signature_secret' => csfx_lb_signature_secret(),
        'signature_secret_legacy' => csfx_lb_signature_secret_legacy(),
        'signature_secret_pool' => array_values( csfx_lb_signature_secret_candidates() ),
        'rest_root' => esc_url_raw( rest_url( 'csfx-lb/v1' ) ),
        'rest_nonce' => wp_create_nonce( 'wp_rest' ),
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
    'signature_secret' => csfx_lb_signature_secret(),
    'signature_secret_legacy' => csfx_lb_signature_secret_legacy(),
    'signature_secret_pool' => array_values( csfx_lb_signature_secret_candidates() ),
  );

  $bootstrap = '<script>window.CSFX_LB_EMBED_CONTEXT = ' . wp_json_encode($context) . ';</script>';
  $html = preg_replace('/<head>/', '<head>' . $bootstrap, $html, 1);
  echo $html;
  exit;
}

add_action('rest_api_init', 'csfx_lb_register_rest_routes');
add_action( 'csfx_lb_resync_order_created', 'csfx_lb_maybe_create_openpos_transactions', 10, 2 );

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

  register_rest_route(
    'csfx-lb/v1',
    '/signature',
    array(
      'methods'             => WP_REST_Server::CREATABLE,
      'permission_callback' => 'csfx_lb_rest_permission',
      'callback'            => 'csfx_lb_rest_signature'
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
    ),
    'signature_secret' => csfx_lb_signature_secret(),
    'signature_secret_legacy' => csfx_lb_signature_secret_legacy(),
    'signature_secret_pool' => array_values( csfx_lb_signature_secret_candidates() ),
  ));
}

function csfx_lb_rest_verify_order( WP_REST_Request $request ){
  return csfx_lb_handle_verify_request( $request, 'verify' );
}

function csfx_lb_rest_resync_order( WP_REST_Request $request ){
  return csfx_lb_handle_verify_request( $request, 'resync' );
}

function csfx_lb_rest_signature( WP_REST_Request $request ){
  $payload = $request->get_json_params();
  if ( ! is_array( $payload ) || empty( $payload['doc'] ) || ! is_array( $payload['doc'] ) ) {
    return new WP_Error( 'csfx_lb_invalid_body', 'Documento inválido.', array( 'status' => 400 ) );
  }

  $doc = $payload['doc'];
  if ( isset( $doc['signature'] ) ) {
    unset( $doc['signature'] );
  }

  $signature_value = csfx_lb_calculate_doc_signature( $doc );
  $doc['signature'] = array(
    'value' => $signature_value,
    'alg'   => 'HS256',
    'ts'    => round( microtime( true ) * 1000 )
  );

  return rest_ensure_response( array(
    'signature' => $signature_value,
    'doc'       => $doc,
  ) );
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

  $signature_status = csfx_lb_validate_doc_signature( $doc );

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

  csfx_lb_maybe_create_openpos_transactions( $order, $doc );

  $comparison = csfx_lb_compare_order_with_doc( $order, $doc );

  csfx_lb_sync_cashier_meta( $order, $doc );

  $signature_warning = '';
  if ( 'invalid' === $signature_status['status'] ) {
    $signature_warning = 'Firma HMAC inválida: no se pudo comprobar integridad del respaldo.';
    $comparison['warnings'][] = $signature_warning;
  } elseif ( 'missing' === $signature_status['status'] ) {
    $signature_warning = 'Respaldo sin firma HMAC: generado antes de la actualización o sin soporte de WebCrypto.';
    $comparison['warnings'][] = $signature_warning;
  } elseif ( 'unchecked' === $signature_status['status'] ) {
    $signature_warning = 'No fue posible validar la firma HMAC en este entorno.';
    $comparison['warnings'][] = $signature_warning;
  }

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
    'signature'     => $signature_status['status'],
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

  if ( isset( $totals['grand_total'] ) && isset( $totals['remain_paid'] ) ) {
    $grand_total = csfx_lb_to_number( $totals['grand_total'] );
    $remain_paid = csfx_lb_to_number( $totals['remain_paid'] );
    $totals['total_paid'] = max( 0, $grand_total - $remain_paid );
  }

  return $totals;
}

function csfx_lb_doc_remain_amount( $doc ){
  $sources = array();
  if ( isset( $doc['totals'] ) && is_array( $doc['totals'] ) ) {
    $sources[] = $doc['totals'];
  }
  if ( isset( $doc['orderData']['totals'] ) && is_array( $doc['orderData']['totals'] ) ) {
    $sources[] = $doc['orderData']['totals'];
  }
  foreach ( $sources as $source ) {
    if ( isset( $source['remain_paid'] ) && $source['remain_paid'] !== '' ) {
      return csfx_lb_to_number( $source['remain_paid'] );
    }
    if ( isset( $source['remain'] ) && $source['remain'] !== '' ) {
      return csfx_lb_to_number( $source['remain'] );
    }
  }
  return 0;
}

function csfx_lb_doc_has_outstanding_balance( $doc ){
  return csfx_lb_doc_remain_amount( $doc ) > 0.01;
}

function csfx_lb_doc_status( $doc ){
  $candidates = array();
  if ( isset( $doc['status'] ) ) {
    $candidates[] = $doc['status'];
  }
  if ( isset( $doc['orderData']['status'] ) ) {
    $candidates[] = $doc['orderData']['status'];
  }
  foreach ( $candidates as $candidate ) {
    $status = sanitize_key( (string) $candidate );
    if ( $status !== '' ) {
      return $status;
    }
  }
  return '';
}

function csfx_lb_order_has_outstanding_balance( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return false;
  }
  $remain_meta = csfx_lb_to_number( $order->get_meta( '_csfx_lb_remain_paid', true ) );
  if ( $remain_meta > 0.01 ) {
    return true;
  }

  $status = sanitize_key( (string) $order->get_status() );
  if ( in_array( $status, array( 'pending', 'on-hold' ), true ) ) {
    $transactions_paid = csfx_lb_order_paid_amount_from_transactions( $order );
    if ( $transactions_paid <= 0.01 ) {
      return true;
    }
  }

  return false;
}

function csfx_lb_collect_wc_totals( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }
  $subtotal   = csfx_lb_to_number( $order->get_subtotal() );
  $total      = csfx_lb_to_number( $order->get_total() );
  $refunded   = csfx_lb_to_number( method_exists( $order, 'get_total_refunded' ) ? $order->get_total_refunded() : 0 );
  $total_paid = max( 0, $total - $refunded );

  $transactions_paid = csfx_lb_order_paid_amount_from_transactions( $order );
  if ( $transactions_paid > 0 ) {
    $total_paid = min( $total, $transactions_paid );
  }

  $remain_meta = csfx_lb_to_number( $order->get_meta( '_csfx_lb_remain_paid', true ) );
  $status = sanitize_key( (string) $order->get_status() );
  if ( $remain_meta > 0.01 ) {
    $total_paid = max( 0, $total - $remain_meta );
  } elseif ( in_array( $status, array( 'pending', 'on-hold' ), true ) && $transactions_paid <= 0 ) {
    $total_paid = 0;
  }

  $discount_total = csfx_lb_to_number( $order->get_discount_total() );
  foreach ( $order->get_items( 'fee' ) as $fee_item ) {
    $fee_total = csfx_lb_to_number( $fee_item->get_total() );
    if ( $fee_total < 0 ) {
      $discount_total += abs( $fee_total );
    }
  }

  $remain_value = max( 0, $total - $total_paid );
  if ( $remain_meta > 0.01 ) {
    $remain_value = $remain_meta;
  }

  return array(
    'sub_total'       => $subtotal,
    'grand_total'     => $total,
    'total_paid'      => $total_paid,
    'remain_paid'     => $remain_value,
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
  $laybuy_pending = false;

  $local = csfx_lb_extract_doc_payments( $doc );
  $remote = csfx_lb_extract_wc_payments( $order );

  $doc_outstanding   = csfx_lb_doc_has_outstanding_balance( $doc );
  $order_outstanding = csfx_lb_order_has_outstanding_balance( $order );

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

  if ( empty( $local ) && empty( $remote ) && ( $doc_outstanding || $order_outstanding ) ) {
    $laybuy_pending = true;
  }

  if ( empty( $remote ) && ! $laybuy_pending ) {
    $warnings[] = 'No se encontraron transacciones en WooCommerce para comparar.';
  }

  return array(
    'report'      => $report,
    'differences' => $differences,
    'warnings'    => $warnings,
    'laybuy_pending' => $laybuy_pending,
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

function csfx_lb_collect_doc_payment_rows( $doc ){
  if ( ! is_array( $doc ) ) {
    return array();
  }

  $candidates = array();
  $paths      = array(
    array( 'payments' ),
    array( 'orderData', 'payments' ),
    array( 'orderData', 'payment_method' ),
    array( 'orderData', 'transactions' ),
    array( 'cart', 'payments' ),
  );

  foreach ( $paths as $path ) {
    $node = $doc;
    foreach ( $path as $segment ) {
      if ( ! isset( $node[ $segment ] ) ) {
        $node = null;
        break;
      }
      $node = $node[ $segment ];
    }
    if ( empty( $node ) ) {
      continue;
    }
    if ( is_array( $node ) ) {
      $candidates = $node;
      break;
    }
    if ( is_object( $node ) ) {
      $candidates = (array) $node;
      break;
    }
  }

  if ( ! is_array( $candidates ) ) {
    return array();
  }

  $is_list = array_keys( $candidates ) === range( 0, count( $candidates ) - 1 );
  if ( ! $is_list ) {
    $candidates = array_values( $candidates );
  }

  $payments = array();
  foreach ( $candidates as $payment ) {
    if ( is_array( $payment ) ) {
      $payments[] = $payment;
    }
  }

  return $payments;
}

function csfx_lb_extract_wc_payments( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }

  $payments = csfx_lb_payments_from_openpos_transactions( $order );
  if ( ! empty( $payments ) ) {
    return $payments;
  }

  $payments = array();
  foreach ( csfx_lb_get_order_transactions_meta( $order ) as $payment ) {
    $payments[] = csfx_lb_normalize_payment_row( $payment );
  }

  if ( empty( $payments ) ) {
    $status = sanitize_key( (string) $order->get_status() );
    $remain_meta = csfx_lb_to_number( $order->get_meta( '_csfx_lb_remain_paid', true ) );
    if ( in_array( $status, array( 'pending', 'on-hold' ), true ) || $remain_meta > 0.01 ) {
      return array();
    }
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
  $payments = csfx_lb_collect_doc_payment_rows( $doc );
  $totals   = isset( $doc['totals'] ) && is_array( $doc['totals'] ) ? $doc['totals'] : array();
  $customer = isset( $doc['customer'] ) && is_array( $doc['customer'] ) ? $doc['customer'] : array();

  if ( empty( $items ) ) {
    return new WP_Error( 'csfx_lb_missing_items', 'No hay productos en el respaldo para crear el pedido.' );
  }

  $default_status = apply_filters( 'csfx_lb_resync_default_status', 'completed', $doc );
  $doc_status     = csfx_lb_doc_status( $doc );
  $order_status   = $doc_status ?: $default_status;
  if ( csfx_lb_doc_has_outstanding_balance( $doc ) ) {
    $laybuy_status = apply_filters( 'op_laybuy_order_status', 'pending' );
    $order_status  = $laybuy_status ?: 'pending';
  }

  $order_args = array(
    'status'      => $order_status,
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
  if ( ! empty( $primary_payment ) ) {
    $method_id = sanitize_key( $primary_payment['code'] ?? 'csfx-offline' );
    $method_title = sanitize_text_field( $primary_payment['label'] ?? $primary_payment['name'] ?? 'CSFX Resync' );
  } else {
    $method_id = 'csfx-laybuy';
    $method_title = __( 'Pagar en POS', 'csfx-lb' );
  }

  $order->set_payment_method( $method_id );
  $order->set_payment_method_title( $method_title );

  $customer_note = csfx_lb_apply_customer_to_order( $order, $customer );

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
    if ( ! csfx_lb_doc_has_outstanding_balance( $doc ) ) {
      $order->set_date_paid( $created_at );
    }
  }

  $transactions_meta = csfx_lb_build_transactions_meta( $payments, $order->get_order_number(), $doc, $cashier_user_id );
  if ( ! empty( $transactions_meta ) ) {
    $order->update_meta_data( '_openpos_transactions', $transactions_meta );
    $order->update_meta_data( '_op_transactions', $transactions_meta );
    $order->update_meta_data( '_op_payment_transactions', $transactions_meta );
    $order->update_meta_data( '_openpos_payment_transactions', $transactions_meta );
  }

  csfx_lb_sync_remain_meta( $order, $doc );

  $order->save();

  $creation_note = 'CSFX Local Backup: Pedido creado mediante resync automático.';
  if ( $customer_note ) {
    $creation_note .= "\n" . $customer_note;
  }
  $order->add_order_note( $creation_note );

  do_action( 'csfx_lb_resync_order_created', $order, $doc );

  return $order;
}

function csfx_lb_apply_customer_to_order( $order, $customer ){
  if ( ! $order instanceof WC_Order ) {
    return '';
  }
  if ( ! is_array( $customer ) || empty( $customer ) ) {
    return '';
  }

  $notes        = array();
  $user_id      = 0;
  $raw_id       = isset( $customer['id'] ) ? trim( (string) $customer['id'] ) : '';
  $email        = isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';
  $identifiers  = array();

  if ( $raw_id !== '' ) {
    $identifiers[] = 'ID ' . $raw_id;
    $candidate = get_user_by( 'ID', intval( $raw_id ) );
    if ( $candidate instanceof WP_User ) {
      $user_id = $candidate->ID;
    }
  }

  if ( ! $user_id && $email ) {
    $identifiers[] = $email;
    $candidate = get_user_by( 'email', $email );
    if ( $candidate instanceof WP_User ) {
      $user_id = $candidate->ID;
    }
  }

  if ( $user_id ) {
    $order->set_customer_id( $user_id );
  } elseif ( ! empty( $identifiers ) ) {
    $notes[] = sprintf(
      'Cliente POS no encontrado en WordPress (%s). Pedido creado como invitado.',
      implode( ', ', array_unique( $identifiers ) )
    );
  }

  $billing_source = array();
  if ( isset( $customer['billing_address'] ) && is_array( $customer['billing_address'] ) ) {
    $billing_source = $customer['billing_address'];
  }

  $billing_first_name = $billing_source['first_name'] ?? $customer['firstname'] ?? $customer['name'] ?? '';
  $billing_last_name  = $billing_source['last_name'] ?? $customer['lastname'] ?? '';
  $billing_company    = $billing_source['company'] ?? '';
  $billing_address_1  = $billing_source['address_1'] ?? $customer['address'] ?? '';
  $billing_address_2  = $billing_source['address_2'] ?? $customer['address_2'] ?? '';
  $billing_city       = $billing_source['city'] ?? $customer['city'] ?? '';
  $billing_state      = $billing_source['state'] ?? $customer['state'] ?? '';
  $billing_postcode   = $billing_source['postcode'] ?? $customer['postcode'] ?? '';
  $billing_country    = $billing_source['country'] ?? $customer['country'] ?? '';
  $billing_email      = $billing_source['email'] ?? $email;
  $billing_phone      = $billing_source['phone'] ?? $customer['phone'] ?? '';

  if ( $billing_first_name !== '' ) {
    $order->set_billing_first_name( sanitize_text_field( $billing_first_name ) );
  }
  if ( $billing_last_name !== '' ) {
    $order->set_billing_last_name( sanitize_text_field( $billing_last_name ) );
  }
  if ( $billing_company !== '' ) {
    $order->set_billing_company( sanitize_text_field( $billing_company ) );
  }
  if ( $billing_address_1 !== '' ) {
    $order->set_billing_address_1( sanitize_text_field( $billing_address_1 ) );
  }
  if ( $billing_address_2 !== '' ) {
    $order->set_billing_address_2( sanitize_text_field( $billing_address_2 ) );
  }
  if ( $billing_city !== '' ) {
    $order->set_billing_city( sanitize_text_field( $billing_city ) );
  }
  if ( $billing_state !== '' ) {
    $order->set_billing_state( sanitize_text_field( $billing_state ) );
  }
  if ( $billing_postcode !== '' ) {
    $order->set_billing_postcode( sanitize_text_field( $billing_postcode ) );
  }
  if ( $billing_country !== '' ) {
    $order->set_billing_country( sanitize_text_field( $billing_country ) );
  }
  if ( $billing_email !== '' ) {
    $order->set_billing_email( sanitize_email( $billing_email ) );
  }
  if ( $billing_phone !== '' ) {
    $order->set_billing_phone( sanitize_text_field( $billing_phone ) );
  }

  $shipping_source = array();
  if ( isset( $customer['shipping_address'] ) && is_array( $customer['shipping_address'] ) && ! empty( $customer['shipping_address'] ) ) {
    $first_shipping = reset( $customer['shipping_address'] );
    if ( is_array( $first_shipping ) ) {
      $shipping_source = $first_shipping;
    }
  }
  if ( empty( $shipping_source ) ) {
    $shipping_source = $billing_source;
  }

  if ( ! empty( $shipping_source ) ) {
    $shipping_first_name = $shipping_source['first_name'] ?? $shipping_source['name'] ?? $billing_first_name;
    $shipping_last_name  = $shipping_source['last_name'] ?? $billing_last_name;
    $shipping_address_1  = $shipping_source['address_1'] ?? $shipping_source['address'] ?? $billing_address_1;
    $shipping_address_2  = $shipping_source['address_2'] ?? $billing_address_2;
    $shipping_city       = $shipping_source['city'] ?? $billing_city;
    $shipping_state      = $shipping_source['state'] ?? $billing_state;
    $shipping_postcode   = $shipping_source['postcode'] ?? $billing_postcode;
    $shipping_country    = $shipping_source['country'] ?? $billing_country;
    $shipping_phone      = $shipping_source['phone'] ?? $billing_phone;

    if ( $shipping_first_name !== '' ) {
      $order->set_shipping_first_name( sanitize_text_field( $shipping_first_name ) );
    }
    if ( $shipping_last_name !== '' ) {
      $order->set_shipping_last_name( sanitize_text_field( $shipping_last_name ) );
    }
    if ( $shipping_address_1 !== '' ) {
      $order->set_shipping_address_1( sanitize_text_field( $shipping_address_1 ) );
    }
    if ( $shipping_address_2 !== '' ) {
      $order->set_shipping_address_2( sanitize_text_field( $shipping_address_2 ) );
    }
    if ( $shipping_city !== '' ) {
      $order->set_shipping_city( sanitize_text_field( $shipping_city ) );
    }
    if ( $shipping_state !== '' ) {
      $order->set_shipping_state( sanitize_text_field( $shipping_state ) );
    }
    if ( $shipping_postcode !== '' ) {
      $order->set_shipping_postcode( sanitize_text_field( $shipping_postcode ) );
    }
    if ( $shipping_country !== '' ) {
      $order->set_shipping_country( sanitize_text_field( $shipping_country ) );
    }
    if ( $shipping_phone !== '' ) {
      if ( method_exists( $order, 'set_shipping_phone' ) ) {
        $order->set_shipping_phone( sanitize_text_field( $shipping_phone ) );
      } else {
        $order->update_meta_data( '_shipping_phone', sanitize_text_field( $shipping_phone ) );
      }
    }
  }

  if ( ! empty( $customer['cedula_rif'] ) ) {
    $order->update_meta_data( '_csfx_customer_document', sanitize_text_field( $customer['cedula_rif'] ) );
  }
  if ( ! empty( $customer['cedula_rif_tipo'] ) ) {
    $order->update_meta_data( '_csfx_customer_document_type', sanitize_text_field( $customer['cedula_rif_tipo'] ) );
  }
  if ( ! empty( $customer['cedula_rif_num'] ) ) {
    $order->update_meta_data( '_csfx_customer_document_number', sanitize_text_field( $customer['cedula_rif_num'] ) );
  }

  return implode( ' ', $notes );
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

function csfx_lb_build_transactions_meta( $payments, $order_number, $doc, $cashier_user_id = 0 ){
  if ( ! is_array( $payments ) || empty( $payments ) ) {
    return array();
  }

  $dt = csfx_lb_parse_doc_datetime( $doc );
  if ( $dt instanceof WC_DateTime ) {
    $created_timestamp = $dt->getTimestamp();
    $created_local     = $dt->date_i18n( 'Y-m-d H:i:s' );
  } else {
    $created_timestamp = current_time( 'timestamp', true );
    $created_local     = current_time( 'mysql' );
    $dt                = null;
  }
  $created_utc = gmdate( 'Y-m-d H:i:s', $created_timestamp );

  $user_id    = $cashier_user_id ? intval( $cashier_user_id ) : get_current_user_id();
  $user_login = 'csfx';
  if ( $user_id ) {
    $user = get_user_by( 'id', $user_id );
    if ( $user && $user->user_login ) {
      $user_login = $user->user_login;
    }
  } else {
    $current_user = wp_get_current_user();
    if ( $current_user && isset( $current_user->user_login ) && $current_user->user_login ) {
      $user_login = $current_user->user_login;
    }
  }

  $session_id = '';
  if ( isset( $doc['eventSession'] ) ) {
    $session_id = sanitize_text_field( (string) $doc['eventSession'] );
  } elseif ( isset( $doc['session'] ) ) {
    $session_id = sanitize_text_field( (string) $doc['session'] );
  }

  $transactions = array();
  foreach ( $payments as $payment ) {
    $amount = csfx_lb_to_number( $payment['amount'] ?? 0 );
    $change = csfx_lb_to_number( $payment['change'] ?? 0 );
    $payment_code = sanitize_key( $payment['code'] ?? $payment['method'] ?? 'pago' );
    $payment_name = sanitize_text_field( $payment['name'] ?? $payment['label'] ?? $payment['method'] ?? 'Pago' );
    $payment_ref  = sanitize_text_field( $payment['payment_ref'] ?? $payment['ref'] ?? $payment['reference'] ?? '' );
    $currency     = null;
    if ( isset( $payment['currency'] ) && is_array( $payment['currency'] ) ) {
      $currency = $payment['currency'];
    } elseif ( isset( $payment['currency_code'] ) || isset( $payment['currency_symbol'] ) ) {
      $currency = array(
        'code'   => $payment['currency_code'] ?? '',
        'symbol' => $payment['currency_symbol'] ?? '',
      );
    } elseif ( isset( $doc['totals']['currency'] ) && is_array( $doc['totals']['currency'] ) ) {
      $currency = $doc['totals']['currency'];
    }

    $transactions[] = array(
      'code'           => $payment_code,
      'name'           => $payment_name,
      'payment_name'   => $payment_name,
      'payment_code'   => $payment_code,
      'in_amount'      => $amount,
      'paid'           => $amount,
      'amount'         => $amount,
      'total'          => $amount,
      'out_amount'     => $change,
      'change'         => $change,
      'return'         => $change,
      'return_amount'  => $change,
      'created_at'     => $created_local,
      'created_at_utc' => $created_utc,
      'created_at_time'=> $created_timestamp,
      'user'           => $user_login,
      'user_id'        => $user_id,
      'currency'       => $currency,
      'payment_ref'    => $payment_ref,
      'session'        => $session_id,
      'note'           => sprintf( 'Pedido #%s', $order_number ),
      'ref'            => $order_number,
      'method'         => $payment_code,
      'method_title'   => $payment_name,
    );
  }

  return $transactions;
}

function csfx_lb_canonicalize_for_signature( $value ){
  if ( is_array( $value ) ) {
    $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
    if ( $is_list ) {
      $normalized = array();
      foreach ( $value as $item ) {
        $normalized[] = csfx_lb_canonicalize_for_signature( $item );
      }
      return $normalized;
    }

    $normalized = array();
    $keys       = array_keys( $value );
    sort( $keys, SORT_STRING );
    foreach ( $keys as $key ) {
      if ( 'signature' === $key ) {
        continue;
      }
      $normalized[ $key ] = csfx_lb_canonicalize_for_signature( $value[ $key ] );
    }
    return $normalized;
  }

  return $value;
}

function csfx_lb_calculate_doc_signature( $doc, $secret = null ){
  $canonical = csfx_lb_canonicalize_for_signature( $doc );
  $canonical_json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES );
  if ( null === $secret ) {
    $secret = csfx_lb_signature_secret();
  }
  return hash_hmac( 'sha256', $canonical_json, $secret );
}

function csfx_lb_validate_doc_signature( $doc ){
  if ( ! is_array( $doc ) ) {
    return array( 'status' => 'invalid' );
  }

  if ( empty( $doc['signature'] ) ) {
    return array( 'status' => 'missing' );
  }

  $signature_node = $doc['signature'];
  $signature_value = '';
  if ( is_array( $signature_node ) && isset( $signature_node['value'] ) ) {
    $signature_value = (string) $signature_node['value'];
  } elseif ( is_string( $signature_node ) ) {
    $signature_value = $signature_node;
  }

  if ( '' === $signature_value ) {
    return array( 'status' => 'missing' );
  }

  $expected = csfx_lb_calculate_doc_signature( $doc );
  if ( hash_equals( $expected, $signature_value ) ) {
    return array( 'status' => 'valid' );
  }

  $candidates = csfx_lb_signature_secret_candidates();
  $primary    = isset( $candidates['primary'] ) ? $candidates['primary'] : null;
  $expected_primary = $expected;
  unset( $candidates['primary'] );

  foreach ( $candidates as $label => $secret_candidate ) {
    $expected_candidate = csfx_lb_calculate_doc_signature( $doc, $secret_candidate );
    if ( hash_equals( $expected_candidate, $signature_value ) ) {
      return array(
        'status' => 'valid_legacy',
        'used'   => $label,
      );
    }
  }

  return array(
    'status'   => 'invalid',
    'expected' => $expected_primary,
    'provided' => $signature_value,
  );
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

function csfx_lb_maybe_create_openpos_transactions( $order, $doc ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return;
  }

  $raw_payments = csfx_lb_collect_doc_payment_rows( is_array( $doc ) ? $doc : array() );
  $has_outstanding = csfx_lb_doc_has_outstanding_balance( $doc );
  $order_pending   = in_array( sanitize_key( (string) $order->get_status() ), array( 'pending', 'on-hold' ), true );
  $should_skip_transactions = empty( $raw_payments ) && ( $has_outstanding || $order_pending );

  $cashier_user_id = csfx_lb_find_cashier_user_id( $doc );
  if ( ! $cashier_user_id ) {
    $cashier_user_id = get_current_user_id();
  }

  $meta_changed = csfx_lb_sync_remain_meta( $order, $doc );

  global $op_transaction;
  $op_ready = is_object( $op_transaction ) && method_exists( $op_transaction, 'add' ) && method_exists( $op_transaction, 'getOrderTransactions' );
  $existing = array();
  if ( $op_ready ) {
    $existing = $op_transaction->getOrderTransactions( $order->get_id(), array( 'order' ) );
  }

  if ( $should_skip_transactions ) {
    if ( ! empty( $existing ) ) {
      foreach ( $existing as $transaction ) {
        $sys_id = isset( $transaction['sys_id'] ) ? absint( $transaction['sys_id'] ) : 0;
        if ( $sys_id ) {
          wp_delete_post( $sys_id, true );
        }
      }
    }
    $meta_changed = csfx_lb_clear_transactions_meta( $order ) || $meta_changed;
    if ( $meta_changed ) {
      $order->save();
    }
    return;
  }

  if ( empty( $raw_payments ) ) {
    $raw_payments[] = array(
      'code'   => $order->get_payment_method() ?: 'pago',
      'label'  => $order->get_payment_method_title() ?: 'Pago',
      'amount' => csfx_lb_to_number( $order->get_total() ),
      'change' => 0,
    );
  }

  $transactions_meta = csfx_lb_build_transactions_meta( $raw_payments, $order->get_order_number(), $doc, $cashier_user_id );
  if ( empty( $transactions_meta ) ) {
    return;
  }

  $order_local_id = '';
  if ( isset( $doc['orderLocalId'] ) ) {
    $order_local_id = sanitize_text_field( (string) $doc['orderLocalId'] );
  }
  if ( '' === $order_local_id ) {
    $order_local_id = sanitize_text_field( (string) $order->get_meta( '_op_local_id', true ) );
  }

  $session_id = '';
  if ( isset( $doc['eventSession'] ) ) {
    $session_id = sanitize_text_field( (string) $doc['eventSession'] );
  } elseif ( isset( $doc['session'] ) ) {
    $session_id = sanitize_text_field( (string) $doc['session'] );
  }

  if ( ! empty( $existing ) ) {
    foreach ( $existing as $transaction ) {
      $sys_id = isset( $transaction['sys_id'] ) ? absint( $transaction['sys_id'] ) : 0;
      if ( $sys_id ) {
        wp_delete_post( $sys_id, true );
      }
    }
  }

  if ( $op_ready ) {
    foreach ( $transactions_meta as $payment_meta ) {
      $payload = csfx_lb_prepare_transaction_payload(
        $payment_meta,
        $order,
        $order_local_id,
        $session_id,
        $cashier_user_id,
        isset( $doc['registerName'] ) ? sanitize_text_field( (string) $doc['registerName'] ) : ''
      );
      $op_transaction->add( $payload );
    }
  }

  $order->update_meta_data( '_openpos_transactions', $transactions_meta );
  $order->update_meta_data( '_op_transactions', $transactions_meta );
  $order->update_meta_data( '_op_payment_transactions', $transactions_meta );
  $order->update_meta_data( '_openpos_payment_transactions', $transactions_meta );
  $order->save();
}

function csfx_lb_transactions_have_value( $transactions ){
  if ( empty( $transactions ) || ! is_array( $transactions ) ) {
    return false;
  }
  foreach ( $transactions as $transaction ) {
    $in  = csfx_lb_to_number( $transaction['in_amount'] ?? $transaction['amount'] ?? 0 );
    $out = csfx_lb_to_number( $transaction['out_amount'] ?? $transaction['change'] ?? 0 );
    if ( csfx_lb_numbers_differs( $in, 0 ) >= 0.01 || csfx_lb_numbers_differs( $out, 0 ) >= 0.01 ) {
      return true;
    }
  }
  return false;
}

function csfx_lb_prepare_transaction_payload( $payment_meta, $order, $order_local_id, $session_id, $cashier_user_id, $register_name = '' ){
  $in_amount  = csfx_lb_to_number( $payment_meta['in_amount'] ?? $payment_meta['amount'] ?? 0 );
  $out_amount = csfx_lb_to_number( $payment_meta['out_amount'] ?? $payment_meta['change'] ?? 0 );
  $payment_code = sanitize_key( $payment_meta['payment_code'] ?? $payment_meta['code'] ?? $payment_meta['method'] ?? 'pago' );
  $payment_name = sanitize_text_field( $payment_meta['payment_name'] ?? $payment_meta['name'] ?? $payment_meta['label'] ?? 'Pago' );
  $created_at_local = $payment_meta['created_at'] ?? current_time( 'mysql' );
  $created_at_utc   = $payment_meta['created_at_utc'] ?? current_time( 'mysql', 1 );
  $created_at_time  = isset( $payment_meta['created_at_time'] ) ? absint( $payment_meta['created_at_time'] ) : current_time( 'timestamp', true );

  $source_data = array(
    'order_id'       => $order->get_id(),
    'order_number'   => $order->get_order_number(),
    'order_local_id' => $order_local_id,
  );
  if ( $register_name !== '' ) {
    $source_data['register_name'] = $register_name;
  }

  return array(
    'in_amount'       => $in_amount,
    'out_amount'      => $out_amount,
    'payment_code'    => $payment_code,
    'payment_name'    => $payment_name,
    'payment_ref'     => sanitize_text_field( $payment_meta['payment_ref'] ?? $payment_meta['ref'] ?? '' ),
    'created_at'      => $created_at_local,
    'created_at_utc'  => $created_at_utc,
    'created_at_time' => $created_at_time,
    'user_id'         => intval( $payment_meta['user_id'] ?? $cashier_user_id ),
    'source_type'     => 'order',
    'source'          => $order->get_id(),
    'source_data'     => $source_data,
    'session'         => $payment_meta['session'] ?? $session_id,
    'currency'        => $payment_meta['currency'] ?? null,
    'ref'             => $payment_meta['ref'] ?? sprintf( 'Pedido #%s', $order->get_order_number() ),
  );
}

function csfx_lb_sync_remain_meta( $order, $doc ){
  if ( ! $order instanceof WC_Order ) {
    return false;
  }
  $remain = csfx_lb_doc_remain_amount( $doc );
  $changed = false;
  if ( $remain > 0.01 ) {
    $order->update_meta_data( '_csfx_lb_remain_paid', $remain );
    $order->update_meta_data( '_op_remain_paid', $remain );
    $order->update_meta_data( '_op_allow_laybuy', 'yes' );
    $changed = true;
  } else {
    if ( $order->get_meta( '_csfx_lb_remain_paid', true ) ) {
      $order->delete_meta_data( '_csfx_lb_remain_paid' );
      $changed = true;
    }
    if ( $order->get_meta( '_op_remain_paid', true ) ) {
      $order->delete_meta_data( '_op_remain_paid' );
      $changed = true;
    }
    if ( $order->get_meta( '_op_allow_laybuy', true ) ) {
      $order->delete_meta_data( '_op_allow_laybuy' );
      $changed = true;
    }
  }
  return $changed;
}

function csfx_lb_clear_transactions_meta( $order ){
  if ( ! $order instanceof WC_Order ) {
    return false;
  }
  $keys = array(
    '_openpos_transactions',
    '_op_transactions',
    '_op_payment_transactions',
    'op_transactions',
    '_openpos_payment_transactions'
  );
  $changed = false;
  foreach ( $keys as $key ) {
    if ( $order->get_meta( $key, true ) ) {
      $order->delete_meta_data( $key );
      $changed = true;
    }
  }
  return $changed;
}

function csfx_lb_get_order_transactions_meta( $order ){
  if ( ! class_exists( 'WC_Order' ) || ! $order instanceof WC_Order ) {
    return array();
  }
  $candidates = array(
    '_openpos_transactions',
    '_op_transactions',
    '_op_payment_transactions',
    'op_transactions',
    '_openpos_payment_transactions'
  );
  $records = array();
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
    foreach ( $value as $row ) {
      if ( is_array( $row ) ) {
        $records[] = $row;
      }
    }
  }
  return $records;
}

function csfx_lb_order_paid_amount_from_transactions( $order ){
  $records = csfx_lb_get_order_transactions_meta( $order );
  if ( empty( $records ) ) {
    return 0;
  }
  $sum = 0;
  foreach ( $records as $record ) {
    $paid = csfx_lb_to_number( $record['in_amount'] ?? $record['amount'] ?? 0 );
    $change = csfx_lb_to_number( $record['out_amount'] ?? $record['change'] ?? 0 );
    $sum += max( 0, $paid - $change );
  }
  return max( 0, $sum );
}
