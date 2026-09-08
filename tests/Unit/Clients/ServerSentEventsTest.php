<?php

declare(strict_types=1);

namespace Tests\Unit\Clients;

use App\Clients\ServerSentEvents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The parser has no Laravel dependencies, so this is a plain unit test.
 *
 * The cases that matter are the ones a naive implementation gets wrong: chunk
 * boundaries that fall in the middle of an event, keep-alive comments, and
 * multi-line data. Each of these produces a real bug in production — usually
 * a truncated or duplicated word in the middle of a stream — and each is
 * invisible until it happens to a user.
 */
final class ServerSentEventsTest extends TestCase
{
    /**
     * @param  iterable<int, array{event: string|null, data: string}>  $events
     * @return list<array{event: string|null, data: string}>
     */
    private function collect(iterable $events): array
    {
        return iterator_to_array($events, preserve_keys: false);
    }

    #[Test]
    public function it_parses_events_arriving_in_one_chunk(): void
    {
        $events = $this->collect(ServerSentEvents::parse([
            "event: message_start\ndata: {\"a\":1}\n\nevent: message_stop\ndata: {\"b\":2}\n\n",
        ]));

        $this->assertSame(
            [
                ['event' => 'message_start', 'data' => '{"a":1}'],
                ['event' => 'message_stop', 'data' => '{"b":2}'],
            ],
            $events,
        );
    }

    #[Test]
    public function it_reassembles_an_event_split_across_chunks(): void
    {
        // The socket does not respect message boundaries. This split falls in
        // the middle of the JSON payload, which is the case that silently
        // drops a fragment if the parser has no buffer.
        $events = $this->collect(ServerSentEvents::parse([
            'event: content_block_de',
            "lta\ndata: {\"text\":\"hel",
            "lo\"}\n\n",
        ]));

        $this->assertSame(
            [['event' => 'content_block_delta', 'data' => '{"text":"hello"}']],
            $events,
        );
    }

    #[Test]
    public function it_ignores_keepalive_comments(): void
    {
        $events = $this->collect(ServerSentEvents::parse([
            ": keep-alive\n\nevent: ping\ndata: {}\n\n",
        ]));

        $this->assertSame([['event' => 'ping', 'data' => '{}']], $events);
    }

    #[Test]
    public function it_joins_multiple_data_lines_with_newlines(): void
    {
        $events = $this->collect(ServerSentEvents::parse([
            "event: note\ndata: first\ndata: second\n\n",
        ]));

        $this->assertSame([['event' => 'note', 'data' => "first\nsecond"]], $events);
    }

    #[Test]
    public function it_handles_crlf_line_endings(): void
    {
        $events = $this->collect(ServerSentEvents::parse([
            "event: ping\r\ndata: {}\r\n\r\n",
        ]));

        $this->assertSame([['event' => 'ping', 'data' => '{}']], $events);
    }

    #[Test]
    public function it_yields_a_trailing_event_that_was_never_terminated(): void
    {
        // A stream cut short by a dropped connection can leave a complete
        // event without its closing blank line. Discarding it would lose the
        // final tokens of an answer the user already paid for.
        $events = $this->collect(ServerSentEvents::parse([
            "event: message_delta\ndata: {\"done\":true}",
        ]));

        $this->assertSame([['event' => 'message_delta', 'data' => '{"done":true}']], $events);
    }

    #[Test]
    public function it_skips_blocks_that_carry_no_data(): void
    {
        $events = $this->collect(ServerSentEvents::parse(["event: ping\n\ndata: real\n\n"]));

        $this->assertSame([['event' => null, 'data' => 'real']], $events);
    }

    #[Test]
    public function it_preserves_colons_inside_a_value(): void
    {
        $events = $this->collect(ServerSentEvents::parse([
            "data: {\"url\":\"https://example.test\"}\n\n",
        ]));

        $this->assertSame([['event' => null, 'data' => '{"url":"https://example.test"}']], $events);
    }
}
