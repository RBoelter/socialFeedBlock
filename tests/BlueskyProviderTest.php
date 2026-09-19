<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\BlueskyProvider;
use APP\plugins\blocks\socialFeedBlock\classes\BlueskyRichText;
use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedError;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use APP\plugins\blocks\socialFeedBlock\classes\Post;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlueskyProviderTest extends TestCase
{
    private const FEED_URI = 'at://did:plc:abc123/app.bsky.feed.generator/whats-hot';

    /** @var array<int, array<string, mixed>> */
    private array $history = [];

    /** @param list<Response> $responses */
    private function provider(array $responses): BlueskyProvider
    {
        $this->history = [];

        return new BlueskyProvider(Fixture::client($responses, $this->history), new BlueskyRichText());
    }

    private function author(int $postCount = 5, bool $excludeReplies = false, bool $excludeReposts = false): FeedConfig
    {
        return new FeedConfig('bluesky', 'author', 'journal.example.com', null, $postCount, $excludeReplies, $excludeReposts, 15);
    }

    /** @return list<Post> */
    private function fetchAuthor(FeedConfig $config): array
    {
        return $this->provider([Fixture::response('bluesky-author-feed.json')])->fetch($config);
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<string> the last path segment of every post address, e.g. "r1"
     */
    private static function ids(array $posts): array
    {
        return array_map(static fn (Post $post): string => basename($post->url), $posts);
    }

    private function requestUri(): string
    {
        return (string) $this->history[0]['request']->getUri();
    }

    #[Test]
    public function itReadsTheAuthorFeedOfAnAccountInOneRequest(): void
    {
        $this->fetchAuthor($this->author());

        self::assertCount(1, $this->history);
        self::assertSame(
            'https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed?actor=journal.example.com&limit=15&filter=posts_with_replies',
            $this->requestUri()
        );
    }

    #[Test]
    public function itAsksTheServerToDropRepliesWhenConfigured(): void
    {
        $this->fetchAuthor($this->author(excludeReplies: true));

        self::assertStringContainsString('filter=posts_no_replies', $this->requestUri());
    }

    #[Test]
    public function itReadsACustomFeedByItsAddress(): void
    {
        $provider = $this->provider([Fixture::response('bluesky-author-feed.json')]);

        $provider->fetch(new FeedConfig('bluesky', 'feed', self::FEED_URI, null, 5, false, false, 15));

        self::assertSame(
            'https://public.api.bsky.app/xrpc/app.bsky.feed.getFeed?feed=at%3A%2F%2Fdid%3Aplc%3Aabc123%2Fapp.bsky.feed.generator%2Fwhats-hot&limit=15',
            $this->requestUri()
        );
    }

    #[Test]
    public function itNeverAsksForMoreThanTheApiAllows(): void
    {
        $this->fetchAuthor($this->author(postCount: 20));

        self::assertStringContainsString('limit=60', $this->requestUri());
    }

    #[Test]
    public function itSkipsHiddenAndUnusablePostsAndStopsAtTheConfiguredNumber(): void
    {
        // r5 is labelled porn, r6 belongs to an account hidden from logged-out visitors,
        // the entry after r4 in the file has no uri; "rrev" is the reposted original
        self::assertSame(['r1', 'rrev', 'r3', 'r4', 'r8'], self::ids($this->fetchAuthor($this->author(postCount: 5))));
        self::assertSame(
            ['r1', 'rrev', 'r3', 'r4', 'r8', 'r9', 'r10'],
            self::ids($this->fetchAuthor($this->author(postCount: 20)))
        );
    }

    #[Test]
    public function itFiltersRepliesWhenTheApiDidNot(): void
    {
        $ids = self::ids($this->fetchAuthor($this->author(postCount: 20, excludeReplies: true)));

        self::assertNotContains('r3', $ids);
        self::assertContains('rrev', $ids, 'a repost is not a reply');
    }

    #[Test]
    public function itFiltersRepostsOnRequest(): void
    {
        $ids = self::ids($this->fetchAuthor($this->author(postCount: 20, excludeReposts: true)));

        self::assertNotContains('rrev', $ids);
        self::assertSame(['r1', 'r3', 'r4', 'r8', 'r9', 'r10'], $ids);
    }

    #[Test]
    public function aPostIsMappedFieldByField(): void
    {
        $post = $this->fetchAuthor($this->author())[0];

        self::assertSame('Example Journal', $post->authorName);
        self::assertSame('@journal.example.com', $post->authorHandle);
        self::assertSame('https://bsky.app/profile/journal.example.com/post/r1', $post->url);
        self::assertSame('2026-09-18T21:32:30+00:00', $post->createdAt->format('c'));
        self::assertSame(2, $post->replyCount);
        self::assertSame(3, $post->repostCount);
        self::assertSame(10, $post->likeCount);
        self::assertFalse($post->hasMedia);
        self::assertNull($post->repostedBy);
        self::assertNull($post->contentWarning);
    }

    #[Test]
    public function linksMentionsAndHashtagsLandOnTheRightTextDespiteEmojiAndUmlauts(): void
    {
        $html = $this->fetchAuthor($this->author())[0]->html;

        self::assertStringStartsWith('Grüße 👋 Read our new issue at <a href="https://journal.example/issue/42"', $html);
        self::assertStringContainsString('>journal.example/issue/42</a> with <a href="https://bsky.app/profile/did%3Aplc%3Aeditor789"', $html);
        self::assertStringContainsString('>@editor.example.com</a> <a href="https://bsky.app/hashtag/openaccess"', $html);
        self::assertStringEndsWith('>#openaccess</a>', $html);
    }

    #[Test]
    public function aRepostShowsTheOriginalCreditedToItsAuthorAndNamesTheReposter(): void
    {
        $repost = $this->fetchAuthor($this->author())[1];

        self::assertSame('https://bsky.app/profile/reviewer.example.org/post/rrev', $repost->url);
        self::assertSame('A Reviewer', $repost->authorName);
        self::assertSame('@reviewer.example.org', $repost->authorHandle);
        self::assertSame('Example Journal', $repost->repostedBy);
        self::assertSame(7, $repost->repostCount);
    }

    #[Test]
    public function aPostWithImagesIsFlaggedButNoImageAddressIsKept(): void
    {
        $post = $this->fetchAuthor($this->author())[3];

        self::assertTrue($post->hasMedia);
        self::assertStringNotContainsString('cdn.bsky.app', json_encode($post->toArray(), JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function noPostCarriesAnAddressOfARemoteImage(): void
    {
        foreach ($this->fetchAuthor($this->author(postCount: 20)) as $post) {
            self::assertStringNotContainsString('cdn.bsky.app', json_encode($post->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('<img', $post->html);
        }
    }

    #[Test]
    public function markupInATextIsEscapedAndUnsafeOrOutOfRangeFacetsAreIgnored(): void
    {
        $post = $this->fetchAuthor($this->author())[4];

        self::assertSame('Hello &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot; click here', $post->html);
        self::assertSame('journal.example.com', $post->authorName, 'an empty display name falls back to the handle');
    }

    #[Test]
    public function anAccountWhoseHandleNoLongerResolvesIsIdentifiedByItsDid(): void
    {
        $post = $this->fetchAuthor($this->author(postCount: 20))[5];

        self::assertSame('Moved Account', $post->authorName);
        self::assertSame('@did:plc:gone999', $post->authorHandle);
        self::assertSame('https://bsky.app/profile/did%3Aplc%3Agone999/post/r9', $post->url);
    }

    #[Test]
    public function anEmptyFeedIsNotAnError(): void
    {
        $provider = $this->provider([new Response(200, [], '{"feed":[]}')]);

        self::assertSame([], $provider->fetch($this->author()));
    }

    #[Test]
    public function aResponseWithoutAFeedListIsTreatedAsEmpty(): void
    {
        $provider = $this->provider([new Response(200, [], '{"unexpected":true}')]);

        self::assertSame([], $provider->fetch($this->author()));
    }

    #[Test]
    public function anUnknownAccountIsReportedAsNotFound(): void
    {
        $provider = $this->provider([new Response(400, [], '{"error":"InvalidRequest","message":"Profile not found"}')]);

        try {
            $provider->fetch($this->author());
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::NotFound, $exception->getError());
        }
    }
}
