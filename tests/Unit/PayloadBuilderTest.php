<?php

namespace Dgtlss\Clearance\Tests\Unit;

use Dgtlss\Clearance\Dynamic\PayloadBuilder;
use PHPUnit\Framework\TestCase;

class PayloadBuilderTest extends TestCase
{
    public function test_required_fields_have_deterministic_type_appropriate_values(): void
    {
        $payload = (new PayloadBuilder)->fromRules([
            'name' => 'required|string',
            'email' => ['required', 'email'],
            'count' => 'required|integer',
            'status' => 'required|in:draft,shipped',
            'terms' => 'accepted',
            'address.city' => 'required|string',
            'items.*.name' => 'required|string',
            'password' => 'required|confirmed',
            'optional' => 'nullable|string',
        ]);

        $this->assertSame('clearance@example.test', $payload['email']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('draft', $payload['status']);
        $this->assertTrue($payload['terms']);
        $this->assertSame('clearance', $payload['address']['city']);
        $this->assertSame('clearance', $payload['items'][0]['name']);
        $this->assertSame($payload['password'], $payload['password_confirmation']);
        $this->assertArrayNotHasKey('optional', $payload);
    }

    public function test_common_string_and_numeric_bounds_produce_usable_values(): void
    {
        $payload = (new PayloadBuilder)->fromRules(['code' => 'required|string|min:12|max:15', 'count' => 'required|integer|min:5']);
        $this->assertSame(12, strlen($payload['code']));
        $this->assertSame(5, $payload['count']);
    }
}
