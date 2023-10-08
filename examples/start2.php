<?php declare(strict_types=1);

require '../vendor/autoload.php';

use PHPSocketIO\Engine\Engine;
use PHPSocketIO\SocketIO;
use Workerman\Worker;

if (! class_exists('Protocols\SktIO')) {
    class_alias('PHPSocketIO\Engine\Protocols\SktIO', 'Protocols\SktIO');
}

$worker = new Worker('SktIO://0.0.0.0:9702', []);
$worker->name = 'PHPSocketIO';


$eio = new SocketIO();
$engine = new Engine();
$engine->attach($worker);
$eio->bind($engine);

$eio->on('connection', function ($socket) {
    $socket->on('broadcast', function ($data) use ($socket) {
        $this->onBroadcast($socket, $data);
    });

    $socket->on('message', function ($data) use ($socket) {
        $socket->emit('message', '转发信息: ' . json_encode($data));
    });
});

Worker::runAll();
