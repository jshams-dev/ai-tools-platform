<?php

declare(strict_types=1);

namespace App\Usage;

use App\AI\ModelPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * Who is making this call, under what plan, and what they are allowed to use.
 *
 * Passed down from the entry point rather than read from globals. That is what
 * lets the same tool handler serve a Livewire request, a REST call, and a
 * queued job without caring which it is — and what makes every one of these
 * paths testable without faking a session.
 *
 * It also keeps the gateway Octane-safe: nothing request-scoped is stored in
 * a singleton, because the request-scoped part travels as an argument.
 */
final readonly class RunContext
{
    public const TIER_GUEST = 'guest';

    public const TIER_FREE = 'free';

    public const TIER_PRO = 'pro';

    /**
     * @param  string  $ipHash  Never the raw address — see hashIp().
     * @param  string  $toolSlug  Recorded on every usage row; tools live in
     *                            code, so this is a plain string with no FK.
     * @param  string|null  $requestedModel  The caller's explicit choice.
     *                                       Untrusted input: validated against
     *                                       the policy *and* the tier before
     *                                       it is honoured.
     */
    public function __construct(
        public string $toolSlug,
        public ModelPolicy $policy,
        public string $ipHash,
        public ?int $userId = null,
        public string $tier = self::TIER_GUEST,
        public ?string $requestedModel = null,
    ) {}

    public static function forGuest(
        string $toolSlug,
        ModelPolicy $policy,
        string $ip,
        ?string $requestedModel = null,
    ): self {
        return new self(
            toolSlug: $toolSlug,
            policy: $policy,
            ipHash: self::hashIp($ip),
            tier: self::TIER_GUEST,
            requestedModel: $requestedModel,
        );
    }

    public static function forUser(
        string $toolSlug,
        ModelPolicy $policy,
        User $user,
        string $ip,
        string $tier = self::TIER_FREE,
        ?string $requestedModel = null,
    ): self {
        return new self(
            toolSlug: $toolSlug,
            policy: $policy,
            ipHash: self::hashIp($ip),
            userId: $user->id,
            tier: $tier,
            requestedModel: $requestedModel,
        );
    }

    /**
     * The context for whoever is calling: a signed-in user, or a guest by IP.
     */
    public static function forCaller(
        string $toolSlug,
        ModelPolicy $policy,
        ?User $user,
        string $ip,
        ?string $requestedModel = null,
    ): self {
        return $user === null
            ? self::forGuest($toolSlug, $policy, $ip, $requestedModel)
            : self::forUser($toolSlug, $policy, $user, $ip, self::tierFor($user), $requestedModel);
    }

    /**
     * The tier a caller runs under. Every account is on the free tier until
     * plans exist (phase 6).
     */
    public static function tierFor(?User $user): string
    {
        return $user === null ? self::TIER_GUEST : self::TIER_FREE;
    }

    /**
     * Hash an IP address with the app key as a pepper.
     *
     * Stored instead of the raw address for two reasons: the logs stop being
     * personal data worth leaking, and a per-IP counter works just as well on
     * a hash. The pepper matters — an unsalted hash of an IPv4 address is
     * trivially reversible, since there are only four billion of them.
     */
    public static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) Config::get('app.key'));
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * Identity for quota accounting (§11).
     *
     * A guest is metered by IP hash, a signed-in user by id — so signing in
     * does not hand someone a fresh allowance, and sharing an office NAT does
     * not merge two users' quotas.
     *
     * @return array{type: string, id: string}
     */
    public function subject(): array
    {
        return $this->isGuest()
            ? ['type' => 'ip', 'id' => $this->ipHash]
            : ['type' => 'user', 'id' => (string) $this->userId];
    }
}
