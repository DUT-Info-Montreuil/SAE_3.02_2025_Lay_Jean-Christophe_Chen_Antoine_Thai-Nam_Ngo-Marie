<?php
require __DIR__ . '/lib.php';
session_start();
include_once RACINE.'/token.php';

$cc = src('Composants/mod_connexion/cont_connexion.php');
verdict('AUTH-1', "pas de session_regenerate_id après connexion (fixation de session, cont_connexion.php:78-80)", strpos($cc, 'session_regenerate_id') === false);
verdict('AUTH-2', 'déconnexion sans suppression du cookie de session (cont_connexion.php:92-98)', strpos($cc, 'setcookie') === false && strpos($cc, 'session_get_cookie_params') === false);
verdict('AUTH-3', 'aucune limitation de tentatives de connexion', !preg_match('/tentative|attempt|sleep\(|lock|captcha/i', $cc));
verdict('AUTH-4', "aucune règle de robustesse du mot de passe côté serveur (seul minlength HTML)", !preg_match('/strlen\(\s*\$pwd|preg_match.*pwd/', $cc));
verdict('AUTH-5', 'champ mot de passe en type="text" (vue_connexion.php:23,63)', substr_count(src('Composants/mod_connexion/vue_connexion.php'), 'name="pwd" class="form-control" placeholder="Mot de passe" type="text"') >= 1);
verdict('AUTH-6', "énumération de logins à l'inscription (cont_connexion.php:50)", strpos($cc, "existe déjà") !== false);
verdict('AUTH-7', "déconnexion déclenchée par GET sans jeton (déni de service par lien / <img>)", strpos(src('Composants/comp_navbar/modele_navbar.php'), 'index.php?actionComposant=deconnexion') !== false);

// AUTH-8 : comparaison lâche du jeton CSRF (token.php:19) — cas « magic hash »
$_SESSION['tokenCSRF'] = '0e462097431906509019562988736854';   // forme possible d'un jeton hex (probabilité faible mais réelle)
$accepte = Token::verifierToken('0');
verdict('AUTH-8', "Token::verifierToken('0') accepté pour un jeton de la forme 0e<chiffres> (opérateur != au lieu de hash_equals)", $accepte === true);

// CSRF-1 : action modifiant l'état en GET, sans jeton. exit() final du contrôleur => sous-processus.
if (($argv[1] ?? '') === 'ban') {
    $pdo = baseDeTest();
    include_once RACINE.'/vue_generique.php';
    include_once RACINE.'/modules/mod_admin/cont_admin.php';
    $pdo->exec("INSERT INTO utilisateurs(id,login) VALUES (20,'victime'),(10,'gest')");
    $pdo->exec("INSERT INTO role VALUES (20,1,'Client'),(10,1,'Gestionnaire')");
    $_SESSION = ['login'=>'gest','id'=>10,'role'=>'Gestionnaire','asso'=>1];
    $_GET = ['id'=>'20'];                       // simple GET, aucun tokenCSRF
    register_shutdown_function(function() use ($pdo) {
        ob_end_clean();
        echo (int)$pdo->query("SELECT COUNT(*) FROM role WHERE idUtilisateur=20")->fetchColumn();
    });
    ob_start();
    (new ContAdmin())->bannirUtilisateur();
    exit;
}
$reste = substr(trim((string)shell_exec('php -d display_errors=0 ' . escapeshellarg(__FILE__) . ' ban 2>/dev/null')), -1);
verdict('CSRF-1', 'bannirUtilisateur exécuté via GET sans jeton (un lien piégé suffit si la victime est gestionnaire)', $reste === '0', "rôles restants de la victime : $reste");
fin();
