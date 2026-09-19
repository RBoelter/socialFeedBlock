<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\FeedError;
use APP\plugins\blocks\socialFeedBlock\classes\Setting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The locale files are the one place a wrong key stays invisible until a
 * user reads a raw key on screen, so their consistency is tested.
 */
final class LocaleTest extends TestCase
{
    private const LANGUAGES = ['en', 'de', 'es', 'fr'];
    private const KEY_PREFIX = 'plugins.blocks.socialFeed.';

    /** @return array<string, string> key => text */
    private static function messages(string $language): array
    {
        $po = file_get_contents(dirname(__DIR__) . "/locale/{$language}/locale.po");
        preg_match_all('/^msgid "([^"]+)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"$/m', $po, $matches, PREG_SET_ORDER);

        $messages = [];
        foreach ($matches as [, $key, $text]) {
            $messages[$key] = stripcslashes($text);
        }

        return $messages;
    }

    /** @return list<string> the keys the plugin's code and templates refer to */
    private static function usedKeys(): array
    {
        $files = array_merge(
            glob(dirname(__DIR__) . '/*.php'),
            glob(dirname(__DIR__) . '/classes/*.php'),
            glob(dirname(__DIR__) . '/templates/*.tpl')
        );

        $keys = [];
        foreach ($files as $file) {
            // A match followed by a dot is only the start of a key that is completed at run time
            preg_match_all('/plugins\.blocks\.socialFeed\.[A-Za-z]+(?:\.[A-Za-z]+)*(?![A-Za-z.])/', file_get_contents($file), $matches);
            $keys = array_merge($keys, $matches[0]);
        }

        // Keys that are assembled from a name at run time and cannot be found by reading the code
        foreach (FeedError::cases() as $error) {
            $keys[] = $error->localeKey();
        }
        foreach (Setting::cases() as $setting) {
            if (in_array($setting->type(), ['string'], true) && $setting !== Setting::BlockTitle) {
                $keys[] = self::KEY_PREFIX . 'validation.' . $setting->value;
            }
        }
        $keys[] = self::KEY_PREFIX . 'validation.range';

        return array_values(array_unique($keys));
    }

    /** @return iterable<string, array{0: string}> */
    public static function languages(): iterable
    {
        foreach (self::LANGUAGES as $language) {
            yield $language => [$language];
        }
    }

    #[Test]
    #[DataProvider('languages')]
    public function everyKeyTheCodeUsesIsTranslated(string $language): void
    {
        $messages = self::messages($language);

        foreach (self::usedKeys() as $key) {
            self::assertArrayHasKey($key, $messages, "{$language}: missing {$key}");
            self::assertNotSame('', trim($messages[$key]), "{$language}: {$key} is empty");
        }
    }

    #[Test]
    #[DataProvider('languages')]
    public function noTranslationIsLeftOverThatNothingUses(string $language): void
    {
        $unused = array_diff(array_keys(self::messages($language)), self::usedKeys());

        self::assertSame([], array_values($unused), "{$language}: keys nothing refers to");
    }

    #[Test]
    #[DataProvider('languages')]
    public function everyLanguageHasTheSameKeysAsEnglish(string $language): void
    {
        self::assertEqualsCanonicalizing(array_keys(self::messages('en')), array_keys(self::messages($language)));
    }

    #[Test]
    #[DataProvider('languages')]
    public function everyLanguageUsesTheSamePlaceholdersAsEnglish(string $language): void
    {
        $placeholders = static fn (string $text): array => (preg_match_all('/\{\$(\w+)\}/', $text, $m) ? array_unique($m[1]) : []);

        foreach (self::messages('en') as $key => $english) {
            $translated = self::messages($language)[$key] ?? '';
            self::assertEqualsCanonicalizing($placeholders($english), $placeholders($translated), "{$language}: placeholders of {$key}");
        }
    }

    /**
     * OJS reserves key, count, locale and params for {translate}: count switches
     * to the plural form, which needs gettext plural entries, and the value is
     * not available as a {$count} placeholder. A message that takes a number
     * has to receive it under another name.
     */
    #[Test]
    public function noTemplatePassesAReservedParameterToTheTranslateFunction(): void
    {
        foreach (glob(dirname(__DIR__) . '/templates/*.tpl') as $template) {
            preg_match_all('/\{translate\b[^}]*\}/', file_get_contents($template), $calls);
            foreach ($calls[0] as $call) {
                self::assertDoesNotMatchRegularExpression('/\s(count|locale|params)=/', $call, basename($template) . ': ' . $call);
            }
        }
    }

    #[Test]
    #[DataProvider('languages')]
    public function theFileHasAHeaderThatNamesItsLanguage(string $language): void
    {
        $po = file_get_contents(dirname(__DIR__) . "/locale/{$language}/locale.po");

        self::assertStringContainsString("\"Language: {$language}\\n\"", $po);
        self::assertStringContainsString('charset=UTF-8', $po);
    }
}
