<?php
// Customer-facing pole display template for WooCommerce OpenPOS Bill with item details

// Example variables (replace with actual data from your POS system)
$order_total = isset($order_total) ? $order_total : '0.00';
$customer_name = isset($customer_name) ? $customer_name : 'Guest';
$thank_you_message = __('Thank you for shopping!','openpos-bill-offline');
$order_items = isset($order_items) && is_array($order_items) ? $order_items : [];
$locale_raw    = function_exists('determine_locale') ? determine_locale() : ( function_exists('get_user_locale') ? get_user_locale() : get_locale() );
$locale_string = str_replace('_', '-', $locale_raw);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo __('Pole Display','openpos-bill-offline'); ?></title>
    <style>
        body {
            background: #000;
            color: #0f0;
            font-family: 'Courier New', Courier, monospace;
            font-size: 2em;
            text-align: center;
            margin: 0;
            padding: 0;
        }
        .pole-container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 100vh;
        }
        .pole-line {
            margin: 20px 0;
        }
        .items-table {
            width: 100%;
            margin: 0 auto 20px auto;
            border-collapse: collapse;
            font-size: 1em;
        }
        .items-table th, .items-table td {
            padding: 0 10px;
        }
        .items-table th.itemname{
            text-align: left;
        }
        .items-table th {
            text-align: right;
            font-weight: bold;
        }
        .items-table td {
            text-align: right;
        }
        .items-table td.name {
            text-align: left;
        }
        .pole-container {
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            height: 100vh;
        }

        .header-line, .footer-line {
            flex: 0 0 auto;
            width: 100%;
            background: #000;
            z-index: 2;
        }

        .item-container {
            flex: 1 1 auto;
            overflow-y: auto;
            max-height: 60vh;
        }
        .sub-title{
            font-size: 20px;
            margin: 0 0 10px 25px;
            font-style: italic;
        }
    </style>
    <script type="text/template" id="tmpl-product-item">
        <div class="header-line">
            <div class="pole-line"> <%= cart.register.name  %></div>
            <div class="pole-line"> <%= cart.sale_person_name %></div>
        </div>
        <div class="item-container">
        <table class="items-table">
            <thead>
                <tr>
                    <th class="itemname"><?php echo __('Item','openpos-bill-offline'); ?></th>
                    <th><?php echo __('Qty','openpos-bill-offline'); ?></th>
                    <th><?php echo __('Subtotal','openpos-bill-offline'); ?></th>
                </tr>
            </thead>
            <tbody>
            <% _.each(cart.items, function(item){ %>
                   
                    <tr>
                            <td class="name">
                                <%= item.name %> 
                                <% if(item.sub_name.length > 0){ %>
                                <p class="sub-title"><%= item.sub_name %></p>
                                <% } %>
                            </td>
                            <td><%= item.qty %></td>
                            <td><%= item.price.toLocaleString('<?php echo $locale_string ?>') %></td>
                            
                    </tr>
                </tr>
            <% }); %>  
               
            </tbody>
        </table>
        </div>
        <div class="footer-line" id="pole-container">
            <div class="pole-line"><?php echo __('TOTAL','openpos-bill-offline'); ?>: <%= cart.grand_total.toLocaleString('<?php echo $locale_string ?>') %></div>
            <div class="pole-line"><?php echo esc_html($thank_you_message); ?></div>
        </div>
    </script>
</head>
<body>
    <div class="pole-container" id="pole-container">
        <div class="footer-line">
            <div class="pole-line"><?php echo __('TOTAL','openpos-bill-offline'); ?>: <?php echo esc_html($order_total); ?></div>
            <div class="pole-line"><?php echo esc_html($thank_you_message); ?></div>
        </div>
    </div>

<?php
    $handes = array(
        'openpos.custom.bill'
    );
    wp_print_scripts($handes);
   
?>
</body>
</html>