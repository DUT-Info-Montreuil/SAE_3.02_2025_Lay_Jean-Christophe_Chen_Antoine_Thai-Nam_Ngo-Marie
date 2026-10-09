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
$pdo->exec("UPDATE produit SET prix=5 WHERE id=500");
$d = '2026-01-01 10:00:00';
// Une commande de 15 € (3 x 5 €) par scénario ; $asso = association de la commande, $statut = statut initial
$cmd = function($id, $client, $statut, $asso = 1, $lignes = true) use ($pdo, $d) {
    $pdo->exec("INSERT INTO solde VALUES ($client,$asso,0)");
    $pdo->exec("INSERT INTO commande VALUES ($id,$client,'$d','$statut',$asso,'abc',NULL)");
    if ($lignes) $pdo->exec("INSERT INTO ligneCommande VALUES ($id,500,3,'$d')");
};
$solde  = function($client, $asso = 1) use ($pdo) { return (float)$pdo->query("SELECT solde FROM solde WHERE idUtilisateur=$client AND idAssociation=$asso")->fetchColumn(); };
$statut = function($id) use ($pdo) { return $pdo->query("SELECT statut FROM commande WHERE id=$id")->fetchColumn(); };
$stock  = function() use ($pdo) { return (int)$pdo->query("SELECT stock FROM ligneInventaire WHERE idInventaire=1 AND idProduit=500")->fetchColumn(); };
// Le contrôleur finit par header()+exit() : on appelle donc la méthode du modèle que refuser() utilise (une assertion sur le source vérifie ce lien).
$m = new ModeleCommande();
$refuser = function($id) use ($m, $d) {
    $_SESSION = ['login'=>'barmanA','id'=>30,'role'=>'Barman','asso'=>1];
    return $m->refuserEtRembourser($id, $d);
};
$valider = function($id) use ($m, $d) {
    $_SESSION = ['login'=>'barmanA','id'=>30,'role'=>'Barman','asso'=>1];
    $m->valideCommande($id, $d);
};

// A : commande « livrée », 3 appels => aucun crédit
$cmd(1, 20, 'livrée');
for ($i = 0; $i < 3; $i++) $refuser(1);
verdict('CA-4-A', "commande « livrée » refusée 3 fois : solde = ".$solde(20).' € (attendu 0), statut « '.$statut(1).' » (attendu « livrée »)',
        $solde(20) != 0 || $statut(1) !== 'livrée', "15 € d'achat ; cont_commande.php:92-111 ne testait jamais le statut");
// B : commande « Encours », 3 appels => remboursée une seule fois, restock jamais répété (le restock est inopérant pour une autre raison : derouleCommande() ne renvoie pas idProduit)
$cmd(2, 21, 'Encours');
$r = [$refuser(2), $refuser(2), $refuser(2)];
verdict('CA-4-B', "commande « Encours » refusée 3 fois : solde = ".$solde(21).' € (attendu 15), stock = '.$stock().' (attendu <= 3 : restock au plus une fois), statut « '.$statut(2).' »',
        $solde(21) != 15 || $stock() > 3 || $statut(2) !== 'rembourser' || $r !== [true, false, false]);
// C : commande déjà remboursée => aucun nouveau crédit
$cmd(3, 22, 'rembourser');
$refuser(3);
verdict('CA-4-C', "commande déjà « rembourser » refusée à nouveau : solde = ".$solde(22).' € (attendu 0)', $solde(22) != 0);
// D : commande d'une autre association => refusée, rien ne bouge
$cmd(4, 23, 'Encours', 2);
$refuser(4);
verdict('CA-4-D', "commande « Encours » de l'asso B refusée par un barman de A : solde = ".$solde(23, 2).' € (attendu 0), statut « '.$statut(4).' » (attendu « Encours »)',
        $solde(23, 2) != 0 || $statut(4) !== 'Encours');
// F : commande sans ligne (prixTotal NULL) => remboursement refusé, solde intact (jamais NULL)
$cmd(5, 24, 'Encours', 1, false);
$pdo->exec("UPDATE solde SET solde=10 WHERE idUtilisateur=24");
$refuser(5);
$s24 = $pdo->query("SELECT solde FROM solde WHERE idUtilisateur=24")->fetchColumn();
verdict('CA-4-F', "commande sans ligne refusée : solde = ".var_export($s24, true).' (attendu 10), statut « '.$statut(5).' » (attendu « Encours »)',
        (float)$s24 != 10 || $s24 === null || $statut(5) !== 'Encours');
// E : refus puis validation, et validation puis refus => le premier état final l'emporte
$cmd(6, 25, 'Encours');
$refuser(6); $valider(6);
verdict('CA-4-E1', "commande refusée puis validée : statut « ".$statut(6).' » (attendu « rembourser »)', $statut(6) !== 'rembourser');
$cmd(7, 26, 'Encours');
$valider(7); $refuser(7);
verdict('CA-4-E2', "commande validée puis refusée : statut « ".$statut(7).' » (attendu « livrée »), solde = '.$solde(26).' € (attendu 0)',
        $statut(7) !== 'livrée' || $solde(26) != 0);
// Lien contrôleur -> modèle : refuser() doit passer par la méthode transactionnelle, plus par rembourser()/refuser() séparés
$cont = src('modules/mod_commande/cont_commande.php');
verdict('CA-4-L', "ContCommande::refuser() appelle refuserEtRembourser() et plus rembourser()/refuser() séparément",
        !preg_match('/function refuser\(\).*?refuserEtRembourser\(/s', $cont) || preg_match('/modele->rembourser\(|modele->refuser\(/', $cont));
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
