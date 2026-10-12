<?php

declare(strict_types=1);

use App\Support\MatricPattern;

it('describes an unpadded sequence and a four digit sequence', function () {
    expect(MatricPattern::formatSequence(1, '{DEPT}/{YEAR}/{SEQ}'))->toBe('1')
        ->and(MatricPattern::formatSequence(1, '{YEAR}/{SEQ4}'))->toBe('0001')
        ->and(MatricPattern::formatSequence(10000, '{YEAR}/{SEQ4}'))->toBe('10000')
        ->and(MatricPattern::help())->toContain('{SEQ}')
        ->and(MatricPattern::help())->toContain('0001')
        ->and(MatricPattern::notice())->toContain('does not change matric numbers');
});

it('rejects a pattern without a sequence token or a department or year token', function () {
    expect(MatricPattern::error('{DEPT}/{YEAR}'))->not->toBeNull()
        ->and(MatricPattern::error('{SEQ4}'))->not->toBeNull()
        ->and(MatricPattern::error('{SEQ}/{SEQ4}'))->not->toBeNull()
        ->and(MatricPattern::error('{DEPT}/{YEAR}/{CLASS}'))->not->toBeNull()
        ->and(MatricPattern::error('{DEPT}/{YEAR}/{SEQ4}'))->toBeNull()
        ->and(MatricPattern::error('{DEPT}/{YEAR}/{SEQ}'))->toBeNull();
});

it('names the counter parts the matric pattern uses', function () {
    expect(MatricPattern::counterParts('{DEPT}/{YEAR}/{SEQ4}'))->toBe([
        'department' => true,
        'year' => true,
        'padded' => true,
    ])->and(MatricPattern::counterParts('{YEAR}/{SEQ}'))->toBe([
        'department' => false,
        'year' => true,
        'padded' => false,
    ]);
});
