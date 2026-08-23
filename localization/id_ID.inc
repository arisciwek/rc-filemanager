<?php
/**
 * Roundcube Webmail — Filemanager Plugin
 *
 * File    : /plugins/filemanager/localization/id_ID.inc
 * Path    : /var/www/roundcube/plugins/filemanager/localization/id_ID.inc
 *
 * Label terjemahan bahasa Indonesia.
 * Dimuat otomatis oleh $this->add_texts('localization/', false)
 * dan diakses via $this->gettext('filemanager.navtitle').
 *
 * @author     ArisCiwek
 * @license    GNU AGPL v3 or later
 * @package    Filemanager
 * @subpackage Localization
 */

$labels = [];

$labels['navtitle'] = 'Berkas';
$labels['myfiles']  = 'Berkas Saya';
$labels['shared']   = 'Dibagikan';
$labels['trash']    = 'Sampah';

$labels['resizer_label'] = 'Geser untuk mengatur pembagi sidebar (dobel-klik: reset)';
$labels['toggle_folder'] = 'Bentangkan atau lipatkan folder';
$labels['choose_folder'] = 'Pilih folder';
$labels['filter_folders'] = 'Saring nama folder…';
$labels['none_found']    = 'Tidak ada folder di dalam mount Anda.';

$labels['settings_title']   = 'Pengaturan';
$labels['setting_chd_label'] = 'Folder induk perusahaan';
$labels['setting_chd_help']  = 'Relatif terhadap mount Anda. Kosongkan untuk memakai nilai config.';
$labels['msg_settings_saved'] = 'Pengaturan tersimpan.';
$labels['stats_title']      = 'Statistik';
$labels['stat_companies']   = 'Perusahaan terdeteksi';
$labels['stat_accounts']    = 'Akun klien';
$labels['stat_shares']      = 'Folder PDF dishare';
$labels['placeholder_company'] = 'PT. Nama Perusahaan';
$labels['placeholder_parent']  = 'mis. 2026/Induk-Perusahaan';
$labels['search_accounts']  = 'Cari nama pengguna / perusahaan…';

$labels['manageclients']    = 'Kelola Klien';
$labels['clientform_edit']  = 'Mengubah klien';
$labels['field_username']   = 'Nama pengguna';
$labels['field_password']   = 'Kata sandi (min. 8 karakter)';
$labels['pwd_hint_edit']    = 'biarkan kata sandi kosong bila tidak diubah';
$labels['btn_generate']     = 'Buat otomatis';
$labels['field_home']       = 'Nama perusahaan (di bawah folder induk default)';
$labels['btn_scan']         = 'Pindai';
$labels['btn_save']         = 'Simpan';
$labels['btn_cancel']       = 'Batal';
$labels['btn_edit']         = 'Ubah';
$labels['btn_delete']      = 'Hapus';
$labels['field_readonly']   = 'Akses hanya-baca';
$labels['field_shares']     = 'Folder share (per bulan, pola TAHUN/BULAN/PDF)';
$labels['shares_hint']      = 'Isi nama perusahaan lalu klik Pindai untuk menampilkan folder share-nya.';
$labels['share_exists']     = 'PDF sudah ada';
$labels['share_create']     = 'PDF akan dibuat';
$labels['col_shares']       = 'Share';
$labels['col_updated']      = 'Diubah';
$labels['msg_scan_ok']      = 'Folder terpindai.';
$labels['msg_saved']        = 'Klien tersimpan.';
$labels['msg_deleted']      = 'Klien terhapus.';
$labels['err_notmanager']   = 'Anda tidak berhak mengelola akun klien.';
$labels['err_token']        = 'Token sesi tidak sah. Silakan ulangi.';
$labels['err_invalid_user'] = 'Nama pengguna tidak valid (3-64 karakter; huruf kecil & angka, boleh titik/garis bawah/strip di tengah, awal & akhir harus huruf/angka).';
$labels['err_user_exists']  = 'Nama pengguna sudah dipakai akun lain. Gunakan nama yang berbeda atau ubah akun yang ada.';
$labels['err_home_exists']  = 'Perusahaan ini sudah dipakai akun lain. Satu perusahaan hanya untuk satu akun.';
$labels['username_help']    = 'Huruf kecil & angka, 3-64 karakter. Boleh titik, garis bawah, strip — tapi tidak di awal/akhir dan tanpa spasi. Contoh: pt.abc2026';
$labels['err_invalid_pass'] = 'Kata sandi minimal 8 karakter.';
$labels['err_invalid_home'] = 'Folder HOME tidak valid atau di luar mount Anda sendiri.';
$labels['err_need_share']   = 'Pilih minimal satu folder tahun untuk dibagikan.';
$labels['err_db']           = 'Database tidak tersedia. Coba lagi nanti.';
$labels['confirm_delete']   = 'Hapus akun klien ini?';
$labels['none_configured']  = 'Belum ada akun klien.';
