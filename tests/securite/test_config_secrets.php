<?php
require __DIR__ . '/lib.php';

// SEC-1 : identifiants BDD en dur dans un fichier versionné (le secret n'est jamais affiché)
$c = src('Connexion.php');
$enDur = (bool)preg_match('/new PDO\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"][^\'"]+[\'"]/', $c);
$versionne = trim((string)shell_exec('cd ' . escapeshellarg(RACINE) . ' && git ls-files --error-unmatch Connexion.php 2>/dev/null')) !== '';
verdict('SEC-1', 'Connexion.php:10 mot de passe BDD en clair dans un fichier suivi par git', $enDur && $versionne, '(valeur masquée volontairement)');

// SEC-2 : pièces légales (données personnelles) versionnées
$n = count(array_filter(explode("\n", (string)shell_exec('cd ' . escapeshellarg(RACINE) . ' && git ls-files documentsLegaux'))));
verdict('SEC-2', 'documentsLegaux/ : fichiers versionnés dans git', $n > 0, "$n fichier(s)");

// SEC-3 : aucun verrou d'accès sur les dossiers d'upload
$verrou = file_exists(RACINE.'/.htaccess') || file_exists(RACINE.'/documentsLegaux/.htaccess');
verdict('SEC-3', "pas de .htaccess / règle d'accès sur documentsLegaux/", !$verrou);

// SEC-4 : code de débogage laissé en production
$dbg = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE.'/modules')) as $f) {
    if (substr($f, -4) !== '.php') continue;
    foreach (file($f) as $i => $l) if (preg_match('/^\s*var_dump\(/', $l)) $dbg[] = str_replace(RACINE.'/', '', $f).':'.($i+1);
}
verdict('SEC-4', 'var_dump() actifs', count($dbg) > 0, implode(', ', $dbg));

// SEC-5 : fuite du mot de passe BDD par la trace d'exception (conditions : display_errors=1 et zend.exception_ignore_args=0, réglages de php.ini-development / XAMPP)
$tmp = sys_get_temp_dir().'/conn_test_'.getmypid().'.php';
$copie = preg_replace('/host=[^\'"]+/', 'host=127.0.0.1;port=1', $c);   // aucune connexion externe
file_put_contents($tmp, $copie . "\nConnexion::initConnexion();\n");
preg_match('/new PDO\([^,]+,[^,]+,\s*[\'"]([^\'"]+)[\'"]/', $c, $m);
$sortie = shell_exec('php -d display_errors=1 -d zend.exception_ignore_args=0 ' . escapeshellarg($tmp) . ' 2>&1');
unlink($tmp);
verdict('SEC-5', 'PDOException non interceptée : le mot de passe apparaît dans la trace (PHP < 8.2 seulement ; masqué par #[SensitiveParameter] depuis 8.2)', isset($m[1]) && strpos((string)$sortie, $m[1]) !== false, '(connexion dirigée vers 127.0.0.1:1 ; PHP '.PHP_VERSION.' ; le nom d\'hôte et l\'utilisateur restent visibles dans les messages d\'erreur)');

// SEC-6 : pas d'en-têtes de sécurité, pas de réglage des cookies de session
$tout = src('index.php').src('template.php');
verdict('SEC-6a', "aucun en-tête de sécurité (CSP, X-Frame-Options, nosniff, HSTS)", !preg_match('/header\(\s*[\'"](Content-Security-Policy|X-Frame-Options|X-Content-Type-Options|Strict-Transport)/i', $tout));
verdict('SEC-6b', 'session_start() sans httponly/secure/samesite (index.php:5)', !preg_match('/session_set_cookie_params|cookie_httponly|cookie_samesite/', $tout));
verdict('SEC-6c', 'Bootstrap Icons chargé depuis un CDN sans attribut integrity (template.php:8)', (bool)preg_match('/<link[^>]+bootstrap-icons[^>]+>/', src('template.php'), $mm) && strpos($mm[0], 'integrity') === false);
fin();
