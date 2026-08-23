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

    /* ---------------- resizer panel sidebar ---------------- */

    /* Dua panel (.fm-panel-tree vs .fm-panel-shortcuts) dipisah
     * .fm-resizer yang bisa digeser vertikal; proporsi disimpan di
     * localStorage (per browser). Default 60% untuk pohon folder.
     * Berlaku di halaman utama DAN halaman Kelola Klien — struktur
     * sidebar sama, sehingga posisi pembatas tidak berpindah saat
     * pindah halaman. */

    function initResizer() {
        var panelsBox = document.querySelector('#layout-sidebar .fm-side-panels');
        var resizer   = document.querySelector('#layout-sidebar .fm-resizer');

        if (!panelsBox || !resizer) {
            return;
        }

        var treePanel  = panelsBox.querySelector('.fm-panel-tree');
        var shortPanel = panelsBox.querySelector('.fm-panel-shortcuts');
        var LS_KEY = 'fm_sidebar_tree_pct';
        var curPct;

        function store() {
            try {
                window.localStorage.setItem(LS_KEY, String(curPct));
            } catch (e) { /* private mode: abaikan */ }
        }

        function applyPct(p) {
            curPct = Math.min(80, Math.max(20, p));
            treePanel.style.flex  = '0 0 ' + curPct + '%';
            shortPanel.style.flex = '1 1 0';
        }

        function dragMove(e) {
            e.preventDefault();
            var r  = panelsBox.getBoundingClientRect();
            applyPct(((e.clientY - r.top) / r.height) * 100);
        }

        function dragStop() {
            document.removeEventListener('pointermove', dragMove);
            document.removeEventListener('pointerup', dragStop);
            resizer.classList.remove('fm-dragging');
            store();
        }

        resizer.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            resizer.classList.add('fm-dragging');
            document.addEventListener('pointermove', dragMove);
            document.addEventListener('pointerup', dragStop);
        });

        /* dobel-klik pembatas = kembali ke proporsi awal 60/40 */
        resizer.addEventListener('dblclick', function () {
            applyPct(60);
            store();
        });

        /* alternatif keyboard: ArrowUp/ArrowDown = geser 2%, Home = reset */
        resizer.addEventListener('keydown', function (e) {
            var handled = false;
            if (e.key === 'ArrowUp') {
                applyPct(curPct - 2);
                handled = true;
            } else if (e.key === 'ArrowDown') {
                applyPct(curPct + 2);
                handled = true;
            } else if (e.key === 'Home') {
                applyPct(60);
                handled = true;
            }
            if (handled) {
                e.preventDefault();
                store();
            }
        });

        var saved = parseFloat((function () {
            try {
                return window.localStorage.getItem(LS_KEY);
            } catch (e) { return null; }
        })());
        applyPct(isNaN(saved) ? 60 : saved);
    }

    /* ---------------- picker folder HOME (Kelola Klien) ---------------- */

    /* Menu dropdown kustom pengganti <datalist>: selalu bisa dibuka via
     * tombol, bisa disaring (filter), item diklik mengisi input _home. */
    function initHomePicker() {
        var wrap = document.querySelector('.fm-home-picker');
        if (!wrap) {
            return;
        }

        var toggle = wrap.querySelector('.fm-home-toggle');
        var menu   = wrap.querySelector('.fm-home-menu');
        var filter = wrap.querySelector('.fm-home-filter');
        var list   = menu.querySelector('ul');
        var input  = document.getElementById('fm-home');

        function close() {
            menu.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        function open() {
            menu.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            if (filter) {
                applyFilter('');
                filter.value = '';
                filter.focus();
            }
        }

        function applyFilter(qs) {
            qs = qs.toLowerCase();
            var items = list.querySelectorAll('li');
            for (var i = 0; i < items.length; i++) {
                var show = !qs || items[i].textContent.toLowerCase().indexOf(qs) !== -1;
                items[i].style.display = show ? '' : 'none';
            }
        }

        if (!toggle.disabled) {
            toggle.addEventListener('click', function () {
                if (menu.hidden) {
                    open();
                } else {
                    close();
                }
            });

            /* tutup saat klik di luar atau tekan Escape */
            document.addEventListener('click', function (e) {
                if (!wrap.contains(e.target)) {
                    close();
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !menu.hidden) {
                    close();
                    toggle.focus();
                }
            });
        }

        if (filter) {
            filter.addEventListener('input', function () {
                applyFilter(filter.value);
            });
        }

        list.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-path]');
            if (!b) {
                return;
            }
            input.value = b.getAttribute('data-path');
            close();
            input.focus();
        });

        /* ketik di input HOME = saring daftar langsung (tanpa buka menu) */
        input.addEventListener('input', function () {
            applyFilter(input.value);
            if (!menu.hidden) {
                return;
            }
        });
    }

    /* ---------------- form Kelola Klien ---------------- */

    /* Generate password + konfirmasi hapus — tanpa handler inline agar
     * tidak tersentuh escaping pipeline output Roundcube. */
    function initClientsForm() {
        var gen = document.getElementById('fm-genpwd');
        var pwd = document.getElementById('fm-pwd');

        if (gen && pwd) {
            gen.addEventListener('click', function () {
                var c = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
                var n = 16, s = '', i;
                for (i = 0; i < n; i++) {
                    s += c[Math.floor(Math.random() * c.length)];
                }
                pwd.value = s;
            });
        }

        /* default HOME terpilih penuh saat fokus — langsung bisa
         * diketik ulang tanpa hapus manual */
        if (input) {
            input.addEventListener('focus', function () {
                input.select();
            });
        }

        /* nama pengguna dipaksa huruf kecil secara langsung */
        var userInput = document.getElementById('fm-user');
        if (userInput) {
            userInput.addEventListener('input', function () {
                var pos = userInput.selectionStart;
                userInput.value = userInput.value.toLowerCase().replace(/\s+/g, '');
                if (typeof pos === 'number') {
                    userInput.setSelectionRange(pos, pos);
                }
            });
        }

        document.addEventListener('click', function (e) {
            var b = e.target.closest('.fm-del');
            if (b && !window.confirm(b.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    }

    function boot() {

    initResizer();
    initHomePicker();
    initClientsForm();

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
            t.setAttribute('aria-label',
                (window.rcmail && rcmail.gettext)
                    ? rcmail.gettext('toggle_folder', 'filemanager')
                    : 'Expand or collapse folder');
            t.innerHTML = '<i class="fa fa-angle-right" aria-hidden="true"></i>';
            row.appendChild(t);
        }

        var a = document.createElement('a');
        a.href = GATEWAY + '?p=' + encodeURIComponent(n.path);
        a.target = 'filemanager-frame';
        a.innerHTML = '<i class="fa fa-folder" aria-hidden="true"></i><span class="inner">'
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

    /* toggle pohon bisa dioperasikan keyboard (Enter/Spasi) — elemen
     * span role=button tidak memicu event click dari keyboard */
    tree.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        var t = e.target.closest('.fm-toggle');
        if (t && tree.contains(t)) {
            e.preventDefault();
            t.click();
        }
    });

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
