/* =====================================================================
 * GMI Widget — embed.js v1
 * =====================================================================
 *
 * Brief §7: consumers embed this script on their own site; it loads
 * their pinned projects from /api/v1/projects.php + renders them
 * inside a Shadow DOM so the host page's CSS can't bleed in and
 * vice versa.
 *
 * EMBED — drop these two lines into any consumer page:
 *
 *   <div data-gmi-widget data-api-key="<consumer api_key>"
 *        data-base="https://www.impacts-foundry.com"
 *        data-mode="list"               // list | card | featured
 *        data-state="planning,execution"
 *        data-scale="micro,mid,macro,borderless"
 *        data-limit="6"></div>
 *   <script src="https://www.impacts-foundry.com/widget/v1/embed.js" async></script>
 *
 * The script auto-discovers EVERY [data-gmi-widget] element on the
 * page at DOMContentLoaded so a consumer can drop several with
 * different filters (e.g. "Education projects" + "Climate projects"
 * side by side) without manual init code.
 *
 * Money lane CTAs ALWAYS route through /api/v1/go.php?ask=N&api_key=K
 * so click attribution is captured BEFORE the supporter leaves the
 * page (brief §6 / §7).
 *
 * No dependencies. No global pollution outside `window.__GMI_WIDGET__`.
 * Shadow DOM => host page CSS doesn't apply to our markup.
 * ===================================================================== */

(function () {
  'use strict';

  /* Guard against double-execution if the script is included twice. */
  if (window.__GMI_WIDGET__ && window.__GMI_WIDGET__.bootstrapped) return;
  window.__GMI_WIDGET__ = { bootstrapped: true, version: '1.0.0' };

  var STYLE = `
    :host {
      all: initial;
      display: block;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #1c2b3a;
      line-height: 1.5;
    }
    .gmi-wrap { display: block; }
    .gmi-list {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 16px;
    }
    .gmi-card {
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 16px;
      background: #fff;
      display: flex;
      flex-direction: column;
      gap: 10px;
      box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .gmi-card h3 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
    }
    .gmi-card p {
      margin: 0;
      font-size: 13px;
      color: #475569;
    }
    .gmi-row {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      font-size: 12px;
      color: #64748b;
    }
    .gmi-chip {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      background: #f1f5f9;
      color: #475569;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .gmi-chip-state-planning   { background: #fef3c7; color: #92400e; }
    .gmi-chip-state-execution  { background: #dcfce7; color: #166534; }
    .gmi-chip-state-done       { background: #e0e7ff; color: #3730a3; }
    .gmi-progress {
      width: 100%;
      height: 6px;
      border-radius: 3px;
      background: #e5e7eb;
      overflow: hidden;
    }
    .gmi-progress-fill {
      height: 100%;
      background: #f97316;
      border-radius: 3px;
      transition: width .25s ease;
    }
    .gmi-cta-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 4px;
    }
    .gmi-btn {
      appearance: none;
      border: 0;
      cursor: pointer;
      padding: 8px 14px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 13px;
      transition: opacity .15s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .gmi-btn-primary  { background: #f97316; color: #fff; }
    .gmi-btn-primary:hover { opacity: .9; }
    .gmi-btn-ghost    { background: #fff; color: #1c2b3a; border: 1px solid #e5e7eb; }
    .gmi-btn-ghost:hover { background: #f8fafc; }
    .gmi-status {
      font-size: 12px;
      color: #64748b;
      padding: 8px;
      text-align: center;
    }
    .gmi-error {
      font-size: 13px;
      color: #b91c1c;
      background: #fef2f2;
      padding: 10px;
      border-radius: 8px;
      border: 1px solid #fecaca;
    }
    .gmi-attrib {
      margin-top: 16px;
      font-size: 11px;
      color: #94a3b8;
      text-align: right;
    }
    .gmi-attrib a { color: inherit; text-decoration: none; border-bottom: 1px dotted #cbd5e1; }
  `;

  /* Lightweight HTML escape — every value rendered from the API
     passes through this. Defense against a sponsor pasting an XSS
     payload into a project title (the API doesn't sanitise; the
     widget MUST). */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmtDateRange(start, end) {
    if (!start && !end) return '';
    var fmt = function (s) {
      if (!s) return '';
      try {
        var d = new Date(s.replace(' ', 'T') + 'Z');
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
      } catch (_) { return s; }
    };
    if (start && end) return fmt(start) + ' — ' + fmt(end);
    return start ? 'Starts ' + fmt(start) : 'Ends ' + fmt(end);
  }

  function renderCard(p, base, apiKey) {
    var stateClass = 'gmi-chip-state-' + esc(p.state);
    var loc = p.location_label
      ? '<span class="gmi-chip">' + esc(p.location_label) + '</span>'
      : '';
    var dateRange = fmtDateRange(p.start_at, p.end_at);
    var dateBlock = dateRange ? '<div class="gmi-row">' + esc(dateRange) + '</div>' : '';

    /* Progress meter for projects in planning — surfaces "needs N
       more supporters" friction to drive supporter conversion. */
    var progressBlock = '';
    if (p.state === 'planning' && p.go_live_progress) {
      var prog = p.go_live_progress;
      var th = prog.thresholds || {};
      var pg = prog.progress  || {};
      var supplied = pg.supporters || 0;
      var required = th.supporters || 1;
      var pct = Math.min(100, Math.round(supplied / required * 100));
      var label = supplied + ' / ' + required + ' supporters';
      if (prog.ready_for_go_live) {
        label = 'Ready to launch';
      }
      progressBlock =
        '<div class="gmi-row">' + esc(label) + '</div>' +
        '<div class="gmi-progress"><div class="gmi-progress-fill" style="width:' + pct + '%"></div></div>';
    }

    /* CTA. Single button for the MVP — clicks land on /v1/go.php
       which logs + redirects to the ask's external_destination_url
       OR (when no money-lane ask exists) on a project detail link
       — Phase 1g / 1h flesh this out. For now, primary CTA is
       "Support this project" which routes to /v1/go.php with the
       project's FIRST active money-lane ask when present; falls
       back to a project mailto / placeholder when none. */
    var ctaUrl = base + '/api/v1/go.php?ask=' +
                 encodeURIComponent(p.primary_ask_id || '') +
                 (apiKey ? '&api_key=' + encodeURIComponent(apiKey) : '');
    var ctaLabel = p.primary_ask_label || 'Support this project';
    var ctaButton = p.primary_ask_id
      ? '<a class="gmi-btn gmi-btn-primary" href="' + esc(ctaUrl) + '" target="_blank" rel="noopener">' + esc(ctaLabel) + '</a>'
      : '<a class="gmi-btn gmi-btn-ghost" href="https://www.impacts-foundry.com/projects/' + encodeURIComponent(p.id) + '" target="_blank" rel="noopener">Learn more</a>';

    return '' +
      '<div class="gmi-card">' +
        '<h3>' + esc(p.title) + '</h3>' +
        (p.description ? '<p>' + esc(p.description.length > 220 ? p.description.slice(0, 217) + '…' : p.description) + '</p>' : '') +
        '<div class="gmi-row">' +
          '<span class="gmi-chip ' + stateClass + '">' + esc(p.state) + '</span>' +
          '<span class="gmi-chip">' + esc(p.scale) + '</span>' +
          loc +
        '</div>' +
        dateBlock +
        progressBlock +
        '<div class="gmi-cta-row">' + ctaButton + '</div>' +
      '</div>';
  }

  function renderShell(projects, base, apiKey) {
    if (!projects.length) {
      return '<div class="gmi-status">No projects available right now. Check back soon.</div>';
    }
    var cards = projects.map(function (p) { return renderCard(p, base, apiKey); }).join('');
    return '<div class="gmi-wrap"><div class="gmi-list">' + cards + '</div>' +
           '<div class="gmi-attrib">Powered by <a href="https://www.impacts-foundry.com" target="_blank" rel="noopener">Impacts Foundry</a></div></div>';
  }

  function mount(host) {
    if (host.__gmiMounted) return;
    host.__gmiMounted = true;

    var apiKey  = (host.getAttribute('data-api-key') || '').trim();
    var base    = (host.getAttribute('data-base')    || 'https://www.impacts-foundry.com').replace(/\/+$/, '');
    var stateF  = (host.getAttribute('data-state')   || '').trim();
    var scaleF  = (host.getAttribute('data-scale')   || '').trim();
    var limit   = parseInt(host.getAttribute('data-limit') || '6', 10);
    if (!Number.isFinite(limit) || limit <= 0) limit = 6;

    /* Shadow DOM keeps host page CSS out + our CSS in. */
    var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style  = document.createElement('style');
    style.textContent = STYLE;
    shadow.appendChild(style);

    var container = document.createElement('div');
    container.innerHTML = '<div class="gmi-status">Loading projects…</div>';
    shadow.appendChild(container);

    if (!apiKey) {
      container.innerHTML = '<div class="gmi-error">GMI widget: missing data-api-key attribute. Contact your Impacts Foundry account manager for a consumer key.</div>';
      return;
    }

    /* Build query. State filter is comma-separated in the data
       attribute but the server only accepts a single value, so we
       hit /projects per-state and concatenate when more than one
       was requested. Keeps the server simple. */
    var states = stateF ? stateF.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [''];
    if (states.length === 0) states = [''];

    Promise.all(states.map(function (st) {
      var url = base + '/api/v1/projects.php?api_key=' + encodeURIComponent(apiKey);
      if (st) url += '&state=' + encodeURIComponent(st);
      return fetch(url, { credentials: 'omit', mode: 'cors' })
        .then(function (r) { return r.json(); })
        .then(function (j) { return (j && j.ok && j.projects) ? j.projects : []; })
        .catch(function () { return []; });
    })).then(function (chunks) {
      var seen = {};
      var merged = [];
      chunks.forEach(function (chunk) {
        chunk.forEach(function (p) {
          if (seen[p.id]) return;
          seen[p.id] = 1;
          /* scale filter applied client-side because we may have
             concatenated multiple state-scoped fetches. */
          if (scaleF) {
            var scales = scaleF.split(',').map(function (s) { return s.trim(); });
            if (scales.indexOf(p.scale) === -1) return;
          }
          merged.push(p);
        });
      });
      /* Sort: planning first (urgency), then execution, then done. */
      var stateOrder = { planning: 0, execution: 1, done: 2 };
      merged.sort(function (a, b) {
        var sa = stateOrder[a.state] != null ? stateOrder[a.state] : 9;
        var sb = stateOrder[b.state] != null ? stateOrder[b.state] : 9;
        if (sa !== sb) return sa - sb;
        return (a.start_at || '').localeCompare(b.start_at || '');
      });
      merged = merged.slice(0, limit);
      container.innerHTML = renderShell(merged, base, apiKey);
    }).catch(function (e) {
      container.innerHTML = '<div class="gmi-error">Could not load projects: ' + esc(e && e.message ? e.message : 'network error') + '</div>';
    });
  }

  function bootstrap() {
    var hosts = document.querySelectorAll('[data-gmi-widget]');
    hosts.forEach(mount);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }

  /* Public API — consumers can mount programmatically after DOM
     mutations (e.g. injecting a widget div via SPA route change). */
  window.__GMI_WIDGET__.mount  = mount;
  window.__GMI_WIDGET__.scan   = bootstrap;
})();
