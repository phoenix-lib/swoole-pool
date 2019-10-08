<?php

//error_reporting(E_ALL);

include 'vendor/autoload.php';
use Smf\ConnectionPool\ConnectionPool;
use Smf\ConnectionPool\ConnectionPoolTrait;
use Smf\ConnectionPool\Connectors\CoroutineSphinxConnector;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

//Main Settings

define('PORT', 8100);
define('ADDRESS', '0.0.0.0');

define('SPH_HOST', 'localhost');
define('SPH_HOST_WEB1', 'localhost');
define('SPH_HOST_WEB2', 'localhost');
define('SPH_HOST_WEB3', 'localhost');
define('SPH_HOST_WEB4', 'localhost');

define('MAX_SPH_POOLS', 4);

define('SPH_PORT', 9312);

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
	    'pid_file' => '/var/run/swoole-sphinx/main.pid',
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

	    // All Sphinx Connections: [8 workers * 4 = 32, 8 workers * 16 = 128] / Per One Pool

	    $sph_pool = new ConnectionPool(
		[
		    'minActive'         => 4,
		    'maxActive'         => 16,
		    'maxWaitTime'       => 0.1,
		    'maxIdleTime'       => 60,
		    'idleCheckInterval' => 10,
		],
		new CoroutineSphinxConnector,
		[
		    'host'     => SPH_HOST,
		    'port'     => SPH_PORT,
		]);
	    $sph_pool->init();

	    for ( $i = 1; $i <= MAX_SPH_POOLS; $i++ ) {

		${'sph_pool_web'.$i} = new ConnectionPool(
		    [
			'minActive'         => 4,
			'maxActive'         => 16,
			'maxWaitTime'       => 0.1,
			'maxIdleTime'       => 60,
			'idleCheckInterval' => 10,
		    ],
		    new CoroutineSphinxConnector,
		    [
			'host'     => constant('SPH_HOST_WEB' . $i),
			'port'     => SPH_PORT,
		    ]);
		${'sph_pool_web'.$i}->init();

	    }

	    $this->addConnectionPool('sph', $sph_pool);

	    for ( $i = 1; $i <= MAX_SPH_POOLS; $i++ ) {

		$this->addConnectionPool('sph_web' . $i, ${'sph_pool_web'.$i});

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

    $sph_pool = $server->getConnectionPool('sph');

    $sph_pools = [];

    for ( $i = 1; $i <= MAX_SPH_POOLS; $i++ ) {

	$sph_pools[$i] = $server->getConnectionPool('sph_web' . $i);

    }

    try {

	$sph = $sph_pool->borrow();

//	print_r($sph);

	$sph_array = array_column(Arrayable::toArray($sph), 'errCode');
	$sph_status = array('errCode' => $sph_array[0]);
	$sph_array = array_column(Arrayable::toArray($sph), 'errMsg');
	$sph_message = array('errMsg' => $sph_array[0]);

	if ( ($sph_status['errCode']) !== 0 ) {

	    $sph_pool->return($sph);
	    shuffle($sph_pools);

	    foreach ( $sph_pools as $sph_pool ) {

		$sph = $sph_pool->borrow();

		$sph_array = array_column(Arrayable::toArray($sph), 'errCode');
		$sph_status = array('errCode' => $sph_array[0]);
		$sph_array = array_column(Arrayable::toArray($sph), 'errMsg');
		$sph_message = array('errMsg' => $sph_array[0]);

		if ( ($sph_status['errCode']) !== 0 ) {

		    $sph_pool->return($sph);
		    continue;

		} else {

		    break;

		}

	    }

	    if ( ($sph_status['errCode']) !== 0 ) {

		$response->status(503);
		$sph_pool->return($sph);

		throw new RuntimeException('Error code: ' . $sph_status['errCode'] . 'Error message: ' . $sph_message['errMsg']);

	    }

	}

    } catch (Exception $e) {

	$isBorrow = false;
	shuffle($sph_pools);

	foreach ( $sph_pools as $sph_pool ) {

	    try {

		$sph = $sph_pool->borrow();

	    } catch (Exception $e) {

		$sph_pool->return($sph);
		continue;

	    }

	    if ( $sph ) {

		$isBorrow == true;
		break;

	    }

	}

	if ( ! $isBorrow ) {

	    $response->status(500);
	    $sph_pool->return($sph);
	    return;

	}

    }

    $data = $sph->Query('@(title,name) andrew', 'index_name');

    $sph_pool->return($sph);

    if ( ! $data ) {

	$response->status(404);
	return;

    }

//    print_r($data);
    $response->end(json_encode($data));

});

//Starting HTTP Server

$server->start();