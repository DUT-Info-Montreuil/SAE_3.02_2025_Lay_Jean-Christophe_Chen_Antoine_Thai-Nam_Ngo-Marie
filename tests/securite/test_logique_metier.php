<?php
require __DIR__ . '/lib.php';
session_start();
$pdo = baseDeTest();
include_once RACINE.'/token.php';
include_once RACINE.'/vue_generique.php';

// LM-1 : validation numérique du rechargement (cont_compte.php:37)
verdict('LM-1', "recharger : \"abc\" > 0 est vrai en PHP 8, aucune validation numérique ni plafond", ("abc" > 0) === true && !preg_match('/is_numeric|filter_var|FILTER_VALIDATE/', src('modules/mod_compte/cont_compte.php')));
verdict('LM-2', "recharger : le client se crédite lui-même sans aucun justificatif de paiement (conception à confirmer avec l'équipe)",
        (bool)preg_match('/updateClientSolde\(\$idClient, \$idAsso, \$montant\)/', src('modules/mod_compte/cont_compte.php')));
verdict('LM-3', 'prix / quantité / perte sans validation (cont_produit.php:52,95,147 ; cont_stock.php:58,170)',
        !preg_match('/is_numeric|filter_var|FILTER_VALIDATE|\(float\)|\(int\)|intval/', src('modules/mod_produit/cont_produit.php').src('modules/mod_stock/cont_stock.php')));

// LM-4 : pertes négatives => le stock augmente (contrôleur exécuté, base SQLite)
include_once RACINE.'/modules/mod_stock/cont_stock.php';
$pdo->exec("INSERT INTO inventaire(id,idAssociation,date) VALUES (1,1,'2026-01-01')");
$pdo->exec("INSERT INTO ligneInventaire VALUES (1,7,5,5,0)");
$_SESSION = ['role'=>'Gestionnaire','asso'=>1,'login'=>'g','id'=>1,'tokenCSRF'=>'t'];
$_POST = ['tokenCSRF'=>'t','perte'=>'-100']; $_GET = ['id'=>'7'];
try { silence(fn() => (new ContStock())->ajouterPertes()); } catch (Throwable $e) {}
$stock = (int)$pdo->query("SELECT stock FROM ligneInventaire WHERE idProduit=7")->fetchColumn();
verdict('LM-4', "ajouterPertes(-100) : stock passé de 5 à $stock", $stock > 5);

// LM-5 : code de retrait NULL + code vide => accepté (comparaison lâche, modele_commande.php:141)
include_once RACINE.'/modules/mod_commande/modele_commande.php';
$pdo->exec("INSERT INTO commande(id,idUtilisateur,date,statut,idAssociation,code) VALUES (9,1,'2026-01-01 10:00:00','Encours',1,NULL)");
verdict('LM-5', "verifCode('') accepté quand le code en base est NULL (== au lieu de ===)", (new ModeleCommande())->verifCode('', 9, '2026-01-01 10:00:00') === true);

// LM-6 : aucune transaction => course critique sur solde/stock (cont_panier.php:79-135)
$tout = '';
foreach (['modules/mod_panier/cont_panier.php', 'modules/mod_panier/modele_panier.php'] as $f) $tout .= src($f);
verdict('LM-6', 'aucune transaction SQL (beginTransaction) : double validation simultanée du panier possible', strpos($tout, 'beginTransaction') === false);
verdict('LM-7', "getCode() : le code de retrait est tiré, affiché par var_dump puis un AUTRE code est retourné (modele_panier.php:112-116)", (bool)preg_match('/var_dump\(bin2hex\(random_bytes\(5\)\)\);\s*return bin2hex\(random_bytes\(5\)\)/', src('modules/mod_panier/modele_panier.php')));
fin();
