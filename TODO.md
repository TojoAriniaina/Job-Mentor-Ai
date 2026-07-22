# TODO — Job-Mentor-AI

Liste de travail restant, classée par priorité.

## Priorité haute — avant une mise en production publique

- [ ] **Corriger le risque XSS lié à `innerHTML`** — présent dans `cv.html`, `lettre.html`, `oral.html`, `entretien.html`, `ai.js`, `utils.js` (environ 74 occurrences). Remplacer par `textContent` partout où le contenu n'est pas volontairement du HTML, ou passer par une fonction d'échappement pour le reste. Risque : un texte généré par l'IA ou saisi par un utilisateur contenant une balise `<script>` pourrait s'exécuter dans le navigateur.
- [ ] **Ajouter une limitation des tentatives de connexion** — rien n'empêche actuellement un enchaînement de tentatives de mot de passe sur `/api/auth/login`. Prévoir un compteur (en session ou en base) avec un délai après un nombre d'échecs (ex : 5).
- [ ] **Décider du sort de l'historique d'entretien** — la table `interview_history` et son modèle existent mais ne sont jamais alimentés par le code actuel (l'échange reste en `localStorage` navigateur). Deux options :
  - implémenter la sauvegarde réelle en base (appeler `create()` en fin de session dans `EntretienController`) ;
  - ou supprimer la table/le modèle inutilisés pour éviter la confusion, et documenter que l'historique est volontairement local.

## Priorité moyenne — amélioration recommandée

- [ ] **Pagination de la liste des utilisateurs (espace admin)** — actuellement chargée en une fois ; à revoir si la base dépasse quelques centaines de comptes.
- [ ] **Journal d'activité admin** — aucune trace des actions (désactivation, promotion, suppression) effectuées par un administrateur. Pourrait être utile en cas de litige (qui a supprimé quel compte, quand).
- [ ] **`og:image` en URL absolue** — actuellement en chemin relatif dans `index.html`. À corriger en `https://votre-domaine.com/assets/img/hero-accueil.jpg` une fois un nom de domaine réel en place, sinon les aperçus de lien (réseaux sociaux, messageries) ne s'afficheront pas.
- [ ] **Focus clavier visible sur les éléments interactifs** — `.btn`, `.nav-links a`, `.dropdown-item` n'ont pas de style `:focus-visible` personnalisé ; un utilisateur naviguant au clavier voit le contour par défaut du navigateur, qui détonne avec le thème sombre. Les champs de formulaire (`.form-control`), eux, sont déjà bien gérés (halo teal au focus).
- [ ] **Générer une nouvelle clé API OpenRouter** si la clé actuelle a pu être exposée en dehors de ce projet (bonne pratique de précaution suite à un partage de fichier `.env`), et renseigner `OPENROUTER_API_KEY_2` pour bénéficier de la bascule automatique déjà en place dans `LlmService`.

## Priorité basse — cosmétique / non urgent

- [ ] Mettre en place des tests automatisés (unitaires sur `AtsScorer`, d'intégration sur les routes principales), absents à ce jour.
- [ ] Revoir les états vides des historiques (CV/lettre/entretien ont déjà une icône, cohérence à vérifier après tout changement de design).

## Déjà fait (pour mémoire, ne pas refaire)

- Structure MVC propre (`src/Controllers` / `Models` / `Services` / `Middleware`), doublons `frontend/`/`backend/` legacy supprimés.
- Tous les chemins d'API dynamiques (`window.API_BASE`), plus aucun `/api/...` codé en dur.
- Boucle de redirection infinie login/accueil corrigée.
- Scores CV et lettre calculés de façon déterministe, plus jamais auto-déclarés par l'IA ; fallback JS qui masquait un score de 0 supprimé.
- Mot de passe minimum 8 caractères (client + serveur), validation email à l'inscription.
- `.gitignore` complet (`.env`, `vendor/`, `logs/*.log`).
- Clé API de secours avec bascule automatique.
- Favicon, page 404 personnalisée, scrollbar custom, métadonnées Open Graph, icônes sur états vides.
- Diagrammes UML (classes, cas d'utilisation, séquences CV/Lettre/Entretien/Oral) vérifiés contre le code réel.
- **Espace administrateur** : rôle `role`/`is_active` sur `users`, `AdminController` (liste, stats, activation, rôle, suppression), désactivation prenant effet **immédiatement** même en session déjà ouverte (revérification en base à chaque requête, pas seulement au login), page dédiée avec recherche/filtres, onglet Administrateur sur la page de connexion. Testé de bout en bout sur un vrai MySQL (pas seulement en SQLite/unitaire).
