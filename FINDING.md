# FINDING — Farm klien tak terbaca (folder share kosong saat login)

Tanggal : 2026-08-23
Pelapor : admin (login klien `petnesia` tidak melihat folder share)
Lingkup : `/mnt/files/<mount>/.clients/<username>/` (farm symlink TFM)

## Gejala

- Login klien via `filemanager-client.php` berhasil, tapi daftar folder
  kosong — padahal baris DB dan symlink farm ada.
- Direktori farm ber-mode aneh: `d-wx-wS--T`, dan berubah-ubah sendiri
  saat di-chmod (mis. `chmod 2770` -> hasil `5322`).

## Akar masalah (berlapis)

1. Umask PHP-FPM memotong bit read saat `mkdir($farm, 2770, true)`:
   hasil nyata bukan `rwxrws---` melainkan tanpa `r` untuk siapa pun.
2. ACL malformed akibat lapisan Samba/ID-map LXC: direktori mewarisi
   default ACL induk, tetapi mask terpotong (`mask::-w-`) dan muncul
   entri duplikat (`group::rwx` vs `group:4294967295:rwx`) sehingga
   `setfacl` biasa menolak ("Duplicate entries").
3. chmod dari dalam container diremas kebijakan Samba pada share
   (map archive/system/hidden + mask) - bit read tak bisa dipaksakan
   lewat `chmod()` saja.
4. Efek bersama: www-data hanya punya `w+x` tanpa `r` -> TFM tidak bisa
   me-list isi chroot klien.

## Bukti

    $ stat -c "%A %U %G" .clients/petnesia
    d-wx-wS--T www-data nogroup          # sebelum perbaikan

    $ getfacl -p .clients/petnesia       # mask memotong semua
    user::-wx
    user:www-data:rwx   #effective:-w-
    mask::-w-

## Solusi yang TERBUKTI bekerja (tetap di pola Samba)

`.clients` adalah dot-folder tersembunyi yang TIDAK dilayani Samba
(hanya dibaca TFM/www-data), sehingga ACL extended Samba justru menjadi
sumber masalah. Perbaikan: bersihkan ACL extended lalu pakai permission
UNIX murni:

    setfacl -b <farm>     # buang ACL extended yang malformed
    chmod 2770 <farm>     # rwxrws--- milik www-data:nogroup

Terbukti di `petnesia`: mode jadi `drwsrwx---`, isi 5 symlink tampil.

## Tindak lanjut

- [ ] `fm_farm::build()`: setelah mkdir jalankan `setfacl -b` (bila ada
      binary-nya) + `chmod 2770`.
- [ ] Terapkan perbaikan manual ke SEMUA farm klien yang sudah ada.
- [ ] `lib/config.php`: self-heal juga ketika farm ada tapi !is_readable.
- [ ] Catatan: file PDF klien tidak tersentuh; hanya metadata direktori.

## KOREKSI AKAR MASALAH (2026-08-23, setelah uji lanjutan)

Teori "remap Samba" TIDAK tepat. Akar sebenarnya: **bug oktal**.

    chmod($farm, 2770)   # SALAH — 2770 desimal = oktal 5322!
    chmod($farm, 02770)  # BENAR — rwxrws---

Mode aneh `5322` (d-wx-wS--T dst.) adalah hasil literal desimal tadi.
Bug sama ada di `fm_farm::build()` (mkdir + chmod) dan auto-create
folder PDF di `filemanager.php`. Semua sudah diganti ke `02770`.
ACL extended Samba yang malformed tetap dibersihkan via `setfacl -b`
sebagai penstabil tambahan.

Status perbaikan massal: 13/13 farm OK dan terbaca www-data
(adis 5, ahm-karawang 3, akasa 1, auto2000bsd 1, balmer 4,
bangung-solar 1, dasatria 2, duckil 1, ipsi 7, knauf 3, mowilex 5,
mutibox 1, petnesia 5 link).

- [x] fm_farm::build(): setfacl -b + chmod 02770
- [x] Perbaikan manual seluruh farm existing
- [x] lib/config.php self-heal saat !is_readable
- [ ] Uji login ulang dari sisi klien (petnesia)
