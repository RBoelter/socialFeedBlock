<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\classes;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\StreamInterface;

/**
 * Reads JSON from a public API under hard limits: a slow, endless or hostile
 * answer must never hold up a journal page. Every failure surfaces as a
 * FeedException whose error case says what kind of failure it was.
 */
final class ApiClient
{
    public const CONNECT_TIMEOUT_SECONDS = 3;
    public const TIMEOUT_SECONDS = 5;
    public const MAX_REDIRECTS = 2;
    public const MAX_BODY_BYTES = 2097152;

    private const READ_CHUNK_BYTES = 8192;
    private const MAX_JSON_DEPTH = 32;

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * @param array<string, string|int> $query
     *
     * @throws FeedException
     *
     * @return array<mixed>
     */
    public function getJson(string $url, array $query = []): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'query' => $query,
                'headers' => ['Accept' => 'application/json'],
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                'allow_redirects' => ['max' => self::MAX_REDIRECTS, 'protocols' => ['https']],
                'stream' => true,
            ]);
        } catch (GuzzleException $exception) {
            throw new FeedException(FeedError::Unreachable, $url . ' - ' . $exception->getMessage(), $exception);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new FeedException(self::errorForStatus($status), "HTTP {$status} from {$url}");
        }

        return $this->decode($this->readLimited($response->getBody()), $url);
    }

    private static function errorForStatus(int $status): FeedError
    {
        return match (true) {
            $status === 401, $status === 403 => FeedError::AuthRequired,
            // Bluesky answers 400 for an account or feed that does not exist
            $status === 400, $status === 404, $status === 410 => FeedError::NotFound,
            $status === 429 => FeedError::RateLimited,
            $status >= 500 => FeedError::ServerError,
            default => FeedError::InvalidResponse,
        };
    }

    /** Reads the body but stops as soon as it is larger than allowed. */
    private function readLimited(StreamInterface $stream): string
    {
        $body = '';
        while (!$stream->eof() && strlen($body) <= self::MAX_BODY_BYTES) {
            $body .= $stream->read(self::READ_CHUNK_BYTES);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new FeedException(FeedError::InvalidResponse, 'response is larger than ' . self::MAX_BODY_BYTES . ' bytes');
        }

        return $body;
    }

    /** @return array<mixed> */
    private function decode(string $body, string $url): array
    {
        try {
            $data = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FeedException(FeedError::InvalidResponse, "invalid JSON from {$url}", $exception);
        }

        if (!is_array($data)) {
            throw new FeedException(FeedError::InvalidResponse, "expected a JSON object or list from {$url}");
        }

        return $data;
    }
}
