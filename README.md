# My Job Mentor AI (Job-Mentor-AI)

> **L'Atelier de l'Entretien** — Coach de carrière intelligent propulsé par l'IA.

Plateforme web francophone d'accompagnement à la recherche d'emploi. Elle aide un candidat à préparer chaque étape de sa recherche : rédaction de CV, lettre de motivation, préparation d'entretien écrit et entraînement à l'oral, le tout assisté par un modèle de langage (LLM) via OpenRouter.

## Fonctionnalités

| Module | Ce qu'il fait |
|---|---|
| **Authentification** | Inscription, connexion, déconnexion, profil éditable (photo, téléphone, secteur, titre) |
| **CV** | Génération par IA à partir d'un formulaire structuré, score de complétude **calculé de façon déterministe** (pas auto-déclaré par l'IA), historique |
| **Lettre de motivation** | Génération à partir d'un CV + une offre, correction de lettres existantes, score de pertinence **déterministe** (comparaison mots-clés texte/offre), sauvegarde en 2 étapes distinctes (générer, puis enregistrer) |
| **Entretien simulé** | Questions générées dynamiquement par l'IA avec feedback à chaque réponse, jusqu'à 5 questions par session |
| **Entraînement oral** | Reconnaissance vocale **100% côté navigateur** (API Web Speech, aucun audio envoyé au serveur), puis analyse du texte transcrit par l'IA avec score et axes d'amélioration |
| **Administration** | Espace réservé aux comptes `role = admin` : liste des utilisateurs (recherche, filtres par rôle/statut), statistiques d'usage par module, activation/désactivation de compte, promotion/rétrogradation de rôle, suppression de compte. Accessible via un onglet dédié sur la page de connexion |

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
├── bootstrap/app.php        # Initialisation : autoload, config, session, connexion DB
├── config/app.php           # Constantes de config (lues depuis .env)
├── public/
│   ├── index.php            # Front controller : route /api/* vers le Router, sinon sert le frontend
│   └── frontend/
│       ├── index.html
│       ├── 404.html
│       ├── pages/           # cv.html, lettre.html, entretien.html, oral.html, login.html, admin.html
│       ├── css/style.css
│       └── js/               # config.js, api.js, utils.js, ai.js, particles.js, admin.js
├── src/
│   ├── Router.php
│   ├── Controllers/          # AuthController, CvController, LettreController, EntretienController, OralController, UserController, AdminController
│   ├── Models/                # User, CvDocument, CoverLetter, InterviewHistory, OralAnalysis, UserNote
│   ├── Services/               # LlmService (appels IA), AtsScorer (scores déterministes)
│   └── Middleware/Auth.php     # Vérification de session (+ rôle pour les routes admin), appelée en tête de chaque action protégée
├── database.sql               # Schéma complet de la base
├── composer.json
└── .env                        # Clés API, config DB (jamais commité, voir .gitignore)
```

## Installation (XAMPP / environnement local)

1. **Copier le projet** dans `htdocs` (ou équivalent Apache). Le nom du dossier n'a pas d'importance : tous les chemins (API, redirections, assets) sont calculés dynamiquement au runtime.
2. **Créer la base de données** :
   ```sql
   CREATE DATABASE jobmentor_db;
   ```
   puis importer `database.sql`.
3. **Configurer `.env`** à la racine du projet :
   ```
   OPENROUTER_API_KEY=votre_cle_ici
   OPENROUTER_API_KEY_2=            # optionnel, clé de secours
   LLM_MODEL=google/gemini-3.1-flash-lite
   LLM_API_URL=https://openrouter.ai/api/v1/chat/completions
   DB_HOST=localhost
   DB_USER=root
   DB_PASS=
   DB_NAME=jobmentor_db
   ```
4. **Installer les dépendances** (autoload uniquement, pas de librairie tierce à ce jour) :
   ```
   composer install
   ```
5. **Démarrer Apache + MySQL**, puis visiter `http://localhost/<nom-du-dossier>/`. La racine redirige automatiquement vers `public/frontend/index.html`, et toute route inconnue vers une page 404 personnalisée.

## Créer le premier compte administrateur

1. S'inscrire normalement sur le site (onglet "Utilisateur" de la page de connexion).
2. Promouvoir ce compte en base :
   ```sql
   UPDATE users SET role = 'admin' WHERE email = 'ton-email@exemple.com';
   ```
3. Sur la page de connexion, choisir l'onglet **Administrateur** puis se connecter avec les mêmes identifiants — redirige directement vers `admin.html`.

Un compte admin peut ensuite promouvoir d'autres comptes directement depuis l'interface, sans repasser par SQL.

## Points d'attention connus

- **Historique d'entretien non persisté** : la table `interview_history` existe en base mais n'est actuellement jamais alimentée par le code — l'échange question/réponse ne vit que dans la mémoire du navigateur (`localStorage`) pendant la session. Voir `TODO.md`.
- **Sécurité** : usage de `innerHTML` à plusieurs endroits du frontend (risque XSS à corriger avant une mise en production publique), pas de limitation du nombre de tentatives de connexion. Voir `TODO.md`.
- **`og:image`** (métadonnées de partage de lien) utilise un chemin relatif — à remplacer par une URL absolue une fois un vrai nom de domaine en place.
- **Espace admin sans pagination** : la liste des utilisateurs se charge en une seule fois (filtrage/recherche côté client). Suffisant pour le volume actuel, à revoir si la base dépasse quelques centaines de comptes.
- **Pas de "Create" côté admin** : la gestion des utilisateurs est volontairement en **Read / Update / Delete** seul — un admin ne peut pas créer de compte pour un tiers, chaque utilisateur passe par le formulaire d'inscription standard pour définir lui-même son mot de passe.

## Documentation complémentaire

- `PLAN_IMPLEMENTATION.md` — démarche de développement, phases, méthodologie de test
- `TODO.md` — travail restant, classé par priorité
- Diagrammes UML (classes, cas d'utilisation, séquences des 4 modules IA), fournis séparément, vérifiés contre le code réel plutôt que contre la conception initiale
