

  (function($) {
    var processing_checkout_id = '';
    var processing_method = null;
    var processing_order = null;
    var processing_refund_receipt_html = '';
    
    var in_checking = false;

    function start_loading(){
        $('body').append('<div id="op-square-loading"><div id="op-square-loading-content"><div class="op-square-msg"></div><a href="javascript:void(0);"class="op-square-close">Close</a></div></div>')
    }
    function stop_loading(){
        $('body').find('#op-square-loading').remove();
    }



    document.addEventListener("openpos.start.payment", function (e) {
       
        var detail = e['detail'];
        var order = detail['data'];
        var method = detail['method'];
        var amount = 1 * detail['amount'];
        var session_id = detail['session'];
        let payment_code = method['code'];
        console.log(payment_code);
        console.log(op_payment_server_url);
        if(payment_code == 'op_credit' && op_payment_server_url &&  op_payment_server_url[payment_code] != undefined)
        {
            processing_checkout_id = '';
            processing_method = method;
            processing_order = order;
            start_loading();
            
            $.ajax({
                url: op_payment_server_url[payment_code],
                method: "POST",
                data: {action:'op_payment_'+method['code'],op_payment_action: 'CreateTerminalCheckout',amount: amount,order: JSON.stringify(order),payment: JSON.stringify(detail),session: session_id},
                dataType: 'json',
                beforeSend:function(){
                    $('body').find('.op-square-msg').text('Sending data....');
                },
                success: function(response){
                    if(response['status'] == 1)
                    {
                        var reponse_data = response['data'];
                        processing_checkout_id = reponse_data['id'];
                        let done_html = '<p>Done</p>';
                        done_html += '<table class="voucher-table">';
                        done_html += '<tr>';
                        done_html += '<th>Code</th';
                        done_html += '<th></th';
                        done_html += '</tr>';

                        done_html += '<tr>';
                        done_html += '<th></th';
                        done_html += '<td></td';
                        done_html += '</tr>';
                        done_html += '</table>';
                        $('body').find('.op-square-msg').html(done_html);  

                        //check_status(session_id,payment_code);
                    }else{
                        $('body').find('.op-square-msg').text(response['message']);
                    }
                }
            });
            
        }
        if(payment_code == 'op_credit_return')
        {
            if(amount < 0)
            {
                processing_checkout_id = '';
                processing_method = method;
                processing_order = order;
                start_loading();
                
                $.ajax({
                    url: op_payment_server_url['op_credit'],
                    method: "POST",
                    data: {action:'op_payment_op_credit',op_payment_action: 'CreateTerminalNegativeCheckout',amount: amount,order: JSON.stringify(order),payment: JSON.stringify(detail),session: session_id},
                    dataType: 'json',
                    beforeSend:function(){
                        $('body').find('.op-square-msg').text('Sending data....');
                    },
                    success: function(response){
                        if(response['status'] == 1)
                        {
                            let paymentFired = new CustomEvent("openpos.paid.payment", {
                                "detail": {"method": detail['method'],"ref": response.data.ref , "order_id": order['id'],'message':'Transaction done ',"amount": amount, "data":response.data  }
                            });
                        
                            document.dispatchEvent(paymentFired);
                            processing_checkout_id = '';
                            processing_method = null;
                            processing_order = null;
                            stop_loading();
                        }else{
                            $('body').find('.op-square-msg').text(response['message']);
                        }
                    }
                });
            }else{
                alert('This method is only for negative amount');
                return;
            }
            
        }
    });

    document.addEventListener("openpos.start.refund", function (e) {
       
       
        let detail = e['detail'];
        let amount = detail['amount'];
        let payment = detail['method'];
        let refund_details = detail['data'];
        let session_id = detail['session'];
        let payment_code = payment['code'];
        console.log(payment);
        console.log(e);
        if(op_payment_server_url &&  op_payment_server_url[payment_code] != undefined)
        {
            processing_checkout_id = '';
            processing_method = payment;
            processing_order = payment;
            start_loading();
            
            $.ajax({
                url: op_payment_server_url[payment_code],
                method: "POST",
                data: {action:'op_payment_'+payment_code,op_payment_action: 'CreateTerminalRefund',amount: amount,refund: JSON.stringify(detail),payment: JSON.stringify(payment),session: session_id},
                dataType: 'json',
                beforeSend:function(){
                    $('body').find('.op-square-msg').text('Sending data....');
                },
                success: function(response){
                    if(response['status'] == 1)
                    {
                        var reponse_data = response['data'];
                        voucher_number = reponse_data['key'];
                        voucher_amount = reponse_data['amount'];
                        voucher_receipt_html = reponse_data['receipt_html'];

                        let done_html = '<p>Done</p>';
                        done_html += '<table class="voucher-table">';
                        done_html += '<tr>';
                        done_html += '<th>Code</th>';
                        done_html += '<th>Amount</th>';
                        done_html += '<th></th>';
                        done_html += '</tr>';

                        done_html += '<tr>';
                        done_html += '<th>'+voucher_number+'</th>';
                        done_html += '<td>'+ 1* voucher_amount+'</td>';
                        done_html += '<td><a href="javascript:void(0)" class="op-print-refund-receipt">Print Voucher</a></td>';
                        done_html += '</tr>';
                        done_html += '</table>';

                        $('body').find('.op-square-msg').html(done_html);  

                        processing_refund_receipt_html = voucher_receipt_html;
                        let fire_data = {
                            amount: (1 * voucher_amount),
                            ref: voucher_number,
                            refund_id: refund_details['id'],
                            method: payment,
                            session: session_id,
                            success: 'yes'
                          };
                          console.log(fire_data);
                        let paymentFired = new CustomEvent("openpos.complete.refund", {
                            "detail": fire_data
                          });
                
                         document.dispatchEvent(paymentFired);

                        
                    }else{
                        $('body').find('.op-square-msg').text(response['message']);
                    }
                }
            });
            
        }

        

        
    });


    $(document).on('click','.op-square-close',function(){
        processing_checkout_id = '';
        processing_method = null;
        processing_order = null;
        stop_loading();
    });
    $(document).on('click','.op-print-refund-receipt',function(){
        let paymentFired = new CustomEvent("openpos.print.custom", {
            "detail": {
              content: processing_refund_receipt_html
            }
          });
         document.dispatchEvent(paymentFired);
    });
    
   

}(jQuery));