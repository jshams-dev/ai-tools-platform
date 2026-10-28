<?php

declare(strict_types=1);

namespace Tests\Feature\Usage;

use App\Models\User;
use App\Repositories\UsageLogRepository;
use App\Usage\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The repository exists because this is the table most likely to outgrow the
 * primary database. These tests pin the two aggregate reads that the spend cap
 * and the quota system are about to depend on.
 */
final class UsageLogRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): UsageLogRepository
    {
        return $this->app->make(UsageLogRepository::class);
    }

    private function record(array $overrides = []): UsageRecord
    {
        return new UsageRecord(...array_merge([
            'toolSlug' => 'text-transformer',
            'capability' => 'text_generation',
            'provider' => 'anthropic',
            'model' => 'claude-haiku',
            'status' => 'ok',
            'ipHash' => str_repeat('a', 64),
        ], $overrides));
    }

    #[Test]
    public function it_persists_a_record(): void
    {
        $this->repository()->record($this->record([
            'inputTokens' => 100,
            'outputTokens' => 50,
            'costUsd' => 0.00035,
            'durationMs' => 1234,
        ]));

        $this->assertDatabaseHas('usage_logs', [
            'tool_slug' => 'text-transformer',
            'model' => 'claude-haiku',
            'input_tokens' => 100,
            'cost_usd' => 0.00035,
            'duration_ms' => 1234,
        ]);
    }

    #[Test]
    public function spend_today_sums_only_todays_real_cost(): void
    {
        $repo = $this->repository();

        $repo->record($this->record(['costUsd' => 0.10]));
        $repo->record($this->record(['costUsd' => 0.25]));

        // A cached hit records zero: it cost nothing, however many tokens the
        // original call consumed.
        $repo->record($this->record(['costUsd' => 0.0, 'wasCached' => true]));

        // A failure never reached a vendor, so it never cost anything.
        $repo->record($this->record(['status' => 'failed', 'costUsd' => null]));

        $this->assertSame(0.35, round($repo->spendToday(), 6));
    }

    #[Test]
    public function spend_today_ignores_yesterday(): void
    {
        $repo = $this->repository();
        $repo->record($this->record(['costUsd' => 5.00]));

        // The cap is a *daily* budget, so it has to reset. Travelling forward
        // a day is the honest way to prove the boundary works.
        Date::setTestNow(Date::now()->addDay());

        $this->assertSame(0.0, $repo->spendToday());

        Date::setTestNow();
    }

    #[Test]
    public function it_counts_calls_for_a_guest_by_ip_hash(): void
    {
        $repo = $this->repository();
        $mine = str_repeat('b', 64);

        $repo->record($this->record(['ipHash' => $mine]));
        $repo->record($this->record(['ipHash' => $mine]));
        $repo->record($this->record(['ipHash' => str_repeat('c', 64)]));

        $this->assertSame(2, $repo->countForSubject(
            ['type' => 'ip', 'id' => $mine],
            Date::now()->subHour(),
        ));
    }

    #[Test]
    public function a_signed_in_users_calls_are_not_counted_against_their_ip(): void
    {
        $repo = $this->repository();
        $user = User::factory()->create();
        $ip = str_repeat('d', 64);

        $repo->record($this->record(['ipHash' => $ip, 'userId' => $user->id]));
        $repo->record($this->record(['ipHash' => $ip]));

        // Otherwise a signed-in user on a shared NAT would burn through the
        // guest allowance of everyone behind it.
        $this->assertSame(1, $repo->countForSubject(['type' => 'ip', 'id' => $ip], Date::now()->subHour()));
        $this->assertSame(1, $repo->countForSubject(['type' => 'user', 'id' => (string) $user->id], Date::now()->subHour()));
    }

    #[Test]
    public function it_counts_only_within_the_period(): void
    {
        $repo = $this->repository();
        $ip = str_repeat('e', 64);

        $repo->record($this->record(['ipHash' => $ip]));

        Date::setTestNow(Date::now()->addHours(2));
        $repo->record($this->record(['ipHash' => $ip]));

        $this->assertSame(1, $repo->countForSubject(
            ['type' => 'ip', 'id' => $ip],
            Date::now()->subHour(),
        ));

        Date::setTestNow();
    }
}
