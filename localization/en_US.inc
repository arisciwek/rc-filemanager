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

$labels['manageclients']    = 'Manage Clients';
$labels['clientform_edit']  = 'Editing client';
$labels['field_username']   = 'Username';
$labels['field_password']   = 'Password (min. 8 chars)';
$labels['pwd_hint_edit']    = 'leave password blank to keep the current one';
$labels['btn_generate']     = 'Generate';
$labels['field_home']       = 'Client home folder (inside your mount)';
$labels['btn_scan']         = 'Scan';
$labels['btn_save']         = 'Save';
$labels['btn_cancel']       = 'Cancel';
$labels['btn_edit']         = 'Edit';
$labels['btn_delete']       = 'Delete';
$labels['field_readonly']   = 'Read-only access';
$labels['field_shares']     = 'Shared folders per year';
$labels['shares_hint']      = 'Fill the home folder, then click Scan to list its year folders.';
$labels['share_exists']     = 'PDF exists';
$labels['share_create']     = 'PDF will be created';
$labels['col_shares']       = 'Shares';
$labels['col_updated']      = 'Updated';
$labels['msg_scan_ok']      = 'Folder scanned.';
$labels['msg_saved']        = 'Client saved.';
$labels['msg_deleted']      = 'Client deleted.';
$labels['err_notmanager']   = 'You are not allowed to manage clients.';
$labels['err_token']        = 'Session token invalid. Please retry.';
$labels['err_invalid_user'] = 'Invalid username (3-64 chars: letters, digits, dot, underscore, dash).';
$labels['err_invalid_pass'] = 'Password must be at least 8 characters.';
$labels['err_invalid_home'] = 'Home folder is invalid or outside your own mount.';
$labels['err_need_share']   = 'Select at least one year folder to share.';
$labels['err_db']           = 'Database unavailable. Please try again later.';
$labels['confirm_delete']   = 'Delete this client account?';
$labels['none_configured']  = 'No client accounts yet.';
