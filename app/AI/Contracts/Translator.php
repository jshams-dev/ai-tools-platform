<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\Requests\TranslationRequest;
use App\AI\ResolvedModel;
use App\AI\Results\TranslationResult;

/**
 * A provider that translates text between languages.
 *
 * This interface is the argument for capability segregation made concrete.
 * DeepL is not a completion API — it takes text and a target language, not a
 * prompt. Forcing it behind a shared `complete(string $prompt)` would mean
 * writing a DeepL method whose only behaviour is to throw, and the interface
 * would then be lying about what the object can do.
 *
 * Note that a model-based provider can implement this too: Anthropic could
 * translate via a prompt. The capability describes the job, not the mechanism.
 */
interface Translator extends AiProvider
{
    public function translate(TranslationRequest $request, ResolvedModel $model): TranslationResult;
}
