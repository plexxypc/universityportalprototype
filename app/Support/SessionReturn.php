<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Send an ended session back to the login page.
 *
 * A return path is stored only for a plain GET page. The login page reads
 * `expired=1` and shows one message.
 */
final class SessionReturn
{
    /**
     * Session key read by the login action after a successful sign-in.
     */
    public const string INTENDED = 'url.intended';

    /**
     * Redirect to login, remembering a safe page when this request is one.
     */
    public static function redirect(Request $request): RedirectResponse
    {
        $path = SafeReturnPath::fromRequest($request);

        if ($path !== null) {
            $request->session()->put(self::INTENDED, $path);
        }

        $cookie = (string) config('session.cookie');
        $expired = $cookie !== '' && $request->cookies->has($cookie);

        return redirect()->route('login', $expired ? ['expired' => '1'] : []);
    }
}
