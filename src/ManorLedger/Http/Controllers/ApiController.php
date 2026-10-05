<?php
/**
 * The dashboard document, and nothing else.
 *
 * The browser never queries Roblox and this endpoint never computes: it
 * streams the file bin/build produced, so a page load costs one authorised
 * read and a slow or rate-limited API can only ever make the data old, not the
 * site unavailable. A missing file is a 503 with Retry-After, not an empty
 * dashboard. The file is the session's game's (GameSelection): a game that has
 * not been built yet answers like a first deploy, never with another game's.
 */
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Session;
use ManorLedger\Http\GameSelection;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;

final class ApiController
{
    public function __construct(private readonly Session $session, private readonly GameSelection $games)
    {
    }

    public function dashboard(Request $request): Response
    {
        if (!$this->session->resume($request) || $this->session->user() === null) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $file = $this->games->path('dashboard');
        if ($file === null || !is_file($file)) {
            return Response::json(['error' => 'dashboard not built yet'], 503)
                ->withHeader('Retry-After', '300');
        }
        return Response::file($file, 'application/json; charset=UTF-8', $request)
            ->withHeader('Cache-Control', 'no-store');
    }
}
