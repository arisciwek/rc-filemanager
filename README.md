# Filemanager Plugin untuk Roundcube Webmail

Plugin Roundcube yang mengintegrasikan [TinyFileManager v2.6](https://github.com/prasathmani/tinyfilemanager)
(TFM, stock tanpa patch) sebagai file manager webmail dengan dua mode akses:

| Mode | Siapa | Autentikasi | Root folder |
|---|---|---|---|
| **Staff** | User Roundcube (login via taskbar) | SSO — sesi Roundcube, tanpa form login | `/mnt/files/<local-part-email>` |
| **Klien** | Eksternal (tanpa akun Roundcube) | Form login bawaan TFM | path per-klien di `config.inc.php` (contoh: `/mnt/files/operation/klienA`) |

Klien hanya melihat foldernya sendiri (chroot native `$directories_users` TFM);
staff `operation` melihat seluruh isi mount-nya termasuk semua folder klien.

## Struktur Direktori

```
filemanager/
├── filemanager.php              # Kelas plugin utama: task + action index
├── filemanager_ui.php           # Handler UI (taskbar button & stylesheet)
├── config.inc.php.dist          # Template konfigurasi + contoh credential klien
├── config.inc.php               # Konfigurasi asli + credential (DI-GITIGNORE!)
├── lib/
│   ├── tinyfilemanager.php      # Engine TFM v2.6 STOCK — upgrade = timpa file ini
│   ├── config.php               # Override config engine (dimuat otomatis TFM)
│   └── translation.json         # Terjemahan engine
├── localization/
│   ├── en_US.inc                # Label "Files"
│   └── id_ID.inc                # Label "Berkas"
└── skins/elastic/
    ├── filemanager.css          # Style tombol taskmenu (ikon)
    ├── images/filemanager.png   # Ikon menu 24x24
    └── templates/filemanager.html # Layout Elastic + iframe gateway

public_html/filemanager.php      # GATEWAY (di luar repo) — bootstrap Roundcube,
                                 # verifikasi sesi, jalankan engine dalam 2 mode.
```

## Cara Kerja

1. Staff login Roundcube → klik tombol **Berkas** pada taskmenu (`#taskmenu`).
2. Task `filemanager` merender template Elastic yang berisi iframe ke
   `/filemanager.php` (gateway).
3. Gateway mem-bootstrap framework Roundcube:
   - Sesi valid → `FM_EMBED` aktif → TFM jalan **tanpa login**, chroot ke
     folder staff (folder harus sudah ada/mounted; jika tidak → 403).
   - Tanpa sesi → sesi Roundcube ditutup, auth bawaan TFM aktif → form login;
     setelah login klien di-chroot via `$directories_users` (fitur native TFM).
4. Kredensial klien disimpan di `config.inc.php` (**di luar DocumentRoot**
   karena secure layout — tidak dilayani web server).

Detail teknis lanjutan: lihat [PLAN.md](PLAN.md).

## Instalasi

1. Salin folder plugin ke `plugins/` instalasi Roundcube.
2. Aktifkan plugin di `config/config.inc.php`:

   ```php
   $config['plugins'] = [
       // ... plugin lainnya ...
       'filemanager',
   ];
   ```

3. Buat konfigurasi:

   ```bash
   cd plugins/filemanager
   cp config.inc.php.dist config.inc.php
   # edit config.inc.php: staff_base, clients (hash bcrypt), dst.
   ```

   Generate hash password klien:

   ```bash
   php -r "echo password_hash('password-anda', PASSWORD_BCRYPT);"
   ```

4. Gateway `public_html/filemanager.php` sudah terpasang otomatis bila repo
   ini berada di instalasi Roundcube target; jika menyalin manual, salin juga
   file tersebut.

## Manajemen Klien

1. Staff membuat folder klien lewat UI file manager (di mount-nya sendiri),
   misal `operation/klienA`.
2. Admin menambahkan entri klien baru di `plugins/filemanager/config.inc.php`
   (username, hash bcrypt, path chroot, opsi readonly).
3. Perubahan langsung efektif tanpa restart (dibaca per request).
4. Menghapus entri klien = klien tersebut tidak bisa login lagi.

## Persyaratan

- Roundcube Webmail ≥ 1.6 (skin Elastic), secure layout (DocumentRoot =
  `public_html/`)
- PHP 8.0+
- Folder storage per-user sudah termount dan writable oleh user web server
  (`www-data`) — ACL sudah disiapkan pada `/mnt/files/*`

## Keamanan

- Credential TIDAK PERNAH di-commit: `config.inc.php` masuk `.gitignore`.
- Engine TFM tidak boleh diakses langsung dari web; satu-satunya titik masuk
  adalah gateway yang memverifikasi sesi/kredensial lebih dulu.
- Instalasi mandiri TFM lama (`public_html/tinyfilemanager/`) telah dihapus.
- `lib/config.php` wajib read-only bagi web server (owner root, 644) —
  mekanisme save() TFM bisa menimpanya jika writable.

## Lisensi

GNU General Public License v3 atau lebih baru — lihat [LICENSE](LICENSE).

## Author

[ArisCiwek](https://github.com/arisciwek)
