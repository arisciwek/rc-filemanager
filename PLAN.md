# PLAN.md — Integrasi TinyFileManager ke Plugin `filemanager`

> Dokumen rencana kerja. Simpan di root repo agar percakapan yang terputus
> bisa dilanjutkan tanpa riset ulang. Update status langkah setiap selesai.

## 1. Tujuan

Mengadopsi [TinyFileManager v2.6](https://github.com/prasathmani/tinyfilemanager)
(TFM) sebagai engine plugin Roundcube `filemanager`:

- **Staff** (user Roundcube): tombol taskbar → file manager **tanpa form login**
  (SSO via sesi Roundcube), chroot ke `/mnt/files/<user>`.
- **Klien** (tanpa akun Roundcube): URL publik `https://domain/filemanager.php`
  → form login bawaan TFM → chroot ke folder klien masing-masing
  (contoh: `/mnt/files/operation/klienA`) — klien tidak dapat melihat folder klien lain.
- Credential klien berupa "raw file" PHP (username => bcrypt hash), disimpan
  **di luar DocumentRoot**, tidak pernah di-commit ke git.

## 2. Fakta teknis hasil riset (jangan riset ulang)

- TFM punya mode embed bawaan: `define('FM_EMBED', true)` sebelum include →
  `$use_auth=false` otomatis (form login hilang) dan TFM **tidak** memanggil
  `session_start()` sendiri → memakai sesi Roundcube.
- TFM selalu mencoba memuat `config.php` dari `__DIR__`-nya (baris ~168–173),
  **setelah** default config internal → gunakan `lib/config.php` untuk menimpa
  `$root_path`, `$auth_users`, dll. **Tanpa patch file utama TFM.**
- Semua link/AJAX TFM dibangun dari `FM_SELF_URL . '?p=...'` (menimpa query
  string). Karena itu routing `?_task=filemanager` Roundcube TIDAK dipakai;
  TFM harus jalan lewat entry script sendiri (`public_html/filemanager.php`)
  agar URL-nya bersih.
- Per-user chroot klien = fitur native TFM: `$directories_users[username] =>
  path` (verifikasi di source baris 290–292 dan 437–438).
- Sesi: staff = sesi Roundcube (`roundcubemail`); klien = sesi TFM sendiri
  (`FM_SESSION_ID='filemanager'`). CSRF token TFM `$_SESSION['token']` tidak
  bentrok dengan Roundcube (`request_token`).
- `/mnt/files/operation` & `/mnt/files/sales` = **mount point ext4 terpisah**
  (LXC 108 Proxmox, disk host), owner `nobody:nogroup` 2770 + ACL
  `user:www-data:rwx` (access & default ACL) → www-data (PHP-FPM/Apache)
  sudah bisa baca-tulis. **Jangan auto-mkdir** folder user sembarangan —
  hanya folder mount yang ada yang valid; sisanya ditolak 403.
- Mapping username→folder staff: local-part email
  (`operation@ciptamasjaya.co.id` → `operation`). Validasi regex
  `^[a-zA-Z0-9._-]+$`.
- Layout server: secure layout, DocumentRoot = `/var/www/roundcube/public_html`
  → direktori `plugins/` TIDAK dilayani web → aman untuk credential.
- `'filemanager'` sudah terdaftar di `config/config.inc.php` Roundcube.
- Instalasi lama `public_html/tinyfilemanager/` punya form login sendiri dan
  terekspos publik → **dihapus** setelah integrasi baru terbukti (backup dulu
  ke `/tmp/opencode/tinyfilemanager-backup`).

## 3. Arsitektur

```
public_html/filemanager.php        ← GATEWAY (~80 baris), 2 mode otomatis
plugins/filemanager/
├── filemanager.php                ← task 'filemanager' + action index → template iframe
├── filemanager_ui.php             ← tombol taskbar Elastic (sudah ada, dari boilerplate)
├── lib/tinyfilemanager.php        ← TFM v2.6 STOCK tanpa patch (mudah upgrade)
├── lib/translation.json           ← terjemahan TFM
├── lib/config.php                 ← dimuat TFM otomatis; timpa default dari konstanta gateway
├── config.inc.php.dist            ← TEMPLATE config + contoh entri klien (di-commit)
├── config.inc.php                 ← ASLI + credential klien (DI-GITIGNORE)
└── skins/elastic/templates/
    ├── filemanager.html           ← layout Elastic + iframe full-height src=/filemanager.php
    └── minimal.html               ← (dihapus, tidak dipakai lagi)
```

### Alur gateway `public_html/filemanager.php`

```
Request → bootstrap framework Roundcube (vendor/autoload + Roundcube/bootstrap.php,
          rcmail::get_instance() → sesi dimulai)
├─ $rcmail->user->ID ada?  → MODE STAFF:
│     localpart(username) → root = <staff_base>/<localpart>
│     validasi regex + is_dir() → gagal? 403
│     define FM_EMBED=true, FILEMANAGER_STAFF, FILEMANAGER_ROOT_PATH, FILEMANAGER_OPTS
│     include plugins/filemanager/lib/tinyfilemanager.php → exit
│     (readonly jika localpart ada di $config['staff_readonly'] → FILEMANAGER_READONLY)
└─ tidak ada sesi → MODE KLIEN:
      define FILEMANAGER_CLIENTS_FILE=<plugin>/config.inc.php, FILEMANAGER_CLIENTS_MODE
      include TFM dengan auth bawaan aktif ($use_auth=true) → form login TFM
      setelah login, TFM chroot otomatis via $directories_users (disuntik lib/config.php)
```

### `lib/config.php` (dimuat TFM setelah defaultnya)

```php
if (defined('FILEMANAGER_STAFF')) {
    $use_auth=false; $auth_users=[]; $root_path=FILEMANAGER_ROOT_PATH;
    $global_readonly = FILEMANAGER_READONLY;   // bool
} elseif (defined('FILEMANAGER_CLIENTS_FILE')) {
    $cfg = @include FILEMANAGER_CLIENTS_FILE;
    foreach ($cfg['clients'] as $u=>$c) {
        $auth_users[$u]=$c['hash'];
        $directories_users[$u]=rtrim($c['path'],'/');
        if (!empty($c['readonly'])) $readonly_users[]=$u;
    }
}
// umum: timezone, max_upload, chunk, lang, theme dst dari FILEMANAGER_OPTS
```

## 4. Langkah eksekusi

| # | Langkah | Status |
|---|---------|--------|
| 0 | Tulis PLAN.md | ✅ |
| 1 | Vendor `tinyfilemanager.php` + `translation.json` stock → `lib/` | ✅ |
| 2 | Buat `lib/config.php` | ✅ |
| 3 | Buat `config.inc.php.dist` + `.gitignore` (config.inc.php) | ✅ |
| 4 | Buat gateway `public_html/filemanager.php` | ✅ |
| 5 | Update `filemanager.php` (action_index), template `filemanager.html`, hapus `minimal.html`, update README | ✅ |
| 6 | Verifikasi: `php -l` semua file; curl gateway tanpa sesi (harus form login); isolasi antar klien | ✅ |
| 7 | Hapus instalasi lama `public_html/tinyfilemanager/` (backup ke /tmp/opencode) | ✅ |
| 8 | Commit + push | ⬜ |

## Hasil verifikasi (2026-08-22)

- `php -l`: semua file PHP lulus.
- Anonim tanpa klien terdaftar → halaman "File sharing belum dikonfigurasi" (engine tidak dijalankan).
- Klien uji: login form muncul; POST login valid → 200 listing chroot (hanya
  isi folder klien); `?p=../../` ditolak (tetap dalam chroot); password salah
  → redirect ke form login. Entri uji sudah dibersihkan.
- Mode staff (SSO via iframe) menunggu uji manual: login Roundcube → taskbar
  **Berkas**.

## Iterasi 2 (2026-08-22) — fix iframe 404 + pemisahan URL staff/klien

**Bug**: iframe memuat `/skins/elastic/filemanager.php` (404 Apache).
**Akar masalah** (rcmail_output_html.php): `fix_paths()`/`file_callback()`
menimpa SEMUA src/href root-absolut dengan base_path skin bila file tidak
ditemukan di skin — `src="/filemanager.php"` menjadi
`src="/skins/elastic/filemanager.php"`.

**Solusi yang diterapkan:**

1. Template: `src="<roundcube:var name='env:gateway_url' />"`; plugin set env
   `scheme://HTTP_HOST/filemanager.php`. Nilai berisi `://` dilewati semua
   rewriter Roundcube.
2. Gateway dipisah dua skrip:
   - `public_html/filemanager.php` = STAFF saja; tanpa sesi → redirect
     `./?_task=filemanager`.
   - `public_html/filemanager-client.php` = ENTRY KLIEN; tanpa bootstrap
     Roundcube, auth TFM aktif. Link untuk dibagikan.
3. Layout staff jadi 3 kolom Elastic: menu bawaan + `#layout-sidebar`
   (template object `filemanager_sidebar`: Berkas Saya / Dibagikan / Sampah,
   item hanya tampil bila foldernya ada; navigasi via `target="filemanager-frame"`)
   + `#layout-content` berisi iframe.
4. `'boilerplate'` dihapus dari `$config['plugins']` Roundcube.

**Verifikasi iterasi 2:** php -l lulus semua; anonim ke /filemanager.php →
302 `./?_task=filemanager`; anonim ke /filemanager-client.php → "belum
dikonfigurasi"; klien uji login+listing chroot OK, traversal ditolak;
entri uji dibersihkan. Uji manual staff (login → tombol Berkas → 3 kolom +
iframe) menunggu konfirmasi user.
- Perbaikan yang ditemukan saat build:
  - `config.inc.php` wajib diakhiri `return $config;` agar `@include`
    menghasilkan array.
  - Tanpa klien terdaftar, engine tidak boleh jalan (sebelumnya anonim melihat
    browser folder jail kosong).
- Instalasi lama `public_html/tinyfilemanager/` dihapus; backup lengkap ada
  di `/tmp/opencode/tinyfilemanager-backup`.
- Artefak lama di public_html sudah dibersihkan (2026-08-22): folder klon
  `tinyfilemanager/` dan `roundcube_filemanager/` dihapus (backup di
  /tmp/opencode/*-backup). Yang tersisa hanya distribusi Roundcube
  (index.php, installer.php, static.php, .htaccess) + gateway filemanager.php.
  Repo lama `plugins/filemanager-tiny/` masih ada (tidak aktif) — kandidat
  pembersihan berikutnya.

## 5. Verifikasi

1. `php -l` semua file PHP baru.
2. `curl -s https://localhost/filemanager.php` → HTML form login TFM (mode klien).
3. Login staff di Roundcube → klik **Filemanager** → iframe terbuka tanpa form
   login, root = `/mnt/files/<localpart>`.
4. Login klien → hanya melihat foldernya; coba path traversal → diblokir TFM.
5. Gateway tanpa sesi Roundcube + bukan POST login → form login (bukan akses langsung).

## 6. Catatan

- JANGAN commit `config.inc.php` (berisi hash password klien).
- Hash password: `php -r "echo password_hash('rahasia', PASSWORD_BCRYPT);"`
- Pembuatan folder klien: staff membuat manual lewat UI (mount operation),
  lalu admin menambah entri di `config.inc.php`. Otomatisasi UI manajemen
  klien = kandidat fase 2 (belum direncanakan).
- Plugin lama `plugins/filemanager-tiny` dibiarkan (tidak aktif di config).
