<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

/**
 * The starting set of AI providers and models.
 *
 * Safe to run again: rows are matched on slug / key and updated in place, so
 * re-seeding never duplicates and never breaks the links tools have to them.
 * Once an admin panel exists, this is only the first-install default.
 *
 * Free models were checked against each vendor's live model list and a real
 * request on 2026-10-09. Free rosters change often — OpenRouter's especially.
 */
final class AiCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['slug' => 'groq', 'name' => 'Groq', 'driver' => 'openai_compatible', 'base_url' => 'https://api.groq.com/openai/v1', 'sort_order' => 1],
            ['slug' => 'gemini', 'name' => 'Google Gemini', 'driver' => 'openai_compatible', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'sort_order' => 2],
            [
                'slug' => 'openrouter',
                'name' => 'OpenRouter',
                'driver' => 'openai_compatible',
                'base_url' => 'https://openrouter.ai/api/v1',
                // OpenRouter attributes traffic to an app by these headers.
                'settings' => ['headers' => ['X-Title' => 'AI Tools Platform']],
                'sort_order' => 3,
            ],
            ['slug' => 'anthropic', 'name' => 'Anthropic', 'driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com', 'settings' => ['version' => '2023-06-01'], 'sort_order' => 10],
            ['slug' => 'deepl', 'name' => 'DeepL', 'driver' => 'deepl', 'base_url' => 'https://api-free.deepl.com', 'sort_order' => 11],
        ];

        foreach ($providers as $provider) {
            AiProvider::updateOrCreate(['slug' => $provider['slug']], $provider);
        }

        foreach ($this->models() as $order => $model) {
            $provider = AiProvider::where('slug', $model['provider'])->sole();
            unset($model['provider']);

            AiModel::updateOrCreate(
                ['key' => $model['key']],
                [...$model, 'ai_provider_id' => $provider->id, 'sort_order' => $order],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function models(): array
    {
        $text = ['text_generation'];

        return [
            // Free — Groq. Very fast; ~30 requests/minute, ~1,000/day per model.
            ['provider' => 'groq', 'key' => 'groq-qwen3.8-27b', 'name' => 'Qwen 3.8 27B', 'api_id' => 'qwen/qwen3.8-27b', 'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192],
            [
                'provider' => 'groq', 'key' => 'groq-gpt-oss-120b', 'name' => 'GPT-OSS 120B', 'api_id' => 'openai/gpt-oss-120b',
                'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192,
                // A reasoning model: at the default effort it can think away
                // a whole small budget and answer with nothing.
                'settings' => ['request_options' => ['reasoning_effort' => 'low']],
            ],
            [
                'provider' => 'groq', 'key' => 'groq-gpt-oss-20b', 'name' => 'GPT-OSS 20B', 'api_id' => 'openai/gpt-oss-20b',
                'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192,
                'settings' => ['request_options' => ['reasoning_effort' => 'low']],
            ],

            // Free — Google. Best quality of the free tiers.
            ['provider' => 'gemini', 'key' => 'gemini-3.5-flash-lite', 'name' => 'Gemini 3.5 Flash-Lite', 'api_id' => 'gemini-3.5-flash-lite', 'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192],
            ['provider' => 'gemini', 'key' => 'gemini-3.5-flash', 'name' => 'Gemini 3.5 Flash', 'api_id' => 'gemini-3.5-flash', 'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192],

            // Free — OpenRouter's shared pool: 20/minute, 50/day, and often
            // rate-limited upstream. Useful as the end of a fallback chain.
            ['provider' => 'openrouter', 'key' => 'openrouter-nemotron-3-super', 'name' => 'Nemotron 3 Super 120B', 'api_id' => 'nvidia/nemotron-3-super-120b-a12b:free', 'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192],
            ['provider' => 'openrouter', 'key' => 'openrouter-gemma-4-31b', 'name' => 'Gemma 4 31B', 'api_id' => 'google/gemma-4-31b-it:free', 'capabilities' => $text, 'tier' => 'free', 'max_output_tokens' => 8192],

            // Paid — Anthropic. Pro tier only, so no guest request reaches
            // them. Prices are USD per million tokens; check the vendor's
            // current model IDs before enabling.
            ['provider' => 'anthropic', 'key' => 'claude-haiku', 'name' => 'Claude Haiku', 'api_id' => 'claude-haiku-4-5', 'capabilities' => $text, 'tier' => 'pro', 'max_output_tokens' => 8192, 'input_cost_per_million' => 1.00, 'output_cost_per_million' => 5.00],
            // These reject temperature with a 400, hence no sampling.
            ['provider' => 'anthropic', 'key' => 'claude-sonnet', 'name' => 'Claude Sonnet', 'api_id' => 'claude-sonnet-5', 'capabilities' => $text, 'tier' => 'pro', 'supports_sampling' => false, 'max_output_tokens' => 64000, 'input_cost_per_million' => 2.00, 'output_cost_per_million' => 10.00],
            ['provider' => 'anthropic', 'key' => 'claude-opus', 'name' => 'Claude Opus', 'api_id' => 'claude-opus-5', 'capabilities' => $text, 'tier' => 'pro', 'supports_sampling' => false, 'max_output_tokens' => 64000, 'input_cost_per_million' => 5.00, 'output_cost_per_million' => 25.00],

            // Translation only: proves a provider can serve one capability.
            ['provider' => 'deepl', 'key' => 'deepl', 'name' => 'DeepL', 'api_id' => 'v2', 'capabilities' => ['translation'], 'tier' => 'free', 'supports_streaming' => false],
        ];
    }
}
