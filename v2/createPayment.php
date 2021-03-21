<?php
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Origin: *");
header('content-type: application/json; charset=utf-8');

if(isset($_POST['englishName']) && isset($_POST['token'])){
    include_once "./DataAccess/MysqldbAccess.php";
    include_once "./DataAccess/db.config.php";
    include_once "./token/tokens.php";

    $connOurs = MysqlConfig::connOurs();
    $oursAccess = new MysqldbAccess($connOurs);


    $token =  mysqli_real_escape_string($connOurs, $_POST['token']);
    $resEnglishName =  mysqli_real_escape_string($connOurs, $_POST['englishName']);
    $trackingId =  mysqli_real_escape_string($connOurs, $_POST['trackingId']);
    $amount =  mysqli_real_escape_string($connOurs, $_POST['amount']);
    $itemType = mysqli_real_escape_string($connOurs, $_POST['itemType']);
    $items = str_replace("\\","",mysqli_real_escape_string($connOurs, $_POST['items']));

    $connRes = MysqlConfig::connRes($resEnglishName);
    $resAccess = new MysqldbAccess($connRes);

    // check user is valid and get it's phone and name
    $userInfo = $oursAccess->select("*", "ours_customers", "`token`='$token'");
    $costumer_phone = $userInfo['phone'];
    $costumer_name = $userInfo['name'];
    if(strlen($costumer_phone) != 11)
        exit(json_encode(array('statusCode' => 401, "details" => "user is not valid")));

    // get restaurant payment key
    $paymentKeyRes = $oursAccess->select("payment_key", "restaurants", "`english_name`='$resEnglishName'  AND `position` = 'admin'");
    // check restaurant is correct
    if (strlen($paymentKeyRes) < 2)
        exit(json_encode(array('statusCode' => 400, "details" => "restaurant wasn't found")));


    $paymentIdType = "x";
    if($itemType == "food"){
        $paymentIdType = "f";
        $items_array = json_decode($items,true); // [{id: 6, number: 2}, {id: 42, number: 6}, ....]
        $foodsFullInfo = getFoodInfo($connRes,$items_array);
        $foodsFullInfo_jsonStr = json_encode($foodsFullInfo);
        $items = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UTF-16BE');
        }, $foodsFullInfo_jsonStr);
    }



    $previousPaidInfo = getPreviousPaidInfo($trackingId, $connOurs, $connRes);
    if($previousPaidInfo['wasPaidTotal'])
        exit(json_encode(array('statusCode'=>600, "details" => "all ware paid")));

    $paymentLastNum = $previousPaidInfo['paymentLastNum'];

    $paymentNum = (($paymentLastNum > 0) ? ($paymentLastNum+1) : 1 );
    $paymentBaseId = (strlen($previousPaidInfo['paymentBaseId']) > 5) ? $previousPaidInfo['paymentBaseId'] : ("cuki".$paymentIdType."-".$paymentKeyRes ."-".generateRandomString(4));

    $paymentId = $paymentBaseId ."-". $paymentNum;

    // for test:
    $amount = 1000;
    $api_key = tokens::PAYPING_TEST;

    // real try:
//    $api_key = tokens::PAYPING_CUKI;

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

    $sqlInsert_createPaymentParams = array(
        'tracking_id'=>$trackingId,
        'payment_id'=>$paymentId,
        'payment_group'=>$paymentBaseId,
        'item_type'=>$itemType,
        'item'=>$items,
        'payment_num'=>$paymentNum,
        'payer_phone'=>$costumer_phone,
        'payer_name'=>$costumer_name,
        'amount'=>$amount,
        'status'=>'0',
        'create_date'=>time(),
        'modified_date'=>time(),
    );


    if($oursAccess->insert("payments", $sqlInsert_createPaymentParams)){
        if(strlen($payPingCode) > 2){
            if($oursAccess->update('payments',array('payping_code'=>$payPingCode), "`payment_id` = '$paymentId'")){
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
            exit(json_encode(array('statusCode'=>408, "details" => "something went wrong during getting payment link")));
        }
    }else{
        exit(json_encode(array('statusCode'=>500, "details" => "something went wrong during saving payment in our database")));
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







