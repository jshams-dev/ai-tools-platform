<?php

declare(strict_types=1);

namespace App\Services\Tools\TextTransformer;

/**
 * What the transformer does to the text.
 *
 * The label (what the user picks) and the instruction (what the model is
 * told) live on the same case, so a new mode cannot ship with one and not
 * the other.
 */
enum TransformMode: string
{
    case Rewrite = 'rewrite';
    case Summarize = 'summarize';
    case Proofread = 'proofread';
    case Simplify = 'simplify';
    case Expand = 'expand';
    case Bullets = 'bullets';

    public function label(): string
    {
        return match ($this) {
            self::Rewrite => 'Rewrite',
            self::Summarize => 'Summarize',
            self::Proofread => 'Fix grammar',
            self::Simplify => 'Simplify',
            self::Expand => 'Expand',
            self::Bullets => 'Bullet points',
        };
    }

    public function instruction(): string
    {
        return match ($this) {
            self::Rewrite => 'Rewrite the text so it reads clearly and naturally. Preserve its meaning, every fact, and roughly its length.',
            self::Summarize => 'Summarize the text. Keep the key points and any figures; drop repetition and filler. Aim for about a quarter of the original length.',
            self::Proofread => 'Correct spelling, grammar, and punctuation. Change as little as possible: leave sentences that are already correct exactly as they are.',
            self::Simplify => 'Rewrite the text in plain language that a twelve-year-old could follow. Use short sentences and common words, and keep every important point.',
            self::Expand => 'Expand the text with relevant detail, examples, and transitions so it reads as a fuller piece. Do not invent facts, figures, names, or quotes.',
            self::Bullets => 'Turn the text into a concise Markdown bullet list of its key points, one idea per bullet.',
        };
    }

    /**
     * Proofreading is a minimal-edit task. Asking for a tone on top of it
     * would license exactly the rewriting the user asked us not to do.
     */
    public function acceptsTone(): bool
    {
        return $this !== self::Proofread;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $mode): array => [$mode->value, $mode->label()], self::cases()),
            1,
            0,
        );
    }
}
