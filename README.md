# Filemanager Plugin untuk Roundcube Webmail

Plugin minimal sebagai **template/filemanager** untuk memulai pengembangan plugin Roundcube yang baru.

Plugin ini menyediakan fondasi lengkap namun sederhana: satu tombol menu (taskbar) dengan ikon kustom, halaman kosong siap isi, dan dukungan multi-bahasa — mengikuti konvensi skin **Elastic** bawaan Roundcube.

## Fitur

- Tombol pada taskbar/taskmenu (`#taskmenu`) dengan ikon kustom
- Task kustom `filemanager` dengan aksi `index`
- Template halaman kosong (`minimal.html`) untuk skin Elastic
- Stylesheet per-skin (`skins/elastic/filemanager.css`)
- Localization: `en_US`, `id_ID`

## Struktur Direktori

```
filemanager/
├── filemanager.php              # Kelas plugin utama (entry point)
├── filemanager_ui.php           # Handler UI (taskbar button & stylesheet)
├── localization/
│   ├── en_US.inc                # Label bahasa Inggris
│   └── id_ID.inc                # Label bahasa Indonesia
├── skins/
│   └── elastic/
│       ├── filemanager.css      # Style tombol taskmenu (ikon)
│       ├── images/
│       │   └── filemanager.png  # Gambar ikon menu (24x24)
│       └── templates/
│           └── minimal.html     # Template halaman kosong
├── LICENSE                      # GNU GPL v3
└── README.md
```

## Instalasi

1. Salin/copy folder `filemanager` ke direktori `plugins/` instalasi Roundcube Anda:

   ```
   cp -r filemanager /path/to/roundcube/plugins/
   ```

2. Aktifkan plugin di `config/config.inc.php`:

   ```php
   $config['plugins'] = [
       // ... plugin lainnya ...
       'filemanager',
   ];
   ```

3. Selesai. Tombol **Filemanager** akan muncul di menu kiri (taskmenu) setelah login.

## Cara Kerja Singkat

| Bagian | Penjelasan |
|---|---|
| `register_task('filemanager')` | Mendaftarkan task baru agar URL `?_task=filemanager` valid |
| `register_action('index', ...)` | Aksi default yang merender template `minimal` |
| `add_button([...], 'taskbar')` | Menambahkan tombol ke container `taskbar` (`#taskmenu` di Elastic) |
| `include_stylesheet()` | Memuat `skins/<skin>/filemanager.css` sesuai skin aktif |
| Ikon | Digambar via pseudo-element `:before` pada anchor tombol, mengikuti sistem ikon Elastic |

## Catatan Penting (Elastic Skin)

- Gunakan `'innerclass' => 'inner'` pada `add_button()` — bukan `button-inner` (konvensi Larry lama).
- Label plugin wajib menyertakan `'domain' => 'filemanager'` agar `gettext()` menemukan teks; tanpa itu label dirender mentah seperti `[filemanager.navtitle]`.
- Pada instalasi *secure layout* (DocumentRoot = `public_html/`), aset statis dilayani lewat `static.php/...`; pastikan path gambar di CSS relatif terhadap file CSS-nya.

## Kustomisasi

- **Ganti nama plugin**: rename folder + semua referensi `filemanager` (nama kelas, ID, domain label, selector CSS `.button-filemanager`).
- **Ganti ikon**: timpa `skins/elastic/images/filemanager.png` (disarankan 24x24 PNG transparan).
- **Isi halaman**: edit `skins/elastic/templates/minimal.html`.

## Persyaratan

- Roundcube Webmail ≥ 1.6 (skin Elastic)
- PHP 8.0+ (atribut `#[AllowDynamicProperties]`)

## Lisensi

GNU General Public License v3 atau lebih baru — lihat [LICENSE](LICENSE).

## Author

[ArisCiwek](https://github.com/arisciwek)
