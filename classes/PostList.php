<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

/**
 * The step every provider ends with: turn the entries of an API answer into
 * posts, dropping the ones that cannot be used, and stop at the wanted number.
 */
final class PostList
{
    /**
     * @param iterable<mixed> $entries the entries of the API answer
     * @param callable(mixed): ?Post $toPost null for an entry that is unwanted or unusable
     *
     * @return list<Post> in the order of the entries
     */
    public static function collect(iterable $entries, callable $toPost, int $max): array
    {
        $posts = [];
        foreach ($entries as $entry) {
            $post = $toPost($entry);
            if ($post === null) {
                continue;
            }

            $posts[] = $post;
            if (count($posts) === $max) {
                break;
            }
        }

        return $posts;
    }
}
