<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * What to show: which network, which source and how many posts.
 *
 * Instances are only created by FeedSettings from validated settings, so every
 * value here can be trusted. That includes the target: it has been checked
 * against a strict pattern and can be used to build a request URL.
 */
final class FeedConfig
{
    public const NETWORK_MASTODON = 'mastodon';
    public const NETWORK_BLUESKY = 'bluesky';

    public const SOURCE_ACCOUNT = 'account';
    public const SOURCE_HASHTAG = 'hashtag';
    public const SOURCE_AUTHOR = 'author';
    public const SOURCE_FEED = 'feed';

    public const MIN_POSTS = 1;
    public const MAX_POSTS = 20;
    public const DEFAULT_POSTS = 5;

    public const MIN_CACHE_MINUTES = 5;
    public const MAX_CACHE_MINUTES = 1440;
    public const DEFAULT_CACHE_MINUTES = 15;

    private const BLUESKY_WEB = 'https://bsky.app';
    private const AT_URI_SCHEME = 'at://';

    /**
     * @param string $target the account name or hashtag (Mastodon, without @ or #),
     *                       the handle or DID (Bluesky author) or the at:// address (Bluesky feed)
     * @param ?string $instance the Mastodon server's host name, null for Bluesky
     */
    public function __construct(
        public readonly string $network,
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $instance,
        public readonly int $postCount,
        public readonly bool $excludeReplies,
        public readonly bool $excludeReposts,
        public readonly int $cacheMinutes
    ) {
    }

    /**
     * Identifies the fetched content: two configurations that would fetch the
     * same posts share a key, and changing any setting that alters the result
     * changes the key, so a settings change never serves stale content. The
     * cache lifetime is left out on purpose, it does not alter the content.
     */
    public function cacheKey(): string
    {
        $content = json_encode([
            $this->network,
            $this->source,
            $this->target,
            $this->instance,
            $this->postCount,
            $this->excludeReplies,
            $this->excludeReposts,
        ], JSON_THROW_ON_ERROR);

        return 'socialFeedBlock:v1:' . sha1($content);
    }

    /** The public page of the source on the social network, for a "more posts" link. */
    public function profileUrl(): string
    {
        return match ($this->source) {
            self::SOURCE_ACCOUNT => "https://{$this->instance}/@{$this->target}",
            self::SOURCE_HASHTAG => "https://{$this->instance}/tags/" . rawurlencode($this->target),
            self::SOURCE_FEED => $this->blueskyFeedUrl(),
            default => self::BLUESKY_WEB . '/profile/' . rawurlencode($this->target),
        };
    }

    /** at://<did>/app.bsky.feed.generator/<name> becomes bsky.app/profile/<did>/feed/<name> */
    private function blueskyFeedUrl(): string
    {
        [$did, , $name] = explode('/', substr($this->target, strlen(self::AT_URI_SCHEME)));

        return self::BLUESKY_WEB . '/profile/' . rawurlencode($did) . '/feed/' . rawurlencode($name);
    }
}
