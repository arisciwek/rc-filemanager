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
     * Render halaman Filemanager (layout Elastic berisi iframe ke gateway).
     *
     * Gateway /filemanager.php yang menjalankan engine TinyFileManager:
     * - sesi Roundcube valid  -> mode staff (SSO, tanpa login)
     * - tanpa sesi Roundcube  -> mode klien (form login bawaan engine)
     */
    public function action_index()
    {
        $this->rc->output->set_pagetitle(
            $this->gettext('filemanager.navtitle')
        );

        $this->rc->output->send('filemanager.filemanager');
    }
}
