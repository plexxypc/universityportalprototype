<?php

declare(strict_types=1);

use App\Support\Import\ColumnMap;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('matches portal names and google form headings', function () {
    $headers = [
        'First Name',
        'Last Name',
        'Other names',
        'E-mail',
        'Phone Number',
        'Gender',
        'Date of birth',
        'State of origin',
        'Address',
        'Programme',
        'Level',
        'Entry Session',
        'Matric Number',
        'Guardian name',
        'Guardian phone',
        'Guardian email',
        'Relationship',
    ];

    expect(ColumnMap::suggest($headers))->toBe([
        'first_name' => 0,
        'last_name' => 1,
        'other_names' => 2,
        'email' => 3,
        'phone' => 4,
        'gender' => 5,
        'date_of_birth' => 6,
        'state_of_origin' => 7,
        'address' => 8,
        'programme_code' => 9,
        'level' => 10,
        'entry_session' => 11,
        'matric_number' => 12,
        'guardian_name' => 13,
        'guardian_phone' => 14,
        'guardian_email' => 15,
        'guardian_relationship' => 16,
    ]);
});

it('uses the first matching column and ignores an unknown header', function () {
    expect(ColumnMap::suggest(['Notes', 'first_name', 'first_name']))->toBe([
        'first_name' => 1,
    ]);
});

it('rejects a map that misses a required field or reuses a column', function () {
    $headers = ['first_name', 'last_name', 'email', 'programme_code', 'level'];

    expect(fn () => ColumnMap::confirmed($headers, [
        'first_name' => 0,
        'last_name' => 1,
        'programme_code' => 3,
        'level' => 4,
    ]))->toThrow(ValidationException::class, ColumnMap::MESSAGE_REQUIRED);

    expect(fn () => ColumnMap::confirmed($headers, [
        'first_name' => 0,
        'last_name' => 0,
        'email' => '2',
        'programme_code' => 3,
        'level' => 4,
    ]))->toThrow(ValidationException::class, ColumnMap::MESSAGE_DUPLICATE);

    expect(fn () => ColumnMap::confirmed($headers, [
        'first_name' => 0,
        'last_name' => 1,
        'email' => 9,
        'programme_code' => 3,
        'level' => 4,
    ]))->toThrow(ValidationException::class, ColumnMap::MESSAGE_COLUMN);

    expect(fn () => ColumnMap::confirmed($headers, [
        'rows' => [['Ada']],
    ]))->toThrow(ValidationException::class, ColumnMap::MESSAGE_FIELD);
});
