<?php

namespace PHPSocketIO\Engine\Protocols;

use Exception;
use \PHPSocketIO\Engine\Protocols\WebSocket;
use \PHPSocketIO\Engine\Protocols\Http\Request;
use \PHPSocketIO\Engine\Protocols\Http\Response;
use \Workerman\Connection\TcpConnection;

class SktIO
{
    public static function input($recv_buffer, $connection)
    {
        static $input = [];
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }
        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            // Judge whether the package length exceeds the limit.
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }

        $length = $crlf_pos + 4;
        $method = \strstr($recv_buffer, ' ', true);

        if (!\in_array($method, ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        $header = \substr($recv_buffer, 0, $crlf_pos);
        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + $match[1];
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }

        if ($has_content_length) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Request Entity Too Large\r\n\r\n", true);
                return 0;
            }
        }

        if (!isset($recv_buffer[512])) {
            $input[$recv_buffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        TcpConnection::$statistics['total_request']++;
        $pos = strpos($recv_buffer, "\r\n\r\n");
        $raw_head = substr($recv_buffer, 0, $pos + 4);
        $req = new Request($connection, $raw_head);
        
        if (isset($req->headers['upgrade']) && strtolower($req->headers['upgrade']) === 'websocket') {
            $connection->consumeRecvBuffer(strlen($recv_buffer));
            WebSocket::dealHandshake($connection, $req, new Response($connection));
            self::cleanup($connection);
            return 0;
        }

        return $length;
    }

    /**
     * Http encode.
     *
     * @param string|Response $response
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode($httpBuffer, TcpConnection $connection)
    {
        return $httpBuffer;
        // if (isset($connection->onRequest)) {
        //     return $httpBuffer;
        // } else {
        //     list($head, $body) = explode("\r\n\r\n", $httpBuffer, 2);
        //     return $body;
        // }
    }

    public static function decode($buffer, TcpConnection $connection)
    {
        $request = new Request($connection, $buffer);
        $request->connection = $connection;
        $connection->__request = $request;

        return $request;
    }


}
