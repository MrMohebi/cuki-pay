<?php


class MysqlConfig {
    protected static $serveraddr = "127.0.0.1";
    protected static $dbuserrname = "cukiir_mrmohebi";
    protected static $dbpass = "0n0g87^a#q0JUf8^kuzeUl%Bt#hF";

    const dbname_ours = 'cukiir_ours';


    public static function connOurs(){
        return mysqli_connect(MysqlConfig::$serveraddr, MysqlConfig::$dbuserrname, MysqlConfig::$dbpass, self::dbname_ours);
    }

    public static function connRes($englishName){
        $resDb = array() ;
        $sql_create_resConn = "SELECT * FROM restaurants WHERE `position` = 'admin' AND `english_name`='$englishName';";
        if ($result = mysqli_query(MysqlConfig::connOurs(), $sql_create_resConn)) {
            while ($row = mysqli_fetch_assoc($result)) {
                $resDb = mysqli_connect(MysqlConfig::$serveraddr, MysqlConfig::$dbuserrname, MysqlConfig::$dbpass, $row['db_name']);
            }
        }
        return $resDb;
    }

    public static function createConn($dbName){
        $connDB = mysqli_connect(MysqlConfig::$serveraddr, MysqlConfig::$dbuserrname, MysqlConfig::$dbpass, $dbName);
        return $connDB ? $connDB : false ;
    }



}