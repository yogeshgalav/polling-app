<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

final class CachedPollIds
{
    public const KEY = 'cachedPollIds';
    public const LIMIT = 50;
    public const TTL_SECONDS = 60 * 60 * 24; // 24h

    private const LOCK_KEY = 'cachedPollIds:lock';
    private const LOCK_TTL_SECONDS = 5;
    private const LOCK_WAIT_SECONDS = 2;

    /**
     * @return list<int>
     */
    public static function getOrBuild(callable $builder): array
    {
        $ids = Cache::get(self::KEY);
        if (is_array($ids)) {
            return self::normalize($ids);
        }

        $ids = $builder();
        $ids = is_array($ids) ? self::normalize($ids) : [];
        Cache::put(self::KEY, $ids, now()->addSeconds(self::TTL_SECONDS));

        return $ids;
    }

    /**
     * Read the cached ids without rebuilding.
     *
     * @return list<int>
     */
    public static function get(): array
    {
        $ids = Cache::get(self::KEY, []);
        return is_array($ids) ? self::normalize($ids) : [];
    }

    public static function contains(int $pollId): bool
    {
        return in_array($pollId, self::get(), true);
    }

    /**
     * Move the id to the front and trim to LIMIT.
     *
     * @return array{ids: list<int>, evicted_id: ?int}
     */
    public static function touch(int $pollId): array
    {
        return self::mutate(function (array $ids) use ($pollId): array {
            $ids = array_values(array_filter($ids, fn ($id) => (int) $id !== $pollId));
            array_unshift($ids, $pollId);

            $evicted = null;
            if (count($ids) > self::LIMIT) {
                $evicted = array_pop($ids);
                $evicted = $evicted === null ? null : (int) $evicted;
            }

            return [$ids, $evicted];
        });
    }

    /**
     * Add id to the front (same as touch()).
     *
     * @return array{ids: list<int>, evicted_id: ?int}
     */
    public static function add(int $pollId): array
    {
        return self::touch($pollId);
    }

    /**
     * Remove id from the list.
     *
     * @return list<int>
     */
    public static function remove(int $pollId): array
    {
        return self::mutate(function (array $ids) use ($pollId): array {
            $ids = array_values(array_filter($ids, fn ($id) => (int) $id !== $pollId));
            return [$ids, null];
        })['ids'];
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * @return array{ids: list<int>, evicted_id: ?int}
     */
    private static function mutate(callable $fn): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($fn): array {
            $current = Cache::get(self::KEY, []);
            $current = is_array($current) ? self::normalize($current) : [];

            /** @var array{0: list<int>, 1: ?int} $result */
            $result = $fn($current);
            [$next, $evicted] = $result;

            Cache::put(self::KEY, $next, now()->addSeconds(self::TTL_SECONDS));

            return ['ids' => $next, 'evicted_id' => $evicted];
        });
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->take(self::LIMIT)
            ->all();
    }
}

