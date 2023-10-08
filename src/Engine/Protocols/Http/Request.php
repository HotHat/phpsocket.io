<?php

namespace PHPSocketIO\Engine\Protocols\Http;

class Request
{
    public $onData = null;

    public $onEnd = null;

    public $httpVersion = null;

    public $headers = [];

    public $rawHeaders = null;

    public $method = null;

    public $url = null;

    public $connection = null;
    public $_query = [];
    public $_buffer = '';

    public function __construct($connection, $rawData)
    {
        $this->connection = $connection;
        $this->_buffer = $rawData;
        $this->parseHead($rawData);
    }

    public function parseHead($raw_head)
    {
        $header_data = explode("\r\n", $raw_head);
        list($this->method, $this->url, $protocol) = explode(' ', $header_data[0]);
        list($null, $this->httpVersion) = explode('/', $protocol);
        unset($header_data[0]);
        foreach ($header_data as $content) {
            if (empty($content)) {
                continue;
            }
            $this->rawHeaders[] = $content;
            list($key, $value) = explode(':', $content, 2);
            $this->headers[strtolower($key)] = trim($value);
        }

        $this->prepare();
    }

    protected function prepare()
    {
        if (empty($this->_query)) {
            $info = parse_url($this->url);
            if (isset($info['query'])) {
                parse_str($info['query'], $this->_query);
            }
        }
    }
    /**
     * Get http raw head.
     *
     * @return string
     */
    public function rawHead()
    {
        if (!isset($this->_data['head'])) {
            $this->_data['head'] = \strstr($this->_buffer, "\r\n\r\n", true);
        }
        return $this->_data['head'];
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody()
    {
        return \substr($this->_buffer, \strpos($this->_buffer, "\r\n\r\n") + 4);
    }

    public function get($key = null)
    {
        $this->prepare();

        if ($key) {
            return $this->_query[$key] ?? null;
        } else {
            return $this->_query;
        }
    }

    public function destroy()
    {
        $this->onData = $this->onEnd = $this->onClose = null;
        $this->connection = null;
    }
}
