<?php
namespace ka\core\db;

/*
The MIT License (MIT)

Copyright (c) 2013 Wes Lanning

Permission is hereby granted, free of charge, to any person obtaining a copy
 of this software and associated documentation files (the "Software"), to deal
 in the Software without restriction, including without limitation the rights
 to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 copies of the Software, and to permit persons to whom the Software is
 furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
 all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 THE SOFTWARE.
*/

/**
 * Wrapper class for MySQLi. Requires PHP 5.3+
 *
 * @TODO : rewrite the example below to reflect making this a singleton.
 *
 * @example
 *
      $dbstr['db'] = '';
      $dbstr['user'] = '';
      $dbstr['pass'] = '';
      $db = new db('localhost',$dbstr['user'], $dbstr['pass'], $dbstr['db'], true);

      $sql = 'SELECT id, some_value FROM some_table AS a WHERE id = ?, latitude = ?, name = ?, blob = ? LIMIT 3';
      // store the info for the prepared statement
      $params = array(
                     'type'=>'idsb',
                     'id'=>5,
                     'latitude'=>50.434,
                     'name'=>'McLovin',
                     'blob'=>'02FC 03CC 4200'
                );
      $result = $db->fetchData($sql, $params);

      if($result === true){
          foreach($db->results as $result){
              echo $result['id'];
              echo $result['some_value'];
              print_r($result); //var dump to show the values above in an array instead
          }
      }
      else{
          echo "No results...";
      }

      $db = MySQL::init(); // now as a singleton
      $db->whatever();
 *
 */

class MySQL extends \mysqli implements MySQL_Config
{
    protected $dbInfo       = array(); // holds current db info
    public $rowsAffected;   // how many rows were affected by the db transaction
    public $results         = array(); // holds returned data from the db
    public $errorMsg;       // error messages
    public $resultParams    = array(); // in case you want the names of the fields from the db given back
    public $debug;          // show error messages

    private static $instance; // Hold an instance of the class

    /**
     * The singleton creator for the class.
     * Call to this method to create and initialize
     * the class instance. Number of args is inferred and passed to the proper private constructor
     *
     * @param array args - contains all the arguments to initalize the object instance
     * @return object instance
     *
     */
    public static function initDB(/*array $dbInfo, $host='localhost', $user, $pass, $dbName, $debug=false*/)
    {
        if (empty(self::$instance)) {
            self::$instance = new self(func_get_args());
        }
        return self::$instance;
    }

    /**
     * Calls to the secondary constructor that matches the number
     * of parameters passed. (i.e. __construct2() if this main
     * construtor had 2 paramters passed to it from the singleton
     * creator method)
     *
     * @param array args - array containing all the arguments of the
     * constructor method
     * @return object instance
     *
     */
    private function __construct()
    {
        parent::init();
        if (method_exists($this, $method='__construct'.func_num_args() ) ) {
            call_user_func_array(array($this, $method), func_get_args());
        }
    }

    /**
     * initialize connection to mysqli db
     *
     * @param str $host
     * @param str $user
     * @param str $pass
     * @param str $db - which db to use
     * @param bool $debug - should we show messages
     * @return object db
     *
     */
    private function __construct5($host='localhost', $user, $pass, $dbName, $debug)
    {
        $this->setConfig($host, $user, $pass, $dbName)->initialize($debug);
    }

    /**
     * initialize connection to mysqli db
     *
     * @param array $dbInfo - the db parameters (host, user, pass, dbName)
     * @param bool $debug - should we show messages
     * @return object db
     *
     */
    private function __construct2(array $dbInfo, $debug)
    {
        $this->dbInfo = $dbInfo;
        $this->setConfig()->initialize($debug);
    }

    /**
     * initialize connection to mysqli db
     * uses the default configuration set in mysql config
     * or the global site config.
     *
     * @param bool $debug - should we show messages
     * @return object db
     *
     */
    private function __construct1($debug)
    {
        $this->setConfig()->initialize($debug);
    }

    protected function setConfig($host='', $user='', $pass='', $dbName='')
    {
        $this->dbInfo['host']   = !empty($host)     ? $host     : self::HOST;
        $this->dbInfo['user']   = !empty($user)     ? $user     : self::USER;
        $this->dbInfo['pass']   = !empty($pass)     ? $pass     : self::PASS;
        $this->dbInfo['dbName'] = !empty($dbName)   ? $dbName   : self::DB_NAME;
        return $this;
    }

    protected function initialize($debug)
    {
        $this->debug = $debug;

        try {
            if(!parent::options(MYSQLI_INIT_COMMAND, 'SET AUTOCOMMIT = 1')) {
                throw new \Exception("MySQL error initially setting AUTOCOMMIT = 1<br><br>");
            }

            if(!parent::options(MYSQLI_OPT_CONNECT_TIMEOUT, 5)) {
                throw new \Exception("MySQL error initially setting CONNECT_TIMEOUT = 5<br><br>");
            }

            if(!parent::real_connect($this->dbInfo['host'], $this->dbInfo['user'], $this->dbInfo['pass'], $this->dbInfo['dbName'])) {
                throw new \Exception("MySQL database connect error.<br><br>");
            }
        }
        catch (\Exception $e) {
            if($this->debug === true){
                echo 'Setting MYSQLI_INIT_COMMAND failed<br><br>';
                print_r($e);
            }
        }
        return $this;
    }

    /**
     * turns autocommit on or off for the
     * database. i.e. BEGIN...
     *
     * @param bool $commitStatus
     */
    public function autoCommit($commitStatus)
    {
        if($commitStatus === false)
            parent::autocommit(false);
        else
            parent::autocommit(true);

        return $this;
    }

    /**
     * are we currently doing a transaction?
     * @return bool $status - true if using autocommit
     */
    public function isAutoCommitOn()
    {
        $result = parent::query('SELECT @@autocommit');
        $status = $result->fetch_array(MYSQLI_NUM);
        return $status[0];
    }

    /**
     * prepares a SQL statement and executes it for either
     * UPDATE or INSERT.
     *
     * @param str $query - sql query
     * @param array $params - parameters to pass in and bind
     * @return bool - result of db call
     *
     * PRESERVES: $query, $params
     * CREATES: $this->rowsAffected, $this->errorMsg
     * ALTERS:
     *
     * NOTE: parent is assumed to be mysqli itself
     *
     * usage:
     * $query = "INSERT INTO table id, username VALUES(? , ?)";
     * $params = array('is', $user, $name); - where 'is' means i for int and s
     * for str (d for double or b for blob) in the next indexes
     *
     * $result = $this->autoInsertData($query, $params);
     *
     */
    public function insertData($query, array $params)
    {
        $stmt = "";

        try {
            if(!$stmt = parent::prepare($query)){
                throw new \Exception("MySQL error creating prepared statement. <br> Query:<br> $query");
            }

            $ret = call_user_func_array (array($stmt,'bind_param'), $this->refValues($params));

            if(!$stmt->execute()){
                 throw new \Exception("MySQL error executing prepared statement. <br> Query:<br> $query");
            }

            $this->rowsAffected = $stmt->affected_rows;
            $stmt->close();
         //   return true;
        }
        catch(\Exception $e ) {
            if($this->debug === true){
                echo "Error No: " . $e->getCode() . " - ". $e->getMessage() . "<br ><br>";
                echo nl2br( $e->getTraceAsString() );
            }
            $this->errorMsg = "Error 00: MySQL Initalization Failure :(";
        }
       // return false; // errors >:o
       return $this;
    }

    /**
     * prepares a SQL SELECT statement and executes it
     * and returns all results as an associative array
     *
     * @param str $query - sql query
     * @param array $params - parameters to pass in
     * and bind to the SELECT condition
     * @return bool - result of db call
     *
     * PRESERVES: $query, $params
     * CREATES: $this->results, $this->resultParams, $this->errorMsg
     * ALTERS:
     *
     * NOTE: parent is assumed to be mysqli itself
     *
     * usage:
     * $query = "SELECT id, username FROM some_table WHERE id = ?";
     * $params = array('is', $user, $name); - where 'is' means i for
     * int and s for str (d for double or b for blob) in the next indexes
     *
     * $result = $this->fetchData($query, $params);
     *
     */
    public function fetchData($query, array $params)
    {
        $stmt = "";

        try {
            if(!$stmt = parent::prepare($query)){
                throw new \Exception("MySQL error creating prepared statement. <br> Query:<br> $query");
            }

            $ret = call_user_func_array (array($stmt,'bind_param'), $this->refValues($params));

            if(!$stmt->execute()){
                 throw new \Exception("MySQL error executing prepared statement. <br> Query:<br> $query");
            }

            $parameters = array();
            $meta = $stmt->result_metadata();
            $this->rowsAffected = $stmt->affected_rows;

            while ( $field = $meta->fetch_field() ) {
                $this->resultParams[] = &$row[$field->name];
            }

            call_user_func_array(array($stmt, 'bind_result'), $this->resultParams);

            while ( $stmt->fetch() ) {
                $x = array();

                foreach( $row as $key => $val ) {
                    $x[$key] = $val;
                }
                $this->results[] = $x;
            }

            $stmt->close();
            //return true;
        }
        catch(\Exception $e ) {
            if($this->debug === true){
                echo "stmt = ";
                print_r ($stmt);
                echo "Error No: " . $e->getCode() . " - ". $e->getMessage() . "<br ><br>";
                echo nl2br( $e->getTraceAsString() );
            }
            $this->errorMsg = "Error 02: Prepared Statement Error";
        }
       // return false; // errors >:o
       return $this;
    }

    /**
     * Array values have to be passed by reference in bind clauses
     * for prepared statments in php > 5.3
     *
     * @param mixed $array params - holds the prepared statement parameters
     * @return mixed $array params
     */
    protected function refValues(array $array){
        if (strnatcmp(phpversion(), '5.3') >= 0){ //Reference is required for PHP 5.3+
            $refs = array();
            foreach($array as $key => $value)
                $refs[$key] = &$array[$key];
            return $refs;
        }
        return $array;
    }


    /**
     * turn on mysql SSL encryption
     *
     * @param str $serverKey
     * @param str $domainkey
     * @param str $caCert
     * @return bool - retult of turning on ssl
     */
    public function useSSL($serverKey='/etc/apache2/ssl-keys/server.key', $domainkey='/etc/apache2/ssl-keys/domain.crt', $caCert='/etc/apache2/ssl-keys/cabundle.crt')
    {
        try{
            if (strnatcmp(phpversion(), '5.3.3') >= 0){ // assuming you are using mysqlnd with php 5.3.3 or greater
                throw new \Exception("SSL cannot be used with mysql native driver before PHP 5.3.3<br><br>");
            }

            if(!$result = parent::ssl_set($serverKey, $domainkey, $caCert, NULL, NULL)){
                throw new \Exception("Could not enabled SSL encryption on mysql!<br><br>");
            }
        }
        catch(\Exception $e ) {
            if($this->debug === true){
                echo 'Server Key: '. $serverKey . '<br>', 'Domain Key:' . $domainkey . '<br>' . 'Ca Cert: ' . $caCert . '<br><br>';
                echo "Error No: " . $e->getCode() . " - ". $e->getMessage() . "<br ><br>";
                echo nl2br( $e->getTraceAsString() );
            }
            $this->errorMsg = "Error 03: MySQL SSL enable failure >:(";
            exit();
        }
       // return $result;
       return $this;
    }

    // Prevent users to clone the instance
    public function __clone()
    {
        trigger_error('Clone is not allowed.', E_USER_ERROR);
    }

    /**
     * kills the db connection
     *
     */
    public function __destruct()
    {
        parent::close();
    }
}
