<?php
/**
 * Read-only endpoints for the Voci view: the computed document and the
 * thumbnails that go with it.
 *
 * Both are behind the session, and the thumbnails deliberately so: they are
 * part of an authenticated page, so they carry `Cache-Control: private` and
 * Cloudflare must never keep a copy. The video id is the only variable path
 * segment in the whole application; it is matched against a strict pattern and
 * never concatenated into a path before that check passes.
 *
 * The view belongs to the session's game: a game without it (no `voices` in
 * its config entry) answers 404 on both endpoints, never with another game's.
 */
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Session;
use ManorLedger\Http\GameSelection;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;

final class VoicesController
{
    public const MEDIA_PREFIX = '/media/yt/';
    private const MEDIA_ROUTE = '#^/media/yt/([A-Za-z0-9_-]{11})\.jpg\z#';

    public function __construct(private readonly Session $session, private readonly GameSelection $games)
    {
    }

    public function index(Request $request): Response
    {
        if (!$this->authenticated($request)) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $file = $this->games->path('voices');
        if ($file === null) {
            return Response::json(['error' => 'not available for this game'], 404);
        }
        if (!is_file($file)) {
            return Response::json(['error' => 'voices not built yet'], 503)->withHeader('Retry-After', '3600');
        }

        return Response::file($file, 'application/json; charset=UTF-8', $request)
            ->withHeader('Cache-Control', 'no-store');
    }

    public function media(Request $request): Response
    {
        // The shape of the path is public knowledge, so it is checked first:
        // a request that is not a thumbnail request is a 404, session or not.
        if (preg_match(self::MEDIA_ROUTE, $request->path(), $matches) !== 1) {
            return Response::json(['error' => 'not found'], 404);
        }
        if (!$this->authenticated($request)) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $mediaDir = $this->games->path('voicesMedia');
        if ($mediaDir === null) {
            return Response::json(['error' => 'not found'], 404);
        }
        $path = $mediaDir . '/' . $matches[1] . '.jpg';
        if (!is_file($path)) {
            return Response::json(['error' => 'not found'], 404);
        }

        return Response::file($path, 'image/jpeg', $request)
            ->withHeader('Cache-Control', 'private, max-age=86400');
    }

    /**
     * The router matches exact paths and the id varies, so the controller
     * declares the single path this request could legitimately take. Anything
     * else never reaches a handler: the router answers 404 on its own.
     */
    public static function mediaRouteFor(string $path): string
    {
        return preg_match(self::MEDIA_ROUTE, $path) === 1 ? $path : rtrim(self::MEDIA_PREFIX, '/');
    }

    private function authenticated(Request $request): bool
    {
        return $this->session->resume($request) && $this->session->user() !== null;
    }
}
