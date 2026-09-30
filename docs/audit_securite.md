# Audit de sécurité — I-CONNECT

Point de départ : `docs/surface_exposee.md`. Chaque affirmation a été revérifiée dans le code. Les tests sont dans `tests/securite/`.

## Lancer les tests

```sh
./tests/securite/run_all.sh                 # tous les scripts
php tests/securite/test_xss.php             # un seul script
```

- PHP 8.x suffit, avec `pdo_sqlite` pour les tests qui exécutent les contrôleurs. Il n'y a pas de MySQL, pas de réseau, pas de Composer.
- Chaque ligne est `[VULNÉRABLE]` (la faille est présente) ou `[OK]`. Le code de sortie vaut 1 si une faille est détectée.
- Les tests ne cassent rien. Les contrôleurs tournent sur une base SQLite en mémoire qui remplace `Connexion::$bdd`. Les marqueurs XSS sont inoffensifs (`<i id="marqueur-xss">`). Le seul serveur lancé est `php -S` sur 127.0.0.1, en requête HEAD.
- Le schéma SQLite des tests est déduit des requêtes. Le dump MySQL fourni (`dutinfopw201668.sql`) a ensuite servi à lever les incertitudes : voir la section 9. Ce dump contient des données personnelles et des hachages : **ne pas le committer**. Le test `test_schema_bdd.php` ne lit que sa structure : `DUMP_SQL=chemin/dump.sql ./tests/securite/run_all.sh`.
- Après correction d'une faille, son test doit passer à `[OK]`. Cela respecte la règle du `CLAUDE.md` : un test accompagne chaque correctif.

Légende. **Certitude** : *Confirmée* = exécutée par un test. *Haute* = lue dans le code, non exécutable sans MySQL. *Moyenne* = dépend d'un élément non versionné.

## Corrections apportées à `surface_exposee.md`

| Affirmation du document | Verdict |
|---|---|
| Injection SQL : requêtes toutes préparées | **Confirmé**. `INJ-SQL`, `INJ-CMD`, `INJ-EVAL`, `INJ-LFI`, `INJ-SSTI`, `INJ-SSRF` et `INJ-REDIR` sortent tous `[OK]`. |
| `cont_stock.php:169` : pertes sur un produit quelconque | **Infirmé**. Le `UPDATE` est borné par l'inventaire de l'association en session. Le vrai défaut est l'absence de validation : pertes négatives (LM-4). |
| `cont_admin.php` : un gestionnaire agit sur « n'importe quel id » | **Nuancé**. L'`id` est un identifiant d'utilisateur et les requêtes sont bornées par `$_SESSION['asso']`. Le vrai défaut est que `asso` peut être changé sans vérification (CA-1). |
| Erreur PDO : mot de passe affiché | **Infirmé sur PHP ≥ 8.2**. PDO masque ses arguments dans la trace (`SensitiveParameterValue`). Seuls l'hôte et l'utilisateur fuient (SEC-5). Le mot de passe en clair dans git reste grave (SEC-1). |
| Vue commande « à vérifier » | **Faille confirmée** (XSS-6). |

**Non listé dans le document** : CA-1, CA-4, CA-5, CA-6, UP-1, UP-4, XSS-1, XSS-2, XSS-4, XSS-6, LM-4, LM-5, AUTH-8, AUTH-9 et la section 9.

---

## 1. Injections (SQL, commandes, templates)

**Aucune faille exploitable trouvée.** Test : `test_injections.php`.

- `INJ-SQL` : aucun `prepare`, `query` ou `exec` construit avec une variable.
- Pas de `shell_exec`, `eval`, `unserialize` ni moteur de templates. Aucun `include` piloté par l'utilisateur (le routage passe par un `switch` sur liste blanche).
- `INJ-SQL-CFG` (faible, durcissement) : `Connexion.php:10` n'a ni `charset` ni `ATTR_EMULATE_PREPARES=false`. Ce n'est pas exploitable en l'état.

---

## 2. XSS — test : `test_xss.php`

Cause commune : les vues construisent du HTML par concaténation, et `htmlspecialchars` est appliqué de façon inégale.

### XSS-1 — Demandes d'inscription (stocké, gestionnaire) — **Haute gravité** — Confirmée
- **Fichier** : `modules/mod_admin/vue_admin.php:156-159`
- **Extrait** : `<td>'. $demande['login'].'</td>` (idem `nom`, `prenom`, `telephone`)
- **Scénario** : un compte s'inscrit avec `prenom` = HTML/JS, puis demande à rejoindre une association. Le gestionnaire ouvre « Demande » et le script s'exécute dans sa session (rôle Gestionnaire).
- **Impact** : actions au nom du gestionnaire. Combiné aux GET sans jeton, cela donne un contrôle complet de l'association.

### XSS-2 — Modale profil client (stocké, barman) — **Moyenne** — Confirmée
- **Fichier** : `modules/mod_commande/vue_commande.php:118-119`
- **Extrait** : `<p>login:'.$utilisateur['login'] .'</p>` et `<p>mail:'.$utilisateur['email'].'</p>`
- **Scénario** : `email` ou `login` piégé. Le barman clique sur « Afficher profil » d'une commande.
- **Impact** : exécution dans la session du barman.

### XSS-3 — Messages flash — **Moyenne** — Confirmée
- **Fichier** : `vue_generique.php:57,68` (aussi `:30,33,39,40` pour les modales)
- **Extrait** : `'.$_SESSION['messageOk'].'`
- **Sources utilisateur** : `cont_admin.php:72` (login du banni), `cont_produit.php:116` (nom produit), `cont_connexion.php:50` (login saisi), `cont_stock.php:176`.
- **Scénario** : un utilisateur choisit un login HTML. Quand un gestionnaire le bannit, le message « Vous avez banni … » s'exécute chez le gestionnaire (XSS-5 vérifie cette source).
- **Nuance** : le cas `cont_connexion.php:50` est un self-XSS, car il exige le jeton CSRF de la victime.

### XSS-4 — Nom d'association de la session — **Moyenne** — Confirmée
- **Fichiers** : `$_SESSION['nomAsso']` est affiché sans échappement dans `vue_admin.php:71,142`, `vue_commande.php:139,214,286,362,376`, `vue_compte.php:13,43`, `vue_fournisseur.php:54`, `vue_panier.php:15`, `vue_produit.php:157`, `vue_stock.php:15,242`.
- **Scénario** : n'importe quel utilisateur crée une association dont le nom est du HTML (`cont_asso.php:164`). `aAppuyeAsso` ne vérifie pas le statut (`cont_asso.php:85`), donc le nom se retrouve dans les pages de toute personne qui la sélectionne. Il touche tous les membres après validation par l'admin.

### XSS-6 — Retrait de commande (réfléchi) — **Moyenne** — Confirmée
- **Fichier** : `modules/mod_commande/vue_commande.php:398-399`, alimenté par `cont_commande.php:75`
- **Extrait** : `<input type="hidden" name="id" value="'.$id.'">` avec `$id = $_GET['id']`
- **Scénario** : un lien `…&action=valideCommande&id="><…` envoyé à un barman. Aucun jeton n'est requis (GET).

**Correctif commun** : `htmlspecialchars($x, ENT_QUOTES, 'UTF-8')` sur toute sortie, ou une fonction `e()` centralisée. Ajouter une CSP (voir SEC-6a).

---

## 3. Authentification et sessions — test : `test_auth_sessions.php`

| ID | Constat | Fichier:lignes | Gravité | Certitude |
|---|---|---|---|---|
| AUTH-1 | Pas de `session_regenerate_id` après connexion : fixation de session | `cont_connexion.php:78-80` | Moyenne | Confirmée |
| AUTH-2 | Déconnexion sans suppression du cookie de session | `cont_connexion.php:92-98` | Faible | Confirmée |
| AUTH-3 | Aucune limitation de tentatives de connexion | `cont_connexion.php:69-90` | Moyenne | Confirmée |
| AUTH-4 | Aucune règle de robustesse côté serveur (seul `minlength` HTML) | `cont_connexion.php:29-56` | Moyenne | Confirmée |
| AUTH-5 | Champ mot de passe en `type="text"` (visible à l'écran) | `vue_connexion.php:23,63` | Faible | Confirmée |
| AUTH-6 | Énumération de logins (« le login X existe déjà ») | `cont_connexion.php:50` | Faible | Confirmée |
| AUTH-7 | Déconnexion par GET sans jeton | `modele_navbar.php:67` | Faible | Confirmée |
| AUTH-8 | `!=` au lieu de `hash_equals` pour le jeton CSRF | `token.php:19` | Faible | Confirmée |
| AUTH-9 | `utilisateurs.login` non unique en base : deux inscriptions simultanées peuvent créer deux comptes au même login (`verifLoginExiste` puis `INSERT` non atomiques). `getUtilisateur` ne lit que la première ligne | `modele_connexion.php:11-26`, schéma | Moyenne | Haute (schéma confirmé par le dump, course non exécutée) |

**AUTH-8, démonstration** : avec un jeton de la forme `0e<chiffres>`, `Token::verifierToken('0')` renvoie `true`. La probabilité est de l'ordre de 1 sur 10 000, mais le défaut est réel.

### CSRF-1 — Actions d'état en GET, sans jeton — **Haute** — Confirmée
- **Fichiers** : `cont_admin.php:66-76` (`bannirUtilisateur`), idem `donnerRole*`, `enleverRole*`, `accepterDemande`, `refuserDemande`, `validerDemandeAsso`, `refuserCommande`, `supprimerFournisseur`. Le jeton manque aussi sur `recharger`, `SuprimerProfil` et `restockerProduit` (POST).
- **Scénario** : un gestionnaire connecté visite une page piégée contenant `<img src=".../index.php?module=admin&action=bannirUtilisateur&id=20">`. Le compte 20 perd ses rôles.
- **Test** : le ban est exécuté sans jeton.
- **Correctif** : POST plus jeton sur toute action qui modifie l'état, et cookie `SameSite=Lax`.

---

## 4. Contrôle d'accès et IDOR — test : `test_controle_acces.php`

### CA-1 — Changement d'association en gardant le rôle — **Critique** — Confirmée
- **Fichier** : `modules/mod_asso/cont_asso.php:77-93`
- **Extrait** : `$_SESSION['asso'] = $idAsso;` (ligne 80), avant tout contrôle. Le rôle n'est pas recalculé si l'utilisateur n'a aucun rôle dans l'association cible.
- **Scénario** : Alice est Gestionnaire de A. Elle appelle `index.php?module=asso&action=choisiAsso&id=<id de B>`. La session devient `role=Gestionnaire`, `asso=B`. Toutes les actions de gestion (produits, stock, fournisseurs, comptes, ban) s'appliquent désormais à B.
- **Impact** : c'est la faille racine des IDOR multi-associations. Elle rend exploitable tout ce que le document attribuait à des `id` non bornés.
- **Test** : le rôle reste `Gestionnaire` et `asso` passe à `2`.
- **Correctif** : ne fixer `asso` et `role` qu'après avoir vérifié le rôle en base pour cette association, et effacer `role` sinon.

### CA-2 — Suppression d'un fournisseur d'une autre association — **Haute** — Confirmée
- **Fichiers** : `cont_fournisseur.php:74-78` et `modele_fournisseur.php:10-13`
- **Extrait** : `DELETE FROM fournisseur WHERE id = ?` (aucun `idAssociation`). Même défaut pour `insertProduitFournisseur` (`cont_fournisseur.php:67-70`).
- **Scénario** : le gestionnaire de A supprime le fournisseur 100 de B via `…action=supprimerFournisseur&id=100`.

### CA-3 — Lecture et modification d'un produit d'une autre association — **Haute** — Confirmée
- **Fichiers** : `cont_produit.php:80-98`, `modele_produit.php:14-23,64-70`
- **Extrait** : `update produit set nom = (?), prix = (?) where id = (?)`
- **Scénario** : un gestionnaire de A change le nom et le prix (par exemple 0) d'un produit de B.
- **Test** : appel exact de `updateProduit` ; le contrôleur ne vérifie jamais l'appartenance.

### CA-4 — Remboursement répétable d'une commande — **Haute** — Confirmée
- **Fichiers** : `cont_commande.php:92-111`, `modele_commande.php:33-52,65-69`
- **Extrait** : `rembourser(...)` puis `refuser(...)`, sans aucun test du `statut`.
- **Scénario** : un barman (ou un lien piégé, car c'est un GET) appelle `refuserCommande` N fois. Le client est crédité N fois, sur une commande déjà livrée. Les requêtes ne filtrent pas non plus par association (`WHERE id=? AND date=?`).
- **Test** : 3 appels sur une commande de 15 € donnent un solde de 45 €.
- **Correctif** : `WHERE statut='Encours' AND idAssociation=?`, vérifier `rowCount()`, et utiliser une transaction.

### CA-5 — Rétrogradation de rôles par `accepterDemande` — **Moyenne** — Confirmée
- **Fichiers** : `cont_admin.php:91-109`, `modele_admin.php:62-70`
- **Extrait** : `UPDATE role SET role = "Client" WHERE idUtilisateur = ? AND idAssociation = ?`
- **Scénario** : `accepterDemande&id=<un gestionnaire>` transforme son rôle en Client. `refuserDemande` supprime tous ses rôles. Il manque `AND role='enCours'`.

### CA-6 — Un gestionnaire peut supprimer le rôle Admin — **Haute** — Confirmée
- **Fichiers** : `cont_admin.php:66-76` et `modele_admin.php:114-117`
- **Extrait** : `DELETE FROM role WHERE idUtilisateur = ? AND idAssociation = ?`, sans vérifier le rôle de la cible.
- **Donnée réelle** : dans le dump, le seul Admin (utilisateur 1) est une ligne `role` rattachée à l'association 1 (SCH-8).
- **Scénario** : un gestionnaire de l'association 1 appelle `bannirUtilisateur&id=1`. Plus aucun compte n'est administrateur, et la validation des demandes de création d'association devient impossible. Comme c'est un GET sans jeton, un lien piégé suffit (CSRF-1).
- **Test** : `deleteUtilisateur(1, 1)`, appel exact du contrôleur.
- **Correctif** : refuser l'action si la cible a un rôle Admin ou Gestionnaire, et stocker le rôle Admin hors de la table des rôles d'association.

### Autres points (Haute, lecture de code)
- `cont_commande.php:147-153` et `modele_commande.php:127-131` : un barman lit login, email et solde du client de toute commande `(id, date)`, même d'une autre association.
- `cont_produit.php:148` : l'`idFournisseur` du restock n'est pas borné à l'association.
- Hors périmètre du document : `modele_commande.php:70-77` contient une affectation `$_SESSION['role']='barman'` dans du code mort. Elle deviendrait grave si la méthode était appelée un jour.

---

## 5. Secrets, configuration, debug, CORS — test : `test_config_secrets.php`

| ID | Constat | Fichier:lignes | Gravité | Certitude |
|---|---|---|---|---|
| SEC-1 | **Mot de passe BDD en clair dans git** (valeur masquée par les tests) | `Connexion.php:10` | **Critique** | Confirmée |
| SEC-2 | 15 pièces légales (identité, statuts, PV) versionnées | `documentsLegaux/` | **Critique** (RGPD) | Confirmée |
| SEC-3 | Aucun `.htaccess` ni règle d'accès sur `documentsLegaux/` | racine | Haute | Confirmée |
| SEC-4 | `var_dump()` actifs | `modele_panier.php:113-114`, `cont_admin.php:176` | Moyenne | Confirmée |
| SEC-5 | Trace PDO : mot de passe visible **uniquement PHP < 8.2** | `Connexion.php:10` | Faible ici | Confirmée (`[OK]` sur 8.4) |
| SEC-6a | Aucun en-tête de sécurité (CSP, X-Frame-Options, nosniff, HSTS) | `index.php`, `template.php` | Moyenne | Confirmée |
| SEC-6b | `session_start()` sans `httponly`, `secure`, `samesite` | `index.php:5` | Moyenne | Confirmée |
| SEC-6c | Bootstrap Icons sans SRI | `template.php:8` | Faible | Confirmée |

- **SEC-1** : même retiré du fichier, le mot de passe reste dans l'historique git. **Il faut le changer côté serveur**, puis le déplacer dans une variable d'environnement.
- **SEC-4** : `getCode()` (`modele_panier.php:112-116`) affiche un code aléatoire puis en retourne un autre. Le `var_dump` ne fuit pas le vrai code, mais il pollue la page (LM-7).
- **SEC-2 et SEC-3** : les fichiers sont servis en direct (UP-5).
- **CORS** : aucune en-tête CORS n'est émise et l'application n'expose pas d'API. Le navigateur applique donc la politique de même origine par défaut, il n'y a pas de faille CORS.
- **Mode debug** : il n'y a pas de drapeau debug à proprement parler. Les `var_dump` du SEC-4 en tiennent lieu, et aucune configuration d'erreurs n'est posée (`display_errors` dépend du serveur).

---

## 6. Dépendances — test : `test_dependances.php`

| ID | Constat | Gravité | Certitude |
|---|---|---|---|
| DEP-1 | Aucun `composer.json` ni lockfile : aucun audit automatique possible | Faible | Confirmée |
| DEP-2 | Versions de PHP et MySQL non déclarées | Faible | Confirmée |
| DEP-3 | Bootstrap Icons 1.11.3 chargé sans SRI (Bootstrap 5.3.8 en a un) | Faible | Confirmée |

- Je n'ai pas pu consulter de base d'avis de sécurité (pas de réseau), donc je **ne conclus pas** que Bootstrap 5.3.8 ou Bootstrap Icons 1.11.3 sont vulnérables. À vérifier sur les avis officiels.
- Recommandation : épingler la version de PHP dans un `composer.json` minimal et lancer `composer audit`.

---

## 7. Téléversements et traversées de répertoires — test : `test_uploads_traversal.php`

### UP-1 — Traversée de répertoire dans le chemin d'écriture — **Haute** — Confirmée
- **Fichier** : `modules/mod_produit/cont_produit.php:93,108-109`
- **Extrait** :
  ```php
  $idProduit = $_GET['id'];
  $cheminNouveauFichier = 'modules/mod_produit/img_produits/' . $idProduit . '.' . $extension;
  move_uploaded_file($_FILES['image']['tmp_name'], $cheminNouveauFichier);
  ```
- **Scénario** : un gestionnaire envoie `id=../../../x` dans l'URL de modification. Le fichier est écrit en dehors de `img_produits/`, avec l'extension jpg, jpeg, png ou webp choisie par le client. Il peut écraser une image existante de la racine web.
- **Test** : avec `id=../../../tests/securite/_canari`, le dossier cible résolu est `tests/securite` (rien n'est écrit).
- **Correctif** : `(int)$_GET['id']`, puis un nom de fichier généré côté serveur.

### UP-2 — Validation par extension seulement — **Moyenne** — Confirmée
- **Fichiers** : `cont_asso.php:166,174`, `cont_produit.php:60,101`
- Aucun contrôle du type réel, de la taille, de `UPLOAD_ERR_OK` ni de `is_uploaded_file`. Le contenu n'est pas vérifié (un fichier `.jpg` peut contenir n'importe quoi). L'extension est limitée à jpg, jpeg, png et webp, donc pas de `.php` direct.
- Conditionnel : avec un Apache mal configuré (`AddHandler` sur `.php.` au milieu d'un nom), `x.php.jpg` pourrait s'exécuter. Ce cas n'est pas démontré.

### UP-3 — Retour de `move_uploaded_file` ignoré — **Faible** — Confirmée
- `cont_asso.php:182,188-190` et `cont_produit.php:64,109` annoncent un succès même si l'écriture échoue.

### UP-4 — Association retrouvée par nom : écrasement et suppression — **Haute** — Confirmée (le dump confirme que `nom` n'est pas unique et qu'il n'y a aucune clé étrangère)
- **Fichier** : `modules/mod_asso/cont_asso.php:167-168,184-190,196`
- **Extrait** : `insertAssociation($nom); $nomFichier = $this->modele->idAsso($nomAssociation);`. La recherche par nom renvoie la **première** association homonyme.
- **Scénario** : un utilisateur crée une « nouvelle » association du même nom qu'une association valide (id 39). Les PDF de la nouvelle demande sont écrits dans `documentsLegaux/*_39.pdf`, écrasant les vrais documents. Si la validation échoue, `deleteAsso(39)` supprime l'association existante.
- **Test** : `idAsso('Buvette')` renvoie l'id existant (39), puis `deleteAsso` le supprime.
- **Schéma réel** : `association.nom` n'a pas de contrainte `UNIQUE` (seul `id` en a une) et la base n'a aucune clé étrangère. L'`INSERT` homonyme réussit donc, et `deleteAsso` supprime l'association existante sans être bloqué (SCH-1, SCH-3).
- **Correctif** : utiliser `lastInsertId()`.

### UP-5 — Pièces légales servies sans authentification — **Critique** — Confirmée
- **Fichier** : liens dans `vue_admin.php:207-213`, dossier `documentsLegaux/`
- **Test** : `GET /documentsLegaux/procesVerbal_39.pdf` renvoie `HTTP/1.1 200 OK` sans session (serveur PHP intégré).
- **Impact** : cartes d'identité et statuts accessibles à quiconque connaît l'URL, dont le nom est prévisible (`<type>_<id>.pdf`).
- **Correctif** : stocker hors racine web et servir via un contrôleur qui vérifie le rôle Admin, ou refuser l'accès au niveau du serveur.

---

## 8. Logique métier — test : `test_logique_metier.php`

| ID | Constat | Fichier:lignes | Gravité | Certitude |
|---|---|---|---|---|
| LM-1 | `"abc" > 0` est vrai en PHP 8 ; aucun contrôle numérique ni plafond du montant | `cont_compte.php:34-37` | Moyenne | Confirmée |
| LM-2 | Le client se crédite lui-même, sans preuve de paiement | `cont_compte.php:32-50` | Haute si non voulu | Confirmée (**à confirmer avec l'équipe** : est-ce le comportement voulu ?) |
| LM-3 | Prix, quantité et perte sans validation | `cont_produit.php:52,95,147`, `cont_stock.php:58,170` | Moyenne | Confirmée |
| LM-4 | Perte négative : `ajouterPertes(-100)` fait passer le stock de 5 à 105 | `cont_stock.php:170-175` | Moyenne | Confirmée |
| LM-5 | Code de retrait NULL + code vide accepté (`==`) | `modele_commande.php:141` | Moyenne | Confirmée. Dans le dump, 56 commandes sur 73 n'ont pas de code, dont 14 encore « Encours » (SCH-5, SCH-6) |
| LM-6 | Aucune transaction : course sur solde et stock à la validation du panier | `cont_panier.php:79-135` | Moyenne | Confirmée (absence) ; course elle-même non exécutée |
| LM-7 | `getCode()` tire un code, l'affiche, puis en retourne un autre | `modele_panier.php:112-116` | Faible | Confirmée |

---

## 9. Schéma de base de données (dump fourni) — test : `test_schema_bdd.php`

| ID | Constat | Gravité | Certitude |
|---|---|---|---|
| SCH-1 | Aucune clé étrangère : pas de cascade, lignes orphelines après `deleteAsso` ou suppression de compte | Moyenne | Confirmée |
| SCH-2 | `utilisateurs.login` non unique (AUTH-9) | Moyenne | Confirmée |
| SCH-3 | `association.nom` non unique (UP-4) | Haute | Confirmée |
| SCH-4 | Table `role` sans clé primaire ni unicité : doublons de rôles et de demandes | Faible | Confirmée |
| SCH-5 | `commande.code` nullable (LM-5) | Moyenne | Confirmée |
| SCH-6 | 56 commandes sur 73 sans code, dont 14 « Encours » validables sans code | Moyenne | Confirmée |
| SCH-7 | Tables en `latin1` et connexion PDO sans `charset` : caractères hors latin1 mal gérés (durcissement) | Faible | Confirmée |
| SCH-8 | Le seul rôle Admin est rattaché à l'association 1 (CA-6) | Haute | Confirmée |
| SCH-9 | `prix` et `solde` en `decimal(10,0)` : aucune décimale, les prix à centimes sont arrondis | Faible (fonctionnel) | Confirmée |

- Le dump est daté du 30/09/2026, serveur MySQL 8.0 (mode strict par défaut non confirmé par le dump). En mode strict, un montant non numérique (LM-1) provoque une exception PDO non interceptée plutôt qu'un enregistrement silencieux.
- Les **hachages** sont bien des `bcrypt` (`$2y$10$…`) : point positif. Plusieurs comptes ont des e-mails et téléphones réels dans ce fichier : à traiter comme des données personnelles.

---

## Priorités de correction

1. **SEC-1** : changer le mot de passe BDD côté serveur, puis externaliser la configuration.
2. **SEC-2, SEC-3, UP-5** : retirer les PDF du dépôt, les stocker hors racine web et contrôler l'accès.
3. **CA-1** : valider association et rôle avant de les écrire en session. Cela neutralise en partie CA-2 et CA-3 pour les utilisateurs légitimes. **CA-6** : protéger le compte Admin.
4. **CA-2, CA-3, CA-4, CA-5** : borner chaque requête par `idAssociation` et vérifier le statut. **SCH-1 à SCH-3** : ajouter clés étrangères et contraintes `UNIQUE` (`login`, `nom`).
5. **CSRF-1** : POST et jeton pour les actions d'état, `SameSite` sur le cookie.
6. **XSS-1 à XSS-6** : échapper toutes les sorties et ajouter une CSP.
7. **UP-1 et UP-4** : entier forcé pour `id`, `lastInsertId()`, noms de fichiers générés.
8. **AUTH-1 à AUTH-4** et **LM-1 à LM-7** : régénérer la session, limiter les tentatives, valider les entrées, utiliser des transactions.

## Limites de cet audit

- Application non exécutée avec MySQL. Les tests reproduisent les contrôleurs sur SQLite, avec un schéma déduit des requêtes et recoupé avec le dump.
- Les PDF, images et le PDF du rapport n'ont pas été lus (règle du `CLAUDE.md`).
- Pas de consultation de bases d'avis de sécurité (pas de réseau) : le point dépendances est limité.
- La concurrence (LM-6) n'est pas exécutée.
