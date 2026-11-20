<?php

namespace App\Erp\Fortnox\Events;

/**
 * A minimal blocking WebSocket client (RFC 6455) over PHP streams: enough for Fortnox's
 * event stream, which sends JSON text messages. No extensions or event loop needed,
 * so it runs the same on Windows (Laragon) and on Fly.
 */
final class WebSocketClient implements Connection
{
    private string $buffer = '';

    /** Pieces of a fragmented message, until its final frame arrives. */
    private string $fragments = '';

    private bool $closed = false;

    /** @param resource $stream */
    private function __construct(private $stream) {}

    /**
     * @throws ConnectionClosed
     */
    public static function open(string $url, float $timeout = 10): self
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        if (! in_array($scheme, ['ws', 'wss'], true) || empty($parts['host'])) {
            throw new ConnectionClosed("Not a WebSocket URL: {$url}");
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $target = ($scheme === 'wss' ? 'tls' : 'tcp')."://{$host}:{$port}";

        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new ConnectionClosed("Could not connect to {$host}:{$port}: {$error} ({$errno})");
        }
        stream_set_timeout($stream, (int) ceil($timeout));

        $key = base64_encode(random_bytes(16));
        $hostHeader = $host.(isset($parts['port']) ? ':'.$port : '');
        fwrite($stream, "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nUser-Agent: VerdantCart-B2B-Sync\r\n\r\n");

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $line = fgets($stream);
            if ($line === false) {
                fclose($stream);
                throw new ConnectionClosed("No WebSocket handshake from {$host}");
            }
            $response .= $line;
        }

        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (! preg_match('#^HTTP/1\.1 101#', $response) || ! preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $response, $m) || $m[1] !== $expected) {
            fclose($stream);
            throw new ConnectionClosed("{$host} refused the WebSocket upgrade: ".strtok($response, "\r\n"));
        }

        stream_set_blocking($stream, false);

        return new self($stream);
    }

    public function send(string $text): void
    {
        $this->write(WebSocketFrame::encode(WebSocketFrame::TEXT, $text));
    }

    public function ping(): void
    {
        $this->write(WebSocketFrame::encode(WebSocketFrame::PING, 'keep-alive'));
    }

    public function receive(float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            [$frame, $used] = WebSocketFrame::decode($this->buffer);
            if ($frame !== null) {
                $this->buffer = substr($this->buffer, $used);
                $message = $this->handle($frame);
                if ($message !== null) {
                    return $message;
                }

                continue;
            }

            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $this->fill($left);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        @fwrite($this->stream, WebSocketFrame::encode(WebSocketFrame::CLOSE, pack('n', 1000)));
        @fclose($this->stream);
    }

    /** A complete text message, or null for control frames and unfinished fragments. */
    private function handle(WebSocketFrame $frame): ?string
    {
        switch ($frame->opcode) {
            case WebSocketFrame::PING:
                $this->write(WebSocketFrame::encode(WebSocketFrame::PONG, $frame->payload));

                return null;
            case WebSocketFrame::PONG:
                return null;
            case WebSocketFrame::CLOSE:
                $code = strlen($frame->payload) >= 2 ? unpack('n', substr($frame->payload, 0, 2))[1] : 1005;
                $reason = substr($frame->payload, 2);
                $this->close();
                throw new ConnectionClosed(trim("Server closed the connection ({$code}) {$reason}"));
            case WebSocketFrame::TEXT:
            case WebSocketFrame::BINARY:
            case WebSocketFrame::CONTINUATION:
                $this->fragments .= $frame->payload;
                if (! $frame->final) {
                    return null;
                }
                $message = $this->fragments;
                $this->fragments = '';

                return $message;
            default:
                return null;
        }
    }

    /**
     * Wait up to $seconds for more bytes. Reads first: TLS and PHP's stream layer can hold
     * bytes already received, which stream_select() doesn't report.
     */
    private function fill(float $seconds): void
    {
        if ($this->readAvailable()) {
            return;
        }

        $read = [$this->stream];
        $write = $except = null;
        $sec = (int) floor($seconds);
        $usec = (int) (($seconds - $sec) * 1_000_000);

        $ready = @stream_select($read, $write, $except, $sec, $usec);
        if ($ready === false) {
            throw new ConnectionClosed('Waiting for the connection failed');
        }
        if ($ready === 0) {
            return;
        }

        $this->readAvailable();
    }

    /** Append whatever can be read without waiting; true if anything was read. */
    private function readAvailable(): bool
    {
        $got = false;
        while (true) {
            $chunk = @fread($this->stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                $this->closed = true;
                @fclose($this->stream);
                throw new ConnectionClosed('The connection was lost');
            }
            if ($chunk === '') {
                return $got;
            }
            $this->buffer .= $chunk;
            $got = true;
        }
    }

    private function write(string $bytes): void
    {
        if ($this->closed) {
            throw new ConnectionClosed('The connection is closed');
        }

        while ($bytes !== '') {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false) {
                $this->closed = true;
                throw new ConnectionClosed('Writing to the connection failed');
            }
            if ($written === 0) {
                $write = [$this->stream];
                $read = $except = null;
                if (@stream_select($read, $write, $except, 5) === 0) {
                    throw new ConnectionClosed('The connection stopped accepting data');
                }

                continue;
            }
            $bytes = substr($bytes, $written);
        }
    }
}
