<?php

error_reporting(E_ALL);

include 'vendor/autoload.php';
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\ConnectionPoolTrait;
use Smf\ConnectionPool\Connectors\CoroutineMemcacheConnector;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

//Main Settings

define('PORT', 8100);
define('ADDRESS', '0.0.0.0');

define('MEM_HOST', 'localhost');
define('MEM_HOST_WEB1', 'localhost');
define('MEM_HOST_WEB2', 'localhost');
define('MEM_HOST_WEB3', 'localhost');
define('MEM_HOST_WEB4', 'localhost');

define('MAX_MEM_POOLS', 4);

define('MEM_PORT', 11211);

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
	    'pid_file' => '/var/run/swoole-memcached/main.pid',
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

	    // All Memcached Connections: [8 workers * 4 = 32, 8 workers * 16 = 128] / Per One Pool

	    $mem_pool = new ConnectionPool(
		[
		    'minActive'         => 4,
		    'maxActive'         => 16,
		    'maxWaitTime'       => 0.1,
		    'maxIdleTime'       => 60,
		    'idleCheckInterval' => 10,
		],
		new CoroutineMemcacheConnector,
		[
		    'host'     => MEM_HOST,
		    'port'     => MEM_PORT,
		]);
	    $mem_pool->init();

	    for ( $i = 1; $i <= MAX_MEM_POOLS; $i++ ) {

		${'mem_pool_web'.$i} = new ConnectionPool(
		    [
			'minActive'         => 4,
			'maxActive'         => 16,
			'maxWaitTime'       => 0.1,
			'maxIdleTime'       => 60,
			'idleCheckInterval' => 10,
		    ],
		    new CoroutineMemcacheConnector,
		    [
			'host'     => constant('MEM_HOST_WEB' . $i),
			'port'     => MEM_PORT,
		    ]);
		${'mem_pool_web'.$i}->init();

	    }

	    $this->addConnectionPool('mem', $mem_pool);

	    for ( $i = 1; $i <= MAX_MEM_POOLS; $i++ ) {

		$this->addConnectionPool('mem_web' . $i, ${'mem_pool_web'.$i});

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

    $mem_pool = $server->getConnectionPool('mem');

    $mem_pools = [];

    for ( $i = 1; $i <= MAX_MEM_POOLS; $i++ ) {

	$mem_pools[$i] = $server->getConnectionPool('mem_web' . $i);

    }

    try {

	$mem = $mem_pool->borrow();

//	print_r($mem);

	$mem_array = array_column(Arrayable::toArray($mem), 'errCode');
	$mem_status = array('errCode' => $mem_array[0]);
	$mem_array = array_column(Arrayable::toArray($mem), 'errMsg');
	$mem_message = array('errMsg' => $mem_array[0]);

	if ( ($mem_status['errCode']) !== 0 ) {

	    $mem_pool->return($mem);
	    shuffle($mem_pools);

	    foreach ( $mem_pools as $mem_pool ) {

		$mem = $mem_pool->borrow();

		$mem_array = array_column(Arrayable::toArray($mem), 'errCode');
		$mem_status = array('errCode' => $mem_array[0]);
		$mem_array = array_column(Arrayable::toArray($mem), 'errMsg');
		$mem_message = array('errMsg' => $mem_array[0]);

		if ( ($mem_status['errCode']) !== 0 ) {

		    $mem_pool->return($mem);
		    continue;

		} else {

		    break;

		}

	    }

	    if ( ($mem_status['errCode']) !== 0 ) {

		$response->status(503);
		$mem_pool->return($mem);

		throw new RuntimeException('Error code: ' . $mem_status['errCode'] . 'Error message: ' . $mem_message['errMsg']);

	    }

	}

    } catch (Exception $e) {

	$isBorrow = false;
	shuffle($mem_pools);

	foreach ( $mem_pools as $mem_pool ) {

	    try {

		$mem = $mem_pool->borrow();

	    } catch (Exception $e) {

		$mem_pool->return($mem);
		continue;

	    }

	    if ( $mem ) {

		$isBorrow == true;
		break;

	    }

	}

	if ( ! $isBorrow ) {

	    $response->status(500);
	    $mem_pool->return($mem);
	    return;

	}

    }

    $mem->set('greeting', 'Hello World');
    $data = $mem->get('greeting');

    $mem_pool->return($mem);

    if ( ! $data ) {

	$response->status(404);
	return;

    }

//    print_r($data);
    $response->end($data);

});

//Starting HTTP Server

$server->start();