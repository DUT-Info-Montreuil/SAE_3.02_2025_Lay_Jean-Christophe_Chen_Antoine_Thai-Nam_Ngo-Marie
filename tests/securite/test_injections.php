<?php
require __DIR__ . '/lib.php';
// Balayage négatif : ce test doit sortir « OK » si aucune injection n'est possible.
$fichiers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE, FilesystemIterator::SKIP_DOTS)) as $f) {
    if (substr($f, -4) === '.php' && strpos($f, '/tests/') === false && strpos($f, '/.git/') === false) $fichiers[] = (string)$f;
}
$trouve = function (string $regex) use ($fichiers) {
    $hits = [];
    foreach ($fichiers as $f) foreach (file($f) as $i => $l) {
        if (preg_match('#^\s*(//|\*|/\*)#', $l)) continue;
        if (preg_match($regex, $l)) $hits[] = str_replace(RACINE.'/', '', $f).':'.($i+1);
    }
    return $hits;
};
$h = $trouve('/(?<![>:\w])(?<!function )(shell_exec|exec|system|passthru|popen|proc_open|pcntl_exec)\s*\(|`[^`]+`/');
verdict('INJ-CMD', 'exécution de commandes système', count($h) > 0, implode(', ', $h));
$h = $trouve('/\b(eval|assert|create_function|unserialize)\s*\(|preg_replace\(.*\/e/');
verdict('INJ-EVAL', 'eval / unserialize / assert', count($h) > 0, implode(', ', $h));
$h = $trouve('/(->query|->exec|->prepare|mysqli_query)\s*\(\s*[^)]*(\$_(GET|POST|REQUEST|COOKIE|SESSION)|"\s*\.\s*\$|\'\s*\.\s*\$|"[^"]*\$[a-zA-Z_])/');
verdict('INJ-SQL', "SQL construit par concaténation / interpolation avec une variable", count($h) > 0, implode(', ', $h));
$h = $trouve('/\b(include|require)(_once)?\s*\(?\s*[^\'"(;]*\$_(GET|POST|REQUEST|COOKIE)/');
verdict('INJ-LFI', 'include/require piloté par une entrée utilisateur', count($h) > 0, implode(', ', $h));
$h = $trouve('/\b(twig|smarty|blade|mustache|Environment\(|->render\(|createTemplate)/i');
verdict('INJ-SSTI', "moteur de templates (injection de template)", count($h) > 0, implode(', ', $h));
$h = $trouve('/\b(file_get_contents|fopen|readfile|curl_init)\s*\([^)]*\$_(GET|POST|REQUEST)/');
verdict('INJ-SSRF', 'lecture de fichier / URL pilotée par l\'utilisateur', count($h) > 0, implode(', ', $h));
$h = $trouve('/header\(\s*[\'"]Location:\s*[\'"]\s*\.\s*\$/i');
verdict('INJ-REDIR', 'redirection ouverte (Location construit avec une variable)', count($h) > 0, implode(', ', $h));
// Nuance : le SQL n'est pas injectable, mais la connexion n'impose ni charset ni EMULATE_PREPARES=false
verdict('INJ-SQL-CFG', 'PDO sans charset ni ATTR_EMULATE_PREPARES=false (durcissement absent, non exploitable en l\'état)',
        strpos(src('Connexion.php'), 'charset') === false && strpos(src('Connexion.php'), 'EMULATE_PREPARES') === false);
fin();
