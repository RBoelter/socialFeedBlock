<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * One post, reduced to what the block shows and independent of the network it
 * came from. $html is sanitised before it gets here and is safe to output as is;
 * every other text field is plain text and has to be escaped when rendered.
 *
 * Nothing in a post refers to a remote image: showing avatars or media would
 * make visitors' browsers contact the social network, which is exactly what
 * this plugin exists to avoid.
 */
final class Post
{
    public function __construct(
        public readonly string $authorName,
        public readonly string $authorHandle,
        public readonly string $url,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $html,
        public readonly ?string $contentWarning = null,
        public readonly ?string $repostedBy = null,
        public readonly int $replyCount = 0,
        public readonly int $repostCount = 0,
        public readonly int $likeCount = 0,
        public readonly bool $hasMedia = false
    ) {
    }

    /**
     * The form stored in the cache and handed to the template. Plain data
     * survives a change of this class, a serialised object would not.
     *
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array
    {
        return [
            'authorName' => $this->authorName,
            'authorHandle' => $this->authorHandle,
            'url' => $this->url,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'html' => $this->html,
            'contentWarning' => $this->contentWarning,
            'repostedBy' => $this->repostedBy,
            'replyCount' => $this->replyCount,
            'repostCount' => $this->repostCount,
            'likeCount' => $this->likeCount,
            'hasMedia' => $this->hasMedia,
        ];
    }

    /**
     * @param array<string, mixed> $data as produced by toArray()
     *
     * @throws InvalidArgumentException when the data is incomplete or has the wrong types
     */
    public static function fromArray(array $data): self
    {
        $createdAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, self::string($data, 'createdAt'));
        if ($createdAt === false) {
            throw new InvalidArgumentException('createdAt is not an ISO 8601 date');
        }

        return new self(
            self::string($data, 'authorName'),
            self::string($data, 'authorHandle'),
            self::string($data, 'url'),
            $createdAt,
            self::string($data, 'html'),
            self::nullableString($data, 'contentWarning'),
            self::nullableString($data, 'repostedBy'),
            self::int($data, 'replyCount'),
            self::int($data, 'repostCount'),
            self::int($data, 'likeCount'),
            self::bool($data, 'hasMedia')
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new InvalidArgumentException("'{$key}' must be a string");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return self::string($data, $key);
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new InvalidArgumentException("'{$key}' must be an integer");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        if (!isset($data[$key]) || !is_bool($data[$key])) {
            throw new InvalidArgumentException("'{$key}' must be a boolean");
        }

        return $data[$key];
    }
}
