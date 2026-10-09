<?php
// CA-1 : changement d'association en gardant son rôle (modules/mod_asso/cont_asso.php, aAppuyeAsso).
// Chaque scénario tourne dans un sous-processus : aAppuyeAsso() appelle header() puis exit().
// Usage : php tests/securite/test_ca1_changement_asso.php  -> code de sortie 1 si la faille est PRÉSENTE.
require __DIR__ . '/lib.php';

/** Scénarios : [titre, session de départ, $_GET, vrai si la faille est présente d'après la session finale]. */
function scenarios(): array {
    $gestA = ['role' => 'Gestionnaire', 'asso' => 1];
    return [
        1 => ["gestionnaire de A sans rôle dans B (asso 2)", ['id' => 10] + $gestA, ['id' => '2'],
              fn($s) => isset($s['role']) || isset($s['asso'])],
        2 => ["gestionnaire de A vers une association inexistante (asso 999)", ['id' => 10] + $gestA, ['id' => '999'],
              fn($s) => isset($s['role']) || isset($s['asso'])],
        3 => ["gestionnaire de A, 2 rôles dans B, choix de rôle pas encore fait", ['id' => 30] + $gestA, ['id' => '2'],
              fn($s) => isset($s['role']) || isset($s['asso'])],
        4 => ["gestionnaire de A, Client dans B, force &role=Gestionnaire", ['id' => 50] + $gestA, ['id' => '2', 'role' => 'Gestionnaire'],
              fn($s) => isset($s['role']) || isset($s['asso'])],
        7 => ["régression : Client de B change d'asso, doit obtenir Client", ['id' => 20] + $gestA, ['id' => '2'],
              fn($s) => ($s['role'] ?? null) !== 'Client' || ($s['asso'] ?? null) != 2],
        8 => ["régression : 2 rôles dans B, choix explicite Barman", ['id' => 30] + $gestA, ['id' => '2', 'role' => 'Barman'],
              fn($s) => ($s['role'] ?? null) !== 'Barman' || ($s['asso'] ?? null) != 2],
    ];
}

// ---- Mode enfant : exécute un scénario et affiche la session finale en JSON
if (($argv[1] ?? '') === 'enfant') {
    [, , $n] = $argv;
    $sc = scenarios()[(int)$n];
    $pdo = baseDeTest();
    $pdo->exec("INSERT INTO association(nom,image,statut) VALUES ('A','x','valide'),('B','x','valide')");
    $pdo->exec("INSERT INTO role VALUES (10,1,'Gestionnaire'),(50,1,'Gestionnaire'),(50,2,'Client'),(20,2,'Client'),(30,2,'Client'),(30,2,'Barman')");
    include_once RACINE . '/modules/mod_asso/cont_asso.php';
    $_SESSION = ['login' => 'u' . $sc[1]['id']] + $sc[1];
    $_GET = $sc[2];
    register_shutdown_function(function () {
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo "SESSION=" . json_encode($_SESSION) . "\n";
    });
    ob_start();
    (new ContAsso())->aAppuyeAsso();
    exit();
}

// ---- Mode principal
foreach (scenarios() as $n => $sc) {
    $sortie = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg(__FILE__) . " enfant $n 2>&1");
    preg_match('/SESSION=(.*)/', (string)$sortie, $m);
    $session = isset($m[1]) ? json_decode($m[1], true) : null;
    if (!is_array($session)) { verdict("CA-1/$n", $sc[0], true, "sortie inexploitable : " . trim((string)$sortie)); continue; }
    $titre = $sc[0] . ' -> role=' . ($session['role'] ?? '(absent)') . ', asso=' . ($session['asso'] ?? '(absent)');
    verdict("CA-1/$n", $titre, ($sc[3])($session));
}
fin();
