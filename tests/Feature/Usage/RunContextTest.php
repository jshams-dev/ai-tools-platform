<?php

declare(strict_types=1);

namespace Tests\Feature\Usage;

use App\AI\ModelPolicy;
use App\Models\User;
use App\Usage\RunContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RunContextTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): ModelPolicy
    {
        return ModelPolicy::tiered(free: ['claude-haiku']);
    }

    #[Test]
    public function it_never_stores_the_raw_ip(): void
    {
        $ctx = RunContext::forGuest('t', $this->policy(), '198.51.100.4');

        $this->assertNotSame('198.51.100.4', $ctx->ipHash);
        $this->assertStringNotContainsString('198.51.100', $ctx->ipHash);
        $this->assertSame(64, strlen($ctx->ipHash));
    }

    #[Test]
    public function the_same_ip_hashes_consistently(): void
    {
        // A per-IP counter only works if the hash is stable across requests.
        $this->assertSame(
            RunContext::hashIp('198.51.100.4'),
            RunContext::hashIp('198.51.100.4'),
        );
    }

    #[Test]
    public function different_ips_hash_differently(): void
    {
        $this->assertNotSame(
            RunContext::hashIp('198.51.100.4'),
            RunContext::hashIp('198.51.100.5'),
        );
    }

    #[Test]
    public function the_hash_is_peppered_with_the_app_key(): void
    {
        $first = RunContext::hashIp('198.51.100.4');

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $second = RunContext::hashIp('198.51.100.4');

        // Without the pepper, an unsalted hash of an IPv4 address is
        // trivially reversible — there are only four billion of them.
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function a_guest_is_metered_by_ip_and_a_user_by_id(): void
    {
        $guest = RunContext::forGuest('t', $this->policy(), '198.51.100.4');
        $this->assertTrue($guest->isGuest());
        $this->assertSame(['type' => 'ip', 'id' => $guest->ipHash], $guest->subject());

        $user = User::factory()->create();
        $signedIn = RunContext::forUser('t', $this->policy(), $user, '198.51.100.4');

        // Signing in must not hand someone a fresh allowance, and an office
        // NAT must not merge two users' quotas.
        $this->assertFalse($signedIn->isGuest());
        $this->assertSame(['type' => 'user', 'id' => (string) $user->id], $signedIn->subject());
    }

    #[Test]
    public function a_guest_defaults_to_the_guest_tier(): void
    {
        $this->assertSame(
            RunContext::TIER_GUEST,
            RunContext::forGuest('t', $this->policy(), '198.51.100.4')->tier,
        );
    }
}
