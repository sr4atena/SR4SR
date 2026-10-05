<?php
/**
 * The `--game=<slug>` / `--all` options shared by bin/refresh and bin/build.
 *
 * It takes the games out of the argument list and hands the rest back, so
 * each script keeps parsing its own options exactly as before. No option
 * means the default game, which is what the scripts did with one game.
 */
declare(strict_types=1);

namespace ManorLedger\Support;

use InvalidArgumentException;

final class GameOption
{
    /**
     * @param list<string> $args argv without the script name
     * @return array{games: list<string>, rest: list<string>} games in run order (the default first with --all)
     * @throws InvalidArgumentException on an unknown slug or contradictory options (exit 2 in the scripts)
     */
    public static function parse(array $args, Games $games): array
    {
        $chosen = null;
        $all = false;
        $rest = [];
        foreach ($args as $arg) {
            if ($arg === '--all') {
                $all = true;
            } elseif (str_starts_with($arg, '--game=')) {
                $slug = substr($arg, strlen('--game='));
                if (!$games->has($slug)) {
                    throw new InvalidArgumentException(sprintf(
                        'Unknown game "%s" (configured: %s)', $slug, implode(', ', $games->slugs())));
                }
                if ($chosen !== null && $chosen !== $slug) {
                    throw new InvalidArgumentException('--game given twice with different values');
                }
                $chosen = $slug;
            } elseif ($arg === '--game') {
                throw new InvalidArgumentException('--game needs a value: --game=<slug>');
            } else {
                $rest[] = $arg;
            }
        }
        if ($all && $chosen !== null) {
            throw new InvalidArgumentException('--all and --game are mutually exclusive');
        }
        return [
            'games' => $all ? $games->slugs() : [$chosen ?? $games->default()],
            'rest'  => $rest,
        ];
    }
}
