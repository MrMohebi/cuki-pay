<?php
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Origin: *");
header('content-type: application/json; charset=utf-8');

if(isset($_POST['englishName']) && isset($_POST['token'])){
    include_once 'db/db.config.php';
    include_once "token/token.php";

    $conn_database_restaurant = $dbs[$_POST['englishName']];

    $token =  mysqli_real_escape_string($conn_database_ours, $_POST['token']);
    $englishName =  mysqli_real_escape_string($conn_database_ours, $_POST['englishName']);
    $trackingId =  mysqli_real_escape_string($conn_database_ours, $_POST['trackingId']);
    $amount =  mysqli_real_escape_string($conn_database_ours, $_POST['amount']);
    $itemType = mysqli_real_escape_string($conn_database_ours, $_POST['itemType']);
    $items = str_replace("\\","",mysqli_real_escape_string($conn_database_ours, $_POST['items']));

    $paymentIdType = "x";
    if($itemType == "food"){
        $paymentIdType = "f";
        $items_array = json_decode($items,true); // [{id: 6, number: 2}, {id: 42, number: 6}, ....]
        $foodsFullInfo = getFoodInfo($conn_database_restaurant,$items_array);
        $foodsFullInfo_jsonStr = json_encode($foodsFullInfo);
        $items = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UTF-16BE');
        }, $foodsFullInfo_jsonStr);
    }

    // get restaurant payment key
    $paymentKeyRes = "";
    $sql_get_payment_key_res= "SELECT * FROM restaurants WHERE `english_name`= '$englishName' AND `position` = 'admin';";
    if ($result = mysqli_query($conn_database_ours, $sql_get_payment_key_res)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $paymentKeyRes = $row['payment_key'];
        }
    }

    // check user is valid and get it's phone and name
    $costumer_phone = false;
    $costumer_name = false;
    $sql_get_customer_phone = "SELECT * FROM ours_customers WHERE `token`='$token';";
    if ($result = mysqli_query($conn_database_ours, $sql_get_customer_phone)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $costumer_phone = $row['phone'];
            $costumer_name = $row['name'];
        }
    }

    if(strlen($costumer_phone) == 11 && strlen($paymentKeyRes) > 1){

        $previousPaidInfo = getPreviousPaidInfo($trackingId, $conn_database_ours,$conn_database_restaurant);
        if($previousPaidInfo['wasPaidTotal'])
            exit(json_encode(array('statusCode'=>600)));

        $paymentLastNum = $previousPaidInfo['paymentLastNum'];

        $paymentNum = (($paymentLastNum > 0) ? ($paymentLastNum+1) : 1 );
        $paymentBaseId = (strlen($previousPaidInfo['paymentBaseId']) > 5) ? $previousPaidInfo['paymentBaseId'] : ("cuki".$paymentIdType."-".$paymentKeyRes ."-".generateRandomString(4));

        $paymentId = $paymentBaseId ."-". $paymentNum;


        $api_key = $PAYPINGTOKEN;


        $info_params = array(
            "amount" => $amount,
            "payerIdentity" => $costumer_phone,
            "payerName" => $costumer_name,
            "description"=>"",
            "clientRefId"=>$paymentId,
            "returnUrl" => 'https://pay.cuki.ir/returnipg.php',
        );


        $requestHandler = curl_init();
        curl_setopt($requestHandler, CURLOPT_URL, 'https://api.payping.ir/v2/pay');
        curl_setopt($requestHandler, CURLOPT_POSTFIELDS, json_encode($info_params));
        curl_setopt($requestHandler, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($requestHandler, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: bearer $api_key",
        ));

        $result = json_decode(curl_exec($requestHandler), true);
        curl_close($requestHandler);
        $payPingCode = $result['code'];

        $sql_create_payment = "INSERT INTO payments
                                              (`tracking_id`, `payment_id`, `payment_group`, `item_type`,  `item`,  `payment_num`, `payer_phone`, `payer_name`,  `amount`, `status`, `create_date`, `modified_date`) 
                                        VALUES('$trackingId', '$paymentId','$paymentBaseId', '$itemType' , '$items' , '$paymentNum','$costumer_phone', '$costumer_name',  '$amount', '0', '$nowTimestamp', '$nowTimestamp') ";

        if(mysqli_query($conn_database_ours, $sql_create_payment)){
            $sql_save_payPing_code = "UPDATE payments SET `payping_code`= '$payPingCode' WHERE `payment_id` = '$paymentId';";
            if(strlen($payPingCode) > 2){
                if(mysqli_query($conn_database_ours, $sql_save_payPing_code) ){
                    exit(json_encode(array(
                        'statusCode'=>200,
                        "data"=>array(
                            "url"=>"https://api.payping.ir/v2/pay/gotoipg/".$payPingCode,
                            "amount"=>$amount,
                            "paymentId"=>$paymentId,
                            "totalPaid"=>$previousPaidInfo['paidSum'],
                            "totalPrice"=>$previousPaidInfo['totalPrice'],
                        ))));
                }else{
                    exit(json_encode(array('statusCode'=>500)));
                }
            }else{
                exit(json_encode(array('statusCode'=>408)));
            }


        }else{
            exit(json_encode(array('statusCode'=>500)));
        }



    }else{
        exit(json_encode(array('statusCode'=>401)));
    }


}else{
    exit(json_encode(array('statusCode'=>400)));
}



function getPreviousPaidInfo($trackingId, $connOurs, $connRes){
    $payments = array();
    $paymentsNum = array();
    $paidSum = 0;
    $paymentBaseId = "";
    $sql_get_payment_key_res= "SELECT * FROM payments WHERE `tracking_id`= '$trackingId';";
    if ($result = mysqli_query($connOurs, $sql_get_payment_key_res)) {
        while ($row = mysqli_fetch_assoc($result)) {
            array_push($payments, $row);
            array_push($paymentsNum, $row["payment_num"]);
            $paymentBaseId = $row["payment_group"];
            if($row['verified_date'] > 1000){
                $paidSum += $row['amount'];
            }
        }
    }

    // get order info
    $orderInfo = array();
    $sql_get_order_info= "SELECT * FROM orders WHERE `tracking_id`= '$trackingId';";
    if ($result = mysqli_query($connRes, $sql_get_order_info)) {
        while ($row = mysqli_fetch_assoc($result)) {
            $orderInfo = $row;
        }
    }

    // check if order was paid dont open new payment
    $wasPaidTotal = false;
    if($orderInfo['total_price'] <= $paidSum)
        $wasPaidTotal = true;


    rsort($paymentsNum);
    return array(
        "wasPaidTotal"=>$wasPaidTotal,
        "paidSum"=>$paidSum,
        "totalPrice"=>$orderInfo['total_price'],
        'paymentBaseId'=>$paymentBaseId,
        'paymentLastNum'=>$paymentsNum[0]
    );
}



function getFoodInfo($conn_database_restaurant, $foods_list){
    $orderedFood = array();

    $all_foods = array();
    $sql_get_foods = "SELECT * FROM foods;";
    if ($result = mysqli_query($conn_database_restaurant, $sql_get_foods)) {
        while ($row = mysqli_fetch_assoc($result)) {
            array_push($all_foods, $row);
        }
    }

    foreach ($foods_list as $eachOrderedFood){
        foreach ($all_foods as $eachFood){
            if ($eachOrderedFood['id'] == $eachFood['foods_id']) {
                $priceAfterDiscount = $eachFood['price'] * ((100 - $eachFood['discount'])/100);
                $eachOrderedFood_newArray = array(
                    'id'=>$eachOrderedFood['id'],
                    'name'=>$eachFood['name'],
                    'number'=>$eachOrderedFood['number'],
                    'price'=>$eachFood['price'],
                    'discount'=>$eachFood['discount'],
                    'priceAfterDiscount'=>$priceAfterDiscount
                );
                array_push($orderedFood, $eachOrderedFood_newArray);
            }
        }
    }
    return $orderedFood;
}





function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}







