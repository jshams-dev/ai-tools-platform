<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\Results\AiResult;
use App\AI\Results\TextResult;
use App\AI\Results\TranslationResult;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Stores finished AI results so an identical call is not paid for twice.
 *
 * Note where this lives: `app/AI/`, not `app/Repositories/`. A repository
 * means database access, and a cache is not a database — it is allowed to
 * forget, which is the one thing a repository must never do.
 *
 * It is a thin wrapper over Laravel's cache rather than a store of its own.
 * The only thing worth writing by hand is the serialisation: results are
 * polymorphic, and `serialize()` on a readonly object would couple every
 * cached row to the exact class shape at the moment it was written. A rename
 * or a new constructor argument would then raise errors from inside the cache
 * on the next deploy. An explicit array plus a type tag survives that.
 */
final class AiResponseCache
{
    /**
     * Result classes that may be cached, by their stored tag.
     *
     * An explicit allow-list, not a class name taken from the stored payload:
     * instantiating whatever class a cache entry names would turn a writable
     * cache into arbitrary object construction.
     *
     * @var array<string, class-string<AiResult>>
     */
    private const TYPES = [
        'text' => TextResult::class,
        'translation' => TranslationResult::class,
    ];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $ttlSeconds = 86_400,
    ) {}

    public function get(string $key): ?AiResult
    {
        $payload = $this->cache->get($this->prefixed($key));

        if (! is_array($payload)) {
            return null;
        }

        $type = $payload['__type'] ?? null;
        $class = is_string($type) ? (self::TYPES[$type] ?? null) : null;

        if ($class === null) {
            // Written by an older version whose shape we no longer recognise.
            // Treated as a miss: re-running the call costs money, but serving
            // a half-understood payload costs correctness.
            return null;
        }

        unset($payload['__type']);

        return $class::fromArray($payload);
    }

    public function put(string $key, AiResult $result): void
    {
        $type = array_search($result::class, self::TYPES, strict: true);

        if ($type === false) {
            // Not cacheable yet. Silently skipping is correct — a new result
            // type should not break the call that produced it — but it must
            // not silently *appear* cached either, hence no write.
            return;
        }

        $this->cache->put(
            $this->prefixed($key),
            ['__type' => $type, ...$result->toArray()],
            $this->ttlSeconds,
        );
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->prefixed($key));
    }

    /**
     * Namespaced so that flushing AI results never touches sessions, rate
     * limiter buckets, or anything else sharing the same store.
     */
    private function prefixed(string $key): string
    {
        return 'ai:result:'.$key;
    }
}
