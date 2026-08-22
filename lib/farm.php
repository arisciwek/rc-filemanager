<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/lib/farm.php
 * Path    : /var/www/roundcube/plugins/filemanager/lib/farm.php
 *
 * Symlink farm: mekanisme menyajikan BANYAK folder share ke SATU klien,
 * padahal TFM native hanya mendukung satu root per user
 * ($directories_users).
 *
 *   <base>/<mount>/.clients/<username>/     <- chroot klien di TFM
 *   ├── 2025 -> /mnt/files/operation/CMJ_2026/1. X/PT. Y/2025/PDF
 *   └── 2026 -> /mnt/files/operation/CMJ_2026/1. X/PT. Y/2026/PDF
 *
 * Farm berada DI DALAM mount terkait (anak langsung <base>) karena hanya
 * mount yang writable oleh www-data (2770 nobody:nogroup + ACL).
 * Nama berawalan titik membuatnya tak tampak di listing TFM maupun
 * pohon sidebar staf.
 *
 * @author     ArisCiwek
 * @copyright  GNU AGPL v3 or later
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 */

class fm_farm
{
    const DIRNAME = '.clients';

    /**
     * Path farm untuk satu klien, atau NULL bila $home berada di luar
     * $base (mis. traversal) sehingga tidak bisa dipetakan ke mount.
     */
    public static function dir_for($username, $home, $base)
    {
        $base = rtrim((string) $base, '/');
        $home = rtrim((string) $home, '/');
        if ($username === '' || $home === '' || $base === ''
            || strpos($home . '/', $base . '/') !== 0) {
            return null;
        }
        $rel    = ltrim(substr($home, strlen($base)), '/');
        $mount  = strtok($rel, '/');
        if ($mount === '' || $mount === false || strpos($mount, '.') === 0) {
            return null;
        }
        return $base . '/' . $mount . '/' . self::DIRNAME . '/' . $username;
    }

    /**
     * Bangun/perbarui isi farm: symlink per path share. Nama link =
     * nama folder tahun (dirname dari target). Idempoten — aman dipanggil
     * ulang kapan pun. Mengembalikan true bila farm siap dipakai.
     */
    public static function build($username, $home, array $paths, $base)
    {
        $farm = self::dir_for($username, $home, $base);
        if ($farm === null) {
            return false;
        }

        if (!is_dir($farm) && !@mkdir($farm, 2770, true)) {
            return false;
        }

        $wanted = [];
        foreach ($paths as $target) {
            $real = realpath((string) $target);
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $label = basename(dirname($real));   // '2025', '2026', ...
            if ($label === '' || isset($wanted[$label])) {
                $label = basename($real);        // fallback anti-tabrakan
            }
            $wanted[$label] = $real;
        }
        if (!count($wanted)) {
            return false;
        }

        // hapus link usang
        foreach ((array) @scandir($farm) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $link = $farm . '/' . $entry;
            if (is_link($link) && !isset($wanted[$entry])) {
                @unlink($link);
            }
        }
        // tulis/segarkan link aktif
        foreach ($wanted as $label => $real) {
            $link = $farm . '/' . $label;
            if (is_link($link) && readlink($link) !== $real) {
                @unlink($link);
            }
            if (!is_link($link) && !@symlink($real, $link)) {
                return false;
            }
        }
        return true;
    }

    /** Hapus seluruh farm klien (isi hanya symlink). Target tak tersentuh. */
    public static function remove($username, $home, $base)
    {
        $farm = self::dir_for($username, $home, $base);
        if ($farm === null || !is_dir($farm)) {
            return true;
        }
        foreach ((array) @scandir($farm) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $farm . '/' . $entry;
            is_link($p) ? @unlink($p) : (is_dir($p) ? @rmdir($p) : @unlink($p));
        }
        return @rmdir($farm);
    }
}
