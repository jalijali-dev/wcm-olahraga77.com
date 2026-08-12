# DEPLOY WORKFLOW — Cheat Sheet Harian (olahraga77.com)

Panduan cepat 3 langkah yang dipakai berulang tiap ada perubahan kode yang
mau naik ke production. Diadaptasi dari alur `sagacrypto-wpm-goal`
(sagagoal.com) — lihat `/CLAUDE.md` di root project buat konteks penuh
soal topologi/tujuan project ini (WCM/tentakel buat sagagoal.com).

Terakhir dibuat: 12 Agustus 2026.

## Alur singkat

```
Claude / devs (lokal)  →  GitHub (jalijali-dev/wcm-olahraga77.com)  →  cPanel repositories/  →  public_html/
        push                                                              pull                    deploy (.cpanel.yml)
      (devs)                                                          (operator)                (operator)
```

Devs (termasuk Claude di sesi Cowork) tidak pernah pegang akses cPanel
langsung. Operator (Donnie) yang jadi gerbang terakhir — jalanin pull +
deploy manual lewat cPanel UI, sekaligus jadi checkpoint review sebelum
kode nyampe production.

## ⚠️ Topologi — WAJIB dipahami sebelum deploy

```
/home/USERNAME/                          ← GANTI USERNAME ke username cPanel asli
  ├── repositories/wcm-olahraga77/        ← working copy git, di LUAR public_html, aman
  └── public_html/                        ← docroot utama (olahraga77.com)
       ├── index.php, artikel.php, kategori.php, cari.php, sitemap.php, dst  ← FRONTEND
       ├── includes/, assets/             ← FRONTEND (site-bootstrap, CSS)
       ├── cms-admin/                     ← ADMIN, diakses lewat olahraga77.com/cms-admin/
       │    ├── pages/, assets/, includes/, dst
       │    └── config/database.php, config/app.php  ← TIDAK PERNAH di git, isi manual sekali di server
       └── uploads/                       ← media upload, TIDAK PERNAH di git, tumbuh di server
```

**Beda penting dari sagagoal.com:** database olahraga77.com WAJIB
database terpisah — jangan pernah reuse DB sagagoal.com (lihat
`/CLAUDE.md` § Risiko penting). File `.cpanel.yml` di root repo sengaja
exclude `cms-admin/config/database.php` dan `uploads/` dari proses
deploy — dua hal itu cuma pernah disentuh manual di server, tidak lewat
git sama sekali.

Jadi:

- File frontend (root repo: `index.php`, `artikel.php`, `kategori.php`,
  `cari.php`, `sitemap.php`, `sitemap-file.php`, `.htaccess`, `robots.txt`,
  folder `includes/`, `assets/`) → ikut ke-deploy otomatis ke
  `~/public_html/`.
- Folder `cms-admin/` di repo → ikut ke-deploy ke `~/public_html/cms-admin/`
  — prefix `cms-admin/` tetap ada, tidak di-flatten.
- `cms-admin/config/database.php`, `cms-admin/config/app.php`, dan
  `uploads/` → **TIDAK PERNAH** ikut deploy git (gitignored + di-exclude
  di `.cpanel.yml`). Isi file `.example`-nya manual di server, sekali,
  lewat File Manager/Terminal cPanel.

## 1️⃣ Push ke Git (dikerjakan devs / Claude, di lokal)

```bash
git add .
git commit -m "Deskripsi perubahan yang jelas"
git push origin main
```

Kalau ada banyak perubahan tapi cuma sebagian yang mau di-commit:

```bash
git add path/ke/file/spesifik.php
git commit -m "..."
git push origin main
```

### ⚠️ Troubleshooting — push ditolak `non-fast-forward` / history diverged

Diagnosa dulu sebelum ambil tindakan:

```bash
git fetch origin
git log --oneline main..origin/main   # commit yang ada di origin, gak ada di lokal
git log --oneline origin/main..main   # commit yang ada di lokal, gak ada di origin
```

Kalau situasinya emang "mau ganti total, versi lokal yang menang" (opsi
ini SELALU harus dikonfirmasi eksplisit ke user dulu, jangan pernah
diasumsikan):

```bash
git push origin main --force
```

Kalau ada keraguan sedikit pun soal history lama masih dibutuhkan,
**jangan** langsung force push — diskusikan opsi merge yang
mempertahankan history lama sebagai ancestor
(`git merge -s ours --allow-unrelated-histories origin/main`) dulu.

## 2️⃣ Pull ke cPanel (dikerjakan operator, via Terminal cPanel atau UI)

```bash
cd ~/repositories/wcm-olahraga77
git pull origin main
```

Verifikasi commit yang masuk sebelum lanjut:

```bash
git log -1
git show --stat <hash-commit>   # opsional, cek file apa aja yang berubah
```

**Alternatif — cPanel "Git™ Version Control" UI** (Manage Repository →
tab "Pull or Deploy"): tombol **"Update from Remote"** buat fetch commit
terbaru dari GitHub, dan tombol **"Deploy HEAD Commit"** buat langsung
jalanin `.cpanel.yml` (lihat § di bawah). Sama-sama valid, pilih yang
lebih nyaman.

### 📄 Isi `.cpanel.yml` — apa yang beneran dijalanin tombol "Deploy HEAD Commit"

```yaml
deployment:
  tasks:
    - export DEPLOYPATH=/home/USERNAME/public_html/
    - /usr/bin/rsync -av --exclude='.git' --exclude='.cpanel.yml' --exclude='cms-admin/config/database.php' --exclude='uploads' ./ $DEPLOYPATH
```

`rsync` nyalin seluruh isi repo ke `public_html/` KECUALI file yang
di-`--exclude` eksplisit (config DB + folder upload) — jadi klik "Deploy
HEAD Commit" aman dipakai sebagai cara **tercepat** buat sinkronin
`public_html`, tanpa risiko nimpa kredensial/media asli di server. Ganti
`/home/USERNAME/public_html/` ke path docroot asli sebelum pemakaian
pertama (cek di cPanel → Domains).

## 3️⃣ (Opsional) `cp` selective — kalau mau review manual dulu

Kalau ragu ada drift di `public_html` yang gak tercermin di git, atau
mau nimpa satu file doang tanpa nunggu deploy penuh:

```bash
cp index.php ~/public_html/index.php
cp cms-admin/pages/pages.php ~/public_html/cms-admin/pages/pages.php
```

Verifikasi hasil copy identik dengan sumber:

```bash
diff <file-di-repo> ~/public_html/<path-tujuan>
```

Kosong (tidak ada output) = sukses, file identik.

## Tips

- Jangan pernah `cp -r` seluruh folder secara manual tanpa pikir panjang
  di luar `.cpanel.yml` — kalau butuh sinkron penuh, pakai "Deploy HEAD
  Commit" (§ 2), bukan `cp -r` manual yang berisiko nimpa
  `database.php`/`uploads/` kalau lupa exclude.
- File yang cuma dipakai git (`.gitignore`, `.git/`, `docs/*.md`,
  `.cpanel.yml`) tidak perlu ada di `public_html` — murni referensi/
  dokumentasi, tidak dipakai runtime (walau `rsync` di atas ikut
  nyalinnya juga, itu harmless, cuma makan sedikit disk).
- Kalau ragu nama folder docroot, cek dulu:

```bash
ls -la ~/public_html
```

Jangan asumsi nama foldernya sama persis dengan nama domain/repo.

## Setup awal (sekali saja, sebelum deploy pertama)

1. cPanel → **Git™ Version Control** → **Create** → "Clone a Repository"
   → Repository URL `https://github.com/jalijali-dev/wcm-olahraga77.com.git`,
   Repository Path `repositories/wcm-olahraga77`, Branch `main`.
2. **Ganti `.cpanel.yml`** di repo: `/home/USERNAME/public_html/` →
   path docroot asli.
3. Klik **Manage** repo → **"Deploy HEAD Commit"** (deploy pertama kali).
4. **Manual, sekali, di server** (File Manager atau Terminal cPanel):
   ```bash
   cd ~/public_html
   cp cms-admin/config/database.php.example cms-admin/config/database.php
   cp cms-admin/config/app.php.example cms-admin/config/app.php
   ```
   Isi kredensial DB production asli + generate 2 secret baru
   (`CMS_AI_ENC_SECRET`, `GROWTH_AGENT_DIGEST_TOKEN`) — lihat komentar di
   masing-masing file `.example` buat caranya.
