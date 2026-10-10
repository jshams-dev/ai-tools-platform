<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\Tool;
use Illuminate\Database\Seeder;

/**
 * The tools and the models each may use. Runs after AiCatalogSeeder.
 *
 * The order of each model list is the fallback chain; the first model is the
 * default. Safe to run again — the model list is synced, not appended.
 */
final class ToolCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tool = Tool::updateOrCreate(['slug' => 'text-transformer'], [
            'name' => 'Text Transformer',
            'description' => 'Rewrite, summarize, proofread, or translate any text — in the tone you need.',
            'category' => 'writing',
            'cost_units' => 1,
            'sort_order' => 1,
        ]);

        $this->attachModels($tool, [
            // Free, in fallback order: fast first, then the rest of the free
            // pool, so a rate-limited vendor degrades to another.
            'groq-qwen3.8-27b',
            'gemini-3.5-flash-lite',
            'groq-gpt-oss-120b',
            'gemini-3.5-flash',
            'groq-gpt-oss-20b',
            'openrouter-nemotron-3-super',
            'openrouter-gemma-4-31b',
            // Pro only.
            'claude-haiku',
            'claude-sonnet',
            'claude-opus',
        ]);
    }

    /**
     * @param  list<string>  $keys  In fallback order; the first is the default.
     */
    private function attachModels(Tool $tool, array $keys): void
    {
        $ids = AiModel::whereIn('key', $keys)->pluck('id', 'key');

        $pivot = [];

        foreach ($keys as $order => $key) {
            $pivot[$ids[$key] ?? throw new \RuntimeException("Unknown model '{$key}'. Run AiCatalogSeeder first.")] = [
                'is_default' => $order === 0,
                'sort_order' => $order,
            ];
        }

        $tool->models()->sync($pivot);
    }
}
