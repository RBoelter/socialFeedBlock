<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Turns the raw plugin settings into a FeedConfig, or explains what is wrong.
 *
 * The settings form and the block both go through here, so what the form
 * accepts is exactly what the block will show. Only the fields of the selected
 * network are checked; the other network's fields are ignored.
 *
 * An error is ['key' => <locale key>, 'params' => <values for the message>],
 * listed per setting name.
 */
final class FeedSettings
{
    private const HOST_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/i';
    private const MASTODON_ACCOUNT_PATTERN = '/^[a-z0-9_]+(?:[a-z0-9_.-]*[a-z0-9_])?$/i';
    private const HASHTAG_PATTERN = '/^[\p{L}\p{N}_]+$/u';
    private const BLUESKY_HANDLE_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i';
    private const DID_PATTERN = '/^did:(?:plc|web):[a-z0-9._%:-]+$/i';
    private const BLUESKY_FEED_PATTERN = '#^at://did:(?:plc|web):[a-z0-9._%:-]+/app\.bsky\.feed\.generator/[a-z0-9._~:-]+$#i';

    private const VALIDATION_PREFIX = 'plugins.blocks.socialFeed.validation.';
    private const MAX_NUMBER_LENGTH = 5;

    /**
     * @param array<string, mixed> $raw the plugin settings as stored or submitted
     *
     * @return array<string, array{key: string, params: array<string, int>}> empty when the settings are valid
     */
    public static function errors(array $raw): array
    {
        return self::analyse($raw)[1];
    }

    /**
     * @param array<string, mixed> $raw the plugin settings as stored or submitted
     *
     * @return ?FeedConfig null when the settings are incomplete or invalid
     */
    public static function config(array $raw): ?FeedConfig
    {
        return self::analyse($raw)[0];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array{0: ?FeedConfig, 1: array<string, array{key: string, params: array<string, int>}>}
     */
    private static function analyse(array $raw): array
    {
        $errors = [];

        $postCount = self::parseNumber(
            $raw,
            Setting::PostCount,
            [FeedConfig::MIN_POSTS, FeedConfig::MAX_POSTS, FeedConfig::DEFAULT_POSTS],
            $errors
        );
        $cacheMinutes = self::parseNumber(
            $raw,
            Setting::CacheTtl,
            [FeedConfig::MIN_CACHE_MINUTES, FeedConfig::MAX_CACHE_MINUTES, FeedConfig::DEFAULT_CACHE_MINUTES],
            $errors
        );

        $network = self::text($raw[Setting::Network->value] ?? '');
        $source = match ($network) {
            FeedConfig::NETWORK_MASTODON => self::parseMastodon($raw, $errors),
            FeedConfig::NETWORK_BLUESKY => self::parseBluesky($raw, $errors),
            default => self::fail(Setting::Network, $errors),
        };

        if ($errors !== [] || $source === null) {
            return [null, $errors];
        }

        $config = new FeedConfig(
            $network,
            $source['source'],
            $source['target'],
            $source['instance'],
            $postCount,
            filter_var($raw[Setting::ExcludeReplies->value] ?? false, FILTER_VALIDATE_BOOLEAN),
            filter_var($raw[Setting::ExcludeReposts->value] ?? false, FILTER_VALIDATE_BOOLEAN),
            $cacheMinutes
        );

        return [$config, []];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, array{key: string, params: array<string, int>}> $errors
     *
     * @return ?array{source: string, target: string, instance: ?string}
     */
    private static function parseMastodon(array $raw, array &$errors): ?array
    {
        $failed = false;

        $instance = strtolower(self::text($raw[Setting::MastodonInstance->value] ?? ''));
        if (preg_match(self::HOST_PATTERN, $instance) !== 1) {
            self::fail(Setting::MastodonInstance, $errors);
            $failed = true;
        }

        $source = self::text($raw[Setting::MastodonSource->value] ?? '');
        $handle = self::text($raw[Setting::MastodonHandle->value] ?? '');

        if ($source === FeedConfig::SOURCE_ACCOUNT) {
            $target = ltrim($handle, '@');
            $targetIsValid = preg_match(self::MASTODON_ACCOUNT_PATTERN, $target) === 1;
        } elseif ($source === FeedConfig::SOURCE_HASHTAG) {
            $target = ltrim($handle, '#');
            $targetIsValid = preg_match(self::HASHTAG_PATTERN, $target) === 1;
        } else {
            // Without a known source there is no rule to judge the account or hashtag by
            return self::fail(Setting::MastodonSource, $errors);
        }

        if (!$targetIsValid) {
            self::fail(Setting::MastodonHandle, $errors);
            $failed = true;
        }

        return $failed ? null : ['source' => $source, 'target' => $target, 'instance' => $instance];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, array{key: string, params: array<string, int>}> $errors
     *
     * @return ?array{source: string, target: string, instance: null}
     */
    private static function parseBluesky(array $raw, array &$errors): ?array
    {
        $source = self::text($raw[Setting::BlueskySource->value] ?? '');
        $actor = self::text($raw[Setting::BlueskyActor->value] ?? '');

        if ($source === FeedConfig::SOURCE_AUTHOR) {
            $target = ltrim($actor, '@');
            $isValid = preg_match(self::BLUESKY_HANDLE_PATTERN, $target) === 1 || preg_match(self::DID_PATTERN, $target) === 1;
        } elseif ($source === FeedConfig::SOURCE_FEED) {
            $target = $actor;
            $isValid = preg_match(self::BLUESKY_FEED_PATTERN, $target) === 1;
        } else {
            // Without a known source there is no rule to judge the account or feed by
            return self::fail(Setting::BlueskySource, $errors);
        }

        return $isValid ? ['source' => $source, 'target' => $target, 'instance' => null] : self::fail(Setting::BlueskyActor, $errors);
    }

    /**
     * Reads a whole number setting, recording a range error when it is not valid.
     *
     * @param array<string, mixed> $raw
     * @param array{0: int, 1: int, 2: int} $limits minimum, maximum and the default used when the setting is empty
     * @param array<string, array{key: string, params: array<string, int>}> $errors
     */
    private static function parseNumber(array $raw, Setting $setting, array $limits, array &$errors): ?int
    {
        [$min, $max, $default] = $limits;
        $number = self::parseInt($raw[$setting->value] ?? '', $min, $max, $default);

        if ($number === null) {
            $errors[$setting->value] = self::rangeError($min, $max);
        }

        return $number;
    }

    /** An empty value means "use the default"; anything else must be a whole number in range. */
    private static function parseInt(mixed $value, int $min, int $max, int $default): ?int
    {
        $text = self::text($value);
        if ($text === '') {
            return $default;
        }
        if (!ctype_digit($text) || strlen($text) > self::MAX_NUMBER_LENGTH) {
            return null;
        }
        $number = (int) $text;

        return $number >= $min && $number <= $max ? $number : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Records an error for the setting and returns null, so callers can
     * `return self::fail(...)`.
     *
     * @param array<string, array{key: string, params: array<string, int>}> $errors
     */
    private static function fail(Setting $setting, array &$errors): null
    {
        $errors[$setting->value] = ['key' => self::VALIDATION_PREFIX . $setting->value, 'params' => []];

        return null;
    }

    /** @return array{key: string, params: array<string, int>} */
    private static function rangeError(int $min, int $max): array
    {
        return ['key' => self::VALIDATION_PREFIX . 'range', 'params' => ['min' => $min, 'max' => $max]];
    }
}
