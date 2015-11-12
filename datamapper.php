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
 *  Built based on the DataMapper pattern. Features a heavy singleton 'mapper' class
 *  (extends off BgbMapper) for each table which maintains field mappings, rules,
 *  relations, etc. and through which all queries are done. The actual records 
 *  are represented by a lightweight 'model' class (extends off BgbModel) which
 *  is basically an array for field data and an array for insert/update errors
 *  when applicable.
 *
 *  The BgbDataMapper is the component class through which all mappers can be
 *  initiated and accessed.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbDataMapper extends BgbConfigurable {

  protected $mappers = array();

  function __construct($config=null) {
    parent::__construct($config);
  }

  public function getMapper($mapper_name) {
    if(!isset($this->mappers[$mapper_name])) {
      $this->mappers[$mapper_name] = $this->loadMapper($mapper_name);
    }
    return $this->mappers[$mapper_name];
  }

  protected function loadMapper($mapper_name) {
    $mapper_conf = $this->getMapperConf($mapper_name);
    if(!$mapper_conf) {
      Bgb::throwLoggedException("BgbDataMapper.loadMapper : No configuration for mapper [ $mapper_name ] found.");
    }

    if(isset($mapper_conf['__files'])) {
      Bgb::$app->loadFiles($mapper_conf['__files']);
    }

    $mapper_class = ucfirst($mapper_name) . 'Mapper';
    if(isset($mapper_conf['__class'])) {
      $mapper_class = $mapper_conf['__class'];
    }

    $mapper_instance = new $mapper_class($this, $this->getDbComponent());
    if(!$mapper_instance instanceof BgbMapper) {
      Bgb::throwLoggedException("BgbDataMapper.loadMapper : Mapper class [ $mapper_class ] is not an instance of BgbMapper.");
    }
    return $mapper_instance;
  }

  protected function getMapperConf($mapper_name) {
    $mapper_confs = $this->config['mappers'];
    foreach($mapper_confs as $name => $conf) {
      if($mapper_name === $name) {
        return $conf;
      }
    }
    return null;
  }

  protected function getDbComponent() {
    $db_component = 'db';
    if(isset($this->config['dbComponent'])) {
      $db_component = $this->config['dbComponent'];
    }
    return $db_component;
  }  

}



/**
 *  BgbMapper: DAO for DataMapper pattern, tied to a table and operates on BgbModel of its type
 *    - contains convenience functions for basic operations on its table
 *    - attempts to cache queries when possible
 *    - no validation on field names; validation must be wrapped around calls
 *    - calls return BgbDBResult
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 *  @method getTable() Must be overriden by implementing class and return table name
 *  @method getIdField() Can be overriden by implementing class when its table's id
 *    field is called something other than the default 'id'
 *
 *  @method get($id) Query for a record by id
 *  @method find($matches, $where, $limit, $offset, $orderby) Advanced query
 *    with multiple optional arguments; empty args gets all.
 *  @method insert($values) Inserts a new record populating it with given values.
 *  @method update($id, $values) Updates id'ed record with give values.
 *  @method delete($id) Deletes id'ed record
 *  @method count($matches, $where) Does count query with options to specify
 *    WHERE clause; empty args counts all
 *
 */
abstract class BgbMapper {

  const BELONGS_TO = 0;
  const HAS_MANY = 1;

  const RELATION_NAME = 0;
  const RELATION_TYPE = 1;
  const RELATION_MAPPER = 2;
  const RELATION_FIELD = 3;

  protected $model_name = null;
  protected $fields = null;
  protected $rules = null;
  protected $pipes = null;
  protected $relations = null;
  protected $query_cache = array();
  protected $datamapper = null;
  protected $db = null;

  public function __construct($datamapper, $db_component) {
    $this->datamapper = $datamapper;
    $this->db = Bgb::$app->$db_component;
    if(!$this->db) {
      Bgb::throwLoggedException("BgbMapper.__construct : Could not map to dbComponent [ $db_component ].");
    }
  }

  abstract protected function getTable();

  abstract protected function buildFields();

  public function getMapper($mapper_name) {
    return $this->datamapper->getMapper($mapper_name);
  }

  public function getFields() {
    if(!$this->fields) {
      $this->fields = BgbUtils::makeArray($this->buildFields());
    }
    return $this->fields;
  } 

  protected function buildRules() {
    return array();
  }
  protected function getRules() {
    if(!$this->rules) {
      $this->rules = $this->buildRules();
    }
    return BgbUtils::makeArray($this->rules);
  }

  protected function buildPipes() {
    return array();
  }
  protected function getPipes() {
    if(!$this->pipes) {
      $this->pipes = $this->buildPipes();
    }
    return BgbUtils::makeArray($this->pipes);
  }

  protected function buildRelations() {
    return array();
  }
  protected function getRelations() {
    if(!$this->relations) {
      $this->relations = $this->buildRelations();
    }
    return BgbUtils::makeArray($this->relations);
  }

  public function getModel() {
    return $this->getTable();
  }
 
  public function getModelName() {
    if(!$this->model_name) {
      $this->model_name = ucfirst($this->getModel()) . 'Model';
    }
    return $this->model_name;
  }

  public function databaseTransform($values) {
    return $values;
  }

  public function modelTransform($values) {
    return $values;
  }

  public function createModel($values=array()) {
    $model_name = $this->getModelName();
    return new $model_name($values);
  }

  public function getIdField() {
    return 'id'; // override if different
  }

  public function getId(BgbModel $model) {
    return $model->callGet($this->getIdField());
  }

  public function setId(BgbModel $model, $id) {
    $model->callSet($this->getIdField(), $id);
  }  

  public function get($id) {
    $q = $this->getQuery('BgbTableMapper::get', false);
    if(!$q) {
      $q = $this->getQuery('get_by_id')->from($this->getTable())->where($this->getIdField() . '=:id');
    }
    $result = $q->select(array('id' => $id));
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    $model = $this->recordToModel($result->next());
    $this->loadRelations($model);
    return $model;
  }

  // - if $matches is present and $where is not a WHERE clause will be built consisting of
  //   exact matches on every field, chained by ANDs
  // - for more sophisticated WHERE clauses, pass $where and populate matches with any
  //   fields referenced
  public function find($matches=null, $where=null, $limit=0, $offset=0, $orderby=null) {
    if(!$orderby) {
      $orderby = $this->getIdField();
    }
    $q = $this->getQuery('BgbTableMapper::find');
    $q->from($this->getTable())
      ->orderBy($orderby);
    if($limit) {
      $q->limit($limit, $offset);
    }
    $values = array();
    if($matches) {
      $values = $matches;
      if(!$where) {
        $q->multiWhere(array_keys($matches));
      } else {
        $q->where($where);
      }
    } elseif($where) {
      $q->where($where);
    } else {
      $q->multiWhere(array());
    }
    $result = $q->select($values);
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    $models = $this->recordsToModels($result->all());
    $this->loadRelations($models);
    return $models;
  }

  public function findOne($matches=null, $where=null, $limit=0, $offset=0, $orderby=null) {
    $models = $this->find($matches, $where, $limit, $offset, $orderby);
    return count($models) > 0 ? $models[0] : null;
  }

  public function save($model, $new_values=null) {
    if($new_values) {
      $model->setValues($new_values);
    }
    $id = $this->getId($model);
    if($id) {
      return $this->update($model);
    } else {
      return $this->insert($model);
    }
  }

  public function insert($model) {
    if(!$this->validate($model)) {
      return false;
    }
    $values = $model->getFieldValues(false);
    $values = $this->databaseTransform($values);
    $result = $this->getQuery('BgbTableMapper::insert')
      ->insert($this->getTable(), $values);
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    $this->setId($model, $result->getLastInsertId());
    return true;
  }

  public function update($model) {
    if(!$this->validate($model)) {
      return false;
    }    
    $values = $model->getFieldValues(false);
    $values = $this->databaseTransform($values);
    unset($values[$this->getIdField()]);
    $result = $this->getQuery('BgbTableMapper::update')
      ->where($this->getIdField() . '=:id')
      ->update($this->getTable(), array('id' => $this->getId($model)), $values);
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    return true;
  }

  public function delete($model) {
    $result = $this->getQuery('BgbTableMapper::delete')
      ->where($this->getIdField() . '=:id')
      ->delete($this->getTable(), array('id' => $this->getId($model)));
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    return true;
  }

  // - if $matches is present and $where is not a WHERE clause will be built consisting of
  //   exact matches on every field, chained by ANDs
  // - for more sophisticated WHERE clauses, pass $where and populate matches with any
  //   fields referenced
  public function count($matches=null, $where=null) {
    $q = $this->getQuery('BgbTableMapper::count');
    $q->from($this->getTable());
    $values = array();
    if($matches) {
      $values = $matches;
      if(!$where) {
        $q->multiWhere(array_keys($matches));
      } else {
        $q->where($where);
      }
    } else {
      $q->multiWhere(array());
    }
    $result = $q->count($values);
    if(!$result->isSuccess()) {
      throw new BgbMapperException($result->getErrorMessage());
    }
    $rec = $result->next();
    return intval($rec['count']);
  }

  protected function validate($model) {
    $rules = $this->getRules();
    $pipes = $this->getPipes();
    $values = $model->getValues();
    $success = true;
    foreach($rules as $field => $field_rules) {
      $value = $model->getValue($field);
      if(isset($pipes[$field])) {
        foreach($pipes[$field] as $pipe) {
          $value = $pipe->filter($value);
          $values[$field] = $value;
        }
        $model->callSet($field, $values[$field]);
      }
      foreach($field_rules as $rule) {
        if(!$rule->validate($value, $values)) {
          $model->setError($field, $rule->getError());
          $success = false;
          break; // break out of this field's rules only, keep checking other fields
        }
      }
    }
    return $success;
  }  

  protected function recordsToModels(array $records) {
    $models = array();
    foreach($records as $record) {
      $models[] = $this->recordToModel($record);
    }
    return $models;
  }

  protected function recordToModel($record) {
    if(!$record || !is_array($record)) {
      return null;
    }
    $record = $this->modelTransform($record);
    return $this->createModel($record);
  }

  // query caching
  protected function getQuery($name, $create=true) {
    if(isset($this->query_cache[$name])) {
      return $this->query_cache[$name];
    }
    if(!$create) {
      return null;
    }
    $this->query_cache[$name] = $this->db->createQuery();
    return $this->query_cache[$name];
  }

  public function loadRelations($models, $filter=null, $get_belongs_to=true) {
    $models = BgbUtils::makeArray($models);
    if(count($models) === 0) {
      return;
    }
    $relations = $this->getRelations();
    if(count($relations) === 0) {
      return;
    }
    if($filter !== null) {
      $filter = BgbUtils::makeArray($filter);
    }
    $ids = $this->getIds($models);
    foreach($relations as $relation) {
      $rel_records = null;
      $rel_name = $relation[self::RELATION_NAME];
      if($filter !== null && !in_array($rel_name, $filter)) {
        continue;
      }
      $rel_type = $relation[self::RELATION_TYPE];
      $rel_mapper = $relation[self::RELATION_MAPPER];
      $rel_field = $relation[self::RELATION_FIELD];
      switch($rel_type) {
        case self::BELONGS_TO:
          if(!$get_belongs_to) {
            continue;
          }
          $mapper = $this->getMapper($rel_mapper);
          $fk_ids = $this->getIds($models, $rel_field);
          $foreign_models = $mapper->getFkRecords($mapper->getIdField(), $fk_ids);
          self::collateRelatedRecords($models, $foreign_models, $rel_field, $mapper->getIdField(), $rel_name, false);
          break;
        case self::HAS_MANY:
          $mapper = $this->getMapper($rel_mapper);
          $foreign_models = $mapper->getFkRecords($rel_field, $ids);
          $mapper->loadRelations($foreign_models, self::pareFilter($filter, $rel_name), false);
          self::collateRelatedRecords($models, $foreign_models, $this->getIdField(), $rel_field, $rel_name, true);
          break;
        default:
          Bgb::throwLoggedException(sprintf('self.loadRelations : relation has unknown type [ %d ]', $rel_type));
      }
    }
    return;
  }

  protected function getFkRecords($fk_field, $fk_ids) {
    $fk_ids = BgbUtils::makeArray($fk_ids);
    $in_query = implode(',', array_fill(0, count($fk_ids), '?'));
    $result = $this->getQuery('get_fk_records')
      ->from($this->getTable())
      ->where($fk_field . " IN ( $in_query )")
      ->select($fk_ids);
    return $this->recordsToModels($result->all());
  }

  protected static function collateRelatedRecords($models, $foreign_models, $local_id_field, $foreign_id_field, $assign_field, $multi) {
    foreach($models as $model) {
      $local_id = $model->callGet($local_id_field);
      $assign_value = null;
      if($multi) {
        $assign_value = array();
      }
      foreach($foreign_models as $foreign_model) {
        if($local_id === $foreign_model->callGet($foreign_id_field)) {
          if($multi) {
            $assign_value[] = $foreign_model;
          } else {
            $assign_value = $foreign_model;
          }
        }
      }
      $model->callSet($assign_field, $assign_value);
    }
  }

  protected static function pareFilter($filter, $prefix) {
    if($filter === null || count($filter) === 0) {
      return $filter;
    }
    $pared = array();
    foreach($filter as $filter_item) {
      if(BgbUtils::startsWith($filter_item, $prefix . '.')) {
        $pared = substr($filter_item, strlen($prefix)+1);
      }
    }
    return $pared;
  }

  protected function getIds($models, $id_field=null) {
    if($id_field === null) {
      $id_field = $this->getIdField();
    }
    $ids = array();
    foreach($models as $model) {
      $id = $model->getValue($id_field);
      if(!in_array($id, $ids)) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

}

abstract class BgbModel {
  const CALL_OP = 0;
  const CALL_FIELD = 1;
  const CALL_VALUE = 2;

  protected $values = array();
  protected $errors = array();

  abstract public function getMapperName();

  function __construct(array $values=array()) {
    $this->setValues($values);
  }

  public function __call($name, $arguments) {
    $op = $this->getOperation($name, $arguments);
    if(!$op) {
      throw new Exception('Method ' . $name . ' does not exist.');
    }
    $field_name = $op[self::CALL_FIELD];
    if($op[self::CALL_OP] === 'get') {
      return isset($this->values[$field_name]) ? $this->values[$field_name] : null;
    } else {
      $this->values[$field_name] = $op[self::CALL_VALUE];
    }
  }

  public function getMapper() {
    $mapper_name = $this->getMapperName();
    $mapper_parts = preg_split('/\./', $mapper_name);
    $datamapper = Bgb::$app->$mapper_parts[0];
    return $datamapper->getMapper($mapper_parts[1]);
  }

  public function setValues($values) {
    $values = BgbUtils::makeArray($values);
    if(count($values) === 0) {
      return;
    }
    if(!BgbUtils::isAssoc($values)) {
      Bgb::throwLoggedException("BgbModel.setValues : Called with non-assoc-array.");
    }
    foreach($values as $field => $value) {
      $this->callSet($field, $value);
    }
  }

  public function getValues() {
    return $this->values;
  }

  public function getValue($field) {
    return isset($this->values[$field]) ? $this->values[$field] : null;
  }  

  public function getFieldValues($include_id=true) {
    $fields = $this->getMapper()->getFields();
    $id_field = $this->getMapper()->getIdField();
    $field_values = array();
    foreach($this->values as $key => $value) {
      if(in_array($key, $fields)) {
        $field_values[$key] = $value;
      }
      if($include_id && $key === $id_field) {
        $field_values[$key] = $value;
      }
    }
    return $field_values;
  }

  public function setError($field, $error) {
    $this->errors[$field] = $error;
  }

  public function getError($field) {
    return isset($this->errors[$field]) ? $this->errors[$field] : null;
  }

  public function getErrors() {
    return $this->errors;
  }

  public function callSet($field, $value) {
    $call = 'set' . BgbUtils::to_camel_case($field, true);
    $this->$call($value);
  }
  public function callGet($field) {
    $call = 'get' . BgbUtils::to_camel_case($field, true);
    return $this->$call();
  }  

  protected function getOperation($name, $arguments) {
    $op = array();
    if(BgbUtils::startsWith($name, 'get')) {
      $op[self::CALL_OP] = 'get';
    } elseif (BgbUtils::startsWith($name, 'set')) {
      $op[self::CALL_OP] = 'set';
      $op[self::CALL_VALUE] = count($arguments) > 0 ? $arguments[0] : null;
    } else {
      return null;
    }
    $op[self::CALL_FIELD] = $this->getFieldName(substr($name, 3));
    return $op;
  }

  protected function getFieldName($input) {
    preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
    $ret = $matches[0];
    foreach ($ret as &$match) {
      $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
    }
    return implode('_', $ret);
  }

  protected function isField($field) {
    return in_array($field, $this->getMapper()->getFields());
  }

  protected function isMapping($field) {
    $mappings = $this->getMapper()->getMappings();
    return isset($mappings[$field]);
  }

  protected function getMapping($field) {
    $mappings = $this->getMapper()->getMappings();
    return isset($mappings[$field]) ? $mappings[$field] : null;
  }

}



abstract class BgbRule {
  protected $error = NULL;
  public function __construct($error) {
    $this->error = $error;
  }
  public function getError() {
    return $this->error;
  }
  abstract public function validate($value, $values);
}

class RequiredRule extends BgbRule {
  public function validate($value, $values) {
    return empty($value) ? false : true;
  }

}

class NameRule extends BgbRule {
  public function validate($value, $values) {
    return empty($value) || !preg_match("/[\^<,\"@\/\{\}\(\)\*\$%\?=>:\|;#0-9]+/i", $value);
  }
}

class EmailRule extends BgbRule {
  public function validate($value, $values) {
    return empty($value) || filter_var($value, FILTER_VALIDATE_EMAIL);
  }
}

class UrlRule extends BgbRule {
  public function validate($value, $values) {
    return empty($value) || filter_var($value, FILTER_VALIDATE_URL);
  }
}

class IntRule extends BgbRule {
  protected $min;
  protected $max;
  public function __construct($error, $min=0, $max=PHP_INT_MAX) {
    parent::__construct($error);
    $this->min = $min;
    $this->max = $max;
  }
  public function validate($value, $values) {
    $options = array('options' => array('min_range' => $this->min, 'max_range' => $this->max));
    return empty($value) || filter_var($value, FILTER_VALIDATE_INT, $options);
  } 
}

class FloatRule extends BgbRule {
  protected $min;
  protected $max;
  public function __construct($error, $min=0, $max=PHP_INT_MAX) {
    parent::__construct($error);
    $this->min = $min;
    $this->max = $max;
  }
  public function validate($value, $values) {
    if(empty($value)) {
      return true;
    }
    if($value < $this->min || $value > $this->max) {
      return false;
    }
    return empty($value) || filter_var($value, FILTER_VALIDATE_FLOAT);
  } 
}

class StringLengthRule extends BgbRule {
  protected $min;
  protected $max;
  public function __construct($error, $min, $max) {
    parent::__construct($error);
    $this->min = $min;
    $this->max = $max;
  }
  public function validate($value, $values) {
    if(empty($value)) {
      return true;
    }
    $len = strlen($value);
    return $len < $this->min || $len > $this->max ? false : true;
  }
}

class ConstMatchFieldRule extends BgbRule {
  protected $match_list;
  protected $allow_null;
  public function __construct($error, $match_list, $allow_null=false) {
    parent::__construct($error);
    $this->match_list = BgbUtils::makeArray($match_list);
    $this->allow_null = $allow_null;
  }
  public function validate($value, $values) {
    if(!$value) {
      return $this->allow_null;
    }
    foreach($this->match_list as $match) {
      if($match === $value) {
        return true;
      }
    }
    return false;
  } 
}

class PregMatchFieldRule extends BgbRule {
  protected $preg_list;
  protected $allow_null;
  public function __construct($error, $preg_list, $allow_null=false) {
    parent::__construct($error);
    $this->preg_list = BgbUtils::makeArray($preg_list);
    $this->allow_null = $allow_null;
  }
  public function validate($value, $values) {
    if(!$value) {
      return $this->allow_null;
    }
    foreach($this->preg_list as $preg_item) {
      if(preg_match($preg_item, $value)) {
        return true;
      }
    }
    return false;
  } 
}

class GroupRequiredRule extends BgbRule {
  protected $dependent_fields;
  public function __construct($error, $dependent_fields) {
    parent::__construct($error);
    $this->dependent_fields = BgbUtils::makeArray($dependent_fields);
  }
  public function validate($value, $values) {
    if(!empty($value)) {
      return true;
    }
    return $this->isDependentsEmpty($values);
  }
  protected function isDependentsEmpty($values) {
    foreach($this->dependent_fields as $field) {
      if(!empty($values[$field])) {
        return false;
      }
      return true;
    }
  }
}


abstract class BgbPipe {
  abstract public function filter($value);
}


class BgbMapperException extends Exception {
}
