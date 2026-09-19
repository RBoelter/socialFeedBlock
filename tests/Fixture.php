<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Throwable;

/**
 * Test helpers shared by the provider tests: recorded-shape API responses and
 * an ApiClient that answers from a queue instead of the network.
 */
final class Fixture
{
    public static function json(string $name): string
    {
        $file = __DIR__ . '/fixtures/' . $name;
        $content = is_file($file) ? file_get_contents($file) : false;
        if ($content === false) {
            throw new RuntimeException("Missing fixture {$name}");
        }

        return $content;
    }

    public static function response(string $name, int $status = 200): Response
    {
        return new Response($status, [], self::json($name));
    }

    /**
     * @param list<Response|Throwable> $queue answers, in the order they are requested
     * @param array<int, array<string, mixed>> $history filled with every request that was made
     */
    public static function client(array $queue, array &$history): ApiClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));

        return new ApiClient(new Client(['handler' => $stack]));
    }
}
