<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Support;

use InvalidArgumentException;
use ManorLedger\Support\Config;
use ManorLedger\Support\Games;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The games map: slug whitelist, per-game paths and the default game left exactly as it was. */
final class GamesTest extends TestCase
{
    private const DATA = '/srv/ledger';

    private static function realConfig(): array
    {
        putenv('MANOR_DATA_DIR=' . self::DATA);
        try {
            return Config::load(__DIR__ . '/../../config/app.php')->all();
        } finally {
            putenv('MANOR_DATA_DIR');
        }
    }

    public function testDefaultGameKeepsTheOriginalLayout(): void
    {
        $config = self::realConfig();
        $games = new Games($config);
        self::assertSame('locust', $games->default());
        self::assertSame(['locust', 'colorblind'], $games->slugs());

        $locust = $games->configFor('locust');
        // Byte for byte the single-game values: production data never moves.
        foreach (['data', 'cache', 'snapshots', 'history', 'dashboard', 'ads', 'apiKey', 'voices', 'voicesMedia'] as $key) {
            self::assertSame($config['paths'][$key], $locust['paths'][$key], $key);
        }
        self::assertSame(self::DATA . '/dashboard.json', $locust['paths']['dashboard']);
        self::assertSame(self::DATA . '/api-key', $locust['paths']['apiKey']);
        self::assertSame("The Locust's Manor", $locust['app']['game']);
        self::assertSame(10674300622, $locust['app']['universeId']);
        self::assertSame(0.17, $locust['economics']['royaltyShare']);
        // The aliases kept for the single-game code are the default game's.
        self::assertSame($config['app']['game'], $locust['app']['game']);
        self::assertSame($config['app']['universeId'], $locust['app']['universeId']);
        self::assertSame($config['economics']['royaltyShare'], $locust['economics']['royaltyShare']);
        self::assertTrue($games->enabled('locust', 'voices'));
        self::assertTrue($games->enabled('locust', 'ads'));
    }

    public function testColorblindLivesUnderItsOwnDirectory(): void
    {
        $games = new Games(self::realConfig());
        $cb = $games->configFor('colorblind');
        $dir = self::DATA . '/games/colorblind';
        self::assertSame('COLORBLIND', $cb['app']['game']);
        self::assertSame(10766214469, $cb['app']['universeId']);
        self::assertSame(0.0, $cb['economics']['royaltyShare']);
        self::assertSame($dir, $cb['paths']['data']);
        self::assertSame($dir . '/cache', $cb['paths']['cache']);
        self::assertSame($dir . '/snapshots', $cb['paths']['snapshots']);
        self::assertSame($dir . '/history.json', $cb['paths']['history']);
        self::assertSame($dir . '/dashboard.json', $cb['paths']['dashboard']);
        self::assertSame($dir . '/api-key', $cb['paths']['apiKey']);
        // Disabled views: no path at all, never the default game's file.
        self::assertNull($cb['paths']['ads']);
        self::assertNull($cb['paths']['voices']);
        self::assertNull($cb['paths']['voicesMedia']);
        self::assertFalse($games->enabled('colorblind', 'voices'));
        self::assertFalse($games->enabled('colorblind', 'ads'));
        // Shared paths stay shared.
        self::assertSame(self::realConfig()['paths']['metrics'], $cb['paths']['metrics']);
        self::assertSame(self::realConfig()['paths']['users'], $cb['paths']['users']);
    }

    public function testKeyFileOfAnotherGameComesFromItsOwnVariable(): void
    {
        putenv('MANOR_API_KEY_FILE=/etc/ledger/api-key');
        putenv('MANOR_API_KEY_FILE_COLORBLIND=/etc/ledger/api-key-colorblind');
        try {
            $games = new Games(Config::load(__DIR__ . '/../../config/app.php')->all());
        } finally {
            putenv('MANOR_API_KEY_FILE');
            putenv('MANOR_API_KEY_FILE_COLORBLIND');
        }
        self::assertSame('/etc/ledger/api-key', $games->path('locust', 'apiKey'));
        self::assertSame('/etc/ledger/api-key-colorblind', $games->path('colorblind', 'apiKey'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function foreignSlugs(): iterable
    {
        yield 'traversal' => ['../x'];
        yield 'upper case' => ['LOCUST'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['colorblind']];
        yield 'padded' => [' colorblind'];
        yield 'nul byte' => ["colorblind\0"];
        yield 'integer' => [0];
    }

    #[DataProvider('foreignSlugs')]
    public function testAnythingButAConfiguredKeyIsTheDefaultGame(mixed $slug): void
    {
        $games = new Games(self::realConfig());
        self::assertFalse($games->has($slug));
        self::assertSame('locust', $games->resolve($slug));
    }

    public function testConfiguredSlugsResolveToThemselves(): void
    {
        $games = new Games(self::realConfig());
        self::assertSame('colorblind', $games->resolve('colorblind'));
        self::assertSame('locust', $games->resolve('locust'));
        $this->expectException(InvalidArgumentException::class);
        $games->configFor('../x');
    }

    public function testConfigWithoutTheMapIsOneGameOfTheTopLevelValues(): void
    {
        $games = new Games([
            'app'       => ['game' => 'Solo', 'universeId' => 7],
            'economics' => ['royaltyShare' => 0.1],
            'paths'     => ['dashboard' => '/d/dashboard.json', 'voices' => '/d/voices.json', 'users' => '/d/users.json'],
        ]);
        self::assertSame(1, $games->count());
        $only = $games->default();
        self::assertSame('Solo', $games->name($only));
        self::assertSame('/d/dashboard.json', $games->path($only, 'dashboard'));
        self::assertSame('/d/voices.json', $games->path($only, 'voices'));
        self::assertSame(7, $games->configFor($only)['app']['universeId']);
        self::assertSame('/d/users.json', $games->configFor($only)['paths']['users']);
    }

    public function testMistypedPathFailsAtConstructionNamingItsKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Config key games.second.paths.dashboard is not a string');
        new Games(['app' => ['defaultGame' => 'first'], 'games' => [
            'first'  => ['name' => 'A', 'universeId' => 1, 'paths' => ['dashboard' => '/a.json']],
            'second' => ['name' => 'B', 'universeId' => 2, 'paths' => ['dashboard' => 12345]],
        ]]);
    }

    public function testSlugsThatCouldNotBeFileNamesAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Games(['games' => ['../evil' => ['name' => 'x', 'universeId' => 1]]]);
    }

    public function testGameWithoutUniverseCannotBeRefreshed(): void
    {
        $games = new Games(['games' => ['nouniverse' => ['name' => 'x', 'paths' => []]]]);
        $this->expectException(InvalidArgumentException::class);
        $games->configFor('nouniverse');
    }
}
