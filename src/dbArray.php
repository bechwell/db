<?php 
namespace BW;

class dbArray implements \ArrayAccess, \JsonSerializable, \Iterator{
    public $__data = [];
    public function __construct($data = []) {
        $this->__data = $data;
    }
    public function current(){
        return current($this->__data);
    }
    public function key(){
        return key($this->__data);
    }
    public function next(){
        return next($this->__data);
    }
    public function valid(){
        $key = key($this->__data);
        $var = ($key !== NULL && $key !== FALSE);
        return $var;
    }
    public function rewind(){
        reset($this->__data);
    }
    public function __dump(){
        return var_dump($this->__data);
    }


    function get($name,$default = null){
        if (isset($this->__data[$name])) {
            return $this->__data[$name];
        }
        return $default;
    }
    function getBy($colname,$value,$default = null){
        foreach ($this->__data as $key => &$row) {
            if (is_array($row) || is_a($row,"dbArray")) {
                if (isset($row[$colname])) {
                    if ($row[$colname] == $value) {
                        return $row;
                    }
                }
            }
        }
        return $default;
    }
    function set($name,$value){
        $this->__data[$name] = $value;
        return $this;
    }
    function __get($name){
        return $this->__data[$name];
    }
    function __set($name,$value){
        $this->set($name,$value);
    }
    public function __toString(){
        return print_r($this->__data,true);
    }
    public function __debugInfo(){
        return $this->__data;
    }
    public function offsetSet($offset, $value) {
        if (is_null($offset)) {
            $this->__data[] = $value;
        } else {
            $this->__data[$offset] = $value;
        }
    }
    public function offsetExists($offset) {
        return isset($this->__data[$offset]);
    }
    public function offsetUnset($offset) {
        unset($this->__data[$offset]);
    }
    public function offsetGet($offset) {
        return isset($this->__data[$offset]) ? $this->__data[$offset] : null;
    }
    public function json(){
        return json_encode($this->__data);
    }

    static function convertAllToArray($obj){
         if(!is_a($obj, "dbArray") && is_array($obj)){
            foreach ($obj as $key => $value) {
               $obj[$key] = self::convertAllToArray($value);
            }
        }elseif(is_a($obj, "dbArray")){
            return self::convertAllToArray($obj->__data);
        }
        return $obj;
    }

    public function jsonSerialize(){
        return self::convertAllToArray($this->__data);
    }
    public function orderBy($col,$type = "ASC"){
        return $this->sort($col,$type);
    }
    public function merge($list){
        foreach ($list as $key => $value) {
            if(is_string($key)){
                $this->__data[$key] = $value;
            }else{
                $this->__data[] = $value;
            }
        }
        return $this;
    }
    public function addUV($list){
        foreach ($list as $key => $value) {
            if(!in_array($value, $this->__data)){
                $this->__data[] = $value;                
            }
        }
        return $this;
    }
    public function sort($on, $type = "ASC"){
        $order = strtolower(trim($type)) == "asc" ? SORT_ASC : SORT_DESC;
        $array = $this->__data;
        $new_array = new self();
        $sortable_array = [];
        if (count($array) > 0) {
            foreach ($array as $k => $v) {
                if (is_array($v) || is_a($v,"dbArray")) {
                    foreach ($v as $k2 => $v2) {
                        if ($k2 == $on) {
                            $sortable_array[$k] = $v2;
                        }
                    }
                } elseif(is_object($v)) {
                    $sortable_array[$k] = $v->$on;
                } else {
                    $sortable_array[$k] = $v;
                }
            }

            switch ($order) {
                case SORT_ASC:
                    asort($sortable_array);
                break;
                case SORT_DESC:
                    arsort($sortable_array);
                break;
            }

            foreach ($sortable_array as $k => $v) {
                $new_array[$k] = $array[$k];
            }
        }

        return $new_array;
    }
    public function forEach($callback){
        foreach ($this->__data as $key => &$value) {
            $callback($value,$key);
        }
        return $this;
    }
    public function map($callback){
        $newlist = new self();
        if (is_callable($callback)) {
            foreach ($this->__data as $key => &$value) {
                $newlist[] = $callback($value,$key);
            }
        }else {
            foreach ($this->__data as $key => &$value) {
                $newlist[] = $value[$callback];
            }
        }
        return $newlist;
    }
    public function filter($callback,$val = null){
        $newlist = new self();
        if(!is_null($val) && !is_callable($callback)){
            foreach ($this->__data as $key => &$value) {
                if ($value[$callback] == $val) {
                    $newlist[$key] = $value;
                }
            }
        }else{
            foreach ($this->__data as $key => &$value) {
                if ($callback($value,$key) === true) {
                    $newlist[$key] = $value;
                }
            }
        }
        return $newlist;
    }
    function find($callback,$default = false){
        foreach ($this->__data as $key => &$value) {
            if ($callback($value,$key) === true) {
                return $value;
            }
        }
        return $default;
    }
    function has($key){
        return array_key_exists($key,$this->__data);
    }
    function first($default = null){
        if (!empty($this->__data)) {
            foreach ($this->__data as $key => $value) {
                return $value;
            }
        }
        return $default;
    }
    function toArray(){
        return $this->__data;
    }
}



