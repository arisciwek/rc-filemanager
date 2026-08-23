# SECURITY-CHECK — Plugin Filemanager

Terakhir diperbarui : 2026-08-23
Lingkup             : semua form & permukaan akses (login klien, CRUD
                      Kelola Klien, Settings, Search/Picker, TFM engine)
Status              : P1 TERPASANG · P2/P3 MENUNGGU EVALUASI

---

## 1. Proteksi yang sudah terpasang (per 2026-08-23)

### Login klien (/filemanager.php — TFM auth internal)
- [x] `password_verify()` bcrypt — tanpa penyimpanan plaintext
- [x] CSRF token di form login (`verifyToken()`, `hash_equals`)
- [x] `sleep(1)` per percobaan (memperlambat oracle waktu)
- [x] **Throttle brute-force** [P1]: 5 kegagalan -> tolak 10 menit
      (window sliding, counter per sesi); counter dibersihkan saat sukses
- [x] **`session_regenerate_id(true)` setelah login sukses** [P1]
      (anti session fixation)
- [x] Pesan error generik (tidak membedakan username/password salah)

### Sesi
- [x] Cookie flags [P1]: `HttpOnly`, `SameSite=Lax`, `Secure` TANPA
      syarat (deployment selalu di balik TLS-proxy publik) — dipasang
      sebelum `session_start()`, dengan guard agar tidak menyentuh
      sesi Roundcube yang aktif
- [x] Nama sesi TFM terpisah (`FM_SESSION_ID`) dari PHPSESSID Roundcube
- [x] Guard semua pemanggilan session_* (log bersih dari warning)

### Hardening di balik TLS-proxy (2026-08-23, respons scanner)
- [x] **`$config['use_https'] = true`** di config Roundcube utama:
      PHP dipaksa memperlakukan koneksi sebagai HTTPS meski terminate
      di proxy -> SEMUA cookie Roundcube (`roundcube_sessid`,
      `csrf_cookie_name`) kini ber-flag `Secure`.
      (Dipilih `use_https`, BUKAN `force_https` — yang terakhir bisa
      menyebabkan redirect-loop karena koneksi ke Apache memang HTTP.)
- [x] **Cookie sesi TFM `secure => true` tanpa syarat** — alasan sama.
- Verifikasi: Set-Cookie `roundcube_sessid=...; path=/; secure;
  HttpOnly` ✓; data URI logo login tak terpengaruh path ✓
- [x] **`session_samesite = 'Lax'`** ditambahkan (2026-08-23) — cookie
      sesi kini lengkap: secure + HttpOnly + SameSite=Lax ✓
- Catatan capture scanner: contoh respons dengan CSP v1 diambil pada
  11:02:56, SEBELUM v2 aktif — data basi. Ketiga varian path
  (`/filemanager`, `/filemanager/`, `/filemanager.php`) telah
  diverifikasi menyajikan CSP v2.

### Header keamanan [P1]
- [x] `X-Content-Type-Options: nosniff`
- [x] `Referrer-Policy: strict-origin-when-cross-origin`
- [x] `X-Frame-Options: SAMEORIGIN`
      (iframe staf tetap jalan: same-origin)
- Terpasang di `fm_show_header_login()` dan `fm_show_header()`

### HSTS [P2 terpasang 2026-08-23 — respons scanner]
- [x] `Strict-Transport-Security: max-age=31536000; includeSubDomains`
      via Apache global (`conf-enabled/hsts.conf`, mod_headers aktif);
      sumber konfigurasi ikut repo:
      `gateways/apache-hsts.conf`
- Efek: setelah kunjungan HTTPS pertama, browser memaksa HTTPS selama
  1 tahun untuk domain & seluruh subdomainnya.
- Rollback bila perlu: hapus/kosongkan header lalu tunggu kedaluwarsa,
  atau turunkan max-age (mis. 21600) beberapa hari sebelum menghapus.
- Catatan: header pada respons HTTP diabaikan browser (sesuai spec) —
  aman bagi akses http internal yang belum pernah buka via https.

### Content-Security-Policy [P2 terpasang 2026-08-23 — respons scanner]
- [x] Via Apache global (`conf-enabled/csp.conf`; sumber di repo:
      `gateways/apache-csp.conf`).
- Versi 2: object-src 'none', base-uri/frame-ancestors/form-action
  'self', connect-src 'self'; unsafe-eval DIHAPUS; wildcard https:
  DIGANTI host eksplisit cdn.jsdelivr.net & cdncdnjs.cloudflare.com.

**Respons per temuan scanner (versi 2):**

| Temuan | Status | Justifikasi |
|--------|--------|-------------|
| `'unsafe-eval'` di script-src | ✅ FIXED v2 | dihapus total |
| `https:` wildcard | ✅ FIXED v2 | hanya jsdelivr + cdnjs |
| `'unsafe-inline'` script/style | ⚠️ ACCEPTED | TFM & Roundcube memakai inline script/style intensif; penghapusan butuh refaktor nonce besar (evaluasi lanjutan). Mitigasi: token CSRF rotasi, honeypot, throttle |
| `'self'` + user uploads (JSONP/JS) | ⚠️ MITIGATED | (1) X-Content-Type-Options: nosniff aktif; (2) akun klien READONLY — tak bisa upload; (3) staf tepercaya; (4) tidak ada endpoint JSONP; (5) ekstensi upload bisa dibatasi via config opts |

### security.txt [RFC 9116 — terpasang 2026-08-23]
- [x] `/.well-known/security.txt` via Alias Apache
      (`conf-enabled/security-txt.conf`; file di
      `public_html/.well-known/`).
- Isi: Contact, Expires (+1 tahun), Preferred-Languages, Canonical.
- **Periksa/ubah Contact** ke email/telp keamanan yang benar.

### CRUD Kelola Klien (create/edit/delete)
- [x] CSRF token sesi (`_token`, diverifikasi `hash_equals`)
- [x] Gate manager (`is_manager()`)
- [x] PDO prepared statements merata (lib/store.php)
- [x] Unique constraint di DATABASE (lapisan terakhir):
      `username` = PRIMARY KEY; `home` = UNIQUE KEY uq_home (migrasi
      idempoten) — tabrakan tak mungkin menimpa diam-diam
      (upsert lama sudah dihapus; insert()/update() tegas)
- [x] Anti-traversal: `realpath()` containment + `valid_seg()` per segmen
- [x] Whitelist karakter setting `_chd` [P2 terpasang]:
      `[A-Za-z0-9 .(),&_-]` + blok `..` + validasi folder eksis
- [x] Password klien: bcrypt, minimal 8, wajib saat create
      (`required minlength="8"`), hash tidak pernah ditampilkan/log
- [x] Notifikasi duplicate-key PDO diterjemahkan menjadi pesan jelas

### Search tabel & picker folder
- [x] Murni sisi-klien (tanpa permintaan server) — tidak ada
      permukaan serangan; endpoint pencarian server TIDAK ADA

### Privasi tampilan klien
- [x] Path internal mount (`/mnt/files/<mount>/CMJ_2026/...`) tidak
      pernah dirender: target symlink disembunyikan, breadcrumb HOME =
      nama perusahaan, `FM_ROOT_PATH` tak lagi bocor via title

---

## 2. MENUNGGU EVALUASI (P2/P3)

| # | Item | Catatan |
|---|------|---------|
| E1 | ~~HSTS~~ | ✅ TERPASANG 2026-08-23 (lihat bagian HSTS di atas) |
| E2 | **Logout-CSRF** | Logout masih GET `?logout=1`. Risiko rendah (hanya memaksa sesi berakhir). Perbaikan = POST ber-token, mengorbankan kesederhanaan |
| E3 | **Fail2ban** | Throttle saat ini berbasis sesi (per-browser). Untuk penyerang lintas-sesi, sarankan pasang fail2ban yang memantau pola POST gagal di access log |
| E4 | **Rate limit halaman Roundcube** | Di luar plugin (tanggung jawab inti RC / infrastruktur) |
| E5 | **Temuan scanner "http_server 2.4.66"** | FALSE POSITIVE — paket Ubuntu `2.4.66-2ubuntu2.4` (resolute-security) sudah memuat patch CVE 2026-*; modul terdampak (ldap/proxy_ftp/dav/xml2enc/proxy_html) bahkan tidak di-enable. Tandai mitigated dengan justifikasi changelog |

## 3. Checklist evaluasi manual (yang harus dicoba)

1. Login klien salah password 6x berturut-turut -> percobaan ke-6 harus
   ditolak dengan pesan "terlalu banyak percobaan" meski password benar.
2. Login benar -> cookie FM session memiliki flag HttpOnly + SameSite
   (cek DevTools > Application > Cookies).
3. Respons halaman membawa header nosniff / Referrer-Policy /
   X-Frame-Options (cek DevTools > Network > Headers).
4. Simpan settings `_chd` dengan karakter aneh (`a;b`, `<x>`, `..`) ->
   harus ditolak err_invalid_home.
5. Create klien dengan username/perusahaan duplikat -> notifikasi jelas,
   baris lama TIDAK berubah.
6. Klien mencoba URL path aneh (`?p=../../..`) -> TFM menolak/kembali.
7. Log apache: tidak ada warning/fatal baru selama seluruh uji.
