# Filemanager Plugin untuk Roundcube Webmail

Plugin Roundcube yang mengintegrasikan [TinyFileManager v2.6](https://github.com/prasathmani/tinyfilemanager)
(TFM, stock tanpa patch) sebagai file manager webmail dengan dua mode akses:

| Mode | Siapa | Autentikasi | Root folder |
|---|---|---|---|
| **Staff** | User Roundcube (login via taskbar) | SSO — sesi Roundcube, tanpa form login | `/mnt/files/<local-part-email>` |
| **Klien** | Eksternal (tanpa akun Roundcube) | Form login bawaan TFM | path per-klien di `config.inc.php` (contoh: `/mnt/files/operation/klienA`) |

Klien hanya melihat foldernya sendiri (chroot native `$directories_users` TFM);
staff `operation` melihat seluruh isi mount-nya termasuk semua folder klien.

Tampilan staff memakai layout Elastic tiga kolom:

```
┌──────────────┬─────────────────┬──────────────────────────┐
│ layout-menu  │ layout-sidebar  │ layout-content           │
│ Mail         │ ▾ Berkas Saya   │                          │
│ Calendar     │   ├ klienA      │ TinyFileManager (iframe) │
│ Contacts     │   └ klienB      │                          │
│ Files ←      │ ─────────────   │                          │
│ Settings     │ ⇗ Dibagikan     │                          │
│              │ 🗑 Sampah        │                          │
└──────────────┴─────────────────┴──────────────────────────┘
```

- **Pohon folder** (`#filemanager-tree`): level pertama dirender
  server-side; level lebih dalam diambil via AJAX (`_action=tree`) saat
  node dibuka (lazy-load). Node aktif di-*highlight* mengikuti folder
  yang sedang ditampilkan iframe (navigasi dari pohon maupun dari dalam
  engine).
- **Pintasan** (`#filemanager-shortcuts`): Dibagikan & Sampah — hanya
  muncul bila foldernya ada, terpisah dari pohon.
- Klik item membuka path tersebut di dalam iframe (atribut `target`).

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
    └── templates/filemanager.html # Layout Elastic 3 kolom + iframe gateway

public_html/filemanager.php      # GATEWAY STAFF (di luar repo) — verifikasi
                                 # sesi Roundcube; anonim di-redirect ke
                                 # ?_task=filemanager
public_html/filemanager-client.php # ENTRY KLIEN (di luar repo) — selalu mode
                                   # klien, form login TFM
```

## Cara Kerja

1. Staff login Roundcube → klik tombol **Berkas** pada taskmenu (`#taskmenu`).
2. Task `filemanager` merender template Elastic tiga kolom; iframe memuat
   `/filemanager.php` (gateway staff) via URL absolut `scheme://host/...` —
   penting agar tidak ditulis-ulang `fix_paths()` Roundcube menjadi
   `/skins/elastic/...`.
3. Gateway staff:
   - Sesi valid → `FM_EMBED` aktif → TFM jalan **tanpa login**, chroot ke
     folder staff (folder harus sudah ada/mounted; jika tidak → 403).
   - Tanpa sesi → redirect ke `/?_task=filemanager`.
4. Klien membuka `/filemanager-client.php`: engine berjalan dengan auth
   bawaan aktif → form login → chroot via `$directories_users`.
5. Kredensial klien disimpan di `config.inc.php` (**di luar DocumentRoot**
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

Akun klien kini dikelola lewat **UI web** (menu `Kelola Klien` di sidebar,
hanya tampil untuk staf yang masuk daftar `managers` di config plugin):

1. Staff membuat struktur folder klien lewat UI file manager, mis.
   `/mnt/files/operation/CMJ_2026/1. NAMA PERUSAHAAN/PT. CONTOH/`.
2. Buka **Kelola Klien** → isi folder HOME → klik **Pindai** → centang
   folder tahun yang ingin dibagikan (folder `PDF` di bawahnya dibuat
   otomatis bila belum ada) → isi username/password → **Simpan**.
3. Data akun disimpan di tabel MySQL `filemanager_clients` (dibuat
   otomatis saat halaman pertama kali dibuka); penyajian multi-folder
   memakai farm symlink `<mount>/.clients/<username>/`.
4. Menghapus klien di UI = farm symlink ikut dibersihkan.
5. Bagikan link **`https://<domain>/filemanager-client.php`** ke klien.

Gate `managers`: key array di `config.inc.php` plugin; kosong = semua
staf Roundcube boleh mengelola. Isi email atau local-part untuk membatasi.

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
