<?php
/**
 * The one HTML page behind the session: a shell that carries no figures.
 *
 * Everything the view shows arrives later from /api/dashboard, so the markup
 * holds no data to leak into a cache or a referrer and the page is identical
 * for every user. It falls back to a placeholder template so a deploy without
 * a built frontend still answers.
 *
 * It is also where the game is chosen: `/?game=<slug>` stores the choice in
 * the session (GameSelection checks it against the configured slugs) and
 * redirects to the bare `/`, so the slug never lingers in a URL or a history
 * entry. The shell then names the game and leaves out the views it lacks.
 */
declare(strict_types=1);

namespace ManorLedger\Http\Controllers;

use ManorLedger\Auth\Session;
use ManorLedger\Http\GameSelection;
use ManorLedger\Http\Request;
use ManorLedger\Http\Response;
use ManorLedger\Http\View;

final class DashboardController
{
    public function __construct(
        private readonly Session $session,
        private readonly View $view,
        private readonly string $appName,
        private readonly GameSelection $selection,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->session->resume($request) ? $this->session->user() : null;
        if ($user === null) {
            return Response::redirect('/login');
        }
        if ($request->query('game') !== null) {
            $this->selection->select($request->query('game'));
            return Response::redirect('/');
        }
        $games = $this->selection->games();
        $current = $this->selection->current();
        $switch = [];
        // One game needs no selector: the template shows it only with two or more.
        foreach ($games->slugs() as $slug) {
            $switch[$slug] = $games->name($slug);
        }
        $vars = [
            'user'        => $user,
            'csrfToken'   => $this->session->csrfToken(),
            'appName'     => $this->appName,
            'gameName'    => $games->name($current),
            'gameSlug'    => $current,
            'games'       => $switch,
            // Hash names of the views this game lacks (router.js shows a notice instead).
            'viewsOff'    => array_keys(array_filter([
                'ads'          => !$games->enabled($current, 'ads'),
                'ai-sentiment' => !$games->enabled($current, 'voices'),
            ])),
        ];
        if (!$this->view->exists('dashboard')) {
            $html = $this->view->page('placeholder', $vars, $this->appName);
            return Response::html($html);
        }
        $html = $this->view->render('dashboard', $vars);
        // The dashboard template may be a full document or a fragment; wrap only the latter.
        if (stripos(ltrim($html), '<!doctype') !== 0 && stripos(ltrim($html), '<html') !== 0) {
            $html = $this->view->render('layout', ['title' => $this->appName, 'content' => $html, 'styles' => []]);
        }
        return Response::html($html);
    }
}
