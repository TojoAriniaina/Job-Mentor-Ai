# TODO — Job-Mentor-AI

Liste de travail restant, classée par priorité.

## Priorité haute — avant une mise en production publique

- [x] **Risque XSS lié à `innerHTML`** — audit complet du frontend (`cv.html`, `lettre.html`, `oral.html`, `entretien.html`, `ai.js`, `utils.js`, `admin.js`). Deux fonctions d'échappement centralisées dans `utils.js` — `escHtml()` pour le texte, `escAttr()` pour les attributs HTML — utilisées partout où du contenu dynamique (saisie utilisateur, texte généré par l'IA, données issues de la base) est injecté dans le DOM. Point le plus sensible corrigé dans l'espace admin : la photo de profil d'un utilisateur était injectée sans échappement dans un attribut `src`, ce qui pouvait permettre l'exécution de JavaScript dans le navigateur d'un administrateur consultant la liste des comptes.
- [x] **Limitation des tentatives de connexion** — `App\Models\LoginAttempt` (table `login_attempts`, auto-créée). Suivi par email + adresse IP en base plutôt qu'en session seule, pour résister au vidage de cookies. Blocage de 5 minutes après 5 échecs, réponse HTTP 429, compteur réinitialisé à la connexion réussie.
- [x] **Persistance de l'historique d'entretien** — la table `interview_history` est désormais réellement alimentée. `EntretienController::save()` enregistre l'échange complet en fin de session (accessible depuis "Mes Archives"), avec un score global calculé par l'IA sur l'ensemble de l'entretien.

## Priorité moyenne — amélioration recommandée

- [ ] **Pagination de la liste des utilisateurs (espace admin)** — actuellement chargée en une fois ; à revoir si la base dépasse quelques centaines de comptes.
- [ ] **Journal d'activité admin** — aucune trace des actions (désactivation, promotion, suppression) effectuées par un administrateur. Utile en cas de litige (qui a supprimé quel compte, quand).
- [ ] **`og:image` en URL absolue** — actuellement en chemin relatif dans `index.html`. À corriger en `https://votre-domaine.com/assets/img/hero-accueil.jpg` une fois un nom de domaine réel en place, sinon les aperçus de lien (réseaux sociaux, messageries) ne s'affichent pas.
- [ ] **Focus clavier visible sur les éléments interactifs** — `.btn`, `.nav-links a`, `.dropdown-item` n'ont pas de style `:focus-visible` personnalisé ; un utilisateur naviguant au clavier voit le contour par défaut du navigateur, qui détonne avec le thème sombre. Les champs de formulaire (`.form-control`) sont déjà bien gérés (halo teal au focus).
- [ ] **Renouveler la clé API OpenRouter** si la clé actuelle a pu être exposée en dehors du projet, et renseigner `OPENROUTER_API_KEY_2` pour bénéficier de la bascule automatique déjà en place dans `LlmService`.

## Priorité basse — cosmétique / non urgent

- [ ] Mettre en place des tests automatisés (unitaires sur `AtsScorer`, d'intégration sur les routes principales), absents à ce jour.
- [ ] Revoir les états vides des historiques (CV/lettre/entretien ont déjà une icône, cohérence à vérifier après tout changement de design).

## Déjà fait (pour mémoire, ne pas refaire)

- Structure MVC propre (`src/Controllers` / `Models` / `Services` / `Middleware`), doublons `frontend/`/`backend/` legacy supprimés.
- Tous les chemins d'API dynamiques (`window.API_BASE`), plus aucun `/api/...` codé en dur.
- Boucle de redirection infinie login/accueil corrigée.
- Scores CV et lettre calculés de façon déterministe, jamais auto-déclarés par l'IA.
- Mot de passe minimum 8 caractères (client + serveur), validation email à l'inscription.
- `.gitignore` complet (`.env`, `vendor/`, `logs/*.log`).
- Clé API de secours avec bascule automatique.
- Favicon, page 404 personnalisée, scrollbar custom, métadonnées Open Graph, icônes sur états vides.
- Diagrammes UML (classes, cas d'utilisation, séquences CV/Lettre/Entretien/Oral) vérifiés contre le code réel.
- **Espace administrateur** : rôle `role`/`is_active` sur `users`, `AdminController` (liste, stats, activation, rôle, suppression), désactivation prenant effet immédiatement même en session déjà ouverte, page dédiée avec recherche/filtres, onglet Administrateur sur la page de connexion.
- **Export PDF de la lettre corrigée** : mise en page fidèle à la lettre générée (en-tête expéditeur/destinataire, date alignée à droite, objet en évidence).
- **Reconnaissance vocale du module oral** : redémarrage automatique en cas d'interruption par le navigateur, pour éviter la perte de transcription en cours d'enregistrement.
