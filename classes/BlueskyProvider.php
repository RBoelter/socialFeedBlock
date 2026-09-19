<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Posts from Bluesky, through the public AppView API: no account, no key.
 *
 * That API serves the posts of an account and of a custom feed. It refuses
 * searches (HTTP 403 without login), which is why hashtags are a Mastodon-only
 * source.
 */
final class BlueskyProvider implements FeedProvider
{
    private const API = 'https://public.api.bsky.app/xrpc/';
    private const WEB = 'https://bsky.app';
    private const MAX_LIMIT = 100;

    /** Reposts are filtered out after the request, so more posts than wanted are asked for. */
    private const OVERFETCH_FACTOR = 3;

    private const REPOST_REASON = 'app.bsky.feed.defs#reasonRepost';
    private const UNRESOLVED_HANDLE = 'handle.invalid';
    private const POST_URI_PATTERN = '#^at://[^/]+/app\.bsky\.feed\.post/([A-Za-z0-9._~:-]+)$#';
    private const MEDIA_EMBEDS = [
        'app.bsky.embed.images#view',
        'app.bsky.embed.video#view',
        'app.bsky.embed.recordWithMedia#view',
    ];

    /**
     * Content labelled like this is not shown: adult or graphic material, and
     * accounts that asked not to be shown to visitors who are not logged in.
     */
    private const HIDDEN_LABELS = ['!no-unauthenticated', 'porn', 'sexual', 'nudity', 'graphic-media', 'gore'];

    public function __construct(
        private readonly ApiClient $api,
        private readonly BlueskyRichText $richText
    ) {
    }

    public function fetch(FeedConfig $config): array
    {
        $limit = min(self::MAX_LIMIT, $config->postCount * self::OVERFETCH_FACTOR);

        $answer = $config->source === FeedConfig::SOURCE_FEED
            ? $this->api->getJson(self::API . 'app.bsky.feed.getFeed', ['feed' => $config->target, 'limit' => $limit])
            : $this->api->getJson(self::API . 'app.bsky.feed.getAuthorFeed', [
                'actor' => $config->target,
                'limit' => $limit,
                'filter' => $config->excludeReplies ? 'posts_no_replies' : 'posts_with_replies',
            ]);

        return PostList::collect(
            Payload::items($answer, 'feed'),
            fn (mixed $item): ?Post => is_array($item) && $this->isWanted($item, $config) ? $this->toPost($item) : null,
            $config->postCount
        );
    }

    /** @param array<mixed> $item */
    private function isWanted(array $item, FeedConfig $config): bool
    {
        if ($config->excludeReplies && Payload::get($item, 'reply') !== null) {
            return false;
        }

        return !($config->excludeReposts && $this->isRepost($item));
    }

    /** @param array<mixed> $item */
    private function isRepost(array $item): bool
    {
        return Payload::string($item, 'reason', '$type') === self::REPOST_REASON;
    }

    /** @param array<mixed> $item */
    private function toPost(array $item): ?Post
    {
        $post = Payload::get($item, 'post');
        $author = Payload::get($post, 'author');
        if (!is_array($post) || !is_array($author) || $this->isHidden($post)) {
            return null;
        }

        $account = $this->accountName($author);
        $createdAt = Payload::date($post, 'record', 'createdAt') ?? Payload::date($post, 'indexedAt');
        preg_match(self::POST_URI_PATTERN, Payload::string($post, 'uri') ?? '', $uri);
        if ($account === null || $createdAt === null || !isset($uri[1])) {
            return null;
        }

        return new Post(
            authorName: $this->displayName($author, $account),
            authorHandle: '@' . $account,
            url: self::WEB . '/profile/' . rawurlencode($account) . '/post/' . rawurlencode($uri[1]),
            createdAt: $createdAt,
            html: $this->richText->toHtml(Payload::string($post, 'record', 'text') ?? '', Payload::items($post, 'record', 'facets')),
            repostedBy: $this->isRepost($item) ? $this->reposterName($item) : null,
            replyCount: Payload::count($post, 'replyCount'),
            repostCount: Payload::count($post, 'repostCount'),
            likeCount: Payload::count($post, 'likeCount'),
            hasMedia: in_array(Payload::string($post, 'embed', '$type'), self::MEDIA_EMBEDS, true)
        );
    }

    /** @param array<mixed> $post */
    private function isHidden(array $post): bool
    {
        $labels = [...Payload::items($post, 'labels'), ...Payload::items($post, 'author', 'labels')];
        foreach ($labels as $label) {
            if (in_array(Payload::string($label, 'val'), self::HIDDEN_LABELS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The handle, or the DID for an account whose handle no longer resolves.
     *
     * @param array<mixed> $author
     */
    private function accountName(array $author): ?string
    {
        $handle = Payload::string($author, 'handle');
        if ($handle !== null && $handle !== '' && $handle !== self::UNRESOLVED_HANDLE) {
            return $handle;
        }

        $did = Payload::string($author, 'did');

        return $did !== null && $did !== '' ? $did : null;
    }

    /** @param array<mixed> $author */
    private function displayName(array $author, string $account): string
    {
        $name = trim(Payload::string($author, 'displayName') ?? '');

        return $name !== '' ? $name : $account;
    }

    /** @param array<mixed> $item */
    private function reposterName(array $item): ?string
    {
        $reposter = Payload::get($item, 'reason', 'by');
        $account = is_array($reposter) ? $this->accountName($reposter) : null;

        return $account === null ? null : $this->displayName($reposter, $account);
    }
}
