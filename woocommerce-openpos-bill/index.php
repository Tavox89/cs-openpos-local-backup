<?php
/*
Plugin Name: Woocommerce OpenPos Offline Customer Pole Display 
Plugin URI: http://openswatch.com
Description:  Woocommerce OpenPos Offline Customer Pole Display. YOU SHOULD OPEN POS AND BILL ON SAME BROWSER.
Author: anhvnit@gmail.com
Author URI: http://openswatch.com/
Version: 1.1
Requires Plugins: woocommerce, woocommerce-openpos
WC requires at least: 2.6
Text Domain: openpos-bill-offline
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
define('OPENPOS_CUSTOM_BILL_DIR',plugin_dir_path(__FILE__));
define('OPENPOS_CUSTOM_BILL_URL',plugins_url('woocommerce-openpos-bill'));
add_action( 'init', function(){
    global $OPENPOS_SETTING;
    global $op_in_pos_screen;
    wp_register_script('openpos.bill.pos',  OPENPOS_CUSTOM_BILL_URL.'/assets/js/pos.js',array('jquery'));
    if($op_in_pos_screen)
    {

        
        
    }else{
        wp_enqueue_script('wp-util');
        wp_enqueue_script('openpos.custom.bill',  OPENPOS_CUSTOM_BILL_URL.'/assets/js/bill.js',array('jquery','wp-util'));
    }
} ,10 );
add_filter('openpos_pos_footer_js',function($handles){
    $handles[] = 'openpos.bill.pos';
    return $handles;
},200,1);


add_filter('op_register_menu',function($result,$register){
    $result = array();
    $result[] = array(
        'url' => admin_url('admin.php?page=op-registers&id='.esc_attr($register['id'])),
        'label' => __('Edit','openpos')
    );
    $result[] = array(
        'url' => 'javascript:void(0);',
        'label' => __('Delete','openpos'),
        'attributes' => array(
            'data-id' => esc_attr($register['id']),
            'class' => 'delete-register-btn'
        )
    );
    $result[] = array(
        'url' => admin_url('admin.php?page=op-transactions&register='.esc_attr($register['id'])),
        'label' => __('Transactions','openpos')
    );
    $result[] = array(
        'url' => admin_url('edit.php?post_type=shop_order&register='.esc_attr($register['id'])),
        'label' => __('Orders','openpos')
    );
    $result[] = array(
        'url' => add_query_arg('openpos-bill', $register['id'], site_url()),
        'label' => __('Bill Screen','openpos'),
        'attributes' => array(
            'target' => '_blank'
        )
    );
    return $result;
},10,2);

add_action('init', function() {
    add_rewrite_rule('^openpos-bill/?$', 'index.php?openpos-bill=?$', 'top');
});

add_filter('query_vars', function($vars) {
    $vars[] = 'openpos-bill';
    return $vars;
});

add_action('template_redirect', function() {
    if (get_query_var('openpos-bill')) {
        // Output your special page content here
        status_header(200);
        nocache_headers();
        require_once plugin_dir_path(__FILE__) . 'templates/bill.php';
        exit;
    }
});
