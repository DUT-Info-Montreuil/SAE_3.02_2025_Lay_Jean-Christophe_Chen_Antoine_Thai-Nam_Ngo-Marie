<?php
include_once 'modele_admin.php';
include_once 'vue_admin.php';

class ContAdmin{
    private $modele;
    private $vue;

    public function __construct(){
        $this->modele = new ModeleAdmin();
        $this->vue = new VueAdmin();
    }

    public function listerAssociation(){
        if ($_SESSION['role'] == 'Admin'){
            $listeAssociations = $this->modele->getAssociations();
            $this->vue->afficherListeAssociations($listeAssociations);
        }
        unset($_SESSION['messageOk']);
        unset($_SESSION['messagePasOk']);
    }

    public function gestionCompte(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire'){
            $this->vue->afficherTabGestionComptes(
                $this->modele->getUtilisateurAsso($_SESSION['asso'])
            );
        }
        unset($_SESSION['messageOk']);
        unset($_SESSION['messagePasOk']);
    }

    /**
     * si le client n'a pas le role barman alors le gestiionnaire lui donne le role barman dans son asso
    */
    public function donnerRoleBarman(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire' && isset($_SESSION['asso']) && isset($_GET['id'])){
            $idUtilisateur = $_GET['id'];
            $idAssociation = $_SESSION['asso'];

            if (!$this->modele->dejaBarman($idUtilisateur, $idAssociation, "Barman")){
                $this->modele->insertRoleBarman($idUtilisateur, $idAssociation, "Barman");
                $_SESSION['messageOk'] = 'Promotion success';
            }else{
                $_SESSION['messagePasOk'] = 'Promotion fail, cette personne est déjà barman';
            }
            header('Location: index.php?module=admin&action=gestionCompte');
            exit();
        }
    }

    public function enleverRoleBarman(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire' && isset($_SESSION['asso']) && isset($_GET['id'])){
            $idUtilisateur = $_GET['id'];
            $idAssociation = $_SESSION['asso'];

            if ($this->modele->dejaBarman($idUtilisateur, $idAssociation, "Barman")){
                $this->modele->deleteRoleBarman($idUtilisateur, $idAssociation, "Barman");
                $_SESSION['messageOk'] = 'Le role barman a bien été enlever';
            }
            header('Location: index.php?module=admin&action=gestionCompte');
            exit();
        }
    }

    public function bannirUtilisateur(){
        if (isset($_SESSION['role']) && ($_SESSION['role'] == 'Gestionnaire' || $_SESSION['role'] == 'Admin') && isset($_SESSION['asso']) && isset($_GET['id'])){
            $idUtilisateur = $_GET['id'];
            $idAssociation = $_SESSION['asso'];
            $loginUtilisateur = $this->modele->getLogin($idUtilisateur);
            $this->modele->deleteUtilisateur($idUtilisateur, $idAssociation);
            $_SESSION['messageOk'] = 'Vous avez banni '.$loginUtilisateur;
            header('Location: index.php?module=admin&action=gestionCompte');
            exit();
        }
    }

    /**
     * lister les demandes des utililisateurs (pour rejoindre l'asso du gestionnaire)
     * accepterDemande() -> donne le role client dans l'asso
     * refuserDemande() -> supp la ligne dans role
     */
    public function listerDemandeUtilisateur(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire' && isset($_SESSION['asso'])){
            $this->vue->afficherListeDemandeUtilisateur(
                $this->modele->getListeDemandeUtilisateur($_SESSION['asso'])
            );
        }
    }

    public function accepterDemande(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire' && isset($_GET['id'])){
            $this->modele->accepterDemandeUtilisateur(
                $_GET['id'],
                $_SESSION['asso']
            );
            $this->listerDemandeUtilisateur();
        }
    }

    public function refuserDemande(){
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Gestionnaire' && isset($_GET['id'])){
            $this->modele->refuserDemandeUtilisateur(
                $_GET['id'],
                $_SESSION['asso']
            );
            $this->listerDemandeUtilisateur();
        }
    }

    public function listeDemandeCreationAsso() {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin'){
            $demandesAsso = $this->modele->listeDemandeAsso();
            if(empty($demandesAsso)) {
                $this->vue->demandeCreationAssoVide();
            }
            else {
                $this->vue->afficherListeDemandeCreationAsso($demandesAsso);
            }
        }
    }

    public function validerDemandeAsso()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin'){
            $idAsso = $_GET['assoId'];
            $idUtilisateur = $_GET['utilisateurId'];
            $this->modele->accepterAsso($idAsso);
            $this->modele->insertRoleGestionnaire($idUtilisateur,$idAsso,"Gestionnaire");
        }
        $this->listeDemandeCreationAsso();
    }

    public function refuserDemandeAsso()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin'){
            $idAsso = $_GET['assoId'];
            $this->modele->refuserAsso($idAsso);
        }
        $this->listeDemandeCreationAsso();
    }
    /**
     * Envoie une pièce légale (PDF) d'une demande de création d'association. Réservé au rôle Admin.
     * 401 sans session, 403 si le rôle n'est pas Admin, 400 si type ou asso sont invalides,
     * 404 si aucune demande ou aucun fichier. Le chemin est reconstruit ici (type de la liste blanche
     * + identifiant entier) : aucune valeur venant de l'utilisateur ou de la base n'y entre.
     */
    public function voirPieceLegale(){
        if (!isset($_SESSION['login']) || !isset($_SESSION['role'])){
            $this->reponseSansPiece(401);
        }
        if ($_SESSION['role'] !== 'Admin'){
            $this->reponseSansPiece(403);
        }
        $type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
        $asso = isset($_GET['asso']) && is_string($_GET['asso']) ? $_GET['asso'] : '';
        if (!in_array($type, ['carteIdentite', 'statutAsso', 'procesVerbal'], true)
            || !ctype_digit($asso) || strlen($asso) > 9){
            $this->reponseSansPiece(400);
        }
        $idAsso = (int) $asso;
        $nom = $type . '_' . $idAsso . '.pdf';
        $chemin = DOCUMENTS_LEGAUX_DIR . '/' . $nom;
        if (!$this->modele->demandeAssoExiste($idAsso) || !is_file($chemin)){
            $this->reponseSansPiece(404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nom . '"');
        header('Content-Length: ' . filesize($chemin));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($chemin);
        exit();
    }

    private function reponseSansPiece($code){
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Pièce légale non disponible';
        exit();
    }

    public function unrecognizedAction(){
        $this->vue->actionNonTrouver();
    }

    public function ajoutGestionnaire()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin'){
            $comptes = $this->modele->getUtilisateurNonRole($_GET['id'],'Gestionnaire');
            $this->vue->afficherTabAjoutGestionnaire($comptes,$_GET['id']);
        }
    }

    public function donnerRoleGestionnaire()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin' && isset($_GET['id'])){
            $idUtilisateur = $_GET['id'];
            $idAssociation = $_GET['asso'];

            if (!$this->modele->dejaBarman($idUtilisateur, $idAssociation, "Gestionnaire")){
                $this->modele->insertRoleBarman($idUtilisateur, $idAssociation, "Gestionnaire");
                $_SESSION['messageOk'] = 'Promotion success';
            }else{
                $_SESSION['messagePasOk'] = 'Promotion fail, cette personne est déjà barman';
            }
            header('Location: index.php?module=admin&action=listerAssociation');
            exit();
        }
    }

    public function enleverRoleGestionnaire()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin' && isset($_GET['id'])){
            $idUtilisateur = $_GET['id'];
            $idAssociation = $_GET['asso'];
            var_dump($idUtilisateur,$idAssociation);
            if ($this->modele->dejaBarman($idUtilisateur, $idAssociation, "Gestionnaire")){
                $this->modele->deleteRoleBarman($idUtilisateur, $idAssociation, "Gestionnaire");
                $_SESSION['messageOk'] = 'Le role gestionnaire a bien été enlever';
            }
            header('Location: index.php?module=admin&action=listerAssociation');
            exit();
        }
    }

    public function enleverGestionnaire()
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] == 'Admin'){
            $comptes = $this->modele->getUtilisateurAvecRole($_GET['id'],'Gestionnaire');
            $this->vue->afficherTabSuppressionGestionnaire($comptes,$_GET['id']);
        }
    }

    public function getVue(){
        return $this->vue->afficher();
    }
}
