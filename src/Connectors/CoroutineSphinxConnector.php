<?php

namespace Smf\ConnectionPool\Connectors;

class CoroutineSphinxConnector implements ConnectorInterface
{

    public function connect(array $config)
    {
        $connection = new Sphinx\SphinxClient;
	$connection->SetServer($config['host'], $config['port']);
	$ret = $connection->Open();

        if ($ret === false) {
            throw new \RuntimeException(sprintf('Failed to connect Sphinx server: [%s] %s', $connection->_connerror, $connection->_error));
        }
        return $connection;
    }

    public function disconnect($connection)
    {
        /**@var Sphinx $connection */
        $connection->Close();
    }

    public function isConnected($connection): bool
    {
        /**@var Sphinx $connection */
        return $connection->IsConnectError();
    }

    public function reset($connection, array $config)
    {
        /**@var Sphinx $connection */
    }

    public function validate($connection): bool
    {
        return $connection instanceof Sphinx\SphinxClient;
    }
}
