<?php
// Usage : php tests/securite/test_schema_bdd.php chemin/vers/dump.sql
// Lit UNIQUEMENT la structure (CREATE/ALTER) et quelques comptages. N'affiche aucune donnée personnelle.
require __DIR__ . '/lib.php';
$f = $argv[1] ?? getenv('DUMP_SQL');
if (!$f || !is_readable($f)) { echo "Passez le dump SQL en argument : php tests/securite/test_schema_bdd.php dump.sql\n"; exit(2); }
$sql = file_get_contents($f);
$alter = function (string $table) use ($sql) {
    preg_match_all('/ALTER TABLE `' . $table . '`\s+(.*?);/s', $sql, $m);
    return implode(' ', $m[1]);
};
verdict('SCH-1', 'aucune clé étrangère : suppression sans cascade, lignes orphelines (deleteAsso, deleteUtilisateur)', stripos($sql, 'FOREIGN KEY') === false);
verdict('SCH-2', 'utilisateurs.login non unique : comptes en double possibles (course entre verifLoginExiste et INSERT)', !preg_match('/UNIQUE[^;]*`login`/', $alter('utilisateurs')));
verdict('SCH-3', "association.nom non unique : idAsso(\$nom) ambigu (aggrave UP-4)", !preg_match('/UNIQUE[^;]*`nom`/', $alter('association')));
verdict('SCH-4', 'table role sans clé primaire ni unicité (doublons de rôles / demandes)', $alter('role') === '');
preg_match('/CREATE TABLE `commande`.*?\) ENGINE/s', $sql, $c);
verdict('SCH-5', 'commande.code nullable (aggrave LM-5)', isset($c[0]) && preg_match('/`code`[^,]*DEFAULT NULL/', $c[0]));
preg_match('/INSERT INTO `commande`.*?;\n/s', $sql, $d);
$lignes = array_filter(explode("\n", $d[0] ?? ''), fn($l) => strlen($l) && $l[0] === '(');
$nulls  = count(array_filter($lignes, fn($l) => preg_match('/, NULL\)[,;]$/', $l)));
$enCours = count(array_filter($lignes, fn($l) => preg_match('/, NULL\)[,;]$/', $l) && strpos($l, 'Encours') !== false));
verdict('SCH-6', "commandes sans code de retrait dans les données : $nulls / " . count($lignes) . " dont $enCours encore « Encours » (validables sans code)", $enCours > 0);
verdict('SCH-7', 'tables en latin1 alors que la connexion PDO ne fixe aucun charset', substr_count($sql, 'DEFAULT CHARSET=latin1') > 0 && !preg_grep('/charset/i', preg_grep('#^\\s*//#', explode("\n", src('Connexion.php')), PREG_GREP_INVERT)));
preg_match_all("/\((\d+), (\d+), 'Admin'\)/", $sql, $a);
verdict('SCH-8', "le rôle Admin est une ligne de la table role rattachée à une association (asso " . implode(',', $a[2]) . ") : supprimable par un gestionnaire de cette asso (CA-6)", count($a[0]) > 0);
verdict('SCH-9', "montants (solde, prix) en decimal(10,0) : aucune décimale, les centimes sont arrondis", (bool)preg_match('/`prix` decimal\(10,0\)/', $sql) && (bool)preg_match('/`solde` decimal\(10,0\)/', $sql));
fin();
