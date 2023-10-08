<?php

namespace PHPSocketIO;

use Exception;
use PHPSocketIO\Parser\Decoder;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Parser\Parser;

class Client
{
    public $server = null;
    public $engineSocket = null;
    public $encoder = null;
    public $decoder = null;
    public $id = null;
    public $request = null;
    public $nsps = [];
    public $connectBuffer = [];
    /**
     * @var array|mixed|null
     */
    public $sockets;

    public function __construct($server, $engineSocket)
    {
        $this->server = $server;
        $this->engineSocket = $engineSocket;
        $this->encoder = new Encoder();
        $this->decoder = new Decoder();
        $this->id = $engineSocket->id;
        $this->request = $engineSocket->request;
        $this->setup();
        Debug::debug('Client __construct');
    }

    public function __destruct()
    {
        Debug::debug('Client __destruct');
    }

    /**
     * Sets up event listeners.
     *
     * @api private
     */

    public function setup()
    {
        $this->decoder->on('decoded', [$this, 'ondecoded']);
        $this->engineSocket->on('data', [$this, 'ondata']);
        $this->engineSocket->on('error', [$this, 'onerror']);
        $this->engineSocket->on('close', [$this, 'onclose']);
    }

    /**
     * Connects a client to a namespace.
     *
     * @param {String} namespace name
     * @api   private
     */

    public function connect($name)
    {
        if (! isset($this->server->nsps[$name])) {
            $this->packet(['type' => Parser::ERROR, 'nsp' => $name, 'data' => 'Invalid namespace']);
            return;
        }
        $nsp = $this->server->of($name);
        if ('/' !== $name && ! isset($this->nsps['/'])) {
            $this->connectBuffer[$name] = $name;
            return;
        }
        $nsp->add($this, $nsp, [$this, 'nspAdd']);
    }

    public function nspAdd($socket, $nsp)
    {
        $this->sockets[$socket->id] = $socket;
        $this->nsps[$nsp->name] = $socket;
        if ('/' === $nsp->name && $this->connectBuffer) {
            foreach ($this->connectBuffer as $name) {
                $this->connect($name);
            }
            $this->connectBuffer = [];
        }
    }

    /**
     * Disconnects from all namespaces and closes transport.
     *
     * @api private
     */
    public function disconnect()
    {
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        $this->sockets = [];
        $this->close();
    }

    /**
     * Removes a socket. Called by each `Socket`.
     *
     * @api private
     */
    public function remove($socket)
    {
        if (isset($this->sockets[$socket->id])) {
            $nsp = $this->sockets[$socket->id]->nsp->name;
            unset($this->sockets[$socket->id]);
            unset($this->nsps[$nsp]);
        }
    }

    /**
     * Closes the underlying connection.
     *
     * @api private
     */
    public function close()
    {
        if (empty($this->engineSocket)) {
            return;
        }
        if ('open' === $this->engineSocket->readyState) {
            $this->engineSocket->close();
            $this->onclose('forced server close');
        }
    }

    /**
     * Writes a packet to the transport.
     *
     * @param {Object} packet object
     * @param {Object} options
     * @api   private
     */
    public function packet($packet, $preEncoded = false, $volatile = false)
    {
        if (! empty($this->engineSocket) && 'open' === $this->engineSocket->readyState) {
            if (! $preEncoded) {
                // not broadcasting, need to encode
                $encodedPackets = $this->encoder->encode($packet);
                $this->writeToEngine($encodedPackets, $volatile);
            } else { // a broadcast pre-encodes a packet
                $this->writeToEngine($packet);
            }
        }
    }

    public function writeToEngine($encodedPackets, $volatile = false)
    {
        if ($volatile) {
            echo new Exception('volatile');
        }
        if ($volatile && ! $this->engineSocket->transport->writable) {
            return;
        }
        if (isset($encodedPackets['nsp'])) {
            unset($encodedPackets['nsp']);
        }
        foreach ($encodedPackets as $packet) {
            $this->engineSocket->write($packet);
        }
    }

    /**
     * Called with incoming transport data.
     *
     * @api private
     */
    public function ondata($data)
    {
        try {
            // todo chek '2["chat message","2"]' . "\0" . ''
            $this->decoder->add(trim($data));
        } catch (Exception $e) {
            $this->onerror($e);
        }
    }

    /**
     * Called when parser fully decodes a packet.
     *
     * @api private
     */
    public function ondecoded($packet)
    {
        if (Parser::CONNECT == $packet['type']) {
            $this->connect($packet['nsp']);
        } else {
            if (isset($this->nsps[$packet['nsp']])) {
                $this->nsps[$packet['nsp']]->onpacket($packet);
            }
        }
    }

    /**
     * Handles an error.
     *
     * @param {Objcet} error object
     * @api   private
     */
    public function onerror($err)
    {
        foreach ($this->sockets as $socket) {
            $socket->onerror($err);
        }
        $this->onclose('client error');
    }

    /**
     * Called upon transport close.
     *
     * @param {String} reason
     * @api   private
     */
    public function onclose($reason)
    {
        if (empty($this->engineSocket)) {
            return;
        }
        // ignore a potential subsequent `close` event
        $this->destroy();

        // `nsps` and `sockets` are cleaned up seamlessly
        foreach ($this->sockets as $socket) {
            $socket->onclose($reason);
        }
        $this->sockets = null;
    }

    /**
     * Cleans up event listeners.
     *
     * @api private
     */
    public function destroy()
    {
        if (! $this->engineSocket) {
            return;
        }
        $this->engineSocket->removeAllListeners();
        $this->decoder->removeAllListeners();
        $this->encoder->removeAllListeners();
        $this->server = $this->engineSocket = null;
        $this->encoder = $this->decoder =  null;
        $this->request = $this->nsps = null;
    }
}
