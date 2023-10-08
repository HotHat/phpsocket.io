<?php

namespace PHPSocketIO\Engine;

use Exception;
use PHPSocketIO\Engine\Protocols\Http\Request;
use PHPSocketIO\Engine\Protocols\Http\Response;
use PHPSocketIO\Engine\Transports\PollingJsonp;
use PHPSocketIO\Engine\Transports\PollingXHR;
use PHPSocketIO\Engine\Transports\WebSocket;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

class Engine extends Emitter
{
    public $server;
    public $pingTimeout = 60;
    public $pingInterval = 25;
    public $upgradeTimeout = 5;
    public $transports = [];
    public $allowUpgrades = [];
    public $allowRequest = [];
    public $clients = [];
    public $origins = '*:*';
    public static $allowTransports = [
        'polling' => 'polling',
        'websocket' => 'websocket'
    ];

    public static $errorMessages = [
        'Transport unknown',
        'Session ID unknown',
        'Bad handshake method',
        'Bad request'
    ];

    const ERROR_UNKNOWN_TRANSPORT = 0;

    const ERROR_UNKNOWN_SID = 1;

    const ERROR_BAD_HANDSHAKE_METHOD = 2;

    const ERROR_BAD_REQUEST = 3;

    public function __construct($opts = [])
    {
        $opsMap = [
            'pingTimeout',
            'pingInterval',
            'upgradeTimeout',
            'transports',
            'allowUpgrades',
            'allowRequest'
        ];
        foreach ($opsMap as $key) {
            if (isset($opts[$key])) {
                $this->$key = $opts[$key];
            }
        }
        Debug::debug('Engine __construct');
    }

    public function __destruct()
    {
        Debug::debug('Engine __destruct');
    }

    public function handleRequest($req, $res)
    {
        // $this->prepare($req);
        // $req->res = $res;
        $this->verify($req, $res, false, [$this, 'dealRequest']);
    }

    /**
     * @throws Exception
     */
    public function dealRequest($req, $res)
    {
        if (isset($req->_query['sid'])) {
            $this->clients[$req->_query['sid']]->transport->onRequest($req, $res);
        } else {
            $this->handshake($req->_query['transport'], $req, $res);
        }
    }

    protected function sendErrorMessage($req, $res, $code)
    {
        $headers = ['Content-Type' => 'application/json'];
        if (isset($req->headers['origin'])) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Origin'] = $req->headers['origin'];
        } else {
            $headers['Access-Control-Allow-Origin'] = '*';
        }

        $res->writeHead(403, '', $headers);
        $res->end(
            json_encode(
                [
                    'code' => $code,
                    'message' => self::$errorMessages[$code] ?? $code
                ]
            )
        );
    }

    protected function verify($req, $res, $upgrade, $fn)
    {
        if (! isset($req->_query['transport']) || ! isset(self::$allowTransports[$req->_query['transport']])) {
            self::sendErrorMessage($req, $res, self::ERROR_UNKNOWN_TRANSPORT);
            return false;
        }
        $transport = $req->_query['transport'];
        $sid = $req->_query['sid'] ?? '';
        if ($sid) {
            if (! isset($this->clients[$sid])) {
                self::sendErrorMessage($req, $res, self::ERROR_UNKNOWN_SID);
                return false;
            }
            if (! $upgrade && $this->clients[$sid]->transport->name !== $transport) {
                self::sendErrorMessage($req, $res, self::ERROR_BAD_REQUEST);
                return false;
            }
            //
            return $fn($req, $res);
        } else {
            if ('GET' !== $req->method) {
                self::sendErrorMessage($req, $res, self::ERROR_BAD_HANDSHAKE_METHOD);
                return false;
            }

            return $this->checkRequest($req, $res, $fn);
        }

        // call_user_func($fn, null, true, $req, $res);
    }

    public function checkRequest($req, $res, $fn)
    {
        if ($this->origins === "*:*" || empty($this->origins)) {
            return $fn($req, $res);
            // return call_user_func($fn, null, true, $req, $res);
        }
        $origin = null;
        if (isset($req->headers['origin'])) {
            $origin = $req->headers['origin'];
        } elseif (isset($req->headers['referer'])) {
            $origin = $req->headers['referer'];
        }

        // file:// URLs produce a null Origin which can't be authorized via echo-back
        if ('null' === $origin || null === $origin) {
            return $fn($req, $res);
            // return call_user_func($fn, null, true, $req, $res);
        }

        if ($origin) {
            $parts = parse_url($origin);
            $defaultPort = 'https:' === $parts['scheme'] ? 443 : 80;
            $parts['port'] = $parts['port'] ?? $defaultPort;
            $allowedOrigins = explode(' ', $this->origins);
            foreach ($allowedOrigins as $allowOrigin) {
                $ok =
                    $allowOrigin === $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] ||
                    $allowOrigin === $parts['scheme'] . '://' . $parts['host'] ||
                    $allowOrigin === $parts['scheme'] . '://' . $parts['host'] . ':*' ||
                    $allowOrigin === '*:' . $parts['port'];
                if ($ok) {
                    // 只需要有一个白名单通过，则都通过
                    return $fn($req, $res);
                    // return call_user_func($fn, null, true, $req, $res);
                }
            }
        }

        self::sendErrorMessage($req, $res, null);
        return false;
        // call_user_func($fn, null, false, $req, $res);
    }

    /**
     * @throws Exception
     */
    public function handshake($trans, $req, $res)
    {
        $sid = bin2hex(
            pack('d', microtime(true)) .
            pack('N', function_exists('random_int') ?
                random_int(1, 100000000) : rand(1, 100000000))
        );

        if ($trans == 'websocket') {
            $transport = new WebSocket($req->connection);
            // $transport = '\\PHPSocketIO\\Engine\\Transports\\WebSocket';
        } elseif (isset($req->_query['j'])) {
            $transport = new PollingJsonp($req);
            // $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingJsonp';
        } else {
            $transport = new PollingXHR();
            // $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingXHR';
        }

        // $transport = new $transport($req, $res);

        $transport->supportsBinary = ! isset($req->_query['b64']);
        $transport->writable = true;
        $transport->emit('drain');

        $transport->onRequest($req, $res);

        $socket = new Socket($sid, $this, $transport, $req);


        $this->clients[$sid] = $socket;
        $socket->once('close', [$this, 'onSocketClose']);
        $this->emit('connection', $socket);
    }

    public function onSocketClose($id)
    {
        unset($this->clients[$id]);
    }

    public function attach($worker)
    {
        $this->server = $worker;
        $worker->onConnect = [$this, 'onConnect'];
        $worker->onMessage = [$this, 'onHttpRequest'];
    }

    /**
     * http response
     * @param $connection
     * @param $request
     * @return void
     */
    public function onHttpRequest($connection, $request) {
        $res = new Response($connection);
        $this->handleRequest($request, $res);
    }

    public function onWebsocketMessage($sid, $message) {
        if (! isset($this->clients[$req->_query['sid']])) {
            // self::sendErrorMessage($this->req, $this->res, 'upgrade attempt for closed client');
            echo 'not found sid';
            return;
        }

        $this->clients[$sid]->transport->onMessage(null, $message);
    }

    public function onConnect($connection)
    {
        // $connection->onRequest = [$this, 'handleRequest'];
        $connection->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        // clean
        $connection->onClose = function ($connection) {
            // if (! empty($connection->httpRequest)) {
            //     $connection->httpRequest->destroy();
            //     $connection->httpRequest = null;
            // }
            // if (! empty($connection->httpResponse)) {
            //     $connection->httpResponse->destroy();
            //     $connection->httpResponse = null;
            // }
            // if (! empty($connection->onRequest)) {
            //     $connection->onRequest = null;
            // }
            if (! empty($connection->onWebSocketConnect)) {
                $connection->onWebSocketConnect = null;
            }
        };
    }

    public function onWebSocketConnect($req, $res)
    {
        // $this->prepare($req);
        $this->verify($req, $res, true, [$this, 'dealWebSocketConnect']);
    }

    /**
     * @throws Exception
     */
    public function dealWebSocketConnect($req, $res)
    {
        if (isset($req->_query['sid'])) {
            if (! isset($this->clients[$req->_query['sid']])) {
                self::sendErrorMessage($req, $res, 'upgrade attempt for closed client');
                return;
            }
            $client = $this->clients[$req->_query['sid']];
            if ($client->upgrading) {
                self::sendErrorMessage($req, $res, 'transport has already been trying to upgrade');
                return;
            }
            if ($client->upgraded) {
                self::sendErrorMessage($req, $res, 'transport had already been upgraded');
                return;
            }
            $transport = new WebSocket($req->connection);
            $client->maybeUpgrade($transport);
        } else {
            $this->handshake($req->_query['transport'], $req, $res);
        }
    }
}
