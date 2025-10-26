<?php
if(!class_exists('OP_Credit'))
{
    class OP_Credit{
       
        public $payment_code = 'op_credit';
        public $payment_name = '';
        public $payment_description = '';
        public $op_setting;
       
        public function __construct($op_setting){   
            $this->payment_name = __('POS Voucher','woocommerce-openpos-credit');         
            $this->payment_description = __('Pay with pos credit voucher','woocommerce-openpos-credit');         
            $this->op_setting = $op_setting;
        }
        public function init(){
            $this->registerScripts();
            add_filter('op_addition_payment_methods',array($this,'op_addition_payment_methods'),10,1);
            add_filter('op_addition_general_setting',array($this,'op_addition_general_setting'),10,1);
            add_filter('op_login_format_payment_data',array($this,'op_login_format_payment_data'),10,2);
            add_filter('op_login_format_payment_data',array($this,'op_login_format_payment_data'),10,2);

            add_filter('openpos_pos_header_style',array($this,'openpos_pos_header_style'),20,1);
            add_filter('openpos_pos_footer_js',array($this,'openpos_pos_footer_js'),20,1);

            add_action( 'wp_ajax_nopriv_op_payment_'.$this->payment_code, array($this,'op_payment') );
            add_action( 'wp_ajax_op_payment_'.$this->payment_code, array($this,'op_payment') );

            add_action( 'wp_ajax_op_admin_vouchers', array($this,'op_admin_vouchers') );


            add_filter( 'op_api_result', array($this,'op_api_result'),10,2 );
            add_action( 'op_add_order_after', array($this,'op_add_order_after'),10,2 );

            add_action( 'admin_menu',  array($this,'admin_menu'),10 );

            
            
        }
        
        
        public function admin_enqueue(){

            wp_enqueue_style( 'openpos.voucher.boostrap.styles', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css');
            wp_enqueue_style( 'openpos.voucher.datatables.styles', 'https://cdn.datatables.net/v/dt/dt-1.10.25/datatables.min.css');
            wp_enqueue_style( 'openpos.voucher.admin.styles',OPENPOS_CREDIT_URL.'/assets/css/admin.css');

            wp_register_script( 'openpos.voucher.bootstrap.js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js' );
            wp_enqueue_script('openpos.voucher.databables.js', 'https://cdn.datatables.net/v/dt/dt-1.10.25/datatables.min.js');
            wp_enqueue_script('openpos.voucher.admin.js', OPENPOS_CREDIT_URL.'/assets/js/admin.js',array('jquery','openpos.voucher.bootstrap.js','openpos.voucher.databables.js'));

            wp_add_inline_script('openpos.voucher.admin.js',"
                       
                       var voucher_table_url = '".admin_url('admin-ajax.php?action=op_admin_vouchers')."';
                       
            ",'before');
        }
        public function admin_menu(){
             $page = add_submenu_page( 'openpos-dasboard', __( 'POS Vouchers', 'woocommerce-openpos-credit' ),  __( 'Vouchers', 'woocommerce-openpos-credit' ) , 'manage_options', 'op-pos-voucher', array($this,'admin_page') );
             add_action( 'admin_print_styles-'. $page, array($this,'admin_enqueue') );
        }
        
        public function registerScripts(){
        
            wp_enqueue_style( 'openpos.'.$this->payment_code.'.terminal.styles', OPENPOS_CREDIT_URL.'/assets/css/style.css');
            wp_register_script( 'openpos.'.$this->payment_code.'.terminal.base.js', OPENPOS_CREDIT_URL.'/assets/js/terminal.base.js');
            wp_enqueue_script('openpos.'.$this->payment_code.'.terminal.base.js');

            wp_add_inline_script('openpos.'.$this->payment_code.'.terminal.base.js',"
                       if(typeof op_payment_server_url == undefined || op_payment_server_url == undefined)
                       {
                            var op_payment_server_url = {};    
                       }
                       op_payment_server_url['".$this->payment_code."'] = '".admin_url('admin-ajax.php')."';
            ");
            wp_enqueue_script('openpos.'.$this->payment_code.'.terminal.js',  OPENPOS_CREDIT_URL.'/assets/js/terminal.js',array('jquery','openpos.'.$this->payment_code.'.terminal.base.js'));
        }

       
        public function openpos_pos_header_style($handles){
           
            $handles[] = 'openpos.'.$this->payment_code.'.terminal.styles';
        
            return $handles;
        }

        public function openpos_pos_footer_js($handles){
            
            $handles[] = 'openpos.'.$this->payment_code.'.terminal.base.js';
            $handles[] = 'openpos.'.$this->payment_code.'.terminal.js';
            return $handles;
        }
        public function op_addition_payment_methods($payment_options){
            $payment_options[$this->payment_code] = array(
                'code' => $this->payment_code,
                'admin_title' => $this->payment_name,
                'frontend_title' => $this->payment_name,
                'description' =>  $this->payment_description
            );
            $payment_options[$this->payment_code.'_return'] = array(
                'code' => $this->payment_code.'_return',
                'admin_title' => __('Refund with Voucher','woocommerce-openpos-credit'),
                'frontend_title' =>  __('Refund with Voucher','woocommerce-openpos-credit'),
                'description' =>   __('Generate voucher when payment less than 0','woocommerce-openpos-credit'),
            );
    
            return $payment_options;
        }
        public function op_addition_general_setting($addition_general_setting){

            $allow_payment_methods = $this->op_setting->get_option('payment_methods','openpos_payment');
            if(isset($allow_payment_methods[$this->payment_code]))
            {
                
                $addition_general_setting[] =     array(
                    'name'    => 'op_credit_expired',
                    'label'   => __( 'POS Credit Expiration', 'woocommerce-openpos-credit' ),
                    'desc'    => __( 'Duaration time to use pos credit', 'woocommerce-openpos-credit' ),
                    'type'    => 'select',
                    'default' => 'no_expire',
                    'options' => array(
                        'no_expire' => __( 'No Expire', 'woocommerce-openpos-credit' ),
                        //'within_24'  => __( 'In 24 hours', 'woocommerce-openpos-credit' ),
                        //'same_day'  => __( 'On Same Refund Date', 'woocommerce-openpos-credit' ),
                    )
                );
                
            }

            return $addition_general_setting;
        }
        public function op_login_format_payment_data($payment_method_data,$methods){
            if($payment_method_data['code'] == $this->payment_code)
            {
                $payment_method_data['type'] = 'voucher';
                $payment_method_data['online_type'] = 'voucher';
                $payment_method_data['allow_refund'] = 'online';
            }
            if($payment_method_data['code'] == $this->payment_code.'_return')
            {
                $payment_method_data['description'] = __('Generate Voucher when payment less than 0', 'woocommerce-openpos-credit');
                $payment_method_data['type'] = 'terminal';
                $payment_method_data['online_type'] = 'terminal';
                $payment_method_data['allow_refund'] = 'no';
                $payment_method_data['offline_transaction'] = 'no';
                $payment_method_data['partial'] = false;
                $payment_method_data['hasRef'] = false;
            }
            return $payment_method_data;
        }


        public function op_payment(){
            global $op_session;
            
            $result = array(
                'status' => 0,
                'data' => array(),
                'message' => ''
            );
            $action = isset($_REQUEST['op_payment_action']) ? $_REQUEST['op_payment_action'] : '';
            $session = isset($_REQUEST['session']) ? $_REQUEST['session'] : '';
            $session_data = $op_session->data($session);
            if($session_data && isset($session_data['login_cashdrawer_id']))
            {
                switch($action){
                    case 'CreateTerminalCheckout':
                        $amount = $_REQUEST['amount'] ? 1*$_REQUEST['amount'] : 0;
                        $payment = isset($_REQUEST['payment']) ? json_decode(stripslashes($_REQUEST['payment']),true) : array();
                        $order = isset($_REQUEST['order']) ? json_decode(stripslashes($_REQUEST['order']),true) : array();
                        if( $amount > 0)
                        {
                            
                            $currency = get_woocommerce_currency();
                            
                            $ref = (isset($order['order_number_format']) && $order['order_number_format']) ? $order['order_number_format'] : $order['order_number'];
                            $terminal_request_data = array(
                                'amountMoney' => $amount,
                                'Currency' => $currency,
                                'ReferenceId' => $ref,
                                'Note' => 'Pay '.$amount. ' For '.$ref,
                            );
                            $request_result = $this->CreateTerminalCheckout($terminal_request_data);
                            if($request_result['status'] === 0)
                            {
                                $errors = $request_result['data'];
                                $error_msg = [];
                                foreach($errors as $e)
                                {
                                    $error_msg[] = $e->getCode().':'.$e->getDetail();
                                }
                                $result['message'] = implode(' ,',$error_msg);
                            }else{
                                $result['data'] = $request_result['data'];
                            }
                            $result['status'] = $request_result['status'];

                                
                        }
                        break;
                    case 'GetTerminalCheckout':
                        $checkoutId = $_REQUEST['checkoutId'] ? $_REQUEST['checkoutId'] : '';
                        if($checkoutId)
                        {
                            $request_result = $this->GetTerminalCheckout($checkoutId);
                           
                        }
                        break;
                    case 'CreateTerminalRefund':
                        $result = $this->CreateTerminalRefund($session_data);
                        break;
                    case 'CancelTerminalCheckout':
                        $checkoutId = $_REQUEST['checkoutId'] ? $_REQUEST['checkoutId'] : '';
                        if($checkoutId)
                        {
                            
                        }
                        break;
                    case 'CreateTerminalNegativeCheckout':
                        $amount = $_REQUEST['amount'] ? 1*$_REQUEST['amount'] : 0;
                        if($amount < 0)
                        {
                            $voucher_amount = 0 - $amount;
                            $voucher_id = $this->create_voucher($voucher_amount,$session_data); 
                            if($voucher_id)
                            {
                                $result['status'] = 1;
                                $result['data'] = array(
                                    'code' => get_post_field('post_title',$voucher_id),
                                    'amount' => wc_price($voucher_amount),
                                    'ref' => 'Generated voucher '.substr(get_post_field('post_title',$voucher_id),0,5).'****',
                                );
                            }else{
                                $result['message'] = __('Can not create voucher','woocommerce-openpos-credit');
                            }
                        }else{
                            $result['message'] = __('Amount must be less than 0','woocommerce-openpos-credit');
                        }
                        break;
                    default:
                        break;
                }
                
            }
            echo json_encode($result);
            exit;
        }
        public function CreateTerminalCheckout($data){
            $result = array(
                'status' => 0,
                'data' => []
            );
            return $result;
        }
        public function GetTerminalCheckout($checkoutId){
            $apiResponse = $this->terminalApi->getTerminalCheckout($checkoutId);
            $result = array(
                'status' => 0,
                'data' => []
            );
            return $result;
        }
        public function CancelTerminalCheckout($checkoutId){
            $result = array(
                'status' => 0,
                'data' => []
            );
            return $result;
        }
        public function CreateTerminalRefund($session_data){
            $result = array(
                'status' => 0,
                'data' => array(),
                'message' => 'Unknown'
            );
            $amount = isset($_REQUEST['amount']) ? 1 * $_REQUEST['amount'] : 0;
            $refund =  isset($_REQUEST['refund']) ? json_decode(stripslashes($_REQUEST['refund']),true) : '';
           
            if($amount > 0)
            {
                
                $min = 1000000000;
                $max = ($min * 10) - 1;
                $rand_key = wp_rand($min,$max);
                $register_id = $session_data['login_cashdrawer_id'];
                $user_id = $session_data['user_id'];
                
                $key = '';
                $key .= $rand_key;
                $key .= $register_id;
                $refund_id = 0;
                $source = '';
                if(isset($refund['data']['id']))
                {
                    $refund_id = $refund['data']['id'];
                }
                if(isset($refund['data']['source']))
                {
                    $source = $refund['data']['source'];
                }

                $my_post = array(
                    'post_title'    => wp_strip_all_tags( $key ),
                    'post_type'  => '_op_pos_credit',
                    'post_status'   => 'publish',
                    'post_author'   => $user_id,
                );
                $post_id = wp_insert_post( $my_post );
                if($post_id)
                {
                    $expired_date = 'no_expire';
                    update_post_meta($post_id,'_value',$amount);
                    update_post_meta($post_id,'_value_used',0);
                    update_post_meta($post_id,'_refund_id',$refund_id);
                    update_post_meta($post_id,'_refund',$refund);
                    update_post_meta($post_id,'_expired',$expired_date);
                    update_post_meta($post_id,'_source',$source);
                    $result['status'] = 1;
                    $result['data'] = array(
                        'key' => $key,
                        'amount' => 1 * $amount,
                        'receipt_html' => $this->receipt_html($post_id)
                    );
                }
            }

            return $result;
        }
        public function create_voucher($amount,$extra_data = array(),$refund = false){
            $min = 1000000000;
            $max = ($min * 10) - 1;
            $rand_key = wp_rand($min,$max);
            $register_id = isset($extra_data['login_cashdrawer_id']) ? $extra_data['login_cashdrawer_id'] : 0;
            $user_id = isset($extra_data['user_id'])? $extra_data['user_id'] : 0;
            
            $key = '';
            $key .= $rand_key;
            $key .= $register_id;
            $refund_id = 0;
            $source = '';
            if($refund && isset($refund['data']['id']))
            {
                $refund_id = $refund['data']['id'];
            }
            if($refund && isset($refund['data']['source']))
            {
                $source = $refund['data']['source'];
            }

            $my_post = array(
                'post_title'    => wp_strip_all_tags( $key ),
                'post_type'  => '_op_pos_credit',
                'post_status'   => 'publish',
                'post_author'   => $user_id,
            );
            $post_id = wp_insert_post( $my_post );
            if($post_id)
            {
                $expired_date = 'no_expire';
                update_post_meta($post_id,'_value',$amount);
                update_post_meta($post_id,'_value_used',0);
                if($refund)
                {
                    update_post_meta($post_id,'_refund_id',$refund_id);
                    update_post_meta($post_id,'_refund',$refund);
                }
                
                update_post_meta($post_id,'_expired',$expired_date);
                update_post_meta($post_id,'_source',$source);
            }
            return $post_id;
        }
        public function get_voucher_by_key($code){


            global $wpdb;

            $page = $wpdb->get_row("select ID from $wpdb->posts where post_title = '$code' AND post_type = '_op_pos_credit' ");

           
            if($page)
            {
                return $this->get_voucher_by_id($page->ID);
            }
            return false;
        }
        public function use_voucher_key($code,$amount,$order_id){
            
            if($voucher = $this->get_voucher_by_key($code))
            {
                $used_by = $voucher['used_by'];
                if(!in_array($order_id,$used_by))
                {
                    $used_by[] = $order_id;
                    $post_id = $voucher['id'];
                    $new_used_amount = $voucher['used_amount'] + $amount;
                    update_post_meta($post_id,'_value_used',$new_used_amount);
                    update_post_meta($post_id,'_value_used',$new_used_amount);
                    update_post_meta($post_id,'_used_by',$used_by);
                }
                
            }
            
        }
        public function get_voucher_by_id($post_id){
            if($post = get_post($post_id))
            {
                
                $key = $post->post_title;
                $amount = get_post_meta($post_id,'_value',true);
                $used_amount = get_post_meta($post_id,'_value_used',true);
                $expired_date = get_post_meta($post_id,'_expired',true);
                $_source = get_post_meta($post_id,'_source',true);
                $_used_by = get_post_meta($post_id,'_used_by',true);
                if(!$used_amount)
                {
                    $used_amount = 0;
                }
                if(!$_used_by)
                {
                    $_used_by = array();
                }
                $result = array(
                    'id' => $post_id,
                    'key' => $key,
                    'amount' => 1 * $amount,
                    'used_amount' => 1 * $used_amount,
                    'available_amount' => 1 * ($amount - $used_amount),
                    'expired_date' => $expired_date,
                    'expired_utc_time' => 0,
                    'used_by' => $_used_by,
                    'source' => $_source,
                    'created_at' => $post->post_date,
                    'created_at_gmt' => $post->post_date_gmt
                );
                return $result;
            }
            return false;
        }
        public function receipt_html($post_id)
        {
            $voucher = $this->get_voucher_by_id($post_id);
            ob_start();
            require(OPENPOS_CREDIT_DIR.'/templates/receipt.php');
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        }
        public function CancelTerminalRefund(){
            $result = array(
                'status' => 0,
                'data' => [],
                'message' => ''
            );
            return $result;
        }
        public function check_voucher($data){
            $result['status'] = 0;
            $result['message'] = 'error';
            if(isset($data['payment']) && $data['payment']['code'] == $this->payment_code)
            {
                $code = $data['code'];
                if($voucher = $this->get_voucher_by_key($code))
                {
                    
                    $voucher_details = array(
                        'code' => $voucher['key'],
                        'amount' => 1 * $voucher['available_amount']
                    );
                    $result['status'] = 1;
                    $result['data'] = $voucher_details;
                }else{
                    $result['status'] = 0;
                    $result['message'] = sprintf(__( 'Voucher with code "%s" is not found. Please try again!', 'woocommerce-openpos-credit' ),$code);
                }
                
            }
            return $result;
        }
        public function op_api_result($result,$api_action){
            if($api_action == 'check_voucher')
            {
                $data_request = json_decode(stripslashes($_REQUEST['data_request']),true);
                if(isset($data_request['payment']) && $data_request['payment']['code'] == $this->payment_code)
                {
                    $code = $data_request['code'];
                    if($voucher = $this->get_voucher_by_key($code))
                    {
                        
                        $voucher_details = array(
                            'code' => $voucher['key'],
                            'amount' => 1 * $voucher['available_amount']
                        );
                        $result['status'] = 1;
                        $result['data'] = $voucher_details;
                    }else{
                        $result['status'] = 0;
                        $result['message'] = sprintf(__( 'Voucher with code "%s" is not found. Please try again!', 'woocommerce-openpos-credit' ),$code);
                    }
                    
                }
            }
            return $result;
        }
        public function op_add_order_after($order,$order_data){
            $payment_methods = isset($order_data['payment_method']) ? $order_data['payment_method'] : array();
            foreach($payment_methods as $payment)
            {
                if($payment['code'] == $this->payment_code){
                    if(isset($payment['callback_data']))
                    {
                        $data_callback = $payment['callback_data'];
                        foreach($data_callback as $v)
                        {
                            $this->use_voucher_key($v['voucher_number'],$v['use_amount'],$order->get_id());
                        }
                    }
                }
            }
        }
        public function admin_page(){
            require(OPENPOS_CREDIT_DIR.'/templates/admin.php');
        }
        public function op_admin_vouchers(){
            $result = array(
                "draw"=>  1,
                "recordsTotal"=>  0,
                "recordsFiltered"=>  0,
                'data' => array()
            );

            $per_page = intval($_REQUEST['length']);
            $start = intval($_REQUEST['start']);
            $term = isset($_REQUEST['search']['value']) ? sanitize_text_field($_REQUEST['search']['value']): '' ;
            $order = isset($_REQUEST['order'][0]['dir']) ? esc_attr($_REQUEST['order'][0]['dir']) : 'asc';
            $draw = isset($_REQUEST['draw']) ? esc_attr($_REQUEST['draw']) : 1;
            $params = array(
                'term' => $term,
                'per_page' => $per_page,
                'start' => $start,
                'order' => $order
            );

            $total_args = array(
                'post_type' => '_op_pos_credit',
                'post_status' => 'publish'
            );
            $total_query = new WP_Query( $total_args );
            $total = $total_query->found_posts;
            $paged = 1;
            if($total > 0)
            {
                $paged = ceil($start/$total) + 1;
            }
            
            $args = array(
                'post_type' => '_op_pos_credit',
                'posts_per_page' => $per_page,
                'paged' => $paged,
                //'post_title' => $term,
                'post_status' => 'publish',
                'orderby'     => 'date', 
                'order'       => 'DESC'
            );
            
            $rows = array();
            $the_query = new WP_Query( $args );
            $posts = $the_query->get_posts();
            
            foreach($posts as $p)
            {
                if($voucher = $this->get_voucher_by_id($p->ID))
                {
                    $output_format = 'Y-m-d H:i:s';
                    // Now we can use our timestamp with get_date_from_gmt()
                    $local_timestamp = get_date_from_gmt( $voucher['created_at_gmt'], $output_format );
                    $voucher['amount'] = wc_price($voucher['amount']);
                    $voucher['used_amount'] = wc_price($voucher['used_amount']);
                    $voucher['action'] = '';
                    $voucher['expired_date'] = '--/--';
                    $voucher['created_at'] =  $local_timestamp;
                    $rows[] = $voucher;
                }
            }
            $result['draw'] = $draw;
            $result['data'] = $rows;
            $result['recordsTotal'] = $total;
            $result['recordsFiltered'] = $total;
            echo json_encode($result);
            exit;
        }


    }
}