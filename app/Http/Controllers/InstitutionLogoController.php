<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public institution logo.
 *
 * The route has no path parameter. Pages link here only when a file is
 * stored, and that URL carries a version from the file hash. A missing
 * file redirects with no-store. The version is not used to choose a file.
 */
final class InstitutionLogoController extends Controller
{
    public const string CACHE_CONTROL = 'public, max-age=31536000';

    public const string FALLBACK_CACHE_CONTROL = 'no-store';

    /**
     * Stream the stored logo, or redirect without a long cache when it is missing.
     */
    public function show(Request $request, SettingsService $settings): Response|RedirectResponse
    {
        $stored = $settings->openStoredLogo();

        if ($stored === null) {
            return redirect()
                ->to($settings->fallbackLogoUrl())
                ->header('Cache-Control', self::FALLBACK_CACHE_CONTROL)
                ->header('X-Content-Type-Options', 'nosniff');
        }

        $etag = '"'.$stored['hash'].'"';
        $matches = trim((string) $request->headers->get('If-None-Match', ''));

        if ($matches === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', self::CACHE_CONTROL)
                ->header('X-Content-Type-Options', 'nosniff');
        }

        return response($stored['contents'], 200)
            ->header('Content-Type', $stored['mime'])
            ->header('ETag', $etag)
            ->header('Cache-Control', self::CACHE_CONTROL)
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
