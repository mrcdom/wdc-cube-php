<?php

declare(strict_types=1);

namespace Wdc\Tests\Framework\Cube\Remote;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wdc\Framework\Commons\Security\Rsa;
use Wdc\Framework\Cube\CubeView;
use Wdc\Framework\Cube\Remote\RemoteAppSecurity;
use Wdc\Framework\Cube\Remote\Transient;
use Wdc\Framework\Cube\Remote\ViewStateSerializer;
use Wdc\Framework\Cube\ViewState;
use Wdc\Tests\Framework\Cube\Fixture\FakeView;

final class SampleItem
{
    public int $id = 7;

    public ?string $note = null;

    public string $blank = ' ';
}

final class SampleState implements ViewState
{
    public static string $ignoredStatic = 'x';

    public ?CubeView $contentView = null;

    public ?CubeView $missingView = null;

    public bool $loading = false;

    public ?bool $maybe = null;

    public int $count = 0;

    public float $price = 10.0;

    public ?string $name = null;

    public string $blank = '   ';

    public string $text = '<b>&</b> /ok/ café';

    /** @var list<SampleItem> */
    public array $items = [];

    /** @var array<string, int> */
    public array $map = ['a' => 1];

    /** @var list<?CubeView> */
    public array $views = [];

    public ?DateTimeImmutable $at = null;

    #[Transient]
    public string $secret = 'not sent';
}

final class RemoteSecurityAndSerializerTest extends TestCase
{
    #[Test]
    public function serializer_followsTheWireFormatRules(): void
    {
        $state = new SampleState();
        $state->contentView = new FakeView('c677cda52d14:2');
        $state->items = [new SampleItem()];
        $state->views = [new FakeView('a:1'), null];
        $state->at = new DateTimeImmutable('2024-05-01 09:00:00-03:00');

        $json = ViewStateSerializer::json(ViewStateSerializer::serialize($state, 'f2d345c4a610:1'));

        self::assertSame(
            '{"#":"f2d345c4a610:1","contentViewId":"c677cda52d14:2","loading":false,"count":0,"price":10.0,'
            . '"text":"<b>&</b> /ok/ café","items":[{"id":7,"blank":" "}],"map":{"a":1},"views":["a:1",null],'
            . '"at":"2024-05-01T12:00:00Z"}',
            $json,
        );
    }

    #[Test]
    public function serializer_emptyListAndEmptyMap(): void
    {
        $state = new SampleState();
        $state->map = [];

        $data = ViewStateSerializer::serialize($state, 'x:1');

        self::assertSame('[]', ViewStateSerializer::json($data['items']));
        self::assertSame('[]', ViewStateSerializer::json($data['map']), 'an empty array is ambiguous in PHP: it is emitted as a list');
    }

    #[Test]
    public function signatures_areDeterministic_andAppIdsCheckOut(): void
    {
        $keys = RemoteAppSecurity::generateDevKeys();
        $security = new RemoteAppSecurity(Rsa::fromBase36($keys['rsaPublicKey'], $keys['rsaPrivateKey']), $keys['signPublicKey'], $keys['signPrivateKey']);

        self::assertSame($security->sign('home?userId=0'), $security->sign('home?userId=0'), 'PKCS#1 v1.5: same input, same signature');
        self::assertTrue($security->isSignatureValid('home?userId=0', $security->sign('home?userId=0')));
        self::assertSame(16, strlen($security->signAsHash('x')));

        $appId = $security->newAppId();
        self::assertTrue($security->isValidAppId($appId));
        self::assertFalse($security->isValidAppId($appId . 'x'));
        self::assertFalse($security->isValidAppId('no-dot'));
        self::assertMatchesRegularExpression('/^[0-9A-Za-z]+\.[0-9A-Za-z]+$/', $appId);
    }
}
