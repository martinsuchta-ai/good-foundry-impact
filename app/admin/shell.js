/* shell.js — shared admin shell behaviour.
 *
 * Renders the sidebar nav + content frame for any authenticated
 * admin page. Boot:
 *   1. Validate session via /api/admin/auth.php?action=me
 *   2. On 401 -> redirect to login.html
 *   3. On 200 -> stash the user on window._impactsUser + render nav
 *
 * Pages embed this script at the top of <body>; nothing renders
 * until the auth check resolves.
 */

(function () {
    var API = '../../api/admin/auth.php';

    function $(sel) { return document.querySelector(sel); }

    function redirectToLogin() {
        window.location.replace('login.html');
    }

    function renderShell(user) {
        var nav = $('#nav');
        if (!nav) return;
        var here = (location.pathname.split('/').pop() || 'index.html');
        var items = [
            { href: 'index.html',     label: 'Dashboard' },
            { href: 'consumers.html', label: 'Consumers' },
            { href: 'placements.html', label: 'Placements' },
            { href: 'sponsors.html',  label: 'Sponsors' },
            { href: 'projects.html',  label: 'Impact projects' },
        ];
        nav.innerHTML = items.map(function (it) {
            var cls = (here === it.href) ? 'nav-item active' : 'nav-item';
            return '<a class="' + cls + '" href="' + it.href + '">' + it.label + '</a>';
        }).join('');

        var who = $('#whoami');
        if (who) who.textContent = user.email || '';
    }

    function signOut() {
        fetch(API + '?action=logout', { method: 'POST', credentials: 'include' })
            .finally(redirectToLogin);
    }

    function boot() {
        fetch(API + '?action=me', { credentials: 'include', cache: 'no-store' })
            .then(function (r) {
                if (r.status === 401) { redirectToLogin(); return null; }
                return r.json();
            })
            .then(function (j) {
                if (!j || !j.ok) return;
                window._impactsUser = j.user;
                renderShell(j.user);
                var btn = $('#signout-btn');
                if (btn) btn.addEventListener('click', signOut);
                document.body.classList.add('booted');
            })
            .catch(function () { redirectToLogin(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
