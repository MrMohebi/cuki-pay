<?php


class MysqldbAccess{

    private $dbConn;
    function __construct($dbConn){
        $this->dbConn = $dbConn;
    }



    // to update all rows at once set condition as a true statement like: 1=1
    public function updateAppendToList ($tableName, $newKeyAppendValObject, $condition){
        $countExceptions = 0;
        $selectedData = self::select("*", $tableName, $condition);

        $selectedData = array_key_exists("0",$selectedData) ? $selectedData[0] : array($selectedData);

        // find primary key
        $specificId = null;
        foreach (array_keys($selectedData[0]) as $eFieldKey){
            $split = explode("_", $eFieldKey);
            if($split[count($split)-1] == "id"){
                $specificId = $eFieldKey;
                break;
            }
        }

        foreach ($selectedData as $eData){
            $sqlCommand = "UPDATE $tableName SET ";
            foreach ($newKeyAppendValObject as $key=>$val){
                $previousData = json_decode($eData[$key], true);
                // if its not array, convert it to an array then append to its end
                $previousData = is_array($previousData) ? $previousData : ($previousData !== null ? array($previousData) :array());
                array_push($previousData, $val);
                $newData = json_encode($previousData);
                $sqlCommand .= " `$key`='$newData', ";
            }
            $sqlCommand = rtrim($sqlCommand, ", ");
            $sqlCommand .= " WHERE `$specificId`='$eData[$specificId]' ;";
            if (!($result = mysqli_query($this->dbConn, $sqlCommand))) {
                $countExceptions +=1;
            }
        }
        return $countExceptions == 0 ? true : $countExceptions;
    }



    // to update all rows at once set condition as a true statement like: 1=1
    public function update ($tableName, $newKeyValObject, $condition){
        $sqlCommand = "UPDATE $tableName SET ";
        foreach ($newKeyValObject as $key=>$val){
            $sqlCommand .= " `$key`='$val', ";
        }
        $sqlCommand = rtrim($sqlCommand, ", ");

        $sqlCommand .= " WHERE $condition ;";

        if ($result = mysqli_query($this->dbConn, $sqlCommand)) {
            return true;
        }else{
            return false;
        }
    }


    public function hasTokenAccess ($token, $tableName, $accessiblePosition){
        $sqlCommand = "SELECT * FROM `$tableName` WHERE `token`='$token';";
        $position = "notAssigned";
        if ($result = mysqli_query($this->dbConn, $sqlCommand)) {
            while ($row = mysqli_fetch_assoc($result)) {
                $position = $row["position"];
            }
        }else{
            return false;
        }
        return in_array($position, $accessiblePosition);
    }

    public function isTokenValid ($token, $tableName){
        $sqlCommand = "SELECT * FROM `$tableName` WHERE `token`='$token';";
        $isValid = false;
        if ($result = mysqli_query($this->dbConn, $sqlCommand)) {
            while ($row = mysqli_fetch_assoc($result)) {
                $isValid = true;
            }
        }else{
            return false;
        }
        return $isValid;
    }


    public function noDuplicate($filedArr, $tableName){
        $sqlCommand = "SELECT * FROM `$tableName` WHERE ";
        foreach ($filedArr as $key=>$value){
            $sqlCommand .= " `$key`='$value' OR";
        }
        $sqlCommand = substr($sqlCommand,0, -2);
        $sqlCommand .= ";";

        $duplicate = false;
        if ($result = mysqli_query($this->dbConn, $sqlCommand)) {
            while ($row = mysqli_fetch_assoc($result)) {
                $duplicate = true;
            }
        }else{
            return null;
        }
        return $duplicate;
    }

    public function select($selector, $tableName, $condition = false , $orderedBy = false ){
        $sqlCommand = "SELECT $selector FROM `$tableName` ";
        if($condition)
            $sqlCommand .= " WHERE $condition ";
        if ($orderedBy)
            $sqlCommand .= " ORDER BY $orderedBy ";
        $sqlCommand .= ";";

        $queryResult = array();
        if ($result = mysqli_query($this->dbConn, $sqlCommand)) {
            while ($row = mysqli_fetch_assoc($result)) {
                array_push($queryResult, $row);
            }
        }else{
            return false;
        }
        if(count($queryResult) == 1 && count($queryResult[0]) == 1){
            return $queryResult[0][$selector];
        }elseif (count($queryResult) == 1 && count($queryResult[0]) > 1){
            return $queryResult[0];
        }
        return count($queryResult) > 0 ? $queryResult :  false ;
    }

    public function insert($tableName, $keyValObject){
        if(!(count($keyValObject) > 0))
            return false;

        $sqlCommand = "INSERT INTO `$tableName`  ";
        $keys = "(";
        $values = " VALUES ( ";
        foreach ($keyValObject as $key=>$val){
            $keys .= "`$key`,";
            $values .= "'$val',";
        }
        $keys =  rtrim($keys, ", ") . ") ";
        $values = rtrim($values, ", ") . ") ;";

        $sqlCommand .= $keys . $values;

        return mysqli_query($this->dbConn, $sqlCommand) ? true: false;
    }
}




