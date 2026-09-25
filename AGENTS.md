# AGENTS.md - Job Mentor Ai

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
│   ├── libs/               # CDN vendored (hors build) : pdfmake, jsPDF, html2canvas, pdf.js, tesseract.js (+core wasm, lang fra/eng .gz), fontawesome, fonts
│   ├── css/                # style.css (design system) + cv-template.css
│   └── js/                 # config.js, api.js, ai.js, utils.js, admin.js, particles.js
src/                        # PHP source (PSR-4, namespace App\)
├── Controllers/            # Auth, Cv, Lettre, Entretien, Oral, User, Admin, Tts
├── Models/                 # User, CvDocument, CoverLetter, InterviewHistory, UserNote, OralAnalysis, LoginAttempt
├── Services/               # LlmService (OpenRouter), AtsScorer, EncryptService (AES-256 for user API keys)
├── Middleware/Auth.php     # require() + requireAdmin() — both re-check DB on every request
├── Middleware/Csrf.php     # Per-session token; Csrf::protect() runs in the front controller before dispatch
└── Router.php              # Simple router (array of routes → controller@method)
bootstrap/app.php           # Autoloader Composer + .env + session + $pdo (via $GLOBALS)
config/app.php              # Custom .env loader (KEY=VALUE only, not vlucas/phpdotenv)
tools/validation_lettre.php # Assertion script for the letter module (no DB, no LLM)
.htaccess                   # Project-root guards: -Indexes, deny dotfiles/.log/.sql, deny
                            # *.md|*.txt|*.json|*.lock at root, deny PHP execution outside public/,
                            # block src|config|bootstrap|logs|vendor|tools
composer.json               # PSR-4 autoload: App\ → src/, php+ext requirements, scripts serve/validate
composer.lock               # Committed (no third-party packages, but pins platform + autoloader)
database.sql                # MySQL schema + inline migrations (ALTER TABLE ... ADD COLUMN IF NOT EXISTS)
```

## Dev server
```bash
php -S localhost:8000 -t public
# Or XAMPP/Apache with DocumentRoot pointing to `public/`. Run `composer install` first.
```

## Frontend loading order
`config.js` must load first (classic script) — it sets `window.API_BASE` and `window.FRONTEND_BASE` dynamically based on the current URL path; every other script depends on it. It also installs the global `window.fetch` CSRF wrapper (adds `X-CSRF-Token` to same-origin `/api/` non-GET calls, from `window.JM_CSRF_TOKEN` or the `csrf_token` cookie) — `checkAuthStatus()` in `utils.js` and the login/register handlers set `window.JM_CSRF_TOKEN` from the server response. `api.js` is **never** loaded via `<script>` tag — it only uses `export` and is `import`ed by `ai.js` (`<script type="module">`). `admin.html` skips `ai.js` entirely. `entretien.html` and `oral.html` also use ES module `<script type="module">` inline to import from `ai.js`/`api.js`. `utils.js` and `particles.js` are classic scripts. Add any shared helpers to `utils.js`; keep ES-module `export`/`import` only inside the ai.js/api.js pair.

## API routing
Routes defined in `public/index.php` using `Router` methods. The front controller strips the project folder prefix if present (e.g., `/Job-Mentor-Ai/api/...` → `/api/...`).

- **Auth**: `/api/auth/{check,login,register,logout,request-reset,reset-password,update-profile}`
- **CV**: `POST /api/cv/{generate,improve,import-analyze}` + `GET /api/cv/history` + `GET|DELETE /api/cv/{id}`
- **Lettre**: `POST /api/lettre/{generate,analyze,correct,save}` + `GET /api/lettre/list` + `GET /api/lettre/{id}` (retrieve) + `POST /api/lettre/{id}` (delete)
- **Entretien**: `GET /api/entretien/{question,list}` + `POST /api/entretien/{analyze,save-notes,save,reset,delete/{id}}` + notes sub-routes (`GET notes/list`, `GET notes/{id}`, `POST notes/delete/{id}`, `POST last-answer`)
- **Oral**: `POST /api/oral/analyze` + `GET /api/oral/list` + `GET /api/oral/{id}` + `POST /api/oral/delete/{id}`
- **User**: `POST /api/user/save-apikey` + `GET /api/user/apikey`
- **TTS**: `POST /api/tts/speak` — text-to-speech via ElevenLabs
- **Admin**: `GET /api/admin/{users,stats}` + `POST /api/admin/users/{id}/{status,role}` + `DELETE /api/admin/users/{id}`

Legacy `?action=` URLs still work via `Router::mapLegacyAction()`.

## Auth model
- Session-based (`$_SESSION['user_id']`). Frontend uses `credentials: 'include'`.
- `Auth::require()` returns 401 JSON if unauthenticated. Also re-checks DB on every request via `assertStillActive()` to catch admin deactivation immediately.
- `Auth::requireAdmin()` checks session role — used for all `/api/admin/*` routes.
- Login rate limiting: `LoginAttempt` model tracks failures by email+IP, blocks after 5 attempts for 5 minutes.
- **CSRF**: per-session token in `$_SESSION['csrf_token']` (`src/Middleware/Csrf.php`). `Csrf::protect()` runs in `public/index.php` before dispatch on every POST/DELETE: origin check (Origin/Referer vs host) on ALL state-changing requests, then `X-CSRF-Token` header compared to session — 403 JSON on failure. Exempt from the token layer (no session yet): `/api/auth/login|register|request-reset` (legacy `?action=` equivalents too). Token delivered by `GET /api/auth/check` (even for guests) and by a JS-readable `csrf_token` cookie set on every API response; rotated on login/register.

## Key gotchas
- **No `.env` in repo** — copy `.env.example`. Required: `OPENROUTER_API_KEY`, `LLM_MODEL`, `DB_HOST/USER/PASS/NAME`, `APP_KEY` (**exactly 64 hex chars**, `openssl rand -hex 32`; `EncryptService` now rejects anything else — a non-hex key used to become `hex2bin() === false`, i.e. an all-zero AES key that silently encrypted every installation's API keys with no secret at all). Optional: `OPENROUTER_API_KEY_2` (auto-failover on rate limit/quota errors), `ELEVENLABS_API_KEY` (+ `ELEVENLABS_API_KEY_2` — same auto-failover pattern on quota/auth errors), `ELEVENLABS_VOICE_ID`, `APP_URL` (absolute URL for Open Graph meta tags — note: `index.html` currently hardcodes a demo domain instead of using `APP_URL`). Note: config/app.php defaults `LLM_MODEL` to `google/gemini-2.0-flash-001` if missing — set it explicitly in `.env`.
- **`.env` loader is custom** (`config/app.php`) — only handles `KEY=VALUE` lines, no multiline, no export prefix.
- **CORS is centralized** in `public/index.php` — echoes back the request `Origin` header (not `*`) with `Allow-Credentials: true`; `Allow-Headers` includes `X-CSRF-Token`. Any new API entry point must call `Csrf::protect($method, $uri)` before dispatch.
- **`$pdo` is global** — stored in `$GLOBALS['pdo']` in `bootstrap/app.php`.
- **Age is calculated server-side** (`AtsScorer::calculateAgeFromBirthdate()`) from DOB — never by the AI.
- **ATS score is algorithmic** (`AtsScorer`) — keyword matching, skills, experience, structure. LLM provides qualitative analysis only.
- **PDF export is client-side and lib differs per module** — CV export uses **pdfmake** (`pdfMake.createPdf()`); Lettre export uses **jsPDF** (+ **html2canvas** for the canvas-based paths in `lettre.html`). No server PDF generation.
- **All front-end libraries are vendored locally** — pdf.js, tesseract.js (worker + wasm core + `fra`/`eng` traineddata), pdfmake, jsPDF, html2canvas, Font Awesome and the Google-font woff2 files live in `public/frontend/libs/` and are referenced by relative path — **no CDN at runtime**, so import/OCR and PDF exports work offline. Tesseract workers must keep the explicit `{ workerPath, corePath, langPath, gzip: true }` options (`tesseract.js-core`'s `-simd-lstm` variant is the one `createWorker` requests by default). If a lib is upgraded, re-run the headless offline check.
- **CV/letter import is OCR'd client-side** — pdf.js + tesseract.js (local `libs/`); files never reach the server, only extracted text does.
- **Speech recognition is 100% browser-side** — `entretien.html` and `oral.html` use the Web Speech API (`SpeechRecognition`/`webkitSpeechRecognition`); audio never leaves the browser, only the transcribed text is POSTed.
- **LLM prompts demand strict JSON output** — all controllers send system prompts like "Réponds UNIQUEMENT en JSON valide, sans markdown"; `LlmService::extractJson()` parses the response. Keep this contract for new AI features.
- **localStorage is user-scoped** — keys prefixed with `jm_u{userId}_` (logged-in) or `jm_guest_` (anonymous) via `jmKey()` in `utils.js`.
- **XSS protection** — all dynamic content must pass through `escHtml()` (text) or `escAttr()` (attribute values) from `utils.js`.
- **Admin module** — `admin.html` page + `admin.js` + `AdminController`. Users can only be Read/Update/Delete by admins; creation goes through standard registration.
- **PHP errors go to `logs/php_errors.log`** — `display_errors` is off in `bootstrap/app.php`; debug via the log file, not the browser.

## DB schema changes
Inline migration pattern via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` in `database.sql` — run the file again to apply new columns; don't create separate migration files.

## Verification
No test framework, linter, or formatter. There is a hand-written assertion script for the letter module: `php tools/validation_lettre.php` (also `composer run validate`) — 46 checks on `AtsScorer::calculateLetterQualityScore()` and the `analyze()`/`correct()` contracts, no DB and no LLM call; it must print `Tests échoués: 0`. To verify anything else: start the dev server (`php -S localhost:8000 -t public` or `composer run serve`), exercise the affected endpoints via browser or curl, and check `logs/php_errors.log` for errors (the app sets `display_errors=0`, so a PHP fatal prints nothing to the client — if a CLI script exits silently, read that log). There is no `npm test`, `composer test`, or CI pipeline.

## Language
All user-facing strings and code comments are in French. Keep new code consistent.

## Letter analysis/improvement system (CRITICAL)

### Global flow (IMPORTANT)
IMPORT/COLLAGE DE LA LETTRE → ANALYSE DE LA LETTRE ORIGINALE → AFFICHAGE: Score, Points forts, Points à améliorer, Anomalies détectées, Recommandations → "Améliorer ma lettre" → RÉÉCRITURE IA → CONTRÔLE DE LA VERSION PRODUITE → LETTRE AMÉLIORÉE

Le diagnostic initial et la réécriture IA sont deux étapes distinctes.

### Score — CRITICAL CONSTRAINT
**Le score est calculé UNIQUEMENT côté PHP sur la lettre originale.**
- Utiliser: `AtsScorer::calculateLetterQualityScore($text)`
- Ne demander aucun score à l'IA.
- Ne pas ajouter "score_original" ou "score_ameliore" dans la réponse IA.
- Le backend peut ajouter le score initial au résultat final de l'analyse.
- La lettre corrigée ne doit avoir AUCUN score.

### Analyse initiale côté backend (point d'entrée)
La méthode `analyze()` doit rester déterministe et sans IA.
- Elle doit calculer: score, détails du score, points forts, points faibles, recommandations, anomalies détectables automatiquement.
- Les duplications/répétitions (segments, lignes, formules d'ouverture/clôture) sont détectées UNIQUEMENT par `AtsScorer::calculateLetterQualityScore()` (règles ancorées + listes d'exclusion); `analyze()` fusionne ces anomalies, sans dupliquer de détecteurs locaux — sinon faux positifs sur « Madame, Monsieur » ouverture+clôture.
- Elle doit notamment pouvoir détecter: répétitions évidentes, duplications, placeholders, longueur, structure, formules de politesse, variété du vocabulaire, formulations génériques, anomalies dans l'en-tête.

### Frontend behavior (après import/collage)
Après import/collage :
- Afficher uniquement l'analyse de la lettre originale: Score, Détails, Points forts, Points à améliorer, Recommandations.
- Puis afficher: "Votre lettre a été analysée. Cliquez ci-dessous pour que l'IA l'améliore."
- Bouton: "Améliorer ma lettre"
- Après amélioration :
  * Afficher: analyse de l'IA, améliorations, recommandations éventuelles, lettre améliorée, Enregistrer, PDF, Copier.
  * NE PAS afficher de score sur cette deuxième partie.
  * NE PAS afficher "68 → 84".

### Format JSON IA
La réponse IA doit STRICTEMENT respecter ce format :
```json
{
"analyse": [
"..."
],
"texte_corrige": "...",
"ameliorations": [
{
"avant": "...",
"probleme": "...",
"apres": "...",
"justification": "..."
}
],
"recommandations": [
"..."
]
}
```
- "ameliorations" peut contenir 0 à 8 éléments.
- Si la lettre est déjà très bonne, ne pas inventer artificiellement des améliorations.
- Les améliorations doivent être les changements les plus importants.
- Ne pas remplir la liste avec uniquement des corrections mineures d'orthographe.

### Analyse IA (chapitre 15)
Le champ "analyse" doit expliquer les vrais problèmes détectés.
- Exemples: "L'en-tête contient une duplication de la formule « À l'attention du ».", "L'introduction est trop générale.", "Certaines idées sont répétées.", "Les compétences techniques sont présentes mais insuffisamment reliées au poste.", "La conclusion peut être rendue plus directe."
- Ne pas inventer de problèmes qui n'existent pas.

### Améliorations IA (chapitre 16)
Afficher uniquement les améliorations significatives.
- "ameliorations" peut contenir 0 à 8 éléments.
- Si la lettre est déjà très bonne, ne pas inventer artificiellement des améliorations.
- Les améliorations doivent être les changements les plus importants.
- Ne pas remplir la liste avec uniquement des corrections mineures d'orthographe.

### Anti-invention — RÈGLE ABSOLUE
La version corrigée ne doit jamais inventer :
- expérience professionnelle, stage, diplôme, certification, compétence, logiciel, langage de programmation, technologie, projet, responsabilité, résultat, chiffre, entreprise, poste occupé, mission, niveau de langue, connaissance particulière.
- Une reformulation est autorisée.
- L'ajout d'un fait non présent dans la source est interdit.
- Ne pas confondre amélioration rédactionnelle et ajout d'informations.
- SOURCE: "Je suis capable de résoudre des problèmes complexes."
- AUTORISÉ: "Ma capacité à résoudre des problèmes complexes me permet d'aborder les difficultés techniques avec efficacité."
- INTERDIT: "J'ai développé une méthode rigoureuse d'analyse et d'optimisation des performances."

### Ne pas surinterpréter les compétences
Si l'offre d'emploi mentionne: React, Laravel, Docker, AWS, Python — cela ne signifie PAS que le candidat maîtrise ces technologies.
- L'IA ne doit jamais ajouter ces technologies dans la lettre simplement parce qu'elles apparaissent dans l'offre.
- Elle peut seulement les utiliser si le candidat les mentionne lui-même ou fournit explicitement cette information.

### Personnalité du candidat
La réécriture doit conserver la personnalité et le niveau réel du candidat.
- Ne pas transformer automatiquement une lettre simple en lettre excessivement sophistiquée.
- Le résultat doit rester: naturel, humain, crédible, professionnel, fluide, adapté au candidat.
- Éviter le langage artificiellement pompeux.

### Éviter les phrases génériques
Éviter les formulations génériques lorsqu'elles n'apportent aucune information réelle.
- Sauf si elles apportent vraiment quelque chose.
- Chaque phrase doit idéalement apporter: une information, une compétence, une motivation, un argument, ou un lien concret avec le poste.

### Personnalisation
Si une offre d'emploi est fournie, analyser les éléments pertinents de l'offre:
- intitulé du poste, missions, compétences recherchées, technologies, environnement, responsabilités, valeurs ou objectifs mentionnés.
- Mettre en évidence les correspondances réelles avec le candidat.
- Mais ne jamais inventer une correspondance.
Si l'offre n'est pas fournie, ne pas prétendre connaître les besoins précis de l'entreprise.

### Structure recommandée
Lorsque cela est pertinent, organiser la lettre selon une structure logique :
1. En-tête / destinataire / date
2. Objet
3. Formule d'appel
4. Introduction
5. Présentation du profil
6. Compétences et expériences pertinentes
7. Correspondance avec le poste
8. Motivation pour le poste / entreprise
9. Conclusion
10. Formule de politesse
11. Signature
- Ne pas appliquer cette structure mécaniquement si la lettre source nécessite une autre organisation.

### En-tête et présentation
L'IA doit également vérifier la cohérence de la présentation.
- Exemples problématiques: "À l'attention du À l'attention du Responsable du recrutement" → Corriger en: "À l'attention du Responsable du recrutement"
- Autres exemples à détecter: "Objet : Objet : Candidature...", "Madame, Monsieur, Madame, Monsieur,", "Antananarivo, le 20 septembre 2026 Antananarivo, le 20 septembre 2026", "Je vous prie d'agréer... Je vous prie d'agréer...", "Antananarivo, le 20 septembre 2026 Antananarivo, le 20 septembre 2026"

### Format et longueur
Ne pas rallonger artificiellement la lettre.
- Une lettre courte mais pertinente est préférable à une lettre longue et remplie de phrases génériques.
- Généralement viser une lettre concise, lisible et adaptée à une page.
- Mais ne jamais supprimer une information importante uniquement pour atteindre une longueur donnée.

### Analyse avant réécriture (chapitre 13)
Avant de produire la version finale, effectuer mentalement :
- ÉTAPE 1 — DIAGNOSTIC: Identifier les problèmes.
- ÉTAPE 2 — STRUCTURE: Déterminer si l'organisation doit être améliorée.
- ÉTAPE 3 — NETTOYAGE: Supprimer: duplications, répétitions, formulations inutiles, erreurs, placeholders, anomalies.
- ÉTAPE 4 — RÉÉCRITURE: Reconstruire les phrases et paragraphes faibles.
- ÉTAPE 5 — PERSONNALISATION: Utiliser uniquement les informations réellement disponibles.
- ÉTAPE 6 — CONTRÔLE ANTI-INVENTION: Comparer mentalement chaque nouvelle information avec la source.
- ÉTAPE 7 — CONTRÔLE FINAL: Vérifier: orthographe, grammaire, syntaxe, cohérence, répétitions, structure, professionnalisme, naturel, absence d'invention, absence de duplication.

### Règle très importante sur la qualité
Avant de retourner "texte_corrige", vérifier que la nouvelle lettre est réellement meilleure que l'originale.
- Une amélioration ne signifie pas nécessairement utiliser un vocabulaire plus compliqué.
- Une bonne amélioration signifie: moins d'erreurs, moins de répétitions, meilleure structure, meilleure clarté, meilleure précision, arguments mieux organisés, motivation plus naturelle, meilleure lisibilité, aucune invention, aucune duplication, aucune phrase artificiellement ajoutée.
- Si la lettre originale est déjà bonne sur un point, ne pas la modifier sans raison.
- Si une partie est faible, la réécrire réellement.

### Fichiers à inspecter avant modification
Avant de modifier le code, inspecte d'abord l'implémentation actuelle et comprends-la.
- src/Controllers/LettreController.php
- public/frontend/pages/lettre.html
- AI.js / ai.js ou fichier contenant correctLetter()
- AtsScorer.php
- LlmService.php
- public/index.php
- AGENTS.md

### Tests à effectuer
Après modification, tester au minimum :

TEST 1 : Lettre contenant une duplication comme: "À l'attention du À l'attention du Responsable du recrutement" → anomalie détectée → elle doit être corrigée.
TEST 2 : Lettre contenant des paragraphes répétitifs → les répétitions doivent être supprimées.
TEST 3 : Lettre déjà très bonne → ne pas inventer de problèmes → ne pas effectuer de réécriture inutile.
TEST 4 : Lettre très faible → produire une vraie restructuration.
TEST 5 : Lettre avec peu d'informations → ne rien inventer.
TEST 6 : Offre d'emploi fournie → personnaliser uniquement avec les correspondances réellement présentes.
TEST 7 : Offre d'emploi non fournie → ne pas inventer d'informations sur l'entreprise.
TEST 8 : Vérifier que la lettre corrigée n'affiche aucun score.
TEST 9 : Vérifier: Enregistrer, PDF, Copier.
TEST 10 : Tester un JSON IA invalide et vérifier que le backend gère correctement l'erreur.

### Objectif final
Je ne veux plus d'un simple "correcteur de texte".
Je veux un système qui se comporte comme : "un recruteur expérimenté + un expert en rédaction professionnelle + un correcteur linguistique + un éditeur de lettre de motivation".
Le système doit être capable de regarder une lettre et de se demander: "Qu'est-ce qui ne va réellement pas dans cette lettre ?", puis: "Comment puis-je l'améliorer sans inventer quoi que ce soit ?", et enfin: "Est-ce que ma version finale est réellement plus claire, plus cohérente, plus naturelle et plus professionnelle que la version originale ?"
Le résultat doit être une amélioration réelle et visible, pas une simple paraphrase.