# Surface exposée du projet

Audit en lecture seule d'une application PHP MVC sans framework, avec un point d'entrée unique, `index.php`. Les numéros de ligne viennent d'une lecture des fichiers. Les fichiers `vue_*.php` n'ont pas tous été parcourus en détail, et l'application n'a pas été exécutée.

## 1. Routes et endpoints

Il n'y a qu'une URL, `index.php`. Elle est routée par trois paramètres GET.

| Paramètre | Fichier:lignes | Valeurs |
|---|---|---|
| `actionComposant` | `Composants/mod_connexion/mod_connexion.php:10,15-37` | `form_inscription`, `inscription`, `form_connexion`, `connexion`, `deconnexion`, `rgpd` |
| `module` | `modules/mod_asso/mod_asso.php:8,15-75` | `produit`, `panier`, `stock`, `compte`, `commande`, `admin`, `fournisseur`, `asso` |
| `action` | un routeur par module (voir ci-dessous) | actions du module |

Routeurs par module :

- **compte** : `mod_compte.php:10,16-41`.
- **admin** : `mod_admin.php:10,16-65`. Il contient 15 actions, dont `bannirUtilisateur`, `donnerRoleGestionnaire`, `validerDemandeAsso` et `accepterDemande`.
- **produit** : `mod_produit.php:10,16-40`.
- **panier** : `mod_panier.php:10,16-29`.
- **stock** : `mod_stock.php:9,15-35`.
- **commande** : `mod_commande.php:9,14-47`.
- **fournisseur** : `mod_fournisseur.php:10,16-30`.
- **asso** : `mod_asso.php:46-71`.

Autres points :

- `index.php:12-16` affiche `ModAsso` dès que `$_SESSION['login']` existe. Sinon il affiche la landing page.
- `index.php:4` appelle `Connexion::initConnexion()` avant `session_start()` (ligne 5).
- Les actions GET qui modifient l'état sont déclenchées par de simples liens. Ce sont par exemple `bannirUtilisateur`, `donnerRole*`, `enleverRole*`, `accepterDemande`, `refuserDemande`, `validerDemandeAsso`, `refuserDemandeAsso`, `refuserCommande`, `supprimerFournisseur`, `ajouterDansPanier`, `enleverProduit`, `viderPanier` et `validerPanier`.
- Le jeton CSRF n'est vérifié que sur certaines actions POST. Détail au point 3.

## 2. Entrées utilisateur

**`$_GET`**

- **Routage** : `actionComposant`, `module` et `action` (lignes ci-dessus).
- **Identifiants de ressources** :
  - `id` : `cont_admin.php:37,53,67,92,102,149,156,173,189`, `cont_asso.php:78-79`, `cont_produit.php:83,93,146`, `cont_panier.php:39,64`, `cont_fournisseur.php:75`, `cont_stock.php:169`, `cont_commande.php:21,74,93,148`.
  - `idFournisseur` : `cont_produit.php:148`, `cont_fournisseur.php:69`.
  - `assoId` et `utilisateurId` : `cont_admin.php:126-127,137`.
  - `asso` : `cont_admin.php:158,175`.
  - `role` : `cont_asso.php:94-95`.
  - `date` : `cont_commande.php:74,93,148`.

**`$_POST`**

- **Inscription** : `login`, `pwd`, `nom`, `prenom`, `telephone`, `email` (`cont_connexion.php:31-37`). Les champs `telephone` et `email` sont lus lignes 36-37 sans `isset`.
- **Connexion** : `login`, `pwd` (`cont_connexion.php:71-73`).
- **Profil** : `login`, `nom`, `prenom`, `telephone`, `email`, `pwd` (`cont_compte.php:72-84`).
- **Rechargement** : `montant` (`cont_compte.php:33-34`).
- **Association** : `nom` (`cont_asso.php:164`).
- **Produit** : `nom`, `prix` (`cont_produit.php:51-52,94-95`).
- **Restock** : `quantite` (`cont_produit.php:145,147`).
- **Fournisseur** : `nom`, `email`, `ville`, `telephone` (`cont_fournisseur.php:24-27`), et `idProduit` (ligne 69).
- **Stock et inventaire** : `stock[]` (`cont_stock.php:58`), `idinventaire` (ligne 96), `perte` (ligne 170).
- **Commande** : `id`, `date`, `code` (`cont_commande.php:79-82`).

**`$_FILES`**

- `imageAso`, `carteIdentite`, `statutAsso`, `procesVerbal` (`cont_asso.php:166,174,182,188-190`).
- `imageProduit` (`cont_produit.php:60,64`).
- `image` (`cont_produit.php:99-109`).

**`$_SESSION` écrite à partir d'une entrée** : `asso` prend `$_GET['id']` sans contrôle (`cont_asso.php:80`), et `role` prend `$_GET['role']` (`cont_asso.php:105`, après vérification).

## 3. Authentification, sessions, autorisation

**Session**

- `session_start()` est appelé sans options dans `index.php:5`. Il n'y a aucun réglage `httponly`, `secure` ou `samesite`.
- Aucun `session_regenerate_id` après connexion (`cont_connexion.php:78-80`). Risque de fixation de session.
- Les variables de session sont `login`, `id`, `role`, `asso`, `nomAsso`, `soldeClient`, `landing`, `tokenCSRF`, `messageOk` et `messagePasOk`.
- `deconnexion()` détruit la session (`cont_connexion.php:92-98`). L'action est atteignable par GET sans jeton, et le cookie de session n'est pas supprimé.

**Inscription et connexion**

- Le hash est calculé avec `password_hash` (`cont_connexion.php:43`, `cont_compte.php:81`).
- La vérification utilise `password_verify` (`cont_connexion.php:77`).
- Il n'y a ni limitation de tentatives, ni règle de complexité du mot de passe.
- Un login inexistant ou un mauvais mot de passe donnent la même issue. Mais `inscription` révèle qu'un login existe (`cont_connexion.php:50`).
- `connexion()` fait toujours un `header('Location…')` (lignes 88-89), même après `form_connexion()` (ligne 82).

**Jeton CSRF** (`token.php:5-26`)

- Il est valide à usage unique, généré avec `random_bytes(24)`.
- La comparaison utilise `!=` (`token.php:13`), pas `hash_equals`.
- Il est vérifié sur : `inscription` (`cont_connexion.php:30`), `connexion` (ligne 70), `modifierProfil` (`cont_compte.php:71`), `ajouterAssociation` (`cont_asso.php:162`), `ajouterNouveauProduit` (`cont_produit.php:47`), `modifierProduit` (ligne 90), `ajouterFournisseur` (`cont_fournisseur.php:21`), `ajoutInventaire` (`cont_stock.php:56`), `rapport` (ligne 94) et `ajouterPertes` (ligne 168).
- **Il est absent** sur `recharger` (`cont_compte.php:33`), `SuprimerProfil` (`cont_compte.php:113`), `restockerProduit` (`cont_produit.php:145`), `verifierCodeDeRetrait` (`cont_commande.php:78`), sur toutes les actions GET de la liste du point 1, et sur `ajouterProduitFournisseur` (`cont_fournisseur.php:67`).

**Contrôles d'accès par rôle**

Le contrôle repose uniquement sur `$_SESSION['role']`, sans vérification côté base. Deux défauts apparaissent :

- **Rôle non vérifié** : `$_SESSION['role']` est utilisé sans `isset` dans `cont_compte.php:18,33,91`, `cont_produit.php:19,40,48,82,91`, `cont_panier.php:18,39,64,80`, `cont_admin.php:15` et `cont_fournisseur.php:40`. Un utilisateur connecté sans rôle déclenche un avertissement PHP.
- **Périmètre d'association non vérifié** : l'`id` (ou `asso`) vient du GET sans vérifier qu'il appartient à l'association de la session. Cela concerne :
  - `cont_produit.php:83,93` : modification d'un produit d'une autre association.
  - `cont_fournisseur.php:75-77` et `:69` : suppression d'un fournisseur ou ajout d'un produit fournisseur d'une autre association.
  - `cont_admin.php:37-38,53-54,92-95,102-105` : un gestionnaire agit sur n'importe quel `id`.
  - `cont_commande.php:74,93` : validation ou refus de n'importe quelle commande, y compris d'une autre association.
  - `cont_stock.php:169` : ajout de pertes sur un produit quelconque.

**Points sensibles supplémentaires**

- `cont_admin.php:67-71` : `bannirUtilisateur` accepte `Admin` mais utilise `$_SESSION['asso']`, or un admin n'a pas d'`asso` (`mod_asso.php:10-11` le redirige vers admin).
- `cont_admin.php:154-169` : `donnerRoleGestionnaire` lit `$_GET['asso']` sans `isset`.
- `cont_admin.php:160-161` : `insertRoleBarman` est réutilisé pour attribuer le rôle Gestionnaire.
- `cont_admin.php:176` : `var_dump($idUtilisateur,$idAssociation)` est resté dans `enleverRoleGestionnaire`. Il expose des données de débogage.
- `cont_asso.php:77-145` : un utilisateur peut déclencher la création d'un rôle `enCours` sur n'importe quelle association valide (via `demandeAccesAssociation`, `modele_asso.php:46-49`). La vérification `existeAssociaion` est faite après l'écriture dans `$_SESSION['asso']` (ligne 80).
- `cont_asso.php:124-141` : le rôle est repris de la base, sans `Admin` géré.
- Modèle : `modele_admin.php:6,63-65` compare `statut="Valide"` à `"valide"` (`accepterAsso`, ligne 96), ce qui est incohérent.

## 4. Accès à la base de données

- **Connexion** : PDO MySQL dans `Connexion.php:10` (statique, partagée par héritage dans `modele.php:2`). Elle n'a ni `charset`, ni `PDO::ATTR_ERRMODE`, ni `ATTR_EMULATE_PREPARES=false`.
- **Requêtes** : elles sont toutes préparées, avec des paramètres liés. Aucune concaténation n'a été trouvée dans les fichiers `modele_*.php`. Les fichiers concernés sont `modele.php`, `modele_connexion.php`, `modele_compte.php`, `modele_admin.php`, `modele_asso.php`, `modele_produit.php`, `modele_panier.php`, `modele_commande.php`, `modele_stock.php`, `modele_fournisseur.php` et `modele_navbar.php` (ce dernier ne fait pas d'accès base). L'injection SQL classique semble donc bien traitée.
- **Écritures de logique métier sans transaction ni contrôle de type** :
  - `cont_panier.php:79-135` : validation du panier (commande, stock, solde) sans transaction. Cela ouvre une condition de concurrence sur le solde et le stock.
  - `cont_compte.php:37` : `$montant > 0` sur une chaîne. Aucune validation numérique, plafond ou format.
  - `cont_produit.php:52,95` : `prix` n'est pas validé. Un prix négatif est accepté.
  - `cont_produit.php:147`, `cont_stock.php:58,170` : `quantite`, `stock[]` et `perte` ne sont pas validés (négatifs, non numériques).
  - `cont_stock.php:58` : les clés de `stock[]` (`$idProduit`) viennent directement du POST.
- **Suppression** : `modele_compte.php:51-54` supprime un utilisateur sur `id` et `login`, sans nettoyage explicite des tables liées.
- **Requêtes du modèle sur des données de rôle** : `modele_connexion.php:28-32` (`estAdmin`), `modele_admin.php:11-16` (`dejaBarman`).
- **Données personnelles stockées** : login, nom, prénom, téléphone, email, mot de passe haché et `consentementRgpd` (`modele_connexion.php:11-13`).

## 5. Lecture et écriture de fichiers

**Upload d'images**

- **Logo d'association** : `cont_asso.php:166,180-183`, écrit dans `modules/mod_asso/logos/<id>.<ext>`.
- **Image produit (ajout)** : `cont_produit.php:60-65`, écrit dans `modules/mod_produit/img_produits/<id>.<ext>`.
- **Image produit (modification)** : `cont_produit.php:99-109`.
  - Il y a un `unlink($ancienChemin)` (ligne 106) sur un chemin lu en base, avant le remplacement.
  - `ajoutProduitInventaire` est appelé ligne 113.

**Upload de PDF légaux** : `cont_asso.php:171-190`, écrits dans `documentsLegaux/{carteIdentite,statutAsso,procesVerbal}_<id>.pdf`.

**Faiblesses communes à ces envois**

- La validation ne porte que sur l'extension du nom fourni par le client (`pathinfo`, lignes `cont_asso.php:166,174`, `cont_produit.php:60,101`). Il n'y a aucun contrôle du type MIME réel, de la taille, ni de `is_uploaded_file`.
- Le retour de `move_uploaded_file` n'est pas vérifié (`cont_asso.php:182,188-190`, `cont_produit.php:64,109`).
- `cont_asso.php:166-168` : `$_FILES['imageAso']` est lu sans `isset` ni test de `UPLOAD_ERR_OK`. L'association est insérée avant la validation des fichiers (ligne 167).
- En cas d'échec, `deleteAsso` est appelé (ligne 196), mais aucun fichier déjà écrit n'est nettoyé.
- `cont_produit.php:62-73` : le produit est créé avant la validation de l'image, puis supprimé si elle est invalide.
- Les dossiers `documentsLegaux/` et `modules/*/logos|img_produits/` sont dans le dépôt et sont vraisemblablement servis par le serveur web. Aucun `.htaccess` ni contrôle d'accès n'a été trouvé. Les pièces d'identité et statuts sont donc probablement accessibles par URL directe. Les liens de `vue_admin.php:207-213` pointent directement vers ces fichiers (avec `htmlspecialchars`).
- Le dépôt Git contient déjà 15 PDF légaux (`documentsLegaux/*_39|41|42|43|47.pdf`), dont des cartes d'identité. À traiter comme une fuite de données personnelles.
- Aucun fichier n'est lu dynamiquement par chemin issu de l'utilisateur (pas de `include` variable côté requête). Les `include_once` de `mod_asso.php:17-42` utilisent des chemins constants.

## 6. Appels externes

- **Aucun appel sortant côté serveur** : pas de `curl`, `file_get_contents` ni de client HTTP.
- **Ressources chargées par le navigateur depuis un CDN** dans `template.php:6-8` : Bootstrap CSS et JS 5.3.8 (avec `integrity` SRI) et Bootstrap Icons 1.11.3 (`template.php:8`, sans SRI).
- **Base de données distante** : l'hôte `database-etudiants.iut.univ-paris8.fr` dans `Connexion.php:10` (voir point 7).
- **Redirections** : uniquement vers des chemins internes constants (`header('Location: index.php?...')`). Aucune redirection vers une URL fournie par l'utilisateur n'a été relevée.

## 7. Secrets et configuration

- **Identifiants de base de données en clair, versionnés** : `Connexion.php:10`
  - nom de base et utilisateur : `dutinfopw201668`
  - mot de passe : présent en clair dans le fichier (non reproduit ici)
  - hôte : `database-etudiants.iut.univ-paris8.fr`

  Ce mot de passe est dans l'historique Git, même s'il est retiré du fichier. Il faut le considérer comme compromis et le changer.
- `Connexion.php:11-12` : une deuxième configuration est commentée (`root`, mot de passe vide, base `buvette`).
- `Connexion.php:18` : URL de phpMyAdmin en commentaire.
- **Aucun fichier de configuration ni variable d'environnement** : tout est codé en dur. `.gitignore` ne contient que `/.idea/`.
- **Gestion d'erreurs** : aucun `display_errors`, `error_reporting` ni `try/catch` autour de `new PDO` (`Connexion.php:10`). Une erreur de connexion peut afficher l'identifiant et le mot de passe dans la page selon la configuration PHP. `cont_admin.php:176` contient aussi un `var_dump`.
- **En-têtes de sécurité** : aucune définition de CSP, `X-Frame-Options`, `X-Content-Type-Options` ni HSTS dans le dépôt.
- **Consentement RGPD** : la chaîne `"à consentit à la collecte"` est écrite en dur à l'inscription (`modele_connexion.php:13`), sans case cochée par l'utilisateur.

## 8. Sorties HTML et XSS (lié aux entrées)

- `template.php:21,32` injecte `$contenuMenu` et `$contenu` par concaténation, sans échappement supplémentaire. La protection dépend donc de chaque vue.
- `htmlspecialchars` est utilisé dans les vues (par exemple 31 fois dans `vue_commande.php`, 25 dans `vue_stock.php`, 19 dans `vue_admin.php`). La couverture n'est pas totale. Chaque vue n'a pas été vérifiée une par une.
- **Sorties non échappées confirmées** dans `vue_generique.php` :
  - `:103` et `:114` affichent `$_SESSION['messageOk']` et `$_SESSION['messagePasOk']` sans échappement.
  - `:76`, `:79`, `:85` affichent `$titre`, `$description`, `$href` et `$action`, également sans échappement.
- Ces messages contiennent des données utilisateur :
  - `cont_connexion.php:50` reprend `$_POST['login']` : XSS réfléchi et stocké en session.
  - `cont_admin.php:72` reprend le login d'un utilisateur banni.
  - `cont_produit.php:116` reprend `$nom`, valeur POST du nom du produit.
  - `cont_stock.php:176,179` reprend `$pertes` (POST) et `$diff`.
  - `cont_panier.php:111` reprend `$soldeManquant`, calculé à partir du solde.
- `vue_commande.php` : `confirmerRetrait` reçoit `$_GET['id']` et `$_GET['date']` (`cont_commande.php:75`). À vérifier à l'affichage, cette vue n'ayant pas été contrôlée ligne par ligne.
- `cont_connexion.php:41,46,49,53` : la vue du formulaire est rendue avant la définition du message d'erreur (ligne 50), sans redirection.

## Synthèse par priorité

1. **Secrets** : `Connexion.php:10`, mot de passe de la base dans le dépôt.
2. **Données personnelles** : `documentsLegaux/*.pdf` versionnés et probablement accessibles par URL directe.
3. **Contrôle d'accès** (IDOR) : les `id` GET ne sont pas rattachés à l'association de la session (`cont_produit.php:83,93`, `cont_fournisseur.php:69,75`, `cont_admin.php`, `cont_commande.php:74,93`).
4. **CSRF** : actions sensibles en GET ou POST sans jeton (point 3).
5. **Envois de fichiers** : validation par extension uniquement, aucun contrôle MIME ni taille (`cont_asso.php:166-190`, `cont_produit.php:60-109`).
6. **XSS** : messages de session non échappés (`vue_generique.php:103,114`).
7. **Sessions** : pas de `session_regenerate_id`, pas de flags de cookie (`index.php:5`, `cont_connexion.php:78`).
8. **Validation métier et concurrence** : montants, prix et quantités non contrôlés, validation du panier sans transaction (`cont_compte.php:37`, `cont_panier.php:79-135`).
9. **Divers** : `var_dump` restant (`cont_admin.php:176`), PDO sans mode d'erreur explicite, en-têtes de sécurité absents.
