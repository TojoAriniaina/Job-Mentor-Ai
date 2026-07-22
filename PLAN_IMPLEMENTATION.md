# Plan d'implémentation — Job-Mentor-AI

Ce document décrit la démarche de développement suivie, du cahier des charges jusqu'aux tests, et sert de référence pour comprendre comment le projet a été construit et validé.

## 1. Cahier des charges

**Objectif** : construire une plateforme web en français d'accompagnement à la recherche d'emploi, propulsée par l'IA, avec 4 modules autonomes :

1. Génération de CV
2. Rédaction de lettre de motivation
3. Simulation d'entretien
4. Entraînement à l'oral

Contraintes de départ : backend PHP/MySQL, frontend en JavaScript natif (pas de framework), intégration à un fournisseur de LLM externe (OpenRouter) pour toute la génération de contenu.

## 2. Choix d'architecture

Décision structurante : adopter une architecture **MVC** plutôt qu'un ensemble de scripts PHP procéduraux indépendants (un fichier par endpoint). Objectifs :

- séparer la réception de requête HTTP (**Controller**), l'accès aux données (**Model**), et la logique métier réutilisable (**Service**) ;
- centraliser l'initialisation (connexion DB, session, config) en un seul point (`bootstrap/app.php`) plutôt que de la répéter dans chaque fichier ;
- permettre l'autoload des classes via Composer/PSR-4, plutôt que des `require` manuels.

Le projet a démarré sur une base plus simple (`backend/` + `frontend/` à plat), migrée en cours de développement vers `src/` (MVC) + `public/` (front controller + assets).

## 3. Phases de développement

### Phase 1 — Fonctionnalités cœur
Mise en place des 4 modules (CV, Lettre, Entretien, Oral) et de l'authentification, avec le `LlmService` comme point d'entrée unique vers OpenRouter, et un `Router` qui fait correspondre chaque route `/api/...` à un Controller.

### Phase 2 — Migration MVC
Passage de la structure à plat vers `src/` + `public/`. Cette migration a temporairement laissé coexister l'ancienne et la nouvelle structure, ce qui a nécessité une phase de nettoyage (voir Phase 4).

### Phase 3 — Fiabilisation des scores
Constat : les scores (ATS pour le CV, pertinence pour la lettre) étaient à l'origine **auto-déclarés par l'IA elle-même** dans sa réponse JSON, avec un fallback JavaScript qui remplaçait silencieusement un score de 0 par une valeur arbitraire (80 ou 75) — masquant les vrais échecs. Ces scores ont été remplacés par un calcul **déterministe** côté serveur (`AtsScorer`), indépendant de ce que retourne l'IA :
- CV : score de complétude du profil, calculé avant l'appel IA.
- Lettre (génération) : score de pertinence par comparaison de mots-clés entre le texte généré et l'offre.
- Lettre (correction) : score de qualité d'écriture (longueur, structure, formules d'usage).

### Phase 4 — Nettoyage et fiabilisation du déploiement
Suite à des bugs de déploiement en sous-dossier (XAMPP), plusieurs correctifs structurels :
- suppression des dossiers `frontend/`/`backend/` legacy, devenus redondants avec `public/` ;
- remplacement de tous les chemins d'API codés en dur (`/api/...`) par un calcul dynamique (`window.API_BASE`), pour fonctionner peu importe le sous-dossier de déploiement ;
- correction d'une boucle de redirection infinie entre la page d'accueil et la page de connexion, causée par un chemin relatif incorrect ;
- ajout d'une page 404 personnalisée et distinction claire, côté routeur PHP, entre "route racine" et "route vraiment inconnue".

### Phase 5 — Sécurité de base
- Longueur minimale du mot de passe (8 caractères, vérifiée côté client et serveur).
- Validation du format d'email à l'inscription.
- `.env` exclu du contrôle de version, `vendor/` et les logs ajoutés au `.gitignore`.
- Clé API de secours avec bascule automatique en cas de rate-limit ou de clé invalide.

### Phase 6 — Finition visuelle
Favicon, page 404 sur le thème du site, scrollbar personnalisée, métadonnées Open Graph pour le partage de lien, icônes sur les états vides (historiques), correction d'un fondu d'image mal ciblé sur la page de connexion.

### Phase 7 — Documentation
Production de diagrammes UML (classes, cas d'utilisation, séquences des 4 modules IA) vérifiés directement contre le code source plutôt que contre la conception initiale, afin de documenter fidèlement le comportement réel de l'application — y compris ses limites connues (voir `TODO.md`).

### Phase 8 — Espace administrateur
Ajout d'un rôle administrateur, en respectant deux contraintes : ne rien casser dans les 4 modules existants, et rester réversible en cas de problème avant la soutenance.

Décisions de conception :
- **Portée volontairement limitée à Read / Update / Delete** sur les utilisateurs (voir `README.md`) — pas de création de compte par un admin, pour que chaque utilisateur choisisse lui-même son mot de passe.
- **Colonnes additives uniquement** (`role`, `is_active` sur `users`, valeurs par défaut), migration par `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, cohérente avec le style déjà en place dans `database.sql` — aucune donnée existante affectée.
- **Revérification du rôle et du statut à chaque requête authentifiée** (`Auth::assertStillActive()`), plutôt qu'au seul login : une désactivation ou une promotion faite par un admin prend effet immédiatement, même sur une session déjà ouverte ailleurs.
- **Auto-protection** : un administrateur ne peut ni se désactiver, ni se rétrograder (s'il est le dernier admin), ni se supprimer lui-même.
- **Deux entrées vers l'espace admin** : un lien dans le menu profil (visible seulement si `role = admin`), et un onglet dédié sur la page de connexion qui redirige directement vers `admin.html` après vérification du rôle.

## 4. Méthodologie de test

Aucun framework de test automatisé n'est en place à ce jour (voir `TODO.md`). La validation a été faite par :

- **Analyse statique** : vérification systématique de la syntaxe PHP (`php -l`) et JavaScript (`node --check`) sur l'ensemble des fichiers après chaque modification.
- **Tests unitaires isolés** : logique du modèle `User` (rôle, activation, suppression, protections) validée sur une base SQLite en mémoire avant tout test HTTP.
- **Tests d'intégration manuels** : environnement de test reconstitué (MySQL/MariaDB réel + serveur PHP intégré), avec rejeu du schéma `database.sql` tel quel, pour valider de bout en bout chacun des parcours (inscription, connexion, CV, lettre, entretien, oral, et les actions admin : désactivation à chaud, promotion à chaud, suppression) via des requêtes HTTP directes.
- **Comparaison différentielle** : chaque fichier livré comparé octet par octet à sa version d'origine (`diff` / `cmp`) pour garantir qu'aucun module existant n'a été modifié par erreur.
- **Simulation de déploiement** : reproduction du sous-dossier XAMPP en local pour valider les correctifs de chemins avant de les considérer résolus.
- **Vérification visuelle** : captures d'écran comparées aux attentes pour les ajustements d'interface (non disponible pour l'espace admin lors de sa création — à vérifier visuellement en local, voir `TODO.md` si un ajustement est nécessaire).

## 5. État actuel

Les 4 modules IA, l'authentification et l'espace administrateur sont fonctionnels de bout en bout au niveau du pipeline (validation, appel IA le cas échéant, gestion d'erreur, persistance). Les limites connues et le travail restant sont documentés dans `TODO.md`.
