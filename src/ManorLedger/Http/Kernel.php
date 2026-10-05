<?php
/**
 * Composition root for the web side: builds the object graph from config,
 * routes the request and turns every outcome (including crashes) into a
 * Response that carries the security headers.
 */
declare(strict_types=1);

namespace ManorLedger\Http;

use ManorLedger\Auth\AuditLog;
use ManorLedger\Auth\Authenticator;
use ManorLedger\Auth\Csrf;
use ManorLedger\Auth\LoginThrottle;
use ManorLedger\Auth\NativeSessionDriver;
use ManorLedger\Auth\PasswordHasher;
use ManorLedger\Auth\Session;
use ManorLedger\Auth\SessionDriver;
use ManorLedger\Auth\Totp;
use ManorLedger\Auth\UserStore;
use ManorLedger\Http\Controllers\ApiController;
use ManorLedger\Http\Controllers\DashboardController;
use ManorLedger\Http\Controllers\LoginController;
use ManorLedger\Http\Controllers\VoicesController;
use ManorLedger\Support\Clock;
use ManorLedger\Support\Config;
use ManorLedger\Support\Games;
use ManorLedger\Support\SystemClock;
use Throwable;

final class Kernel
{
    private const STATUS_TEXT = [
        403 => 'Accesso negato', 404 => 'Pagina non trovata', 405 => 'Metodo non consentito',
        500 => 'Errore interno', 503 => 'Servizio non disponibile',
    ];

    private readonly Clock $clock;
    private readonly SessionDriver $sessionDriver;

    public function __construct(
        private readonly Config $config,
        private readonly string $root,
        ?Clock $clock = null,
        ?SessionDriver $sessionDriver = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->sessionDriver = $sessionDriver ?? new NativeSessionDriver();
        require_once $root . '/src/ManorLedger/Support/functions.php';
    }

    public static function boot(string $root): self
    {
        return new self(Config::load($root . '/config/app.php'), $root);
    }

    public function run(): void
    {
        // A PHP warning (e.g. an unwritable data dir) must surface as a generic 500, never as a partial page.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false; // leave deprecations to the standard logger
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->handle(Request::fromGlobals())->send();
        } finally {
            restore_error_handler();
        }
    }

    public function handle(Request $request): Response
    {
        $headers = new SecurityHeaders($request->isHttps());
        $view = new View($this->root . '/templates', View::assetVersionFor($this->root . '/public'));
        try {
            $response = $this->router($request, $view)->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->errorResponse($request, $view, $e->status(), $e->getMessage(), null);
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (Throwable $e) {
            error_log(sprintf('[manor] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $details = $this->config->bool('app.debug') ? $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() : null;
            $response = $this->errorResponse($request, $view, 500, 'Si è verificato un errore.', $details);
        }
        return $headers->apply($response);
    }

    private function router(Request $request, View $view): Router
    {
        $appName = $this->config->string('app.name');
        $gameName = $this->config->string('app.game');

        $session = new Session(
            $this->sessionDriver,
            $this->clock,
            $this->config->string('paths.sessions'),
            $this->config->int('auth.idleTimeout'),
            $this->config->int('auth.absoluteTimeout'),
            $this->config->string('auth.cookieName'),
        );
        $csrf = new Csrf($session, $this->config->string('app.host'));
        $users = new UserStore($this->config->string('paths.users'), $this->clock);
        $throttle = new LoginThrottle(
            $this->config->string('paths.throttle'),
            $this->clock,
            $this->config->int('auth.maxFailures'),
            $this->config->int('auth.lockoutSeconds'),
        );
        $audit = new AuditLog($this->config->string('paths.authLog'), $this->clock);
        $auth = new Authenticator($users, new PasswordHasher(), $throttle, $session, $audit, new Totp($this->clock), $this->clock);

        // Built before any route runs, so a mistyped game path fails every request alike.
        $selection = new GameSelection($session, Games::fromConfig($this->config));

        $login = new LoginController($auth, $session, $csrf, $view, $appName, $gameName);
        $dashboard = new DashboardController($session, $view, $appName, $selection);
        $api = new ApiController($session, $selection);
        $voices = new VoicesController($session, $selection);

        $router = new Router();
        $router->get('/', $dashboard->index(...));
        $router->get('/login', $login->show(...));
        $router->post('/login', $login->submit(...));
        $router->post('/logout', $login->logout(...));
        $router->get('/api/dashboard', $api->dashboard(...));
        $router->get('/api/voices', $voices->index(...));
        // One variable path segment in the whole application: the controller
        // declares the only /media/yt/ path this request may legitimately have.
        $router->get(VoicesController::mediaRouteFor($request->path()), $voices->media(...));
        $router->get('/healthz', static fn (Request $r): Response => Response::text('ok')->withHeader('Cache-Control', 'no-store'));
        return $router;
    }

    private function errorResponse(Request $request, View $view, int $status, string $message, ?string $details): Response
    {
        if (str_starts_with($request->path(), '/api/')) {
            return Response::json(['error' => strtolower(self::STATUS_TEXT[$status] ?? 'error')], $status);
        }
        $html = $view->page('error', [
            'status'  => $status,
            'title'   => self::STATUS_TEXT[$status] ?? 'Errore',
            'message' => $message,
            'details' => $details,
            'appName' => $this->config->string('app.name'),
        ], $this->config->string('app.name') . ' · ' . $status, ['/assets/css/login.css']);
        return Response::html($html, $status);
    }
}
