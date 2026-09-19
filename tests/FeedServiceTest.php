<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedError;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use APP\plugins\blocks\socialFeedBlock\classes\FeedService;
use APP\plugins\blocks\socialFeedBlock\classes\Post;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeedServiceTest extends TestCase
{
    private const START = '2026-09-19 12:00:00';

    private FakeProvider $provider;

    private Repository $cache;

    /** @var list<string> */
    private array $reported = [];

    private FeedService $service;

    protected function setUp(): void
    {
        Carbon::setTestNow(self::START);
        $this->provider = new FakeProvider();
        $this->cache = new Repository(new ArrayStore());
        $this->reported = [];
        $this->service = new FeedService(
            ['mastodon' => $this->provider],
            $this->cache,
            function (string $message): void {
                $this->reported[] = $message;
            }
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function config(string $target = 'examplejournal', int $cacheMinutes = 15): FeedConfig
    {
        return new FeedConfig('mastodon', 'account', $target, 'mastodon.example', 5, false, false, $cacheMinutes);
    }

    private function later(int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse(self::START)->addMinutes($minutes));
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<string>
     */
    private static function names(array $posts): array
    {
        return array_map(static fn (Post $post): string => $post->authorName, $posts);
    }

    #[Test]
    public function aFeedIsFetchedOnceAndThenServedFromTheCache(): void
    {
        $this->provider->answers = [[FakeProvider::post('first')]];

        self::assertSame(['first'], self::names($this->service->getPosts($this->config())));
        self::assertSame(['first'], self::names($this->service->getPosts($this->config())));

        self::assertSame(1, $this->provider->calls);
    }

    #[Test]
    public function theCacheLivesForTheConfiguredTime(): void
    {
        $this->provider->answers = [[FakeProvider::post('first')], [FakeProvider::post('second')]];
        $this->service->getPosts($this->config(cacheMinutes: 15));

        $this->later(14);
        self::assertSame(['first'], self::names($this->service->getPosts($this->config(cacheMinutes: 15))));

        $this->later(16);
        self::assertSame(['second'], self::names($this->service->getPosts($this->config(cacheMinutes: 15))));
        self::assertSame(2, $this->provider->calls);
    }

    #[Test]
    public function anEmptyFeedIsCachedToo(): void
    {
        $this->provider->answers = [[]];

        $this->service->getPosts($this->config());
        $this->service->getPosts($this->config());

        self::assertSame(1, $this->provider->calls);
    }

    #[Test]
    public function aChangedSettingNeverServesTheOldContent(): void
    {
        $this->provider->answers = [[FakeProvider::post('one')], [FakeProvider::post('other')]];

        $this->service->getPosts($this->config('examplejournal'));
        $posts = $this->service->getPosts($this->config('anotheraccount'));

        self::assertSame(['other'], self::names($posts));
    }

    #[Test]
    public function aFailureWithoutAnEarlierCopyGivesAnEmptyListAndIsReportedOnce(): void
    {
        $this->provider->answers = [new FeedException(FeedError::Unreachable, 'timed out')];

        self::assertSame([], $this->service->getPosts($this->config()));

        self::assertCount(1, $this->reported);
        self::assertStringContainsString('mastodon', $this->reported[0]);
        self::assertStringContainsString('unreachable: timed out', $this->reported[0]);
    }

    #[Test]
    public function afterAFailureNothingIsFetchedAgainForAWhile(): void
    {
        $this->provider->answers = [new FeedException(FeedError::Unreachable, 'timed out')];
        $this->service->getPosts($this->config());

        $this->later(4);
        self::assertSame([], $this->service->getPosts($this->config()));

        self::assertSame(1, $this->provider->calls, 'the provider must not be asked again within the back-off');
        self::assertCount(1, $this->reported, 'and the same failure is not logged again');
    }

    #[Test]
    public function afterTheBackOffTheFeedIsTriedAgain(): void
    {
        $this->provider->answers = [new FeedException(FeedError::Unreachable, 'timed out'), [FakeProvider::post('recovered')]];
        $this->service->getPosts($this->config());

        $this->later(6);

        self::assertSame(['recovered'], self::names($this->service->getPosts($this->config())));
        self::assertSame(2, $this->provider->calls);
    }

    #[Test]
    public function whenAFetchFailsTheLastGoodCopyIsShown(): void
    {
        $this->provider->answers = [[FakeProvider::post('good')], new FeedException(FeedError::ServerError, 'HTTP 503')];
        $this->service->getPosts($this->config(cacheMinutes: 15));

        $this->later(20); // the fresh copy has expired, the fetch now fails

        self::assertSame(['good'], self::names($this->service->getPosts($this->config(cacheMinutes: 15))));
        self::assertCount(1, $this->reported);
    }

    #[Test]
    public function theLastGoodCopyIsStillShownDuringTheBackOff(): void
    {
        $this->provider->answers = [[FakeProvider::post('good')], new FeedException(FeedError::ServerError, 'HTTP 503')];
        $this->service->getPosts($this->config(cacheMinutes: 15));
        $this->later(20);
        $this->service->getPosts($this->config(cacheMinutes: 15));

        $this->later(22);

        self::assertSame(['good'], self::names($this->service->getPosts($this->config(cacheMinutes: 15))));
        self::assertSame(2, $this->provider->calls);
    }

    #[Test]
    public function theLastGoodCopyExpiresAfterSevenDays(): void
    {
        $this->provider->answers = [[FakeProvider::post('good')], new FeedException(FeedError::ServerError, 'HTTP 503')];
        $this->service->getPosts($this->config());

        Carbon::setTestNow(Carbon::parse(self::START)->addSeconds(FeedService::STALE_LIFETIME_SECONDS + 60));

        self::assertSame([], $this->service->getPosts($this->config()));
    }

    #[Test]
    public function refreshFetchesAtOnceAndPrimesTheCache(): void
    {
        $this->provider->answers = [[FakeProvider::post('primed')]];

        $refreshed = $this->service->refresh($this->config());
        $served = $this->service->getPosts($this->config());

        self::assertSame(['primed'], self::names($refreshed));
        self::assertSame(['primed'], self::names($served));
        self::assertSame(1, $this->provider->calls);
    }

    #[Test]
    public function refreshIgnoresAFreshCacheEntry(): void
    {
        $this->provider->answers = [[FakeProvider::post('old')], [FakeProvider::post('new')]];
        $this->service->getPosts($this->config());

        $this->service->refresh($this->config());

        self::assertSame(['new'], self::names($this->service->getPosts($this->config())));
    }

    #[Test]
    public function refreshPassesTheFailureOnAndLeavesTheCacheAlone(): void
    {
        $this->provider->answers = [[FakeProvider::post('good')], new FeedException(FeedError::NotFound, 'HTTP 404')];
        $this->service->getPosts($this->config());

        try {
            $this->service->refresh($this->config());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::NotFound, $exception->getError());
        }

        self::assertSame(['good'], self::names($this->service->getPosts($this->config())), 'the cached copy survives');
        self::assertSame([], $this->reported, 'refresh reports to its caller, not to the log');
    }

    #[Test]
    public function aSuccessfulRefreshEndsTheBackOff(): void
    {
        $this->provider->answers = [new FeedException(FeedError::Unreachable, 'timed out'), [FakeProvider::post('back')], [FakeProvider::post('unused')]];
        $this->service->getPosts($this->config());

        $this->service->refresh($this->config());

        self::assertSame(['back'], self::names($this->service->getPosts($this->config())));
    }

    #[Test]
    public function aCacheEntryOfAnUnknownShapeIsTreatedAsMissing(): void
    {
        $this->cache->put($this->config()->cacheKey(), [['authorName' => 'old format']], 900);
        $this->provider->answers = [[FakeProvider::post('fresh')]];

        self::assertSame(['fresh'], self::names($this->service->getPosts($this->config())));
    }

    #[Test]
    public function aCacheEntryThatIsNotAListIsTreatedAsMissing(): void
    {
        $this->cache->put($this->config()->cacheKey(), 'garbage', 900);
        $this->provider->answers = [[FakeProvider::post('fresh')]];

        self::assertSame(['fresh'], self::names($this->service->getPosts($this->config())));
    }

    #[Test]
    public function aNetworkWithoutAProviderIsAProgrammingError(): void
    {
        $service = new FeedService([], $this->cache, static function (string $message): void {
        });

        $this->expectException(LogicException::class);

        $service->getPosts($this->config());
    }
}
