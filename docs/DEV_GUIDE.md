# Olahraga77.com — Dev Guide

> Konvensi teknis wajib + checklist siap pakai untuk siapa pun (manusia atau
> Claude) yang mengerjakan project ini. Diadaptasi dari `DEV_GUIDE.md`
> project `sagacrypto-wpm-goal` (sagagoal.com) — konvensi teknis intinya
> sama karena `cms-admin/` di project ini hasil clone dari sana (lihat
> `/CLAUDE.md` § "Konteks & tujuan"). Untuk konteks project, keputusan
> desain, dan progress terkini, **`/CLAUDE.md` di root adalah satu-satunya
> sumber kebenaran** — project ini belum punya `HANDOFF.md`/`SITEMAP.md`/
> `docs/ROADMAP.md` terpisah seperti sagagoal, semuanya masih digabung di
> `CLAUDE.md`. Untuk alur deploy, lihat `docs/DEPLOY_WORKFLOW.md`.

---

## 1. Checklist konvensi wajib (cek sebelum commit/deliver perubahan apa pun)

- [ ] **`declare(strict_types=1);` adalah statement PERTAMA** di setiap file
      PHP, persis setelah `<?php`. Tidak ada pengecualian, tidak ada file
      "terlalu kecil untuk butuh ini".
- [ ] **PDO selalu dibuat dengan `PDO::ATTR_EMULATE_PREPARES => false`**
      (lihat `cms-admin/config/database.php` — file ini di-`.gitignore`,
      berisi kredensial asli, jangan pernah commit versi dengan kredensial
      asli; edit `database.php.example` kalau mau ubah *struktur* config).
- [ ] **Semua query pakai prepared statement** (`$pdo->prepare()` +
      `->execute([...])`), tidak ada concatenation nilai dari
      `$_GET`/`$_POST`/`$_REQUEST` langsung ke dalam string SQL. Nilai
      numerik yang terpaksa masuk lewat `LIMIT`/`OFFSET` (yang tidak bisa
      di-bind sebagai parameter di semua driver) wajib di-cast `(int)` +
      di-clamp dengan `min()`/`max()` dulu, tidak pernah dipakai mentah.
- [ ] **URL internal frontend publik** → wajib pakai `wpm_base_url()` /
      `wpm_article_url()` / `wpm_category_url()` / `wpm_image_url()`
      (`includes/site-bootstrap.php`). **Jangan pernah** hardcode path
      absolute `/...` langsung — site ini bisa jalan di subfolder dev
      (`/wcm_olahraga77.com/`) maupun domain root production, dan
      hardcode `/` sudah pernah bikin CSS + link artikel putus total di
      test pertama (12 Agu 2026, lihat `/CLAUDE.md`).
- [ ] **URL absolut dari admin balik ke frontend** → pakai
      `cms_public_base_prefix()` (`cms-admin/includes/functions.php`),
      bukan `BASE_URL` mentah.
- [ ] **Link relatif internal admin panel** → pakai `cms_action_href()` /
      `cms_api_href()` / `cms_nav_href()`, bukan hardcode path seperti
      `'../../api/...'`.
- [ ] **Halaman/action admin yang menyentuh role atau data sensitif** →
      pasang `cms_require_role([...])` di baris paling atas (setelah
      `require_once auth.php` + `config/database.php`), sebelum logic apa
      pun jalan. Role tersimpan di `admins.role`
      (`enum('superadmin','admin','editor')`) — selalu lowercase, tanpa
      spasi.
- [ ] **Semua output ke HTML** di-escape lewat `cms_esc()` (admin) /
      `wpm_esc()` (frontend publik) kecuali memang sengaja merender HTML
      tepercaya (mis. konten WYSIWYG artikel dari `pages.content`).
- [ ] **Konsep `sport_key`/livescore SUDAH DIHAPUS TOTAL** dari project ini
      (Opsi A, keputusan final 12 Agu 2026) — jangan tambahkan lagi kode
      yang mengasumsikan integrasi livescore/football-API ada di sini.
      Kategori artikel cukup pakai `category_id`/`category_slug` biasa.
- [ ] **Database olahraga77.com HARUS terpisah** dari database
      sagagoal.com — jangan pernah reuse `DB_NAME`/host yang sama, walau
      dua project ini berbagi struktur `cms-admin/` yang identik. Lihat
      `/CLAUDE.md` § Risiko penting.
- [ ] **Hindari duplicate content / PBN footprint** — konten, desain,
      permalink olahraga77.com harus beda dari sagagoal.com. Jangan
      copy-paste artikel atau reuse aset visual dari sagagoal.com.

---

## 2. Cara jalanin migrasi schema

Project ini **tidak punya folder `cms-admin/migrations/`** (beda dari
sagagoal.com) — semua perubahan schema lewat **auto-migration PHP**
(`cms_ensure_table()` / `cms_ensure_column()` /
`cms_widen_column()` di `cms-admin/includes/schema-guard.php`), jalan
otomatis & idempotent setiap admin membuka halaman yang butuh
tabel/kolom tersebut. Developer cukup pastikan halaman terkait pernah
dibuka sekali di browser (lokal atau production) — tidak ada file `.sql`
manual yang perlu dijalankan user untuk skema baru yang non-destructive.

Migrasi **destructive** (`DROP TABLE`, `DROP COLUMN`) tetap harus:

- Ditulis eksplisit sebagai instruksi ke user (bukan kode yang
  "otomatis jalan").
- Dikonfirmasi eksplisit oleh user sebelum dieksekusi — sandbox Claude
  **tidak punya akses DB live**, tidak pernah bisa dan tidak pernah
  boleh mencoba menjalankan `DROP` sendiri.
- Dicatat statusnya di `/CLAUDE.md` § "Yang BELUM dikerjakan" (contoh:
  drop 12 tabel livescore + kolom `sport_key`, masih opt-in per 12 Agu
  2026).

---

## 3. Batasan sandbox yang perlu diketahui

- **Tidak ada akses PHP/MySQL live** dari sandbox Claude. Verifikasi
  syntax PHP dilakukan lewat pengecekan statis (brace/paren/tag
  balance), bukan `php -l` (biasanya tidak tersedia di sandbox).
- **Tidak ada akses cPanel/hosting production** — semua langkah di
  `docs/DEPLOY_WORKFLOW.md` yang menyentuh server (clone repo di cPanel,
  isi `database.php`/`app.php` asli, jalanin migrasi destructive)
  dikerjakan manual oleh operator.
- **Tidak ada akses git live** untuk push/commit atas nama user tanpa
  diminta eksplisit.
