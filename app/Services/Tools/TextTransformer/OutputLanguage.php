<?php

declare(strict_types=1);

namespace App\Services\Tools\TextTransformer;

enum OutputLanguage: string
{
    case SameAsInput = 'auto';
    case English = 'en';
    case Persian = 'fa';
    case Arabic = 'ar';
    case Turkish = 'tr';
    case German = 'de';
    case French = 'fr';
    case Spanish = 'es';

    public function label(): string
    {
        return match ($this) {
            self::SameAsInput => 'Same language',
            self::English => 'English',
            self::Persian => 'Persian',
            self::Arabic => 'Arabic',
            self::Turkish => 'Turkish',
            self::German => 'German',
            self::French => 'French',
            self::Spanish => 'Spanish',
        };
    }

    public function instruction(): string
    {
        return $this === self::SameAsInput
            ? 'Write the result in the same language as the text.'
            : "Write the result in {$this->label()}. If the text is in another language, translate it as part of the task.";
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $language): array => [$language->value, $language->label()], self::cases()),
            1,
            0,
        );
    }
}
