<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/lib/config.php
 * Path    : /var/www/roundcube/plugins/filemanager/lib/config.php
 *
 * Dimuat otomatis oleh tinyfilemanager.php (baris ~168) SETELAH default
 * konfigurasinya, sehingga variabel di file ini MENIMPA default TFM.
 * Tidak ada patch pada tinyfilemanager.php — upgrade engine cukup timpa file.
 *
 * Konstanta mode didefinisikan oleh gateway public_html/filemanager.php:
 * - FILEMANAGER_STAFF         : mode staff (SSO sesi Roundcube, FM_EMBED aktif)
 * - FILEMANAGER_ROOT_PATH     : chroot folder staff (hanya mode staff)
 * - FILEMANAGER_READONLY      : bool, staff hanya-baca (hanya mode staff)
 * - FILEMANAGER_CLIENTS_FILE  : path config.inc.php plugin (mode klien,
 *                               auth bawaan TFM tetap aktif)
 * - FILEMANAGER_OPTS          : array opsi umum (timezone, upload, ui, dst)
 *
 * PENTING: file ini harus TIDAK BISA DITULIS oleh web server (owner root,
 * chmod 644). FM_Config::save() TFM menargetkan file config.php yang
 * readable ketika user mengganti bahasa/theme dari UI — jika writable,
 * isi file ini akan tertimpa JSON $CONFIG.
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 * @subpackage Engine
 */

/*
 * ------------------------------------------------------------------
 * MODE STAFF — SSO via sesi Roundcube (FM_EMBED sudah didefinisikan
 * gateway sebelum include engine; auth TFM otomatis nonaktif).
 * ------------------------------------------------------------------
 */
if (defined('FILEMANAGER_STAFF')) {
    $use_auth        = false;
    $auth_users      = [];
    $readonly_users  = [];
    $global_readonly = defined('FILEMANAGER_READONLY') ? (bool) FILEMANAGER_READONLY : false;
    $root_path       = FILEMANAGER_ROOT_PATH;
}

/*
 * ------------------------------------------------------------------
 * MODE KLIEN — tanpa sesi Roundcube. Auth bawaan TFM tetap aktif
 * ($use_auth=true), form login milik TFM. Chroot per-klien memakai
 * fitur native $directories_users.
 * ------------------------------------------------------------------
 */
elseif (defined('FILEMANAGER_CLIENTS_FILE')) {
    $_cfg = is_readable(FILEMANAGER_CLIENTS_FILE) ? @include FILEMANAGER_CLIENTS_FILE : [];
    if (!is_array($_cfg)) {
        $_cfg = [];
    }

    // Kredensial klien: username => ['hash'=>bcrypt, 'path'=>chroot, 'readonly'=>bool]
    $auth_users        = [];
    $directories_users = [];
    $readonly_users    = [];

    foreach ($_cfg['clients'] ?? [] as $_user => $_entry) {
        if (!is_string($_user) || $_user === ''
            || empty($_entry['hash']) || empty($_entry['path'])) {
            continue;
        }
        $_path = rtrim($_entry['path'], '/\\');
        // Entri tanpa folder valid dilewati — tidak bisa login sama sekali.
        if ($_path === '' || !is_dir($_path)) {
            continue;
        }
        $auth_users[$_user]        = $_entry['hash'];
        $directories_users[$_user] = $_path;
        if (!empty($_entry['readonly'])) {
            $readonly_users[] = $_user;
        }
    }

    // Tanpa klien valid → kunci auth...
    $use_auth = !empty($auth_users);

    // ...dan JANGAN jalankan engine sama sekali: tamu anonim hanya melihat
    // pemberitahuan ini, bukan penjelajah folder kosong sekalipun.
    if (!$use_auth) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        exit(
            '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Filemanager</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding-top:12vh;color:#444">'
            . '<h2>File sharing belum dikonfigurasi</h2>'
            . '<p>Silakan hubungi administrator.</p>'
            . '</body></html>'
        );
    }

    /*
     * Jail fallback: sesi lama milik klien yang entrinya sudah dihapus
     * dari config tidak boleh jatuh ke $root_path default TFM
     * ($_SERVER['DOCUMENT_ROOT']). Tunjuk ke folder kosong di luar docroot.
     */
    $_jail = sys_get_temp_dir() . '/filemanager-jail';
    if (!is_dir($_jail)) {
        @mkdir($_jail, 0700, true);
    }
    $root_path = $_jail;

    unset($_cfg, $_user, $_entry, $_path, $_jail);
}

/*
 * ------------------------------------------------------------------
 * OPSI UMUM (kedua mode) — dari konstanta FILEMANAGER_OPTS gateway.
 * ------------------------------------------------------------------
 */
$_opts = defined('FILEMANAGER_OPTS') && is_array(FILEMANAGER_OPTS) ? FILEMANAGER_OPTS : [];

if (!empty($_opts['timezone'])) {
    $default_timezone = $_opts['timezone'];
}
if (isset($_opts['max_upload_bytes'])) {
    $max_upload_size_bytes = (int) $_opts['max_upload_bytes'];
}
if (isset($_opts['upload_chunk'])) {
    $upload_chunk_size_bytes = (int) $_opts['upload_chunk'];
}
if (isset($_opts['allowed_upload_extensions'])) {
    $allowed_upload_extensions = (string) $_opts['allowed_upload_extensions'];
}
if (isset($_opts['allowed_file_extensions'])) {
    $allowed_file_extensions = (string) $_opts['allowed_file_extensions'];
}
if (isset($_opts['exclude_items']) && is_array($_opts['exclude_items'])) {
    $exclude_items = $_opts['exclude_items'];
}
if (isset($_opts['online_viewer'])) {
    $online_viewer = $_opts['online_viewer'];
}

/*
 * $CONFIG (JSON) dibaca class FM_Config SETELAH file ini disertakan,
 * jadi menimpanya di sini aman: lang, theme, show_hidden, dll.
 */
$_ui = array_merge([
    'lang'            => 'en',
    'error_reporting' => false,
    'show_hidden'     => false,
    'hide_Cols'       => false,
    'theme'           => 'light',
], $_opts['ui'] ?? []);
$CONFIG = json_encode($_ui);

unset($_opts, $_ui);
