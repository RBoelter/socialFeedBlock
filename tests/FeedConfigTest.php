<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedConfigTest extends TestCase
{
    private function mastodon(
        string $target = 'PublicKnowledgeProject',
        int $postCount = 5,
        bool $excludeReplies = false,
        int $cacheMinutes = 15
    ): FeedConfig {
        return new FeedConfig(
            FeedConfig::NETWORK_MASTODON,
            FeedConfig::SOURCE_ACCOUNT,
            $target,
            'mastodon.social',
            $postCount,
            $excludeReplies,
            false,
            $cacheMinutes
        );
    }

    #[Test]
    public function theCacheKeyIsStableForTheSameContent(): void
    {
        self::assertSame($this->mastodon()->cacheKey(), $this->mastodon()->cacheKey());
        self::assertStringStartsWith('socialFeedBlock:v1:', $this->mastodon()->cacheKey());
    }

    #[Test]
    public function theCacheKeyChangesWithEverySettingThatAltersTheContent(): void
    {
        $keys = [
            $this->mastodon()->cacheKey(),
            $this->mastodon('OtherAccount')->cacheKey(),
            $this->mastodon(postCount: 6)->cacheKey(),
            $this->mastodon(excludeReplies: true)->cacheKey(),
            (new FeedConfig('mastodon', 'account', 'PublicKnowledgeProject', 'other.social', 5, false, false, 15))->cacheKey(),
            (new FeedConfig('mastodon', 'account', 'PublicKnowledgeProject', 'mastodon.social', 5, false, true, 15))->cacheKey(),
        ];

        self::assertCount(count($keys), array_unique($keys));
    }

    #[Test]
    public function theCacheKeyIgnoresTheCacheLifetime(): void
    {
        self::assertSame($this->mastodon(cacheMinutes: 15)->cacheKey(), $this->mastodon(cacheMinutes: 60)->cacheKey());
    }

    #[Test]
    public function profileUrlOfAMastodonAccount(): void
    {
        self::assertSame('https://mastodon.social/@PublicKnowledgeProject', $this->mastodon()->profileUrl());
    }

    #[Test]
    public function profileUrlOfAMastodonHashtagIsEncoded(): void
    {
        $config = new FeedConfig('mastodon', 'hashtag', 'Café', 'mastodon.social', 5, false, false, 15);

        self::assertSame('https://mastodon.social/tags/Caf%C3%A9', $config->profileUrl());
    }

    #[Test]
    public function profileUrlOfABlueskyAuthor(): void
    {
        $config = new FeedConfig('bluesky', 'author', 'pkp.sfu.ca', null, 5, false, false, 15);

        self::assertSame('https://bsky.app/profile/pkp.sfu.ca', $config->profileUrl());
    }

    #[Test]
    public function profileUrlOfABlueskyFeedPointsToTheFeedPage(): void
    {
        $config = new FeedConfig(
            'bluesky',
            'feed',
            'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.generator/whats-hot',
            null,
            5,
            false,
            false,
            15
        );

        self::assertSame('https://bsky.app/profile/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/feed/whats-hot', $config->profileUrl());
    }
}
