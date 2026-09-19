<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Posts from a Mastodon server, through its public API: no account, no key.
 *
 * A server can switch that public access off; the answer is then 401 or 403
 * and shows up as FeedError::AuthRequired.
 */
final class MastodonProvider implements FeedProvider
{
    private const MAX_LIMIT = 40;

    /**
     * Replies and reposts are filtered out after the request for hashtag
     * timelines, so more posts than wanted are asked for.
     */
    private const OVERFETCH_FACTOR = 2;

    public function __construct(
        private readonly ApiClient $api,
        private readonly HtmlSanitizer $sanitizer
    ) {
    }

    public function fetch(FeedConfig $config): array
    {
        $base = "https://{$config->instance}/api/v1";
        $limit = min(self::MAX_LIMIT, $config->postCount * self::OVERFETCH_FACTOR);

        $statuses = $config->source === FeedConfig::SOURCE_HASHTAG
            ? $this->api->getJson($base . '/timelines/tag/' . rawurlencode($config->target), ['limit' => $limit])
            : $this->api->getJson($base . '/accounts/' . $this->accountId($base, $config) . '/statuses', [
                'limit' => $limit,
                'exclude_replies' => $config->excludeReplies ? 'true' : 'false',
                'exclude_reblogs' => $config->excludeReposts ? 'true' : 'false',
            ]);

        $posts = [];
        foreach ($statuses as $status) {
            $post = is_array($status) && $this->isWanted($status, $config) ? $this->toPost($status) : null;
            if ($post !== null) {
                $posts[] = $post;
            }
            if (count($posts) === $config->postCount) {
                break;
            }
        }

        return $posts;
    }

    /** @throws FeedException */
    private function accountId(string $base, FeedConfig $config): string
    {
        $account = $this->api->getJson($base . '/accounts/lookup', ['acct' => $config->target]);
        $id = Payload::string($account, 'id');

        if ($id === null || !ctype_digit($id)) {
            throw new FeedException(FeedError::InvalidResponse, "account lookup for {$config->target} returned no id");
        }

        return $id;
    }

    /**
     * @param array<mixed> $status
     */
    private function isWanted(array $status, FeedConfig $config): bool
    {
        if ($config->excludeReplies && Payload::get($status, 'in_reply_to_id') !== null) {
            return false;
        }

        return !($config->excludeReposts && is_array(Payload::get($status, 'reblog')));
    }

    /**
     * A repost (Mastodon: boost) shows the original post, credited to its own
     * author, and names the account that boosted it.
     *
     * @param array<mixed> $status
     */
    private function toPost(array $status): ?Post
    {
        $reblog = Payload::get($status, 'reblog');
        $original = is_array($reblog) ? $reblog : $status;

        $url = Payload::webUrl($original, 'url');
        $createdAt = Payload::date($original, 'created_at');
        $author = Payload::get($original, 'account');
        if ($url === null || $createdAt === null || !is_array($author)) {
            return null;
        }

        $emojiCodes = $this->emojiCodes($original, $author);
        $contentWarning = trim($this->withoutEmojiCodes(Payload::string($original, 'spoiler_text') ?? '', $emojiCodes));

        return new Post(
            authorName: $this->displayName($author, $emojiCodes),
            authorHandle: $this->handle($author, $url),
            url: $url,
            createdAt: $createdAt,
            // Text behind a content warning is not shown, only linked to
            html: $contentWarning === ''
                ? $this->sanitizer->clean($this->withoutEmojiCodes(Payload::string($original, 'content') ?? '', $emojiCodes))
                : '',
            contentWarning: $contentWarning === '' ? null : $contentWarning,
            repostedBy: is_array($reblog) ? $this->reposterName($status) : null,
            replyCount: Payload::count($original, 'replies_count'),
            repostCount: Payload::count($original, 'reblogs_count'),
            likeCount: Payload::count($original, 'favourites_count'),
            hasMedia: Payload::items($original, 'media_attachments') !== []
        );
    }

    /**
     * @param array<mixed> $author
     * @param list<string> $emojiCodes
     */
    private function displayName(array $author, array $emojiCodes): string
    {
        $name = trim($this->withoutEmojiCodes(Payload::string($author, 'display_name') ?? '', $emojiCodes));

        return $name !== '' ? $name : (Payload::string($author, 'username') ?? '');
    }

    /**
     * Mastodon leaves the server off the handle of its own accounts; it is
     * added, taken from the address of the post.
     *
     * @param array<mixed> $author
     */
    private function handle(array $author, string $postUrl): string
    {
        $acct = Payload::string($author, 'acct') ?? Payload::string($author, 'username') ?? '';

        return str_contains($acct, '@') ? '@' . $acct : '@' . $acct . '@' . parse_url($postUrl, PHP_URL_HOST);
    }

    /** @param array<mixed> $status */
    private function reposterName(array $status): ?string
    {
        $account = Payload::get($status, 'account');
        if (!is_array($account)) {
            return null;
        }

        $name = $this->displayName($account, $this->emojiCodes($status, $account));

        return $name !== '' ? $name : null;
    }

    /**
     * Custom emoji arrive as :shortcode: in the text and would show as such.
     *
     * @param array<mixed> $status
     * @param array<mixed> $account
     *
     * @return list<string>
     */
    private function emojiCodes(array $status, array $account): array
    {
        $codes = [];
        foreach ([...Payload::items($status, 'emojis'), ...Payload::items($account, 'emojis')] as $emoji) {
            $code = Payload::string($emoji, 'shortcode');
            if ($code !== null && $code !== '') {
                $codes[] = ':' . $code . ':';
            }
        }

        return $codes;
    }

    /** @param list<string> $emojiCodes */
    private function withoutEmojiCodes(string $text, array $emojiCodes): string
    {
        return $emojiCodes === [] ? $text : str_replace($emojiCodes, '', $text);
    }
}
