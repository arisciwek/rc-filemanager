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
}
