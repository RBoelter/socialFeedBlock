<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\Post;
use APP\plugins\blocks\socialFeedBlock\classes\PostList;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostListTest extends TestCase
{
    private static function post(string $name): Post
    {
        return new Post($name, '@' . $name, 'https://example.social/' . $name, new DateTimeImmutable('2026-09-18T12:00:00+00:00'), '');
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
    public function itKeepsTheOrderAndStopsAtTheLimit(): void
    {
        $posts = PostList::collect(['a', 'b', 'c', 'd'], self::post(...), 3);

        self::assertSame(['a', 'b', 'c'], self::names($posts));
    }

    #[Test]
    public function unusableEntriesDoNotCountTowardsTheLimit(): void
    {
        $toPost = static fn (string $entry): ?Post => $entry === 'x' ? null : self::post($entry);

        $posts = PostList::collect(['x', 'a', 'x', 'b', 'c'], $toPost, 2);

        self::assertSame(['a', 'b'], self::names($posts));
    }

    #[Test]
    public function itReturnsWhatThereIsWhenThereAreFewerEntriesThanTheLimit(): void
    {
        self::assertSame(['a'], self::names(PostList::collect(['a'], self::post(...), 5)));
        self::assertSame([], PostList::collect([], self::post(...), 5));
    }

    #[Test]
    public function itDoesNotReadPastTheLimit(): void
    {
        $visited = [];
        $entries = (static function () use (&$visited) {
            foreach (['a', 'b', 'c', 'd'] as $entry) {
                $visited[] = $entry;
                yield $entry;
            }
        })();

        PostList::collect($entries, self::post(...), 2);

        self::assertSame(['a', 'b'], $visited);
    }
}
