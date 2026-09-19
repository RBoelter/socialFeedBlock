<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use LogicException;
use TypeError;

/**
 * Gets the posts for a configuration, and makes sure that a slow or failing
 * social network never slows or breaks a journal page.
 *
 * - A fetched feed is cached for the configured time.
 * - A second copy is kept much longer. When a fetch fails, that copy is shown
 *   instead of nothing.
 * - After a failure nothing is fetched for a few minutes, so every page view
 *   does not wait for the same timeout again.
 */
final class FeedService
{
    public const STALE_LIFETIME_SECONDS = 604800;
    public const FAILURE_BACKOFF_SECONDS = 300;

    private const STALE_SUFFIX = ':stale';
    private const FAILED_SUFFIX = ':failed';

    /**
     * @param array<string, FeedProvider> $providers by network name
     * @param Closure(string): void $report receives a message for the server log
     */
    public function __construct(
        private readonly array $providers,
        private readonly Repository $cache,
        private readonly Closure $report
    ) {
    }

    /**
     * The posts to show. Never throws for a feed problem: when there is
     * nothing usable, the result is an empty list.
     *
     * @return list<Post>
     */
    public function getPosts(FeedConfig $config): array
    {
        $key = $config->cacheKey();

        $fresh = $this->read($key);
        if ($fresh !== null) {
            return $fresh;
        }

        if ($this->cache->has($key . self::FAILED_SUFFIX)) {
            return $this->read($key . self::STALE_SUFFIX) ?? [];
        }

        try {
            return $this->refresh($config);
        } catch (FeedException $exception) {
            ($this->report)("socialFeedBlock: {$config->network} feed failed - " . $exception->getMessage());
            $this->cache->put($key . self::FAILED_SUFFIX, true, self::FAILURE_BACKOFF_SECONDS);

            return $this->read($key . self::STALE_SUFFIX) ?? [];
        }
    }

    /**
     * Fetches the feed now, whatever the cache holds, and caches the result.
     *
     * @throws FeedException when the feed cannot be fetched
     *
     * @return list<Post>
     */
    public function refresh(FeedConfig $config): array
    {
        $provider = $this->providers[$config->network]
            ?? throw new LogicException("No provider for the network '{$config->network}'");

        $posts = $provider->fetch($config);

        $key = $config->cacheKey();
        $data = array_map(static fn (Post $post): array => $post->toArray(), $posts);
        $this->cache->put($key, $data, $config->cacheMinutes * 60);
        $this->cache->put($key . self::STALE_SUFFIX, $data, self::STALE_LIFETIME_SECONDS);
        $this->cache->forget($key . self::FAILED_SUFFIX);

        return $posts;
    }

    /**
     * @return ?list<Post> null when there is no entry, or one that this version of
     *                     the plugin cannot read
     */
    private function read(string $key): ?array
    {
        $data = $this->cache->get($key);
        if (!is_array($data)) {
            return null;
        }

        try {
            return array_values(array_map(Post::fromArray(...), $data));
        } catch (InvalidArgumentException | TypeError) {
            return null;
        }
    }
}
