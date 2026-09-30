# CLAUDE.md

## Projet
I-CONNECT est une application web de gestion de buvettes associatives, réalisée pour la
SAE 3.02 de l'IUT de Montreuil. Clients, barmans, gestionnaires et administrateurs y gèrent
produits, stocks, fournisseurs, commandes, paniers et soldes, pour une ou plusieurs associations.

## Stack
- **Langages** : PHP (HTML généré côté serveur), CSS, un peu de JavaScript pour les modales.
- **Framework** : aucun côté serveur (MVC maison). Front : Bootstrap 5.3.8 et Bootstrap Icons 1.11.3 (CDN).
- **Base de données** : MySQL via PDO, sans schéma `.sql` versionné (voir `documentsArendre/mld.png`).
- **Versions** : Bootstrap et Bootstrap Icons sont épinglés dans `template.php`. Les versions de PHP
  et de MySQL ne sont déclarées nulle part (ni Composer) ; le code a été vérifié avec PHP 8.4.

## Commandes
### Installation
Prérequis : PHP avec `pdo_mysql` et un serveur MySQL. Créer une base `buvette`, importer le schéma
(à demander à l'équipe), puis dans `Connexion.php` utiliser la ligne locale commentée
(`localhost`, base `buvette`, `root`, mot de passe vide).
### Lancement
`php -S localhost:8000`, puis ouvrir http://localhost:8000/index.php.
### Tests
Aucun test automatisé : vérifier à la main dans le navigateur, par rôle (Client, Barman, Gestionnaire, Admin).
### Linter
Aucun linter configuré. Contrôle de syntaxe : `find . -name '*.php' -exec php -l {} \;`

## Architecture
MVC maison. `index.php` charge `Connexion.php`, démarre la session, instancie le module `mod_*`
voulu et injecte son rendu dans `template.php`.
- `index.php` : point d'entrée et aiguillage (landing page ou `ModAsso` si connecté).
- `Connexion.php` : connexion PDO statique partagée. `modele.php` : requêtes communes aux modules.
- `template.php`, `style.css`, `vue_generique.php` : gabarit de page, styles, éléments de vue communs (modales).
- `token.php`, `abstractToken.php` : génération et vérification du jeton CSRF.
- `modules/mod_*` : un module par fonctionnalité (admin, asso, commande, compte, fournisseur,
  panier, produit, stock) ; `modules/landingPage` : page d'accueil publique.
- `Composants/` : éléments transverses (`comp_navbar` : barre de navigation ; `mod_connexion` : connexion).
- `documentsArendre/` : livrables (notice, rapport, MCD/MLD, personas). `documentsLegaux/` : pièces
  des associations. Ne pas modifier ces deux dossiers.

## Domaines

Un domaine couvre **soit le front, soit le back**, jamais les deux.

### Back

| Domaine | Dossiers / fichiers |
|---|---|
| Bootstrap & routage global | `index.php` |
| Accès BDD & requêtes communes | `Connexion.php`, `modele.php` |
| Sécurité (CSRF) | `token.php`, `abstractToken.php` |
| Authentification / connexion | `Composants/mod_connexion/{mod,cont,modele}_connexion.php` |
| Navigation (logique de la navbar) | `Composants/comp_navbar/{comp,cont,modele}_navbar.php` |
| Landing page (logique) | `modules/landingPage/{mod,cont}_landingPage.php` |
| Associations | `modules/mod_asso/{mod,cont,modele}_asso.php` |
| Administration | `modules/mod_admin/{mod,cont,modele}_admin.php` |
| Comptes utilisateurs | `modules/mod_compte/{mod,cont,modele}_compte.php` |
| Produits | `modules/mod_produit/{mod,cont,modele}_produit.php` |
| Stock | `modules/mod_stock/{mod,cont,modele}_stock.php` |
| Fournisseurs | `modules/mod_fournisseur/{mod,cont,modele}_fournisseur.php` |
| Panier | `modules/mod_panier/{mod,cont,modele}_panier.php` |
| Commandes | `modules/mod_commande/{mod,cont,modele}_commande.php` |

### Front

| Domaine | Dossiers / fichiers |
|---|---|
| Gabarit global & styles | `template.php`, `style.css`, `vue_generique.php` |
| Vue connexion | `Composants/mod_connexion/vue_connexion.php` |
| Vue navbar | `Composants/comp_navbar/vue_navbar.php` |
| Vue landing page | `modules/landingPage/vue_landingPage.php`, `img_landingPage/` |
| Vues associations | `modules/mod_asso/vue_asso.php`, `logos/` |
| Vues administration | `modules/mod_admin/vue_admin.php` |
| Vues comptes | `modules/mod_compte/vue_compte.php` |
| Vues produits | `modules/mod_produit/vue_produit.php`, `img_produits/` |
| Vues stock | `modules/mod_stock/vue_stock.php` |
| Vues fournisseurs | `modules/mod_fournisseur/vue_fournisseur.php` |
| Vues panier | `modules/mod_panier/vue_panier.php` |
| Vues commandes | `modules/mod_commande/vue_commande.php` |

Hors domaines : `documentsArendre/`, `documentsLegaux/` (données personnelles), `.idea/`.

## Conventions
### Nommage
- Par module : `mod_X.php` (routeur), `cont_X.php` (contrôleur), `modele_X.php`, `vue_X.php`.
- Classes `ModX`, `ContX`, `ModeleX`, `VueX` ; méthodes en camelCase ; code et commentaires en français.
- Routage par URL : `index.php?module=...&action=...`, une `action` correspond à une méthode du contrôleur.
### Style
- PHPDoc en français sur les méthodes complexes des contrôleurs.
- Les vues construisent le HTML avec `echo` et des chaînes PHP (Bootstrap) ; les sorties utilisateur
  passent par `htmlspecialchars()`.
- SQL uniquement avec `prepare()` + `execute([...])`.
### Gestion des erreurs
- Pas d'exceptions (aucun `try/catch`) : les entrées sont testées avec `isset()` et le rôle avec `$_SESSION['role']`.
- Action inconnue : `default` du `switch` → `unrecognizedAction()` → modale « action non trouvée ».
- Messages à l'utilisateur via `$_SESSION['messageOk']` / `$_SESSION['messagePasOk']`, supprimés après affichage.
- Formulaires POST : refusés si `Token::verifierToken($_POST['tokenCSRF'])` échoue.
- Redirection avec `header('Location: ...'); exit();`.
- Attention : `Connexion.php` contient des identifiants BDD en clair, à ne pas propager.

## Règles de travail
- **Ne travailler que dans un seul périmètre (domaine) à la fois**, selon le tableau « Domaines ». Ne lire ni les dépendances installées (`vendor/`, `node_modules/`), ni les fichiers de verrouillage (`composer.lock`, `package-lock.json`…), ni les données, logs ou fichiers générés (uploads, images, PDF, dumps).
- Si le front et le back doivent communiquer, s'appuyer sur `docs/API.md` (quand il existera) plutôt que sur une lecture complète de l'autre partie du projet. En attendant, lire uniquement la signature de la méthode de vue appelée par le contrôleur (`VueX::afficherXxx(...)`).
- Ne jamais committer de secret (mot de passe, identifiant BDD, clé) ; ne pas en ajouter dans le code ni dans `CLAUDE.md`.
- Créer une branche et une PR par modification, jamais de push direct sur la branche principale.
- Accompagner tout correctif de sécurité d'un test qui reproduit la faille.
- Lancer les tests (et le contrôle de syntaxe `php -l`) avant d'ouvrir une PR.
- Expliquer tout changement de dépendance (ajout, mise à jour, suppression, version CDN) dans la PR.
- Ne pas modifier `documentsArendre/` ni `documentsLegaux/` sans demande explicite.
- **En cas de doute, quel qu'il soit** (périmètre, choix technique, comportement attendu, ambiguïté d'une consigne…), **poser la question à l'équipe** au lieu de trancher seul.
- Répondre en français.

## Journal d'initialisation (vérification de l'environnement)
Environnement : PHP 8.4.19, Composer et Node 22 disponibles.

Commandes exécutées et résultats :
- `ls composer.json package.json phpunit.xml*` → aucun de ces fichiers n'existe : **aucune dépendance à installer**.
- `find . -name '*.php' -not -path './.git/*' | wc -l` → 50 fichiers PHP.
- `for f in $(find . -name '*.php' -not -path './.git/*'); do php -l "$f" >/dev/null || echo "FAIL $f"; done`
  → **0 erreur de syntaxe** (seul « test » disponible : il n'y a ni PHPUnit ni dossier `tests/`).
- Application non lancée : aucune base MySQL dans l'environnement.

Problèmes rencontrés / points d'attention :
- Pas de tests automatisés ni de gestionnaire de dépendances.
- `Connexion.php` contient des identifiants BDD en clair, présents dans l'historique git : à déplacer vers des
  variables d'environnement (modèle : `.env.example`, pas encore lu par le code) et à faire changer côté serveur.
- `.gitignore` complété et `.env.example` ajouté (ce dernier documente la config attendue, sans secret).
- `modules/mod_asso/logos/.9png` : nom de fichier suspect (probablement un `.png` mal nommé).
- La branche distante `s5_ai_coding` contenait déjà un `CLAUDE.md` (et `docs/API.md`) : conservés, cette section y est ajoutée.
