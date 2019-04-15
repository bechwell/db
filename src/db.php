<?php 
namespace BW;
include_once "dbArray.php";


class dbkernel{
    static $listdb = [];
    static $pdo = null;
    static $name = "";
    static $events = [];
    static function on($event,$callback){
        self::$events[] = ["name" => $event,"callback"=>$callback];
    }
    static function emit($event,$data = ""){
        foreach (self::$events as $key => $value) {
            if ($value["name"] == $event) {
                $callback = $value["callback"];
                $callback($data);
            }
        }
    }
    static function fire($event,&$data = ""){
        return self::emit($event,$data);
    }
    static function log($txt){
        if(is_array($txt) || is_a($txt,"dbArray")) $txt = print_r($txt,true);
        self::emit("log",$txt);
    }
    static function add($name,$host,$dbname,$user="root",$pass="",$port = null){
        self::$listdb[$name] = [
            "name"   => $name,
            "host"   => $host,
            "dbname" => $dbname,
            "user"   => $user,
            "pass"   => $pass,
            "port"   => $port,
        ];
    }
    static function in($name = "",$callback = null){
        if($name == ""){
            if(!is_null(self::$pdo)) return;
            $list = array_keys(self::$listdb);
            if(!empty($list)){
                self::in($list[0],$callback);
            }
            return;
        }
        if (is_callable($callback)) {
            $name1 = self::$name;
            db::in($name);
            $callback();
            if(!empty($name1))
            db::in($name1);
            return;
        }
        if (isset(self::$listdb[$name])) {
            if (!isset(self::$listdb[$name]["pdo"])) {
                $data = self::$listdb[$name];
                $db = null;
                try {
                    $cnx = "mysql:host=".$data["host"].";"
                            .(empty($data["port"])?"":"port=".$data["port"].";")
                            ."dbname=".$data["dbname"];
                    $db = new \PDO($cnx ,$data["user"],$data["pass"]);
                    $db->query("SET NAMES utf8");
                    $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
                    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                } catch (\Exception $e) {
                    $db = null;
                    echo "Erreur connection : $name : ".$e->getMessage();
                    return false;
                }
                self::$listdb[$name]["pdo"] = $db;
            }
            self::$name = $name;
            self::$pdo = self::$listdb[$name]["pdo"];
            db::log("Connecté");
        }
    }
    static $listMap = [];
    static function addMap($key,$value,$dbname = "*"){
        self::$listMap[] = [
            "key" => $key,
            "value" => $value,
            "dbname" => $dbname
        ];
    }
    static function decodeMap($sql){
        foreach (self::$listMap as $row) {
            if ($row["dbname"] == "*" || $row["dbname"] == self::$name) {
                $sql = str_replace($row["key"],$row["value"],$sql);
            }
        }
        return $sql;
    }
    static function query($sql,$data = []){
        self::in();
        $sql = self::decodeMap($sql);
        db::log("db::query $sql");
        try {
            if (!is_null($data) && is_array($data) && !empty($data)) {
                $list = [];
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    if (is_string($key) && !preg_match("/^\:/i",$key)) {
                        $list[":$key"] = $value;
                    }else{
                        $list[$key] = $value;
                    }
                }
                $q = self::$pdo->prepare($sql);
                $q->execute($list); 
                return $q;
            }
            return self::$pdo->query($sql);
        } catch (Exception $e) {
            db::log("db::query Error : ".$e->getMessage());
        }   
        return false;
    }
    static function id(){
        return self::$pdo->lastinsertId();
    }
}

class dbPDE{
    var $props = [];
    var $data = [];
    var $events = [];
    function __set($name,$value){
        $this->props[$name] = $value;
    }
    function __get($name){
        if (array_key_exists($name, $this->props)) {
            return $this->props[$name];
        }
        return null;
    }
    function get($name,$i = 0,$default = null){
        if (array_key_exists($name, $this->data)) {
            if($i>=0){
                if ($i < count($this->data[$name])) {
                    return $this->data[$name][$i];
                }
            }else{
                return $this->data[$name];
            }
            return $default;
        }
        return $default;
    } 
    function __call($name,$args){
        $this->data[$name] = $args;
        return $this;
    }
    function on($event,$callback){
        if(!array_key_exists($event, $this->events)){
            $this->events[$event] = [];
        }
        $this->events[$event][] = [
            "event" => $event,
            "callback" => $callback
        ];
        return $this;
    }
    function emit($event,$data = []){
        if(array_key_exists($event, $this->events)){
            foreach ($this->events[$event] as $key => $value) {
                $cb = $value["callback"];
                $cb = $cb->bindTo($this);
                $cb($data);
            }
        }
        return $this;
    }
    function fire($event,$data){
        return $this->emit($event,$data);
    }
}

class dbTable extends dbPDE{
    var $name = "";
    var $engine = "MyISAM";
    var $charset = "utf8";
    var $cols = [];
    function __construct($name){
        $this->cols = new dbArray();
        $this->name = $name;
        $this->on("log",function($txt){
            db::log($txt);
        });
    }
    function __call($name,$args){
        switch (strtolower($name)) {
            case 'id':
                return $this->int("id")->pri()->ai();
            break;
            case "int":
            case "float":
            case "double":
                $namecol = $args[0];
                if (!$this->cols->has($namecol)) {
                    $this->cols[$namecol] = new dbTableCol($this,$namecol,$name);
                }
                $this->cols[$namecol]->on("load",function(&$row) use ($namecol){
                    $row[$namecol] = floatval($row[$namecol]);
                });
                return $this->cols[$namecol];
            break;
            case "bool":
            case "date":
            case "text":
            case "string":
            case "longtext":
                $namecol = $args[0];
                if (!$this->cols->has($namecol)) {
                    $this->cols[$namecol] = new dbTableCol($this,$namecol,$name);
                }
                return $this->cols[$namecol];
            break;
            case "json":
            case "longjson":
            case "long_json":
                $namecol = $args[0];
                if (!$this->cols->has($namecol)) {
                    $this->cols[$namecol] = new dbTableCol($this,$namecol,$name);
                }
                $this->cols[$namecol]->on("load",function(&$row) use ($namecol){
                    $row[$namecol] = new dbArray(json_decode($row[$namecol] , true));
                });
                return $this->cols[$namecol];
            break;
        }
        return parent::__call($name, $args);
    }
    function getLastInsertId($data){
        $col = $this->cols->find(function($col){ return $col->ai; });
        if($col){
            if (isset($data[$col->name]) && is_numeric($data[$col->name])) {
                return (int)$data[$col->name];
            }else{
                db::id();
            }
        }
        return null;
    }

    function save($data){
        $args = [];
        $cols = [];
        $upda = [];
        foreach ($data as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":$key"] = $value;
                $cols[] = "`$key` = :$key";
                $upda[] = "`$key` = value(`$key`)";
            }
        }
        if (!empty($cols)) {
            $cols = join(", ",$cols);
            $upda = join(", ",$upda);
            db::query("INSERT INTO `$this->name` SET $cols ON DUPLICATE KEY UPDATE $upda",$args);
            return $this->getLastInsertId($data);
        }
        return false;
    }
    function insert($data){
        $args = [];
        $cols = [];
        foreach ($data as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":$key"] = $value;
                $cols[] = "`$key` = :$key";
            }
        }
        if (!empty($cols)) {
            $cols = join(", ",$cols);
            db::query("INSERT INTO `$this->name` SET $cols",$args);
            return $this->getLastInsertId($data);
        }
        return false;
    }
    function add($data){
        return $this->insert($data);
    }
    function update($filter,$data){
        $args = [];
        $cols = [];
        $filt = ["1"];
        foreach ($data as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":$key"] = $value;
                $cols[] = "`$key` = :$key";
            }
        }
        foreach ($filter as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":filtvalue$key"] = $value;
                $filt[] = "`$key` = :filtvalue$key";
            }
        }
        if (!empty($cols)) {
            $cols = join(", ",$cols);
            $filt = join(" and ",$filt);
            db::query("UPDATE `$this->name` SET $cols where $filt",$args);
        }
    }
    function delete($filter){
        $args = [];
        $filt = ["1"];
        foreach ($filter as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":filtvalue$key"] = $value;
                $filt[] = "`$key` = :filtvalue$key";
            }
        }
        if (!empty($filt)) {
            $filt = join(" and ",$filt);
            db::query("DELETE FROM `$this->name` where $filt",$args);
        }
    }
    function loadRow($row){
        $this->cols->forEach(function($col) use ($row){
            $col->emit("load",$row);
        });
        return $row;
    }
    function select($filter,$suffix = ""){
        $args = [];
        $filt = ["1"];
        foreach ($filter as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":filtvalue$key"] = $value;
                $filt[] = "`$key` = :filtvalue$key";
            }
        }
        if (!empty($filt)) {
            $filt = join(" and ",$filt);
            return db::select("SELECT * FROM `$this->name` where $filt $suffix",$args)->map($this->loadRow);
        }
        return new dbArray();
    }
    function row($filter,$suffix = ""){
        $args = [];
        $filt = ["1"];
        foreach ($filter as $key => $value) {
            if ($this->cols->has($key)) {
                $args[":filtvalue$key"] = $value;
                $filt[] = "`$key` = :filtvalue$key";
            }
        }
        if (!empty($filt)) {
            $filt = join(" and ",$filt);
            if($row = db::row("SELECT * FROM `$this->name` where $filt $suffix",$args)){
                return $this->loadRow($row);
            }
        }
        return null;
    }
    function get($name,$value = null,$suffix = ""){
        if (is_null($value)) {
            if (!empty($this->primaryCols)) {
                $colname = array_keys($this->primaryCols)[0];
                return $this->get($colname,$name,$suffix);
            }
            return null;
        }
        return $this->row([$name => $value],$suffix);
    }


    var $primaryCols = [];
    function addPrimary($col){
        $this->primaryCols[$col->name] = $col;
        return $this;
    }
    var $indexCols = [];
    function addIndex($col,$name){
        if(empty($this->indexCols[$name])) $this->indexCols[$name] = [];
        $this->indexCols[$name][$col->name] = $col;
        return $this;
    }
    var $uniqueCols = [];
    function addUnique($col,$name){
        if(empty($this->uniqueCols[$name])) $this->uniqueCols[$name] = [];
        $this->uniqueCols[$name][$col->name] = $col;
        return $this;
    }

    function sqlCreate(&$data = []){
        $sqlcols = [];
        $this->cols->forEach(function($col) use (&$sqlcols,&$data){
            $sqlcols[] = $col->sqlCreate($data);
        });

        if(!empty($this->primaryCols)){
            $sqlcols[] = "PRIMARY KEY (`".join("`,`",array_keys($this->primaryCols))."`)";
        }
        if(!empty($this->indexCols)){
            foreach ($this->indexCols as $indexname => $list) {
                $sqlcols[] = "KEY `$indexname` (`".join("`,`",array_keys($list))."`)";
            }
        }
        if(!empty($this->uniqueCols)){
            foreach ($this->uniqueCols as $indexname => $list) {
                $sqlcols[] = "UNIQUE KEY `$indexname` (`".join("`,`",array_keys($list))."`)";
            }
        }
        $sqlcols = "\t".join(",\n\t", $sqlcols);
        $s = [];
        $s[] = "CREATE TABLE IF NOT EXISTS `$this->name`(";
        $s[] = $sqlcols;
        $s[] = ") ENGINE=$this->engine CHARSET=$this->charset AUTO_INCREMENT=1";
        return join("\n",$s);
    }
    function install(){
        db::in();
        $this->emit("before-install");
        $schema = db::row("SELECT table_name from information_schema.tables where table_schema = '".db::$listdb[db::$name]["dbname"]."' and table_name='$this->name'");
        if (!$schema) {
            $this->emit("before-create-schema");
            $data = [];
            $sql = $this->sqlCreate($data);
            db::query($sql,$data);
            $this->emit("create-schema");
            $this->emit("log",$sql);
        }else{
            $this->emit("before-update-schema");
            $cols = db::select("SELECT table_schema as base, table_name, column_name as col,column_type as type,data_type,column_key,extra  from information_schema.columns where table_schema = '".db::$listdb[db::$name]["dbname"]."' and table_name='$this->name' order by ordinal_position");
           
            $this->cols->forEach(function($col) use ($cols){
                $val = $cols->getBy("col",$col->name);
                if (!$val) {
                    $col->insertSchema();
                }elseif(!$col->isType($val["type"])){
                    $col->updateSchema();
                }
            });
            
            $self = $this;
            $cols->forEach(function($col) use ($self){
                if (!$self->cols->has($col["col"])) {
                    db::query("ALTER TABLE `".$self->name."` DROP `".$col["col"]."`");
                }
            });
            /*
            */
            $this->emit("update-schema");
        }
        $this->emit("install");
        $this->emit("init");
    }
}

class dbTableCol extends dbPDE{
    var $table = null; 
    var $name = null; 
    var $type = null; 
    function __construct($table,$name,$type){
        $this->table = $table;
        $this->name = $name;
        $this->type = $type;
        $this->hasDefault = false;
        $this->on("log",function($txt) use ($table){
            $table->emit("log","[$table->name] ".$txt);
        });
    }
    function __call($name,$args){
        switch (strtolower($name)) {
            case 'pri':
            case 'primary':
                $this->pri = true;
                $this->notNull();
                $this->table->addPrimary($this);
            break;
            case 'index':
                $this->table->addIndex($this,count($args)>0?$args[0]:"idx_$this->name");
            break;
            case 'unique':
                $this->table->addUnique($this,count($args)>0?$args[0]:"unq_$this->name");
            break;
            case 'ai':
            case 'auto':
            case 'auto_increment':
            case 'autoincrement':
            case 'autoinc':
                $this->ai = true;
            break;
            case 'notnull':
            case 'not_null':
                $this->notNull = true;
            break;
            case "def":
            case "default":
                $this->hasDefault = true;
                $this->defValue = $args[0];
                $this->defValueIsMysqlFunction = false;
                if (count($args)>1) {
                    if((bool)$args[1]){
                        $this->defValueIsMysqlFunction = true;
                    }
                }
            break;
            case 'length':
                $this->length = $args[0];
            break;
        }
        return parent::__call($name, $args);
    }
    function isType($type){
        $type = explode("(",$type);
        if (count($type)>1) {
            if (!is_null($this->length)) {
                $l = strtolower(trim(explode(")", $type[1])[0]));
                if($l != $this->length){
                    return false;
                }
            }
        }
        $type = strtolower(trim($type[0]));
        switch (strtolower($this->type)) {
            case 'string':
                return in_array($type, ["varchar"]);
            break;
            case 'json':
                return in_array($type, ["text"]);
            break;
            case 'longjson':
            case 'long_json':
                return in_array($type, ["longtext"]);
            break;
            default:
                return $type == strtolower($this->type);
            break;
        }
        return false;
    }
    function getMysqlType(){
        switch (strtolower($this->type)) {
            case 'string':
                $length = "255";
                if (!is_null($this->length)) {
                    $length = $this->length;
                }
                return "VARCHAR($length)";
            break;
            case 'json':
                return "TEXT";
            break;
            case 'longjson':
            case 'long_json':
                return "LONGTEXT";
            break;
            case 'int':
                $length = "11";
                if (!is_null($this->length)) {
                    $length = $this->length;
                }
                return "int($length)";
            break;
        }
    }
    function getMysqlAttrs(&$data = []){
        $attrs = [];
        if ($this->notNull) {
            $attrs[] = "Not Null";
        }else{
            $attrs[] = "Null";
        }

        if ($this->hasDefault) {
            if ($this->defValueIsMysqlFunction) {
                $attrs[] = "DEFAULT $this->defValue";
            }else{
                $attrs[] = "DEFAULT :defval$this->name";
                if(is_array($this->defValue)){
                    $data[":defval$this->name"] = json_encode($this->defValue);
                }else{
                    $data[":defval$this->name"] = $this->defValue;
                }
            }
        }

        if ($this->ai) {
            $attrs[] = "AUTO_INCREMENT";
        }

        return join(" ",$attrs);
    }
    function sqlCreate(&$data = []){
        return trim("`$this->name` ".$this->getMysqlType()." ".$this->getMysqlAttrs($data));
    }
    function insertSchema(){
        $tablename = $this->table->name;
        $this->emit("before-add-schema");
        $data = [];
        $sqlcreate = $this->sqlCreate($data);
        db::query("ALTER TABLE `$tablename` ADD COLUMN $sqlcreate",$data);
        $this->emit("add-schema");
        $this->emit("log","ADD $sqlcreate");
    }
    function updateSchema(){
        $tablename = $this->table->name;
        $data = [];
        $sqlcreate = $this->sqlCreate($data);
        $this->emit("before-update-schema");
        db::query("ALTER TABLE `$tablename` MODIFY COLUMN $sqlcreate",$data);
        $this->emit("update-schema");
        $this->emit("log","MODIFY $sqlcreate");
    }
}

class db extends dbkernel{
    static function decode($row,$array = false){
        return $array ? $row : new dbArray($row);
    }
    static $listTables = [];
    static function create($tableName,$callback = null){
        if (!array_key_exists($tableName, self::$listTables)) {
            self::$listTables[$tableName] = new dbTable($tableName);
        }
        if(is_callable($callback)){
            $callback(self::$listTables[$tableName]);
        }
        return self::$listTables[$tableName];
    }
    static function sql($sql,$data = []){
        return self::query($sql,$data);
    }
    static function q($sql,$data = []){
        return self::query($sql,$data);
    }
    static function row($sql,$data = [],$array = false){
        if ($q = self::query($sql,$data)) {
            if ($row = $q->fetch()) {
                return self::decode($row,$array);
            }
            return null;
        }
        return null;
    }
    static function select($sql,$data = [],$array = false){
        if ($q = self::query($sql,$data)) {
            $table = $array?[]:new dbArray();
            while ($row = $q->fetch()) {
                $table[] = self::decode($row,$array);
            }
            return $table;
        }
        return $array?[]:new dbArray();
    }
    static function val($sql,$data = [],$colname = null,$default = null){
        if($row = self::row($sql,$data)){
            return $row->first($default);
        }
        return $default;
    }


    static function table($tableName){
        return self::$listTables[$tableName];
    }
    static function install(){
        foreach (self::$listTables as $key => $table) {
            $table->install();
        }
    }
}

class dbQuery{
    var $_tableName = "";
    var $_sql = "";
    var $_select = null;
    var $_where = null;
    function __construct($tableName = ""){
        $this->_select = new dbArray();
        $this->_where = new dbArray();
        $this->tableName($tableName);
    }
    function tableName($tableName){
        $this->_tableName = $tableName;
        return $this;
    }
    function select($col_or_cols){
        if(is_string($col_or_cols)){
            $col_or_cols = explode(",",$col_or_cols);
        }elseif(is_array($col_or_cols)){
            $newlist = [];
            foreach ($col_or_cols as $key => $value) {
                if (is_string($key)) {
                    $newlist[] = "$key as $value";
                }else{
                    $newlist[] = $value;
                }
            }
            $col_or_cols = $newlist;
        }
        $this->_select->addUV($col_or_cols);
        return $this;
    }
    function where($sqlWhere,$data = []){
        $this->_where[] = [
            "sql" => $sqlWhere,
            "data" => $data
        ];
        return $this;
    }
    function all(){
        $sql = $this->sql();
        $list = db::select($sql,$this->getData());
        return $list;
    }
    function first(){
        $sql = $this->sql();
        $row = db::row($sql,$this->getData());
        return $row;
    }
    function count($col = "*",$default = 0){
        $sql = $this->sql();
        $row = db::row("SELECT count($col) as total from ($sql) counttablevalue",$this->getData());
        if(!$row) return $default;
        if(empty($row["total"])) return $default;
        return floatval($row["total"]);
    }
    function sum($col,$default = 0){
        $sql = $this->sql();
        $row = db::row("SELECT sum(IFNULL($col,0)) as total from ($sql) counttablevalue",$this->getData());
        if(!$row) return $default;
        return floatval($row["total"]);
    }
    function avg($col,$default = 0){
        $sql = $this->sql();
        $row = db::row("SELECT avg(IFNULL($col,0)) as total from ($sql) counttablevalue",$this->getData());
        if(!$row) return $default;
        return floatval($row["total"]);
    }
    function page($page,$length = 10){
        return $this;
    }
    function setSql($sql){
        $this->_sql = $sql;
        return $this;
    }
    function sql($pagination = false){

    }
    function getData(){

    }
    function run(){
        $sql = $this->sql();
        return db::query($sql,$this->getData());
    }
    function dist($col){
        
    }
}


