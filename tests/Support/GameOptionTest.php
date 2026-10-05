<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Support;

use InvalidArgumentException;
use ManorLedger\Support\GameOption;
use ManorLedger\Support\Games;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** --game / --all as bin/refresh and bin/build read them. */
final class GameOptionTest extends TestCase
{
    private static function games(): Games
    {
        return new Games(['app' => ['defaultGame' => 'locust'], 'games' => [
            'colorblind' => ['name' => 'COLORBLIND', 'universeId' => 2],
            'locust'     => ['name' => 'Locust', 'universeId' => 1],
        ]]);
    }

    public function testNoOptionIsTheDefaultGameAndLeavesTheRestAlone(): void
    {
        $parsed = GameOption::parse(['--if-older-than=8', '--dry-run'], self::games());
        self::assertSame(['locust'], $parsed['games']);
        self::assertSame(['--if-older-than=8', '--dry-run'], $parsed['rest']);
    }

    public function testOneGame(): void
    {
        $parsed = GameOption::parse(['--game=colorblind', '--print'], self::games());
        self::assertSame(['colorblind'], $parsed['games']);
        self::assertSame(['--print'], $parsed['rest']);
    }

    public function testAllRunsTheDefaultFirst(): void
    {
        // Declared second in the map, run first: the default game's refresh
        // must not wait behind a newer game's.
        self::assertSame(['locust', 'colorblind'], GameOption::parse(['--all'], self::games())['games']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badUsage(): iterable
    {
        yield 'unknown slug' => [['--game=nope']];
        yield 'traversal' => [['--game=../locust']];
        yield 'upper case' => [['--game=LOCUST']];
        yield 'empty' => [['--game=']];
        yield 'no value' => [['--game']];
        yield 'all and game' => [['--all', '--game=locust']];
        yield 'two games' => [['--game=locust', '--game=colorblind']];
    }

    /** @param list<string> $args */
    #[DataProvider('badUsage')]
    public function testBadUsageIsRefused(array $args): void
    {
        $this->expectException(InvalidArgumentException::class);
        GameOption::parse($args, self::games());
    }
}
