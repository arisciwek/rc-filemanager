<?php
/**
 * Roundcube Webmail — Filemanager Gateway (PINTU KLIEN)
 *
 * File    : /public_html/filemanager.php
 * Path    : /var/www/roundcube/public_html/filemanager.php
 *
 * PERAN UTAMA FILE INI: pintu masuk KLIEN EKSTERNAL.
 * Link yang dibagikan ke klien: https://<domain>/filemanager.php
 *
 * 1. MODE KLIEN (peran utama) — tanpa sesi Roundcube:
 *    - Engine berjalan dengan auth bawaannya aktif -> form login klien;
 *      setelah login tiap klien di-chroot ke farm .clients/<username>
 *      berisi symlink share bulanannya.
 *    - Kredensial dibaca lib/config.php dari tabel filemanager_clients
 *      (+ fallback config.inc.php) — di luar DocumentRoot.
 *
 * 2. MODE STAFF (pendamping, untuk SSO iframe Roundcube):
 *    - Pengunjung dengan sesi Roundcube aktif langsung dilayani engine
 *      tanpa login (FM_EMBED), chroot <staff_base>/<local-part>.
 *    - Cabang ini WAJIB ada karena iframe pada menu "Filemanager"
 *      Roundcube menunjuk ke file ini; tanpanya staf justru melihat
 *      form login klien.
 *
 * Riwayat: sebelumnya mode klien memakai skrip terpisah
 * /filemanager-client.php — digabung ke sini dan skrip tersebut
 * dihapus (satu pintu untuk semua).
 *
 * Keamanan:
 * - Cek sesi staff SELALU lebih dulu; klien tak mungkin masuk cabang
 *   staff tanpa sesi Roundcube valid.
 * - Folder staff HARUS sudah ada (mount point); selain itu 403.
 * - Nama folder divalidasi regex ketat (anti traversal).
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 */

// ------------------------------------------------------------------
// 1) Bootstrap framework Roundcube (memulai konfigurasi + sesi)
//    File ini tinggal DI DALAM folder plugin (gateways/) dan dieksekusi
//    lewat Alias Apache /filemanager -> file ini. Root Roundcube =
//    tiga level di atas folder gateways.
// ------------------------------------------------------------------
$rc_root = dirname(dirname(dirname(__DIR__)));
require_once $rc_root . '/program/include/iniset.php';
$rcmail = rcmail::get_instance(0, $GLOBALS['env'] ?? null);

// ------------------------------------------------------------------
// 2) Muat konfigurasi plugin
// ------------------------------------------------------------------
$plugin_dir = dirname(__DIR__);

$fmcfg = is_readable($plugin_dir . '/config.inc.php')
    ? @include $plugin_dir . '/config.inc.php'
    : [];
if (!is_array($fmcfg)) {
    $fmcfg = [];
}

// ------------------------------------------------------------------
// 3) MODE STAFF — sesi Roundcube valid?
// ------------------------------------------------------------------
if ($rcmail->get_user_id()) {
    $username  = (string) $rcmail->get_user_name();
    $pos       = strrpos($username, '@');
    $localpart = strtolower($pos !== false ? substr($username, 0, $pos) : $username);

    // Regex ketat: anti directory traversal & karakter aneh
    if (!preg_match('/^[a-z0-9._-]{1,64}$/', $localpart)) {
        http_response_code(403);
        exit('Filemanager: username tidak valid.');
    }

    $base = rtrim(isset($fmcfg['staff_base']) && $fmcfg['staff_base'] !== ''
        ? $fmcfg['staff_base']
        : '/mnt/files', '/');
    $root = $base . '/' . $localpart;

    // Mount point harus benar-benar ada — tidak dibuat otomatis.
    if (!is_dir($root)) {
        http_response_code(403);
        exit('Filemanager: folder penyimpanan untuk user ini tidak tersedia.');
    }

    define('FM_EMBED', true);           // tanpa login & session_start milik TFM
    define('FILEMANAGER_STAFF', true);
    define('FILEMANAGER_ROOT_PATH', $root);
    define('FILEMANAGER_READONLY', in_array(
        $localpart,
        !empty($fmcfg['staff_readonly']) && is_array($fmcfg['staff_readonly'])
            ? $fmcfg['staff_readonly']
            : [],
        true
    ));

    define('FILEMANAGER_OPTS', (!empty($fmcfg['opts']) && is_array($fmcfg['opts']))
        ? $fmcfg['opts']
        : []);

    require $plugin_dir . '/lib/tinyfilemanager.php';
    exit;
}

// ------------------------------------------------------------------
// 4) MODE KLIEN — tanpa sesi Roundcube: engine dengan auth internal
//    aktif (form login klien), chroot via farm .clients/<username>.
//    (Dulu skrip terpisah /filemanager-client.php — kini digabung.)
// ------------------------------------------------------------------
if (!is_readable($plugin_dir . '/lib/tinyfilemanager.php')) {
    http_response_code(500);
    exit('Filemanager: engine tidak ditemukan.');
}

define('FILEMANAGER_OPTS', (!empty($fmcfg['opts']) && is_array($fmcfg['opts']))
    ? $fmcfg['opts']
    : []);
// [LOCAL PATCH rc-filemanager] label & logo halaman login klien
define('FILEMANAGER_CLIENT_LOGIN', (!empty($fmcfg['client_login'])
    && is_array($fmcfg['client_login'])) ? $fmcfg['client_login'] : []);
define('FILEMANAGER_CLIENTS_FILE', $plugin_dir . '/config.inc.php');
define('FILEMANAGER_CLIENTS_BASE', rtrim(!empty($fmcfg['staff_base'])
    ? (string) $fmcfg['staff_base']
    : '/mnt/files', '/'));

require $plugin_dir . '/lib/tinyfilemanager.php';
exit;
