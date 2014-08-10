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
 *  BgbConfigurable: Automatically sets up magic __get for config passed to constructor
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
abstract class BgbConfigurable {
  protected $config = null;

  function __construct($config) {
    $this->config = $config;
  }

  public function __get($name) {
    if(array_key_exists($name, $this->config)) {
      return($this->config[$name]);
    }
    echo "BgbConfigurable.__get : Undefined property [ $name ] via __get()\n";
    BgbUtils::getStacktrace();
    Bgb::$log->warning("BgbConfigurable.__get : Undefined property [ $name ] via __get()");
    return null;
  }  

}


/**
 *  BgbRouter: Routes the application control to appropriate Controller based on rules in app conf
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 *  @method run() Determines Controller and action, then passes controll to it.
 *
 */
abstract class BgbRouter extends BgbConfigurable {

  function __construct($config=null) {
    parent::__construct($config);
  }

  abstract public function run($args=null);

  public function handleFatalError($e) {
    echo BgbUtils::getStacktrace($e);
    die();
  }

}


/**
 *  BgbLog: Abstract logging class which all logging classes extend
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
abstract class BgbLog extends BgbConfigurable {
  const FATAL = 0;
  const ERROR = 1;
  const WARNING = 2;
  const MESSAGE = 3;
  const DEBUG = 4;
  const TRACE = 5;
  public static $LEVELS_STR = array('FATAL', 'ERROR', 'WRNNG', 'MESSG', 'DEBUG', 'TRACE');

  protected $config = null;
  protected $level;
  protected $raw = false;
  protected $identifier;

  function __construct($config) {
    parent::__construct($config);
    $this->level = self::MESSAGE;
    if(isset($this->config['level'])) {
      $this->level = $this->config['level'];
    }
    if(isset($this->config['raw'])) {
      $this->raw = $this->config['raw'];
    }
    $this->identifier = time();
    //date_default_timezone_set(Bgb::$app->defaultTimezone);
  }
  public function fatal($msg) {
    if($this->level >= self::FATAL) { $this->log($msg, self::FATAL); }
  }
  public function error($msg) {
    if($this->level >= self::ERROR) { $this->log($msg, self::ERROR); }
  }
  public function warning($msg) {
    if($this->level >= self::WARNING) { $this->log($msg, self::WARNING); }
  }
  public function message($msg) {
    if($this->level >= self::MESSAGE) { $this->log($msg, self::MESSAGE); }
  }
  public function debug($msg) {
    if($this->level >= self::DEBUG) { $this->log($msg, self::DEBUG); }
  }
  public function trace($msg) {
    if($this->level >= self::TRACE) { $this->log($msg, self::TRACE); }
  }
  public function head($msg) {
    $this->doLog(''); $this->doLog('');
    $this->log($msg, self::MESSAGE);
    $this->doLog('');
  }
  protected function log($msg, $level) {
    $out_msg = $msg;
    if(!$this->raw) {
      $date_str = date('Y-m-d H:i:s', time());
      $level_str = self::$LEVELS_STR[$level];
      $out_msg = "$date_str - $this->identifier - $level_str - $msg";
    }
    $this->doLog($out_msg);
  }
  abstract protected function doLog($msg);
}


/**
 *  BgbLog: Stub logging class. All logging to this class is echoed.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbEchoLog extends BgbLog {
  protected function doLog($msg) {
    echo "$msg\n";
  }  
}


/**
 *  BgbFileLog: Basic file logging class. Requires 'logfile' in configuration.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbFileLog extends BgbLog {
  protected $fh = null;
  protected $err = false;

  protected function doLog($msg) {
    if(!$this->fh) {
      $this->openFile();
    }
    fwrite($this->fh, $msg . "\n");
  }

  protected function openFile() {
    if(!$this->err && !$this->fh) {
      $logfile = Bgb::$app->rootPath . $this->config['logfile'];
      $this->fh = fopen($logfile, 'a');
      if(!$this->fh) {
        $this->err = true;
        Bgb::throwLoggedException("BgbFileLog.openFile : Could not open log file at [ $logfile ]");
      }
    }
  }

}


/**
 *  BgbResult: Object to use for returning complex data structures
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbResult {

  const STATE_ERROR = 0;
  const STATE_SUCCESS = 1;

  protected $state;
  protected $error_message = null;
  protected $error_code = null;

  function __construct($state=self::STATE_ERROR, $error_message=null, $error_code=null) {
    $this->state = $state;
    $this->error_message = $error_message;
    $this->error_code = $error_code;
  }

  public function setState($state) {
    $this->state = $state;
  }
  public function getState() {
    return $this->state;
  }
  public function isSuccess() {
    return $this->state === self::STATE_SUCCESS;
  }
  public function isError() {
    return $this->state === self::STATE_ERROR;
  }

  public function getErrorCode() {
    return $this->error_code;
  }
  public function getErrorMessage() {
    return $this->error_message;
  }

}


/**
 *  BgbUtils: A mix of convenience functions needed by Bgb
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbUtils {

  private static $from_cc_func = null;
  private static $to_cc_func = null;

  public static function multipleIsSet($array, $keys) {
    if(!$array || !is_array($array)) {
      return false;
    }
    if(!$keys) {
      return true;
    }
    $keys = self::makeArray($keys);
    foreach($keys as $key) {
      if(!isset($array[$key])) {
        return false;
      }
    }
    return true;
  }

  public static function makeArray($arr) {
    if($arr === null) {
      return array();
    }
    if(!is_array($arr)) {
      return array($arr);
    }
    return $arr;
  }

  public static function arrayOrSplit($arr, $delim=",") {
    if($arr === null) {
      return array();
    }
    if(!is_array($arr)) {
      return preg_split("/$delim/", $arr);
    }
    return $arr;

  }

  public static function isAssoc($array) {
    return (bool)count(array_filter(array_keys($array), 'is_string'));
  }

  public static function startsWith($haystack, $needle) {
    return !strncmp($haystack, $needle, strlen($needle));
  }

  public static function endsWith($haystack, $needle) {
    $length = strlen($needle);
    if($length == 0) {
      return true;
    }
    return (substr($haystack, -$length) === $needle);
  }

  public static function from_camel_case($str) {
    $str[0] = strtolower($str[0]);
    if(!self::$from_cc_func) {
      self::$from_cc_func = create_function('$c', 'return "_" . strtolower($c[1]);');
    }
    return preg_replace_callback('/([A-Z])/', self::$from_cc_func, $str);
  }
 
  public static function to_camel_case($str, $capitalise_first_char=false) {
    if($capitalise_first_char) {
      $str[0] = strtoupper($str[0]);
    }
    if(!self::$to_cc_func) {
      self::$to_cc_func = create_function('$c', 'return strtoupper($c[1]);');  
    }
    return preg_replace_callback('/_([a-z])/', self::$to_cc_func, $str);
  }

  public static function getStacktrace(Exception $e) {
    $trace = $e->getTrace();

    $result = 'Exception: "';
    $result .= $e->getMessage();
    $result .= '" @ ';
    if($trace[0]['class'] != '') {
      $result .= $trace[0]['class'];
      $result .= '->';
    }
    $result .= $trace[0]['function'];
    $result .= '();<br />';

    return $result;
  }

}
