<?php
/**
 * Dashboard shell. The data is fetched by the browser from /api/dashboard;
 * this template only renders the chrome. No inline scripts or styles: the
 * CSP is script-src 'self'; style-src 'self'.
 *
 * @var array{username: string, role: string} $user
 * @var string $csrfToken
 * @var string $appName
 * @var string $gameName
 * @var string $gameSlug
 * @var array<string, string> $games  slug => name, every configured game
 * @var list<string> $viewsOff        views the selected game lacks
 * @var string $assetVersion
 */
declare(strict_types=1);

/** @var callable(string):string $asset */
$initial = mb_strtoupper(mb_substr($user['username'], 0, 1));
$games = $games ?? [];
$viewsOff = $viewsOff ?? [];
$on = static fn (string $view): bool => !in_array($view, $viewsOff, true);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="referrer" content="same-origin">
<title><?= e($gameName) ?> · <?= e($appName) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= e($asset('img/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e($asset('css/app.css')) ?>">
</head>
<body data-game="<?= e($gameName) ?>" data-views-off="<?= e(implode(' ', $viewsOff)) ?>">
<a class="skip" href="#main">Vai al contenuto</a>

<header class="topbar">
  <a class="brand" href="#valore">
    <svg class="brand-mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
      <rect width="64" height="64" rx="14" fill="#121826"/>
      <path d="M12 34 L32 14 L52 34" fill="none" stroke="#e6e9ef" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
      <path d="M18 32 V50 H46 V32" fill="none" stroke="#e6e9ef" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" opacity="0.55"/>
      <path d="M14 46 L24 40 L31 43 L40 34 L50 30" fill="none" stroke="#f5b942" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="50" cy="30" r="3.5" fill="#f5b942"/>
    </svg>
    <span class="brand-text">
      <span class="brand-name"><?= e($appName) ?></span>
      <span class="brand-sub"><b><?= e($gameName) ?></b> &middot; analisi economica e diagnostica</span>
    </span>
  </a>
  <div class="topbar-right">
<?php if (count($games) > 1): ?>
    <nav class="game-switch" aria-label="Gioco">
<?php foreach ($games as $slug => $name): ?>
      <a href="/?game=<?= e(rawurlencode($slug)) ?>"<?= $slug === $gameSlug ? ' aria-current="page"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?>
    </nav>
<?php endif ?>
    <span id="freshness" class="chip-fresh" aria-live="polite">dati in caricamento</span>
    <button id="glossary-button" class="btn-ghost" type="button" aria-controls="glossary" aria-haspopup="dialog">Glossario</button>
    <div class="user">
      <button id="user-menu-button" class="user-button" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="user-menu">
        <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
        <span class="user-name"><?= e($user['username']) ?><span class="user-role"><?= e($user['role']) ?></span></span>
      </button>
      <div id="user-menu" class="menu">
        <form method="post" action="/logout">
          <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
          <button class="menu-item" type="submit">Esci</button>
        </form>
      </div>
    </div>
  </div>
</header>

<div class="toolbar">
  <nav class="tabs" aria-label="Viste" role="tablist">
    <a class="tab" role="tab" href="#valore" data-view-link="valore" aria-selected="true">Valore</a>
    <a class="tab" role="tab" href="#crescita" data-view-link="crescita" aria-selected="false">Crescita</a>
    <a class="tab" role="tab" href="#monetizzazione" data-view-link="monetizzazione" aria-selected="false"><span class="tab-long">Monetizzazione</span><span class="tab-short">Monetiz.</span></a>
    <a class="tab" role="tab" href="#salute" data-view-link="salute" aria-selected="false">Salute</a>
<?php if ($on('ads')): ?>
    <a class="tab" role="tab" href="#ads" data-view-link="ads" aria-selected="false"><span class="tab-long">Analisi Ads</span><span class="tab-short">Ads</span></a>
<?php endif ?>
<?php if ($on('ai-sentiment')): ?>
    <a class="tab" role="tab" href="#ai-sentiment" data-view-link="ai-sentiment" aria-selected="false"><span class="tab-long">AI Sentiment</span><span class="tab-short">Sentiment</span></a>
<?php endif ?>
  </nav>
  <div class="segmented" role="group" aria-label="Periodo dei grafici">
    <button type="button" data-period="7g" aria-pressed="false">7g</button>
    <button type="button" data-period="30g" aria-pressed="true">30g</button>
    <button type="button" data-period="90g" aria-pressed="false">90g</button>
    <button type="button" data-period="tutto" aria-pressed="false">tutto</button>
  </div>
</div>

<main id="main" class="main" tabindex="-1"></main>

<footer class="foot">
  <span><?= e($appName) ?> &middot; <?= e($gameName) ?></span>
  <span>Fonte: Roblox Open Cloud Analytics, aggiornamento giornaliero alle 07:00 (Europe/Rome).</span>
</footer>

<div id="glossary-backdrop" class="backdrop"></div>
<aside id="glossary" class="drawer" role="dialog" aria-modal="true" aria-labelledby="glossary-title" aria-hidden="true">
  <header class="drawer-head">
    <h2 id="glossary-title" class="drawer-title">Glossario</h2>
    <button class="drawer-close" type="button" data-glossary-close aria-label="Chiudi il glossario">&times;</button>
  </header>
  <div class="drawer-body">
    <p class="drawer-intro">Gli acronimi usati nella dashboard. Ogni chip nelle schede apre la voce corrispondente.</p>
    <dl class="glossary-list" data-glossary-list></dl>
  </div>
</aside>

<script src="<?= e($asset('vendor/echarts/echarts.min.js')) ?>"></script>
<script type="module" src="<?= e($asset('js/app.js')) ?>"></script>
</body>
</html>
