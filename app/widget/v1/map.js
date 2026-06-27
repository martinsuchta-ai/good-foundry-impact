/* =====================================================================
 * GMI Map Widget — map.js v1
 * =====================================================================
 *
 * Brief §7 / §6a: embeddable map widget. Reads /api/v1/map.php
 * GeoJSON, drops markers via Leaflet (loaded lazily from CDN on first
 * use), pops a tooltip on click with project title + state + CTA
 * routing through /api/v1/go.php for attribution.
 *
 * Privacy: this widget NEVER receives exact coordinates for
 * vulnerable-people projects — the SERVER snaps them to suburb
 * before responding (CLAUDE.md §9 + brief §6a).
 *
 * EMBED:
 *
 *   <div data-gmi-map data-api-key="..."
 *        data-base="https://www.impacts-foundry.com"
 *        data-state="planning,execution"
 *        data-scale="micro,mid,macro,borderless"
 *        data-height="420px"
 *        data-center="-25.2744,133.7751"     // optional auto-fit if absent
 *        data-zoom="4"></div>
 *   <script src="https://www.impacts-foundry.com/app/widget/v1/map.js" async></script>
 *
 * Leaflet is loaded from unpkg on first map mount (no bundling — one
 * CDN ping per page). If the page already has Leaflet loaded the
 * widget reuses it.
 * ===================================================================== */

(function () {
  'use strict';

  if (window.__GMI_MAP__ && window.__GMI_MAP__.bootstrapped) return;
  window.__GMI_MAP__ = { bootstrapped: true, version: '1.0.0' };

  var LEAFLET_JS  = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
  var LEAFLET_CSS = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

  var _leafletReady = null;
  function ensureLeaflet() {
    if (window.L) return Promise.resolve(window.L);
    if (_leafletReady) return _leafletReady;
    _leafletReady = new Promise(function (resolve, reject) {
      /* Leaflet CSS — load once at document head; safe to repeat-load
         a CSS tag (browser deduplicates by URL). */
      if (!document.querySelector('link[href="' + LEAFLET_CSS + '"]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = LEAFLET_CSS;
        document.head.appendChild(link);
      }
      var s = document.createElement('script');
      s.src = LEAFLET_JS;
      s.async = true;
      s.onload = function () { resolve(window.L); };
      s.onerror = function () { reject(new Error('failed to load leaflet from ' + LEAFLET_JS)); };
      document.head.appendChild(s);
    });
    return _leafletReady;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  var STATE_COLOURS = {
    planning:  '#f59e0b',
    execution: '#16a34a',
    done:      '#6366f1',
  };

  function popupHtml(props, base) {
    var ctaUrl = props.cta_url ? (base + props.cta_url) : null;
    var stateBadge = '<span style="display:inline-block;padding:1px 6px;border-radius:999px;font-size:10px;font-weight:700;background:' + (STATE_COLOURS[props.state] || '#94a3b8') + ';color:#fff;text-transform:uppercase;">' + esc(props.state) + '</span>';
    var ctaLine = ctaUrl
      ? '<div style="margin-top:8px;"><a href="' + esc(ctaUrl) + '" target="_blank" rel="noopener" style="background:#f97316;color:#fff;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;display:inline-block;">Support →</a></div>'
      : '';
    return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:#1c2b3a;font-size:13px;max-width:240px;">' +
             '<div style="font-weight:700;margin-bottom:4px;">' + esc(props.title) + '</div>' +
             '<div style="display:flex;gap:6px;align-items:center;font-size:11px;color:#64748b;">' +
               stateBadge +
               '<span>' + esc(props.scale) + '</span>' +
               (props.location_label ? '<span>· ' + esc(props.location_label) + '</span>' : '') +
             '</div>' +
             ctaLine +
           '</div>';
  }

  function mount(host) {
    if (host.__gmiMapMounted) return;
    host.__gmiMapMounted = true;

    var apiKey  = (host.getAttribute('data-api-key') || '').trim();
    var base    = (host.getAttribute('data-base')    || 'https://www.impacts-foundry.com').replace(/\/+$/, '');
    var stateF  = (host.getAttribute('data-state')   || '').trim();
    var scaleF  = (host.getAttribute('data-scale')   || '').trim();
    var height  = (host.getAttribute('data-height')  || '420px').trim();
    var center  = (host.getAttribute('data-center')  || '').trim();
    var zoom    = parseInt(host.getAttribute('data-zoom') || '4', 10);
    if (!Number.isFinite(zoom)) zoom = 4;

    /* We do NOT use Shadow DOM here — Leaflet's CSS expects to land
       on document-level styles, and shadow-scoped Leaflet has
       persistent layout bugs (tile pane sizing, marker icon paths).
       Instead we namespace everything under a unique class. */
    host.style.height = height;
    host.style.minHeight = '240px';
    host.style.borderRadius = '8px';
    host.style.overflow = 'hidden';
    host.style.position = 'relative';
    host.innerHTML = '<div style="padding:14px;font-family:-apple-system,sans-serif;font-size:13px;color:#64748b;">Loading map…</div>';

    if (!apiKey) {
      host.innerHTML = '<div style="padding:14px;font-family:-apple-system,sans-serif;font-size:13px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">GMI map: missing data-api-key attribute.</div>';
      return;
    }

    /* Build query. */
    var url = base + '/api/v1/map.php?api_key=' + encodeURIComponent(apiKey);
    if (stateF) url += '&state=' + encodeURIComponent(stateF.split(',')[0]);  /* single-state on server */
    if (scaleF) url += '&scale=' + encodeURIComponent(scaleF.split(',')[0]);

    Promise.all([ensureLeaflet(), fetch(url, { credentials: 'omit', mode: 'cors' }).then(function (r) { return r.json(); })])
      .then(function (results) {
        var L = results[0];
        var data = results[1];
        if (!data || !data.ok || !Array.isArray(data.features)) {
          throw new Error(data && data.error ? data.error : 'bad map response');
        }

        host.innerHTML = '';

        var map = L.map(host, { zoomControl: true, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 18,
          attribution: '© OpenStreetMap'
        }).addTo(map);

        var markers = L.featureGroup();
        data.features.forEach(function (f) {
          if (!f.geometry || !Array.isArray(f.geometry.coordinates)) return;
          var coords = f.geometry.coordinates;  /* [lng, lat] */
          var marker = L.circleMarker([coords[1], coords[0]], {
            radius:      8,
            color:       STATE_COLOURS[f.properties.state] || '#94a3b8',
            fillColor:   STATE_COLOURS[f.properties.state] || '#94a3b8',
            fillOpacity: 0.85,
            weight:      2,
          });
          marker.bindPopup(popupHtml(f.properties, base));
          markers.addLayer(marker);
        });
        markers.addTo(map);

        /* Auto-fit unless explicit data-center supplied. */
        if (center) {
          var parts = center.split(',');
          if (parts.length === 2) {
            map.setView([parseFloat(parts[0]), parseFloat(parts[1])], zoom);
          } else {
            map.setView([-25.2744, 133.7751], zoom);
          }
        } else if (data.features.length > 0) {
          map.fitBounds(markers.getBounds().pad(0.2));
        } else {
          map.setView([-25.2744, 133.7751], 4);  /* Australia default */
        }

        /* Empty-state hint overlay. */
        if (data.features.length === 0) {
          var hint = document.createElement('div');
          hint.style.cssText = 'position:absolute;top:14px;left:50%;transform:translateX(-50%);z-index:1000;background:#fff;padding:8px 14px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.18);font-family:-apple-system,sans-serif;font-size:13px;color:#64748b;';
          hint.textContent = 'No mappable projects yet.';
          host.appendChild(hint);
        }
      })
      .catch(function (e) {
        host.innerHTML = '<div style="padding:14px;font-family:-apple-system,sans-serif;font-size:13px;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;">Could not load map: ' + esc(e && e.message ? e.message : 'unknown error') + '</div>';
      });
  }

  function bootstrap() {
    var hosts = document.querySelectorAll('[data-gmi-map]');
    hosts.forEach(mount);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }

  window.__GMI_MAP__.mount = mount;
  window.__GMI_MAP__.scan  = bootstrap;
})();
