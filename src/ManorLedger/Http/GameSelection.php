<?php
/**
 * Which game this session is looking at.
 *
 * The choice arrives once as `?game=<slug>` and is kept in the session, so
 * every later request (the shell, /api/dashboard, /api/voices) reads the same
 * game without carrying it in the URL. The value is checked against the
 * configured slugs both when it is stored and when it is read back: a slug
 * that is not a key — or one removed from the config since — is the default
 * game, and no slug is ever turned into a path.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

use ManorLedger\Auth\Session;
use ManorLedger\Support\Games;

final class GameSelection
{
    private const SESSION_KEY = 'game';

    public function __construct(private readonly Session $session, private readonly Games $games)
    {
    }

    public function games(): Games
    {
        return $this->games;
    }

    /** The session's game, the default one when none (or an unknown one) is stored. */
    public function current(): string
    {
        return $this->games->resolve($this->session->get(self::SESSION_KEY));
    }

    /** Stores the asked game (or the default for anything else) and returns it. The session must be started. */
    public function select(?string $asked): string
    {
        $slug = $this->games->resolve($asked);
        $this->session->set(self::SESSION_KEY, $slug);
        return $slug;
    }

    /** Path of a per-game file for the session's game, null when the game has none. */
    public function path(string $key): ?string
    {
        return $this->games->path($this->current(), $key);
    }
}
