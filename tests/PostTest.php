<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\Post;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
    private function post(): Post
    {
        return new Post(
            'Example Journal',
            '@journal@example.social',
            'https://example.social/@journal/1',
            new DateTimeImmutable('2026-09-18T21:32:30+00:00'),
            '<p>Hello</p>',
            'Content warning',
            'Somebody',
            3,
            5,
            12,
            true
        );
    }

    #[Test]
    public function aPostSurvivesTheRoundTripThroughAnArray(): void
    {
        $restored = Post::fromArray($this->post()->toArray());

        self::assertEquals($this->post(), $restored);
    }

    #[Test]
    public function optionalFieldsMayBeNull(): void
    {
        $data = ['contentWarning' => null, 'repostedBy' => null] + $this->post()->toArray();

        $restored = Post::fromArray($data);

        self::assertNull($restored->contentWarning);
        self::assertNull($restored->repostedBy);
    }

    #[Test]
    public function optionalFieldsMayBeMissing(): void
    {
        $data = $this->post()->toArray();
        unset($data['contentWarning'], $data['repostedBy']);

        self::assertNull(Post::fromArray($data)->contentWarning);
    }

    #[Test]
    public function theDateIsStoredAsIso8601(): void
    {
        self::assertSame('2026-09-18T21:32:30+00:00', $this->post()->toArray()['createdAt']);
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function corruptFields(): iterable
    {
        yield 'a missing author' => ['authorName', null];
        yield 'a numeric url' => ['url', 5];
        yield 'an invalid date' => ['createdAt', 'yesterday-ish'];
        yield 'a date of the wrong type' => ['createdAt', 1758231150];
        yield 'a string count' => ['likeCount', '12'];
        yield 'a missing html' => ['html', null];
        yield 'a string flag' => ['hasMedia', 'yes'];
    }

    #[Test]
    #[DataProvider('corruptFields')]
    public function corruptCacheDataIsRejected(string $field, mixed $value): void
    {
        $data = [$field => $value] + $this->post()->toArray();

        $this->expectException(InvalidArgumentException::class);

        Post::fromArray($data);
    }
}
