<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/filemanager.php
 * Path    : /var/www/roundcube/plugins/filemanager/filemanager.php
 *
 * Plugin utama (entry point) yang mendaftarkan task "filemanager":
 * - Taskbar menu
 * - Localization
 * - Icon
 * - Halaman engine TinyFileManager (iframe)
 *
 * Struktur direktori:
 *   filemanager/
 *   ├── filemanager.php              -> Kelas plugin utama (file ini)
 *   ├── filemanager_ui.php           -> Handler UI (taskbar button & stylesheet)
 *   ├── config.inc.php.dist          -> Template konfigurasi (credential klien)
 *   ├── lib/
 *   │   ├── tinyfilemanager.php      -> Engine TinyFileManager v2.6 (stock)
 *   │   ├── config.php               -> Override config engine (dibaca otomatis)
 *   │   └── translation.json         -> Terjemahan engine
 *   └── skins/elastic/
 *       ├── filemanager.css          -> Style tombol taskmenu (ikon)
 *       ├── images/filemanager.png   -> Gambar ikon menu
 *       └── templates/filemanager.html -> Layout Elastic + iframe gateway
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 * @version    @package_version@
 */
require_once __DIR__ . '/filemanager_ui.php';

#[AllowDynamicProperties]
class filemanager extends rcube_plugin
{
    public $task = '?(?!login|logout).*';

    public $allowed_prefs = [];

    public $rc;

    public $api;

    private $ui;

    /** @var array Konteks untuk handler sidebar [gateway, root] */
    private $sidebar_ctx = [];

    public function init()
    {
        $this->rc  = rcmail::get_instance();
        $this->api = $this->rc->plugins;

        // Load plugin localization
        $this->add_texts('localization/', false);

        // Register custom task
        $this->register_task('filemanager');

        // Register default action for the filemanager task
        $this->register_action('index', [$this, 'action_index']);

        // AJAX: daftar subfolder untuk pohon sidebar (lazy-load)
        $this->register_action('tree', [$this, 'action_tree']);

        // Manajemen akun klien (CRUD, DISCUSS.md #5)
        $this->register_action('clients', [$this, 'action_clients']);
        $this->register_action('clients_save', [$this, 'action_clients_save']);
        $this->register_action('clients_delete', [$this, 'action_clients_delete']);
        $this->register_action('clients_settings', [$this, 'action_clients_settings']);

        // Initialize UI
        $this->ui = new filemanager_ui($this);
        $this->ui->init();
    }

    /**
     * Render halaman Filemanager: layout Elastic tiga kolom
     * (menu bawaan + pohon folder di sidebar + iframe engine).
     *
     * Gateway /filemanager.php menjalankan engine TinyFileManager dalam
     * mode staff (SSO sesi Roundcube, tanpa form login). Pengunjung tanpa
     * sesi di-redirect gateway ke task ini.
     */
    public function action_index()
    {
        // URL gateway absolut penuh (scheme://host/filemanager.php).
        // PENTING: fix_paths() rcmail_output_html menimpa src/href
        // root-absolut ("/x") dengan base_path skin -> "/skins/elastic/x".
        // Nilai berisi "://" dilewati semua rewriter Roundcube.
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme  = $https ? 'https' : 'http';
        $gateway = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/filemanager';

        $this->rc->output->set_env('gateway_url', $gateway);
        $this->rc->output->set_env('fm_gateway', $gateway);
        $this->include_script($this->local_skin_path() . '/filemanager.js');

        $this->sidebar_ctx = ['gateway' => $gateway, 'root' => $this->staff_root()];
        $this->rc->output->add_handler('filemanager_sidebar', [$this, 'tpl_sidebar']);
        $this->rc->output->set_pagetitle($this->gettext('filemanager.navtitle'));

        $this->rc->output->send('filemanager.filemanager');
    }

    /**
     * AJAX (?_task=filemanager&_action=tree&_folder=<rel>): daftar
     * subfolder langsung untuk lazy-load pohon. Output JSON array of
     * {name, path, has_children}. Hanya sesi Roundcube yang sah.
     */
    public function action_tree()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->rc->user || !$this->rc->user->ID) {
            echo '[]';
            exit;
        }

        $rel  = self::safe_rel(rcube_utils::get_input_value('_folder', rcube_utils::INPUT_GET));
        $dir  = $rel === null ? null : self::resolve_dir($this->staff_root(), $rel);
        $list = $dir === null ? [] : $this->tree_nodes($dir, $rel);

        echo json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Root folder staf: <staff_base>/<local-part email> — konsisten dengan
     * aturan chroot pada gateway public_html/filemanager.php.
     */
    private function staff_root()
    {
        $cfg = is_readable(__DIR__ . '/config.inc.php')
            ? @include __DIR__ . '/config.inc.php'
            : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $username  = (string) $this->rc->user->get_username();
        $pos       = strrpos($username, '@');
        $localpart = strtolower($pos !== false ? substr($username, 0, $pos) : $username);
        $base      = rtrim(!empty($cfg['staff_base']) ? $cfg['staff_base'] : '/mnt/files', '/');

        return $base . '/' . preg_replace('/[^a-z0-9._-]/', '', $localpart);
    }

    /**
     * Nilai MENTAH setting folder induk perusahaan (relatif terhadap
     * mount user). Sumber: tabel filemanager_settings -> fallback
     * config 'client_home_default'. Dipakai untuk input pengaturan.
     */
    private function client_home_default_raw()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $val = '';
        try {
            require_once __DIR__ . '/lib/store.php';
            $val = trim((string) fm_store::get_setting('client_home_default'));
        } catch (Exception $e) {
            $val = ''; // DB tak tersedia: fallback config
        }
        if ($val === '') {
            $cfg = is_readable(__DIR__ . '/config.inc.php')
                ? @include __DIR__ . '/config.inc.php'
                : [];
            $val = is_array($cfg) && !empty($cfg['client_home_default'])
                ? trim((string) $cfg['client_home_default'])
                : '';
        }
        $cache = trim($val, '/');
        return $cache;
    }

    /**
     * Folder induk perusahaan (absolut) untuk picker, normalisasi,
     * dan tampilan. Nilai relatif dinormalisasi terhadap mount user.
     */
    private function client_home_default()
    {
        $val = $this->client_home_default_raw();
        if ($val === '') {
            return '';
        }
        if ($val[0] !== '/') {
            $val = rtrim($this->staff_root(), '/') . '/' . $val;
        }
        return rtrim($val, '/');
    }

    /**
     * Template object <roundcube:object name="filemanager_sidebar" />:
     * - Pohon folder "Berkas Saya" (#filemanager-tree). Level pertama
     *   dirender server-side; level lebih dalam dimuat via AJAX saat
     *   node dibuka (lihat filemanager.js + action_tree()).
     * - Pintasan terpisah (#filemanager-shortcuts): Dibagikan & Sampah,
     *   hanya bila foldernya ada.
     * Klik item membuka path tersebut di iframe via atribut target HTML.
     */
    public function tpl_sidebar()
    {
        // Mode halaman Kelola Klien: struktur dua panel SAMA dengan
        // halaman utama agar posisi pembatas tidak berpindah (dipulihkan
        // dari localStorage oleh filemanager.js). Panel pohon hanya
        // berisi pintasan kembali ke Berkas Saya (tanpa iframe/JS pohon);
        // semua link navigasi top-window.
        if (($this->sidebar_ctx['mode'] ?? '') === 'clients') {
            return '<div class="header">'
                . '<span class="header-title">' . rcube::Q($this->gettext('manageclients')) . '</span>'
                . '</div>'
                . '<div class="fm-side-panels">'
                . '<div class="fm-panel fm-panel-tree"><div class="scroller">'
                . '<ul class="listing">'
                . '<li><a href="' . rcube::Q('?_task=filemanager')
                . '"><i class="fa fa-folder-open" aria-hidden="true"></i><span class="inner">'
                . rcube::Q($this->gettext('myfiles')) . '</span></a></li>'
                . '</ul></div></div>'
                . $this->resizer_html()
                . '<div class="fm-panel fm-panel-shortcuts"><div class="scroller">'
                . '<ul class="listing" id="filemanager-shortcuts">'
                . '<li class="selected"><a href="'
                . rcube::Q('?_task=filemanager&_action=clients&amp;_saved=1')
                . '" class="fm-clients-nav"><i class="fa fa-users" aria-hidden="true"></i><span class="inner">'
                . rcube::Q($this->gettext('manageclients')) . '</span></a></li>'
                . '</ul></div></div>'
                . '</div>';
        }

        $gateway = rtrim($this->sidebar_ctx['gateway'], '/');
        $root    = rtrim($this->sidebar_ctx['root'], '/');
        $nodes   = is_dir($root) ? $this->tree_nodes($root, '') : [];

        // Dua panel vertikal (pohon vs pintasan) dipisah .fm-resizer;
        // proporsi digeser user via JS (filemanager.js) dan disimpan
        // di localStorage browser.
        $out = '<div class="header">'
            . '<span class="header-title">' . rcube::Q($this->gettext('filemanager.navtitle')) . '</span>'
            . '</div>'
            . '<div class="fm-side-panels">'

            // -- panel 1: pohon folder (scroll sendiri) --
            . '<div class="fm-panel fm-panel-tree"><div class="scroller">'
            . '<ul class="listing" id="filemanager-tree" role="tree">'
            . '<li class="fm-node fm-expanded fm-haschildren" data-path="" data-loaded="1">'
            . '<div class="fm-row"><span class="fm-toggle" role="button" tabindex="0"'
            . ' aria-label="' . rcube::Q($this->gettext('toggle_folder')) . '">'
            . '<i class="fa fa-angle-down" aria-hidden="true"></i></span>'
            . '<a href="' . rcube::Q($gateway . '?p=') . '" target="filemanager-frame">'
            . '<i class="fa fa-folder-open" aria-hidden="true"></i><span class="inner">'
            . rcube::Q($this->gettext('myfiles')) . '</span></a></div>'
            . '<ul class="fm-children">';
        foreach ($nodes as $n) {
            $out .= $this->node_li($gateway, $n);
        }
        $out .= '</ul></li></ul>';
        $out .= '</div></div>'

            // -- pembatas yang bisa digeser --
            . $this->resizer_html()

            // -- panel 2: pintasan (scroll sendiri) --
            . '<div class="fm-panel fm-panel-shortcuts"><div class="scroller">'
            . '<ul class="listing" id="filemanager-shortcuts">';

        // Kelola Klien — hanya untuk manager (DISCUSS.md #5)
        if ($this->is_manager()) {
            $out .= '<li><a href="' . rcube::Q('?_task=filemanager&_action=clients&_saved=1')
                . '" class="fm-clients-nav"><i class="fa fa-users" aria-hidden="true"></i><span class="inner">'
                . rcube::Q($this->gettext('manageclients'))
                . '</span></a></li>';
        }

        foreach ([['shared', 'share-alt', 'shared'], ['.trash', 'trash', 'trash']] as $s) {
            if (!is_dir($root . '/' . $s[0])) {
                continue;
            }
            $out .= '<li><a href="' . rcube::Q($gateway . '?p=' . rawurlencode($s[0]))
                . '" target="filemanager-frame"><i class="fa fa-' . $s[1]
                . '" aria-hidden="true"></i><span class="inner">' . rcube::Q($this->gettext($s[2]))
                . '</span></a></li>';
        }
        $out .= '</ul></div></div></div>';

        return $out;
    }

    /**
     * Pembatas dua panel sidebar. Dapat digeser mouse/touch (filemanager.js)
     * dan keyboard (ArrowUp/ArrowDown, Home = reset 60/40).
     */
    private function resizer_html()
    {
        return '<div class="fm-resizer" role="separator" aria-orientation="horizontal"'
            . ' tabindex="0" title="' . rcube::Q($this->gettext('resizer_label'))
            . '" aria-label="' . rcube::Q($this->gettext('resizer_label')) . '"></div>';
    }

    /**
     * Satu <li> pohon. Struktur identik dengan buildNode() di
     * filemanager.js agar hasil AJAX dan render awal seragam.
     */
    private function node_li($gateway, array $node)
    {
        $href = $gateway . '?p=' . rawurlencode($node['path']);
        $html = '<li class="fm-node' . ($node['has_children'] ? ' fm-haschildren' : '')
            . '" data-path="' . rcube::Q($node['path']) . '"><div class="fm-row">';
        if ($node['has_children']) {
            $html .= '<span class="fm-toggle" role="button" tabindex="0"'
                . ' aria-label="' . rcube::Q($this->gettext('toggle_folder')) . '">'
                . '<i class="fa fa-angle-right" aria-hidden="true"></i></span>';
        }
        $html .= '<a href="' . rcube::Q($href) . '" target="filemanager-frame">'
            . '<i class="fa fa-folder" aria-hidden="true"></i><span class="inner">' . rcube::Q($node['name'])
            . '</span></a></div>';
        if ($node['has_children']) {
            $html .= '<ul class="fm-children"></ul>';
        }
        return $html . '</li>';
    }

    /**
     * Normalisasi path relatif dari input user: string bersih ('' = root)
     * atau NULL bila mencurigakan. Pengaman utama tetap resolve_dir()
     * berbasis realpath.
     */
    private static function safe_rel($rel)
    {
        $rel = trim((string) $rel, '/');
        if ($rel === '') {
            return '';
        }
        if (strpos($rel, "\0") !== false) {
            return null;
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return null;
            }
        }
        return $rel;
    }

    /**
     * Konversi path relatif -> direktori nyata DI DALAM $root, atau NULL
     * (menahan traversal/symlink keluar root).
     */
    private static function resolve_dir($root, $rel)
    {
        $base = realpath($root);
        if ($base === false || !is_dir($base)) {
            return null;
        }
        if ($rel === '') {
            return $base;
        }
        $full = realpath($base . '/' . $rel);
        if ($full === false || !is_dir($full) || strpos($full, $base . '/') !== 0) {
            return null;
        }
        return $full;
    }

    /**
     * Daftar subfolder langsung dari $dir ($rel = path relatifnya terhadap
     * root staf). Entri top-level 'shared' dan '.trash' disembunyikan dari
     * pohon karena tersedia sebagai pintasan terpisah.
     */
    private function tree_nodes($dir, $rel)
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return [];
        }
        $out = [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue; // termasuk .cache/.AppleDouble/.trash dsb.
            }
            if ($rel === '' && $name === 'shared') {
                continue; // tersedia sebagai pintasan terpisah
            }
            $full = $dir . '/' . $name;
            if (!is_dir($full)) {
                continue;
            }
            $has  = false;
            $subs = @scandir($full);
            foreach ((array) $subs as $s) {
                if ($s !== '.' && $s !== '..' && is_dir($full . '/' . $s)) {
                    $has = true;
                    break;
                }
            }
            $out[] = [
                'name'         => $name,
                'path'         => $rel === '' ? $name : $rel . '/' . $name,
                'has_children' => $has,
            ];
            if (count($out) >= 500) { // pengaman mount raksasa
                break;
            }
        }
        usort($out, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

    /* ==================================================================
     * MANAJEMEN KLIEN (CRUD) — DISCUSS.md #5
     * Sumber data: tabel MySQL filemanager_clients via lib/store.php.
     * Penyajian multi-folder: farm symlink via lib/farm.php.
     * ================================================================== */

    /** @var array Konteks render halaman manajemen klien */
    private $clients_ctx = [];

    /**
     * Gate izin pengelola: key 'managers' di config.inc.php plugin.
     * Kosong/tak diset = semua staf boleh. Isi boleh email lengkap
     * atau local-part, case-insensitive.
     */
    private function is_manager()
    {
        $cfg = is_readable(__DIR__ . '/config.inc.php')
            ? @include __DIR__ . '/config.inc.php'
            : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $m = [];
        foreach ((array) ($cfg['managers'] ?? []) as $v) {
            if (is_string($v) && $v !== '') {
                $m[] = strtolower($v);
            }
        }
        if (!count($m)) {
            return true;
        }
        $username = strtolower((string) $this->rc->user->get_username());
        $p     = strrpos($username, '@');
        $local = $p !== false ? substr($username, 0, $p) : $username;
        return in_array($username, $m, true) || in_array($local, $m, true);
    }

    /** Akar mount utk farm klien (config 'staff_base'). */
    private function client_base()
    {
        $cfg = is_readable(__DIR__ . '/config.inc.php')
            ? @include __DIR__ . '/config.inc.php'
            : [];
        return rtrim(!empty($cfg['staff_base'])
            ? (string) $cfg['staff_base']
            : '/mnt/files', '/');
    }

    /** CSRF manual: token sesi Roundcube vs POST _token. */
    private function check_token()
    {
        $tok = rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST);
        return is_string($tok) && !empty($_SESSION['request_token'])
            && hash_equals($_SESSION['request_token'], $tok);
    }

    /** Respon token tidak sah: pesan + kembali ke daftar klien. */
    private function token_fail()
    {
        $this->rc->output->show_message('err_token', 'error');
        $this->rc->output->redirect(['_task' => 'filemanager', '_action' => 'clients']);
        $this->rc->output->send('filemanager.clients');
    }

    /** Segmen nama folder aman (anti traversal). */
    private static function valid_seg($s)
    {
        return is_string($s) && $s !== '' && $s[0] !== '.'
            && strpos($s, '/') === false && strpos($s, '\\') === false
            && strpos($s, "\0") === false && !in_array($s, ['.', '..'], true);
    }

    /**
     * Tampilan path HOME untuk UI: prefix folder induk default
     * (client_home_default) dibuang karena sudah tersirat dari config.
     * Database tetap menyimpan path absolut.
     */
    private function home_display($home)
    {
        $home   = (string) $home;
        $defDir = rtrim($this->client_home_default(), '/');
        if ($defDir !== '' && strpos($home . '/', $defDir . '/') === 0) {
            return ltrim(substr($home, strlen($defDir)), '/');
        }
        return $home;
    }

    /**
     * Pindai struktur share HOME (folder induk perusahaan): daftar
     * TAHUN beserta BULAN di bawahnya — unit share resmi adalah
     * <TAHUN>/<BULAN>/PDF (konsistensi wajib). Varian lain di luar
     * pola (mis. <TAHUN>/<BULAN>/LAPORAN/PDF) DIBIARKAN.
     * Legacy <TAHUN>/PDF tanpa bulan tetap didukung sebagai entri ''.
     *
     * Hasil: [tahun => [bulan => pdfSudahAda]] dengan bulan '' = legacy.
     */
    private function scan_years($home)
    {
        $out = [];
        foreach ((array) @scandir($home) as $year) {
            if ($year === '.' || $year === '..' || $year[0] === '.') {
                continue;
            }
            $ydir = $home . '/' . $year;
            if (!is_dir($ydir)) {
                continue;
            }

            $months = [];
            if (is_dir($ydir . '/PDF')) {
                $months[''] = true; // legacy: PDF langsung di bawah tahun
            }
            foreach ((array) @scandir($ydir) as $month) {
                if ($month === '.' || $month === '..' || $month[0] === '.') {
                    continue;
                }
                if ($month === 'PDF' || !is_dir($ydir . '/' . $month)) {
                    continue;
                }
                $months[$month] = is_dir($ydir . '/' . $month . '/PDF');
            }
            if (count($months)) {
                $out[$year] = $months;
            }
        }
        uksort($out, 'strnatcasecmp');
        return $out;
    }

    /** Halaman manajemen hanya utk manager; selain itu pulang ke index. */
    private function render_gate()
    {
        if (!$this->is_manager()) {
            $this->rc->output->show_message('err_notmanager', 'error');
            $this->rc->output->redirect(['_task' => 'filemanager']);
            $this->rc->output->send('filemanager.filemanager');
            return false;
        }
        return true;
    }

    /** Render halaman clients dengan konteks tertentu. */
    private function render_clients(array $ctx, $info_key = null)
    {
        require_once __DIR__ . '/lib/store.php';
        require_once __DIR__ . '/lib/farm.php';

        $this->clients_ctx = $ctx;
        if ($info_key !== null) {
            $this->rc->output->show_message($info_key, 'confirmation');
        }
        $this->sidebar_ctx = ['mode' => 'clients'];
        $this->rc->output->add_handler('filemanager_sidebar', [$this, 'tpl_sidebar']);
        $this->include_script($this->local_skin_path() . '/filemanager.js');
        $this->rc->output->add_handler('filemanager_clients', [$this, 'tpl_clients']);
        $this->rc->output->set_pagetitle($this->gettext('manageclients'));
        $this->rc->output->send('filemanager.clients');
    }

    /** GET halaman kelola klien: form baru / mode edit (_edit=username). */
    public function action_clients()
    {
        if (!$this->render_gate()) {
            return;
        }

        require_once __DIR__ . '/lib/store.php';
        try {
            fm_store::ensure_table();
        } catch (Exception $e) {
            $this->rc->output->show_message('err_db', 'error');
            $this->rc->output->redirect(['_task' => 'filemanager']);
            $this->rc->output->send('filemanager.filemanager');
            return;
        }

        $ctx = [
            'edit' => null, 'u' => '', 'home' => '', 'ro' => false, 'p' => '',
            'years' => null, 'checked' => [],
        ];

        // refill hasil submit yang gagal validasi (flash session).
        // Setelah simpan/hapus sukses redirect membawa _saved=1: URL jadi
        // unik (mencegah Chrome memulihkan isi form lama) dan refill
        // sengaja dilewati agar form bersih untuk input baru.
        if (rcube_utils::get_input_value('_saved', rcube_utils::INPUT_GET) === '1') {
            unset($_SESSION['fm_client_form']);
        } elseif (isset($_SESSION['fm_client_form'])
            && is_array($_SESSION['fm_client_form'])) {
            $ctx = array_merge($ctx, $_SESSION['fm_client_form']);
            unset($_SESSION['fm_client_form']);
        }

        // mode edit
        $edit = rcube_utils::get_input_value('_edit', rcube_utils::INPUT_GET);
        if ($edit !== null && $edit !== '') {
            $row = fm_store::get((string) $edit);
            if ($row) {
                $ctx['edit'] = $row;
                $ctx['u']    = (string) $row['username'];
                $ctx['home'] = (string) $row['home'];
                $ctx['ro']   = !empty($row['readonly']);
                $ctx['years']  = $this->scan_years((string) $row['home']);
                // paths tersimpan sebagai map label => path absolut.
                // Key checkbox = relatif dari home: "TAHUN" atau "TAHUN/BULAN".
                $checked = [];
                foreach ((array) json_decode((string) $row['paths'], true) as $p) {
                    $r  = realpath((string) $p);
                    $hR = realpath((string) $row['home']);
                    if ($r === false || $hR === false
                        || strpos($r . '/', $hR . '/') !== 0) {
                        continue;
                    }
                    // "<home>/2026/MEI/PDF" -> "2026/MEI";
                    // "<home>/2026/PDF"     -> "2026"
                    $rel = ltrim(substr($r, strlen($hR)), '/');
                    if (substr($rel, -4) === '/PDF') {
                        $checked[substr($rel, 0, -4)] = true;
                    }
                }
                $ctx['checked'] = $checked;
            } else {
                $this->rc->output->show_message('err_invalid_user', 'error');
            }
        }

        $this->render_clients($ctx);
    }

    /** Tombol Pindai (_do=scan) & Simpan (_do=save) — satu endpoint POST. */
    public function action_clients_save()
    {
        if (!$this->render_gate()) {
            return;
        }
        if (!$this->check_token()) {
            $this->token_fail();
            return;
        }

        require_once __DIR__ . '/lib/store.php';
        require_once __DIR__ . '/lib/farm.php';

        $do     = (string) rcube_utils::get_input_value('_do', rcube_utils::INPUT_POST);
        $cu     = (string) rcube_utils::get_input_value('_cu', rcube_utils::INPUT_POST);
        $u      = strtolower(trim((string) rcube_utils::get_input_value('_u', rcube_utils::INPUT_POST)));
        $pwd    = (string) rcube_utils::get_input_value('_p', rcube_utils::INPUT_POST);
        $shares = (array) rcube_utils::get_input_value('_share', rcube_utils::INPUT_POST);

        // jaga-jaga: username tak terkirim pada mode edit -> pakai _cu
        if ($u === '' && $cu !== '') {
            $u = strtolower(trim($cu));
        }

        // Klien SELALU akses hanya-baca — tidak ada opsi di form.
        $ro = true;

        // _home boleh: (a) nama perusahaan saja — diresolusi ke bawah
        // folder client_home_default; (b) path relatif terhadap mount
        // user; atau (c) absolut. Hasil akhir selalu absolut.
        $home = trim((string) rcube_utils::get_input_value('_home', rcube_utils::INPUT_POST));
        if ($home !== '' && $home[0] !== '/') {
            $def  = rtrim($this->client_home_default(), '/');
            $cand = $def !== '' ? $def . '/' . $home : '';
            if ($cand !== '' && is_dir($cand)) {
                $home = $cand;
            } else {
                $home = rtrim($this->staff_root(), '/') . '/' . $home;
            }
        }
        $home = rtrim($home, '/');

        /* ---------- PINDAI ---------- */
        if ($do === 'scan') {
            $real = realpath($home);
            $root = rtrim($this->staff_root(), '/');
            if ($real === false || !is_dir($real)
                || strpos($real . '/', $root . '/') !== 0 || $real === $root) {
                $this->rc->output->show_message('err_invalid_home', 'error');
                $this->render_clients([
                    'edit' => null, 'u' => $u, 'home' => $home, 'ro' => $ro,
                    'years' => null, 'checked' => [],
                ]);
                return;
            }
            $years   = $this->scan_years($real);
            $checked = [];
            foreach ($years as $y => $months) {
                // key checkbox: "TAHUN/BULAN" atau "TAHUN" (legacy)
                foreach ($months as $m => $has) {
                    $checked[$m === '' ? $y : $y . '/' . $m] = $has;
                }
            }
            $this->render_clients([
                // 'p': bawa password ketikan user melewati putaran Pindai
                // (render di-request yang sama; tak pernah ke DB/log)
                'edit' => null, 'u' => $u, 'home' => $real, 'ro' => $ro,
                'p' => $pwd, 'years' => $years, 'checked' => $checked,
            ], 'msg_scan_ok');
            return;
        }

        /* ---------- SIMPAN ---------- */
        $err      = null;
        $realHome = false;
        $reals    = [];

        do {
            // huruf kecil/angkat; titik, garis bawah & strip hanya di tengah
            if (!preg_match('/^[a-z0-9](?:[a-z0-9._-]{1,62}[a-z0-9])$/', $u)) {
                $err = 'err_invalid_user';
                break;
            }
            // unik: tolak bila username sudah dipakai baris LAIN
            // (create duplikat maupun rename ke nama yang sudah ada);
            // tanpa ini ON DUPLICATE KEY UPDATE akan menimpa diam-diam.
            $existing = fm_store::get($u);
            if ($existing !== null && (string) $existing['username'] !== $cu) {
                $err = 'err_user_exists';
                break;
            }
            $old = $cu !== '' ? fm_store::get($cu) : null;
            if ($cu !== '' && !$old) {
                $err = 'err_invalid_user';
                break;
            }
            if ($pwd !== '') {
                if (strlen($pwd) < 8) {
                    $err = 'err_invalid_pass';
                    break;
                }
                $hash = password_hash($pwd, PASSWORD_BCRYPT);
            } elseif ($old) {
                $hash = (string) $old['hash']; // kosong = tetap pakai lama
            } else {
                $err = 'err_invalid_pass';
                break;
            }
            $realHome = realpath($home);
            $root     = rtrim($this->staff_root(), '/');
            if ($realHome === false || !is_dir($realHome)
                || strpos($realHome . '/', $root . '/') !== 0 || $realHome === $root) {
                $err = 'err_invalid_home';
                break;
            }
            // unik: 1 akun = 1 perusahaan. Tolak bila perusahaan sudah
            // dipakai akun LAIN (edit milik sendiri tetap boleh).
            $byHome = fm_store::get_by_home($realHome);
            if ($byHome !== null && (string) $byHome['username'] !== $u) {
                $err = 'err_home_exists';
                break;
            }
            foreach ($shares as $segRaw) {
                // nilai checkbox: "TAHUN/BULAN" (resmi) atau "TAHUN" (legacy)
                $segRaw = trim((string) $segRaw);
                if ($segRaw === '') {
                    continue;
                }
                if (strpos($segRaw, '/') !== false) {
                    list($y, $b) = explode('/', $segRaw, 2);
                    if (!self::valid_seg($y) || !self::valid_seg($b)) {
                        continue;
                    }
                    $rel   = $y . '/' . $b;
                    $label = $y . '-' . $b;
                } else {
                    if (!self::valid_seg($segRaw)) {
                        continue;
                    }
                    $rel   = $segRaw;
                    $label = $segRaw;
                }
                $target = $realHome . '/' . $rel . '/PDF';
                if (!is_dir($target)) {
                    @mkdir($target, 02770, true); // auto-create PDF
                }
                $r = realpath($target);
                if ($r !== false && is_dir($r)) {
                    $reals[$label] = $r; // map label unik => path
                }
            }
            if (!count($reals)) {
                $err = 'err_need_share';
                break;
            }

            try {
                $row = [
                    'username' => $u,
                    'hash'     => $hash,
                    'home'     => $realHome,
                    // map label unik ("2026-JANUARI") => path PDF absolut
                    'paths'    => json_encode(
                        $reals,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    'readonly' => $ro,
                ];
                $actor  = (string) $this->rc->user->get_username();
                $rename = ($cu !== '' && $u !== $cu && $old);
                if ($cu === '' || $rename) {
                    fm_store::insert($row, $actor);
                } else {
                    fm_store::update($cu, $row, $actor);
                }
                fm_farm::build($u, $realHome, $reals, $this->client_base());
            } catch (Exception $e) {
                // pelanggaran UNIQUE di DB (lapisan terakhir) diterjemahkan
                // menjadi notifikasi yang jelas, bukan error generik
                $msg = $e->getMessage();
                if (strpos($msg, 'uq_home') !== false) {
                    $err = 'err_home_exists';
                } elseif (strpos($msg, 'PRIMARY') !== false
                    || strpos($msg, "'username'") !== false) {
                    $err = 'err_user_exists';
                } else {
                    $err = 'err_db';
                }
                break;
            }
        } while (false);

        if ($err !== null) {
            // simpan nilai terisi agar user tidak mengetik ulang semuanya
            $_SESSION['fm_client_form'] = [
                'u' => $u, 'home' => $home, 'ro' => $ro, 'p' => $pwd,
                'years'   => $realHome ? $this->scan_years($realHome) : null,
                'checked' => array_fill_keys(
                    array_map('trim', array_filter(array_map('strval', $shares))),
                    true
                ),
            ];
            $this->rc->output->show_message($err, 'error');
            $this->rc->output->redirect(['_task' => 'filemanager', '_action' => 'clients']);
            $this->rc->output->send('filemanager.clients');
            return;
        }

        // rename: pindahkan farm lama -> nama baru
        if ($cu !== '' && $cu !== $u && $old) {
            fm_farm::remove($cu, (string) $old['home'], $this->client_base());
            fm_store::delete($cu);
        }

        $this->rc->output->show_message('msg_saved', 'confirmation');
        // _saved=1 + timestamp: URL unik -> Chrome tidak memulihkan isi
        // form lama; server juga memaksa form kosong
        $this->rc->output->redirect([
            '_task' => 'filemanager',
            '_action' => 'clients',
            '_saved' => '1',
            '_t' => time(),
        ]);
        $this->rc->output->send('filemanager.clients');
    }

    /**
     * Simpan pengaturan folder induk perusahaan (panel kanan form).
     * Nilai relatif terhadap mount user; kosong = kembali ke config.
     */
    public function action_clients_settings()
    {
        if (!$this->render_gate()) {
            return;
        }
        if (!$this->check_token()) {
            $this->token_fail();
            return;
        }

        require_once __DIR__ . '/lib/store.php';

        $val  = trim((string) rcube_utils::get_input_value(
            '_chd',
            rcube_utils::INPUT_POST
        ));
        $val  = trim($val, "/ \t");
        $err  = null;

        if ($val !== '') {
            foreach (explode('/', $val) as $seg) {
                if ($seg === '' || $seg === '.' || $seg === '..'
                    || strpos($seg, '\\') !== false
                    || strpos($seg, "\0") !== false) {
                    $err = 'err_invalid_home';
                    break;
                }
            }
            if ($err === null && !is_dir(
                rtrim($this->staff_root(), '/') . '/' . $val
            )) {
                $err = 'err_invalid_home';
            }
        }

        if ($err !== null) {
            $this->rc->output->show_message($err, 'error');
        } else {
            try {
                fm_store::set_setting(
                    'client_home_default',
                    $val,
                    (string) $this->rc->user->get_username()
                );
                $this->rc->output->show_message('msg_settings_saved', 'confirmation');
            } catch (Exception $e) {
                $this->rc->output->show_message('err_db', 'error');
            }
        }

        $this->rc->output->redirect(['_task' => 'filemanager', '_action' => 'clients']);
        $this->rc->output->send('filemanager.clients');
    }

    public function action_clients_delete()
    {        if (!$this->render_gate()) {
            return;
        }
        if (!$this->check_token()) {
            $this->token_fail();
            return;
        }

        require_once __DIR__ . '/lib/store.php';
        require_once __DIR__ . '/lib/farm.php';

        $del = (string) rcube_utils::get_input_value('_del', rcube_utils::INPUT_POST);
        $row = fm_store::get($del);
        if ($row) {
            fm_store::delete($del);
            fm_farm::remove($del, (string) $row['home'], $this->client_base());
        }

        $this->rc->output->show_message('msg_deleted', 'confirmation');
        $this->rc->output->redirect([
            '_task' => 'filemanager',
            '_action' => 'clients',
            '_saved' => '1',
            '_t' => time(),
        ]);
        $this->rc->output->send('filemanager.clients');
    }

    /**
     * Template object halaman Kelola Klien: form tambah/edit + tabel daftar.
     * Nilai diambil dari $this->clients_ctx (diisi render_clients).
     */
    public function tpl_clients()
    {
        $ctx   = $this->clients_ctx;
        $Q     = ['rcube', 'Q'];
        $edit  = $ctx['edit'];
        $token = isset($_SESSION['request_token']) ? $_SESSION['request_token'] : '';
        $root  = rtrim($this->staff_root(), '/');

        $g = function ($key) {
            return rcube::Q($this->gettext($key));
        };
        $q = function ($v) {
            return rcube::Q((string) $v);
        };

        /* Dropdown pilihan perusahaan: anak-anak folder induk
         * client_home_default. Input cukup berisi nama perusahaan —
         * normalisasi di action_clients_save yang melengkapi path.
         * Datalist native tidak reliabel lintas-browser — diganti menu
         * kustom yang selalu bisa dibuka via tombol. */
        $defDir   = $this->client_home_default();
        $folders  = [];
        if ($defDir !== '' && basename($defDir) !== '' && is_dir($defDir)) {
            foreach ((array) @scandir($defDir) as $name) {
                if ($name === '.' || $name === '..' || $name[0] === '.') {
                    continue;
                }
                if (is_dir($defDir . '/' . $name)) {
                    $folders[] = ['name' => $name, 'path' => $name];
                }
            }
        }
        usort($folders, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        /* ---- data klien (untuk tabel & statistik) ---- */
        $rows = [];
        try {
            $rows = fm_store::all();
        } catch (Exception $e) {
            $rows = [];
        }
        $statShares = 0;
        foreach ($rows as $r0) {
            $statShares += count((array) json_decode((string) $r0['paths'], true));
        }

        /* layout dua kolom: utama (form+tabel) | samping (statistik+setelan) */
        $html  = '<div class="header"><span class="header-title">'
            . $g('manageclients') . '</span></div><div class="scroller fm-clients">'
            . '<div class="fm-cols"><div class="fm-col-main">';

        if ($edit) {
            $html .= '<div class="fm-note">' . $g('clientform_edit')
                . ': <b>' . $q($edit['username']) . '</b>'
                . ' &mdash; <span class="fm-muted">'
                . $g('pwd_hint_edit') . '</span></div>';
        }

        /* picker folder HOME: input + tombol buka menu + menu filterable.
         * Grid dua kolom: kiri = identitas akun, kanan = share per bulan;
         * tombol aksi full-width di bawah grid. */
        $html .= '<form method="post" action="?_task=filemanager&amp;_action=clients_save" class="fm-form">'
            . '<input type="hidden" name="_token" value="' . $q($token) . '">'
            . '<input type="hidden" name="_cu" value="' . $q($edit ? $edit['username'] : '') . '">'
            . '<div class="fm-form-grid"><div class="fm-form-main">'

            . '<label for="fm-home">' . $g('field_home') . '</label>'
            . '<div class="fm-home-picker">'
            . '<div class="fm-pwd-row">'
            . '<input type="text" id="fm-home" name="_home"'
            . ' value="' . $q($this->home_display($ctx['home'])) . '"'
            . ' autocomplete="off" spellcheck="false"'
            . ($edit ? '' : ' required')
            . ' placeholder="' . $g('placeholder_company') . '">'
            . '<button type="button" class="fm-home-toggle btn btn-secondary"'
            . ' aria-label="' . $g('choose_folder') . '" aria-expanded="false" aria-haspopup="listbox"'
            . (!count($folders) ? ' disabled' : '')
            . '><i class="fa fa-chevron-down" aria-hidden="true"></i></button>'
            . '</div>'
            . '<div class="fm-home-menu" hidden>'
            . '<input type="text" class="fm-home-filter form-control"'
            . ' placeholder="' . $g('filter_folders') . '" aria-label="' . $g('filter_folders') . '">'
            . '<ul role="listbox">';
        foreach ($folders as $f) {
            $html .= '<li role="option"><button type="button" data-path="' . $q($f['path']) . '">'
                . '<i class="fa fa-folder" aria-hidden="true"></i> ' . $q($f['name'])
                . '</button></li>';
        }
        if (!count($folders)) {
            $html .= '<li class="fm-hint">' . $g('none_found') . '</li>';
        }
        $html .= '</ul></div></div>'

            . '<label for="fm-user">' . $g('field_username') . '</label>'
            . '<input type="text" id="fm-user" name="_u" size="32" maxlength="64"'
            . ' value="' . $q($ctx['u']) . '"'
            . ' autocomplete="off" spellcheck="false"'
            // kompatibel regex flag "v" Chrome: '-' di class wajib \-
            . ' pattern="[a-z0-9][a-z0-9._\\-]{1,62}[a-z0-9]"'
            . ' title="' . $g('username_help') . '"'
            // readonly (bukan disabled): nilai tetap ter-submit saat edit
            . ($edit ? ' readonly' : ' required') . '>'
            . '<div class="fm-hint fm-input-help">' . $g('username_help') . '</div>'

            . '<label for="fm-pwd">' . $g('field_password') . '</label>'
            . '<span class="fm-pwd-row">'
            . '<input type="text" id="fm-pwd" name="_p" size="32" autocomplete="off"'
            . ' spellcheck="false"'
            // create: wajib min 8; edit: opsional (kosong = pakai lama)
            . ($edit ? '' : ' required minlength="8"')
            . ' value="' . $q(isset($ctx['p']) ? $ctx['p'] : '') . '">'
            . '<button type="button" id="fm-genpwd" class="btn btn-secondary" aria-label="' . $g('btn_generate') . '">' . $g('btn_generate') . '</button>'
            . '</span>'

            // tutup kolom kiri, buka kolom kanan (share per bulan)
            . '</div><div class="fm-form-aside">';

        /* area checkbox share: dikelompokkan per tahun, unit = bulan */
        $html .= '<fieldset class="fm-shares"><legend>' . $g('field_shares') . '</legend>';
        if (!is_array($ctx['years']) || !count($ctx['years'])) {
            $html .= '<p class="fm-hint">' . $g('shares_hint') . '</p>';
        } else {
            foreach ($ctx['years'] as $year => $months) {
                $html .= '<div class="fm-share-year"><b>'
                    . $q($year) . '</b>';
                foreach ($months as $month => $hasPdf) {
                    // nilai "TAHUN/BULAN"; legacy PDF-langsung = "TAHUN"
                    $val  = $month === '' ? (string) $year : $year . '/' . $month;
                    $name = $month === '' ? $year : $year . ' &ndash; ' . $month;
                    $checked = !empty($ctx['checked'][$val]);
                    $html .= '<label class="fm-check"><input type="checkbox"'
                        . ' name="_share[]" value="' . $q($val) . '"'
                        . ($checked ? ' checked' : '') . '> '
                        . $name
                        . ' <span class="fm-badge ' . ($hasPdf ? 'ok' : 'new') . '">'
                        . ($hasPdf ? $g('share_exists') : $g('share_create'))
                        . '</span></label>';
                }
                $html .= '</div>';
            }
        }
        // tombol Pindai menempel di dasar fieldset-nya sendiri
        $html .= '<div class="fm-actions fm-scan-actions">'
            . '<button type="submit" name="_do" value="scan" class="btn btn-secondary">'
            . $g('btn_scan') . '</button></div>'
            . '</fieldset>';

        // tutup grid, tombol aksi full-width di bawahnya
        $html .= '</div></div>';

        $html .= '<div class="fm-actions">'
            . '<button type="submit" name="_do" value="save" class="btn btn-primary mainaction">'
            . $g('btn_save') . '</button>'
            . ' <a href="?_task=filemanager&amp;_action=clients&amp;_saved=1"'
            . ' class="btn btn-link fm-cancel">' . $g('btn_cancel') . '</a>'
            . '</div></form>';

        /* tutup kolom utama, lalu kolom samping dalam baris yang sama */
        $html .= '</div>';

        $html .= '<div class="fm-col-side">'

            . '<div class="fm-card fm-side-card"><h3 class="fm-side-title">'
            . $g('stats_title') . '</h3><ul class="fm-stat-list">'
            . '<li><span>' . $g('stat_companies') . '</span><b>' . count($folders) . '</b></li>'
            . '<li><span>' . $g('stat_accounts') . '</span><b>' . count($rows) . '</b></li>'
            . '<li><span>' . $g('stat_shares') . '</span><b>' . $statShares . '</b></li>'
            . '</ul></div>'

            . '<div class="fm-card fm-side-card">'
            . '<form method="post" action="?_task=filemanager&amp;_action=clients_settings"'
            . ' class="fm-form fm-form-settings">'
            . '<input type="hidden" name="_token" value="' . $q($token) . '">'
            . '<label for="fm-chd">' . $g('setting_chd_label') . '</label>'
            . '<input type="text" id="fm-chd" name="_chd"'
            . ' value="' . $q($this->client_home_default_raw()) . '"'
            . ' autocomplete="off" spellcheck="false"'
            . ' placeholder="' . $g('placeholder_parent') . '">'
            . '<div class="fm-hint fm-input-help">' . $g('setting_chd_help') . '</div>'
            . '<div class="fm-actions"><button type="submit"'
            . ' class="btn btn-primary mainaction">' . $g('btn_save') . '</button></div>'
            . '</form></div>'

            . '</div></div>';

        /* ---- tabel daftar klien: full width di bawah ---- */
        $html .= '<div class="fm-table-tools">'
            . '<input type="text" id="fm-table-search" class="form-control"'
            . ' placeholder="' . $g('search_accounts') . '"'
            . ' aria-label="' . $g('search_accounts') . '"'
            . ' autocomplete="off" spellcheck="false">'
            . '<span class="fm-hint" id="fm-table-count" aria-live="polite"></span>'
            . '</div>'
            . '<div class="fm-card fm-card-table"><div class="fm-table-wrap">'
            . '<table class="fm-clients-table" id="fm-clients-table"><thead><tr>'
            . '<th>' . $g('field_username') . '</th>'
            . '<th>' . $g('field_home') . '</th>'
            . '<th>' . $g('col_shares') . '</th>'
            . '<th>' . $g('field_readonly') . '</th>'
            . '<th>' . $g('col_updated') . '</th>'
            . '<th></th></tr></thead><tbody>';

        if (!count($rows)) {
            $html .= '<tr class="fm-empty-row"><td colspan="6" class="fm-hint">'
                . $g('none_configured') . '</td></tr>';
        }
        foreach ($rows as $r) {
            $cnt   = count((array) json_decode((string) $r['paths'], true));
            $uEnc  = rawurlencode((string) $r['username']);
            // tampilan path tanpa prefix folder induk default (sudah
            // menjadi konfigurasi); fallback: full path
            $homeDisp = $this->home_display($r['home']);
            $dForm = '<form method="post" action="?_task=filemanager&amp;_action=clients_delete" class="fm-inline">'
                . '<input type="hidden" name="_token" value="' . $q($token) . '">'
                . '<input type="hidden" name="_del" value="' . $q($r['username']) . '">'
                . '<button type="submit" class="btn btn-danger fm-del"'
                . ' data-confirm="' . $g('confirm_delete') . '">'
                . $g('btn_delete') . '</button></form>';
            $html .= '<tr>'
                . '<td><b>' . $q($r['username']) . '</b></td>'
                . '<td class="fm-path">' . $q($homeDisp) . '</td>'
                . '<td>' . (int) $cnt . '</td>'
                . '<td>' . (!empty($r['readonly']) ? '&#10003;' : '&ndash;') . '</td>'
                . '<td>' . $q($r['updated_at']) . '</td>'
                . '<td><a href="?_task=filemanager&amp;_action=clients&amp;_edit=' . $uEnc
                . '">' . $g('btn_edit') . '</a> ' . $dForm . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        return $html . '</div>';
    }
}
