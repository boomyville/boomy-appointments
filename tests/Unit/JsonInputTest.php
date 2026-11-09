<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JsonInputTest extends TestCase
{
    public function testValidObjectIsDecoded(): void
    {
        $this->assertSame(
            ['customer_email' => 'a@b.c', 'n' => 3],
            normalize_json_input('{"customer_email":"a@b.c","n":3}')
        );
    }

    public function testGarbageAndEmptyBodiesGiveAnEmptyArray(): void
    {
        $this->assertSame([], normalize_json_input(''));
        $this->assertSame([], normalize_json_input(false));
        $this->assertSame([], normalize_json_input('{not json'));
        $this->assertSame([], normalize_json_input('"just a string"'));
    }

    public function testArraysWhereTextIsExpectedBecomeEmptyStrings(): void
    {
        $data = normalize_json_input('{"customer_email":[1],"customer_mobile":{"a":1},"customer_mode":[[]],"ok":"x"}');
        $this->assertSame('', $data['customer_email']);
        $this->assertSame('', $data['customer_mobile']);
        $this->assertSame('', $data['customer_mode']);
        $this->assertSame('x', $data['ok']);

        // The point: casting is now harmless (no "Array to string conversion" warning).
        $this->assertSame('', trim((string) $data['customer_email']));
    }

    public function testListedKeysMayStayArrays(): void
    {
        $data = normalize_json_input('{"answers":{"1":"Yes"},"other":[1]}', ['answers']);
        $this->assertSame(['1' => 'Yes'], $data['answers']);
        $this->assertSame('', $data['other']);
    }
}
