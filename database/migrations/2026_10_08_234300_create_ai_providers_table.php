<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per AI vendor: Groq, Google, OpenRouter, Anthropic, ...
 *
 * A row cannot carry code, so `driver` names one of the few protocol classes
 * registered in config/ai.php ('openai_compatible', 'anthropic', 'deepl'). It
 * is a short key rather than a class name on purpose: a class name in a table
 * is an admin form away from instantiating anything on the server.
 *
 * Most vendors speak the OpenAI chat format, so a new vendor of that kind is
 * a row here and nothing else.
 *
 * The API key is not stored. config/ai.php maps each slug to an environment
 * variable, so secrets stay in .env until encrypted storage is built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('name');
            $table->string('driver', 32);
            $table->string('base_url');
            $table->string('proxy')->nullable();

            // Vendor-specific extras the driver understands, e.g. a version
            // header. Kept as JSON so a new vendor quirk is data, not a column.
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
