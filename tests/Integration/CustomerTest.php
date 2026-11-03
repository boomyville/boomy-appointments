<?php
declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;

final class CustomerTest extends DatabaseTestCase
{
    public function testCreatesACustomer(): void
    {
        $id = create_customer('Mark Smith', 'mark@example.com', '0400111222');

        $this->assertIsInt($id);
        $customer = get_customer_by_id($id);
        $this->assertSame('Mark Smith', $customer['full_name']);
        $this->assertSame('mark@example.com', $customer['email']);
        $this->assertSame('0400111222', $customer['mobile']);
    }

    public function testMissingEmailAndMobileAreStoredAsEmptyStrings(): void
    {
        $id = create_customer('No Contact', null, null);

        $customer = get_customer_by_id($id);
        $this->assertSame('', $customer['email']);
        $this->assertSame('', $customer['mobile']);
    }

    public function testDateAddedIsStampedWithApplicationTime(): void
    {
        $before = time();
        $id = create_customer('Clock Check', 'clock@example.com', '0400000001');
        $after = time();

        $stamped = strtotime((string) get_customer_by_id($id)['date_added']);
        $this->assertGreaterThanOrEqual($before - 2, $stamped);
        $this->assertLessThanOrEqual($after + 2, $stamped);
    }

    public function testDateOfBirthAndGenderAreNormalised(): void
    {
        $id = create_customer('Jane Doe', 'jane@example.com', '0400000002', '1990-05-17', ' Female ');
        $customer = get_customer_by_id($id);

        $this->assertSame('1990-05-17', $customer['date_of_birth']);
        $this->assertSame('female', $customer['gender']);
    }

    public function testInvalidDateOfBirthAndGenderAreIgnored(): void
    {
        $id = create_customer('Bad Data', 'bad@example.com', '0400000003', '31/02/1990', 'robot');
        $customer = get_customer_by_id($id);

        $this->assertNull($customer['date_of_birth']);
        $this->assertSame('unknown', $customer['gender']);
    }

    public function testFindsCustomersByExactEmailAndMobile(): void
    {
        $mark = create_customer('Mark Smith', 'family@example.com', '0400111222');
        $jay = create_customer('Jay Smith', 'family@example.com', '0400111222');
        create_customer('Other Mobile', 'family@example.com', '0400999999');
        create_customer('Other Email', 'someone@else.com', '0400111222');

        $matches = get_customers_by_exact_contact('family@example.com', '0400111222');

        $this->assertSame([$mark, $jay], array_map(static fn(array $c): int => (int) $c['id'], $matches));
    }

    public function testExactContactLookupTrimsInputAndReturnsNothingWhenThereIsNoMatch(): void
    {
        $id = create_customer('Padded', 'pad@example.com', '0400555666');

        $this->assertCount(1, get_customers_by_exact_contact('  pad@example.com ', ' 0400555666  '));
        $this->assertSame($id, (int) get_customers_by_exact_contact('pad@example.com', '0400555666')[0]['id']);
        $this->assertSame([], get_customers_by_exact_contact('pad@example.com', '0400000000'));
        $this->assertSame([], get_customers_by_exact_contact('pad@exam', '0400555666'), 'partial emails never match');
    }
}
