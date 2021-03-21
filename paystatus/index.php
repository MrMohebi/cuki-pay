<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="stylesheet" href="style/style.css">
    <title>Pay Status</title>
</head>
<body>
<?php
if (!(isset($_GET['paymentId']) && isset($_GET['trackingId']) && isset($_GET['amount']) && isset($_GET['statusCode']))) {
    exit();
}
?>
<div class="payStatusMain">
    <?php
    $paymentStatus = $_GET['statusCode'];

    if ($paymentStatus == 200) {
        echo '<div class="happyCuki" ></div>';
    } else {
        echo '<div class="sadCuki" ></div>';
    }
    ?>

    <div class="statTextGroup">
        <?php
        if ($paymentStatus == 200) {
            echo '<div class="tickClass"></div>
        <div class="statText">پرداخت شد</div>';
        }else if($paymentStatus == 409){
            echo '<div class="statText">پرداخت نشد</div>';
            echo '<br/>';
            echo '<div class="more-info">فکر کنم دوستتون قبلا یکی دوتا از این آیتم ها رو پرداخته</div>';
        } else {
            echo '<div class="statText">پرداخت نشد</div>';
        }
        ?>


    </div>

    <div class="orderId">
        <span class="idNumver"><?php
            $trackingId = $_GET['trackingId'];
            echo $trackingId
            ?></span>
        <span class="idHolder">شماره سفارش</span>
    </div>

    <div class="orderId">
        <span class="idNumver"><?php
            
            $paymentId = $_GET['paymentId'];

            echo $paymentId
            ?></span>
        <span class="idHolder">
کد پیگیری پرداخت        </span>
    </div>

    <div class="orderId">

        <span class="idHolder">
            <?php
            $amount = $_GET['amount'];
            echo $amount
            ?>T
        </span>

        <span class="idNumver">
            مبلغ پرداختی
        </span>

    </div>
    <div class="orderId">
        <span class="paidItems">آیتم های پرداختی</span>
    </div>

    <div class="paidItemsElements">
                <?php
                $item = json_decode($_GET['item'],true);
                for ($i=0;$i< sizeof($item);$i++){
                    echo "<span class='eachOrderPayStat'><span> {$item[$i]['number']} x </span> {$item[$i]['name']} </span>";
                }
                ?>
    </div>

</div>


</body>
</html>