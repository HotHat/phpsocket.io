<?php declare(strict_types=1);

use Workerman\Connection\TcpConnection;

class Worker
{
    public function log($data)
    {
        // var_dump('-------------------log start----------------');
        // var_dump($data);
        // var_dump('-------------------log end----------------');
    }
}

class FakeTcpConnection extends TcpConnection
{
    public $id;

    public function __construct($id)
    {
        $this->id = $id;
        $this->worker = new Worker();

    }

    public function send($data, $raw = false)
    {

        // var_dump('connection id: ' . $this->id);
        echo $data;
        return $data;
    }
}
