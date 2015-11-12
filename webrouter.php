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
 *  BgbWebRouter: Custom BgbRouter used by BgbWebApplication
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 */
class BgbWebRouter extends BgbRouter {
  const RULE_METHOD = 0;
  const RULE_PREG = 1;
  const RULE_ACTION = 2;

  public $method = '';
  public $controller = '';
  public $action = '';

  protected $route_key = '__route__';

  function __construct($config=null) {
    parent::__construct($config);
    if(isset($config['route_key'])) {
      $this->route_key = $config['route_key'];
    }
    if(!isset($config['rootViewsPath'])) {
      $this->rootViewsPath = Bgb::$app->rootPath . 'views';
    }
  }

  public function run($args=null) {
    $uri = $this->getRequestURI();
    $action = $this->findAction($uri);
    if(!$action) {
      $this->sendErrorAndDie(404, 'The requested URL was not found on this server.');
    }
    $this->callAction($action);
  }

  public function runError($status_code, $status_name, $custom_msg) {
    $rule_action = $this->findErrorAction($status_code);
    if(!$rule_action) {
      return false;
    }
    $action_arr = preg_split('/\//', $rule_action);
    if(count($action_arr) !== 2) {
      Bgb::$log->error("BgbWebRouter.runError : Bad action specified for error code [ $status_code ]");
      return false;
    }
    $this->callAction(array(
      'controller' => $action_arr[0],
      'action' => $action_arr[1],
      'args' => array(array(
        'status_code' => $status_code,
        'status_name' => $status_name,
        'custom_msg' => $custom_msg,
      )),
    ));
    return true;
  }

  protected function callAction($action) {
    $controller_name = $action['controller'];
    $controller_class_name = ucfirst($controller_name) . 'Controller';
    $action_name = $action['action'];
    $arguments = $action['args'];

    $controller_class = new $controller_class_name($this);
    if(!$controller_class instanceof BgbController) {
      Bgb::throwLoggedException("BgbWebRouter.callAction : Controller class [ $controller_class ] is not an instance of BgbController");
    }
    if(!method_exists($controller_class, $action_name)) {
      Bgb::throwLoggedException("BgbWebRouter.callAction : Controller action for [ ${controller_name}/${action_name} ] not found");
    }
    $this->assertArgCountInRange($controller_class, $action_name, $arguments);
    Bgb::$log->debug(sprintf("BgbWebRouter.callAction : calling [ %s/%s ] with [ %d ] arguments.", $controller_name, $action_name, count($arguments)));
    $this->controller = $controller_name;
    $this->action = $action_name;
    call_user_func_array(array($controller_class, $action_name), $arguments);
  }

  protected function assertArgCountInRange($instance, $action_name, $arguments) {
    $reflection_class = new ReflectionClass($instance);
    $method = $reflection_class->getMethod($action_name);
    $max_args = $method->getNumberOfParameters();
    $min_args = $method->getNumberOfRequiredParameters();
    $arg_count = count($arguments);
    if($arg_count > $max_args || $arg_count < $min_args) {
      Bgb::throwLoggedException(sprintf('BgbWebRouter.assertArgCountInRange - Method argument length [ min: %d, max: %d ] did not match route argument length [ %d ] for %s.', $min_args, $max_args, $arg_count, $this->getRequestURI()));
    }
  }

  protected function findAction($uri) {
    $route = $this->findRoute($uri);
    if(!$route) {
      return null;
    }
    $rule = $route['rule'];
    $arguments = $route['args'];
    $rule_action = $rule[self::RULE_ACTION];
    $action_arr = preg_split('/\//', $rule_action);

    return array(
      'controller' => isset($action_arr[0]) ? $action_arr[0] : 'default',
      'action' => isset($action_arr[1]) ? $action_arr[1] : 'index',
      'args' => $arguments,
    );
  }

  protected function findRoute($route) {
    $method = strtolower($_SERVER['REQUEST_METHOD']);
    $this->method = $method;
    foreach($this->config['rules'] as $rule) {
      $rule_methods = BgbUtils::makeArray($rule[self::RULE_METHOD]);
      $method_match = false;
      foreach($rule_methods as $rule_method) {
        if($method === $rule_method) {
          $method_match = true;
          break;
        }
      }
      if(!$method_match) {
        continue;
      }

      /*if($method !== strtolower($rule[self::RULE_METHOD])) {
        continue;
      }*/
      $regex = '|^/' . $rule[self::RULE_PREG] . '$|';
      if(preg_match($regex, $route, $arguments)) {
        array_shift($arguments);
        return array('rule' => $rule, 'args' => $arguments);
      }
    }
    return null;
  }

  protected function findErrorAction($status_code) {
    if(!isset($this->config['errors'])) {
      return null;
    }
    $rule_action = null;
    if(isset($this->config['errors'][$status_code])) {
      $rule_action = $this->config['errors'][$status_code];
    } elseif(isset($this->config['errors']['*'])) {
      $rule_action = $this->config['errors']['*'];
    }
    return $rule_action;
  }

  protected function getRequestURI() {
    $uri = isset($_REQUEST[$this->route_key]) ? rtrim($_REQUEST[$this->route_key], '/') : '';
    if(!$uri) {
      $uri = '/';
    }
    return $uri;
  }

  static $status_codes = array (
      100 => 'Continue',
      101 => 'Switching Protocols',
      102 => 'Processing',
      200 => 'OK',
      201 => 'Created',
      202 => 'Accepted',
      203 => 'Non-Authoritative Information',
      204 => 'No Content',
      205 => 'Reset Content',
      206 => 'Partial Content',
      207 => 'Multi-Status',
      300 => 'Multiple Choices',
      301 => 'Moved Permanently',
      302 => 'Found',
      303 => 'See Other',
      304 => 'Not Modified',
      305 => 'Use Proxy',
      307 => 'Temporary Redirect',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      402 => 'Payment Required',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      406 => 'Not Acceptable',
      407 => 'Proxy Authentication Required',
      408 => 'Request Timeout',
      409 => 'Conflict',
      410 => 'Gone',
      411 => 'Length Required',
      412 => 'Precondition Failed',
      413 => 'Request Entity Too Large',
      414 => 'Request-URI Too Long',
      415 => 'Unsupported Media Type',
      416 => 'Requested Range Not Satisfiable',
      417 => 'Expectation Failed',
      422 => 'Unprocessable Entity',
      423 => 'Locked',
      424 => 'Failed Dependency',
      426 => 'Upgrade Required',
      500 => 'Internal Server Error',
      501 => 'Not Implemented',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Timeout',
      505 => 'HTTP Version Not Supported',
      506 => 'Variant Also Negotiates',
      507 => 'Insufficient Storage',
      509 => 'Bandwidth Limit Exceeded',
      510 => 'Not Extended'
  );  

  public function handleFatalError($e) {
    $custom_msg = '';
    if(Bgb::$app->debug) {
      $custom_msg .= $e->getMessage() . "<br><br>\n";
      $custom_msg .= preg_replace('/\n/', "<br>\n", $e->getTraceAsString());
    }
    $this->sendErrorAndDie(500, $custom_msg);
  }
  
  public function sendErrorAndDie($status_code, $custom_msg='', $send_raw=false) {
    if(!isset(self::$status_codes[$status_code])) {
      $status_code = 500;
    }
    $status_name = self::$status_codes[$status_code];
    header($status_code . ' ' . $_SERVER['SERVER_PROTOCOL'] . ' ' . $status_name, true, $status_code);
    if($send_raw) {
      echo $custom_msg;
    } elseif(!$this->runError($status_code, $status_name, $custom_msg)) {
      $html_template = '<!DOCTYPE html><html><head><title>%s %s</title></head><body><h1>%s</h1><p>%s</p></body></html>';
      echo sprintf($html_template, $status_code, $status_name, $status_name, $custom_msg);
    }
    die();
  }  

}


/**
 *  BgbController: Base class which all web app controllers must extend.
 *
 *  @author Dani Dumitrescu <dtdumitrescu@gmail.com>
 *
 *  @method render() Render template and send to output
 *  @method string fetch() Render template and return string, without outputting
 * 
 */
class BgbController {

  protected $router;

  public function __construct($router) {
    $this->router = $router;
  }

 protected function render($template, $layout=null, $template_data=null, $layout_data=null) {
    echo $this->_render($template, $layout, $template_data, $layout_data);
  }

  protected function fetch($template, $layout=null, $template_data=null, $layout_data=null) {
    return $this->_render($template, $layout, $template_data, $layout_data);
  }

  protected function _render($template, $layout, $template_data, $layout_data) {
    $template_html = $this->_renderFile($template, BgbUtils::makeArray($template_data));
    if(!$layout) {
      return $template_html;
    }
    $layout_data = BgbUtils::makeArray($layout_data);
    $layout_data['_html'] = $template_html;
    return $this->_renderFile($layout, $layout_data);
  }

  protected function _renderFile($view_file, $data) {
    extract($data);
    ob_start();
    $view_file_path = $this->router->rootViewsPath . DIRECTORY_SEPARATOR . $view_file . '.php';
    require $view_file_path;
    return ob_get_clean();
  }

  public function isPost() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
  }

  protected function redirect($url) {
    header("Location: " . $url);
    exit;
  }

}
