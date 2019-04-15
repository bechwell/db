<?php 

class db{
	static $pdo = null;
	static $issqlite = false;
	static $ismysql = false;
	static $ismysqli = false;
	static $listdb = [];
	static function add_mysql($name,$host="127.0.0.1",$dbname="base",$user="root",$password=null){
		self::$listdb[$name] = [
			"mysql"  => true,
			"sqlite" => false,
			"pdo"    => self::connect("mysql:host=$host;dbname=$dbname",$user,$password),
		];
	}
	static function add_sqlite($name,$path="database.sqlite"){
		self::$listdb[$name] = [
			"mysql"  => false,
			"sqlite" => true,
			"pdo"    => self::connect("sqlite:$path"),
		];
	}
	static function db($name){
		if (isset(self::$listdb[$name])) {
			self::$pdo = self::$listdb[$name]["pdo"];
			self::$issqlite = self::$listdb[$name]["sqlite"];
			self::$ismysql = self::$listdb[$name]["mysql"];
			return self::$pdo;
		}
		trigger_error("Erreur aucun base nomme : $dbname", E_USER_ERROR);
		return false;
	}
	static function sqlite($path="database.sqlite"){
		self::$issqlite = true;
		self::$ismysql = false;
		self::$ismysqli = false;
		self::$pdo = null;
		self::$pdo = self::connect("sqlite:$path");
		return self::$pdo;
	}
	static function mysql($host="127.0.0.1",$dbname="base",$user="root",$password=null){
		self::$issqlite = false;
		self::$ismysql = true;
		self::$ismysqli = false;
		self::$pdo = null;
		self::$pdo = self::connect("mysql:host=$host;dbname=$dbname",$user,$password);
		return self::$pdo;
	}
	static function mysqli($host="127.0.0.1",$dbname="base",$user="root",$password=null){
		self::$issqlite = false;
		self::$ismysql = true;
		self::$ismysqli = true;
		self::$pdo = null;
		self::$pdo = self::connect("mysql:host=$host;dbname=$dbname",$user,$password);
		return self::$pdo;
	}
	static function connect($str,$user = null,$password = null){
		$db = null;
		try {
			$db = new PDO($str,$user,$password);
			$db->query("SET NAMES utf8");
			$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (Exception $e) {
			$db = null;
			throw new Exception("Erreur connection : $dbType : ".$e->getMessage(), 1);
			return false;
		}
		return $db;
	}
	static function script($name,$callback){

	}
	static function run($name,$callback = null){

	}
	static function stop($name){

	}
	static function query($sql,$data = null){
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
	}
	static function decode($row){
		foreach ($row as $key => $value) {
			$v = json_decode($value,true);
			if (json_last_error() == JSON_ERROR_NONE) {
			  $row[$key] = $v;
			}
		}
		return $row;
	}
	static function row($sql,$data = null){
		if ($q = self::query($sql,$data)) {
			if ($row = $q->fetch()) {
				return self::decode($row);
			}
		}
		return null;
	}
	static function table($sql,$data = null){
		if ($q = self::query($sql,$data)) {
			$table = [];
			while ($row = $q->fetch()) {
				$table[] = self::decode($row);
			}
			return $table;
		}
		return null;
	}
	static function val($sql,$data = null,$default = null){
		if($row = self::row($sql,$data)){
			$keys = array_keys($row);
			if (!empty($keys)) {
				return $row[$keys[0]];
			}
		}
		return $default;
	}
	static $schema = null;
	static function getColsTable($table){
		if (is_null(self::$schema)) {
			self::$schema = [];
			if (self::$ismysql) {
				$sql = "SELECT * FROM information_schema.columns where `TABLE_SCHEMA`<>'information_schema'";
				$list = self::table($sql);
				$tables = [];
				foreach ($list as $key => $row) {
					if(empty($tables[$row["TABLE_NAME"]])) $tables[$row["TABLE_NAME"]] = [];
					if(empty($tables[$row["TABLE_NAME"]]["cols"])) $tables[$row["TABLE_NAME"]]["cols"] = [];
					if(empty($tables[$row["TABLE_NAME"]]["auto"])) $tables[$row["TABLE_NAME"]]["auto"] = [];
					if(empty($tables[$row["TABLE_NAME"]]["default"])) $tables[$row["TABLE_NAME"]]["default"] = [];
					if(empty($tables[$row["TABLE_NAME"]]["nullable"])) $tables[$row["TABLE_NAME"]]["nullable"] = [];
					$tables[$row["TABLE_NAME"]]["dbname"]  = $row["TABLE_SCHEMA"];
					$tables[$row["TABLE_NAME"]]["cols"][$row["COLUMN_NAME"]] = $row["COLUMN_TYPE"];
					if(!is_null($row["COLUMN_DEFAULT"])){
						$tables[$row["TABLE_NAME"]]["default"][$row["COLUMN_NAME"]] = $row["COLUMN_DEFAULT"];
					}
					$tables[$row["TABLE_NAME"]]["nullable"][$row["COLUMN_NAME"]] = $row["IS_NULLABLE"] == "YES";
					if ($row["EXTRA"] == "auto_increment") {
						$tables[$row["TABLE_NAME"]]["auto"][$row["COLUMN_NAME"]] = $row["COLUMN_NAME"];
					}
				}
				foreach ($tables as $tablename=>$tabledata) {
					if(empty($tables[$tablename]["primary"])) $tables[$tablename]["primary"] = [];
					if(empty($tables[$tablename]["index"])) $tables[$tablename]["index"] = [];
					if(empty($tables[$tablename]["unique"])) $tables[$tablename]["unique"] = [];
					$indexs = self::table("SHOW INDEX FROM `$tablename` FROM `".$tabledata["dbname"]."`");
					foreach ($indexs as $idx) {
						if($idx["Key_name"] == "PRIMARY"){
							$tables[$tablename]["primary"][] = $idx["Column_name"];
						}elseif ((bool)$idx["Non_unique"]) {
							if(empty($tables[$tablename]["index"][$idx["Key_name"]])) $tables[$tablename]["index"][$idx["Key_name"]] = [];
							$tables[$tablename]["index"][$idx["Key_name"]][] = $idx["Column_name"];
						}else{
							if(empty($tables[$tablename]["unique"][$idx["Key_name"]])) $tables[$tablename]["unique"][$idx["Key_name"]] = [];
							$tables[$tablename]["unique"][$idx["Key_name"]][] = $idx["Column_name"];
						}
					}
				}
			}else{
				$listtable = self::table("SELECT name FROM sqlite_master WHERE type='table'");
				$tables = [];
				foreach ($listtable as $tbl) {
					$tablename = $tbl["name"];
					$tables[$tablename] = [
						"cols"     => [],
						"default"  => [],
						"nullable" => [],
						"primary"  => [],
						"auto"     => [],
						"index"    => [],
						"unique"   => [],
					];
					$cols = self::table("PRAGMA table_info(`$tablename`)");
					foreach ($cols as $col) {
						$tables[$tablename]["cols"][$col["name"]] = $col["type"];
						$tables[$tablename]["default"][$col["name"]] = $col["dflt_value"];
						$tables[$tablename]["nullable"][$col["name"]] = !(bool)$col["notnull"];
						if ((bool)$col["pk"]) {
							$tables[$tablename]["primary"][] = $col["name"];
							if (self::row("SELECT 'is-autoincrement' as val FROM sqlite_master WHERE tbl_name='$tablename' AND sql LIKE '%AUTOINCREMENT%'")) {
								$tables[$tablename]["auto"][$col["name"]] = $col["name"];
							}
						}
					}
					$indexs = self::table("PRAGMA index_list(`$tablename`)");
					foreach ($indexs as $index) {
						$cols = self::table("PRAGMA index_info(`".$index["name"]."`)");
						if ((bool)$index["unique"]) {
							foreach ($cols as $col) {
								if(empty($tables[$tablename]["unique"][$index["name"]])) $tables[$tablename]["unique"][$index["name"]] = [];
								$tables[$tablename]["unique"][$index["name"]][] = $col["name"];
							}
						}else{
							foreach ($cols as $col) {
								if(empty($tables[$tablename]["index"][$index["name"]])) $tables[$tablename]["index"][$index["name"]] = [];
								$tables[$tablename]["index"][$index["name"]][] = $col["name"];
							}
						}
					}
				}
			}
			self::$schema = $tables;
			// schema
			/*
				self::$schema = [
					"tablename" => [
						"cols"=>[
							"colname"=>"col Type",
							"colname"=>"col Type",
							"colname"=>"col Type",
							...
						],
						"primary" => ["col1","col2" ...],
						"auto"=>[
							"colname"=>"colname",
							"colname"=>"colname",
							"colname"=>"colname",
							...
						],
						"index"=>[
							"indexname"=>["col1","col2" ...],
							"indexname"=>["col1","col2" ...],
							...
						],
						"unique"=>[
							"indexname"=>["col1","col2" ...],
							"indexname"=>["col1","col2" ...],
							...
						]
					]
				]
			*/
		}
		return isset(self::$schema[$table])?self::$schema[$table]:[];
	}
	static function hasCol($table,$col){
		$cols = self::getColsTable($table);
		return isset($cols["cols"][$col]);
	}
	static function getJustColTable($table,$row){
		$list = [];
		foreach ($row as $key => $value) {
			if (self::hasCol($table,$key)) {
				$list[$key] = $value;
			}
		}
		return $list;
	}
	static function insert($table,$data){
		$data = self::getJustColTable($table,$data);
		$cols = array_keys($data);
		if(empty($cols)) return false;
		$strcolsk = "`".join("`,`",$cols)."`";
		$strcolsv = ":".join(",:",$cols);
		$sql = "INSERT INTO `$table` ($strcolsk) values ($strcolsv)";
		if (self::query($sql,$data)) {
			return self::$pdo->lastInsertId();
		}
		return null;
	}
	static function update($table,$data,$filter){
		$data = self::getJustColTable($table,$data);
		$filter = self::getJustColTable($table,$filter);
		$cols = array_keys($data);
		if(empty($cols)) return false;
		if(empty($filter)) return false;
		$strcols = [];
		foreach ($cols as $key => $col) {
			$strcols[] = "`$col`=:$col";
		}
		$strcols = join(", ",$strcols);
		$strcolsfilter = [];
		foreach ($filter as $col => $value) {
			$strcolsfilter[] = "`$col`=:filter$col";
			$data[":filter$col"] = $filter[$col];
		}
		$strcolsfilter = join(", ",$strcolsfilter);
		$sql = "UPDATE `$table` set $strcols where $strcolsfilter";
		return self::query($sql,$data);
	}
	static function save($table,$data){
		$data = self::getJustColTable($table,$data);
		$cols = array_keys($data);
		if(empty($cols)) return false;
		$strcolsk = "`".join("`,`",$cols)."`";
		$strcolsv = ":".join(",:",$cols);
		$update = [];
		foreach ($cols as $col) {
			$update[] = "`$col`=values(`$col`)";
		}
		$update = join(",",$update);
		if (self::$ismysql) {
			$sql = "INSERT INTO `$table` ($strcolsk) values ($strcolsv) ON DUPLICATE KEY UPDATE $update";
		}else{
			$sql = "INSERT OR REPLACE INTO `$table` ($strcolsk) values ($strcolsv)";
		}
		if (self::query($sql,$data)) {
			return self::$pdo->lastInsertId();
		}
		return null;
	}
	static $listCreateTable = [];
	static function create($tablename,$callback){
		$table = new self($tablename);
		self::$listCreateTable[$tablename] = $table;
		$callback->bindTo($table)($table);
		return $table;
	}
	static function getCreateSchema($force = false,$checkupdate = false){
		$sql = [];
		foreach (self::$listCreateTable as $table) {
			if ($s = $table->install($force,true,false,$checkupdate)) {
				if (!empty($s)) {
					$sql[] = $s ;
				}
			}
		}
		return join("\n\n\n",$sql);
	}
	var $name = "";
	function __construct($tablename){
		$this->name = $tablename;
	}
	var $cols = [];
	function __call($name,$args){
		switch ($name) {
			default:
				$this->cols[$args[0]] = new dbCol($this,$args[0],$name);
				return $this->cols[$args[0]];
			break;
		}
	}
	static $typemap =  [
		"int"       => ["INT(11)","INTEGER"],
		"string"    => ["VARCHAR(255)","TEXT"],
		"double"    => ["DOUBLE","DOUBLE"],
		"date"      => ["date","date"],
		"timestamp" => ["TIMESTAMP","TIMESTAMP"],
		"text"      => ["TEXT","TEXT"],
		"longtext"  => ["LONGTEXT","TEXT"],
		"json"  	=> ["TEXT"],
		"longjson"  => ["LONGTEXT"],
	];
	static function getphptype($type){
		foreach (self::$typemap as $key => $value) {
			$tt =strtolower(explode("(", $value[0])[0]);
			if ($tt == strtolower(trim(explode("(", $type)[0]))) {
				return $key;
			}
			$tt =strtolower(explode("(", $value[1])[0]);
			if ($tt == strtolower(trim(explode("(", $type)[0]))) {
				return $key;
			}
		}
		return "string";
	}
	static function genphp(){
		self::getColsTable("");
		$str = "";
		foreach (self::$schema as $tablename=>$table) {
			$str .= "db::create(\"$tablename\",function(\$table){\n";
			foreach ($table["cols"] as $colname => $coltype) {
				$typename = self::getphptype($coltype);
				$str .= "\t\$table->$typename(\"$colname\")";
				if (!$table["nullable"][$colname]) {
					$str .= "->notnull()";
				}
				if (!empty($table["default"][$colname])) {
					if ($typename == "int") {
						$str .= "->default(".$table["default"][$colname].")";
					}elseif(in_array(strtolower($table["default"][$colname]),["current_timestamp"]) || preg_match("/\(/i",$table["default"][$colname])){
						$str .= "->default(\"".$table["default"][$colname]."\")";
					}else{
						$str .= "->default(\"'".$table["default"][$colname]."'\")";
					}
				}
				if (in_array($colname,$table["primary"])) {
					$str .= "->pri()";
				}
				if (in_array($colname,$table["auto"])) {
					$str .= "->auto()";
				}
				foreach ($table["index"] as $indexname => $cols) {
					if (in_array($colname,$cols)) {
						$str .= "->index(\"$indexname\")";
					}
				}
				foreach ($table["unique"] as $indexname => $cols) {
					if (in_array($colname,$cols)) {
						$str .= "->unique(\"$indexname\")";
					}
				}
				$str .= ";\n";
			}
			$str .= "});\n";
		}
		return $str;
	}
	function install($force = false,$returnschema = false,$returnasarray = false,$checkupdate = true){
		$schema = self::getColsTable($this->name);
		$sql = [];
		if (!empty($schema) && $checkupdate) {
			$insertcol = [];
			$updatecol = [];
			$deletecol = [];
			foreach ($this->cols as $colname => $col) {
				if (empty($schema["cols"][$colname])) {
					$insertcol[] = $col;
				}else{
					if (self::getphptype($schema["cols"][$colname]) != strtolower(trim($col->type))) {
						$updatecol[] = $col;
					}
				}
			}
			foreach ($schema["cols"] as $colname => $coltype) {
				$find = false;
				foreach ($this->cols as $cn => $col) {
					if ($cn == $colname) {
						$find = true;
						break;
					}
				}
				if (!$find) {
					$deletecol[] = $colname;
				}
			}
			$sql = [];
			if (self::$ismysql) {
				foreach ($deletecol as $key => $value) {
					$sql[] = "ALTER TABLE `".$this->name."` DROP COLUMN `$value`";
				}
				foreach ($insertcol as $key => $col) {
					$sql[] = "ALTER TABLE `".$this->name."` ADD COLUMN ".$col->sql(self::$ismysql);
				}
				foreach ($updatecol as $key => $col) {
					$sql[] = "ALTER TABLE `".$this->name."` MODIFY COLUMN ".$col->sql(self::$ismysql);
				}
			}else{
				if (empty($deletecol) && empty($updatecol)) {
					foreach ($insertcol as $key => $col) {
						$sql[] = "ALTER TABLE `".$this->name."` ADD ".$col->sql(self::$ismysql);
					}
				}else{
					$newname = $this->name."_".uniqid();
					$sql[] = "ALTER TABLE `".$this->name."` RENAME TO `$newname`";
					$sql = array_merge($sql,$this->install(false,true,true,false));
					$listcol = [];
					foreach ($this->cols as $colname => $value) {
						if (isset($schema["cols"][$colname])) {
							$listcol[] = $colname;
						}
					}
					$listcol = "`".join("`,`",$listcol)."`";
					$sql[] = "INSERT INTO `".$this->name."` ($listcol) SELECT $listcol FROM $newname";
				}
			}
		}else{
			if ($force) $sql[] = "DROP TABLE IF EXISTS `$this->name`";
			$list = [];
			foreach ($this->cols as $key => $col) $col->getIndexList($list,self::$ismysql);
			$ss  = "CREATE TABLE IF NOT EXISTS `$this->name` (";
			$v = false;
			foreach ($this->cols as $key => $col) {
				if($v) $ss .=",";
				$ss .= "\n\t".$col->sql(self::$ismysql);
				$v = true;
			}
			if (!empty($list["primary"])) {
				$ss .= ",\n\tPRIMARY KEY (`".join("`,`",$list["primary"])."`)";
			}
			$ss .= "\n)";
			if (self::$ismysql) $ss .= " ENGINE=MyISAM CHARSET=utf8 AUTO_INCREMENT=1";
			$sql[] = $ss;
			if (!empty($list["index"])) {
				foreach ($list["index"] as $indexname => $cols) {
					if (!self::$ismysql){
						foreach (self::$schema as $tablename => $value) {
							if(strtolower($indexname) == strtolower($tablename)) $indexname = "index_$indexname";
						}
					}
					$sql[] = "CREATE INDEX".(self::$ismysql?"":" IF NOT EXISTS")." `$indexname` ON `$this->name` (`".join("`,`",$cols)."`)";
				}
			}
			if (!empty($list["unique"])) {
				foreach ($list["unique"] as $indexname => $cols) {
					if (!self::$ismysql) if(!empty(self::$schema[$indexname])) $indexname = "index_$indexname";
					$sql[] = "CREATE UNIQUE INDEX".(self::$ismysql?"":" IF NOT EXISTS")." `$indexname` ON `$this->name` (`".join("`,`",$cols)."`)";
				}
			}
		}
		if ($returnschema) {
			if ($returnasarray) {
				return $sql;
			}else{
				if (empty($sql)) {
					return "";
				}
				return join(";\n",$sql).";";
			}
		}
		foreach ($sql as $sqlQuery) {
			self::query($sqlQuery);
		}
	}
}
class dbCol{
	var $name = "";
	var $type = "";
	var $data = [];
	var $parent = null;
	var $indexnames = [];
	var $uniqueindexnames = [];
	function index($name = null){
		if (is_null($name)) $name = "indexCol".ucfirst(strtolower($this->name));
		$this->indexnames[$name] = $name;
		return $this;
	}
	function unique($name = null){
		if (is_null($name)) $name = "indexCol".ucfirst(strtolower($this->name));
		$this->uniqueindexnames[$name] = $name;
		return $this;
	}
	function getIndexList(&$list,$ismysql){
		if ($this->pri || $this->primary) {
			if (empty($list["primary"])) $list["primary"] = [];
			if ($ismysql || (!$this->auto && !$this->autoinc && !$this->auto_increment)) {
				$list["primary"][] = $this->name;
			}
		}
		if (empty($list["index"])) $list["index"] = [];
		foreach ($this->indexnames as $indexname) {
			if (empty($list["index"][$indexname])) $list["index"][$indexname] = [];
			$list["index"][$indexname][] = $this->name;
		}
		if (empty($list["unique"])) $list["unique"] = [];
		foreach ($this->uniqueindexnames as $indexname) {
			if (empty($list["unique"][$indexname])) $list["unique"][$indexname] = [];
			$list["unique"][$indexname][] = $this->name;
		}
	}
	function __construct($parent,$colName,$type){
		$this->name = $colName;
		$this->type = $type;
		$this->parent = $parent;
	}
	function __call($name,$args){
		if (empty($args)) {
			$args = [true];
		}
		$this->data[$name] = $args[0];
		return $this;
	}
	function __set($name,$value){
		$this->data[$name] = $value;
	}
	function __get($name){
		return $this->data[$name];
	}
	function sql($ismysql){
		$sql = [];
		$sql[] = "`$this->name`";
		switch ($this->type) {
			case 'int':
				if ($ismysql) {
					$sql[] ="int(11)";
				}else{
					$sql[] ="INTEGER";
				}
			break;
			case 'string':
				if ($ismysql) {
					$sql[] ="varchar(255)";
				}else{
					$sql[] ="TEXT";
				}
			break;
			default:
				$sql[] = strtoupper($this->type);
			break;
		}
		if ($this->notnull || $this->NotNull || $this->notNull) {
			$sql[] ="NOT NULL";
		}else{
			$sql[] ="NULL";
		}
		if ($this->def) {
			$sql[] ="DEFAULT ".$this->def;
		}
		if ($this->default) {
			$sql[] ="DEFAULT ".$this->default;
		}
		if ($this->auto || $this->autoinc || $this->auto_increment) {
			if ($ismysql) {
				$sql[] ="AUTO_INCREMENT";
			}else{
				$sql[] ="PRIMARY KEY AUTOINCREMENT";
			}
		}
		return join(" ",$sql);
	}
}
