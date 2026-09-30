<?php
require __DIR__ . '/lib.php';
verdict('DEP-1', 'aucun composer.json / lockfile : impossible d\'auditer ou d\'épingler les dépendances PHP', !file_exists(RACINE.'/composer.json'));
verdict('DEP-2', "aucune version de PHP/MySQL déclarée (ni composer, ni .php-version, ni Dockerfile)", !file_exists(RACINE.'/composer.json') && !file_exists(RACINE.'/Dockerfile') && !file_exists(RACINE.'/.php-version'));
$t = src('template.php'); $sans = [];
preg_match_all('#<(link|script)[^>]+(https://[^"\']+)[^>]*>#', $t, $m, PREG_SET_ORDER);
foreach ($m as $x) if (strpos($x[0], 'integrity=') === false) $sans[] = $x[2];
verdict('DEP-3', 'ressources CDN sans SRI', count($sans) > 0, implode(', ', $sans));
preg_match_all('#bootstrap@([0-9.]+)#', $t, $v);
echo "            info   : Bootstrap " . implode(', ', array_unique($v[1])) . " (à comparer avec les avis de sécurité officiels ; aucun accès réseau ici)\n";
echo "            info   : PHP d'exécution " . PHP_VERSION . "\n";
fin();
