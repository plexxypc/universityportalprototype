<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\StudentPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorised download of one import's failed rows.
 *
 * A missing batch and a batch this account does not own both end as 404.
 * The file name is generated. The CSV has no password column.
 */
final class ImportDownloadController extends Controller
{
    /**
     * Send the failed-row CSV for a batch this account imported.
     */
    public function failures(Request $request, int $batch): StreamedResponse|Response
    {
        $actor = $request->user();
        $row = ImportBatch::query()->whereKey($batch)->first();

        if (! $actor instanceof User || ! app(StudentPolicy::class)->create($actor)) {
            abort(404);
        }

        if (! $row instanceof ImportBatch || (int) $row->user_id !== (int) $actor->id || $row->failure_report_path === null) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($row->failure_report_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($row->failure_report_path, 'failed-rows-'.$row->id.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }
}
