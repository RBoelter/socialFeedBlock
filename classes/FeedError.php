<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * Why a feed could not be loaded. Each case maps to a locale key, so the reason
 * can be shown to an administrator in their own language.
 */
enum FeedError: string
{
    case Unreachable = 'unreachable';
    case AuthRequired = 'authRequired';
    case NotFound = 'notFound';
    case RateLimited = 'rateLimited';
    case ServerError = 'serverError';
    case InvalidResponse = 'invalidResponse';

    public function localeKey(): string
    {
        return 'plugins.blocks.socialFeed.error.' . $this->value;
    }
}
