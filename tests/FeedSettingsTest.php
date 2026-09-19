<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedSettingsTest extends TestCase
{
    private const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function validSettings(): iterable
    {
        $mastodon = [
            'network' => 'mastodon',
            'mastodonInstance' => 'mastodon.social',
            'mastodonSource' => 'account',
            'mastodonHandle' => 'PublicKnowledgeProject',
        ];

        yield 'mastodon account' => [$mastodon, [
            'network' => 'mastodon',
            'source' => 'account',
            'target' => 'PublicKnowledgeProject',
            'instance' => 'mastodon.social',
        ]];
        yield 'mastodon account with @ and a mixed-case host' => [
            ['mastodonInstance' => 'Mastodon.Social', 'mastodonHandle' => '@PublicKnowledgeProject'] + $mastodon,
            ['instance' => 'mastodon.social', 'target' => 'PublicKnowledgeProject'],
        ];
        yield 'mastodon account with dots and hyphens' => [
            ['mastodonHandle' => 'open-access.news'] + $mastodon,
            ['target' => 'open-access.news'],
        ];
        yield 'mastodon hashtag with #' => [
            ['mastodonSource' => 'hashtag', 'mastodonHandle' => '#OpenAccess'] + $mastodon,
            ['source' => 'hashtag', 'target' => 'OpenAccess'],
        ];
        yield 'mastodon hashtag with non-ASCII letters' => [
            ['mastodonSource' => 'hashtag', 'mastodonHandle' => 'Wissenschaftsfreiheit'] + $mastodon,
            ['target' => 'Wissenschaftsfreiheit'],
        ];
        yield 'mastodon on an internationalised host' => [
            ['mastodonInstance' => 'social.xn--p1ai'] + $mastodon,
            ['instance' => 'social.xn--p1ai'],
        ];
        yield 'bluesky author by handle' => [
            ['network' => 'bluesky', 'blueskySource' => 'author', 'blueskyActor' => '@pkp.sfu.ca'],
            ['network' => 'bluesky', 'source' => 'author', 'target' => 'pkp.sfu.ca', 'instance' => null],
        ];
        yield 'bluesky author by DID' => [
            ['network' => 'bluesky', 'blueskySource' => 'author', 'blueskyActor' => self::DID],
            ['target' => self::DID],
        ];
        yield 'bluesky custom feed' => [
            [
                'network' => 'bluesky',
                'blueskySource' => 'feed',
                'blueskyActor' => 'at://' . self::DID . '/app.bsky.feed.generator/whats-hot',
            ],
            ['source' => 'feed', 'target' => 'at://' . self::DID . '/app.bsky.feed.generator/whats-hot'],
        ];
        yield 'the other network\'s fields are ignored' => [
            [
                'network' => 'bluesky',
                'blueskySource' => 'author',
                'blueskyActor' => 'pkp.sfu.ca',
                'mastodonInstance' => 'not a host at all',
                'mastodonHandle' => '!!',
            ],
            ['network' => 'bluesky', 'target' => 'pkp.sfu.ca'],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $expected properties of the resulting FeedConfig
     */
    #[Test]
    #[DataProvider('validSettings')]
    public function validSettingsProduceAConfig(array $settings, array $expected): void
    {
        $config = FeedSettings::config($settings);

        self::assertNotNull($config);
        self::assertSame([], FeedSettings::errors($settings));
        foreach ($expected as $property => $value) {
            self::assertSame($value, $config->$property, $property);
        }
    }

    #[Test]
    public function defaultsApplyWhenNumbersAreLeftEmpty(): void
    {
        $config = FeedSettings::config([
            'network' => 'bluesky',
            'blueskySource' => 'author',
            'blueskyActor' => 'pkp.sfu.ca',
            'postCount' => '',
            'cacheTtl' => '  ',
        ]);

        self::assertSame(FeedConfig::DEFAULT_POSTS, $config->postCount);
        self::assertSame(FeedConfig::DEFAULT_CACHE_MINUTES, $config->cacheMinutes);
    }

    #[Test]
    public function numbersAndFlagsAreParsedFromFormValues(): void
    {
        $config = FeedSettings::config([
            'network' => 'bluesky',
            'blueskySource' => 'author',
            'blueskyActor' => 'pkp.sfu.ca',
            'postCount' => '20',
            'cacheTtl' => '1440',
            'excludeReplies' => '1',
            'excludeReposts' => true,
        ]);

        self::assertSame(20, $config->postCount);
        self::assertSame(1440, $config->cacheMinutes);
        self::assertTrue($config->excludeReplies);
        self::assertTrue($config->excludeReposts);
    }

    #[Test]
    public function flagsDefaultToFalse(): void
    {
        $config = FeedSettings::config([
            'network' => 'bluesky',
            'blueskySource' => 'author',
            'blueskyActor' => 'pkp.sfu.ca',
        ]);

        self::assertFalse($config->excludeReplies);
        self::assertFalse($config->excludeReposts);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function invalidSettings(): iterable
    {
        $mastodon = [
            'network' => 'mastodon',
            'mastodonInstance' => 'mastodon.social',
            'mastodonSource' => 'account',
            'mastodonHandle' => 'PublicKnowledgeProject',
        ];
        $bluesky = ['network' => 'bluesky', 'blueskySource' => 'author', 'blueskyActor' => 'pkp.sfu.ca'];

        yield 'nothing configured' => [[], ['network']];
        yield 'unknown network' => [['network' => 'x'], ['network']];

        foreach (
            [
                'a URL' => 'https://mastodon.social',
                'a host with a path' => 'mastodon.social/api',
                'a host with a port' => 'mastodon.social:8080',
                'localhost' => 'localhost',
                'an IPv4 address' => '127.0.0.1',
                'an IPv6 address' => '[::1]',
                'a host with a space' => 'mastodon .social',
                'an empty host' => '',
                'a host with a leading hyphen' => '-a.social',
            ] as $label => $host
        ) {
            yield "mastodon instance is {$label}" => [['mastodonInstance' => $host] + $mastodon, ['mastodonInstance']];
        }

        yield 'mastodon source unknown' => [['mastodonSource' => 'list'] + $mastodon, ['mastodonSource']];
        yield 'mastodon account with a server' => [['mastodonHandle' => 'user@other.social'] + $mastodon, ['mastodonHandle']];
        yield 'mastodon account empty' => [['mastodonHandle' => '@'] + $mastodon, ['mastodonHandle']];
        yield 'mastodon account with a slash' => [['mastodonHandle' => 'user/../x'] + $mastodon, ['mastodonHandle']];
        yield 'mastodon hashtag with a space' => [
            ['mastodonSource' => 'hashtag', 'mastodonHandle' => 'open access'] + $mastodon,
            ['mastodonHandle'],
        ];
        yield 'mastodon hashtag with a query string' => [
            ['mastodonSource' => 'hashtag', 'mastodonHandle' => 'tag?limit=100'] + $mastodon,
            ['mastodonHandle'],
        ];

        yield 'bluesky source unknown' => [['blueskySource' => 'search'] + $bluesky, ['blueskySource']];
        yield 'bluesky handle without a dot' => [['blueskyActor' => 'pkp'] + $bluesky, ['blueskyActor']];
        yield 'bluesky handle with a space' => [['blueskyActor' => 'pkp .sfu.ca'] + $bluesky, ['blueskyActor']];
        yield 'bluesky author given a feed address' => [
            ['blueskyActor' => 'at://did:plc:abc/app.bsky.feed.generator/x'] + $bluesky,
            ['blueskyActor'],
        ];
        yield 'bluesky feed given a handle' => [['blueskySource' => 'feed'] + $bluesky, ['blueskyActor']];
        yield 'bluesky feed addressed by handle instead of DID' => [
            ['blueskySource' => 'feed', 'blueskyActor' => 'at://bsky.app/app.bsky.feed.generator/whats-hot'] + $bluesky,
            ['blueskyActor'],
        ];
        yield 'bluesky feed of the wrong record type' => [
            ['blueskySource' => 'feed', 'blueskyActor' => 'at://did:plc:abc/app.bsky.feed.post/3k'] + $bluesky,
            ['blueskyActor'],
        ];

        foreach (['0', '21', '-1', '1.5', 'abc', '99999999'] as $count) {
            yield "post count {$count}" => [['postCount' => $count] + $bluesky, ['postCount']];
        }
        foreach (['4', '1441', 'x'] as $minutes) {
            yield "cache lifetime {$minutes}" => [['cacheTtl' => $minutes] + $bluesky, ['cacheTtl']];
        }

        yield 'several errors are all reported' => [
            ['mastodonHandle' => 'a@b', 'postCount' => '0', 'cacheTtl' => '1'] + $mastodon,
            ['mastodonHandle', 'postCount', 'cacheTtl'],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<string> $expectedFields
     */
    #[Test]
    #[DataProvider('invalidSettings')]
    public function invalidSettingsAreRejectedPerField(array $settings, array $expectedFields): void
    {
        $errors = FeedSettings::errors($settings);

        self::assertEqualsCanonicalizing($expectedFields, array_keys($errors));
        self::assertNull(FeedSettings::config($settings));
    }

    #[Test]
    public function errorsCarryALocaleKeyAndMessageParameters(): void
    {
        $errors = FeedSettings::errors([
            'network' => 'bluesky',
            'blueskySource' => 'author',
            'blueskyActor' => 'pkp',
            'postCount' => '0',
        ]);

        self::assertSame('plugins.blocks.socialFeed.validation.blueskyActor', $errors['blueskyActor']['key']);
        self::assertSame([], $errors['blueskyActor']['params']);
        self::assertSame('plugins.blocks.socialFeed.validation.range', $errors['postCount']['key']);
        self::assertSame(['min' => 1, 'max' => 20], $errors['postCount']['params']);
    }

    #[Test]
    public function nonScalarSettingValuesAreTreatedAsEmpty(): void
    {
        $errors = FeedSettings::errors(['network' => ['mastodon']]);

        self::assertArrayHasKey('network', $errors);
    }
}
