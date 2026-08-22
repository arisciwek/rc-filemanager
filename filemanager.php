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

        // Initialize UI
        $this->ui = new filemanager_ui($this);
        $this->ui->init();
    }

    /**
     * Render halaman Filemanager: layout Elastic tiga kolom
     * (menu bawaan + sidebar pintasan folder + iframe engine).
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
        $scheme   = $https ? 'https' : 'http';
        $gateway  = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/filemanager.php';

        // Root folder staff — dipakai untuk item sidebar dinamis.
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
        $root      = $base . '/' . preg_replace('/[^a-z0-9._-]/', '', $localpart);

        $this->sidebar_ctx = ['gateway' => $gateway, 'root' => $root];

        $this->rc->output->set_env('gateway_url', $gateway);
        $this->rc->output->add_handler('filemanager_sidebar', [$this, 'tpl_sidebar']);
        $this->rc->output->set_pagetitle($this->gettext('filemanager.navtitle'));

        $this->rc->output->send('filemanager.filemanager');
    }

    /**
     * Template object <roundcube:object name="filemanager_sidebar" />:
     * daftar pintasan folder pada #layout-sidebar. Item hanya muncul bila
     * foldernya benar-benar ada pada root staff. Klik membuka path di dalam
     * iframe via atribut target HTML (tanpa JS).
     */
    public function tpl_sidebar()
    {
        $gateway = rtrim($this->sidebar_ctx['gateway'], '/');
        $root    = rtrim($this->sidebar_ctx['root'], '/');

        $items = [
            ['p' => '',       'dir' => null,     'icon' => 'folder-open', 'label' => $this->gettext('myfiles')],
            ['p' => 'shared', 'dir' => 'shared', 'icon' => 'share-alt',   'label' => $this->gettext('shared')],
            ['p' => '.trash', 'dir' => '.trash', 'icon' => 'trash',       'label' => $this->gettext('trash')],
        ];

        $out = '<div class="header">'
            . '<span class="header-title">' . rcube::Q($this->gettext('filemanager.navtitle')) . '</span>'
            . '</div><div class="scroller"><ul class="listing" id="filemanager-folders">';

        foreach ($items as $it) {
            if ($it['dir'] !== null && !is_dir($root . '/' . $it['dir'])) {
                continue;
            }
            $href = $gateway . '?p=' . rawurlencode($it['p']);
            $out .= '<li><a href="' . rcube::Q($href) . '" target="filemanager-frame">'
                . '<i class="fa fa-' . $it['icon'] . '" aria-hidden="true"></i>'
                . '<span class="inner">' . rcube::Q($it['label']) . '</span>'
                . '</a></li>';
        }

        return $out . '</ul></div>';
    }
}
