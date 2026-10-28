<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\AI\ModelPolicy;
use App\Usage\RunContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelPolicyTest extends TestCase
{
    #[Test]
    public function a_fixed_policy_returns_one_model_for_every_tier(): void
    {
        $policy = ModelPolicy::fixed('veo-3');

        $this->assertSame(['veo-3'], $policy->chainFor(RunContext::TIER_GUEST));
        $this->assertSame(['veo-3'], $policy->chainFor(RunContext::TIER_PRO));
        // There is no second video model to degrade to, so offering a choice
        // would be a lie.
        $this->assertFalse($policy->userSelectable);
    }

    #[Test]
    public function a_tiered_policy_keeps_each_chain_in_declared_order(): void
    {
        $policy = ModelPolicy::tiered(
            free: ['gemini-flash', 'gpt-mini'],
            pro: ['claude-sonnet', 'gpt-full'],
        );

        // Order is the resilience story: first choice first, fallback second.
        $this->assertSame(['gemini-flash', 'gpt-mini'], $policy->chainFor(RunContext::TIER_FREE));
        $this->assertSame(['claude-sonnet', 'gpt-full'], $policy->chainFor(RunContext::TIER_PRO));
    }

    #[Test]
    public function an_unknown_tier_falls_back_to_the_free_chain(): void
    {
        $policy = ModelPolicy::tiered(free: ['gemini-flash'], pro: ['claude-sonnet']);

        // 'guest' is a real tier (§11), not a special case, and must resolve
        // to something rather than producing an empty chain.
        $this->assertSame(['gemini-flash'], $policy->chainFor(RunContext::TIER_GUEST));
        $this->assertSame(['gemini-flash'], $policy->chainFor('some-future-tier'));
    }

    #[Test]
    public function omitting_the_pro_chain_reuses_the_free_one(): void
    {
        $policy = ModelPolicy::tiered(free: ['gemini-flash']);

        $this->assertSame(['gemini-flash'], $policy->chainFor(RunContext::TIER_PRO));
    }

    #[Test]
    public function a_pro_chain_is_not_silently_extended_with_free_models(): void
    {
        $policy = ModelPolicy::tiered(free: ['cheap'], pro: ['expensive']);

        // If a pro tool should be able to degrade that far, its policy says
        // so explicitly. Appending it here would silently hand a paying user
        // a worse model.
        $this->assertSame(['expensive'], $policy->chainFor(RunContext::TIER_PRO));
    }

    #[Test]
    public function a_policy_needs_at_least_one_free_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ModelPolicy::tiered(free: []);
    }

    #[Test]
    public function allows_requires_both_selectability_and_tier_entitlement(): void
    {
        $selectable = ModelPolicy::tiered(free: ['cheap'], pro: ['expensive'], userSelectable: true);

        $this->assertTrue($selectable->allows('cheap', RunContext::TIER_FREE));

        // The security-relevant case: a free caller naming a pro model.
        $this->assertFalse($selectable->allows('expensive', RunContext::TIER_FREE));
        $this->assertTrue($selectable->allows('expensive', RunContext::TIER_PRO));

        // A model nobody listed is never allowed, on any tier.
        $this->assertFalse($selectable->allows('veo-3', RunContext::TIER_PRO));
    }

    #[Test]
    public function a_non_selectable_policy_allows_nothing_to_be_chosen(): void
    {
        $fixed = ModelPolicy::tiered(free: ['cheap'], userSelectable: false);

        // Listed, entitled, and still refused — because this tool does not
        // offer a choice at all.
        $this->assertFalse($fixed->allows('cheap', RunContext::TIER_FREE));
    }

    #[Test]
    public function it_reports_every_model_named_across_all_tiers(): void
    {
        $policy = ModelPolicy::tiered(free: ['a', 'b'], pro: ['b', 'c']);

        $keys = $policy->allTierKeys();
        sort($keys);

        $this->assertSame(['a', 'b', 'c'], $keys);
    }
}
