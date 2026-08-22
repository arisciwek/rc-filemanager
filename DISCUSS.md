# DISCUSS.md — Catatan Diskusi & Keputusan Desain

> Log pertanyaan/timbulan selama pengembangan plugin filemanager,
> beserta analisanya. Update setiap sesi diskusi baru.

## 1. (2026-08-22) Form login tidak muncul di filemanager-client.php

**Temuan user:** akses `$HOST/filemanager-client.php` menampilkan
"File sharing belum dikonfigurasi — Silakan hubungi administrator.",
bukan form login TFM.

**Kesimpulan:** memang by design. `config.inc.php` masih 0 klien terdaftar
(entri uji dibersihkan setelah verifikasi). Perilaku defensif di
`lib/config.php`: tanpa klien valid → engine tidak dijalankan.

**Tindak lanjut:** tambahkan entri klien pertama di
`plugins/filemanager/config.inc.php`:

```php
$config['clients']['klienA'] = [
    'hash' => password_hash('rahasia', PASSWORD_BCRYPT),
    'path' => '/mnt/files/operation/klienA',
];
```

Setelah itu form login otomatis muncul di URL yang sama.

**Status:** ✅ terjawab

## 2. (2026-08-22) Bisakah cukup SATU path ($HOST/filemanager.php)?

**Pertanyaan user:** kalau staf dan klien memakai satu URL saja,
di mana letak kerumitannya?

**Analisis kerumitan satu-path:**

| Masalah | Penjelasan |
|---|---|
| Flag `?client` hilang | Setelah login TFM redirect ke `FM_SELF_URL?p=` (PHP_SELF tanpa query string) — request berikutnya polos |
| Gateway tak bisa membedakan | Klien aktif vs staf yang sesinya habis tampak identik |
| Deteksi sesi TFM = jebakan | Gateway harus membuka sesi `filemanager` sendiri; saat TFM memanggil `session_start()` lagi handler error-nya abort + ID baru → klien ter-log-out diam-diam (TFM ~baris 259–270) |
| Konflik dengan redirect staf | Aturan "anonim → ?_task=filemanager" otomatis menendang keluar semua klien |
| Audit & link | Dua skrip = log akses bersih + link klien stabil permanen |

**Satu-path alternatif yang mungkin:** buang fitur redirect staf
(kembali ke perilaku iterasi-1: semua anonim melihat form login klien).
Konsekuensi: staf sesi-kedaluwarsa melihat form klien — membingungkan,
bertentangan dengan permintaan sebelumnya.

**Keputusan:** tetap DUA skrip.
- `/filemanager.php` → staff only (sesi valid → SSO; anonim → redirect task)
- `/filemanager-client.php` → klien only (form login TFM)

Biaya hanya dua URL untuk diingat; untungnya arsitektur bersih tanpa
akal-akalan sesi.

**Status:** ✅ diputuskan (bisa dibuka lagi jika kebutuhan berubah)

## 3. (2026-08-22) Adopsi pola directory tree ala file manager web (Webix)?

**Pertanyaan user:** bisakah folder ditampilkan sebagai POHON di
`layout-sidebar` (expand/collapse); klik folder → `layout-content`
(iframe TFM) menampilkan isinya? Ref: https://webix.com/demos/filemanager/

**Analisis:** BISA, tanpa patch TFM (prinsip stock terjaga). Plugin punya
akses filesystem langsung → pohon dirender server-side pada handler
sidebar yang sudah ada; tiap node = link `?p=<path>` +
`target="filemanager-frame"` (nol JS wajib). Expand/collapse via
`<details>/<summary>` native atau widget treelist Elastic.

| Aspek | Konsekuensi | Mitigasi |
|---|---|---|
| Snapshot saat load | Perubahan folder di iframe tak langsung tampak | Tombol refresh / reload |
| Highlight folder aktif | Pohon tak tahu posisi iframe | JS kecil same-origin membaca URL iframe |
| Performa | Scandir mount tiap load | Batasi depth (~3) + guard jumlah entri |
| Klien eksternal | Halaman klien standalone tanpa layout RC | Fitur staf saja |

Alternatif ditolak: (B) patch UI TFM — langgar prinsip stock;
(C) ganti UI dengan aplikasi JS custom ala Webix — over-engineering.

**Keputusan (2026-08-22):**
1. Kedalaman pohon → **lazy-load**: level pertama dirender server-side
   (tpl_sidebar); level lebih dalam diambil via AJAX
   (`?_task=filemanager&_action=tree&_folder=<rel>`, JSON) saat toggle
   diklik pertama kali.
2. Shared/Trash → **item pintasan terpisah** (#filemanager-shortcuts),
   tidak masuk cabang pohon; disembunyikan juga dari listing top-level.
3. Highlight otomatis → **ya, diaktifkan**: klik link pohon langsung
   menandai node; saat navigasi DI DALAM iframe, JS membaca
   `location.search` iframe (same-origin) lalu menandai node yang cocok
   + membuka semua leluhurnya.

Implementasi:
- `filemanager.php`: action_tree() (JSON, guard sesi + realpath chroot),
  staff_root(), tpl_sidebar() baru, node_li(), safe_rel(), resolve_dir(),
  tree_nodes() (skip shared/.trash top-level, limit 500, natcasesort).
- `skins/elastic/filemanager.js`: buildNode/lazy-load/highlight/spinner.
- CSS: panah expand-collapse, indentasi bertingkat, highlight biru lembut,
  ikon folder-open pada node aktif.
- Aset plugin dilayani aman via `/static.php/plugins/...` — ditulis-ulang
  otomatis oleh resource_location() rcmail_output_html.

**Status:** ✅ selesai diimplementasikan (menunggu uji manual user)

## 4. (2026-08-22) Kebijakan pemeliharaan engine TFM

**Latar:** setelah LOCAL PATCH pertama (breadcrumb di luar `<nav>`), user
bertanya apakah kita masih memakai file resmi TFM.

**Keputusan (user):**
- `lib/tinyfilemanager.php` adalah **milik plugin** — TIDAK akan ditimpa
  file resmi TFM lagi.
- Upgrade/adopsi dilakukan **manual & selektif**: hanya patch penting
  (terutama security) yang diambil dari rilis TFM berikutnya.
- Konsekuensi: risiko "patch hilang saat overwrite" praktis hilang;
  penanda `[LOCAL PATCH rc-filemanager]` tetap dipertahankan sebagai
  jejak untuk audit/manual-merge di masa depan.

**Status:** ✅ diputuskan

## 5. (2026-08-22) Client management: CRUD UI untuk admin non-developer

**Permintaan:** staff admin (bukan developer) bisa create/edit/delete user
klien: username, password, path tersedia; timestamp otomatis.

**Realitas teknis:** TFM tak membaca store langsung — adapter
lib/config.php menerjemahkan store → $auth_users/$directories_users,
sehingga backend bebas dipilih tanpa menyentuh engine.

**Opsi backend:**

| | A. File PHP diperkuat | B. SQLite | C. MySQL RC |
|---|---|---|---|
| Dep baru | - | paket php-sqlite3 (belum terpasang) | parsing DSN utk skrip standalone |
| Konkurensi | flock+rename | WAL | native |
| Timestamp | key per entri | kolom | kolom |
| Upaya | kecil | sedang | sedang-berat |

Server terpasang: pdo_mysql ADA, pdo_sqlite TIDAK.

**Keputusan backend (user):** pola SQL disetujui; dipilih **MySQL
(low impact)** — infrastruktur sudah ada (driver + DSN Roundcube +
backup rutin). SQLite ditolak (perlu paket baru = medium impact).
Implementasi: tabel `filemanager_clients`; helper bersama
`lib/store.php` membuka PDO via DSN dari config Roundcube sehingga
gateway staff, entry klien standalone, dan UI CRUD memakai jalur akses
yang sama. File config.inc.php untuk klien di-retire setelah UI jalan.

**Keputusan:** ⏳ tersisa 3 poin:
1. Gate izin pengelola: usulan key 'managers' di config (bukan semua staff)?
2. Input path: absolut ber-prefix whitelist mount admin yg login vs dropdown?
3. Form: readonly toggle + generate password + min length 8?

---
*(tambahkan topik diskusi berikutnya di bawah ini)*

**Status #5 — IMPLEMENTED (iterasi 4).** Pola MySQL terpilih; form memakai
alur Pindai → centang tahun → Simpan. Gate `managers`; farm symlink;
PDF auto-create; rename & hapus ikut merapikan farm.
