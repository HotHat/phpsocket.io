<?php declare(strict_types=1);

require '../vendor/autoload.php';
require './FakeTcpConnection.php';

if (! class_exists('Protocols\SktIO')) {
    class_alias('PHPSocketIO\Engine\Protocols\SktIO', 'Protocols\SktIO');
}

class MyEio extends \PHPSocketIO\Event\Emitter {
    private $engine;
    private $eio;
    public function __construct()
    {

        $this->eio = new \PHPSocketIO\SocketIO();
        $this->engine = new \PHPSocketIO\Engine\Engine();
        $this->eio->bind($this->engine);
    }

    public function onRequest($request) {
        $res = new \PHPSocketIO\Engine\Protocols\Http\Response($request->connection);

        $this->engine->handleRequest($request, $res);
    }

    public function onWebSocketConnect($conn, $req, $res) {

    }

    public function onWebSocketMessage($conn, $message) {

    }
}

$myEio = new MyEio();


$tmp = <<<EOF
GET /socket.io/?EIO=4&transport=polling&t=Ogi5Z5L{{sid_mark}} HTTP/1.1
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7
Accept-Encoding: gzip, deflate
Accept-Language: zh-CN,zh;q=0.9,en;q=0.8,ja;q=0.7
Cache-Control: no-cache
Connection: keep-alive
Cookie: io={{cookie_mark}}
Host: hyj-v2.test
Pragma: no-cache
Upgrade-Insecure-Requests: 1
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36
EOF;

$raw = str_replace('{{sid_mark}}', '', $tmp);
$raw = str_replace('{{cookie_mark}}', '', $raw);

$connection = new FakeTcpConnection(1);

$req = new \PHPSocketIO\Engine\Protocols\Http\Request($connection, $raw);

ob_start();
$myEio->onRequest($req);

$content = ob_get_contents();

preg_match('/sid\":\"([^\"]+)\"/', $content, $match);

// var_dump($content);
// var_dump($match);
$sid = $match[1];
var_dump($sid);

$next = str_replace('{{sid_mark}}', '&sid='.$sid, $tmp);
$next = str_replace('{{cookie_mark}}', $sid, $next);
var_dump($next);

$connection = new FakeTcpConnection(2);
$req1 = new \PHPSocketIO\Engine\Protocols\Http\Request($connection, $next);
$myEio->onRequest($req1);



