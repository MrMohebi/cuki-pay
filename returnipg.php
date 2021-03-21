<?php
header('content-type: application/json; charset=utf-8');
if(isset($_POST['refid'])){
    include_once "db/db.config.php";
    include_once "token/token.php";

    $code = mysqli_real_escape_string($conn_database_ours, $_POST['code']);
    $refid = mysqli_real_escape_string($conn_database_ours, $_POST['refid']);
    $clientrefid = mysqli_real_escape_string($conn_database_ours, $_POST['clientrefid']);
    $cardnumber = mysqli_real_escape_string($conn_database_ours, $_POST['cardnumber']);
    $cardhashpan = mysqli_real_escape_string($conn_database_ours, $_POST['cardhashpan']);

    // get restaurant english name
    $paymentKey = explode("-",$clientrefid)[1];
    $englishName = false;
    $sql_get_trackingId = "SELECT * FROM restaurants WHERE `payment_key`='$paymentKey' AND `position` = 'admin';";
    if ($result = mysqli_query($conn_database_ours, $sql_get_trackingId)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $englishName = $row['english_name'];
        }
    }
    $connRes = $dbs[$englishName];

    // get payment info
    $paymentInfo = false;
    $sql_get_paymentInfo = "SELECT * FROM payments WHERE `payment_id`='$clientrefid';";
    if ($result = mysqli_query($conn_database_ours, $sql_get_paymentInfo)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $paymentInfo = $row;
        }
    }


    // check if foods r not paid
    // if its paid before, dont verify payment and give back money (it will be done after about 10 min)
    if(($paymentInfo["item_type"] == "food") && isFoodsPaid($paymentInfo, $paymentInfo["payment_group"], $paymentInfo["tracking_id"], $connRes, $conn_database_ours)){
        header("location: https://pay.cuki.ir/paystatus/?".
            "statusCode=409".
            "&amount=".$paymentInfo['amount'].
            "&paymentId=".$clientrefid.
            "&trackingId=".$paymentInfo['tracking_id'].
            "&itemType=".$paymentInfo['item_type'].
            "&item=".json_encode(json_decode($paymentInfo['item']))
        );
        exit();
    }



    $api_key = $PAYPINGTOKEN;
    $info_params = array(
        "refId" => $refid,
        'amount'=> $paymentInfo['amount'],
    );

    $requestHandler = curl_init();
    curl_setopt($requestHandler, CURLOPT_URL, 'https://api.payping.ir/v2/pay/verify');
    curl_setopt($requestHandler, CURLOPT_POSTFIELDS, json_encode($info_params));
    curl_setopt($requestHandler, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($requestHandler, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Content-Type: application/json',
        "Authorization: bearer $api_key",
    ));

    $result = json_decode(curl_exec($requestHandler), true);
    curl_close($requestHandler);
    $verifyCardNumber = $result['cardNumber'];

    //check payment is valid and its not dublicated
    if(strlen($verifyCardNumber) > 4 && ($paymentInfo["verified_date"] < 1000)){
        $sql_update_payment_paid = "UPDATE payments SET `verified_date`='$nowTimestamp', `payer_card`= '$verifyCardNumber' WHERE `payment_id`='$clientrefid';";
        if(mysqli_query($conn_database_ours, $sql_update_payment_paid)){
            if($paymentInfo['item_type'] == "food"){
                if(foodPaid($paymentInfo,  $connRes)){
                    header("location: https://pay.cuki.ir/paystatus/?".
                        "statusCode=200".
                        "&amount=".$paymentInfo['amount'].
                        "&paymentId=".$clientrefid.
                        "&trackingId=".$paymentInfo['tracking_id'].
                        "&itemType=".$paymentInfo['item_type'].
                        "&item=".json_encode(json_decode($paymentInfo['item']))
                    );
                }else{
                    header("location: https://pay.cuki.ir/paystatus/?".
                        "statusCode=500".
                        "&details=item couldn't be saved as paid in restaurant".
                        "&amount=".$paymentInfo['amount'].
                        "&paymentId=".$clientrefid.
                        "&trackingId=".$paymentInfo['tracking_id'].
                        "&itemType=".$paymentInfo['item_type'].
                        "&item=".json_decode($paymentInfo['item'])
                    );
                }
            }else{
                header("location: https://pay.cuki.ir/paystatus/?".
                    "statusCode=200".
                    "&amount=".$paymentInfo['amount'].
                    "&paymentId=".$clientrefid.
                    "&trackingId=".$paymentInfo['tracking_id'].
                    "&itemType=".$paymentInfo['item_type'].
                    "&item=".json_decode($paymentInfo['item'])
                );
            }
        }else{
            header("location: https://pay.cuki.ir/paystatus/?".
                "statusCode=500".
                "&details=payment couldn't be saved on our server".
                "&amount=".$paymentInfo['amount'].
                "&paymentId=".$clientrefid.
                "&trackingId=".$paymentInfo['tracking_id'].
                "&itemType=".$paymentInfo['item_type'].
                "&item=".json_decode($paymentInfo['item'])
            );

        }
    }else{
        header("location: https://pay.cuki.ir/paystatus/?".
            "statusCode=402".
            "&details=payment is not valid or it's duplicate".
            "&amount=".$paymentInfo['amount'].
            "&paymentId=".$clientrefid.
            "&trackingId=".$paymentInfo['tracking_id'].
            "&itemType=".$paymentInfo['item_type'].
            "&item=".json_decode($paymentInfo['item'])
        );
        exit(json_encode(array('statusCode'=>402)));
    }

}


function foodPaid($paymentInfo, $connRes){
    $trackingId = $paymentInfo['tracking_id'];

    // get order info
    $orderInfo = false;
    $sql_get_orderInfo = "SELECT * FROM orders WHERE `tracking_id`='$trackingId';";
    if ($result = mysqli_query($connRes, $sql_get_orderInfo)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $orderInfo = $row;
        }
    }

    $paymentIdsArr = ($orderInfo['payment_id'] != null) ? json_decode($orderInfo['payment_id']) : array();
    array_push($paymentIdsArr, $paymentInfo['payment_id']);
    $newPaymentIdStr = json_encode($paymentIdsArr);

    $paidFoods = ($orderInfo['paid_foods'] != null) ? json_decode($orderInfo['paid_foods']) : array();
    $newPaidFoodsArr = array_merge($paidFoods, json_decode($paymentInfo['item']));
    $newPaidFoodsStr = characterFixer(json_encode($newPaidFoodsArr));

    $paidAmount = ($orderInfo['paid_amount'] != null) ? $orderInfo['paid_amount'] : 0;
    $newPaidAmount = $paidAmount + $paymentInfo['amount'];

    $sql_add_paid_to_order_table = "UPDATE orders SET `payment_id`='$newPaymentIdStr', `paid_foods`='$newPaidFoodsStr', `paid_amount`='$newPaidAmount' WHERE `tracking_id`='$trackingId' ;";
    if(mysqli_query($connRes, $sql_add_paid_to_order_table)){
        return true;
    }else{
        return false;
    }

}

function isFoodsPaid ($currentPaymentInfo, $paymentGroupKey, $trackingId, $connRes, $connOur) {
    $isItemOverPaid = false;


    $orderInfo = false;
    $sql_get_orderInfo = "SELECT * FROM orders WHERE `tracking_id`='$trackingId';";
    if ($result = mysqli_query($connRes, $sql_get_orderInfo)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $orderInfo = $row;
        }
    }

    // add current payment to payments then calculate
    // it means imagine this payment is confirmed then what would happen? would items over paid?
    $allPaidPaymentsInGroup = array($currentPaymentInfo);
    $sql_get_allPaymentsGroup = "SELECT * FROM payments WHERE `payment_group`='$paymentGroupKey' AND `verified_date`>1000 ;";
    if ($result = mysqli_query($connOur, $sql_get_allPaymentsGroup)) {
        while ($row = mysqli_fetch_assoc($result)) {
            array_push($allPaidPaymentsInGroup, $row);
        }
    }


    $orderedFoodsList = json_decode($orderInfo['order_list'], true);


    // check if number of payed food will be more than ordered ones
    foreach ($allPaidPaymentsInGroup as $ePPayment){
        $pPFoodsList = json_decode(str_replace("\\","",$ePPayment['item']), true);
        foreach ($pPFoodsList as $ePPFood){
            for($i = 0; $i < count($orderedFoodsList); $i++){
                if($orderedFoodsList[$i]["id"] == $ePPFood["id"]){
                    $orderedFoodsList[$i]['number'] = $orderedFoodsList[$i]['number'] -  $ePPFood['number'];
                }
                // check if its over paid
                if($orderedFoodsList[$i]['number'] < 0){
                    $isItemOverPaid = true;
                    break;
                }
            }
        }
    }

    return $isItemOverPaid;
}


function characterFixer($str){
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
        return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UTF-16BE');
    }, $str);
}