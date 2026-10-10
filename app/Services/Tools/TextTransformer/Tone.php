<?php

declare(strict_types=1);

namespace App\Services\Tools\TextTransformer;

enum Tone: string
{
    case Neutral = 'neutral';
    case Professional = 'professional';
    case Friendly = 'friendly';
    case Confident = 'confident';
    case Persuasive = 'persuasive';

    public function label(): string
    {
        return match ($this) {
            self::Neutral => 'Neutral tone',
            self::Professional => 'Professional',
            self::Friendly => 'Friendly',
            self::Confident => 'Confident',
            self::Persuasive => 'Persuasive',
        };
    }

    /**
     * Null for neutral: saying nothing leaves the source's own tone intact,
     * which an explicit "use a neutral tone" would flatten.
     */
    public function instruction(): ?string
    {
        return match ($this) {
            self::Neutral => null,
            self::Professional => 'Use a professional, polished tone suitable for business communication.',
            self::Friendly => 'Use a warm, friendly, conversational tone.',
            self::Confident => 'Use a confident, direct tone without hedging.',
            self::Persuasive => 'Use a persuasive tone that makes a compelling case to the reader.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $tone): array => [$tone->value, $tone->label()], self::cases()),
            1,
            0,
        );
    }
}
