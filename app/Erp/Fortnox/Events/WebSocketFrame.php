<?php

namespace App\Erp\Fortnox\Events;

/**
 * Encodes and decodes WebSocket frames (RFC 6455), just what a client needs:
 * text, close, ping and pong frames, any payload length, fragmented messages.
 * Pure functions, so they are tested without a network.
 */
final class WebSocketFrame
{
    public const CONTINUATION = 0x0;

    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xA;

    public function __construct(
        public readonly int $opcode,
        public readonly string $payload,
        public readonly bool $final = true,
    ) {}

    /**
     * A client frame. Clients must mask what they send; $maskKey is fixed only in tests.
     */
    public static function encode(int $opcode, string $payload, ?string $maskKey = null): string
    {
        $maskKey ??= random_bytes(4);
        $length = strlen($payload);

        $head = chr(0x80 | $opcode); // FIN + opcode
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126).pack('n', $length);
        } else {
            $head .= chr(0x80 | 127).pack('J', $length);
        }

        return $head.$maskKey.($payload ^ str_repeat($maskKey, intdiv($length, 4) + 1));
    }

    /**
     * The first complete frame in $buffer, and how many bytes it used; [null, 0] when more
     * bytes are needed. Server frames are normally unmasked, but a mask is honoured.
     *
     * @return array{0: ?self, 1: int}
     */
    public static function decode(string $buffer): array
    {
        if (strlen($buffer) < 2) {
            return [null, 0];
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return [null, 0];
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return [null, 0];
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $maskKey = '';
        if ($masked) {
            if (strlen($buffer) < $offset + 4) {
                return [null, 0];
            }
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return [null, 0];
        }

        $payload = substr($buffer, $offset, $length);
        if ($masked) {
            $payload ^= str_repeat($maskKey, intdiv($length, 4) + 1);
            $payload = substr($payload, 0, $length);
        }

        return [new self($first & 0x0F, $payload, ($first & 0x80) !== 0), $offset + $length];
    }
}
