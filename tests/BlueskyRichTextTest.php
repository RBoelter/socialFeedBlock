<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\BlueskyRichText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlueskyRichTextTest extends TestCase
{
    private const ATTRIBUTES = ' target="_blank" rel="nofollow noopener noreferrer ugc"';

    /**
     * @param array<string, string> $feature the feature fields besides its type
     *
     * @return array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}
     */
    private static function facet(string $text, string $part, string $type, array $feature): array
    {
        $start = strpos($text, $part);
        self::assertNotFalse($start, 'test setup: the marked part must be in the text');

        return [
            'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($part)],
            'features' => [['$type' => 'app.bsky.richtext.facet#' . $type] + $feature],
        ];
    }

    /** @param array<mixed> $facets */
    private function render(string $text, array $facets = []): string
    {
        return (new BlueskyRichText())->toHtml($text, $facets);
    }

    #[Test]
    public function plainTextWithoutFacetsIsEscaped(): void
    {
        self::assertSame('Fish &amp; chips &lt;b&gt;bold&lt;/b&gt; &quot;q&quot; &#039;s&#039;', $this->render('Fish & chips <b>bold</b> "q" \'s\''));
    }

    #[Test]
    public function lineBreaksBecomeBreaks(): void
    {
        self::assertSame('one<br>two<br>three<br>four', $this->render("one\ntwo\r\nthree\rfour"));
    }

    #[Test]
    public function aLinkFacetBecomesALinkWithTheFullAddress(): void
    {
        $text = 'Read journal.example/x… now';
        $facet = self::facet($text, 'journal.example/x…', 'link', ['uri' => 'https://journal.example/x/full/path']);

        self::assertSame(
            'Read <a href="https://journal.example/x/full/path"' . self::ATTRIBUTES . '>journal.example/x…</a> now',
            $this->render($text, [$facet])
        );
    }

    #[Test]
    public function aMentionLinksToTheProfileOfTheDid(): void
    {
        $text = 'Thanks @editor.example.com!';
        $facet = self::facet($text, '@editor.example.com', 'mention', ['did' => 'did:plc:editor789']);

        self::assertStringContainsString(
            '<a href="https://bsky.app/profile/did%3Aplc%3Aeditor789"' . self::ATTRIBUTES . '>@editor.example.com</a>!',
            $this->render($text, [$facet])
        );
    }

    #[Test]
    public function aHashtagLinksToTheTagPageWithTheTagEncoded(): void
    {
        $text = 'Go #Café now';
        $facet = self::facet($text, '#Café', 'tag', ['tag' => 'Café']);

        self::assertStringContainsString('<a href="https://bsky.app/hashtag/Caf%C3%A9"' . self::ATTRIBUTES . '>#Café</a>', $this->render($text, [$facet]));
    }

    #[Test]
    public function offsetsAreCountedInBytesSoEmojiAndUmlautsBeforeALinkDoNotShiftIt(): void
    {
        // "Grüße 👋 " is 8 characters but 13 bytes; the link starts at byte 13, character 8.
        $text = 'Grüße 👋 journal.example/issue/42 end';
        $facet = self::facet($text, 'journal.example/issue/42', 'link', ['uri' => 'https://journal.example/issue/42']);
        self::assertSame(13, $facet['index']['byteStart'], 'test setup: byte offset, not character offset');
        self::assertSame(8, mb_strpos($text, 'journal'), 'test setup: the character offset differs');

        self::assertSame(
            'Grüße 👋 <a href="https://journal.example/issue/42"' . self::ATTRIBUTES . '>journal.example/issue/42</a> end',
            $this->render($text, [$facet])
        );
    }

    #[Test]
    public function aFacetThatEndsInsideAMultiByteCharacterIsIgnored(): void
    {
        $text = 'a👋b';
        $facet = ['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'x']]];

        self::assertSame('a👋b', $this->render($text, [$facet]));
    }

    #[Test]
    public function aFacetThatStartsInsideAMultiByteCharacterIsIgnored(): void
    {
        $text = 'a👋b';
        $facet = ['index' => ['byteStart' => 2, 'byteEnd' => 6], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'x']]];

        self::assertSame('a👋b', $this->render($text, [$facet]));
    }

    #[Test]
    public function facetsInAnyOrderAreAppliedByPosition(): void
    {
        $text = 'one two';
        $first = self::facet($text, 'one', 'tag', ['tag' => 'one']);
        $second = self::facet($text, 'two', 'tag', ['tag' => 'two']);

        self::assertSame($this->render($text, [$first, $second]), $this->render($text, [$second, $first]));
        self::assertSame(2, substr_count($this->render($text, [$second, $first]), '<a '));
    }

    #[Test]
    public function anOverlappingFacetIsDroppedAndTheEarlierOneKept(): void
    {
        $text = 'abcdef';
        $earlier = ['index' => ['byteStart' => 0, 'byteEnd' => 4], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'first']]];
        $overlapping = ['index' => ['byteStart' => 2, 'byteEnd' => 6], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'second']]];

        $html = $this->render($text, [$overlapping, $earlier]);

        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('hashtag/first', $html);
        self::assertStringEndsWith('ef', $html);
    }

    #[Test]
    public function twoFacetsThatTouchAreBothKept(): void
    {
        $text = 'abcd';
        $one = ['index' => ['byteStart' => 0, 'byteEnd' => 2], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'a']]];
        $two = ['index' => ['byteStart' => 2, 'byteEnd' => 4], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'b']]];

        self::assertSame(2, substr_count($this->render($text, [$one, $two]), '<a '));
    }

    /** @return iterable<string, array{0: array<mixed>}> */
    public static function unusableFacets(): iterable
    {
        $link = [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.org']];

        yield 'not an array' => ['just a string'];
        yield 'no index' => [['features' => $link]];
        yield 'no features' => [['index' => ['byteStart' => 0, 'byteEnd' => 3]]];
        yield 'empty features' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => []]];
        yield 'an empty range' => [['index' => ['byteStart' => 2, 'byteEnd' => 2], 'features' => $link]];
        yield 'a reversed range' => [['index' => ['byteStart' => 3, 'byteEnd' => 1], 'features' => $link]];
        yield 'a negative start' => [['index' => ['byteStart' => -1, 'byteEnd' => 2], 'features' => $link]];
        yield 'an end past the text' => [['index' => ['byteStart' => 0, 'byteEnd' => 999], 'features' => $link]];
        yield 'offsets as strings' => [['index' => ['byteStart' => '0', 'byteEnd' => '3'], 'features' => $link]];
        yield 'an unknown feature type' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.example#bold']]]];
        yield 'a javascript link' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']]]];
        yield 'a data link' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'data:text/html,x']]]];
        yield 'a mention without a valid did' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => '"><script>']]]];
        yield 'an empty tag' => [['index' => ['byteStart' => 0, 'byteEnd' => 3], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => '']]]];
    }

    #[Test]
    #[DataProvider('unusableFacets')]
    public function anUnusableFacetIsIgnoredAndTheTextStaysIntact(mixed $facet): void
    {
        self::assertSame('abc def', $this->render('abc def', [$facet]));
    }

    #[Test]
    public function markupInTheTextAndInTheLabelIsEscaped(): void
    {
        $text = '<img src=x onerror=alert(1)> here';
        $facet = self::facet($text, '<img src=x onerror=alert(1)>', 'link', ['uri' => 'https://example.org/?a=1&b="2"']);

        $html = $this->render($text, [$facet]);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;</a>', $html);
        self::assertStringContainsString('href="https://example.org/?a=1&amp;b=&quot;2&quot;"', $html);
    }

    #[Test]
    public function anEmptyTextStaysEmpty(): void
    {
        self::assertSame('', $this->render(''));
    }
}
