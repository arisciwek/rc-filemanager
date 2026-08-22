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
 * - Blank template
 *
 * Struktur direktori:
 *   filemanager/
 *   ├── filemanager.php              -> Kelas plugin utama (file ini)
 *   ├── filemanager_ui.php           -> Handler UI (taskbar button & stylesheet)
 *   ├── localization/                -> Terjemahan label (en_US, id_ID)
 *   └── skins/elastic/
 *       ├── filemanager.css          -> Style tombol taskmenu (ikon)
 *       ├── images/filemanager.png   -> Gambar ikon menu
 *       └── templates/minimal.html   -> Template halaman kosong
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
     * Render blank filemanager page.
     */
    public function action_index()
    {
        $this->rc->output->set_pagetitle(
            $this->gettext('filemanager.navtitle')
        );

        $this->rc->output->send('filemanager.minimal');
    }
}
