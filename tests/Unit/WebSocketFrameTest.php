<?php

namespace Tests\Unit;

use App\Erp\Fortnox\Events\WebSocketFrame;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebSocketFrameTest extends TestCase
{
    /** @return array<string, array{int}> the three ways RFC 6455 encodes a length */
    public static function lengths(): array
    {
        return ['short (< 126)' => [5], 'medium (16-bit)' => [300], 'long (64-bit)' => [70000]];
    }

    #[DataProvider('lengths')]
    public function test_a_client_frame_is_masked_and_decodes_back(int $length): void
    {
        $payload = str_repeat('åäö', intdiv($length, 6) + 1);
        $frame = WebSocketFrame::encode(WebSocketFrame::TEXT, $payload, 'abcd');

        $this->assertSame(0x81, ord($frame[0]), 'FIN + text');
        $this->assertSame(0x80, ord($frame[1]) & 0x80, 'clients must mask');
        $this->assertStringNotContainsString($payload, $frame);

        [$decoded, $used] = WebSocketFrame::decode($frame.'next');
        $this->assertSame($payload, $decoded->payload);
        $this->assertSame(strlen($frame), $used, 'what follows the frame is left for the next one');
    }

    public function test_an_unmasked_server_frame_decodes(): void
    {
        [$frame, $used] = WebSocketFrame::decode("\x81\x05hello");

        $this->assertSame([WebSocketFrame::TEXT, 'hello', true, 7], [$frame->opcode, $frame->payload, $frame->final, $used]);
    }

    public function test_an_incomplete_frame_waits_for_more_bytes(): void
    {
        $whole = "\x81\x7E".pack('n', 300).str_repeat('x', 300);

        foreach ([1, 3, 10, 303] as $cut) {
            $this->assertSame([null, 0], WebSocketFrame::decode(substr($whole, 0, $cut)), "cut at {$cut}");
        }
        $this->assertSame(304, WebSocketFrame::decode($whole)[1]);
    }

    public function test_fragments_and_control_frames_are_recognised(): void
    {
        $this->assertFalse(WebSocketFrame::decode("\x01\x03abc")[0]->final, 'first fragment');
        $this->assertSame(WebSocketFrame::CONTINUATION, WebSocketFrame::decode("\x80\x03def")[0]->opcode);
        $this->assertSame(WebSocketFrame::PING, WebSocketFrame::decode("\x89\x00")[0]->opcode);

        [$close] = WebSocketFrame::decode("\x88\x05".pack('n', 4000).'bye');
        $this->assertSame([WebSocketFrame::CLOSE, 4000, 'bye'], [$close->opcode, unpack('n', $close->payload)[1], substr($close->payload, 2)]);
    }
}
