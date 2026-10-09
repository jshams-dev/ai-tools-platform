<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which models a tool may use, which one it starts on, and in what order the
 * rest are tried when one fails.
 *
 * `sort_order` is the fallback chain. `is_default` is what a caller gets when
 * they do not pick — at most one per tool, enforced where it is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_model', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tool_id', 'ai_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_model');
    }
};
