/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File : /plugins/filemanager/skins/elastic/filemanager.js
 * Path : /var/www/roundcube/plugins/filemanager/skins/elastic/filemanager.js
 *
 * Perilaku sidebar pohon folder (#filemanager-tree):
 * - Lazy-load : anak folder diambil via ?_task=filemanager&_action=tree
 *               saat toggle diklik pertama kali; berikutnya hanya
 *               expand/collapse lokal.
 * - Highlight : node pohon mengikuti folder aktif di iframe — baik saat
 *               link pohon diklik maupun saat navigasi terjadi DI DALAM
 *               iframe (same-origin, dibaca dari location.search).
 *
 * Dimuat oleh filemanager.php::action_index() via include_script().
 */

(function () {
    'use strict';

    function boot() {

    var frame = document.getElementById('filemanager-frame');
    var tree  = document.getElementById('filemanager-tree');
    var GATEWAY = (window.rcmail && rcmail.env && rcmail.env.fm_gateway) || '';

    if (!frame || !tree) {
        return;
    }

    /* ---------------- util ---------------- */

    function cssEscape(s) {
        return (window.CSS && CSS.escape) ? CSS.escape(s)
            : String(s).replace(/([^a-zA-Z0-9_\u00A0-\uFFFF-])/g, '\\$1');
    }

    function htmlEsc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;',
                    '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    /** Cari anak LANGSUNG berdasarkan tag (+opsional class). */
    function childOf(parent, tag, cls) {
        var els = parent.children, i, el;
        for (i = 0; i < els.length; i++) {
            el = els[i];
            if (el.tagName === tag.toUpperCase()
                    && (!cls || el.classList.contains(cls))) {
                return el;
            }
        }
        return null;
    }

    /* ---------------- highlight ---------------- */

    function select(path) {
        var i, old = tree.querySelectorAll('li.fm-node.selected');
        for (i = 0; i < old.length; i++) {
            old[i].classList.remove('selected');
        }
        var li = tree.querySelector(
            'li.fm-node[data-path="' + cssEscape(path) + '"]');
        if (!li) {
            return;
        }
        li.classList.add('selected');

        /* buka semua leluhur agar node aktif selalu terlihat */
        var up = li.parentNode;
        while (up && up !== tree) {
            if (up.classList && up.classList.contains('fm-children')) {
                up.parentNode.classList.add('fm-expanded');
            }
            up = up.parentNode;
        }
    }

    function syncFromFrame() {
        var p = '';
        try {
            var m = frame.contentWindow.location.search.match(/[?&]p=([^&]*)/);
            if (m) {
                p = decodeURIComponent(m[1].replace(/\+/g, '%20'));
            }
        } catch (e) { /* same-origin selalu lolos */ }
        select(p);
    }

    /* ---------------- lazy-load ---------------- */

    function buildNode(n) {
        var li = document.createElement('li');
        li.className = 'fm-node' + (n.has_children ? ' fm-haschildren' : '');
        li.setAttribute('data-path', n.path);

        var row = document.createElement('div');
        row.className = 'fm-row';

        if (n.has_children) {
            var t = document.createElement('span');
            t.className = 'fm-toggle';
            t.setAttribute('role', 'button');
            t.setAttribute('tabindex', '0');
            t.innerHTML = '<i class="fa fa-angle-right"></i>';
            row.appendChild(t);
        }

        var a = document.createElement('a');
        a.href = GATEWAY + '?p=' + encodeURIComponent(n.path);
        a.target = 'filemanager-frame';
        a.innerHTML = '<i class="fa fa-folder"></i><span class="inner">'
            + htmlEsc(n.name) + '</span>';
        row.appendChild(a);
        li.appendChild(row);

        if (n.has_children) {
            var ul = document.createElement('ul');
            ul.className = 'fm-children';
            li.appendChild(ul);
        }
        return li;
    }

    function loadChildren(li) {
        var url = '?_task=filemanager&_action=tree&_folder='
            + encodeURIComponent(li.getAttribute('data-path'));

        fetch(url, {credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) {
                li.removeAttribute('data-waiting');
                li.classList.remove('fm-loading');

                var ul = childOf(li, 'ul', 'fm-children');
                if (ul) {
                    list.forEach(function (n) { ul.appendChild(buildNode(n)); });
                }
                if (!list.length) {
                    /* ternyata daun: cabut panahnya */
                    li.classList.remove('fm-haschildren');
                    var row = childOf(li, 'div', 'fm-row');
                    var t   = row && childOf(row, 'span', 'fm-toggle');
                    if (t) {
                        t.parentNode.removeChild(t);
                    }
                }
                li.setAttribute('data-loaded', '1');
                li.classList.add('fm-expanded');
            })
            .catch(function () {
                li.removeAttribute('data-waiting');
                li.classList.remove('fm-loading');
                if (window.rcmail && rcmail.display_message) {
                    rcmail.display_message('Gagal memuat daftar folder.', 'error');
                }
            });
    }

    /* ---------------- event ---------------- */

    tree.addEventListener('click', function (e) {
        var t = e.target.closest('.fm-toggle');
        if (t && tree.contains(t)) {
            e.preventDefault();
            var li = t.closest('li.fm-node');
            if (!li || li.getAttribute('data-waiting')) {
                return;
            }
            if (li.classList.contains('fm-expanded')) {   // collapse lokal
                li.classList.remove('fm-expanded');
                return;
            }
            if (li.getAttribute('data-loaded')) {         // expand lokal
                li.classList.add('fm-expanded');
                return;
            }
            li.setAttribute('data-waiting', '1');         // fetch pertama
            li.classList.add('fm-loading');
            loadChildren(li);
            return;
        }

        var link = e.target.closest('a[href]');
        if (link && tree.contains(link)) {
            var n = link.closest('li.fm-node');
            if (n) {
                select(n.getAttribute('data-path'));
            }
        }
    });

    frame.addEventListener('load', syncFromFrame);

    /* posisi awal iframe (?p=...) */
    syncFromFrame();

    }

    /* include_script() mencetak <script> di <head>: elemen sidebar/iframe
     * belum ada saat file dieksekusi — tunda sampai DOM siap. */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
