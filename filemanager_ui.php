<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/filemanager_ui.php
 * Path    : /var/www/roundcube/plugins/filemanager/filemanager_ui.php
 *
 * Kelas handler UI untuk plugin Filemanager.
 * Menangani:
 * - Taskbar button (menu #taskmenu skin Elastic)
 * - Plugin stylesheet (skins/<skin>/filemanager.css)
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 * @subpackage UI
 * @version    @package_version@
 */

class filemanager_ui
{
    private $rc;
    private $plugin;
    private $ready = false;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        $this->rc     = $plugin->rc;
    }

    /**
     * Initialize plugin UI.
     */
    public function init()
    {
        if ($this->ready) {
            return;
        }

        // Add taskbar button
        $this->plugin->add_button([
            'command'    => 'filemanager',
            'class'      => 'button-filemanager',
            'classsel'   => 'button-filemanager button-selected',
            'innerclass' => 'inner',
            'label'      => 'filemanager.navtitle',
            'domain'     => 'filemanager',
            'type'       => 'link',
        ], 'taskbar');

        // Load skin-specific stylesheet
        $this->plugin->include_stylesheet(
            $this->plugin->local_skin_path() . '/filemanager.css'
        );

        $this->ready = true;
    }
}
