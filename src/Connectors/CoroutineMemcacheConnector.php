<?php

namespace Smf\ConnectionPool\Connectors;

class CoroutineMemcacheConnector implements ConnectorInterface
{

    public function connect(array $config)
    {

        $connection = new Memcache\Memcache;
	$ret = $connection->connect($config['host'], $config['port']);

        if ($ret === false) {
            throw new \RuntimeException(sprintf('Failed to connect Memcached server: [%s] %s', $connection->errCode, $connection->errMsg));
        }
        return $connection;
    }

    public function disconnect($connection)
    {
        /**@var Memcache $connection */
    }

    public function isConnected($connection): bool
    {
        /**@var Memcache $connection */
	  return true;
    }

    public function reset($connection, array $config)
    {
        /**@var Memcache $connection */
    }

    public function validate($connection): bool
    {
        return $connection instanceof Memcache\Memcache;
    }
}
