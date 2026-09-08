<?php

declare(strict_types=1);

namespace App\Clients;

/**
 * Turns a stream of arbitrary byte chunks into Server-Sent Events.
 *
 * This handles the *framing* defined by the SSE spec — nothing more. It does
 * not know what an event means; Anthropic's `content_block_delta` and any
 * other vendor's event names are interpreted by that vendor's provider.
 *
 * The reason this exists as its own class: socket chunks do not respect
 * message boundaries. A single event can arrive split across three reads, and
 * one read can carry two and a half events. Every provider that streams would
 * otherwise re-implement the same buffer, and each would get the edge cases
 * subtly wrong in its own way.
 */
final class ServerSentEvents
{
    /**
     * @param  iterable<int, string>  $chunks  Raw bytes from the socket.
     * @return \Generator<int, array{event: string|null, data: string}>
     */
    public static function parse(iterable $chunks): \Generator
    {
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            // Events are separated by a blank line. Normalise CRLF first so
            // that a vendor using \r\n does not silently produce zero events.
            $buffer = str_replace("\r\n", "\n", $buffer);

            while (($position = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + 2);

                if (($event = self::parseBlock($block)) !== null) {
                    yield $event;
                }
            }
        }

        // A well-behaved server ends with a blank line, but a truncated stream
        // may leave a final complete event unterminated in the buffer.
        if (trim($buffer) !== '' && ($event = self::parseBlock($buffer)) !== null) {
            yield $event;
        }
    }

    /**
     * @return array{event: string|null, data: string}|null
     */
    private static function parseBlock(string $block): ?array
    {
        $name = null;
        $data = [];

        foreach (explode("\n", $block) as $line) {
            // A line starting with ':' is a comment. Servers send these as
            // keep-alives; treating one as data would corrupt the output.
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');

            // Exactly one leading space after the colon is part of the
            // framing, not the value.
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            match ($field) {
                'event' => $name = $value,
                // Multiple data lines in one event are joined with newlines.
                'data' => $data[] = $value,
                // 'id' and 'retry' are spec fields we have no use for.
                default => null,
            };
        }

        if ($data === []) {
            return null;
        }

        return ['event' => $name, 'data' => implode("\n", $data)];
    }
}
