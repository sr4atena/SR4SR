<?php
/**
 * The games this installation follows, keyed by slug (config/app.php `games`).
 *
 * The rest of the code base was written for one game and reads `app.game`,
 * `app.universeId`, `economics.royaltyShare` and `paths.*` from the config
 * array. Rather than teach every class about games, configFor() returns that
 * same array with the selected game's values laid over it, so a refresh or a
 * build for any game runs through unchanged code.
 *
 * A slug arriving from a request or a command line is only ever compared with
 * the configured keys (strict, case-sensitive); it is never used to build a
 * path. Anything that is not a key falls back to the default game.
 */
declare(strict_types=1);

namespace ManorLedger\Support;

use InvalidArgumentException;

final class Games
{
    /** Shape a slug must have to be configured at all: it ends up in file names and URLs. */
    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,31}\z/';
    /** The per-game paths: everything else in `paths` is shared by every game. */
    private const GAME_PATHS = ['data', 'cache', 'snapshots', 'history', 'dashboard', 'apiKey', 'ads', 'voices', 'voicesMedia'];

    /** @var array<string, array<string, mixed>> slug => game entry */
    private readonly array $games;
    private readonly string $default;

    /** @param array<string, mixed> $config the array returned by config/app.php */
    public function __construct(private readonly array $config)
    {
        $games = $config['games'] ?? null;
        $source = 'games.%s.paths.%s';
        if (!is_array($games) || $games === []) {
            // A config without the map (older files, tests) is one game made of
            // the top-level values, exactly as before the map existed.
            $games = ['default' => [
                'name'         => (string)($config['app']['game'] ?? ''),
                'universeId'   => (int)($config['app']['universeId'] ?? 0),
                'royaltyShare' => $config['economics']['royaltyShare'] ?? null,
                'voices'       => true,
                'ads'          => true,
                'paths'        => array_intersect_key($config['paths'] ?? [], array_flip(self::GAME_PATHS)),
            ]];
            $source = 'paths.%2$s';
        }
        foreach ($games as $slug => $game) {
            if (!is_string($slug) || preg_match(self::SLUG, $slug) !== 1 || !is_array($game)) {
                throw new InvalidArgumentException('Invalid game slug in config: ' . var_export($slug, true));
            }
            // Checked here, at boot, and named after the key that holds it: a
            // mistyped path is a crash on the first request, not on the first
            // visit to the game that has it.
            foreach ($game['paths'] ?? [] as $key => $path) {
                if (!is_string($path) || $path === '') {
                    throw new InvalidArgumentException(sprintf('Config key ' . $source . ' is not a string', $slug, $key));
                }
            }
        }
        $default = (string)($config['app']['defaultGame'] ?? array_key_first($games));
        if (!array_key_exists($default, $games)) {
            throw new InvalidArgumentException("Default game {$default} is not configured");
        }
        $this->games = $games;
        $this->default = $default;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->all());
    }

    /** @return list<string> every configured slug, the default first (the order --all runs them in) */
    public function slugs(): array
    {
        $slugs = array_keys($this->games);
        return array_values(array_unique([$this->default, ...$slugs]));
    }

    public function default(): string
    {
        return $this->default;
    }

    public function count(): int
    {
        return count($this->games);
    }

    /** Strict whitelist check: only an exact configured key is a game. */
    public function has(mixed $slug): bool
    {
        return is_string($slug) && array_key_exists($slug, $this->games);
    }

    /** The slug itself when configured, the default game otherwise. */
    public function resolve(mixed $slug): string
    {
        return $this->has($slug) ? $slug : $this->default;
    }

    public function name(string $slug): string
    {
        return (string)($this->entry($slug)['name'] ?? $slug);
    }

    /** Whether the game has the given optional view ('voices' or 'ads'). */
    public function enabled(string $slug, string $feature): bool
    {
        return (bool)($this->entry($slug)[$feature] ?? false);
    }

    /** One per-game path, null when the game does not have it (a disabled feature). */
    public function path(string $slug, string $key): ?string
    {
        return $this->paths($slug)[$key] ?? null;
    }

    /**
     * The game's own paths, every per-game key present: a game that does not
     * declare one, or has the feature off, gets null and never the default
     * game's file.
     *
     * @return array<string, ?string>
     */
    public function paths(string $slug): array
    {
        $declared = $this->entry($slug)['paths'] ?? [];
        $paths = [];
        foreach (self::GAME_PATHS as $key) {
            $paths[$key] = isset($declared[$key]) ? (string)$declared[$key] : null;
        }
        if (!$this->enabled($slug, 'ads')) {
            $paths['ads'] = null;
        }
        if (!$this->enabled($slug, 'voices')) {
            $paths['voices'] = $paths['voicesMedia'] = null;
        }
        return $paths;
    }

    /**
     * The whole config array as the single-game code expects it, for this game.
     *
     * @return array<string, mixed>
     */
    public function configFor(string $slug): array
    {
        $game = $this->entry($slug);
        $universeId = (int)($game['universeId'] ?? 0);
        if ($universeId <= 0) {
            throw new InvalidArgumentException("Game {$slug} has no universeId");
        }
        $config = $this->config;
        $config['app']['slug'] = $slug;
        $config['app']['game'] = (string)($game['name'] ?? $slug);
        $config['app']['universeId'] = $universeId;
        if (isset($game['royaltyShare'])) {
            $config['economics']['royaltyShare'] = (float)$game['royaltyShare'];
        }
        $config['paths'] = $this->paths($slug) + ($config['paths'] ?? []);
        return $config;
    }

    /** @return array<string, mixed> */
    private function entry(string $slug): array
    {
        if (!$this->has($slug)) {
            throw new InvalidArgumentException("Unknown game: {$slug}");
        }
        return $this->games[$slug];
    }
}
