# CLAUDE.md

## Projet
SAE 3.02 (IUT Montreuil) : application web de gestion de buvettes associatives.
Rôles observés : Client, Barman, Gestionnaire, Admin. Fonctions : produits, stock,
inventaire, fournisseurs, commandes, panier, compte/solde, associations.

## Stack
- PHP (objet + sessions), rendu HTML côté serveur, CSS dans `style.css`.
- Aucun framework, pas de Composer, pas de npm.
- MySQL via PDO (requêtes préparées). Aucun schéma `.sql` versionné
  (voir `documentsArendre/mcd.png` et `mld.png`).

## Structure
- `index.php` : point d'entrée. Connexion BDD, session, choix du module, puis `template.php`.
- `Connexion.php` : classe PDO (`Connexion::initConnexion()`), base statique `$bdd`.
- `modele.php` : classe `Modele` commune (requêtes partagées), étend `Connexion`.
- `vue_generique.php`, `template.php`, `token.php` / `abstractToken.php` : vue de base,
  gabarit de page, jeton CSRF.
- `modules/mod_*` : un module fonctionnel par dossier
  (admin, asso, commande, compte, fournisseur, panier, produit, stock) + `landingPage`.
- `Composants/` : éléments réutilisables (`comp_navbar`, `mod_connexion`).
- `documentsArendre/` (rendus : notice, rapport, MCD/MLD, personas) et
  `documentsLegaux/` (pièces des associations) : ne pas modifier.

## Installation
Prérequis : PHP 8 avec l'extension `pdo_mysql`, et un serveur MySQL.
1. Créer une base `buvette` et importer le schéma (à obtenir auprès de l'équipe).
2. Dans `Connexion.php`, utiliser la ligne locale commentée
   (`mysql:host=localhost;dbname=buvette;charset=utf8`, `root`, mot de passe vide).

## Lancement
```
php -S localhost:8000
```
Puis ouvrir http://localhost:8000/index.php.

## Tests
Aucun test automatisé ni linter. Vérifier à la main dans le navigateur.
Contrôle de syntaxe : `find . -name '*.php' -exec php -l {} \;`

## Conventions observées
- Architecture MVC par module, avec 4 fichiers :
  `mod_X.php` (routeur), `cont_X.php` (contrôleur), `modele_X.php`, `vue_X.php`.
- Routage : `?module=...&action=...`. Le `switch` dans `mod_X.php` appelle une méthode du
  contrôleur, avec `unrecognizedAction()` par défaut.
- Noms de classes : `ModX`, `ContX`, `ModeleX`, `VueX`. Le code et les commentaires sont en français.
- Les vues sont des `ContX->getVue()` ; le rendu est renvoyé à `index.php`.
- Le contrôleur vérifie le rôle avec `$_SESSION['role']`, puis utilise `$_SESSION['id']`
  et `$_SESSION['asso']`.
- SQL : toujours `prepare()` + `execute([...])`, jamais de concaténation de paramètres.
- Commentaires PHPDoc au-dessus des méthodes complexes du contrôleur.

## Points d'attention
- `Connexion.php` contient des identifiants BDD en clair : ne pas les copier ni les
  propager. À terme, les sortir du dépôt (fichier de config ignoré par git).
- `.gitignore` ne contient que `/.idea/`.
