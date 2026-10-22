<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Cube\Util\QueryString;

/** Parser de query string: vazios, codificação percentual, UTF-8, pontos no nome e chaves repetidas. */
final class QueryStringTest extends TestCase
{
    /** @return iterable<string, array{string|null, array<string, string|list<string>>}> */
    public static function parseCases(): iterable
    {
        yield 'singleParam' => ['name=Alice', ['name' => 'Alice']];
        yield 'numericValueStaysString' => ['id=42', ['id' => '42']];
        yield 'multipleParams' => ['a=1&b=2&c=3', ['a' => '1', 'b' => '2', 'c' => '3']];
        yield 'emptyValue' => ['key=', ['key' => '']];
        yield 'valueContainsEquals' => ['expr=a=b', ['expr' => 'a=b']];
        yield 'plusDecodedAsSpace' => ['msg=hello+world', ['msg' => 'hello world']];
        yield 'percentEncodedSpace' => ['msg=hello%20world', ['msg' => 'hello world']];
        yield 'percentEncodedAmpersand' => ['val=a%26b', ['val' => 'a&b']];
        yield 'percentEncodedEquals' => ['val=a%3Db', ['val' => 'a=b']];
        yield 'lowerHex' => ['p=a%2fb', ['p' => 'a/b']];
        yield 'upperHex' => ['p=a%2Fb', ['p' => 'a/b']];
        yield 'null' => [null, []];
        yield 'empty' => ['', []];
        yield 'blank' => ['   ', []];
        yield 'keyWithoutEquals_ignored' => ['lonely', []];
        yield 'mixedKeyWithAndWithoutValue' => ['orphan&real=value', ['real' => 'value']];
        yield 'trailingAmpersand' => ['a=1&', ['a' => '1']];
        yield 'leadingAmpersand' => ['&a=1', ['a' => '1']];
        yield 'consecutiveAmpersands' => ['a=1&&b=2', ['a' => '1', 'b' => '2']];
        yield 'utf8' => ['name=caf%C3%A9', ['name' => 'café']];
        yield 'specialChars' => ['q=hello+world%21', ['q' => 'hello world!']];
        yield 'percentEncodedKey' => ['%6Bey=val', ['key' => 'val']];
        yield 'dotsInNameAreKept' => ['a.b=1', ['a.b' => '1']];   // parse_str() trocaria por "a_b"
        yield 'repeatedKey_becomesList' => ['t=1&t=2&t=3', ['t' => ['1', '2', '3']]];   // chave repetida vira lista, não sobrescreve
    }

    /** @param array<string, string|list<string>> $expected */
    #[Test]
    #[DataProvider('parseCases')]
    public function parse(?string $query, array $expected): void
    {
        self::assertSame($expected, QueryString::parse($query));
    }

    #[Test]
    public function longValue(): void
    {
        $long = str_repeat('a', 500);
        self::assertSame(['data' => $long], QueryString::parse("data={$long}"));
    }

    #[Test]
    public function build_skipsNullAndBlank_andRepeatsLists(): void
    {
        self::assertSame(
            'a=1&t=x&t=y&b=true',
            QueryString::build(['a' => 1, 'n' => null, 'e' => '  ', 't' => ['x', 'y'], 'b' => true]),
        );
    }

    #[Test]
    public function build_encodes_soParseRoundTrips(): void
    {
        $params = ['q' => 'café & chá=bom', 'path' => 'a/b?c'];
        $query = QueryString::build($params);

        self::assertSame('q=caf%C3%A9%20%26%20ch%C3%A1%3Dbom&path=a%2Fb%3Fc', $query);
        self::assertSame($params, QueryString::parse($query));
    }
}
