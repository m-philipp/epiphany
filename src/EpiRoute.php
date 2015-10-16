<?php
/**
 * EpiRoute master file
 *
 * This contains the EpiRoute class as wel as the EpiException abstract class
 * @author  Jaisen Mathai <jaisen@jmathai.com>
 * @version 1.0
 * @package EpiRoute
 */

/**
 * This is the EpiRoute class.
 * @name    EpiRoute
 * @author  Jaisen Mathai <jaisen@jmathai.com>
 * @final
 */
class EpiRoute
{
    private static $instance;
    private $routes = array();
    private $regexes = array();
    private $route = null;
    private $httpMethod = null;
    private $preproc = null;
    private $notFound = null;
    const routeKey = '__route__';
    const httpGet = 'GET';
    const httpPost = 'POST';
    const httpPut = 'PUT';
    const httpDelete = 'DELETE';

    /**
     * get('/', 'function');
     * @name  get
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @param mixed $callback
     */
    public function get($route, $callback, $isApi = false)
    {
        $this->addRoute($route, $callback, self::httpGet, $isApi);
    }

    /**
     * post('/', 'function');
     * @name  post
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @param mixed $callback
     */
    public function post($route, $callback, $isApi = false)
    {
        $this->addRoute($route, $callback, self::httpPost, $isApi);
    }

    /**
     * put('/', 'function');
     * @name  put
     * @author  Sandro Meier <sandro.meier@fidelisfactory.ch>
     * @param string $route
     * @param mixed $callback
     */
    public function put($route, $callback, $isApi = false)
    {
        $this->addRoute($route, $callback, self::httpPut, $isApi);
    }

    /**
     * delete('/', 'function');
     * @name  delete
     * @author  Sandro Meier <sandro.meier@fidelisfactory.ch>
     * @param string $route
     * @param mixed $callback
     */
    public function delete($route, $callback, $isApi = false)
    {
        $this->addRoute($route, $callback, self::httpDelete, $isApi);
    }

    /**
     * NOT YET IMPLEMENTED
     * request('/', 'function', array(EpiRoute::httpGet, EpiRoute::httpPost));
     * @name  request
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @param mixed $callback
     */
    /*public function request($route, $callback, $httpMethods = array(self::httpGet, self::httpPost))
      {
      }*/

    /**
     * @name  loadDir
     * @author  Martin Philipp <mail@martin-philipp.de>
     * @param string $dir
     * @static method
     */
    public function loadDir($dir)
    {
        //$realDir = Epi::getPath('config') . "/{$dir}";
        $realDir = $dir;

        $files = scandir($realDir);

        foreach ($files as $file) {
            if ($file != "." and $file != "..")
                $this->load($dir . DIRECTORY_SEPARATOR . $file);
        }

    }


    /**
     * load('/path/to/file');
     * @name  load
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $file
     */
    public function load($file)
    {

        // $file = Epi::getPath('config') . "/{$file}";

        if (!file_exists($file)) {
            EpiException::raise(new EpiException("Config file ({$file}) does not exist"));
            break; // need to simulate same behavior if exceptions are turned off
        }

        $parsed_array = parse_ini_file($file, true);
        foreach ($parsed_array as $route) {
            $method = strtolower($route['method']);
            if (isset($route['class']) && isset($route['function']))
                $this->$method($route['path'], array($route['class'], $route['function']));
            elseif (isset($route['function']))
                $this->$method($route['path'], $route['function']);
        }
    }

    /**
     * EpiRoute::set_preprocessing($class, $function);
     * @name  preprocess
     * @author  Martin Zittel <martin.zittel@gmail.com>
     * @param string $function
     * @param string $class = null
     * @method set_preprocessing
     * @static method
     */
    public function setPreprocessor($callback)
    {
        $this->preproc = $callback;
    }

    private function preprocess()
    {
        if ($this->callbackExists($this->preproc)) {
            call_user_func($this->preproc, $this->route, $this->httpMethod, $this->isApiCall());

        }
    }

    public function isApiCall()
    {

        foreach ($this->regexes as $ind => $regex) {
            if (preg_match($regex, $this->route, $arguments)) {
                array_shift($arguments);
                $def = $this->routes[$ind];

                if (Epi::getSetting('debug'))
                    getDebug()->addMessage(__CLASS__, sprintf('Matched %s : %s : %s : %s', $this->httpMethod, $this->route, json_encode($def['callback']), json_encode($arguments)));

                return $def['postprocess'];
            }
        }
        return false;

    }

    public function setNotFound($callback)
    {
        $this->notFound = $callback;
    }

    private function notFound()
    {
        header($_SERVER["SERVER_PROTOCOL"] . " 404 Not Found");
        if ($this->callbackExists($this->notFound)) {
            call_user_func($this->notFound);

            getLogger()->warn("Called notFound() because a Route for {$this->route} wasn't found.");
            exit;
        }

    }

    private function setRouteVar($route = false, $httpMethod = null)
    {
        if ($route === false)
            $this->route = isset($_GET[self::routeKey]) ? $_GET[self::routeKey] : '/';

        if ($httpMethod === null)
            $this->httpMethod = $_SERVER['REQUEST_METHOD'];

    }

    /**
     * EpiRoute::run($_GET['__route__'], $_['routes']);
     * @name  run
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @param array $routes
     * @method run
     * @static method
     */
    public function run($route = false, $httpMethod = null)
    {
        $this->setRouteVar($route, $httpMethod);

        $this->preprocess();

        $routeDef = $this->getRoute();


        $response = call_user_func_array($routeDef['callback'], $routeDef['args']);

        if (!$routeDef['postprocess'])
            return $response;
        else {
            // Only echo the response if it's not null.
            if (!is_null($response)) {
                $response = json_encode($response, JSON_NUMERIC_CHECK);
                if (isset($_GET['callback']))
                    $response = "{$_GET['callback']}($response)";
                else
                    header('Content-Type: application/json');

                // TODO UTF-8 ?? -> header("Content-Type: application/json; charset=utf-8");
                // TODO remove callback aka JSONP

                header('Content-Length:' . strlen($response));
                echo $response;
            }
        }
    }

    /**
     * EpiRoute::getRoute($route);
     * @name  getRoute
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @method getRoute
     * @static method
     */
    private function getRoute()
    {

        foreach ($this->regexes as $ind => $regex) {
            if (preg_match($regex, $this->route, $arguments)) {
                array_shift($arguments);
                $def = $this->routes[$ind];
                if ($this->httpMethod != $def['httpMethod']) {
                    continue;
                } else if ($this->callbackExists($def['callback'])) {
                    if (Epi::getSetting('debug'))
                        getDebug()->addMessage(__CLASS__, sprintf('Matched %s : %s : %s : %s', $this->httpMethod, $this->route, json_encode($def['callback']), json_encode($arguments)));
                    return array('callback' => $def['callback'], 'args' => $arguments, 'postprocess' => $def['postprocess']);
                }

                EpiException::raise(new EpiException('Could not call ' . json_encode($def) . " for route {$regex}"));
            }
        }


        $this->notFound();
        EpiException::raise(new EpiRouteException("Could not find route {$this->route} from {$_SERVER['REQUEST_URI']}"));
    }

    /**
     * EpiRoute::redirect($url);
     * @name  redirect
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $url
     * @method redirect
     * @static method
     */
    public function redirect($url, $code = null, $offDomain = false)
    {
        $continue = !empty($url);
        if ($offDomain === false && preg_match('#^https?://#', $url))
            $continue = false;

        if ($continue) {
            if ($code != null && (int)$code == $code)
                header("Status: {$code}");
            header("Location: {$url}");
            die();
        }
        EpiException::raise(new EpiException("Redirect to {$url} failed"));
    }

    public function route()
    {
        return $this->route;
    }

    /*
       * EpiRoute::getInstance
       */
    public static function getInstance()
    {
        if (self::$instance)
            return self::$instance;

        self::$instance = new EpiRoute;
        return self::$instance;
    }

    /**
     * addRoute('/', 'function', 'GET');
     * @name  addRoute
     * @author  Jaisen Mathai <jaisen@jmathai.com>
     * @param string $route
     * @param mixed $callback
     * @param mixed $method
     * @param string $callback
     */
    private function addRoute($route, $callback, $method, $postprocess = false)
    {
        $this->routes[] = array('httpMethod' => $method, 'path' => $route, 'callback' => $callback, 'postprocess' => $postprocess);
        $this->regexes[] = "#^{$route}[(/)]?\$#";
        if (Epi::getSetting('debug'))
            getDebug()->addMessage(__CLASS__, sprintf('Found %s : %s : %s', $method, $route, json_encode($callback)));
    }

    private function callbackExists($callable)
    {
        if (is_array($callable) && method_exists($callable[0], $callable[1])) {
            return true;
        } else if (is_string($callable) && function_exists($callable)) {
            return true;
        }

        return false;
    }
}

function getRoute()
{
    return EpiRoute::getInstance();
}
