<?php

declare(strict_types=1);

namespace App\Tools\Definitions;

use App\AI\ModelPolicy;
use App\Services\Tools\TextTransformer\OutputLanguage;
use App\Services\Tools\TextTransformer\TextTransformerHandler;
use App\Services\Tools\TextTransformer\Tone;
use App\Services\Tools\TextTransformer\TransformMode;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Schema\Field;

final class TextTransformerTool implements ToolInterface
{
    public function slug(): string
    {
        return 'text-transformer';
    }

    public function name(): string
    {
        return 'Text Transformer';
    }

    public function description(): string
    {
        return 'Rewrite, summarize, proofread, or translate any text — in the tone you need.';
    }

    public function category(): string
    {
        return 'writing';
    }

    public function inputSchema(): array
    {
        return [
            Field::textarea('text')
                ->primary()
                ->required()
                ->max(8000)
                ->label('Text')
                ->placeholder('Paste or type the text you want to transform…'),
            Field::select('mode')
                ->label('Task')
                ->options(TransformMode::options())
                ->default(TransformMode::Rewrite->value),
            Field::select('tone')
                ->label('Tone')
                ->options(Tone::options())
                ->default(Tone::Neutral->value),
            Field::select('language')
                ->label('Output language')
                ->options(OutputLanguage::options())
                ->default(OutputLanguage::SameAsInput->value),
        ];
    }

    public function starters(): array
    {
        return [
            ['label' => 'Summarize an article', 'options' => ['mode' => 'summarize']],
            ['label' => 'Fix grammar and spelling', 'options' => ['mode' => 'proofread']],
            ['label' => 'Make it sound professional', 'options' => ['mode' => 'rewrite', 'tone' => 'professional']],
            ['label' => 'Turn notes into bullet points', 'options' => ['mode' => 'bullets']],
            [
                'label' => 'Try an example',
                'options' => ['mode' => 'simplify'],
                'prompt' => 'Notwithstanding the aforementioned considerations, the committee has resolved to defer '.
                    'implementation of the proposed modifications pending further deliberation regarding their '.
                    'budgetary ramifications and the operational capacity of the departments concerned.',
            ],
        ];
    }

    public function handler(): string
    {
        return TextTransformerHandler::class;
    }

    public function modelPolicy(): ModelPolicy
    {
        return ModelPolicy::tiered(
            free: ['claude-haiku'],
            pro: ['claude-sonnet', 'claude-haiku'],
            userSelectable: true,
        );
    }

    public function costUnits(): int
    {
        return 1;
    }

    public function component(): ?string
    {
        return null;
    }
}
