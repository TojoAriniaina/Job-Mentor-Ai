# AGENTS.md

## What this is

French-language career coaching web app (CV generator, cover letters, interview simulator, oral practice). PHP MVC backend with Composer autoloading + vanilla JS frontend.

## Architecture

```
public/                     # Document root (Apache/XAMPP pointe ici)
├── index.php               # Front controller — toutes les routes API passent par ici
├── .htaccess               # Rewrite rules → index.php
├── frontend/               # HTML + JS + CSS (statiques)
│   ├── index.html
│   ├── pages/              # login, cv, lettre, entretien, oral
│   ├── css/                # style.css (design system) + cv-template.css
│   └── js/                 # api.js, ai.js, utils.js, particles.js
src/                        # PHP source (PSR-4, namespace App\)
├── Controllers/            # Auth, Cv, Lettre, Entretien, Oral, User
├── Models/                 # User, CvDocument, CoverLetter, InterviewHistory, UserNote, OralAnalysis
├── Services/               # LlmService (OpenRouter), AtsScorer
├── Middleware/              # Auth (requireAuth)
└── Router.php              # Routeur simple (tableau routes → controller@method)
bootstrap/app.php           # Autoloader Composer + .env + session + $pdo
config/app.php              # Constantes DB/LLM depuis .env
composer.json               # PSR-4 autoload: App\ → src/
database.sql                # Schéma MySQL (jobmentor_db)
```

- **Database**: MySQL, DB name `jobmentor_db`. Tables: `users`, `cv_documents`, `cover_letters`, `interview_history`, `user_notes`, `oral_analyses`.
- **AI**: OpenRouter API (default model `google/gemini-2.0-flash-001`). Called via `LlmService::call()`.

## Dev server

```bash
# Depuis la racine du projet (public/ est le document root)
php -S localhost:8000 -t public
```

Ou XAMPP/Apache avec DocumentRoot pointant vers `public/`.

## API routing

Routes définies dans `public/index.php`. Le front controller route vers les controllers.

| Controller | Routes | Purpose |
|---|---|---|
| `AuthController` | `/api/auth/{check,login,register,logout,request-reset,reset-password,update-profile}` | Auth + profil |
| `CvController` | `/api/cv/{generate,improve,history,list}` + `/api/cv/{id}` | Génération, analyse, historique CV |
| `LettreController` | `/api/lettre/{generate,correct,save,list}` + `/api/lettre/{id}` | Lettres de motivation |
| `EntretienController` | `/api/entretien/{question,analyze,save-notes,reset,notes/list}` + `/api/entretien/{id}` | Simulation entretien |
| `OralController` | `/api/oral/{analyze,list}` + `/api/oral/{id}` | Entraînement oral |
| `UserController` | `/api/user/{save-apikey,apikey}` | Clés API utilisateur |

Le router supporte l'ancien format `?action=` via `mapLegacyAction()` pour rétrocompatibilité.

Protected routes use `Auth::require()` from `App\Middleware\Auth` — returns 401 JSON if unauthenticated.

## Adding a new endpoint

1. Créer ou modifier le Controller dans `src/Controllers/`
2. Ajouter la route dans `public/index.php`
3. Si nouvelle table : créer le Model dans `src/Models/`

## Frontend JS pattern

- `frontend/js/api.js` — `callAPI(endpoint)` et `postAPI(endpoint, data)` avec `API_BASE = '/api'`.
- `frontend/js/ai.js` — exports AI functions (`window.AI.generateCV`, etc.) qui wrappent `postAPI`.
- `frontend/js/utils.js` — auth check, profile dropdown, toasts, mobile nav, init chain.
- Frontend pages use ES modules (`import` from `api.js`) in `ai.js` but `utils.js` loads as a classic script.

## Key gotchas

- **No `.env` in repo** — copy from README docs if setting up fresh. Required vars: `OPENROUTER_API_KEY`, `LLM_MODEL`, `DB_HOST/USER/PASS/NAME`.
- **`.env` loader is custom** (`config/app.php`) — not `vlucas/phpdotenv`. Only handles `KEY=VALUE` lines.
- **CORS is centralized** in `public/index.php` — `Access-Control-Allow-Origin: *`. If adding a new entry point, keep CORS consistent.
- **Session-based auth** — PHP sessions (`$_SESSION['user_id']`). Frontend uses `credentials: 'include'`.
- **Age is calculated server-side** (`AtsScorer::calculateAgeFromBirthdate()`) from DOB — never by the AI.
- **ATS score is algorithmic** (`AtsScorer`) — keyword matching, skills, experience, structure. LLM provides qualitative analysis only.
- **PDF export** uses jsPDF + html2canvas client-side. No server PDF generation.
- **localStorage is user-scoped** — keys prefixed with `jm_u{userId}_` via `jmKey()` in utils.js.
- **Legacy `?action=` URLs** still work via `Router::mapLegacyAction()` — old frontend pages don't break.

## DB schema changes

The `users` table has inline migration via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in `database.sql` and `AuthController::register()`. Follow this pattern — don't create separate migration files.

## Language

All user-facing strings and code comments are in French. Keep new code consistent with this.
