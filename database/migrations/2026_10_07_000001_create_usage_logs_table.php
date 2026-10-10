<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every AI call, whether it succeeded, was served from cache, or failed.
 *
 * This is the audit and cost table. `tool_slug` and `model` are plain strings
 * rather than foreign keys on purpose: the audit trail must survive a tool or
 * model row being deleted, and must keep saying what was actually called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Hashed, never the raw address: the column stops being personal
            // data worth leaking, and a per-IP counter works just as well.
            $table->string('ip_hash', 64)->index();

            $table->string('tool_slug')->index();
            $table->string('capability', 32);
            $table->string('provider', 32);
            $table->string('model', 64);

            // Nullable because not every vendor reports tokens — DeepL bills
            // per character. A zero here would quietly understate the spend
            // total the circuit breaker sums.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();

            // Charged to the caller's allowance. Added now so the quota work
            // in a later phase does not need a second migration.
            $table->unsignedInteger('units_charged')->default(0);

            $table->unsignedInteger('duration_ms')->nullable();

            // Later answers "how much did caching actually save us".
            $table->boolean('was_cached')->default(false);

            // ok | failed | rate_limited | quota_exceeded | aborted
            $table->string('status', 20);
            $table->string('error_code', 64)->nullable();

            $table->timestamps();

            // Feeds the daily spend cap: sum cost_usd for today where the call
            // actually happened. Without this index that query scans the table
            // on every request once the log is large.
            $table->index(['created_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_logs');
    }
};
