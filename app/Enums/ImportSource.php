<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a row entered the portal: manual, csv, xlsx, google_sheet.
 *
 * manual is an applicant added by hand. An import batch is csv, xlsx, or google_sheet.
 */
enum ImportSource: string
{
    case Manual = 'manual';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case GoogleSheet = 'google_sheet';
}
