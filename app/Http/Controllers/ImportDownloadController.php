<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Import\ImportTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorised import downloads.
 *
 * A missing batch and a batch this account does not own both end as 404.
 * The failed-row file name is generated. Templates are built on request.
 */
final class ImportDownloadController extends Controller
{
    public function __construct(private readonly ImportTemplate $templates) {}

    /**
     * Send the failed-row CSV for a batch this account imported.
     */
    public function failures(Request $request, int $batch): StreamedResponse|Response
    {
        $row = $this->ownedBatch($request, $batch);

        if (! $row instanceof ImportBatch || $row->failure_report_path === null) {
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

    /**
     * CSV template with the student import columns.
     */
    public function templateCsv(Request $request): Response
    {
        $this->authorise($request);

        return response($this->templates->csv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="students-import-template.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Excel template with the student import columns.
     */
    public function templateXlsx(Request $request): Response
    {
        $this->authorise($request);

        return response($this->templates->xlsx(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="students-import-template.xlsx"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The batch when this account may import and owns the row.
     */
    private function ownedBatch(Request $request, int $batch): ?ImportBatch
    {
        $actor = $this->authorise($request);
        $row = ImportBatch::query()->whereKey($batch)->first();

        if (! $row instanceof ImportBatch || (int) $row->user_id !== (int) $actor->id) {
            return null;
        }

        return $row;
    }

    /**
     * The signed-in account that may manage student records.
     */
    private function authorise(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User || ! app(StudentPolicy::class)->create($actor)) {
            abort(404);
        }

        return $actor;
    }
}
