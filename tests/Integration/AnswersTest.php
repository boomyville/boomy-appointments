<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use Tests\Support\TestDatabase;

/** Booking questions: the user_questions setting and where answers are stored. */
final class AnswersTest extends DatabaseTestCase
{
    private int $customer;
    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::setSetting('user_questions', json_encode([
            ['id' => 'glasses', 'label' => 'Do you wear glasses?', 'options' => ['Yes', 'No'], 'required' => true],
            ['id' => 'contacts', 'label' => 'Do you wear contact lenses?', 'options' => ['Yes', 'No'], 'required' => false],
        ]));

        $this->customer = TestDatabase::customer('Mark Smith', 'family@example.com');
        $appointment = create_appointment($this->customer, TestDatabase::provider(), TestDatabase::appointmentType(30), '2030-01-07 10:00:00');
        $this->assertIsArray($appointment);
        $this->appointment = (int) $appointment['id'];
    }

    /** Answers come back in database order, which is not part of the contract, so compare ignoring key order. */
    private function assertAnswers(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testReadsConfiguredQuestions(): void
    {
        $questions = get_user_questions();

        $this->assertSame(['glasses', 'contacts'], array_column($questions, 'id'));
        $this->assertSame(['Yes', 'No'], $questions[0]['options']);
        $this->assertTrue($questions[0]['required']);
        $this->assertFalse($questions[1]['required']);
    }

    public function testInvalidOrIncompleteQuestionConfigurationIsIgnored(): void
    {
        TestDatabase::setSetting('user_questions', 'this is not json');
        $this->assertSame([], get_user_questions());

        TestDatabase::setSetting('user_questions', json_encode([
            ['id' => 'ok', 'label' => 'Fine?', 'options' => ['A']],
            ['id' => 'no_options', 'label' => 'Broken', 'options' => []],
            ['label' => 'No id', 'options' => ['A']],
            'not an array',
        ]));
        $this->assertSame(['ok'], array_column(get_user_questions(), 'id'));
    }

    public function testSanitizeKeepsOnlyValidAnswersForConfiguredQuestions(): void
    {
        $clean = sanitize_answers([
            'glasses'  => ' Yes ',
            'contacts' => 'Maybe',      // not an offered option
            'unknown'  => 'Yes',        // not a configured question
        ]);

        $this->assertSame(['glasses' => 'Yes'], $clean);
    }

    public function testRequiredQuestionsMustBeAnswered(): void
    {
        $this->assertSame('Please answer: Do you wear glasses?', validate_required_answers([]));
        $this->assertSame('Please answer: Do you wear glasses?', validate_required_answers(['contacts' => 'No']));
        $this->assertNull(validate_required_answers(['glasses' => 'No']));
    }

    public function testSavingStoresAnswersOnTheAppointmentAndTheCustomer(): void
    {
        $before = time();
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes', 'contacts' => 'No']);

        $this->assertAnswers(['glasses' => 'Yes', 'contacts' => 'No'], get_appointment_answers($this->appointment));

        $customerAnswers = get_customer_answers($this->customer);
        $this->assertSame('Yes', $customerAnswers['glasses'][0]);
        $this->assertSame('No', $customerAnswers['contacts'][0]);
        $this->assertGreaterThanOrEqual($before - 2, strtotime((string) $customerAnswers['glasses'][1]), 'updated_at is stamped with application time');
        $this->assertLessThanOrEqual(time() + 2, strtotime((string) $customerAnswers['glasses'][1]));
    }

    public function testSavingAgainReplacesPreviousAnswers(): void
    {
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes', 'contacts' => 'No']);
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'No']);

        $this->assertAnswers(['glasses' => 'No'], get_appointment_answers($this->appointment));
        $this->assertSame('No', get_customer_answers($this->customer)['glasses'][0]);
        $this->assertSame('No', get_customer_answers($this->customer)['contacts'][0], 'the customer keeps the last answer they gave');
    }

    public function testCustomerRecordIsLeftAloneWhenRequested(): void
    {
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes'], false);

        $this->assertAnswers(['glasses' => 'Yes'], get_appointment_answers($this->appointment));
        $this->assertSame([], get_customer_answers($this->customer));
    }

    public function testCopyingPromotesAppointmentAnswersToTheCustomer(): void
    {
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes'], false);

        copy_appointment_answers_to_customer($this->appointment, $this->customer);

        $this->assertSame('Yes', get_customer_answers($this->customer)['glasses'][0]);
    }

    public function testAnswersForRemovedQuestionsSurviveAnEdit(): void
    {
        $this->db()->prepare('INSERT INTO appointment_answers (appointment_id, question_id, answer) VALUES (?, ?, ?)')
            ->execute([$this->appointment, 'retired_question', 'Yes']);

        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'No']);

        $answers = get_appointment_answers($this->appointment);
        $this->assertSame('Yes', $answers['retired_question'], 'history for questions that are no longer asked is kept');
        $this->assertSame('No', $answers['glasses']);
    }

    public function testDeletingAnAppointmentDeletesItsAnswers(): void
    {
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes']);

        delete_appointment($this->appointment);

        $this->assertSame([], get_appointment_answers($this->appointment));
        $this->assertSame('Yes', get_customer_answers($this->customer)['glasses'][0], 'the customer record is separate');
    }

    public function testRemovedOptionsDisplayAsNotApplicable(): void
    {
        $question = ['id' => 'q', 'label' => 'Q', 'options' => ['Yes', 'No'], 'required' => false];

        $this->assertSame('Yes', answer_display($question, 'Yes'));
        $this->assertSame('n/a', answer_display($question, 'Maybe'));
        $this->assertSame('n/a', answer_display($question, null));
    }

    public function testCustomerProfileListsEveryConfiguredQuestion(): void
    {
        save_appointment_answers($this->appointment, $this->customer, ['glasses' => 'Yes']);
        // Simulate the option being removed from the setting afterwards
        TestDatabase::setSetting('user_questions', json_encode([
            ['id' => 'glasses', 'label' => 'Do you wear glasses?', 'options' => ['No'], 'required' => false],
            ['id' => 'contacts', 'label' => 'Do you wear contact lenses?', 'options' => ['Yes', 'No'], 'required' => false],
        ]));

        $display = get_customer_answers_display($this->customer);

        $this->assertCount(2, $display);
        $this->assertSame('glasses', $display[0]['id']);
        $this->assertSame('n/a', $display[0]['answer'], '"Yes" is no longer an offered option');
        $this->assertNotNull($display[0]['updated_at']);
        $this->assertSame('', $display[1]['answer'], 'never answered');
        $this->assertNull($display[1]['updated_at']);
    }
}
