# CLAUDE.md

## Projet
I-CONNECT est une application web de gestion de buvettes associatives, réalisée pour la
SAE 3.02 de l'IUT de Montreuil. Clients, barmans, gestionnaires et administrateurs y gèrent
produits, stocks, fournisseurs, commandes, paniers et soldes, pour une ou plusieurs associations.

## Stack
- PHP (aucune version déclarée ; testé avec PHP 8.4 en local), sessions PHP, rendu HTML côté serveur.
- MySQL via PDO (aucune version déclarée, pas de schéma `.sql` versionné ; voir `documentsArendre/mld.png`).
- Front : Bootstrap 5.3.8 et Bootstrap Icons 1.11.3 (CDN dans `template.php`), `style.css`.
- Aucun framework, ni Composer, ni npm.

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
