<?php
declare(strict_types=1);

namespace ManorLedger\Tests\Http;

use ManorLedger\Auth\ArraySessionDriver;
use ManorLedger\Http\Kernel;
use ManorLedger\Http\Request;
use ManorLedger\Support\Config;
use ManorLedger\Support\FrozenClock;
use ManorLedger\Tests\TempDirTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Two games through Kernel::handle(): the selector, the session memory and what each game may serve. */
final class GameSelectionTest extends TestCase
{
    use TempDirTrait;

    private const ROOT = __DIR__ . '/../..';
    private string $dir;
    private ArraySessionDriver $driver;

    protected function setUp(): void
    {
        $this->dir = $this->makeTempDir();
        $this->driver = new ArraySessionDriver();
        mkdir($this->dir . '/games/second', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function kernel(bool $twoGames = true): Kernel
    {
        $games = [
            'first' => [
                'name' => 'First Game', 'universeId' => 1, 'voices' => true, 'ads' => true,
                'paths' => ['dashboard' => $this->dir . '/dashboard.json', 'voices' => $this->dir . '/voices.json',
                            'voicesMedia' => $this->dir . '/media/yt'],
            ],
            'second' => [
                'name' => 'Second Game', 'universeId' => 2, 'voices' => false, 'ads' => false,
                'paths' => ['dashboard' => $this->dir . '/games/second/dashboard.json'],
            ],
        ];
        $config = new Config([
            'app'   => ['name' => 'Manor Ledger', 'game' => 'First Game', 'defaultGame' => 'first', 'host' => 'ledger.example', 'debug' => false],
            'games' => $twoGames ? $games : ['first' => $games['first']],
            'paths' => ['users' => $this->dir . '/users.json', 'throttle' => $this->dir . '/throttle', 'sessions' => $this->dir . '/sessions',
                        'authLog' => $this->dir . '/auth.log'],
            'auth'  => ['idleTimeout' => 1800, 'absoluteTimeout' => 43200, 'maxFailures' => 5, 'lockoutSeconds' => 900, 'cookieName' => '__Host-manor_session'],
        ]);
        return new Kernel($config, self::ROOT, new FrozenClock(1_700_000_000), $this->driver);
    }

    /** @param array<string, string> $query */
    private function request(string $method, string $path, array $query = [], array $post = []): Request
    {
        $cookies = $this->driver->isStarted() ? ['__Host-manor_session' => $this->driver->id()] : [];
        return new Request($method, $path, ['Host' => 'ledger.example', 'User-Agent' => 'UA', 'Origin' => 'https://ledger.example'],
            $query, $post, $cookies, ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
    }

    private function login(Kernel $kernel): void
    {
        file_put_contents($this->dir . '/users.json', json_encode(['demo' => [
            'username' => 'demo', 'hash' => password_hash('demo-password-123', PASSWORD_BCRYPT, ['cost' => 4]), 'role' => 'owner',
            'totpSecret' => null, 'createdAt' => 'x', 'lastLoginAt' => null,
        ]], JSON_THROW_ON_ERROR));
        $kernel->handle($this->request('GET', '/login'));
        $response = $kernel->handle($this->request('POST', '/login', [], ['_csrf' => (string)$this->driver->get('csrf'), 'username' => 'demo', 'password' => 'demo-password-123']));
        self::assertSame(302, $response->status());
    }

    public function testDefaultGameUntilAnotherIsChosen(): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);
        file_put_contents($this->dir . '/dashboard.json', '{"game":"first"}');

        $home = $kernel->handle($this->request('GET', '/'));
        self::assertSame(200, $home->status());
        self::assertStringContainsString('<title>First Game · Manor Ledger</title>', $home->body());
        self::assertStringContainsString('class="game-switch"', $home->body());
        self::assertStringContainsString('<a href="/?game=first" aria-current="page">First Game</a>', $home->body());
        self::assertStringContainsString('<a href="/?game=second">Second Game</a>', $home->body());
        self::assertStringContainsString('data-view-link="ads"', $home->body());
        self::assertStringContainsString('data-view-link="ai-sentiment"', $home->body());
        self::assertStringNotContainsString('<script>', $home->body(), 'no inline scripts');
        self::assertSame($this->dir . '/dashboard.json', $kernel->handle($this->request('GET', '/api/dashboard'))->filePath());
    }

    public function testChoiceIsRememberedAndScopesEveryEndpoint(): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);
        file_put_contents($this->dir . '/dashboard.json', '{"game":"first"}');
        file_put_contents($this->dir . '/voices.json', '{}');

        $switch = $kernel->handle($this->request('GET', '/', ['game' => 'second']));
        self::assertSame(302, $switch->status());
        self::assertSame('/', $switch->header('Location'), 'the slug does not linger in the URL');

        $home = $kernel->handle($this->request('GET', '/'));
        self::assertStringContainsString('<title>Second Game · Manor Ledger</title>', $home->body());
        self::assertStringContainsString('<a href="/?game=second" aria-current="page">Second Game</a>', $home->body());
        self::assertStringContainsString('data-views-off="ads ai-sentiment"', $home->body());
        self::assertStringNotContainsString('data-view-link="ads"', $home->body());
        self::assertStringNotContainsString('data-view-link="ai-sentiment"', $home->body());

        // Not built yet: the "in costruzione" answer, never the first game's file.
        $missing = $kernel->handle($this->request('GET', '/api/dashboard'));
        self::assertSame(503, $missing->status());
        self::assertNull($missing->filePath());

        file_put_contents($this->dir . '/games/second/dashboard.json', '{"game":"second"}');
        self::assertSame($this->dir . '/games/second/dashboard.json', $kernel->handle($this->request('GET', '/api/dashboard'))->filePath());

        // No AI Sentiment for this game: 404, although the first game has a voices.json.
        $voices = $kernel->handle($this->request('GET', '/api/voices'));
        self::assertSame(404, $voices->status());
        self::assertSame('{"error":"not available for this game"}', $voices->body());
        mkdir($this->dir . '/media/yt', 0755, true);
        file_put_contents($this->dir . '/media/yt/O8eWFVZxgcI.jpg', "\xFF\xD8\xFF\xE0");
        self::assertSame(404, $kernel->handle($this->request('GET', '/media/yt/O8eWFVZxgcI.jpg'))->status());

        // And back.
        $kernel->handle($this->request('GET', '/', ['game' => 'first']));
        self::assertSame($this->dir . '/dashboard.json', $kernel->handle($this->request('GET', '/api/dashboard'))->filePath());
        self::assertSame(200, $kernel->handle($this->request('GET', '/api/voices'))->status());
    }

    /** @return iterable<string, array{string}> */
    public static function foreignSlugs(): iterable
    {
        yield 'traversal' => ['../games/second'];
        yield 'upper case' => ['SECOND'];
        yield 'empty' => [''];
        yield 'unknown' => ['third'];
    }

    #[DataProvider('foreignSlugs')]
    public function testForeignSlugFallsBackToTheDefaultGame(string $slug): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);
        $kernel->handle($this->request('GET', '/', ['game' => 'second']));
        $kernel->handle($this->request('GET', '/', ['game' => $slug]));
        self::assertSame('first', $this->driver->get('game'));
        self::assertStringContainsString('<title>First Game · Manor Ledger</title>', $kernel->handle($this->request('GET', '/'))->body());
    }

    public function testStaleSessionValueIsReadAsTheDefaultGame(): void
    {
        $kernel = $this->kernel();
        $this->login($kernel);
        $this->driver->set('game', '../../etc');
        file_put_contents($this->dir . '/dashboard.json', '{}');
        self::assertSame($this->dir . '/dashboard.json', $kernel->handle($this->request('GET', '/api/dashboard'))->filePath());
    }

    public function testChoosingRequiresASession(): void
    {
        $response = $this->kernel()->handle($this->request('GET', '/', ['game' => 'second']));
        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertFalse($this->driver->isStarted());
    }

    public function testOneGameShowsNoSelector(): void
    {
        $kernel = $this->kernel(false);
        $this->login($kernel);
        $home = $kernel->handle($this->request('GET', '/'));
        self::assertSame(200, $home->status());
        self::assertStringNotContainsString('game-switch', $home->body());
        self::assertStringContainsString('data-views-off=""', $home->body());
    }
}
