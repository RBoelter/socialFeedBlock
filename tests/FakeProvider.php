<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedConfig;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use APP\plugins\blocks\socialFeedBlock\classes\FeedProvider;
use APP\plugins\blocks\socialFeedBlock\classes\Post;
use DateTimeImmutable;

/** A provider that answers from a queue and counts how often it was asked. */
final class FakeProvider implements FeedProvider
{
    public int $calls = 0;

    /** @var list<list<Post>|FeedException> */
    public array $answers = [];

    public static function post(string $name): Post
    {
        return new Post($name, '@' . $name, 'https://example.social/' . $name, new DateTimeImmutable('2026-09-18T12:00:00+00:00'), '<p>' . $name . '</p>');
    }

    public function fetch(FeedConfig $config): array
    {
        $this->calls++;
        $answer = array_shift($this->answers) ?? [];

        if ($answer instanceof FeedException) {
            throw $answer;
        }

        return $answer;
    }
}
