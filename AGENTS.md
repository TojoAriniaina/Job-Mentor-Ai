# AGENTS.md

## What this is

French-language career coaching web app (CV generator, cover letters, interview simulator, oral training). PHP MVC backend with Composer autoloading + vanilla JS frontend. No build step, no bundler, no test framework.

## Architecture

```
public/                     # Document root (Apache/XAMPP)
├── index.php               # Front controller — routes /api/* to Router, else serves frontend
├── .htaccess               # Rewrite rules → index.php
├── frontend/               # Static HTML + JS + CSS (no build)
│   ├── index.html
│   ├── 404.html
│   ├── pages/              # login, cv, lettre, entretien, oral, admin, dashboard
│   ├── css/                # style.css (design system) + cv-template.css
│   └── js/                 # config.js, api.js, ai.js, utils.js, auth.js, admin.js, particles.js
src/                        # PHP source (PSR-4, namespace App\)
├── Controllers/            # Auth, Cv, Lettre, Entretien, Oral, User, Admin, Tts
├── Models/                 # User, CvDocument, CoverLetter, InterviewHistory, UserNote, OralAnalysis, LoginAttempt
├── Services/               # LlmService (OpenRouter), AtsScorer
├── Middleware/Auth.php     # require() + requireAdmin() — both re-check DB on every request
└── Router.php              # Simple router (array of routes → controller@method)
bootstrap/app.php           # Autoloader Composer + .env + session + $pdo (via $GLOBALS)
config/app.php              # Custom .env loader (KEY=VALUE only, not vlucas/phpdotenv)
composer.json               # PSR-4 autoload: App\ → src/
database.sql                # MySQL schema + inline migrations (ALTER TABLE ... ADD COLUMN IF NOT EXISTS)
```

## Dev server

```bash
# From project root (public/ is the document root)
php -S localhost:8000 -t public
```

Or XAMPP/Apache with DocumentRoot pointing to `public/`. Run `composer install` first to generate the autoloader (no third-party packages, but PSR-4 autoload requires it).

## Frontend loading order

`config.js` must load first (classic script) — it sets `window.API_BASE` and `window.FRONTEND_BASE` dynamically based on the current URL path; every other script depends on it. `api.js` is **never** loaded via `<script>` tag — it only uses `export` and is `import`ed by `ai.js` (`<script type="module">`). Pages that need no AI (entretien.html, admin.html) skip `ai.js` entirely. `utils.js` and `particles.js` are classic scripts. Add any shared helpers to `utils.js`; keep ES-module `export`/`import` only inside the ai.js/api.js pair.

## API routing

Routes defined in `public/index.php` using `Router` methods. The front controller strips the project folder prefix if present (e.g., `/Job-Mentor-Ai/api/...` → `/api/...`).

- **Auth**: `/api/auth/{check,login,register,logout,request-reset,reset-password,update-profile}`
- **CV**: `POST /api/cv/{generate,improve,import-analyze}` + `GET /api/cv/history` + `GET|DELETE /api/cv/{id}`
- **Lettre**: `POST /api/lettre/{generate,correct,save}` + `GET /api/lettre/list` + `GET|POST /api/lettre/{id}`
- **Entretien**: `GET /api/entretien/{question,list,reset}` + `POST /api/entretien/{analyze,save-notes,save}` + `GET /api/entretien/delete/{id}` + notes sub-routes (`notes/list`, `notes/{id}`, `notes/delete/{id}`, `last-answer`)
- **Oral**: `POST /api/oral/analyze` + `GET /api/oral/{list}` + `GET /api/oral/{id}` + `GET /api/oral/delete/{id}`
- **User**: `POST /api/user/save-apikey` + `GET /api/user/apikey`
- **TTS**: `POST /api/tts/speak` — text-to-speech via ElevenLabs
- **Admin**: `GET /api/admin/{users,stats}` + `POST /api/admin/users/{id}/{status,role}` + `DELETE /api/admin/users/{id}`

Legacy `?action=` URLs still work via `Router::mapLegacyAction()`.

## Auth model

- Session-based (`$_SESSION['user_id']`). Frontend uses `credentials: 'include'`.
- `Auth::require()` returns 401 JSON if unauthenticated. Also re-checks DB on every request via `assertStillActive()` to catch admin deactivation immediately.
- `Auth::requireAdmin()` checks session role — used for all `/api/admin/*` routes.
- Login rate limiting: `LoginAttempt` model tracks failures by email+IP, blocks after 5 attempts for 5 minutes.

## Key gotchas

- **No `.env` in repo** — copy from README docs. Required: `OPENROUTER_API_KEY`, `LLM_MODEL`, `DB_HOST/USER/PASS/NAME`. Optional: `OPENROUTER_API_KEY_2` (auto-failover on rate limit/quota errors), `ELEVENLABS_API_KEY`, `ELEVENLABS_VOICE_ID`, `APP_URL` (absolute URL for Open Graph meta tags).
- **`.env` loader is custom** (`config/app.php`) — only handles `KEY=VALUE` lines, no multiline, no export prefix.
- **CORS is centralized** in `public/index.php` — `Access-Control-Allow-Origin: *`. If adding a new entry point, keep consistent.
- **`$pdo` is global** — stored in `$GLOBALS['pdo']` in `bootstrap/app.php`.
- **Age is calculated server-side** (`AtsScorer::calculateAgeFromBirthdate()`) from DOB — never by the AI.
- **ATS score is algorithmic** (`AtsScorer`) — keyword matching, skills, experience, structure. LLM provides qualitative analysis only.
- **PDF export is client-side and lib differs per module** — CV export uses **pdfmake** (`pdfMake.createPdf()`); Lettre export uses **jsPDF directly** (html2canvas was deliberately removed). No server PDF generation.
- **CV/letter import is OCR'd client-side** — pdf.js + tesseract.js loaded from CDN in `cv.html`/`lettre.html`; files never reach the server, only extracted text does.
- **Speech recognition is 100% browser-side** — `entretien.html` and `oral.html` use the Web Speech API (`SpeechRecognition`/`webkitSpeechRecognition`); audio never leaves the browser, only the transcribed text is POSTed.
- **LLM prompts demand strict JSON output** — all controllers send system prompts like "Réponds UNIQUEMENT en JSON valide, sans markdown"; `LlmService::extractJson()` parses the response. Keep this contract for new AI features.
- **localStorage is user-scoped** — keys prefixed with `jm_u{userId}_` via `jmKey()` in `utils.js`.
- **XSS protection** — all dynamic content must pass through `escHtml()` (text) or `escAttr()` (attribute values) from `utils.js`.
- **Admin module** — `admin.html` page + `admin.js` + `AdminController`. Users can only be Read/Update/Delete by admins; creation goes through standard registration.
- **PHP errors go to `logs/php_errors.log`** — `display_errors` is off in `bootstrap/app.php`; debug via the log file, not the browser.

## DB schema changes

Inline migration pattern via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in `database.sql` — run the file again to apply new columns; don't create separate migration files.

## Language

All user-facing strings and code comments are in French. Keep new code consistent.
