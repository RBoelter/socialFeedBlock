<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Every plugin setting, defined once: the name it is stored and submitted
 * under, and the type OJS stores it as.
 */
enum Setting: string
{
    case BlockTitle = 'blockTitle';
    case Network = 'network';
    case MastodonInstance = 'mastodonInstance';
    case MastodonSource = 'mastodonSource';
    case MastodonHandle = 'mastodonHandle';
    case BlueskySource = 'blueskySource';
    case BlueskyActor = 'blueskyActor';
    case PostCount = 'postCount';
    case ExcludeReplies = 'excludeReplies';
    case ExcludeReposts = 'excludeReposts';
    case CacheTtl = 'cacheTtl';

    /** The type name Plugin::updateSetting() expects. */
    public function type(): string
    {
        return match ($this) {
            self::PostCount, self::CacheTtl => 'int',
            self::ExcludeReplies, self::ExcludeReposts => 'bool',
            default => 'string',
        };
    }

    /** @return list<string> the names of all settings */
    public static function names(): array
    {
        return array_map(static fn (self $setting): string => $setting->value, self::cases());
    }
}
