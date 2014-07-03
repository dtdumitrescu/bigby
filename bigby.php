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


require_once(dirname(__FILE__) . '/utils.php');

/**
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class Bgb extends BgbConfigurable {

  /** Class variables and functions **/

  public static $bigby_dir = null;
  public static $app = null;
  public static $log = null;
  protected static $loaded_files = array();

  public static function init($config) {
    self::$bigby_dir = dirname(__FILE__);
    self::$app = new Bgb($config);
    self::$app->initialize();
    self::$log = self::$app->log;
    return self::$app;
  }

  public static function throwLoggedException($msg) {
    Bgb::$app->log->fatal($msg);
    throw new Exception($msg);
  }


  /** Instance attributes and methods **/  
  
  protected $modules = array();
  protected $components = array();

  function __construct($config) {
    parent::__construct($config);
  }

  public function initialize() {
    try {
      $this->loadDefaultLog();
      $this->loadComponents();
      $this->loadIncludes();
    } catch(Exception $e) {
      $this->handleFatalError($e);
    }
  }

  protected function loadDefaultLog() {
    $this->components['log'] = new BgbEchoLog(null);
  }

  protected function loadIncludes() {
    if(!isset($this->config['includes'])) {
      return;
    }
    $includes = $this->config['includes'];
    $this->loadFiles($includes);
  }

  protected function loadComponents() {
    $component_confs = $this->config['components'];
    foreach($component_confs as $component_name => $component_conf) {
      if(isset($component_conf['__require'])) {
        $this->loadCoreComponents($component_conf['__require']);
      }
      if(isset($component_conf['__files'])) {
        $this->loadFiles($component_conf['__files']);
      }
      $this->components[$component_name] = $this->createComponent($component_name, $component_conf);
    }
  }

  protected function createComponent($component_name, $component_conf) {
    $class_name = $component_conf['__class'];
    if($index = strrpos($class_name, DIRECTORY_SEPARATOR)) {
      $this->loadFile($this->rootPath . DIRECTORY_SEPARATOR . $class_name . '.php');
      $class_name = substr($class_name, $index+1);
    }
    if(!class_exists($class_name)) {
      $this->throwLoggedException("Bgb.loadClass : Component [ $component_name ] class [ $class_name ] is not defined.");
    }
    return new $class_name($component_conf);
  }

  protected function loadCoreComponents($names) {
    $names_arr = BgbUtils::makeArray($names);
    foreach($names_arr as $name) {
      $this->loadCoreComponent($name);
    }
  }  

  protected function loadCoreComponent($name) {
    $this->loadFile(Bgb::$bigby_dir . DIRECTORY_SEPARATOR . $name . '.php');
  }

  public function loadFiles($files) {
    $files_arr = BgbUtils::makeArray($files);
    foreach($files_arr as $file) {
      $this->loadFile($file);
    }
  }

  public function loadFile($path) {
    if(in_array($path, Bgb::$loaded_files)) {
      return;
    }
    if(is_dir($path)) {
      $dir = new DirectoryIterator($path);
      foreach($dir as $fileinfo) {
        if(!$fileinfo->isDot() && ($fileinfo->isDir() || BgbUtils::endsWith($fileinfo->getPathname(), '.php'))) {
          $this->loadFile($fileinfo->getPathname());
        }
      }
      return;
    }
    if(!file_exists($path)) {
      $this->throwLoggedException("Bgb.loadFile : File at [ $path ] does not exist.");
    }
    require($path);
    Bgb::$loaded_files[] = $path;
  }   

  public function __get($name) {
    if(array_key_exists($name, $this->components)) {
      return($this->components[$name]);
    }
    return parent::__get($name);
  }

  public function run($args=null) {
    foreach($this->components as $component) {
      if(!($component instanceof BgbRouter)) {
        continue;
      }
      try {
        $component->run($args);
      } catch(Exception $e) {
        $component->handleFatalError($e);
      }
    }
  }

  public function handleFatalError($e) {
    echo $e->getMessage();
    echo BgbUtils::getStacktrace($e);
    die();
  }  

}
