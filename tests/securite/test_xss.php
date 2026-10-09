<?php
require __DIR__ . '/lib.php';
session_start();
include_once RACINE . '/Connexion.php';          // définit la classe, n'ouvre PAS de connexion
include_once RACINE . '/token.php';
include_once RACINE . '/vue_generique.php';
include_once RACINE . '/modules/mod_admin/vue_admin.php';
include_once RACINE . '/modules/mod_commande/vue_commande.php';

$marqueur = '<i id="marqueur-xss">x</i>';   // balise inoffensive : si elle ressort brute, l'échappement manque
$_SESSION['nomAsso'] = 'X';

// XSS-1 : liste des demandes d'inscription (vue gestionnaire)
$v = new VueAdmin();
$v->afficherListeDemandeUtilisateur([[ 'id'=>1,'login'=>$marqueur,'nom'=>$marqueur,'prenom'=>$marqueur,'telephone'=>$marqueur ]]);
$html = $v->getAffichage();
verdict('XSS-1', 'vue_admin.php:156-159 (login/nom/prénom/tél non échappés)', strpos($html, $marqueur) !== false);

// XSS-2 : modale profil client (vue barman)
$v = new VueCommande();
$v->afficherProfilModal('client', ['login'=>$marqueur,'email'=>$marqueur,'solde'=>1]);
$html = $v->getAffichage();
verdict('XSS-2', 'vue_commande.php:118-119 (login/email non échappés)', strpos($html, $marqueur) !== false);

// XSS-3 : messages de session (vue_generique.php:57,68)
$_SESSION['messageOk'] = $marqueur; $_SESSION['messagePasOk'] = $marqueur;
$v = new VueGenerique(); $v->confirmationProgressBar();
$html = $v->getAffichage();
verdict('XSS-3', 'vue_generique.php:57,68 (messageOk / messagePasOk non échappés)', substr_count($html, $marqueur) === 2);

// XSS-4 : nom d'association de la session, affiché dans presque toutes les vues
$_SESSION['nomAsso'] = $marqueur; $_SESSION['role'] = 'Gestionnaire';
$v = new VueAdmin(); $v->afficherTabGestionComptes([]);
$html = $v->getAffichage();
verdict('XSS-4', 'vue_admin.php:71 et ~12 autres vues ($_SESSION[nomAsso] non échappé)', strpos($html, $marqueur) !== false);

// XSS-5 : sources des messages
$ban = src('modules/mod_admin/cont_admin.php');
verdict('XSS-5', "cont_admin.php:72 (login concaténé dans messageOk)", (bool)preg_match("/messageOk'\]\s*=\s*'Vous avez banni '\.\\\$loginUtilisateur/", $ban));
// XSS-6 : XSS réfléchi, id/date de $_GET injectés dans des attributs value="" (vue_commande.php:398-399)
$payload = '"><i id="marqueur-xss">';
$v = new VueCommande(); $v->confirmerRetrait('titre', $payload, $payload);
$html = $v->getAffichage();
verdict('XSS-6', 'vue_commande.php:398-399 : rupture d\'attribut avec $_GET[id] / $_GET[date] (lien GET, sans jeton)', substr_count($html, '"><i id="marqueur-xss">') >= 2);
fin();
