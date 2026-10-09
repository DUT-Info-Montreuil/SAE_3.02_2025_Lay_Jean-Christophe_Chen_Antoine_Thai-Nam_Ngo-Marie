<?php
require __DIR__ . '/lib.php';

$prod = src('modules/mod_produit/cont_produit.php');
$asso = src('modules/mod_asso/cont_asso.php');

// UP-1 : traversée de répertoire via $_GET['id'] dans le chemin d'écriture (cont_produit.php:93,108-109)
$idBrut = (bool)preg_match('/\$idProduit\s*=\s*\$_GET\[\'id\'\];/', $prod) && !preg_match('/\(int\)\s*\$_GET\[\'id\'\]|intval\(\s*\$_GET\[\'id\'\]/', $prod);
preg_match("/\\\$cheminNouveauFichier\s*=\s*('[^']+')\s*\.\s*\\\$idProduit\s*\.\s*'\.'\s*\.\s*\\\$extension;/", $prod, $m);
$idTest   = '../../../tests/securite/_canari';              // dossier inoffensif du dépôt, rien n'est écrit
$chemin   = $m ? trim($m[1], "'") . $idTest . '.jpg' : '';
$resolu   = $chemin ? realpath(RACINE . '/' . dirname($chemin)) : false;
$dossierUp = realpath(RACINE . '/modules/mod_produit/img_produits');
$sortie   = $resolu && strpos($resolu . '/', $dossierUp . '/') !== 0;
verdict('UP-1', "chemin d'écriture construit avec \$_GET['id'] brut : le fichier peut atterrir hors de img_produits/", $idBrut && $sortie,
        $resolu ? "id=$idTest  =>  dossier cible résolu : " . str_replace(RACINE, '<racine>', $resolu) : 'motif non trouvé');

// UP-2 : validation par extension du nom client uniquement
$ext = substr_count($prod . $asso, 'pathinfo(');
$mime = preg_match('/finfo_|mime_content_type|getimagesize|is_uploaded_file|\[\'size\'\]|UPLOAD_ERR_OK/', $prod . $asso);
verdict('UP-2', "aucun contrôle MIME, taille, UPLOAD_ERR_OK ni is_uploaded_file ($ext validations par pathinfo)", $ext > 0 && !$mime);

// UP-3 : retour de move_uploaded_file ignoré
verdict('UP-3', 'retour de move_uploaded_file() jamais vérifié (le succès est annoncé même si l\'écriture échoue)',
        preg_match_all('/^\s*move_uploaded_file\(/m', $prod . $asso) >= 4);

// UP-4 : l'association est insérée AVANT validation, puis retrouvée PAR NOM (cont_asso.php:167-168,196)
verdict('UP-4a', "id du fichier obtenu par nom (idAsso(\$nom)) : écrase les pièces d'une asso homonyme (cont_asso.php:168,184-190)",
        (bool)preg_match('/insertAssociation\(\$nomAssociation\);\s*\$nomFichier\s*=\s*\$this->modele->idAsso\(\$nomAssociation\)/s', $asso));
$pdo = baseDeTest();
$mod = new ModeleAsso_Shim();
function ModeleAssoCharge() { require_once RACINE.'/modules/mod_asso/modele_asso.php'; return new ModeleAsso(); }
class ModeleAsso_Shim {}
$ma = ModeleAssoCharge();
$pdo->exec("INSERT INTO association(id,nom,image,statut) VALUES (39,'Buvette','x','valide')");   // asso existante
$ma->insertAssociation('Buvette');                                                               // « nouvelle » demande homonyme
$idUtilise = (int)$ma->idAsso('Buvette');
verdict('UP-4b', "idAsso('Buvette') renvoie l'id EXISTANT ($idUtilise) et non celui de la nouvelle demande", $idUtilise === 39,
        "dépend de l'absence de contrainte UNIQUE sur association.nom (schéma non versionné : à vérifier)");
$ma->deleteAsso($idUtilise);
verdict('UP-4c', "le nettoyage en cas d'échec (deleteAsso) supprime l'asso existante", (int)$pdo->query("SELECT COUNT(*) FROM association WHERE id=39")->fetchColumn() === 0);

// UP-5 : exposition directe des pièces légales par le serveur web (vérifié en HTTP local, HEAD uniquement)
$port = 18000 + getmypid() % 1000;
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', RACINE], [1=>['file','/dev/null','w'], 2=>['file','/dev/null','w']], $p);
$code = null;
for ($i = 0; $i < 20 && $code === null; $i++) {
    usleep(150000);
    $h = @get_headers("http://127.0.0.1:$port/documentsLegaux/procesVerbal_39.pdf", false, stream_context_create(['http'=>['method'=>'HEAD','timeout'=>2]]));
    if ($h) $code = $h[0];
}
proc_terminate($proc);
verdict('UP-5', 'documentsLegaux/procesVerbal_39.pdf servi sans authentification (serveur PHP intégré)', $code !== null && strpos($code, '200') !== false, (string)$code);
fin();
