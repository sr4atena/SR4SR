/**
 * Boot: load /api/dashboard, wire the header (freshness, glossary, period),
 * start the hash router and render views. No framework, no build step.
 */
import { initRouter } from './router.js';
import { getState, setData, setPeriod, restorePeriod, onPeriodChange } from './state.js';
import { PERIODS } from './series.js';
import { fmtDate, fmtStamp } from './format.js';
import { initGlossary } from './glossary.js';
import { disposeCharts, refreshCharts, connectView } from './charts.js';
import { el } from './cards.js';
import * as valore from './views/valore.js';
import * as crescita from './views/crescita.js';
import * as monetizzazione from './views/monetizzazione.js';
import * as salute from './views/salute.js';
import * as ads from './views/ads.js';
import * as aiSentiment from './views/voci.js';

// Keys are the hash and the body[data-view] value; router.js maps the old #voci to the new one.
const views = { valore, crescita, monetizzazione, salute, ads, 'ai-sentiment': aiSentiment };
const main = document.getElementById('main');

// The server leaves out the tabs the selected game lacks (body[data-views-off]);
// an old link or bookmark to one of them gets a notice, never another game's view.
for (const name of (document.body.dataset.viewsOff || '').split(' ').filter(Boolean)) {
  if (!views[name]) continue;
  const title = views[name].title;
  views[name] = {
    title,
    render() { showStatus('Non disponibile', `${title} non è disponibile per questo gioco.`); },
  };
}

function showStatus(title, text, { retry = false } = {}) {
  const box = el('div', 'status');
  box.setAttribute('role', 'status');
  box.appendChild(el('h2', 'status-title', title));
  if (text) box.appendChild(el('p', 'status-text', text));
  if (retry) {
    const btn = el('button', 'btn', 'Riprova');
    btn.type = 'button';
    btn.addEventListener('click', () => location.reload());
    box.appendChild(btn);
  }
  main.replaceChildren(box);
}

async function load() {
  let res;
  try {
    res = await fetch('/api/dashboard', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  } catch {
    showStatus('Connessione assente', 'Impossibile raggiungere il server. Controlla la rete e riprova.', { retry: true });
    return null;
  }
  if (res.status === 401 || res.status === 403 || (res.redirected && new URL(res.url).pathname.startsWith('/login'))) {
    location.href = '/login';
    return null;
  }
  if (res.status === 503) {
    showStatus('Dashboard in costruzione', 'I dati non sono ancora stati generati. Il primo aggiornamento arriva alle 07:00.', { retry: true });
    return null;
  }
  if (!res.ok) {
    showStatus('Qualcosa non va', `Il server ha risposto ${res.status}. Riprova tra qualche minuto.`, { retry: true });
    return null;
  }
  try {
    return await res.json();
  } catch {
    showStatus('Dati non leggibili', 'La risposta del server non è un JSON valido.', { retry: true });
    return null;
  }
}

function wireHeader(data) {
  const chip = document.getElementById('freshness');
  if (chip) {
    // "dati al 16 set" alone reads as two days stale on the 18th: Roblox
    // publishes a day late and revises it, so the provisional day is named
    // next to the consolidated one, and the fetch says which day it ran.
    const through = data.dataThrough ? fmtDate(data.dataThrough, 'axis') : null;
    const prov = data.provisionalDate ? fmtDate(data.provisionalDate, 'axis') : null;
    const stamp = data.coverage?.fetchedAt ?? data.generatedAt;
    chip.textContent = [
      through && `dati al ${through}`,
      prov && `${prov} provvisorio`,
      stamp && `aggiornati ${fmtStamp(stamp)}`,
    ].filter(Boolean).join(' · ');
    const cov = data.coverage;
    if (cov?.from && cov?.to) chip.setAttribute('title', `Storico dal ${fmtDate(cov.from, 'long')} al ${fmtDate(cov.to, 'long')} (${cov.days ?? ''} giorni)`);
  }
  const menuBtn = document.getElementById('user-menu-button');
  const menu = document.getElementById('user-menu');
  if (menuBtn && menu) {
    menuBtn.addEventListener('click', () => {
      const open = menu.classList.toggle('is-open');
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', (ev) => {
      if (!menu.contains(ev.target) && ev.target !== menuBtn && !menuBtn.contains(ev.target)) {
        menu.classList.remove('is-open');
        menuBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }
  const glossaryBtn = document.getElementById('glossary-button');
  if (glossaryBtn) initGlossary(data.glossary, glossaryBtn);
}

function wirePeriod() {
  const buttons = [...document.querySelectorAll('[data-period]')];
  const paint = () => buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.period === getState().period)));
  restorePeriod(PERIODS);
  paint();
  buttons.forEach((b) => b.addEventListener('click', () => { setPeriod(b.dataset.period); paint(); }));
  onPeriodChange(() => refreshCharts());
}

function renderView(name, view) {
  disposeCharts();
  main.replaceChildren();
  main.className = `main view-${name}`;
  document.body.dataset.view = name;
  view.render(main, getState());
  connectView(name);
}

async function boot() {
  if (!globalThis.echarts) {
    showStatus('Libreria grafici mancante', 'Il file ECharts non è stato caricato.');
    return;
  }
  showStatus('Caricamento…', null);
  const data = await load();
  if (!data) return;
  setData(data);
  wireHeader(data);
  wirePeriod();
  initRouter(views, renderView);
}

boot();
