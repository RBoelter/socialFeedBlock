<?php

declare(strict_types=1);

namespace APP\plugins\blocks\socialFeedBlock\tests;

use APP\plugins\blocks\socialFeedBlock\classes\Payload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    private const DATA = [
        'name' => 'Ada',
        'count' => 7,
        'negative' => -3,
        'tags' => ['a', 'b'],
        'nested' => ['deep' => ['value' => 'x', 'zero' => 0, 'nothing' => null]],
    ];

    #[Test]
    public function getFollowsANestedPath(): void
    {
        self::assertSame('x', Payload::get(self::DATA, 'nested', 'deep', 'value'));
        self::assertSame(self::DATA, Payload::get(self::DATA));
    }

    #[Test]
    public function getReturnsNullForAnyMissingStepOrANonArray(): void
    {
        self::assertNull(Payload::get(self::DATA, 'missing'));
        self::assertNull(Payload::get(self::DATA, 'nested', 'missing', 'value'));
        self::assertNull(Payload::get(self::DATA, 'name', 'deeper'));
        self::assertNull(Payload::get('not an array', 'a'));
        self::assertNull(Payload::get(null, 'a'));
    }

    #[Test]
    public function stringOnlyAcceptsStrings(): void
    {
        self::assertSame('Ada', Payload::string(self::DATA, 'name'));
        self::assertNull(Payload::string(self::DATA, 'count'));
        self::assertNull(Payload::string(self::DATA, 'tags'));
        self::assertNull(Payload::string(self::DATA, 'nested', 'deep', 'nothing'));
    }

    #[Test]
    public function countAcceptsOnlyPositiveIntegers(): void
    {
        self::assertSame(7, Payload::count(self::DATA, 'count'));
        self::assertSame(0, Payload::count(self::DATA, 'negative'));
        self::assertSame(0, Payload::count(self::DATA, 'name'));
        self::assertSame(0, Payload::count(self::DATA, 'nested', 'deep', 'zero'));
        self::assertSame(0, Payload::count(self::DATA, 'missing'));
        self::assertSame(0, Payload::count(['n' => '5'], 'n'), 'a numeric string is not trusted as a count');
    }

    #[Test]
    public function itemsReturnsAnArrayOrNothing(): void
    {
        self::assertSame(['a', 'b'], Payload::items(self::DATA, 'tags'));
        self::assertSame([], Payload::items(self::DATA, 'name'));
        self::assertSame([], Payload::items(self::DATA, 'missing'));
    }

    /** @return iterable<string, array{0: mixed, 1: ?string}> */
    public static function webUrls(): iterable
    {
        yield 'https' => ['https://example.social/@a/1', 'https://example.social/@a/1'];
        yield 'http' => ['http://example.social/@a/1', 'http://example.social/@a/1'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD4=', null];
        yield 'ftp' => ['ftp://example.social/file', null];
        yield 'a relative address' => ['/@a/1', null];
        yield 'not a url' => ['hello world', null];
        yield 'a number' => [5, null];
        yield 'missing' => [null, null];
    }

    #[Test]
    #[DataProvider('webUrls')]
    public function webUrlOnlyAcceptsHttpAndHttps(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, Payload::webUrl(['url' => $value], 'url'));
    }

    #[Test]
    public function dateParsesIsoTimestamps(): void
    {
        $date = Payload::date(['at' => '2026-09-18T21:32:30.367Z'], 'at');

        self::assertSame('2026-09-18T21:32:30+00:00', $date->format('c'));
    }

    #[Test]
    public function dateIsNullForGarbageOrAbsence(): void
    {
        self::assertNull(Payload::date(['at' => 'not a date'], 'at'));
        self::assertNull(Payload::date(['at' => 12345], 'at'));
        self::assertNull(Payload::date([], 'at'));
    }
}
