<?php
$nowTimestamp = time();

$serveraddr = "127.0.0.1";
$dbuserrname = "cukiir_mrmohebi";
$dbpass = "0n0g87^a#q0JUf8^kuzeUl%Bt#hF";

$dbname_ours = 'cukiir_ours';


$conn_database_ours = mysqli_connect($serveraddr, $dbuserrname, $dbpass, $dbname_ours);


// get restaurants databases names
$dbs = array() ;  // $dbs = {"english_name1" : database_connection1, "english_name2" : database_connection2}

$sql_create_dbConnections = "SELECT * FROM restaurants WHERE `position` = 'admin';";
if ($result = mysqli_query($conn_database_ours, $sql_create_dbConnections)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $dbs[$row['english_name']] = mysqli_connect($serveraddr, $dbuserrname, $dbpass, $row['db_name']);
    }
}