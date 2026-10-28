<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Results\ReasoningDelta;

/**
 * Separates thought blocks at the start of a streamed answer from the answer.
 *
 * Gemini's OpenAI-compatible endpoint, asked to include its thoughts, puts
 * them in the ordinary content stream wrapped in <thought>…</thought> before
 * the answer. Left alone, the thinking would be shown, cached, and billed as
 * part of the answer.
 *
 * Only *leading* blocks count: once real answer text has started, a literal
 * "<thought>" — say, in text about HTML — is just text. And because a tag can
 * be split across network chunks ("<tho" | "ught>"), anything that might
 * still turn out to be a tag is held back until the next chunk decides it.
 */
final class LeadingThoughtSplitter
{
    private const OPEN = '<thought>';

    private const CLOSE = '</thought>';

    /** 'start' (no answer yet), 'thought' (inside a block), or 'answer'. */
    private string $state = 'start';

    private string $pending = '';

    /**
     * @return list<string|ReasoningDelta> Answer text as strings, thinking as ReasoningDelta.
     */
    public function push(string $fragment): array
    {
        if ($this->state === 'answer') {
            return $fragment === '' ? [] : [$fragment];
        }

        $this->pending .= $fragment;
        $out = [];

        while (true) {
            if ($this->state === 'start') {
                $trimmed = ltrim($this->pending);

                if (str_starts_with($trimmed, self::OPEN)) {
                    $this->state = 'thought';
                    $this->pending = substr($trimmed, strlen(self::OPEN));

                    continue;
                }

                // Too short to tell yet ("<th…"): wait for the next chunk.
                if (str_starts_with(self::OPEN, $trimmed)) {
                    return $out;
                }

                $this->state = 'answer';
                $answer = $this->pending;
                $this->pending = '';

                if ($answer !== '') {
                    $out[] = $answer;
                }

                return $out;
            }

            // Inside a thought block.
            $end = strpos($this->pending, self::CLOSE);

            if ($end !== false) {
                $this->emitThought(substr($this->pending, 0, $end), $out);
                $this->pending = substr($this->pending, $end + strlen(self::CLOSE));
                // Another block may follow before the answer starts.
                $this->state = 'start';

                continue;
            }

            // Release everything except a tail that could be the start of "</thought>".
            $hold = $this->partialTagLength($this->pending, self::CLOSE);
            $this->emitThought(substr($this->pending, 0, strlen($this->pending) - $hold), $out);
            $this->pending = substr($this->pending, strlen($this->pending) - $hold);

            return $out;
        }
    }

    /**
     * Whatever is still held back once the stream has ended.
     *
     * @return list<string|ReasoningDelta>
     */
    public function flush(): array
    {
        $rest = $this->pending;
        $this->pending = '';

        if ($rest === '') {
            return [];
        }

        // An unclosed block is still thinking; a lone "<th" was answer text.
        return $this->state === 'thought' ? [new ReasoningDelta($rest)] : [$rest];
    }

    /**
     * Strip leading thought blocks from a complete, non-streamed answer.
     */
    public static function stripFrom(string $text): string
    {
        return (string) preg_replace('/^(\s*<thought>.*?<\/thought>)+\s*/s', '', $text);
    }

    /**
     * @param  list<string|ReasoningDelta>  $out
     */
    private function emitThought(string $text, array &$out): void
    {
        if ($text !== '') {
            $out[] = new ReasoningDelta($text);
        }
    }

    /**
     * Length of the longest suffix of $text that is a prefix of $tag.
     */
    private function partialTagLength(string $text, string $tag): int
    {
        for ($length = min(strlen($tag) - 1, strlen($text)); $length > 0; $length--) {
            if (str_ends_with($text, substr($tag, 0, $length))) {
                return $length;
            }
        }

        return 0;
    }
}
