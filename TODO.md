# TODO — Job-Mentor-AI

Liste de travail restant, classée par priorité.

## Priorité haute — avant une mise en production publique

- [x] **Risque XSS lié à `innerHTML`** — audit complet du frontend (`cv.html`, `lettre.html`, `oral.html`, `entretien.html`, `ai.js`, `utils.js`, `admin.js`). Deux fonctions d'échappement centralisées dans `utils.js` — `escHtml()` pour le texte, `escAttr()` pour les attributs HTML — utilisées partout où du contenu dynamique (saisie utilisateur, texte généré par l'IA, données issues de la base) est injecté dans le DOM. Point le plus sensible corrigé dans l'espace admin : la photo de profil d'un utilisateur était injectée sans échappement dans un attribut `src`, ce qui pouvait permettre l'exécution de JavaScript dans le navigateur d'un administrateur consultant la liste des comptes.
- [x] **Limitation des tentatives de connexion** — `App\Models\LoginAttempt` (table `login_attempts`, auto-créée). Suivi par email + adresse IP en base plutôt qu'en session seule, pour résister au vidage de cookies. Blocage de 5 minutes après 5 échecs, réponse HTTP 429, compteur réinitialisé à la connexion réussie.
- [x] **Persistance de l'historique d'entretien** — la table `interview_history` est désormais réellement alimentée. `EntretienController::save()` enregistre l'échange complet en fin de session (accessible depuis "Mes Archives"), avec un score global calculé par l'IA sur l'ensemble de l'entretien.

- [ ] **Anomalies de `LettreController::analyze()` trop généreuses** — sur une lettre correcte, la phase « Analyser » signale encore une « formule d'ouverture répétée » (l'appel repris dans la formule de politesse) ou un « segment dupliqué » entre l'objet et la phrase d'accroche. Le score reste juste, mais le diagnostic invente des problèmes : aligner ces règles sur les exclusions déjà présentes dans `AtsScorer`.
- [ ] **Jeton CSRF sur les routes en session** — protection actuelle = cookie `SameSite=Lax` seulement.
- [ ] **Bibliothèques CDN à héberger en local** — pdf.js, tesseract.js, pdfmake, jsPDF sont chargés depuis un CDN : l'import OCR et les exports PDF tombent sans réseau.
- [ ] **Quota ElevenLabs** — la synthèse vocale du module Oral répond 401 `quota_exceeded` au-delà du quota ; prévoir un message explicite côté utilisateur plutôt qu'un échec silencieux.
- [ ] **Vérifier les règles `.htaccess` sous Apache** — durcies (indexes, points cachés, docs racine, PHP hors `public/`, dossiers sensibles) mais jamais testées avec un vrai Apache ; à valider en levant le serveur local.

## Priorité moyenne — amélioration recommandée

- [ ] **Pagination de la liste des utilisateurs (espace admin)** — actuellement chargée en une fois ; à revoir si la base dépasse quelques centaines de comptes.
- [ ] **Journal d'activité admin** — aucune trace des actions (désactivation, promotion, suppression) effectuées par un administrateur. Utile en cas de litige (qui a supprimé quel compte, quand).
- [x] **`og:image` en URL absolue** — les balises portent désormais une URL absolue, mais vers un domaine de démonstration (`jobmentor-ai.mg`). Reste à mettre le vrai domaine (et renseigner `APP_URL`) à la mise en ligne, sinon les aperçus de lien ne s'affichent pas.
- [ ] **Focus clavier visible sur les éléments interactifs** — `.btn`, `.nav-links a`, `.dropdown-item` n'ont pas de style `:focus-visible` personnalisé ; un utilisateur naviguant au clavier voit le contour par défaut du navigateur, qui détonne avec le thème sombre. Les champs de formulaire (`.form-control`) sont déjà bien gérés (halo teal au focus).
- [ ] **Renouveler la clé API OpenRouter** si la clé actuelle a pu être exposée en dehors du projet, et renseigner `OPENROUTER_API_KEY_2` pour bénéficier de la bascule automatique déjà en place dans `LlmService`.

## Priorité basse — cosmétique / non urgent

- [ ] **Étendre la validation automatisée** — `tools/validation_lettre.php` couvre le module lettre (46 assertions, sans base ni IA). Il manque des assertions sur le score CV d'`AtsScorer`, et des tests d'intégration sur les routes (il faudrait un jeu de données de test + un compte de test supprimé après passage).
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
- **Préparation du dépôt** : dossier `tools/` (script de validation du module lettre, 46 assertions), `composer.lock` et `.gitignore` complétés, exigences PHP + extensions déclarées, `database.sql` vérifié par import dans une base temporaire (aucune dérive avec la base de travail).
- **Durcissement serveur et session** : `.htaccess` racine (pas de listing, points cachés et docs refusés, PHP exécuté uniquement depuis `public/`, dossiers sensibles bloqués), cookie de session `secure` automatique en HTTPS, format de `APP_KEY` contrôlé au lieu d'être silencieusement dégradé en clé vide.
