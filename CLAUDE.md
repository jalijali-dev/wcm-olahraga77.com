# Project: olahraga77.com — "Tentakel 1" (WCM/PBN) untuk sagagoal.com

## Konteks & tujuan

Ini situs "tentakel" (link-building/WCM) yang nunjuk balik ke
sagagoal.com (the "Kepala"/money site) — lihat diagram operator: Kepala
(sagagoal.com) diperkuat backlink dari beberapa tentakel, salah satunya
olahraga77.com ini.

**Arsitektur yang disepakati (11 Agu 2026):**
- Cuma folder `cms-admin/` yang di-reuse/di-copy dari project
  `wpm_sagagoal.com` (sudah dilakukan operator — folder ini sekarang
  cuma isi `cms-admin/`, TIDAK ada frontend publik).
- Frontend publik (`index.php`, `artikel.php`, dst, `includes/site-bootstrap.php`,
  `assets/css/site.css`) akan dibikin BARU/beda total, BELUM dikerjakan.
- **Livescore HARUS dihapus dari cms-admin ini** — situs ini gak perlu
  API football/basket/F1 sama sekali. Livescore itu situs (sagagoal.com
  ini punya, Tentakel ini TIDAK).
- Growth Agent, Prompt Control, AI Credentials, AI Models, Advertisements,
  Media Library, Banners, Special Pages, Admin Users, Site Settings — SEMUA
  ini TETAP dipakai apa adanya, tidak nyantol ke livescore, aman.
- Konsep `sport_key` di artikel — **SUDAH DIPUTUSKAN (12 Agu 2026,
  "OPSI A"): dihapus total.** Artikel di situs ini cuma pakai
  `category_id`/`category_slug` biasa, sama kayak konten non-sport-
  specific di sagagoal.com. Sudah dieksekusi penuh (lihat poin di
  "Yang SUDAH dikerjakan" di bawah).

## Risiko penting yang harus diingat (jangan skip)

1. **Database HARUS terpisah dari sagagoal.com** — `cms-admin/config/database.php`
   di folder ini masih nunjuk ke config lokal (`wpm_cms_goal`, docker
   default `mysql`/`root`/`root`) — itu config dev lokal, BUKAN kredensial
   production sagagoal.com yang sebenarnya (production diakses lewat
   cPanel terpisah, Claude gak punya akses langsung ke situ — lihat
   workflow deploy di bawah). Tetap, begitu operator siapin hosting/DB
   asli buat olahraga77.com di cPanel, `database.php` ini WAJIB diarahin
   ke DB BARU YANG BENAR-BENAR TERPISAH dari DB sagagoal.com — jangan
   pernah reuse DB yang sama, nanti data 2 situs kecampur.
2. **Hindari duplicate content & PBN footprint** — kalau konten
   olahraga77.com hasil copy-paste dari sagagoal.com, atau tema/struktur
   kelewat mirip, Google bisa deteksi ini sebagai jaringan PBN dan
   nge-penalti DUA-duanya (termasuk sagagoal.com sebagai money site).
   Konten harus digenerate terpisah (misal Growth Agent versi
   olahraga77.com sendiri, prompt/gaya beda), desain/tema harus beda
   secara visual.

## Yang SUDAH dikerjakan

**11 Agu 2026:**
- Operator copy folder `cms-admin/` dari `wpm_sagagoal.com` ke
  `wcm_olahraga77.com` — DONE.
- Folder ini di-connect ke Cowork (Claude sudah punya akses baca/tulis).
- Semua halaman/aksi terkait livescore dihapus dari `cms-admin`: 15 file
  (`pages/livescore-api-settings.php`, `pages/matches.php`,
  `pages/f1-podium.php`, `f1-races.php`, `f1-standings.php`,
  `pages/nba-games.php`, `actions/f1-api-test.php`, `f1-sync-now.php`,
  `actions/basketball-api-test.php`, `basketball-sync-now.php`,
  `actions/livescore-api-test.php`, `livescore-api-leagues.php`,
  `livescore-sync-now.php`, `actions/sport-toggle.php`,
  `sport-request-submit.php`) + 3 partial template orphan
  (`partials/settings-form-football.php`, `settings-form-f1.php`,
  `settings-form-basketball.php`).
- `cms-admin/includes/sidebar.php` dibersihin — grup nav "Integrations"
  dan "Sports Data" (NBA Games, F1 Races/Podium/Standings, Livescore API
  Settings) dicabut total.
- `cms-admin/pages/dashboard.php` — quick-action "Livescore API
  Settings" dicabut.

**12 Agu 2026:**
- **Konsep `sport_key` dihapus total dari codebase (Opsi A, keputusan
  final operator).** Dieksekusi di:
  - `cms-admin/pages/pages.php` — validasi, INSERT/UPDATE, dan dropdown
    "Cabang Sport" di form create/edit artikel semua dicabut. Kolom DB
    `sport_key` (schema migration di baris 52) SENGAJA dibiarin ada tapi
    gak dipakai lagi — harmless, gampang di-drop belakangan kalau mau.
  - `cms-admin/includes/growth-agent-service.php` — system prompt AI
    (`$defaultSystemPrompt`) gak lagi minta AI output `sport_key`,
    picklist "Valid sport_key options" (query ke tabel `sports`)
    dicabut, parsing response AI dan INSERT `pages` gak lagi nyentuh
    `sport_key`. `cms_growth_agent_build_cover_image_prompt()` sekarang
    gak butuh param `$sportKey` lagi — jalur keyword-matching title
    (`$offSiteSportKeywords`, misal deteksi "motogp"/"f1"/"sepak bola"
    dari judul) yang dulu cuma jalan pas `sport_key === 'general'`,
    sekarang jalan selalu buat semua artikel.
  - Sudah di-grep-sweep: gak ada lagi query `SELECT ... FROM sports`
    atau referensi ke tabel `sports_api_settings` di manapun di
    `cms-admin`. Tabel `sports`/`sports_api_settings` sendiri gak lagi
    di-create otomatis oleh kode manapun (dulu juga gak ada
    `cms_ensure_table` buat tabel itu, aman).

**12 Agu 2026 (lanjutan) — Setup DB lokal + admin panel siap pakai:**
- Operator udah setup DB dev lokal SENDIRI (bukan production cPanel):
  `cms-admin/config/database.php` diarahin ke `DB_NAME=wpm_cms_olahraga77`
  (docker `mysql`/`root`/`root`, genuinely terpisah dari `wpm_cms_goal`
  punya sagagoal.com). Database ini hasil clone struktur dari
  sagagoal.com (lengkap sama tabel livescore-nya), TAPI kode `cms-admin`
  di folder ini sudah gak baca tabel-tabel itu lagi.
- Tabel yang aman didrop dari `wpm_cms_olahraga77` (udah dikonfirmasi via
  grep — gak ada satupun query yang nyentuh, kecuali `leagues` yang
  SENGAJA dipertahanin karena masih dipakai dropdown "Liga" di
  `pages.php`): `f1_constructor_standings`, `f1_driver_standings`,
  `f1_race_podium`, `f1_races`, `fixtures`, `livescore_cache`,
  `nba_games`, `nba_teams`, `sport_requests`, `sports`,
  `sports_api_settings`, `teams`. Operator belum eksekusi drop-nya per
  12 Agu 2026 — masih di tabel task list di bawah.
- Branding "Sagagoal" dibersihin dari cms-admin: `CMS_ADMIN_TAGLINE`
  (config/app.php) dan tagline hardcoded di `login.php` diganti jadi
  "OLAHRAGA77.COM" / "Admin Panel - Olahraga77.com". Semua system prompt
  AI (Growth Agent SEO/content strategist, Agent SEO article/FAQ/meta
  generator, growth-agent-digest) yang tadinya bilang "Sagagoal, a
  livescore & sports news website" diganti netral jadi "an
  Indonesian-language sports news website" — biar AI gak nulis
  "Sagagoal" di konten olahraga77.com atau nganggep situs ini masih ada
  livescore.
- Admin user pertama (`admin@olahraga77.com`, superadmin) berhasil login
  — sempat gagal karena password_hash di tabel `admins` gak cocok sama
  password yang mau dipakai (`Admin123`), udah di-reset via script
  sekali-pakai (sudah dihapus lagi setelah selesai, jangan bikin lagi
  kecuali kejadian serupa).
- Sidebar admin (Pages & Articles, SEO Settings, AI Management,
  Advertisements) SEMPAT keliatan hilang — ternyata bukan bug, itu cuma
  soal scroll (`.admin-sidebar__nav` punya `overflow: auto` sendiri,
  kotak akun di bawah posisinya fixed/`flex-shrink: 0` independen dari
  scroll nav). Kalau muncul lagi keluhan serupa, ingetin buat scroll di
  DALAM sidebar dulu sebelum curiga ada yang kehapus.

**12 Agu 2026 (lanjutan #2) — FRONTEND PUBLIK v1 dibikin:**
- Keputusan desain (disepakati sama operator sebelum ngoding, referensi
  visual: https://superball.bolasport.com untuk struktur, TAPI warna &
  permalink sengaja dibikin beda biar gak PBN-footprint):
  - Palet: **charcoal + hijau** (bukan biru ala SuperBall/bolasport).
  - Permalink artikel: **`/artikel/{slug}`** (bukan `/read/{id}/{slug}`
    ala SuperBall).
  - Kategori URL: **`/kategori/{slug}`**.
  - Nav menu (4 item, fokus sepakbola domestik Indonesia sesuai arahan
    operator — bola dunia porsinya kecil, masuk "Ragam"): **TIMNAS /
    LIGA 1 / TRANSFER / RAGAM**.
- File baru yang dibikin (semua di project root, TERPISAH dari
  `cms-admin/` — cuma reuse `cms-admin/config/database.php` buat
  koneksi DB):
  - `includes/site-bootstrap.php` — koneksi DB, helper query artikel/
    kategori (`wpm_get_articles`, `wpm_get_article_by_slug`,
    `wpm_get_related_articles`, `wpm_get_popular_articles`,
    `wpm_increment_views`), helper URL (`wpm_article_url`,
    `wpm_category_url`), helper tampilan (`wpm_esc`, `wpm_image_url`,
    `wpm_time_ago`). Juga isi `wpm_site_migrate_categories()` — migrasi
    idempotent yang jalan tiap page load.
  - `includes/site-header.php`, `includes/site-footer.php` — partial
    header (2 layer: brand+search, lalu nav hijau solid) & footer.
  - `assets/css/site.css` — palet charcoal+hijau, card-based layout.
  - `index.php` — homepage (hero featured + list terkini + sidebar
    populer).
  - `kategori.php` — listing per kategori (`?slug=` lewat rewrite
    `/kategori/{slug}`), pagination.
  - `artikel.php` — detail artikel (`?slug=` lewat rewrite
    `/artikel/{slug}`), breadcrumb, related articles, sidebar populer,
    increment views.
  - `.htaccess` — rewrite rule buat kedua permalink di atas.
- **Migrasi kategori (task #1 lama, SUDAH dieksekusi):**
  `wpm_site_migrate_categories()` di `site-bootstrap.php` collapse
  kategori lama era livescore (Sepak Bola, Liga Champions, Liga
  Inggris, Business, Sports, Livescore, Apps, Guides, General News) ke
  cuma 4: **Timnas, Liga 1, Transfer, Ragam** — artikel yang nyantol ke
  kategori lama otomatis dipindah ke "Ragam" dulu sebelum kategori lama
  itu dihapus. Fungsi ini idempotent & jalan otomatis tiap ada yang
  buka halaman publik (cek stale category dulu, no-op kalau udah
  bersih) — **belum divalidasi langsung ke DB live** karena sandbox
  Claude gak punya akses ke docker MySQL operator (host `mysql` cuma
  reachable dari dalam network docker operator). Operator perlu buka
  homepage sekali di browser buat trigger migrasi ini jalan, baru cek
  di admin panel (Pages & Articles) apakah kategori di dropdown udah
  keganti jadi 4 itu aja.
- **Verifikasi visual pertama (12 Agu 2026, via Claude in Chrome operator)
  — ketemu 1 bug, SUDAH DIFIX:** operator akses site di
  `http://localhost:8008/wcm_olahraga77.com/` (subfolder, bukan domain
  root). Semua URL internal awalnya di-hardcode absolute `/...` (CSS,
  link artikel/kategori, home link, search form action) — asumsi site
  di root domain. Akibatnya: CSS gak ke-load (tampilan jadi link biru
  default browser, gak ke-style sama sekali) dan link artikel/kategori
  kepotong prefix-nya (`localhost:8008/artikel/...` bukan
  `localhost:8008/wcm_olahraga77.com/artikel/...`).
  - **Fix:** nambah `WPM_BASE_PATH` (dihitung dari
    `dirname($_SERVER['SCRIPT_NAME'])` — reliable walau lewat rewrite
    `.htaccess` karena SCRIPT_NAME selalu physical file yang jalan, bukan
    URI hasil rewrite) + helper `wpm_base_url()` di `site-bootstrap.php`.
    Semua URL internal (CSS href, `wpm_article_url()`,
    `wpm_category_url()`, `wpm_image_url()`, home link, search form
    action) sekarang lewat helper ini — otomatis kerja baik di subfolder
    dev (`/wcm_olahraga77.com/`) maupun domain root production nanti.
  - File yang kena: `includes/site-bootstrap.php`,
    `includes/site-header.php`, `includes/site-footer.php`,
    `artikel.php`, `kategori.php`.
  - **Belum ditest ulang di browser setelah fix ini** — operator perlu
    hard refresh (`Cmd+Shift+R`) halaman yang lagi dibuka buat konfirmasi
    CSS udah ke-load dan link artikel/kategori udah bener.

**12 Agu 2026 (lanjutan #3) — SEO dasar (robots.txt, sitemap.xml, favicon):**
- `robots.txt` dibikin di root — allow semua, disallow `/cms-admin/`,
  `/includes/`, `/cari.php`. **Sitemap URL di dalamnya masih hardcoded
  `https://olahraga77.com/sitemap.xml`** — kalau domain production beda,
  ganti manual sebelum go-live.
- `sitemap.php` (index) + `sitemap-file.php` (per-bucket urlset) dibikin
  di root, reuse penuh modul `cms-admin/includes/sitemap-service.php`
  yang udah ada (tabel `sitemap_urls`, diisi otomatis tiap artikel/
  kategori disave dari admin, atau lewat tombol "Regenerate Sitemap" di
  admin). `.htaccess` ditambah rewrite `/sitemap.xml` dan
  `/sitemap-*.xml` ke file-file itu.
  - **Ketemu & difix 2 bug lama di `sitemap-service.php`** (peninggalan
    clone sagagoal, belum pernah dites karena situs ini publiknya baru
    ada sekarang): `cms_sitemap_path_for('category', ...)` generate URL
    `berita/kategori/{slug}` yang gak match sama route asli situs ini
    (`kategori/{slug}`) — udah dibenerin. Dan `cms_sitemap_full_resync()`
    ada entry statis "Semua Berita" nunjuk ke `kategori` tanpa slug yang
    bakal 404 (`kategori.php` butuh `?slug=`) — entry itu dicabut.
  - **Sitemap masih KOSONG sampai admin klik "Regenerate Sitemap"**
    (Special Pages -> Sitemaps, atau menu sitemap manapun itu ditaruh)
    minimal sekali, atau sampai ada artikel baru disave (hook otomatis
    jalan). Operator perlu trigger itu sebelum submit sitemap ke Google
    Search Console.
- Favicon: `assets/img/favicon.svg` (mark hijau+charcoal, "77") +
  `favicon.ico` fallback (di-generate dari svg via ImageMagick, size
  16/32/48/64). Dikaitkan di `includes/site-header.php` (`<link
  rel="icon">` + `rel="alternate icon"`).
- **Belum ditest di browser** — sama kayak fix sebelumnya, operator
  perlu buka `/robots.txt`, `/sitemap.xml` langsung, dan cek tab
  browser buat favicon-nya muncul.

**12 Agu 2026 (lanjutan #4) — Git + deploy workflow disiapin:**
- Repo GitHub dibikin operator: `jalijali-dev/wcm-olahraga77.com`. Belum
  pernah di-push isinya (masih kosong per commit ini).
- `.gitignore`, `.cpanel.yml`, `docs/DEPLOY_WORKFLOW.md`,
  `docs/DEV_GUIDE.md` dibikin, diadaptasi dari workflow
  `sagacrypto-wpm-goal` (sagagoal.com) yang operator kasih contoh.
- **`cms-admin/config/database.php` DAN `cms-admin/config/app.php`
  di-gitignore** (bukan cuma database.php seperti awalnya) — app.php
  ternyata juga nyimpen secret asli (`CMS_AI_ENC_SECRET`,
  `GROWTH_AGENT_DIGEST_TOKEN`) hardcoded, ketauan pas nyusun
  `.gitignore` ini. Dua-duanya sekarang punya versi `.example` buat
  ditemplate ulang di server manual (lihat instruksi di masing-masing
  file `.example`).
- `.cpanel.yml` pakai `rsync` dengan `--exclude` buat `database.php` dan
  `uploads/` — jadi tombol "Deploy HEAD Commit" di cPanel aman dipakai
  tanpa risiko nimpa kredensial/media asli di server. **Path
  `/home/USERNAME/public_html/` di dalamnya masih placeholder** — WAJIB
  diganti ke path docroot asli sebelum dipakai (operator yang tau
  username cPanel-nya, Claude gak punya akses).
- **Repo GitHub sempat berstatus Public** — operator udah diingetin buat
  ganti ke Private sebelum push (biar `database.php`/`app.php` gak
  kebuka ke publik kalau somehow ke-commit karena gitignore-nya
  kelewat). Belum dikonfirmasi udah diganti apa belum per commit ini.

## Yang BELUM dikerjakan — task list buat lanjut

1. Opsional: jalanin `DROP TABLE` buat 12 tabel livescore yang gak
   kepake lagi (list lengkap di atas) + `ALTER TABLE pages DROP COLUMN
   sport_key` kalau operator mau beres-beres schema DB dev lokal (gak
   urgent, semuanya cuma nganggur, gak ganggu fungsi apapun).
2. **Verifikasi frontend publik di browser** (lihat poin "Belum
   diverifikasi visual" di atas) — cek homepage, detail artikel,
   kategori, dan rewrite `.htaccess` beneran jalan di docker operator.
3. ~~Halaman `/cari.php`~~ — sudah dibikin (search sederhana LIKE
   query di title/excerpt).
4. Begitu operator siapin hosting/DB production asli di cPanel buat
   olahraga77.com, `cms-admin/config/database.php` WAJIB diganti lagi
   dari config dev lokal ke kredensial production (jangan sebelum itu).

## Cara lanjut sesi ini

Baca file ini dulu di awal sesi, terus cek poin "Yang BELUM dikerjakan"
di atas buat tau progress terakhir. Kalau ada keputusan besar yang
belum jelas (desain frontend, kredensial DB production), TANYA operator
dulu — jangan asumsi sendiri, ini project SEO/backlink yang sensitif
soal footprint & duplicate content.
