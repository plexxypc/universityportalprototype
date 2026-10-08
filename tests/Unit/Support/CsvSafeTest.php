<?php

declare(strict_types=1);

use App\Support\CsvSafe;

it('neutralises formula prefixes and leaves the same characters in the middle', function () {
    expect(CsvSafe::cell('=1+1'))->toBe("'=1+1")
        ->and(CsvSafe::cell('+123'))->toBe("'+123")
        ->and(CsvSafe::cell('-10'))->toBe("'-10")
        ->and(CsvSafe::cell('@cmd'))->toBe("'@cmd")
        ->and(CsvSafe::cell("\t1"))->toBe("'\t1")
        ->and(CsvSafe::cell("\r1"))->toBe("'\r1")
        ->and(CsvSafe::cell('total=1'))->toBe('total=1')
        ->and(CsvSafe::cell('a+b'))->toBe('a+b')
        ->and(CsvSafe::cell('user@example.com'))->toBe('user@example.com')
        ->and(CsvSafe::cell('room 1-2'))->toBe('room 1-2')
        ->and(CsvSafe::cell(' =1+1'))->toBe(' =1+1');
});

it('handles non-string values', function () {
    expect(CsvSafe::cell(null))->toBe('')
        ->and(CsvSafe::cell(true))->toBe('true')
        ->and(CsvSafe::cell(false))->toBe('false')
        ->and(CsvSafe::cell(0))->toBe('0')
        ->and(CsvSafe::cell(-5))->toBe("'-5")
        ->and(CsvSafe::cell(1.5))->toBe('1.5')
        ->and(CsvSafe::cell(['name' => 'Ada']))->toBe('{"name":"Ada"}')
        ->and(CsvSafe::cell(new stdClass))->toBe('');
});
