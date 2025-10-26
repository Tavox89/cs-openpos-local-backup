

(function($) {
    $(document).ready(function() {
        $('#example').DataTable( {
            "ordering": false,
            "searching": false,
            "processing": true,
            "serverSide": true,
            "ajax": {
                "url": voucher_table_url,
                "type": "POST"
            },
            "columns": [
                { "data": "key" },
                { "data": "amount" },
                { "data": "used_amount" },
                { "data": "expired_date" },
                { "data": "created_at" },
               // { "data": "action" }
            ]
        } );
    } );
   

}(jQuery));