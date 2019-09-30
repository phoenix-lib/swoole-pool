<?php

//error_reporting(E_ALL);

include 'vendor/autoload.php';
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\ConnectionPoolTrait;
use Smf\ConnectionPool\Connectors\CoroutineRedisConnector;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

//Main Settings

define('PORT', 8100);
define('ADDRESS', '0.0.0.0');

define('RED_HOST', 'localhost');
define('RED_HOST_WEB1', 'localhost');
define('RED_HOST_WEB2', 'localhost');
define('RED_HOST_WEB3', 'localhost');
define('RED_HOST_WEB4', 'localhost');

define('MAX_RED_POOLS', 4);

define('RED_PORT', 6379);
define('RED_DATABASE_INDEX', 0);

//General Router Class

class Router {

    protected static $_routes = [];
    /**
     * @param string $method
     * @param string $url
     * @param callable $callback
     */

    public static function addRoute(string $method, string $url, callable $callback) : void {

	foreach( self::$_routes as $route ) {

	    if ( $route['method'] === strtolower($method) && $route['url'] === $url ) {

		print("Route $method $url already exists");
		return;

	    }

	}

	self::$_routes[] = [
	    'method' => strtolower($method),
	    'url' => $url,
	    'callback' => $callback,
	];

    }

    public static function dispatch(swoole_http_request &$request,swoole_http_response &$response) {

	$method = strtolower($request->server['request_method']);
	$uri =  $request->server['request_uri'];

	foreach ( self::$_routes as $route ) {

	    if ( $route['method'] !== $method ) {

		continue;

	    }

	    $regularStart = '/^';
	    $regularEnd = '$/i';
	    $url = str_replace('/', '\/', $route['url']);
	    $regular = $regularStart . $url . $regularEnd;

	    if ( preg_match($regular, $uri) ) {

		$route['callback']($request, $response);
		return true;

	    }

	}
	
	return false;

    }

}

//General HTTP Class

class HttpServer {

    use ConnectionPoolTrait;

    protected $swoole;

    public function __construct(string $host, int $port) {

	$this->swoole = new Server($host, $port, SWOOLE_PROCESS);

	$this->setDefault();
	$this->bindWorkerEvents();
	$this->bindHttpEvent();

    }

    //Main Performance Settings

    protected function setDefault() {

	$this->swoole->set([

	    'worker_num' => 8,
	    'daemonize' => FALSE,
	    'max_conn' => 1024,
	    'pid_file' => '/var/run/swoole-redis/main.pid',
	    'dispatch_mode' => 2,
	    'open_tcp_nodelay' => TRUE,
	    'reload_async' => TRUE,
	    'enable_reuse_port' => TRUE,
	    'enable_coroutine' => TRUE

	]);

    }

    //Binding HTTP and HTTP Router

    protected function bindHttpEvent()
    {

	$this->swoole->on('Request', function (Request $request, Response $response) {

	    if (!Router::dispatch($request, $response)){

		$response->status(421);

	    }
			
	});

    }

    //Configuring Databases Pools

    protected function bindWorkerEvents()
    {

	$createPools = function () {

	    // All Redis Connections: [8 workers * 4 = 32, 8 workers * 16 = 128] / Per One Pool

	    $red_pool = new ConnectionPool(
		[
		    'minActive'         => 4,
		    'maxActive'         => 16,
		    'maxWaitTime'       => 0.1,
		    'maxIdleTime'       => 60,
		    'idleCheckInterval' => 10,
		],
		new CoroutineRedisConnector,
		[
		    'host'     => RED_HOST,
		    'port'     => RED_PORT,
		    'database' => RED_DATABASE_INDEX,
		    'password' => null,
		]);
	    $red_pool->init();

	    for ( $i = 1; $i <= MAX_RED_POOLS; $i++ ) {

		${'red_pool_web'.$i} = new ConnectionPool(
		    [
			'minActive'         => 4,
			'maxActive'         => 16,
			'maxWaitTime'       => 0.1,
			'maxIdleTime'       => 60,
			'idleCheckInterval' => 10,
		    ],
		    new CoroutineRedisConnector,
		    [
			'host'     => constant('RED_HOST_WEB' . $i),
			'port'     => RED_PORT,
			'database' => RED_DATABASE_INDEX,
			'password' => null,
		    ]);
		${'red_pool_web'.$i}->init();

	    }

	    $this->addConnectionPool('red', $red_pool);

	    for ( $i = 1; $i <= MAX_RED_POOLS; $i++ ) {

		$this->addConnectionPool('red_web' . $i, ${'red_pool_web'.$i});

	    }

	};

	$closePools = function () {

	    $this->closeConnectionPools();

	};

	//System Interception

	$this->swoole->on('WorkerStart', $createPools);
	$this->swoole->on('WorkerStop', $closePools);
	$this->swoole->on('WorkerError', $closePools);

    }

    public function start() {

	$this->swoole->start();

    }

}

//Adding New HTTP Server

$server = new HttpServer(ADDRESS, PORT);

//Multi Object to Array Converter

class Arrayable {

    public static function toArray($object) {

        if ( is_null($object) ) {
            $object = null;
        }

        $original_object = $object;

        if ( is_object($object) ) {
            $object = (array) $object;
        }

        if ( is_array($object) ) {

            $converted = [];

            foreach ( $object as $key => $val ) {

                if ( (!is_object($key))) {

                    $converted[$key] = self::toArray($val);

                } else {

                    $key = str_replace(get_class($original_object), '', $key);
                    $converted[$key] = self::toArray($val);

                }

            }

        } else {

            $converted = $object;

        }

        return $converted;

    }

}

//Creating Routes And Main Logic

Router::addRoute('GET', '/.*', function ($request, $response) use (&$server){

    $red_pool = $server->getConnectionPool('red');

    $red_pools = [];

    for ( $i = 1; $i <= MAX_RED_POOLS; $i++ ) {

	$red_pools[$i] = $server->getConnectionPool('red_web' . $i);

    }

    try {

	$red = $red_pool->borrow();
	$red_status = (array) $red;

	if ( ($red_status['errCode']) !== 0 ) {

	    $red_pool->return($red);
	    shuffle($red_pools);

	    foreach ( $red_pools as $red_pool ) {

		$red = $red_pool->borrow();
		$red_status = (array) $red;

		if ( ($red_status['errCode']) !== 0 ) {

		    $red_pool->return($red);
		    continue;

		} else {

		    break;

		}

	    }

	    if ( ($red_status['errCode']) !== 0 ) {

		$response->status(503);
		$red_pool->return($red);

		throw new RuntimeException('Error code: ' . $red_status['errCode'] . 'Error message: ' . $red_status['errMsg']);

	    }

	}

    } catch (Exception $e) {

	$isBorrow = false;
	shuffle($red_pools);

	foreach ( $red_pools as $red_pool ) {

	    try {

		$red = $red_pool->borrow();

	    } catch (Exception $e) {

		$red_pool->return($red);
		continue;

	    }

	    if ( $red ) {

		$isBorrow == true;
		break;

	    }

	}

	if ( ! $isBorrow ) {

	    $response->status(500);
	    $red_pool->return($red);
	    return;

	}

    }

    $red->set('greetings','Hello World');
    $data = $red->get('greetings');

    $red_pool->return($red);

    if ( ! $data ) {

	$response->status(404);
	return;

    }

//    print_r($data);
    $response->end($data);

});

//Starting HTTP Server

$server->start();
