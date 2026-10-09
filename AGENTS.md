# Quiz — Plugin untuk Jyavani CMS

Plugin quiz, proctoring, dan manajemen tes online untuk Jyavani CMS.

## Aturan Utama

**JANGAN PERNAH menyentuh file di luar direktori plugin ini.** Plugin ini berjalan di atas Jyavani CMS — semua modifikasi harus berada dalam `plugins/quiz/`. File core CMS (`dashboard/`, `cfg/`, `app/`, `public/router.php`, dll.) adalah milik repositori terpisah (`jyavani.git`) dan tidak boleh diubah oleh plugin.

## CMS API yang Digunakan

| API | File | Penggunaan |
|---|---|---|
| `add_action('admin_menu', ...)` | `plugin.php` | Sidebar menu |
| `quiz_install_schema()` | `plugin.php` | Idempotent schema install on validated Quiz requests |
| `add_action('wp_head', ...)` | `plugin.php` | Frontend CSS |
| `register_frontend_route(...)` | `plugin.php` | Exact `/quiz/` and per-Quiz public routes |
| `plugin_manifest()` | `plugin.php` | Version check |
| `settings_get()` / `settings_set()` | `plugin.php` | Version tracking |
| `plugin_static_path()` | CMS | Enforces `static/plugins/quiz/` destinations |
| `plugin_guard_route()` | CMS | Enforces manifest `roles` before admin route inclusion |
| `plugin_resolve_route()` | CMS | Route → file mapping via `plugin.json` |
| `svg_ico()` | CMS | SVG icons di sidebar |
| `csrf_token()` / `csrf_check()` | CMS | CSRF protection |
| `adiwira_require_permission($pdo, ...)` | CMS | Dynamic permission guard |

## Route Convention

Semua admin route didefinisikan di `plugin.json` → `admin.pages[]`. Route pattern: `admin/quiz/{section}[/{action}]`. Compatibility roles harus sama dengan `default_roles` permission route; custom role tetap diotorisasi melalui grant permission dinamis. Master API routes wajib `hidden: true`.

Setiap route terpisah per file — **tidak boleh pakai `$do` switch**. Index/list/Add/Edit/Delete masing-masing file sendiri.

## Authorization Boundaries

- `plugin.quiz.dashboard.access` hanya membuka dashboard Quiz yang capability-aware.
- `plugin.quiz.workspace.access` membuka bank soal pada quiz yang dimiliki atau memiliki assignment `quiz_collaborators`.
- Collaborator bersifat per quiz dan satu quiz dapat memiliki banyak collaborator.
- `question_categories`, `question_passages`, dan `questions` wajib memiliki `created_by`; jawaban mengikuti owner question.
- Collaborator boleh membaca resource bersama, tetapi mutasi hanya untuk resource miliknya. Quiz owner dan `plugin.quiz.content.manage-any` dapat memutasi semua resource dalam quiz.
- Pembuatan dan pengaturan quiz memakai `plugin.quiz.quizzes.manage`, bukan workspace access.
- Attempt read, export, OTP, responses, operate, dan delete adalah permission terpisah. Jangan menggabungkannya kembali menjadi broad permission.
- Semua mutasi harus mengulang authorization di dalam transaction dan mengunci resource yang diperiksa.

## URL Convention

Gunakan `$base/?page=...`, **bukan** `$base/index.php?page=...`. Contoh:
- ✅ `$base/?page=admin/quiz/attempts`
- ❌ `$base/index.php?page=admin/quiz/attempts`

## Frontend

Frontend quiz di `public/`, diakses melalui route exact yang didaftarkan oleh `quiz_register_public_routes()` pada akhir fase frontend `init`; request dashboard mendaftarkan route pada akhir `plugins_loaded` agar pemeriksaan konflik Core melihat namespace yang sama. Prefix global dikonfigurasi lewat Quiz Settings, `/quiz/` tetap menjadi alias kompatibilitas, dan setiap Quiz dapat memiliki prefix publik unik. Dispatcher hanya boleh memuat entrypoint PHP yang tercantum eksplisit di `quiz_frontend_entrypoint()`; jangan membentuk path file dari URL request. API endpoints di `public/api/`.

Asset yang dikelola Core harus dideklarasikan melalui `static.copy` ke `static/plugins/quiz/` dan direferensikan dengan URL `/static/plugins/quiz/...`.

## Dependencies

- PHP >= 8.3
- Jyavani Core >= 2.3.91
- PDO, json, mbstring
- Tabel: `quizzes`, `questions`, `answers`, `passages`, `categories`, `students`, `quiz_attempts`, dll. (schema di `schema.sql`)
