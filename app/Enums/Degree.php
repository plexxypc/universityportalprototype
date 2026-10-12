<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Degree types a programme may use.
 *
 * The list is closed. The programmes.degree column stays a string; the
 * service rejects any value that is not one of these labels.
 */
enum Degree: string
{
    case Nd = 'ND';
    case Hnd = 'HND';
    case Bsc = 'BSc';
    case Ba = 'BA';
    case Beng = 'BEng';
    case Bed = 'BEd';
    case Llb = 'LLB';
    case Bnsc = 'BNSc';
    case Mbbs = 'MBBS';
    case Bpharm = 'BPharm';
    case Bagric = 'BAgric';
    case Btech = 'BTech';
    case Mtech = 'MTech';
    case Pgd = 'PGD';
    case Msc = 'MSc';
    case Ma = 'MA';
    case Meng = 'MEng';
    case Phd = 'PhD';

    /**
     * Select options, keyed by the stored label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->value;
        }

        return $options;
    }
}
