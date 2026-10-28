<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every model the platform can call, each belonging to one provider.
 *
 * `key` is ours and stable; `api_id` is the vendor's and changes when they
 * retire a model. Keeping them apart means a retirement is one edited row,
 * and usage_logs keep pointing at a key that still means something.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();

            // usage_logs.model is 64 characters, so the key must fit in it.
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('api_id');

            // ['text_generation'], ['translation'], ...
            $table->json('capabilities');

            // Which plan may use it: 'free' or 'pro'. Where "free" lives — a
            // property of the model, never of the provider.
            $table->string('tier', 16)->default('free');

            $table->boolean('supports_streaming')->default(true);

            // Newer reasoning models reject temperature with a 400 rather than
            // ignoring it, so whether to send it is a per-model fact.
            $table->boolean('supports_sampling')->default(true);

            $table->unsignedInteger('max_output_tokens')->nullable();

            // USD per million tokens. Zero for free models, which still log
            // their tokens so we can see what they would have cost.
            $table->decimal('input_cost_per_million', 10, 4)->default(0);
            $table->decimal('output_cost_per_million', 10, 4)->default(0);

            // Model-specific extras. `request_options` is merged into the
            // request payload — e.g. {"request_options": {"reasoning_effort":
            // "low"}} for gpt-oss, which otherwise thinks away its budget.
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
