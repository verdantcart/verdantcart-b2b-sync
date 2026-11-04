<?php

namespace App\Http\Controllers;

use App\Erp\ErpException;
use App\Erp\Fortnox\FortnoxClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FortnoxConnectionController extends Controller
{
    /** GET /fortnox/connect — send the admin to Fortnox to approve the integration. */
    public function connect(Request $request, FortnoxClient $fortnox): RedirectResponse|Response
    {
        if (! $fortnox->isConfigured()) {
            return response('Fortnox is not configured. Set FORTNOX_CLIENT_ID and FORTNOX_CLIENT_SECRET in .env, then try again.', 500);
        }

        $state = Str::random(40);
        $request->session()->put('fortnox_oauth_state', $state);

        return redirect()->away($fortnox->authorizeUrl($state));
    }

    /** GET /fortnox/callback?code=…&state=… — Fortnox redirects back here after approval. */
    public function callback(Request $request, FortnoxClient $fortnox): RedirectResponse|Response
    {
        $expected = $request->session()->pull('fortnox_oauth_state');

        if ($request->filled('error')) {
            return response('Fortnox login was cancelled or refused: '.e($request->query('error_description', $request->query('error'))), 400);
        }
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return response('Invalid or expired login attempt (state mismatch). Start again at /fortnox/connect.', 400);
        }
        if (! $request->filled('code')) {
            return response('Fortnox did not return an authorization code.', 400);
        }

        try {
            $fortnox->connect((string) $request->query('code'));
        } catch (ErpException $e) {
            Log::error('Fortnox connect failed', ['error' => $e->getMessage()]);

            return response(e($e->getMessage()), 502);
        }

        return redirect()->route('dashboard')->with('status', 'Fortnox connected.');
    }
}
