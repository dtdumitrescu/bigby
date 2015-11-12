<?php

/**
* Bigby - v1.0.0
*
* A simple, light PHP framework.
*
*
* Released under the MIT license
*
* Copyright (C) 2014 Dani Dumitrescu
*
* Permission is hereby granted, free of charge, to any person obtaining a copy
* of this software and associated documentation files (the "Software"), to deal
* in the Software without restriction, including without limitation the rights
* to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
* copies of the Software, and to permit persons to whom the Software is
* furnished to do so, subject to the following conditions:
*
* The above copyright notice and this permission notice shall be included in
* all copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
* LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
* OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
* SOFTWARE.
*
*/


/**
 *  BgbDB: Abstract DB class which all DB classes extend. Represents one connection with a database.
 *    An app may have multiple DB components, as defined in the app configuraion file. This class is
 *    never directly instantiated. Rather, one of its database-type-specific child-classes is.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 *  @method BgbQuery createQuery() Instantiates and returns a database-type-appropriate instance of
 *    BgbQuery.
 *
 */
abstract class BgbDB extends BgbConfigurable {

  function __construct($config) {
    parent::__construct($config);
  }

  abstract public function getDatabaseHandle();

  abstract public function createQuery();

  public function create_placeholders($number_of_placeholders) {
    return join(", ", array_fill(0, $number_of_placeholders, "?"));
  }
}


/**
 *  BgbMySqlDB: BgbDB subclass for accessing a MySql database. Returns BgbMySqlQuery types.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbMySqlDB extends BgbDB {

  protected $dbh = null;

  function __construct($config) {
    parent::__construct($config);
  }

  public function getDatabaseHandle() {
    return $this->dbh;
  }  

  public function createQuery() {
    $this->connect();
    $query = new BgbMySqlQuery($this->dbh);
    return $query;
  }

  protected function connect() {
    if($this->dbh) {
      return;
    }
    $this->assertConfig();
    try {
      $dsn = 'mysql:host=' . $this->config['hostname'] . ';dbname=' . $this->config['database'];
      $this->dbh = new PDO($dsn, $this->config['username'], $this->config['password']);
      $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      if(isset($this->config['utf8_names']) && $this->config['utf8_names'] === true) {
        $this->createQuery()->raw('SET NAMES utf8');
      }
    } catch(PDOException $e) {
      Bgb::throwLoggedException('BgbMySqlDB.connect : ' . $e->getMessage());
    }
  }

  protected function assertConfig() {
    if(!BgbUtils::multipleIsSet($this->config, array('hostname', 'database', 'username', 'password'))) {
      Bgb::throwLoggedException('BgbDB.assertConfig : configuration missing required parameters');
    }
  }

}


/**
 *  BgbQuery: Abstract query class. A PDO statement wrapper.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
abstract class BgbQuery {

}


/**
 *  BgbMySqlQuery: MySql specific query builder.
 *
 *  Built light and with the philosophy that query builders are meant for convenience, not to protect you
 *  from SQL. If the query builder doesn't support an advanced query, don't be afraid to drop to raw SQL
 *  via the raw() call.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbMySqlQuery extends BgbQuery {
  const OP_AND = 0;
  const OP_OR = 1;

  protected $dbh = null;
  protected $statement = null;
  protected $sql = null;

  protected $mode = null;
  protected $tables = array();
  protected $fields = array();
  protected $wheres = array();
  protected $orderBys = array();
  protected $limit = null;
  protected $offset = null;
  protected $table = null;

  function __construct($dbh) {
    $this->dbh = $dbh;
  }

  protected function execute($values=null) {
    if(!$this->sql) {
      $this->buildQuery();
    }
    if(!$this->statement) {
      $this->statement = $this->dbh->prepare($this->sql);
    }
    $values = BgbUtils::makeArray($values);
    $result = new BgbDBResult($this->sql, $values);
    if(!$this->statement) {
      $result->setState(BgbResult::STATE_ERROR);
    } else {
      $result->setPdoHandles($this->dbh, $this->statement);
      try {
        $this->statement->execute($values);
        if($this->statement->errorCode() == 0) {
          $result->setState(BgbResult::STATE_SUCCESS);
        }
      } catch(PDOException $e) {
        Bgb::$log->error('BgbDB.execute : ' . $e->getMessage());
        $result->setState(BgbResult::STATE_ERROR);
      }
    }
    return $result;
  }

  protected function buildQuery() {
    $sql = $this->mode . ' ';
    switch($this->mode) {
      case 'SELECT':
        $sql .= $this->buildSelectFields();
        $sql .= $this->buildFrom();
        $sql .= $this->buildWhere();
        $sql .= $this->buildOrderBy();
        $sql .= $this->buildLimit();
        $sql .= $this->buildOffset();
        break;
      case 'INSERT':
        $sql .= $this->buildInsert();
        break;
      case 'UPDATE':
        $sql .= $this->buildUpdate();
        $sql .= $this->buildWhere();
        $sql .= $this->buildLimit();
        break;
      case 'DELETE':
        $sql .= $this->buildDelete();
        $sql .= $this->buildWhere();
        $sql .= $this->buildLimit();
        break;
    }
    $this->sql = trim($sql);
    Bgb::$log->trace('BgbDB.buildQuery : ' . $this->sql);
  }
  protected function reset() {
    $this->sql = null;
    $this->statement = null;
  }

  public function select($values=null) {
    $this->mode = 'SELECT';
    return $this->execute($values);
  }

  public function insert($table, $values) {
    $fields = array_keys($values);
    // if fields are the same as before just use same PDO statement, otherwise reset
    if($fields != $this->fields || $this->table !== $table) { 
      $this->mode = 'INSERT';
      $this->prepareManip($table, $fields);
    }
    return $this->execute($values);
  }

  public function update($table, $condition_values, $update_values) {
    $condition_values = BgbUtils::makeArray($condition_values);
    $fields = array_keys($update_values);
    // if fields are the same as before just use same PDO statement, otherwise reset
    if($fields != $this->fields || $this->table !== $table) {
      $this->mode = 'UPDATE';
      $this->prepareManip($table, $fields);
    }
    return $this->execute(array_merge($condition_values, $update_values));
  }

  public function delete($table, $values) {
    $this->mode = 'DELETE';
    $this->table = $table;
    return $this->execute($values);
  }

  public function count($values=null) {
    $this->fields('COUNT(*) AS count');
    return $this->select($values);
  }

  public function raw($sql, $values=null) {
    if($this->sql !== $sql) {
      $this->reset();
    }
    $this->sql = $sql;
    return $this->execute($values);
  }

  public function fields($fields=null) {
    $old_fields = $this->fields;
    $this->fields = array();
    $fields = BgbUtils::arrayOrSplit($fields);
    foreach($fields as $field) {
      $this->addSelectFieldItem($field);
    }
    if($old_fields !== $this->fields) {
      $this->reset();
    }
    return $this;
  }
  protected function addSelectFieldItem($field) {
    $field_arr = array('table' => null, 'as' => null);
    $field_parts = preg_split('/ /', trim($field));
    if(count($field_parts) === 3 && strcasecmp($field_parts[1], 'AS') === 0) {
      $field_arr['as'] = "`$field_parts[2]`";
    }
    if(strpos($field_parts[0], '(') !== false) {
      $field_arr['name'] = $field_parts[0];
    } else {
      $field_arr['name'] = "`$field_parts[0]`";
      $field_parts = preg_split('/\./', $field_parts[0]);
      if(count($field_parts) === 2) {
        $field_arr['table'] = "`$field_parts[0]`";
        $field_arr['name'] = "`$field_parts[1]`";
      }
    }
    $this->fields[] = $field_arr;
  }
  protected function buildSelectFields() {
    if(!count($this->fields)) {
      return '* ';
    }
    $sql = '';
    foreach($this->fields as $field) {
      $sql .= $field['table'] ? $field['table'] . '.' : '';
      $sql .= $field['name'] . ($field['as'] ? ' AS ' . $field['as'] : '') . ', ';
    }
    return rtrim($sql, ', ') . ' ';
  }

  public function from($tables) {
    $old_tables = $this->tables;
    $this->tables = array();
    $tables = BgbUtils::arrayOrSplit($tables);
    foreach($tables as $table) {
      $this->addFromItem($table);
    }
    if($old_tables !== $this->tables) {
      $this->reset();
    }    
    return $this;
  }
  protected function addFromItem($table) {
    $table_arr = array('alias' => null);
    $table_parts = preg_split('/ /', trim($table));
    if(strpos($table_parts[0], '(') !== false) {
      $table_arr['name'] = $table_parts[0];
    } else {
      $table_arr['name'] = "`$table_parts[0]`";
      if(count($table_parts) === 2) {
        $table_arr['alias'] = "`$table_parts[1]`";
      }
    }
    $this->tables[] = $table_arr;
  }
  protected function buildFrom() {
    $tables = array();
    foreach($this->tables as $table) {
      $tables[] = $table['name'] . ($table['alias'] ? ' ' . $table['alias'] : '');
    }
    return 'FROM ' . implode(', ', $tables) . ' ';
  }

  // no escaping done for where clauses - TODO, maybe?
  public function where($condition) {
    $old_wheres = $this->wheres;
    $this->wheres = array();
    $this->addWhere($condition, self::OP_AND);
    if($old_wheres !== $this->wheres) {
      $this->reset();
    }
    return $this;
  }
  public function andWhere($condition) {
    $this->reset();
    $this->addWhere($condition, self::OP_AND);
    return $this;
  }
  public function orWhere($condition) {
    $this->reset();
    $this->addWhere($condition, self::OP_OR);
    return $this;
  }
  // convenience function: match on all fields passed
  public function multiWhere($fields) {
    $old_wheres = $this->wheres;
    $this->wheres = array();
    $fields = BgbUtils::makeArray($fields);
    foreach($fields as $field) {
      $this->addWhere("$field=:$field", self::OP_AND);
    }
    if($old_wheres !== $this->wheres) {
      $this->reset();
    }
    return $this;
  }
  protected function addWhere($condition, $op) {
    $this->wheres[] = array('cond' => $condition, 'op' => $op);
  }  
  protected function buildWhere() {
    $sql = '';
    foreach($this->wheres as $where) {
      $sql .= (!$sql ? 'WHERE ' : ($where['op'] === self::OP_AND ? 'AND ' : 'OR ')) . $where['cond'] . ' ';
    }
    return $sql;
  }

  public function orderBy($fields) {
    $old_orderBys = $this->orderBys;
    $this->orderBys = array();
    $fields = BgbUtils::arrayOrSplit($fields);
    foreach($fields as $field) {
      $this->addOrderByItem($field);
    }
    if($old_orderBys !== $this->orderBys) {
      $this->reset();
    }    
    return $this;
  }
  protected function addOrderByItem($field) {
    $order_arr = array('sort' => null);
    $order_parts = preg_split('/ /', trim($field));
    $order_arr['name'] = "`$order_parts[0]`";
    if(count($order_parts) === 2) {
      $order_arr['sort'] = $order_parts[1];
    }
    $this->orderBys[] = $order_arr;
  }  
  protected function buildOrderBy() {
    $orders = array();
    foreach($this->orderBys as $field) {
      $orders[] = $field['name'] . ($field['sort'] ? ' ' . $field['sort'] : '');
    }
    return count($orders) > 0 ? 'ORDER BY ' . implode(', ', $orders) . ' ': '';
  }

  public function limit($limit, $offset=null) {
    if($this->limit !== $limit) {
      $this->limit = $limit;
      $this->reset();
    }
    $this->offset($offset);
    return $this;
  }
  protected function buildLimit() {
    return $this->limit !== null ? 'LIMIT ' . $this->limit . ' ' : '';
  }

  protected function offset($offset) {
    if($this->offset !== $offset) {
      $this->offset = $offset;
      $this->reset();
    }
    return $this;
  }
  protected function buildOffset() {
    return $this->offset !== null ? 'OFFSET ' . $this->offset . ' ' : '';
  }

  protected function prepareManip($table, $fields) {
    $this->reset();
    $this->table = $table;
    $this->fields = array();
    $fields = BgbUtils::arrayOrSplit($fields);
    foreach($fields as $field) {
      $this->fields[] = $field;
    }
  }  
  protected function buildInsert() {
    $values = array();
    foreach($this->fields as $field) {
      $values[] = ":$field";
    }
    return "INTO $this->table (" . implode(',', $this->fields) . ') VALUES (' . implode(',', $values) . ')';
  }
  protected function buildUpdate() {
    $assigns = array();
    foreach($this->fields as $field) {
      $assigns[] = "$field=:$field";
    }
    return "$this->table SET " . implode(',', $assigns) . ' ';
  }

  protected function buildDelete() {
    return "FROM $this->table ";
  }    

}


class BgbDBResult extends BgbResult {

  protected $sql;
  protected $values;
  protected $dbh = null;
  protected $statement = null;
  protected $cached_records = array();
  protected $next_pointer = 0;

  function __construct($sql, $values) {
    parent::__construct();
    $this->sql = $sql;
    $this->values = $values;
  }

  public function setPdoHandles($dbh, $statement) {
    $this->dbh = $dbh;
    $this->statement = $statement;
  }  

  public function getError() {
    if(!$this->statement) {
      return null;
    }
    return $this->statement->errorInfo();
  }
  public function getErrorCode() {
    if($this->error_code) {
      return $this->error_code;
    }
    if(!$this->statement) {
      return null;
    }
    return $this->statement->errorCode();
  }
  public function getErrorMessage() {
    if($this->error_message) {
      return $this->error_message;
    }
    if(!$this->statement) {
      return null;
    }
    $error = $this->getError();
    return $error[2];
  }

  public function setCachedRecords($records) {
    $this->cached_records = $records;
    $this->resetNextPointer();
  }
  public function resetNextPointer() {
    $this->next_pointer = 0;
  }  

  public function next() {
    if(!$this->isSuccess()) {
      return null;
    }
    if($cached_record = $this->nextFromCached()) {
      return $cached_record;
    }
    $fresh_record = $this->statement->fetch(PDO::FETCH_ASSOC);
    if($fresh_record) {
      $this->cached_records[] = $fresh_record;
      ++$this->next_pointer;
    }
    return $fresh_record;
  }
  protected function nextFromCached() {
    if($this->next_pointer >= count($this->cached_records)) {
      return null;
    }
    return $this->cached_records[$this->next_pointer++];
  }

  public function all() {
    if(!$this->isSuccess()) {
      return null;
    }
    if(count($this->cached_records) < $this->getRowCount()) {
      $this->cached_records = $this->statement->fetchAll(PDO::FETCH_ASSOC);
    }
    return $this->cached_records;
  }
  
  public function getLastInsertId() {
    if(!$this->isSuccess()) {
      return null;
    }
    return $this->dbh->lastInsertId();
  }
  public function getRowCount() {
    if(!$this->isSuccess()) {
      return null;
    }
    return $this->statement->rowCount();
  }

}
