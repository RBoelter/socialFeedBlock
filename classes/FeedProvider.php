<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * A social network the block can show posts from. Adding a network means
 * adding one implementation of this interface; nothing that renders posts
 * needs to change.
 */
interface FeedProvider
{
    /**
     * Fetches the posts described by the configuration, newest first: at most
     * $config->postCount of them, already sanitised.
     *
     * @throws FeedException when the feed cannot be fetched or understood
     *
     * @return list<Post>
     */
    public function fetch(FeedConfig $config): array;
}
