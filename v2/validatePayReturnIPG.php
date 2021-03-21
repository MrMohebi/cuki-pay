<?php
header('content-type: application/json; charset=utf-8');

if(isset($_POST['refid'])){
    include_once "./DataAccess/MysqldbAccess.php";
    include_once "./DataAccess/db.config.php";
    include_once "./token/tokens.php";

    $connOurs = MysqlConfig::connOurs();
    $oursAccess = new MysqldbAccess($connOurs);

    $code = mysqli_real_escape_string($connOurs, $_POST['code']);
    $refid = mysqli_real_escape_string($connOurs, $_POST['refid']);
    $clientrefid = mysqli_real_escape_string($connOurs, $_POST['clientrefid']);
    $cardnumber = mysqli_real_escape_string($connOurs, $_POST['cardnumber']);
    $cardhashpan = mysqli_real_escape_string($connOurs, $_POST['cardhashpan']);

    $paymentKey = explode("-",$clientrefid)[1];
    $resEnglishName = $oursAccess->select('english_name', "restaurants", "`payment_key`='$paymentKey' AND `position` = 'admin'");

    $connRes = MysqlConfig::connRes($resEnglishName);
    $resAccess = new MysqldbAccess($connRes);


    // get payment info
    $paymentInfo = $oursAccess->select("*", "payments", "`payment_id`='$clientrefid'");


    // check if foods r not paid
    // if its paid before, dont verify payment and give back money (it will be done after about 10 min)
    if(($paymentInfo["item_type"] == "food") && isFoodsPaid($paymentInfo, $paymentInfo["payment_group"], $paymentInfo["tracking_id"], $connRes, $connOurs)){
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



    $api_key = tokens::PAYPING_CUKI;
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
    $verifyCardHash = $result['cardHashPan'];
    $verifyAmount = $result['amount'];


    //check payment is valid and its not dublicated
    if(strlen($verifyCardHash) > 10 && ($paymentInfo["verified_date"] < 1000) && ($paymentInfo["amount"] == $verifyAmount)){
        $sqlUpdate_paymentPaidParams = array(
            'verified_date'=>time(),
            'payer_card'=>$verifyCardNumber,
            'payer_card_hash'=>$verifyCardHash,
        );
        if($oursAccess->update("payments", $sqlUpdate_paymentPaidParams,"`payment_id`='$clientrefid'" )){
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