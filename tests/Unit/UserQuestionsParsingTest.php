<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UserQuestionsParsingTest extends TestCase
{
    public function testParsesAQuestionLine(): void
    {
        $parsed = parse_user_questions_text('glasses | Do you wear glasses? | Yes, No');

        $this->assertSame([[
            'id' => 'glasses',
            'label' => 'Do you wear glasses?',
            'options' => ['Yes', 'No'],
            'required' => false,
        ]], $parsed);
    }

    public function testRequiredFlagIsCaseInsensitive(): void
    {
        $parsed = parse_user_questions_text("a | A? | x, y | REQUIRED\nb | B? | x, y | something else");

        $this->assertTrue($parsed[0]['required']);
        $this->assertFalse($parsed[1]['required']);
    }

    public function testIdIsLowercasedStrippedAndCappedAt32Characters(): void
    {
        $parsed = parse_user_questions_text('My ID! | Q | a, b');
        $this->assertSame('myid', $parsed[0]['id']);

        $long = parse_user_questions_text(str_repeat('a', 50) . ' | Q | a, b');
        $this->assertSame(32, strlen($long[0]['id']));
    }

    public function testSkipsMalformedLines(): void
    {
        $text = implode("\n", [
            'only two | parts',
            ' | No id | a, b',
            'noLabel |  | a, b',
            'noOptions | Label |  , ,',
            '',
            'ok | Fine | a, b',
        ]);

        $parsed = parse_user_questions_text($text);
        $this->assertCount(1, $parsed);
        $this->assertSame('ok', $parsed[0]['id']);
    }

    public function testTrimsAndDeduplicatesOptions(): void
    {
        $parsed = parse_user_questions_text('q | Q | Yes ,  No, Yes,No ');
        $this->assertSame(['Yes', 'No'], $parsed[0]['options']);
    }

    public function testLaterLineWithSameIdWins(): void
    {
        $parsed = parse_user_questions_text("q | First | a, b\nq | Second | c, d");

        $this->assertCount(1, $parsed);
        $this->assertSame('Second', $parsed[0]['label']);
        $this->assertSame(['c', 'd'], $parsed[0]['options']);
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $parsed = parse_user_questions_text("a | A | x, y\r\nb | B | x, y\r\n");
        $this->assertSame(['a', 'b'], array_column($parsed, 'id'));
    }

    public function testFormatAndParseRoundTrip(): void
    {
        $questions = [
            ['id' => 'glasses', 'label' => 'Do you wear glasses?', 'options' => ['Yes', 'No'], 'required' => true],
            ['id' => 'contacts', 'label' => 'Contact lenses?', 'options' => ['Yes', 'No', 'Sometimes'], 'required' => false],
        ];

        $text = format_user_questions_text($questions);
        $this->assertSame("glasses | Do you wear glasses? | Yes, No | required\ncontacts | Contact lenses? | Yes, No, Sometimes", $text);
        $this->assertSame($questions, parse_user_questions_text($text));
    }
}
