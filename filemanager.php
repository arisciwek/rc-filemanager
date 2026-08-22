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
        $gateway = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/filemanager.php';

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
        $gateway = rtrim($this->sidebar_ctx['gateway'], '/');
        $root    = rtrim($this->sidebar_ctx['root'], '/');
        $nodes   = is_dir($root) ? $this->tree_nodes($root, '') : [];

        $out = '<div class="header">'
            . '<span class="header-title">' . rcube::Q($this->gettext('filemanager.navtitle')) . '</span>'
            . '</div><div class="scroller">'

            // -- pohon folder --
            . '<ul class="listing" id="filemanager-tree" role="tree">'
            . '<li class="fm-node fm-expanded fm-haschildren" data-path="" data-loaded="1">'
            . '<div class="fm-row"><span class="fm-toggle" role="button" tabindex="0">'
            . '<i class="fa fa-angle-down"></i></span>'
            . '<a href="' . rcube::Q($gateway . '?p=') . '" target="filemanager-frame">'
            . '<i class="fa fa-folder-open"></i><span class="inner">'
            . rcube::Q($this->gettext('myfiles')) . '</span></a></div>'
            . '<ul class="fm-children">';
        foreach ($nodes as $n) {
            $out .= $this->node_li($gateway, $n);
        }
        $out .= '</ul></li></ul>';

        // -- pintasan terpisah --
        $out .= '<ul class="listing" id="filemanager-shortcuts">';

        // Kelola Klien — hanya untuk manager (DISCUSS.md #5)
        if ($this->is_manager()) {
            $out .= '<li><a href="' . rcube::Q('?_task=filemanager&amp;_action=clients')
                . '"><i class="fa fa-users"></i><span class="inner">'
                . rcube::Q($this->gettext('manageclients'))
                . '</span></a></li>';
        }

        foreach ([['shared', 'share-alt', 'shared'], ['.trash', 'trash', 'trash']] as $s) {
            if (!is_dir($root . '/' . $s[0])) {
                continue;
            }
            $out .= '<li><a href="' . rcube::Q($gateway . '?p=' . rawurlencode($s[0]))
                . '" target="filemanager-frame"><i class="fa fa-' . $s[1]
                . '"></i><span class="inner">' . rcube::Q($this->gettext($s[2]))
                . '</span></a></li>';
        }
        $out .= '</ul></div>';

        return $out;
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
            $html .= '<span class="fm-toggle" role="button" tabindex="0">'
                . '<i class="fa fa-angle-right"></i></span>';
        }
        $html .= '<a href="' . rcube::Q($href) . '" target="filemanager-frame">'
            . '<i class="fa fa-folder"></i><span class="inner">' . rcube::Q($node['name'])
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
     * Pindai anak langsung HOME: [nama => folder PDF sudah ada?].
     * Entri berawalan titik dilewati.
     */
    private function scan_years($home)
    {
        $out = [];
        foreach ((array) @scandir($home) as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            if (!is_dir($home . '/' . $name)) {
                continue;
            }
            $out[$name] = is_dir($home . '/' . $name . '/PDF');
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
            'edit' => null, 'u' => '', 'home' => '', 'ro' => false,
            'years' => null, 'checked' => [],
        ];

        // refill hasil submit yang gagal validasi (flash session)
        if (isset($_SESSION['fm_client_form']) && is_array($_SESSION['fm_client_form'])) {
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
                $labels = [];
                foreach ((array) json_decode((string) $row['paths'], true) as $p) {
                    $r = realpath((string) $p);
                    if ($r !== false) {
                        $labels[basename(dirname($r))] = true;
                    }
                }
                foreach ($ctx['years'] as $y => $has) {
                    $ctx['checked'][$y] = isset($labels[$y]);
                }
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
        $u      = trim((string) rcube_utils::get_input_value('_u', rcube_utils::INPUT_POST));
        $pwd    = (string) rcube_utils::get_input_value('_p', rcube_utils::INPUT_POST);
        $home   = rtrim((string) rcube_utils::get_input_value('_home', rcube_utils::INPUT_POST), '/');
        $ro     = (bool) rcube_utils::get_input_value('_ro', rcube_utils::INPUT_POST);
        $shares = (array) rcube_utils::get_input_value('_share', rcube_utils::INPUT_POST);

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
            foreach ($years as $y => $has) {
                $checked[$y] = $has; // precheck bila PDF sudah ada
            }
            $this->render_clients([
                'edit' => null, 'u' => $u, 'home' => $real, 'ro' => $ro,
                'years' => $years, 'checked' => $checked,
            ], 'msg_scan_ok');
            return;
        }

        /* ---------- SIMPAN ---------- */
        $err      = null;
        $realHome = false;
        $reals    = [];

        do {
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $u)) {
                $err = 'err_invalid_user';
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
            foreach ($shares as $seg) {
                if (!self::valid_seg((string) $seg)) {
                    continue;
                }
                $target = $realHome . '/' . $seg . '/PDF';
                if (!is_dir($target)) {
                    @mkdir($target, 2770, true); // auto-create PDF
                }
                $r = realpath($target);
                if ($r !== false && is_dir($r)) {
                    $reals[] = $r;
                }
            }
            if (!count($reals)) {
                $err = 'err_need_share';
                break;
            }

            try {
                fm_store::save([
                    'username' => $u,
                    'hash'     => $hash,
                    'home'     => $realHome,
                    'paths'    => json_encode(
                        array_values(array_unique($reals)),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                    ),
                    'readonly' => $ro,
                ], (string) $this->rc->user->get_username());
                fm_farm::build($u, $realHome, $reals, $this->client_base());
            } catch (Exception $e) {
                $err = 'err_db';
                break;
            }
        } while (false);

        if ($err !== null) {
            // simpan nilai terisi agar user tidak mengetik ulang semuanya
            $_SESSION['fm_client_form'] = [
                'u' => $u, 'home' => $home, 'ro' => $ro,
                'years'   => $realHome ? $this->scan_years($realHome) : null,
                'checked' => array_fill_keys(
                    array_values(array_filter($shares, [self::class, 'valid_seg'])),
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
        $this->rc->output->redirect(['_task' => 'filemanager', '_action' => 'clients']);
        $this->rc->output->send('filemanager.clients');
    }

    public function action_clients_delete()
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

        $del = (string) rcube_utils::get_input_value('_del', rcube_utils::INPUT_POST);
        $row = fm_store::get($del);
        if ($row) {
            fm_store::delete($del);
            fm_farm::remove($del, (string) $row['home'], $this->client_base());
        }

        $this->rc->output->show_message('msg_deleted', 'confirmation');
        $this->rc->output->redirect(['_task' => 'filemanager', '_action' => 'clients']);
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

        /* datalist: folder level-1 mount admin sbg hint cepat */
        $hints = '';
        foreach ($this->tree_nodes($root, '') as $n) {
            $hints .= '<option value="' . $q($root . '/' . $n['name']) . '"></option>';
        }

        $html  = '<div class="header"><span class="header-title">'
            . $g('manageclients') . '</span></div><div class="scroller">';

        if ($edit) {
            $html .= '<div class="fm-note">' . $g('clientform_edit')
                . ': <b>' . $q($edit['username']) . '</b>'
                . ' &mdash; <span class="fm-muted">'
                . $g('pwd_hint_edit') . '</span></div>';
        }

        $html .= '<form method="post" action="?_task=filemanager&amp;_action=clients_save" class="fm-form">'
            . '<input type="hidden" name="_token" value="' . $q($token) . '">'
            . '<input type="hidden" name="_cu" value="' . $q($edit ? $edit['username'] : '') . '">'

            . '<label for="fm-home">' . $g('field_home') . '</label>'
            . '<input type="text" id="fm-home" name="_home" size="70"'
            . ' value="' . $q($ctx['home']) . '" list="fm-hints"'
            . ' placeholder="' . $q($root . '/CMJ_2026/1. PERUSAHAAN/PT. KLIEN') . '">'
            . '<datalist id="fm-hints">' . $hints . '</datalist>'

            . '<label for="fm-user">' . $g('field_username') . '</label>'
            . '<input type="text" id="fm-user" name="_u" size="32" maxlength="64"'
            . ' value="' . $q($ctx['u']) . '"'
            . ($edit ? ' disabled' : '') . '>'

            . '<label for="fm-pwd">' . $g('field_password') . '</label>'
            . '<span class="fm-pwd-row">'
            . '<input type="text" id="fm-pwd" name="_p" size="32" autocomplete="off">'
            . '<button type="button" onclick="fmGenPwd()">' . $g('btn_generate') . '</button>'
            . '</span>'

            . '<label class="fm-check"><input type="checkbox" name="_ro" value="1"'
            . (!empty($ctx['ro']) ? ' checked' : '') . '> ' . $g('field_readonly') . '</label>';

        /* area checkbox tahun */
        $html .= '<fieldset class="fm-shares"><legend>' . $g('field_shares') . '</legend>';
        if (!is_array($ctx['years']) || !count($ctx['years'])) {
            $html .= '<p class="fm-hint">' . $g('shares_hint') . '</p>';
        } else {
            foreach ($ctx['years'] as $year => $hasPdf) {
                $checked = !empty($ctx['checked'][$year]);
                $html .= '<label class="fm-check"><input type="checkbox"'
                    . ' name="_share[]" value="' . $q($year) . '"'
                    . ($checked ? ' checked' : '') . '> ' . $q($year)
                    . ' <span class="fm-badge ' . ($hasPdf ? 'ok' : 'new') . '">'
                    . ($hasPdf ? $g('share_exists') : $g('share_create'))
                    . '</span></label>';
            }
        }
        $html .= '</fieldset>';

        $html .= '<div class="fm-actions">'
            . '<button type="submit" name="_do" value="scan">' . $g('btn_scan') . '</button>'
            . '<button type="submit" name="_do" value="save" class="main action">'
            . $g('btn_save') . '</button>'
            . ' <a href="?_task=filemanager&amp;_action=clients">' . $g('btn_cancel') . '</a>'
            . '</div></form><hr>';

        /* ---- tabel daftar klien ---- */
        $rows = [];
        try {
            $rows = fm_store::all();
        } catch (Exception $e) {
            $rows = [];
        }

        $html .= '<table class="fm-clients-table"><thead><tr>'
            . '<th>' . $g('field_username') . '</th>'
            . '<th>' . $g('field_home') . '</th>'
            . '<th>' . $g('col_shares') . '</th>'
            . '<th>' . $g('field_readonly') . '</th>'
            . '<th>' . $g('col_updated') . '</th>'
            . '<th></th></tr></thead><tbody>';

        if (!count($rows)) {
            $html .= '<tr><td colspan="6" class="fm-hint">' . $g('none_configured')
                . '</td></tr>';
        }
        foreach ($rows as $r) {
            $cnt   = count((array) json_decode((string) $r['paths'], true));
            $uEnc  = rawurlencode((string) $r['username']);
            $dForm = '<form method="post" action="?_task=filemanager&amp;_action=clients_delete" class="fm-inline">'
                . '<input type="hidden" name="_token" value="' . $q($token) . '">'
                . '<input type="hidden" name="_del" value="' . $q($r['username']) . '">'
                . '<button type="submit" class="delete"'
                . ' onclick="return confirm(\'' . $g('confirm_delete') . '\')">'
                . $g('btn_delete') . '</button></form>';
            $html .= '<tr>'
                . '<td><b>' . $q($r['username']) . '</b></td>'
                . '<td class="fm-path">' . $q($r['home']) . '</td>'
                . '<td>' . (int) $cnt . '</td>'
                . '<td>' . (!empty($r['readonly']) ? '&#10003;' : '&ndash;') . '</td>'
                . '<td>' . $q($r['updated_at']) . '</td>'
                . '<td><a href="?_task=filemanager&amp;_action=clients&amp;_edit=' . $uEnc
                . '">' . $g('btn_edit') . '</a> ' . $dForm . '</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<script>'
            . 'function fmGenPwd(){var c="ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789",'
            . 'n=16,s="";for(var i=0;i<n;i++){s+=c[Math.floor(Math.random()*c.length]);}'
            . 'document.getElementById("fm-pwd").value=s;}'
            . '</script>';

        return $html . '</div>';
    }
}
