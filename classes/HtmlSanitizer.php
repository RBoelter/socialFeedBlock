<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Reduces the HTML a Mastodon server sends to a small, safe subset.
 *
 * OJS' own PKPString::stripUnsafeHtml() is not used because its whitelist has
 * no <span> and no class attribute, which Mastodon needs to shorten long URLs
 * and mark mentions and hashtags.
 *
 * There is deliberately no <img>, <iframe> or <script> in the whitelist: a
 * post can therefore never make a visitor's browser contact another server.
 */
final class HtmlSanitizer
{
    private const ALLOWED_ELEMENTS = 'p,br,a[href|rel|target],span[class],em,strong,del,code,pre,blockquote,ul,ol,li';

    /**
     * The classes Mastodon puts on the <span> elements it generates: the two that
     * shorten a long URL, and the one that wraps a mention. Classes on links are
     * not allowed, nothing here needs them.
     */
    private const ALLOWED_CLASSES = ['invisible', 'ellipsis', 'h-card'];

    private const ALLOWED_LINK_TYPES = ['nofollow', 'noopener', 'noreferrer', 'ugc'];

    private ?HTMLPurifier $purifier = null;

    public function clean(string $html): string
    {
        return trim($this->purifier()->purify($html));
    }

    private function purifier(): HTMLPurifier
    {
        return $this->purifier ??= new HTMLPurifier($this->configuration());
    }

    private function configuration(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        // Transitional is the doctype that allows target="_blank"; PKP uses it as well.
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.Allowed', self::ALLOWED_ELEMENTS);
        $config->set('Attr.AllowedClasses', self::ALLOWED_CLASSES);
        $config->set('Attr.AllowedRel', self::ALLOWED_LINK_TYPES);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Nofollow', true);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        // The definition cache would write to disk; sanitising only happens on a
        // cache miss of the feed, so building the definition each time is cheap.
        $config->set('Cache.DefinitionImpl', null);

        return $config;
    }
}
