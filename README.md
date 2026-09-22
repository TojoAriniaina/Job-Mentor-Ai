# My Job Mentor AI (Job-Mentor-AI)

> **L'Atelier de l'Entretien** — Coach de carrière intelligent propulsé par l'IA.

Plateforme web francophone d'accompagnement à la recherche d'emploi. Elle aide un candidat à préparer chaque étape de sa recherche : rédaction de CV, lettre de motivation, préparation d'entretien écrit et entraînement à l'oral, le tout assisté par un modèle de langage (LLM) via OpenRouter.

## Fonctionnalités

| Module | Ce qu'il fait |
|---|---|
| **Authentification** | Inscription, connexion, déconnexion, profil éditable (photo, téléphone, secteur, titre) |
| **CV** | Génération par IA à partir d'un formulaire structuré, score de complétude **calculé de façon déterministe** (pas auto-déclaré par l'IA), historique |
| **Lettre de motivation** | Génération à partir d'un CV + une offre, correction de lettres existantes, score de pertinence **déterministe** (comparaison mots-clés texte/offre), export PDF avec mise en page automatique de l'en-tête |
| **Entretien simulé** | Questions générées dynamiquement par l'IA avec feedback à chaque réponse, jusqu'à 5 questions par session, sauvegarde en archives avec score global |
| **Entraînement oral** | Reconnaissance vocale **100% côté navigateur** (API Web Speech, aucun audio envoyé au serveur), puis analyse du texte transcrit par l'IA avec score et axes d'amélioration |
| **Administration** | Espace réservé aux comptes `role = admin` : liste des utilisateurs (recherche, filtres par rôle/statut, photo de profil ou initiales en repli), statistiques d'usage par module, activation/désactivation de compte, promotion/rétrogradation de rôle, suppression de compte. Accessible via un onglet dédié sur la page de connexion |

## Stack technique

| Couche | Technologie |
|---|---|
| Backend | PHP 8, architecture MVC (`Controllers` / `Models` / `Services` / `Middleware`), autoload Composer PSR-4 |
| Base de données | MySQL |
| Frontend | HTML5 / CSS3 (thème sombre indigo/teal personnalisé) / JavaScript vanilla, sans framework |
| IA | [OpenRouter](https://openrouter.ai), modèle configurable via `.env`, bascule automatique sur une clé de secours en cas d'échec |

## Structure du projet

```
Job-Mentor-Ai/
├── bootstrap/app.php        # Initialisation : autoload, config, erreurs, session, connexion DB
├── config/app.php           # Constantes de config (lues depuis .env)
├── public/
│   ├── index.php            # Front controller : route /api/* vers le Router, sinon sert le frontend
│   ├── .htaccess            # Empêche le listing du dossier
│   └── frontend/
│       ├── index.html
│       ├── 404.html
│       ├── pages/           # cv.html, lettre.html, entretien.html, oral.html, login.html, admin.html, dashboard.html
│       ├── css/             # style.css (design system) + cv-template.css
│       ├── js/              # config.js, api.js, ai.js, utils.js, admin.js, particles.js
│       └── assets/img/
├── src/
│   ├── Router.php
│   ├── Controllers/          # AuthController, CvController, LettreController, EntretienController, OralController, UserController, AdminController, TtsController
│   ├── Models/                # User, CvDocument, CoverLetter, InterviewHistory, OralAnalysis, UserNote, LoginAttempt
│   ├── Services/               # LlmService (appels IA), AtsScorer (scores déterministes), EncryptService (AES-256 des clés API)
│   └── Middleware/Auth.php     # Vérification de session (+ rôle pour les routes admin), appelée en tête de chaque action protégée
├── tools/
│   └── validation_lettre.php  # 46 assertions du module lettre, exécutables sans base ni appel IA
├── logs/                     # php_errors.log (créé automatiquement, jamais commité)
├── .htaccess                  # Docroot projet (XAMPP) : blocage des fichiers/dossiers sensibles
├── database.sql               # Schéma complet de la base + migrations inline idempotentes
├── composer.json / composer.lock
└── .env                        # Clés API, config DB (jamais commité, voir .gitignore)
```

## Installation (XAMPP / environnement local)

Prérequis : PHP ≥ 8.1 (extensions `curl`, `json`, `mbstring`, `openssl`, `pdo_mysql`), MySQL/MariaDB, Composer.

1. **Copier le projet** dans `htdocs` (ou équivalent Apache). Le nom du dossier n'a pas d'importance : tous les chemins (API, redirections, assets) sont calculés dynamiquement au runtime.
2. **Créer la base de données** :
   ```sql
   CREATE DATABASE jobmentor_db;
   ```
   puis importer `database.sql`. Le fichier crée la base `jobmentor_db` et l'utilise : ajustez ces deux premières lignes si votre `DB_NAME` est différent. Les `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` sont idempotentes — relancer le fichier après une évolution du schéma suffit.
3. **Configurer `.env`** à la racine du projet, à partir de `.env.example` :
   ```
   OPENROUTER_API_KEY=votre_cle_ici
   OPENROUTER_API_KEY_2=            # optionnel, clé de secours
   LLM_MODEL=google/gemini-2.0-flash-001
   LLM_API_URL=https://openrouter.ai/api/v1/chat/completions
   DB_HOST=localhost
   DB_USER=root
   DB_PASS=
   DB_NAME=jobmentor_db
   APP_KEY=                         # obligatoire : openssl rand -hex 32 (64 caractères hex)
   APP_URL=                         # URL absolue une fois en ligne (aperçus Open Graph)
   ELEVENLABS_API_KEY=              # optionnel, synthèse vocale (module Oral)
   ELEVENLABS_VOICE_ID=             # optionnel, voix par défaut
   ```
   Sans `APP_KEY` au bon format, l'enregistrement d'une clé API utilisateur est refusé (`EncryptService`) : une clé mal formatée serait ramenée à une clé vide et chiffrerait sans aucun secret.
4. **Installer les dépendances** (autoload uniquement, aucune librairie tierce à ce jour) :
   ```
   composer install
   ```
5. **Démarrer Apache + MySQL**, puis visiter `http://localhost/<nom-du-dossier>/`. La racine redirige automatiquement vers `public/frontend/index.html`, et toute route inconnue vers une page 404 personnalisée.

   Sans Apache, le serveur de développement PHP suffit (le `.htaccess` n'est alors pas lu) :
   ```
   composer run serve      # équivaut à php -S localhost:8000 -t public
   ```

## Vérification

Aucun framework de test n'est en place. Ce qui est vérifiable automatiquement :

```
composer run validate     # 46 assertions du module lettre (score ATS, duplications,
                          # structure, longueur) — sans base de données ni appel IA
php -l <fichier>          # syntaxe de tous les fichiers PHP
```

Le reste se contrôle à la main : démarrer le serveur, parcourir connexion → CV → lettre → entretien → oral, et lire `logs/php_errors.log` (les erreurs PHP n'affichent jamais de stack trace côté navigateur, `display_errors` est désactivé).

## Créer le premier compte administrateur

1. S'inscrire normalement sur le site (onglet "Utilisateur" de la page de connexion).
2. Promouvoir ce compte en base :
   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'ton-email@exemple.com';
   ```
3. Sur la page de connexion, choisir l'onglet **Administrateur** puis se connecter avec les mêmes identifiants — redirige directement vers `admin.html`.

Un compte admin peut ensuite promouvoir d'autres comptes directement depuis l'interface, sans repasser par SQL.

## Sécurité

- **Limitation des tentatives de connexion** : suivi des échecs par email + adresse IP en base (`login_attempts`), blocage temporaire de 5 minutes après 5 échecs, réponse HTTP 429.
- **Protection contre les injections XSS** : tout contenu dynamique (saisie utilisateur, texte généré par l'IA, données issues de la base) affiché dans l'interface passe par des fonctions d'échappement centralisées (`escHtml`, `escAttr` dans `utils.js`).
- **Requêtes préparées PDO** partout où une valeur vient de la requête.
- **Mots de passe** : hachage via `password_hash()`, longueur minimale de 8 caractères vérifiée côté client et serveur.
- **Clés API utilisateur chiffrées** : AES-256-CBC (`EncryptService`), clé `APP_KEY` contrôlée au chargement (64 caractères hexadécimaux obligatoires, sinon refus explicite plutôt qu'une clé vide silencieuse).
- **Session** : cookie `HttpOnly`, `SameSite=Lax`, `use_only_cookies`, `use_strict_mode`, `cookie_secure` automatique dès que la requête arrive en HTTPS (direct ou reverse proxy), `session_regenerate_id(true)` à la connexion.
- **`.htaccess` racine** : `Options -Indexes`, refus des points cachés (`.env`, `.env.example`), des `.log`/`.sql`, de la documentation à la racine (`.md`, `.txt`, `.json`, `.lock`), des dossiers `src/`, `config/`, `bootstrap/`, `logs/`, `vendor/`, `tools/`, et exécution PHP refusée pour tout script posé à la racine.
- **Aucun dépôt de fichier** : les photos de profil sont stockées en base (data URL), l'OCR des CV/lettres importés se fait dans le navigateur — le serveur ne reçoit que du texte.
- **Clé API de secours** avec bascule automatique en cas d'erreur de quota sur la première clé.

## Avant mise en ligne (check-list)

- [ ] Docroot pointant sur `public/` plutôt que sur la racine du projet (le `.htaccess` racine ne devient qu'une ceinture de secours).
- [ ] HTTPS en place : le module Oral (Web Speech API) et le microphone exigent un contexte sécurisé ; le cookie de session passe alors automatiquement en `secure`.
- [ ] `.env` recréé avec des **clés propres à la production** — ne pas réutiliser les clés de développement, et ne jamais les mettre dans le dépôt. Vérifier qu'il est bien lu : sans fichier, `config/app.php` retombe sur `localhost` / `root` / sans mot de passe / `jobmentor_db`, ce qui fait tourner l'application sur une base de développement sans le moindre message d'erreur.
- [ ] `APP_KEY` générée (`openssl rand -hex 32`). Changer cette clé rend indécryptables les clés API déjà enregistrées en base : les utilisateurs doivent les ressaisir.
- [ ] `APP_URL` renseigné, et `og:image` / `og:url` dans `public/frontend/index.html` pointant vers le vrai domaine (ils portent aujourd'hui un domaine de démonstration).
- [ ] `display_errors` toujours à `0`, et `logs/php_errors.log` consulté après chaque parcours. Attention : les journaux contiennent des extraits de texte saisis par les utilisateurs — à ne pas exposer ni commité.
- [ ] Sauvegarde de la base avant toute ré-exécution de `database.sql`.
- [ ] Supprimer tout fichier de test ou de debug ajouté à la racine depuis lors (`tools/` est bloqué HTTP, mais un fichier posé ailleurs ne bénéficie que des règles `.htaccess`, inopérantes hors Apache).

## Points d'attention connus

- **`og:image` / `og:url`** portent un domaine de démonstration (`jobmentor-ai.mg`) et non la constante `APP_URL` du `.env` : à remplacer par le vrai domaine avant publication, sinon les aperçus de lien restent vides.
- **Anomalies de la phase « Analyser » de la lettre** : sur une lettre pourtant correcte, `LettreController::analyze()` peut encore signaler une « formule d'ouverture répétée » (la formule d'appel reprise dans la formule de politesse) ou un « segment dupliqué » entre l'objet et la première phrase. Le score n'est pas faussé, mais le diagnostic affiche des problèmes qui n'en sont pas — à fiabiler avant une ouverture publique.
- **Pas de jeton CSRF** : les appels d'API s'appuient sur le cookie de session en `SameSite=Lax`, qui bloque les POST inter-sites mais pas une origine du même site. À durcir si l'application évolue vers des sous-domaines.
- **Exports PDF et OCR dépendent de bibliothèques chargées depuis un CDN** (pdf.js, tesseract.js, pdfmake, jsPDF) : sans accès réseau côté navigateur, l'import de CV/lettre et l'export PDF ne fonctionnent plus. Les héberger en local pour un déploiement intranet.
- **Synthèse vocale du module Oral** soumise au quota du compte ElevenLabs ; au-delà du quota l'API répond 401 `quota_exceeded` et la lecture échoue (visible dans les journaux, message à l'utilisateur).
- **Espace admin sans pagination** : la liste des utilisateurs se charge en une seule fois (filtrage/recherche côté client). Suffisant pour le volume actuel, à revoir si la base dépasse quelques centaines de comptes.
- **Pas de "Create" côté admin** : la gestion des utilisateurs est volontairement en **Read / Update / Delete** seul — un admin ne peut pas créer de compte pour un tiers, chaque utilisateur passe par le formulaire d'inscription standard pour définir lui-même son mot de passe.
- **Export PDF de la lettre corrigée** : la détection automatique de l'en-tête (expéditeur/destinataire/date/objet) repose sur des motifs de texte usuels d'une lettre de motivation française — fonctionne pour une structure classique, mais retombe sur un rendu en paragraphes simples si la structure n'est pas reconnue.

## Documentation complémentaire

- `PLAN_IMPLEMENTATION.md` — démarche de développement, phases, méthodologie de test
- `TODO.md` — travail restant, classé par priorité
- `AGENTS.md` — notes de fonctionnement destinées aux outils d'assistance par IA (architecture, conventions, pièges connus) ; bloqué en HTTP par le `.htaccess` racine
- Diagrammes UML (classes, cas d'utilisation, séquences des 4 modules IA), fournis séparément, vérifiés contre le code réel plutôt que contre la conception initiale
