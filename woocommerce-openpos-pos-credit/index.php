<?php
/*
Plugin Name: OpenPos - POS Credit Checkout
Plugin URI: http://openswatch.com
Description: After customer take refund on POS via "POS Voucher" , A voucher generate with refund credit, customer can use this voucher to order other items in store. Work with openpos 5.4.9 or higher on payment mode "Single payment" or "Single payment with Multi times"
Author: anhvnit@gmail.com
Author URI: http://openswatch.com/
Version: 1.6
Requires Plugins: woocommerce, woocommerce-openpos
WC requires at least: 2.6
Text Domain: woocommerce-openpos-credit
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
define('OPENPOS_CREDIT_DIR',plugin_dir_path(__FILE__));
define('OPENPOS_CREDIT_URL',plugins_url('woocommerce-openpos-pos-credit'));
require_once( OPENPOS_CREDIT_DIR.'vendor/autoload.php');
require_once( OPENPOS_CREDIT_DIR.'lib/credit.php' );
add_action( 'wp_loaded', 'op_openpos_wp_loaded');

if( !function_exists('is_plugin_active') ) {
			
    include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
    
}
global $op_payment_credit ;
add_action('plugins_loaded',function(){
    add_action( 'rest_api_init', function(){
        register_rest_route( 'op/v1', '/check_voucher', array(
            'methods' =>  WP_REST_Server::CREATABLE,
            'callback' => 'check_voucher_callback',
            'permission_callback' => '__return_true',
            ) 
        );
    });
});

function check_voucher_callback($request){
    $result = array(
        'code' =>'unknown_error',
        'response' => array(
            'status' => 0,
            'data' => array(),
            'message' => ''
        ),
        'api_message' => ''
    );
    try{
        global $op_payment_credit;
        global $OPENPOS_SETTING; 
        if(!$op_payment_credit)
        {
            $op_payment_credit = new OP_Credit($OPENPOS_SETTING);
        }
        $data_request = json_decode($request->get_param('data_request'),true);
        $check =  $op_payment_credit->check_voucher($data_request);
        if(!$check['status'])
        {
            throw new Exception($check['message']);
        }else{
            $result['response']['status'] = 1;
            $result['response']['data'] = $check['data'];
        }
        $result['code'] = 200;
        $result['response']['status'] = 1;
    }catch(Exception $e)
    {
        $result['code'] = 400;
        $result['response']['status'] = 0;
        $result['response']['message'] = $e->getMessage();
        
    }
    return rest_ensure_response($result);
}
if(!function_exists('op_openpos_wp_loaded'))
{
    function op_openpos_wp_loaded()
    {
        if(is_plugin_active( 'woocommerce-openpos/woocommerce-openpos.php' ))
        {
            global $OPENPOS_SETTING; 
            global $op_payment_credit;
            $op_payment_credit = new OP_Credit($OPENPOS_SETTING);
            $op_payment_credit->init();
        }
    }
}

