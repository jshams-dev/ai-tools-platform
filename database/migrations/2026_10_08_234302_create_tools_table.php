<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The editable half of a tool: what it is called, how it is described,
 * whether it is live, and what it costs.
 *
 * The other half — its input fields and the handler that runs it — is code,
 * found by `slug` in config/tools.php. A row with no matching code is simply
 * not served; code with no row is not shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tools', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('category', 32)->index();

            // Base weight against a caller's quota — see §11.1.
            $table->unsignedSmallInteger('cost_units')->default(1);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
