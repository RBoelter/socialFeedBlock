<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\ApiClient;
use APP\plugins\blocks\socialFeedBlock\classes\FeedError;
use APP\plugins\blocks\socialFeedBlock\classes\FeedException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @param list<Response|\Throwable> $queue */
    private function client(array $queue): ApiClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($this->history));

        return new ApiClient(new Client(['handler' => $stack]));
    }

    #[Test]
    public function itReturnsTheDecodedJson(): void
    {
        $client = $this->client([new Response(200, [], '{"id":"7","tags":["a","b"]}')]);

        self::assertSame(['id' => '7', 'tags' => ['a', 'b']], $client->getJson('https://example.social/api/v1/x'));
    }

    #[Test]
    public function itAcceptsAJsonList(): void
    {
        $client = $this->client([new Response(200, [], '[{"id":"1"},{"id":"2"}]')]);

        self::assertCount(2, $client->getJson('https://example.social/api/v1/x'));
    }

    #[Test]
    public function itSendsTheQueryAndAskForJsonWithinTheTimeLimits(): void
    {
        $client = $this->client([new Response(200, [], '[]')]);

        $client->getJson('https://example.social/api/v1/statuses', ['limit' => 10, 'feed' => 'at://did:plc:x/y']);

        $request = $this->history[0]['request'];
        $options = $this->history[0]['options'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('limit=10&feed=at%3A%2F%2Fdid%3Aplc%3Ax%2Fy', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame(ApiClient::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        self::assertSame(ApiClient::TIMEOUT_SECONDS, $options['timeout']);
    }

    #[Test]
    public function itOnlyFollowsARedirectOverHttps(): void
    {
        $client = $this->client([new Response(200, [], '[]')]);

        $client->getJson('https://example.social/x');

        $redirects = $this->history[0]['options']['allow_redirects'];
        self::assertSame(ApiClient::MAX_REDIRECTS, $redirects['max']);
        self::assertSame(['https'], $redirects['protocols']);
    }

    #[Test]
    public function aRedirectToAnotherHttpsHostIsFollowed(): void
    {
        $client = $this->client([
            new Response(301, ['Location' => 'https://web.example.social/api/v1/x']),
            new Response(200, [], '{"ok":true}'),
        ]);

        self::assertSame(['ok' => true], $client->getJson('https://example.social/api/v1/x'));
    }

    #[Test]
    public function aRedirectToPlainHttpIsRefused(): void
    {
        $client = $this->client([new Response(302, ['Location' => 'http://intranet.example/secret'])]);

        try {
            $client->getJson('https://example.social/api/v1/x');
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::Unreachable, $exception->getError());
            self::assertCount(1, $this->history, 'the plain-HTTP location must not be requested');
        }
    }

    /** @return iterable<string, array{0: int, 1: FeedError}> */
    public static function failingStatuses(): iterable
    {
        yield '401 needs a login' => [401, FeedError::AuthRequired];
        yield '403 forbids anonymous access' => [403, FeedError::AuthRequired];
        yield '404 is not found' => [404, FeedError::NotFound];
        yield '400 (Bluesky: unknown profile or feed)' => [400, FeedError::NotFound];
        yield '410 is gone' => [410, FeedError::NotFound];
        yield '429 is rate limited' => [429, FeedError::RateLimited];
        yield '500 is a server error' => [500, FeedError::ServerError];
        yield '503 is a server error' => [503, FeedError::ServerError];
        yield '422 is unexpected' => [422, FeedError::InvalidResponse];
        yield '302 that was not followed is unexpected' => [302, FeedError::InvalidResponse];
    }

    #[Test]
    #[DataProvider('failingStatuses')]
    public function anErrorStatusBecomesTheMatchingFeedError(int $status, FeedError $expected): void
    {
        $client = $this->client([new Response($status, [], '{"error":"nope"}')]);

        try {
            $client->getJson('https://example.social/x');
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame($expected, $exception->getError());
            self::assertStringContainsString((string) $status, $exception->getMessage());
        }
    }

    #[Test]
    public function aConnectionFailureIsReportedAsUnreachable(): void
    {
        $client = $this->client([new ConnectException('cURL error 6: Could not resolve host', new Request('GET', 'https://example.social/x'))]);

        try {
            $client->getJson('https://example.social/x');
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::Unreachable, $exception->getError());
            self::assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function undecodableBodies(): iterable
    {
        yield 'not JSON at all' => ['<html>Maintenance</html>'];
        yield 'an empty body' => [''];
        yield 'truncated JSON' => ['{"id":'];
        yield 'a JSON string' => ['"just text"'];
        yield 'a JSON number' => ['42'];
        yield 'JSON null' => ['null'];
    }

    #[Test]
    #[DataProvider('undecodableBodies')]
    public function anUnusableBodyIsAnInvalidResponse(string $body): void
    {
        $client = $this->client([new Response(200, [], $body)]);

        try {
            $client->getJson('https://example.social/x');
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::InvalidResponse, $exception->getError());
        }
    }

    #[Test]
    public function aBodyOverTheLimitIsRefusedWithoutBeingDecoded(): void
    {
        $body = '["' . str_repeat('a', ApiClient::MAX_BODY_BYTES) . '"]';
        $client = $this->client([new Response(200, [], $body)]);

        try {
            $client->getJson('https://example.social/x');
            self::fail('a FeedException was expected');
        } catch (FeedException $exception) {
            self::assertSame(FeedError::InvalidResponse, $exception->getError());
            self::assertStringContainsString('larger than', $exception->getMessage());
        }
    }

    #[Test]
    public function aBodyJustWithinTheLimitIsAccepted(): void
    {
        $filler = str_repeat('a', ApiClient::MAX_BODY_BYTES - 4);
        $client = $this->client([new Response(200, [], '["' . $filler . '"]')]);

        self::assertCount(1, $client->getJson('https://example.social/x'));
    }
}
