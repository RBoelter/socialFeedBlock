<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Turns the plain text of a Bluesky post and its "facets" into safe HTML.
 *
 * A facet marks a link, mention or hashtag by the position of its text. Those
 * positions are byte offsets into the UTF-8 encoded text, not character
 * offsets: every emoji and every umlaut before a link shifts it. The text is
 * therefore cut with byte functions (substr, strlen), and a facet whose
 * boundary would fall inside a multi-byte character is ignored.
 *
 * The text is escaped before anything else, so no post can inject markup.
 */
final class BlueskyRichText
{
    private const LINK = 'app.bsky.richtext.facet#link';
    private const MENTION = 'app.bsky.richtext.facet#mention';
    private const TAG = 'app.bsky.richtext.facet#tag';

    private const WEB = 'https://bsky.app';
    private const DID_PATTERN = '/^did:[a-z]+:[a-z0-9._%:-]+$/i';
    private const LINK_ATTRIBUTES = ' target="_blank" rel="nofollow noopener noreferrer ugc"';

    /** @param array<mixed> $facets the "facets" list of the post record */
    public function toHtml(string $text, array $facets): string
    {
        $html = '';
        $cursor = 0;

        foreach ($this->links($text, $facets) as [$start, $end, $url]) {
            $before = substr($text, $cursor, $start - $cursor);
            $label = substr($text, $start, $end - $start);
            if (!mb_check_encoding($before, 'UTF-8') || !mb_check_encoding($label, 'UTF-8')) {
                continue;
            }

            $link = '<a href="' . self::escape($url) . '"' . self::LINK_ATTRIBUTES . '>' . self::escape($label) . '</a>';
            $html .= self::escape($before) . $link;
            $cursor = $end;
        }

        $html .= self::escape(substr($text, $cursor));

        return str_replace(["\r\n", "\r", "\n"], '<br>', $html);
    }

    /**
     * The usable facets as [start, end, url], ordered by position and without
     * any that overlap an earlier one.
     *
     * @param array<mixed> $facets
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function links(string $text, array $facets): array
    {
        $length = strlen($text);
        $links = [];

        foreach ($facets as $facet) {
            $start = Payload::get($facet, 'index', 'byteStart');
            $end = Payload::get($facet, 'index', 'byteEnd');
            $url = $this->urlOf(Payload::items($facet, 'features'));

            if (is_int($start) && is_int($end) && $start >= 0 && $start < $end && $end <= $length && $url !== null) {
                $links[] = [$start, $end, $url];
            }
        }

        usort($links, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $kept = [];
        $lastEnd = 0;
        foreach ($links as $link) {
            if ($link[0] >= $lastEnd) {
                $kept[] = $link;
                $lastEnd = $link[1];
            }
        }

        return $kept;
    }

    /**
     * The address a facet points to, or null when it is of a kind that is not
     * shown as a link or its address is not a plain web address.
     *
     * @param array<mixed> $features
     */
    private function urlOf(array $features): ?string
    {
        foreach ($features as $feature) {
            $url = match (Payload::string($feature, '$type')) {
                self::LINK => Payload::webUrl($feature, 'uri'),
                self::MENTION => $this->profileUrl(Payload::string($feature, 'did')),
                self::TAG => $this->hashtagUrl(Payload::string($feature, 'tag')),
                default => null,
            };
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function profileUrl(?string $did): ?string
    {
        return $did !== null && preg_match(self::DID_PATTERN, $did) === 1 ? self::WEB . '/profile/' . rawurlencode($did) : null;
    }

    private function hashtagUrl(?string $tag): ?string
    {
        return $tag !== null && $tag !== '' ? self::WEB . '/hashtag/' . rawurlencode($tag) : null;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
