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

$labels['manageclients']    = 'Kelola Klien';
$labels['clientform_edit']  = 'Mengubah klien';
$labels['field_username']   = 'Nama pengguna';
$labels['field_password']   = 'Kata sandi (min. 8 karakter)';
$labels['pwd_hint_edit']    = 'biarkan kata sandi kosong bila tidak diubah';
$labels['btn_generate']     = 'Buat otomatis';
$labels['field_home']       = 'Folder HOME klien (di dalam mount Anda)';
$labels['btn_scan']         = 'Pindai';
$labels['btn_save']         = 'Simpan';
$labels['btn_cancel']       = 'Batal';
$labels['btn_edit']         = 'Ubah';
$labels['btn_delete']      = 'Hapus';
$labels['field_readonly']   = 'Akses hanya-baca';
$labels['field_shares']     = 'Folder share per tahun';
$labels['shares_hint']      = 'Isi folder HOME lalu klik Pindai untuk menampilkan folder tahunnya.';
$labels['share_exists']     = 'PDF sudah ada';
$labels['share_create']     = 'PDF akan dibuat';
$labels['col_shares']       = 'Share';
$labels['col_updated']      = 'Diubah';
$labels['msg_scan_ok']      = 'Folder terpindai.';
$labels['msg_saved']        = 'Klien tersimpan.';
$labels['msg_deleted']      = 'Klien terhapus.';
$labels['err_notmanager']   = 'Anda tidak berhak mengelola akun klien.';
$labels['err_token']        = 'Token sesi tidak sah. Silakan ulangi.';
$labels['err_invalid_user'] = 'Nama pengguna tidak valid (3-64 karakter: huruf, angka, titik, garis bawah, strip).';
$labels['err_invalid_pass'] = 'Kata sandi minimal 8 karakter.';
$labels['err_invalid_home'] = 'Folder HOME tidak valid atau di luar mount Anda sendiri.';
$labels['err_need_share']   = 'Pilih minimal satu folder tahun untuk dibagikan.';
$labels['err_db']           = 'Database tidak tersedia. Coba lagi nanti.';
$labels['confirm_delete']   = 'Hapus akun klien ini?';
$labels['none_configured']  = 'Belum ada akun klien.';
