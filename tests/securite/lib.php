<?php
// Mini-harnais : aucune dépendance, aucune base, aucun réseau.
// Usage : php tests/securite/<test>.php  -> code de sortie 1 si la faille est PRÉSENTE.
define('RACINE', dirname(__DIR__, 2));
$GLOBALS['failles'] = 0;

function src(string $chemin): string { return file_get_contents(RACINE . '/' . $chemin); }

/** Affiche le résultat d'un contrôle. $faillePresente=true => VULNÉRABLE. */
function verdict(string $id, string $titre, bool $faillePresente, string $preuve = ''): void {
    if ($faillePresente) { $GLOBALS['failles']++; }
    printf("[%s] %s : %s\n", $faillePresente ? 'VULNÉRABLE' : 'OK        ', $id, $titre);
    if ($preuve !== '') { echo "            preuve : $preuve\n"; }
}
function fin(): void { echo "\n" . $GLOBALS['failles'] . " faille(s) détectée(s)\n"; exit($GLOBALS['failles'] > 0 ? 1 : 0); }

/** Remplace la connexion MySQL par une base SQLite en mémoire (schéma minimal déduit des requêtes). */
function baseDeTest(): PDO {
    require_once RACINE . '/Connexion.php';
    require_once RACINE . '/modele.php';
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_BOTH);
    $pdo->exec("
      CREATE TABLE association(id INTEGER PRIMARY KEY AUTOINCREMENT, nom, image, statut);
      CREATE TABLE utilisateurs(id INTEGER PRIMARY KEY AUTOINCREMENT, login, pwd, nom, prenom, telephone, email, consentementRgpd);
      CREATE TABLE role(idUtilisateur INTEGER, idAssociation INTEGER, role);
      CREATE TABLE fournisseur(id INTEGER PRIMARY KEY AUTOINCREMENT, nom, email, ville, tel, idAssociation INTEGER);
      CREATE TABLE produit(id INTEGER PRIMARY KEY AUTOINCREMENT, nom, prix REAL, image);
      CREATE TABLE inventaire(id INTEGER PRIMARY KEY AUTOINCREMENT, idAssociation INTEGER, date);
      CREATE TABLE ligneInventaire(idInventaire INTEGER, idProduit INTEGER, stock INTEGER, stockInitial INTEGER, pertes INTEGER DEFAULT 0);
      CREATE TABLE demandeCreationAsso(idUtilisateur, idAsso, carteIdentitePDF, statutAssoPDF, procesVerbalPDF);
      CREATE TABLE produitsFournisseur(idProduit, idFournisseur);
      CREATE TABLE boutique(idAssociation, idProduit);
      CREATE TABLE historiqueRestock(id INTEGER PRIMARY KEY AUTOINCREMENT, idGestionnaire, idProduit, quantite, idAssociation, idFournisseur, date);
      CREATE TABLE solde(idUtilisateur INTEGER, idAssociation INTEGER, solde REAL);
      CREATE TABLE commande(id INTEGER, idUtilisateur INTEGER, date, statut, idAssociation INTEGER, code, idBarman INTEGER);
      CREATE TABLE ligneCommande(idCommande INTEGER, idProduit INTEGER, quantite INTEGER, date);
    ");
    $p = new ReflectionProperty('Connexion', 'bdd');
    $p->setAccessible(true);
    $p->setValue(null, $pdo);
    return $pdo;
}

function silence(callable $f) { ob_start(); try { $f(); } finally { ob_end_clean(); } }
