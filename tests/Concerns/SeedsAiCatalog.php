<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Database\Seeders\AiCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A fresh database with the real provider and model catalog.
 *
 * Providers and models live in the database, so anything that resolves a
 * model needs these rows. Seeding the production catalog, rather than a
 * test-only copy, means a typo in the seeder fails here first.
 *
 * Seeded per test (inside the test's transaction) rather than with `$seed`:
 * the in-memory database is migrated once per run, so `$seed` would only
 * apply for whichever test class happened to run first.
 */
trait SeedsAiCatalog
{
    use RefreshDatabase;

    /** Called by Laravel's TestCase for every test, after the database refresh. */
    protected function setUpSeedsAiCatalog(): void
    {
        $this->seed(AiCatalogSeeder::class);
    }
}
