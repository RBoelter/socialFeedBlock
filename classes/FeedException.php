<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use RuntimeException;
use Throwable;

/**
 * Thrown when a feed cannot be fetched or understood. The message is meant for
 * the server log; the error case is what an administrator gets to see.
 */
final class FeedException extends RuntimeException
{
    public function __construct(
        private readonly FeedError $error,
        string $detail = '',
        ?Throwable $previous = null
    ) {
        parent::__construct(trim($error->value . ': ' . $detail, ': '), 0, $previous);
    }

    public function getError(): FeedError
    {
        return $this->error;
    }
}
