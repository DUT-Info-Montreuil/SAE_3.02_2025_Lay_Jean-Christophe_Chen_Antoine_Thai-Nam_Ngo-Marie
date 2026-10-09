<?php
// UP-5 : les pièces légales ne doivent être lisibles que par l'Admin, via l'action voirPieceLegale.
// Aucun vrai PDF n'est lu : le test crée de faux PDF dans un dossier temporaire (variable ICONNECT_DOCS_LEGAUX).
// Usage : php tests/securite/test_up5_pieces_legales.php  -> code de sortie 1 si la faille est PRÉSENTE.
require __DIR__ . '/lib.php';

const TYPES_PIECES = ['carteIdentite', 'statutAsso', 'procesVerbal'];

if (PHP_SAPI === 'cli-server') { amorcer('Admin', $_GET['asso'] ?? '39', $_GET['type'] ?? 'procesVerbal'); new ModAdmin(); return; }   // routeur du volet D
if (($argv[1] ?? '') === '--enfant') { enfant($argv); exit(0); }

/** Prépare la base SQLite, la session simulée et la route index.php?module=admin&action=voirPieceLegale. */
function amorcer(string $role, string $asso, string $type): void {
    $pdo = baseDeTest();
    // colonnes typées comme en MySQL (la table de lib.php est sans type : SQLite ne compare pas 39 et '39')
    $pdo->exec('DROP TABLE demandeCreationAsso');
    $pdo->exec('CREATE TABLE demandeCreationAsso(idUtilisateur INTEGER, idAsso INTEGER, carteIdentitePDF, statutAssoPDF, procesVerbalPDF)');
    $pdo->exec("INSERT INTO demandeCreationAsso VALUES (10, 39, 'x', 'x', 'x')");
    include_once RACINE . '/token.php';
    include_once RACINE . '/vue_generique.php';
    include_once RACINE . '/modules/mod_admin/mod_admin.php';
    $_SESSION = $role === '-' ? [] : ['login' => 'u', 'id' => 1, 'role' => $role, 'asso' => 1];
    $_GET = ['module' => 'admin', 'action' => 'voirPieceLegale', 'asso' => $asso, 'type' => $type];
}

/** Processus enfant (CLI) : exécute la route avec une session simulée et relève le code HTTP. */
function enfant(array $a): void {
    [, , $role, $asso, $type, $meta] = $a;
    amorcer($role, $asso, $type);
    register_shutdown_function(function () use ($meta) {
        file_put_contents($meta, json_encode(['code' => http_response_code(), 'entetes' => headers_list()]));
    });
    new ModAdmin();   // exec() est appelé dans le constructeur, comme dans index.php
}

/** Lance l'enfant ; retourne ['code' => int|false, 'entetes' => [...], 'corps' => string]. */
function lancer(string $role, string $asso, string $type, string $dossierDocs): array {
    $meta = tempnam(sys_get_temp_dir(), 'up5m');
    $env = ['ICONNECT_DOCS_LEGAUX' => $dossierDocs] + getenv();
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', __FILE__, '--enfant', $role, $asso, $type, $meta],
                   [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
    $corps = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($p);
    $m = json_decode((string)file_get_contents($meta), true) ?: ['code' => false, 'entetes' => []];
    unlink($meta);
    return ['code' => $m['code'], 'entetes' => $m['entetes'], 'corps' => $corps];
}

function estPdf(array $r): bool { return strncmp($r['corps'], '%PDF', 4) === 0; }
function suppr(string $d): void {
    foreach (glob("$d/*") ?: [] as $f) { is_dir($f) ? suppr($f) : unlink($f); }
    @rmdir($d);
}

// ---- Jeu d'essai : faux PDF dans un dossier temporaire privé
$tmp = sys_get_temp_dir() . '/up5_' . getmypid();
$prive = "$tmp/prive";
mkdir($prive, 0777, true);
register_shutdown_function(fn() => suppr($tmp));
foreach (TYPES_PIECES as $t) {
    if ($t !== 'statutAsso') { file_put_contents("$prive/{$t}_39.pdf", "%PDF-1.4 FAUX {$t}\n"); }   // statutAsso_39 volontairement absent
}

// ---- Volet A : URL directe /documentsLegaux/... sans session (serveur PHP intégré, HEAD uniquement)
$noms = array_map('basename', array_filter(explode("\n", (string)shell_exec('cd ' . escapeshellarg(RACINE) . ' && git ls-files documentsLegaux'))));
$noms = array_values(array_unique(array_filter(array_merge($noms, ['carteIdentite_39.pdf', 'statutAsso_39.pdf', 'procesVerbal_39.pdf']),
                                               fn($n) => substr($n, -4) === '.pdf')));
$port = 18000 + getmypid() % 1000;
$serveur = proc_open(['php', '-S', "127.0.0.1:$port", '-t', RACINE], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
$ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 2]]);
$pret = false;
for ($i = 0; $i < 25 && !$pret; $i++) { usleep(150000); $pret = (bool)@get_headers("http://127.0.0.1:$port/", false, $ctx); }
if (!$pret) {
    verdict('UP-5a', 'URL directe : serveur de test non démarré, volet non vérifié', true, 'php -S injoignable');
} else {
    $servis = [];
    foreach ($noms as $n) {
        $h = @get_headers("http://127.0.0.1:$port/documentsLegaux/$n", false, $ctx);
        if ($h && strpos($h[0], '200') !== false) { $servis[] = $n; }
    }
    verdict('UP-5a', 'URL directe /documentsLegaux/<nom>.pdf servie sans session (' . count($noms) . ' noms testés)',
            $servis !== [], $servis ? count($servis) . ' servi(s) en HTTP 200, ex. ' . $servis[0] : 'aucun servi');
}
proc_terminate($serveur);

// ---- Volet B : sans session, via la route (index.php ne route pas un visiteur anonyme vers ModAdmin : voir PR)
$r = lancer('-', '39', 'procesVerbal', $prive);
verdict('UP-5b', 'sans session : 401 attendu, aucun PDF', $r['code'] !== 401 || estPdf($r), 'code=' . var_export($r['code'], true));

// ---- Volet C : connecté sans le bon rôle
foreach (['Client', 'Barman', 'Gestionnaire'] as $role) {
    $r = lancer($role, '39', 'procesVerbal', $prive);
    verdict('UP-5c', "rôle $role : 403 attendu, aucun PDF", $r['code'] !== 403 || estPdf($r), 'code=' . var_export($r['code'], true));
}

// ---- Volet D : Admin, cas positif en HTTP réel (ce fichier sert de routeur à php -S) ; empêche le 404 du volet A de passer trivialement
$portD = $port + 1;
$srvD = proc_open(['php', '-S', "127.0.0.1:$portD", __FILE__], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pd, null, ['ICONNECT_DOCS_LEGAUX' => $prive] + getenv());
$url = "http://127.0.0.1:$portD/index.php?module=admin&action=voirPieceLegale&asso=39&type=procesVerbal";
$ctxG = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
$corps = false;
for ($i = 0; $i < 25 && $corps === false; $i++) { usleep(150000); $corps = @file_get_contents($url, false, $ctxG); }
$ent = $http_response_header ?? [];
proc_terminate($srvD);
$entTxt = implode("\n", $ent);
verdict('UP-5d', 'Admin : HTTP 200 + application/pdf + nosniff + %PDF via voirPieceLegale',
        !($corps !== false && strncmp($corps, '%PDF', 4) === 0 && strpos($ent[0] ?? '', '200') !== false
          && stripos($entTxt, 'Content-Type: application/pdf') !== false && stripos($entTxt, 'X-Content-Type-Options: nosniff') !== false),
        ($ent[0] ?? 'aucune réponse') . ' debut=' . json_encode($corps === false ? '' : substr($corps, 0, 12)));

// ---- Volet E : Admin, entrées piégées ou fichier absent
$cas = [['39', '../../Connexion'], ['39/../1', 'procesVerbal'], ['abc', 'procesVerbal'], ['40', 'procesVerbal'], ['39', 'statutAsso']];
foreach ($cas as [$asso, $type]) {
    $r = lancer('Admin', $asso, $type, $prive);
    verdict('UP-5e', "Admin, asso=$asso type=$type : 400/404, aucun contenu", !in_array($r['code'], [400, 404], true) || estPdf($r) || strpos($r['corps'], 'class Connexion') !== false,
            'code=' . var_export($r['code'], true));
}

// ---- Volet F : dossier d'écriture hors racine web, constante unique
$env = getenv(); unset($env['ICONNECT_DOCS_LEGAUX']);
$cmd = [PHP_BINARY, '-r', 'require "Connexion.php"; require "modele.php"; echo defined("DOCUMENTS_LEGAUX_DIR") ? DOCUMENTS_LEGAUX_DIR : "";'];
$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, RACINE, $env);
$dir = trim((string)stream_get_contents($pipes[1])); fclose($pipes[1]); proc_close($p);
verdict('UP-5f', 'DOCUMENTS_LEGAUX_DIR défini par défaut hors de la racine web', $dir === '' || strpos($dir . '/', RACINE . '/') === 0, "valeur=" . ($dir === '' ? '(non définie)' : $dir));
verdict('UP-5g', "cont_asso.php écrit via DOCUMENTS_LEGAUX_DIR, plus dans 'documentsLegaux/' (analyse statique)",
        strpos(src('modules/mod_asso/cont_asso.php'), 'DOCUMENTS_LEGAUX_DIR') === false || strpos(src('modules/mod_asso/cont_asso.php'), "'documentsLegaux/") !== false);
verdict('UP-5h', "vue_admin.php ne lie plus les chemins bruts de la base (analyse statique)",
        strpos(src('modules/mod_admin/vue_admin.php'), "['carteIdentitePDF']") !== false);
fin();
