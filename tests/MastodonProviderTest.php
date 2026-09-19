<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedError;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use APP\plugins\blocks\socialFeedBlock\classes\HtmlSanitizer;
use APP\plugins\blocks\socialFeedBlock\classes\MastodonProvider;
use APP\plugins\blocks\socialFeedBlock\classes\Post;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MastodonProviderTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): MastodonProvider
    {
        $this->history = [];

        return new MastodonProvider(Fixture::client($responses, $this->history), new HtmlSanitizer());
    }

    private function account(int $postCount = 5, bool $excludeReplies = false, bool $excludeReposts = false): FeedConfig
    {
        return new FeedConfig('mastodon', 'account', 'examplejournal', 'mastodon.example', $postCount, $excludeReplies, $excludeReposts, 15);
    }

    /** @return list<Post> */
    private function fetchAccount(FeedConfig $config): array
    {
        return $this->provider([
            Fixture::response('mastodon-account-lookup.json'),
            Fixture::response('mastodon-statuses.json'),
        ])->fetch($config);
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<string> the last path segment of every post address, e.g. "1001"
     */
    private static function ids(array $posts): array
    {
        return array_map(static fn (Post $post): string => basename($post->url), $posts);
    }

    private function requestUri(int $index): string
    {
        return (string) $this->history[$index]['request']->getUri();
    }

    #[Test]
    public function itLooksUpTheAccountThenReadsItsStatuses(): void
    {
        $this->fetchAccount($this->account());

        self::assertCount(2, $this->history);
        self::assertSame('https://mastodon.example/api/v1/accounts/lookup?acct=examplejournal', $this->requestUri(0));
        self::assertSame(
            'https://mastodon.example/api/v1/accounts/109/statuses?limit=10&exclude_replies=false&exclude_reblogs=false',
            $this->requestUri(1)
        );
    }

    #[Test]
    public function itAsksTheServerToDropRepliesAndBoostsWhenConfigured(): void
    {
        $this->fetchAccount($this->account(excludeReplies: true, excludeReposts: true));

        self::assertStringContainsString('exclude_replies=true&exclude_reblogs=true', $this->requestUri(1));
    }

    #[Test]
    public function itNeverAsksForMoreThanTheServerAllows(): void
    {
        $this->fetchAccount($this->account(postCount: 20));

        self::assertStringContainsString('limit=40', $this->requestUri(1));
    }

    #[Test]
    public function itReadsAHashtagTimelineInOneRequestWithTheTagEncoded(): void
    {
        $provider = $this->provider([Fixture::response('mastodon-statuses.json')]);
        $config = new FeedConfig('mastodon', 'hashtag', 'Café', 'mastodon.example', 5, false, false, 15);

        $provider->fetch($config);

        self::assertCount(1, $this->history);
        self::assertSame('https://mastodon.example/api/v1/timelines/tag/Caf%C3%A9?limit=10', $this->requestUri(0));
    }

    #[Test]
    public function itReturnsAtMostTheConfiguredNumberOfPostsAndSkipsUnusableStatuses(): void
    {
        // 555 is the boosted original behind status 1002
        self::assertSame(['1001', '555', '1003', '1004', '1005'], self::ids($this->fetchAccount($this->account(postCount: 5))));
        // 1006 has no url and 1007 no usable date: both are skipped, not fatal
        self::assertSame(
            ['1001', '555', '1003', '1004', '1005', '1008'],
            self::ids($this->fetchAccount($this->account(postCount: 20)))
        );
    }

    #[Test]
    public function itFiltersRepliesWhenTheServerDidNot(): void
    {
        $ids = self::ids($this->fetchAccount($this->account(postCount: 20, excludeReplies: true)));

        self::assertNotContains('1003', $ids);
        self::assertContains('555', $ids, 'a boost is not a reply');
    }

    #[Test]
    public function itFiltersBoostsWhenTheServerDidNot(): void
    {
        $ids = self::ids($this->fetchAccount($this->account(postCount: 20, excludeReposts: true)));

        self::assertNotContains('555', $ids);
        self::assertSame(['1001', '1003', '1004', '1005', '1008'], $ids);
    }

    #[Test]
    public function aPlainPostIsMappedFieldByField(): void
    {
        $post = $this->fetchAccount($this->account())[0];

        self::assertSame('Example Journal', $post->authorName);
        self::assertSame('@examplejournal@mastodon.example', $post->authorHandle, 'the server is added to a local account');
        self::assertSame('https://mastodon.example/@examplejournal/1001', $post->url);
        self::assertSame('2026-09-18T21:32:30+00:00', $post->createdAt->format('c'));
        self::assertSame(2, $post->replyCount);
        self::assertSame(3, $post->repostCount);
        self::assertSame(10, $post->likeCount);
        self::assertTrue($post->hasMedia);
        self::assertNull($post->contentWarning);
        self::assertNull($post->repostedBy);
    }

    #[Test]
    public function theTextIsSanitisedAndKeepsItsLinks(): void
    {
        $html = $this->fetchAccount($this->account())[0]->html;

        self::assertStringContainsString('href="https://journal.example/issue/42"', $html);
        self::assertStringContainsString('<span class="ellipsis">journal.example/issue/42</span>', $html);
        self::assertStringContainsString('target="_blank"', $html);
    }

    #[Test]
    public function aBoostShowsTheOriginalPostCreditedToItsAuthor(): void
    {
        $boost = $this->fetchAccount($this->account())[1];

        self::assertSame('https://other.example/@reviewer/555', $boost->url);
        self::assertSame('A Reviewer', $boost->authorName, 'the custom emoji code is dropped');
        self::assertSame('@reviewer@other.example', $boost->authorHandle, 'a remote account already carries its server');
        self::assertSame('Example Journal', $boost->repostedBy);
        self::assertSame(7, $boost->repostCount);
        self::assertStringNotContainsString(':verified:', $boost->html);
        self::assertStringContainsString('Reviewing is work', $boost->html);
    }

    #[Test]
    public function textBehindAContentWarningIsNotReturned(): void
    {
        $post = $this->fetchAccount($this->account())[3];

        self::assertSame('Politics', $post->contentWarning);
        self::assertSame('', $post->html);
    }

    #[Test]
    public function hostileMarkupIsNeutralisedAndTheAuthorNameFallsBackToTheUsername(): void
    {
        $post = $this->fetchAccount($this->account())[4];

        self::assertSame('examplejournal', $post->authorName);
        foreach (['<script', 'onclick', '<img', '<iframe', 'javascript:', 'tracker.example', 'evil.example'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $post->html);
        }
        self::assertStringContainsString('Hello', $post->html);
    }

    #[Test]
    public function noPostCarriesAnAddressOfARemoteImage(): void
    {
        foreach ($this->fetchAccount($this->account(postCount: 20)) as $post) {
            self::assertStringNotContainsString('files.mastodon.example', json_encode($post->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('.png', json_encode($post->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function anEmptyTimelineIsNotAnError(): void
    {
        $provider = $this->provider([Fixture::response('mastodon-account-lookup.json'), new Response(200, [], '[]')]);

        self::assertSame([], $provider->fetch($this->account()));
    }

    #[Test]
    public function aLookupWithoutAnIdIsAnInvalidResponse(): void
    {
        $provider = $this->provider([new Response(200, [], '{"username":"examplejournal"}')]);

        try {
            $provider->fetch($this->account());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::InvalidResponse, $exception->getError());
        }
    }

    #[Test]
    public function anIdThatIsNotNumericIsRefusedBeforeItIsUsedInAnUrl(): void
    {
        $provider = $this->provider([new Response(200, [], '{"id":"../../admin"}')]);

        try {
            $provider->fetch($this->account());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::InvalidResponse, $exception->getError());
            self::assertCount(1, $this->history, 'the malicious id must not be requested');
        }
    }

    #[Test]
    public function anUnknownAccountIsReportedAsNotFound(): void
    {
        $provider = $this->provider([new Response(404, [], '{"error":"Record not found"}')]);

        try {
            $provider->fetch($this->account());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::NotFound, $exception->getError());
        }
    }

    #[Test]
    public function aServerThatClosedItsPublicApiIsReportedAsNeedingALogin(): void
    {
        $provider = $this->provider([new Response(401, [], '{"error":"This API requires an authenticated user"}')]);

        try {
            $provider->fetch($this->account());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::AuthRequired, $exception->getError());
        }
    }
}
