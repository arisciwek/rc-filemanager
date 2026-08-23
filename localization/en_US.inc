<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/localization/en_US.inc
 * Path    : /var/www/roundcube/plugins/filemanager/localization/en_US.inc
 *
 * Label terjemahan bahasa Inggris (default).
 * Dimuat otomatis oleh $this->add_texts('localization/', false)
 * dan diakses via $this->gettext('filemanager.navtitle').
 *
 * @author     ArisCiwek
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 * @subpackage Localization
 */

$labels = [];

$labels['navtitle'] = 'Files';
$labels['myfiles']  = 'My Files';
$labels['shared']   = 'Shared';
$labels['trash']    = 'Trash';

$labels['resizer_label'] = 'Drag to resize the sidebar split (double-click: reset)';
$labels['toggle_folder'] = 'Expand or collapse folder';
$labels['choose_folder'] = 'Choose folder';
$labels['filter_folders'] = 'Filter folder names…';
$labels['none_found']    = 'No folders found inside your mount.';

$labels['settings_title']   = 'Settings';
$labels['setting_chd_label'] = 'Company parent folder';
$labels['setting_chd_help']  = 'Relative to your mount. Leave empty to use the config value.';
$labels['msg_settings_saved'] = 'Settings saved.';
$labels['stats_title']      = 'Statistics';
$labels['stat_companies']   = 'Companies detected';
$labels['stat_accounts']    = 'Client accounts';
$labels['stat_shares']      = 'Shared PDF folders';
$labels['placeholder_company'] = 'Company Name Inc.';
$labels['placeholder_parent']  = 'e.g. 2026/company-parent';

$labels['manageclients']    = 'Manage Clients';
$labels['clientform_edit']  = 'Editing client';
$labels['field_username']   = 'Username';
$labels['field_password']   = 'Password (min. 8 chars)';
$labels['pwd_hint_edit']    = 'leave password blank to keep the current one';
$labels['btn_generate']     = 'Generate';
$labels['field_home']       = 'Company name (under the default parent folder)';
$labels['btn_scan']         = 'Scan';
$labels['btn_save']         = 'Save';
$labels['btn_cancel']       = 'Cancel';
$labels['btn_edit']         = 'Edit';
$labels['btn_delete']       = 'Delete';
$labels['field_readonly']   = 'Read-only access';
$labels['field_shares']     = 'Shared folders (monthly, YEAR/MONTH/PDF pattern)';
$labels['shares_hint']      = 'Fill the company name, then click Scan to list its share folders.';
$labels['share_exists']     = 'PDF exists';
$labels['share_create']     = 'PDF will be created';
$labels['col_shares']       = 'Shares';
$labels['col_updated']      = 'Updated';
$labels['msg_scan_ok']      = 'Folder scanned.';
$labels['msg_saved']        = 'Client saved.';
$labels['msg_deleted']      = 'Client deleted.';
$labels['err_notmanager']   = 'You are not allowed to manage clients.';
$labels['err_token']        = 'Session token invalid. Please retry.';
$labels['err_invalid_user'] = 'Invalid username (3-64 chars; lowercase letters & digits, dot/underscore/dash allowed in the middle, must start and end with a letter or digit).';
$labels['username_help']    = 'Lowercase letters & digits, 3-64 chars. Dot, underscore, dash allowed — but not at the start/end, and no spaces. Example: pt.abc2026';
$labels['err_invalid_pass'] = 'Password must be at least 8 characters.';
$labels['err_invalid_home'] = 'Home folder is invalid or outside your own mount.';
$labels['err_need_share']   = 'Select at least one year folder to share.';
$labels['err_db']           = 'Database unavailable. Please try again later.';
$labels['confirm_delete']   = 'Delete this client account?';
$labels['none_configured']  = 'No client accounts yet.';
