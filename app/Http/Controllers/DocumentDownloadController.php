<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorised download of one private document.
 *
 * A missing id and a document this account cannot see both end as 404.
 * The filename is generated. The content type is the sniffed type.
 */
final class DocumentDownloadController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Send the file as an attachment.
     */
    public function __invoke(Request $request, int $document): StreamedResponse|Response
    {
        $actor = $request->user();
        $row = Document::query()->whereKey($document)->first();

        if (! $actor instanceof User || ! $row instanceof Document) {
            abort(404);
        }

        if (! app(DocumentPolicy::class)->view($actor, $row)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($row->path)) {
            return response(DocumentService::MESSAGE_FILE_GONE, 404);
        }

        $name = $this->documents->downloadName($row);

        return Storage::disk('local')->download($row->path, $name, [
            'Content-Type' => $row->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
