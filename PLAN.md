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

## Iterasi 3 (2026-08-22) — pohon folder lazy-load + highlight (keputusan DISCUSS.md #3)

Sidebar staf kini berisi:
1. **Pohon folder** — level 1 server-side (tpl_sidebar/tree_nodes), level
   lebih dalam via AJAX `?_task=filemanager&_action=tree&_folder=<rel>`
   (JSON {name, path, has_children}). Guard: sesi Roundcube wajib,
   safe_rel() + resolve_dir() realpath-chroot, limit 500 entri,
   shared/.trash disembunyikan dari pohon (jadi pintasan terpisah).
2. **Pintasan terpisah** Dibagikan/Sampah (#filemanager-shortcuts).
3. **Highlight node aktif** — klik pohon langsung menandai; navigasi di
   iframe membaca location.search (same-origin) lalu menandai + membuka
   leluhur. CSS: panah toggle, indentasi, spinner, folder-open aktif.

Aset JS/CSS plugin dilayani lewat `/static.php/plugins/<plugin>/...`
(otomatis oleh resource_location() rcmail_output_html — URL /plugins/...
langsung memang 404 di layout secure).

Verifikasi: php -l OK; node --check OK; static.php js/css = 200;
endpoint tree anonim = halaman login (JSON hanya sesi sah). Uji manual
staff menunggu konfirmasi user.

### Iterasi 3b — perbaikan pohon tidak berfungsi (laporan uji user)

Gejala: level-1 tampil (CMJ_2026), tapi toggle lazy-load & highlight mati.
Akar masalah: include_script() mencetak <script> di <head>; getElementById
gagal (DOM belum siap) -> boot() keluar diam-diam, tak ada handler.
Perbaikan:
1. filemanager.js dibungkus boot() + tunggu DOMContentLoaded.
2. Root li diberi data-loaded="1" (anak sudah server-side; cegah duplikat
   saat collapse->expand).
3. Folder titik (.cache/.AppleDouble/...) disembunyikan dari pohon,
   konsisten hidden-files TFM; kini cukup 'shared' yang dikecualikan khusus.
Verifikasi: node --check OK; static.php menyaji versi baru (ada
DOMContentLoaded). CATATAN DEPLOY: aset di-cache browser ~1 bulan
(ExpiresDefault) -> WAJIB hard refresh (Ctrl+Shift+R) setelah update.

### Iterasi 3c — breadcrumb keluar dari <nav> (permintaan user)

lib/tinyfilemanager.php diberi LOCAL PATCH bertanda komentar
`[LOCAL PATCH rc-filemanager]`: blok breadcrumb dipindah ke atas <nav>
sebagai baris sendiri (.fm-pathbar, bootstrap utilities). Alasan: di dalam
.collapse.navbar-collapse, breadcrumb ikut tersembunyi saat iframe lebih
sempit dari breakpoint lg. Menu kanan tetap di dalam collapse.

PERHATIAN: sejak DISCUSS.md #4, file TFM tidak pernah ditimpa file resmi
lagi (adopsi rilis baru manual & selektif). Penanda [LOCAL PATCH]
dipertahankan untuk jejak audit saat manual-merge.

## Iterasi 4 (2026-08-22) — manajemen klien CRUD (DISCUSS.md #5)

Keputusan: pola SQL (MySQL via Roundcube db_dsnw, tabel `filemanager_clients`
dibuat otomatis), farm symlink `<mount>/.clients/<username>/<tahun> -> PDF`,
gate izin key `managers` di config plugin.

Komponen:
- `lib/store.php`  — PDO CRUD + ensure_table() (idempotent)
- `lib/farm.php`   — build()/remove() farm symlink per username
- `lib/config.php` — mode klien membaca DB; self-heal farm bila hilang
- `filemanager.php`: action `clients` / `clients_save` / `clients_delete`,
  gate `is_manager()`, CSRF token sesi (`request_token`),
  alur Pindai→centang tahun→Simpan (folder `PDF` auto-create 2770)
- template `clients.html`, label en/id, CSS halaman
- shortcut sidebar `Kelola Klien` (fa-users) khusus manager

Verifikasi: php -l semua file OK; anon `?_action=clients` → login page;
gateway staff 302; entry klien menampilkan pesan "belum dikonfigurasi"
saat tabel kosong.

## Iterasi 5 (2026-08-23) — tabel akun: scroll + sticky header (paging ditunda)

Keputusan: tabel Kelola Klien TIDAK memakai paging maupun DataTable.
Alasan: pencarian instan sisi-klien sudah ada (filter semua kolom +
penghitung "n / m akun"), lebih efektif daripada lompat halaman;
dependensi DataTable (~250KB jQuery plugin) tidak sepadan dan rawan
bentrok gaya dengan Elastic.

Implementasi:
- `.fm-table-wrap` — container `max-height: 65vh`, `overflow-y: auto`,
  `overscroll-behavior: contain`.
- `.fm-clients-table thead th { position: sticky; top: 0 }` — header
  tetap terlihat saat menggulir.
- Baris hover disorot; baris kosong disembunyikan saat mencari.

Pemicu meninjau ulang paging server-side: jumlah akun tembus ±100,
atau render halaman terasa berat. Struktur siap ditambah tanpa ubah
skema (query LIMIT/OFFSET di fm_store::all() + kontrol di controller).

Catatan unik yang menyertai (sudah implementasi iterasi ini):
- username: PRIMARY KEY (DB) + cek controller + pattern browser.
- home: UNIQUE KEY uq_home (migrasi idempoten) + cek controller
  ("1 akun = 1 perusahaan").
- INSERT ... ON DUPLICATE KEY UPDATE DIHAPUS — diganti insert()/update()
  tegas agar tabrakan unique tidak pernah menimpa diam-diam; exception
  PDO duplicate-key diterjemahkan menjadi notifikasi err_user_exists /
  err_home_exists.

## TODO — Pencarian & Paging Tabel Kelola Klien (hasil diskusi 2026-08-23)

Status kini: pencarian instan sisi-klien (semua kolom) + tabel scroll
`.fm-table-wrap` (65vh, header sticky). Tanpa paging/DataTable.
Referensi: snippet "ambil_data.php" (server-side search/paging mandiri)
DINILAI TIDAK LAYAK diadopsi langsung — bypass sesi/CSRF/gate manager,
koneksi DB kedua (root), render HTML dari backend tanpa kolom aksi
Ubah/Hapus, tombol pagination loop semua nomor.

Pemicu implementasi: akun klien tembus ±100, atau render terasa berat.

Rencana saat pemicu tercapai (pola yang disetujui):
1. Action baru `_action=clients_data` (JSON):
   - render_gate() manager-only + token
   - PDO prepared via fm_store::pdo() — TANPA koneksi kedua:
     WHERE home LIKE ? ORDER BY username LIMIT ? OFFSET ?
   - COUNT(*) terpisah untuk total halaman
2. Frontend:
   - fetch + debounce 300ms + reset ke halaman 1 saat keyword berubah
   - render baris tetap di JS (kolom aksi Ubah/Hapus tetap ada,
     fm-del confirm tetap jalan)
   - pagination prev/next (bukan loop semua nomor), halaman aktif
     di query param (?_page=)
3. Pencarian SATU kolom saja: nama perusahaan (home LIKE) — keputusan
   user 2026-08-23; tampilan perusahaan tetap lewat home_display().
4. Client-side instant filter dipertahankan untuk halaman aktif.
