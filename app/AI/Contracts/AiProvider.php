<?php

declare(strict_types=1);

namespace App\AI\Contracts;

/**
 * A vendor. Nothing more.
 *
 * This interface carries no work methods on purpose. Everything a provider can
 * actually *do* is declared by a capability interface it opts into, so no
 * provider is ever forced to implement a method it cannot honour — the reason
 * there is no single `complete(string $prompt)` anywhere in this codebase.
 *
 * There is deliberately no FreeProvider and no FakeProvider. Whether a model is
 * cheap is a property of the model, not of the vendor class; and because all
 * traffic passes through BaseHttpClient, `Http::fake()` stands in for every
 * provider at once, so no test double needs to ship in app/.
 */
interface AiProvider
{
    /**
     * Registry key for this vendor, e.g. 'anthropic'. Used in usage logs and
     * cache keys, so it must stay stable once rows exist.
     */
    public function name(): string;
}
