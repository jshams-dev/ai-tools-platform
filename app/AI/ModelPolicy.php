<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Exceptions\UnknownModelException;

/**
 * Which models a tool is allowed to use, and in what order.
 *
 * Declared by the tool, enforced by the resolver. Two shapes cover every case
 * so far: a tool locked to exactly one model, and a tool whose choice depends
 * on the caller's tier.
 *
 * The ordering is the resilience story. A chain of several models means one
 * vendor going down degrades output quality instead of taking the tool
 * offline — and it costs nothing to declare.
 */
final readonly class ModelPolicy
{
    /**
     * @param  array<string, list<string>>  $byTier  Plan tier => ordered model keys.
     */
    private function __construct(
        private array $byTier,
        public bool $userSelectable = false,
    ) {}

    /**
     * One model, no choice, no fallback.
     *
     * For work only a single model can do — there is no second video model to
     * degrade to, so pretending otherwise would just produce a confusing
     * failure further down.
     */
    public static function fixed(string $modelKey): self
    {
        return new self(['*' => [$modelKey]], userSelectable: false);
    }

    /**
     * A chain per tier, tried in order.
     *
     * @param  list<string>  $free
     * @param  list<string>  $pro
     */
    public static function tiered(array $free, array $pro = [], bool $userSelectable = false): self
    {
        if ($free === []) {
            throw new \InvalidArgumentException('A tiered policy needs at least one free-tier model.');
        }

        return new self(
            ['free' => $free, 'pro' => $pro === [] ? $free : $pro],
            $userSelectable,
        );
    }

    /**
     * The ordered model keys this tier may use.
     *
     * A tier with no list of its own falls back to the free chain rather than
     * failing: 'guest' is a real tier (§11) and must resolve to something.
     * Note that a pro chain is deliberately *not* extended with the free
     * models — if a pro tool should be able to degrade that far, its policy
     * says so explicitly.
     *
     * @return list<string>
     */
    public function chainFor(string $tier): array
    {
        return $this->byTier['*']
            ?? $this->byTier[$tier]
            ?? $this->byTier['free']
            ?? [];
    }

    /**
     * Whether this tier may explicitly ask for this model.
     *
     * The security-relevant half of the policy. A submitted model name is
     * caller-controlled input: without this check a free user could post
     * `model=claude-opus` and spend our money at ten times the rate their
     * plan allows.
     */
    public function allows(string $modelKey, string $tier): bool
    {
        return $this->userSelectable && in_array($modelKey, $this->chainFor($tier), strict: true);
    }

    /**
     * @return list<string>
     */
    public function allTierKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values($this->byTier))));
    }

    /**
     * Fails fast on a policy naming a model that does not exist.
     *
     * Worth doing at the boundary rather than at call time: a typo in a tool's
     * policy would otherwise only surface for whichever tier happens to hit
     * that position in the chain.
     */
    public function assertModelsExist(ModelRegistry $registry): void
    {
        foreach ($this->allTierKeys() as $key) {
            if (! $registry->has($key)) {
                throw UnknownModelException::forKey($key);
            }
        }
    }
}
