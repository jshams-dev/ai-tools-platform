<?php

declare(strict_types=1);

namespace App\Services\Tools\TextTransformer;

/**
 * The transformer's input, typed.
 *
 * The handler works with this rather than the validated array, so a typo in
 * a key is a compile-time miss instead of a silently-null prompt fragment.
 */
final readonly class TextTransformerInput
{
    public function __construct(
        public string $text,
        public TransformMode $mode = TransformMode::Rewrite,
        public Tone $tone = Tone::Neutral,
        public OutputLanguage $language = OutputLanguage::SameAsInput,
    ) {}

    /**
     * @param  array<string, string|null>  $input  Validated by ToolRunner.
     */
    public static function fromValidated(array $input): self
    {
        return new self(
            text: (string) $input['text'],
            mode: TransformMode::from($input['mode'] ?? TransformMode::Rewrite->value),
            tone: Tone::from($input['tone'] ?? Tone::Neutral->value),
            language: OutputLanguage::from($input['language'] ?? OutputLanguage::SameAsInput->value),
        );
    }
}
