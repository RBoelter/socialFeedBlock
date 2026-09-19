<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private function clean(string $html): string
    {
        return (new HtmlSanitizer())->clean($html);
    }

    #[Test]
    public function itKeepsTheFormattingMastodonUses(): void
    {
        $html = '<p>One <strong>two</strong> <em>three</em><br>four <del>five</del> <code>six</code></p><ul><li>a</li></ul>';

        self::assertSame($html, $this->clean($html));
    }

    #[Test]
    public function itKeepsTheSpansThatShortenUrlsAndWrapMentions(): void
    {
        $html = '<p><span class="h-card"><a href="https://example.social/@a" class="u-url mention">@<span>a</span></a></span> '
            . '<a href="https://example.org/long/path"><span class="invisible">https://</span>'
            . '<span class="ellipsis">example.org/long</span><span class="invisible">/path</span></a></p>';

        $cleaned = $this->clean($html);

        self::assertStringContainsString('<span class="h-card">', $cleaned);
        self::assertStringContainsString('<span class="invisible">https://</span>', $cleaned);
        self::assertStringContainsString('<span class="ellipsis">example.org/long</span>', $cleaned);
        self::assertStringContainsString('href="https://example.social/@a"', $cleaned);
    }

    #[Test]
    public function itDropsClassesThatCouldStyleTheJournalPage(): void
    {
        $cleaned = $this->clean('<span class="pkp_block block_announcements evil ellipsis">x</span><a class="pkp_button" href="https://example.org">y</a>');

        self::assertStringContainsString('<span class="ellipsis">x</span>', $cleaned);
        self::assertStringNotContainsString('pkp_', $cleaned);
        self::assertStringNotContainsString('evil', $cleaned);
    }

    #[Test]
    public function linksOpenInANewTabWithoutLeakingTheOpenerOrTheReferrer(): void
    {
        $cleaned = $this->clean('<a href="https://example.org/x">link</a>');

        self::assertStringContainsString('href="https://example.org/x"', $cleaned);
        self::assertStringContainsString('target="_blank"', $cleaned);
        foreach (['nofollow', 'noopener', 'noreferrer'] as $type) {
            self::assertStringContainsString($type, $cleaned);
        }
    }

    #[Test]
    public function itRemovesEverythingThatLoadsAResourceFromAnotherServer(): void
    {
        $html = '<p>text</p>'
            . '<img src="https://files.example.social/avatar.png" alt="x">'
            . '<iframe src="https://evil.example/frame"></iframe>'
            . '<script src="https://evil.example/x.js"></script>'
            . '<link rel="stylesheet" href="https://evil.example/x.css">'
            . '<video src="https://evil.example/v.mp4"></video>'
            . '<object data="https://evil.example/o"></object>';

        self::assertSame('<p>text</p>', $this->clean($html));
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function attacks(): iterable
    {
        yield 'inline script' => ['<p>a</p><script>alert(1)</script>', '<p>a</p>'];
        yield 'event handler' => ['<p onclick="alert(1)">a</p>', '<p>a</p>'];
        yield 'style attribute' => ['<p style="position:fixed;top:0">a</p>', '<p>a</p>'];
        yield 'javascript link' => ['<a href="javascript:alert(1)">a</a>', '<a>a</a>'];
        yield 'data link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">a</a>', '<a>a</a>'];
        yield 'mailto link' => ['<a href="mailto:x@example.org">a</a>', '<a>a</a>'];
        yield 'image with an error handler' => ['<img src=x onerror=alert(1)>b', 'b'];
        yield 'svg' => ['<svg onload="alert(1)"><circle/></svg>t', 't'];
        yield 'an unclosed tag' => ['<p><strong>a', '<p><strong>a</strong></p>'];
    }

    #[Test]
    #[DataProvider('attacks')]
    public function itNeutralisesMarkupThatIsNotOnTheWhitelist(string $input, string $expected): void
    {
        self::assertSame($expected, $this->clean($input));
    }

    #[Test]
    public function anObfuscatedScriptSchemeDoesNotSurviveAsARunnableLink(): void
    {
        $cleaned = $this->clean('<a href="java&#x0A;script:alert(1)">a</a><a href=" JaVaScRiPt:alert(1)">b</a>');

        // Whatever is left of the href must not contain a scheme separator: a link
        // without one is a harmless relative address.
        preg_match_all('/href="([^"]*)"/', $cleaned, $matches);
        foreach ($matches[1] as $href) {
            self::assertStringNotContainsString(':', $href);
        }
        self::assertStringNotContainsString('javascript', strtolower($cleaned));
    }

    #[Test]
    public function itKeepsNonAsciiTextIntact(): void
    {
        self::assertSame('<p>Grüße 👋 — Ünïcödé</p>', $this->clean('<p>Grüße 👋 — Ünïcödé</p>'));
    }

    #[Test]
    public function anEmptyStringStaysEmpty(): void
    {
        self::assertSame('', $this->clean(''));
    }
}
