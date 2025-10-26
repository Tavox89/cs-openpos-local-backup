<?php
$generator = new Picqer\Barcode\BarcodeGeneratorPNG();
?>
<html>
<head>
    <style>
            h1{
            font-size: 1.5em;
            color: #000;
            }
            h2{font-size: .9em;}
            h3{
            font-size: 1.2em;
            font-weight: 300;
            line-height: 2em;
            }
            p{
            font-size: .7em;
            color: #000;
            line-height: 1.2em;
            }
            .info{
                display: block;
                margin-left: 0;
                text-align: center;
            }
            .info p{
                margin: 0;
                padding: 0 2px;
            }
            .title{
            float: right;
            }
            .title p{text-align: right;}
            table{
            width: 100%;
            border-collapse: collapse;
            }
            .tabletitle{
            font-size: .5em;
            }
            .items-table-label{
            border-bottom:solid 1px #000;
            }
            .service{border-bottom: 1px dotted #000;}
            .item{width: 24mm;}
            .itemtext{
                font-size: .5em;
                margin-bottom:0;
                display: inline-block;
            }
            .option-item{
                font-size: .5em;
                font-style: italic;
                display: block;
                color: #000;
            }
            .item-qty .itemtext{
                text-align: center;
            }
            .item-time .itemtext{
                text-align: right;
            }
            #top .info{
                text-align:center;
            }
            .served .tableitem p{
                text-decoration: line-through;
            }
            #invoice-container{
                page-break-inside: avoid;
                padding: 0;
                margin:0;

            }
            #op-page-cut{page-break-after: always; }
            .barcode-image{
                text-align: center;
            }
    </style>
</head>
<body>
    <div id="invoice-container">
        <div class="info">
            <h2>Voucher - <?php echo wc_price($voucher['amount']); ?></h2>
            <div class="barcode-image">
                <?php echo '<img src="data:image/png;base64,' . base64_encode($generator->getBarcode($voucher['key'], $generator::TYPE_CODE_128)) . '">'; ?>
                <p><?php echo $voucher['key']; ?></p>
            </div>
        </div>
    </div>
    <p id="op-page-cut">&nbsp;</p>
</body>
</html>