# API (routes HTTP) — I-CONNECT

Application PHP rendue côté serveur : il n'y a pas d'API JSON. Le « contrat » entre le front (vues) et le back
(contrôleurs/modèles) est l'ensemble des routes ci-dessous. Toutes les réponses sont des pages HTML complètes
(`template.php`), ou une redirection `Location`.

## Conventions

- Point d'entrée unique : `index.php`.
- Routage : `index.php?module=<module>&action=<action>` (GET). Les paramètres d'une action passent en GET (`id`, `date`…) ou en POST (formulaires).
- Exception : connexion/inscription utilisent `index.php?actionComposant=<action>` (voir plus bas).
- Session PHP : `login`, `id`, `role` (`Client`, `Barman`, `Gestionnaire`, `Admin`), `asso` (association choisie), `soldeClient`.
- Un rôle ou une session insuffisante ne renvoie pas d'erreur HTTP : le contrôleur n'affiche rien ou redirige.
- Action inconnue : modale « action non trouvée » (`unrecognizedAction`).
- Formulaires POST sensibles : champ caché `tokenCSRF` obligatoire (`Token::verifierToken`) ; sinon la requête est ignorée.
- Messages utilisateur : `$_SESSION['messageOk']` / `$_SESSION['messagePasOk']`.
- Non connecté : seule la landing page (`index.php`) et `actionComposant` sont accessibles.

## Routage par module

`module` absent → `asso` (un `Admin` est alors redirigé vers `admin`). Valeurs : `asso`, `admin`, `produit`, `panier`,
`stock`, `compte`, `commande`, `fournisseur`. Toute autre valeur → `commande`.

## Connexion (`?actionComposant=`)

| actionComposant | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `form_inscription` | GET | — | public | Formulaire d'inscription |
| `inscription` | POST | `tokenCSRF`, `login`, `pwd`, `nom`, `prenom`, `telephone` ou `email` | public | Crée le compte |
| `form_connexion` | GET | — | public | Formulaire de connexion |
| `connexion` | POST | `tokenCSRF`, `login`, `pwd` | public | Ouvre la session |
| `deconnexion` | GET | — | connecté | Ferme la session |
| `rgpd` | GET | — | public | Avertissement RGPD |

## Associations (`module=asso`, défaut `afficherAssoInscris`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `afficherAssoInscris` | GET | — | connecté | Mes associations |
| `afficherAssoPasInscris` | GET | — | connecté | Toutes les associations |
| `afficherAssoInscriptionEnAttente` | GET | — | connecté | Inscriptions en attente |
| `afficherAssoCreationEnAttente` | GET | — | connecté | Créations en attente |
| `choisiAsso` | GET | `id` (asso) ou `role` | connecté | Choisit l'association / le rôle, puis redirige vers le module du rôle |
| `formAssociation` | GET | — | connecté sans rôle actif | Formulaire de création |
| `ajouterAssociation` | POST | `tokenCSRF`, `nom` (+ pièces justificatives) | connecté sans rôle actif | Demande de création |

## Administration (`module=admin`, défaut `listerAssociation`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `listerAssociation` | GET | — | Admin | Liste des associations |
| `listeDemandeCreationAsso` | GET | — | Admin | Demandes de création |
| `validerDemandeAsso` | GET | `assoId`, `utilisateurId` | Admin | Valide une création |
| `refuserDemandeAsso` | GET | `assoId` | Admin | Refuse une création |
| `ajoutGestionnaire` | GET | `id` (asso) | Admin | Liste des comptes promouvables |
| `donnerRoleGestionnaire` | GET | `id` (utilisateur), `asso` | Admin | Ajoute un gestionnaire |
| `enleverGestionnaire` | GET | `id` (asso) | Admin | Liste des gestionnaires retirables |
| `enleverRoleGestionnaire` | GET | `id` (utilisateur), `asso` | Admin | Retire un gestionnaire |
| `gestionCompte` | GET | — | Gestionnaire | Comptes de l'association |
| `donnerRoleBarman` / `enleverRoleBarman` | GET | `id` (utilisateur) | Gestionnaire | Gère le rôle barman |
| `bannirUtilisateur` | GET | `id` (utilisateur) | Gestionnaire, Admin | Bannit |
| `listerDemandeUtilisateur` | GET | — | Gestionnaire | Demandes d'inscription |
| `accepterDemande` / `refuserDemande` | GET | `id` (demande) | Gestionnaire | Traite une demande |

## Comptes (`module=compte`, défaut `formRecharger`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `formRecharger` | GET | — | Client | Formulaire de recharge |
| `recharger` | POST | `montant` | Client | Crédite le solde |
| `profil` | GET | — | connecté | Profil |
| `formModifierProfil` | GET | — | connecté | Formulaire de modification |
| `modifierProfil` | POST | `tokenCSRF`, `login`, `nom`, `prenom`, `telephone` ou `email`, `pwd` (optionnel) | connecté | Met à jour le profil |
| `modalSuprimerProfil` | GET | — | connecté | Confirmation de suppression |
| `SuprimerProfil` | GET | — | connecté hors Admin | Supprime le compte |

## Produits (`module=produit`, défaut `listerProduits`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `listerProduits` | GET | — | Client | Catalogue de l'association |
| `form_ajouterNouveauProduit` | GET | — | Gestionnaire | Formulaire d'ajout |
| `ajouterNouveauProduit` | POST | `tokenCSRF`, `nom`, `prix` (+ image) | Gestionnaire | Crée le produit |
| `form_modifierProduit` | GET | `id` | Gestionnaire | Formulaire de modification |
| `modifierProduit` | POST | `tokenCSRF`, GET `id`, `nom`, `prix` | Gestionnaire | Modifie le produit |
| `listerProduitsFournisseur` | GET | — | Gestionnaire | Produits à restocker |
| `restockerProduit` | POST | GET `id`, `idFournisseur` ; `quantite` | Gestionnaire | Commande de restock |

## Panier (`module=panier`, défaut `panier`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `panier` | GET | — | Client | Affiche le panier et le solde |
| `ajouterDansPanier` | GET | `id` (produit) | Client | Ajoute un produit |
| `enleverProduit` | GET | `id` (produit) | Client | Retire un produit |
| `validerPanier` | GET | — | Client | Crée la commande, débite le solde, décrémente le stock |
| `viderPanier` | GET | — | connecté | Vide le panier |

## Commandes (`module=commande`, défaut `commandeAvancee`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `commandeAvancee` | GET | — | Barman | Commandes du jour |
| `historique` | GET | — | Barman | Historique des commandes |
| `valideCommande` | GET | `id`, `date` | Barman | Demande le code de retrait |
| `validationDuRetrait` | POST | `id`, `date`, `code` | Barman | Valide si le code est correct |
| `refuserCommande` | GET | `id`, `date` | Barman | Refuse et rembourse |
| `afficherProfile` | GET | `id`, `date` | Barman | Profil du client de la commande |
| `historiqueCommandeClient` | GET | — | Client | Historique du client |
| `historiqueCommandeFournisseur` | GET | — | Gestionnaire | Historique des restocks |
| `historiqueCommandeAsso` | GET | — | Gestionnaire | Commandes clients de l'association |

## Stock (`module=stock`, défaut `stockProduits`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `stockProduits` | GET | — | Gestionnaire | Stock courant |
| `stockProduitBarman` | GET | — | Barman | Stock (lecture) |
| `form_inventaire` | GET | — | Gestionnaire | Formulaire d'inventaire |
| `ajoutInventaire` | POST | `tokenCSRF`, `stock[]` | Gestionnaire | Enregistre l'inventaire |
| `ajouterPertes` | POST | `tokenCSRF`, GET `id` (produit), `perte` | Gestionnaire | Déclare des pertes |
| `formChoixInventaireRapport` | GET | — | Gestionnaire | Choix de l'inventaire |
| `rapport` | POST | `tokenCSRF`, `idinventaire` | Gestionnaire | Rapport d'inventaire |

## Fournisseurs (`module=fournisseur`, défaut `listerFournisseur`)

| action | Méthode | Paramètres | Rôle | Effet |
|---|---|---|---|---|
| `listerFournisseur` | GET | — | Gestionnaire | Liste des fournisseurs |
| `formAjouterFournisseur` | GET | — | Gestionnaire | Formulaire d'ajout |
| `ajouterFournisseur` | POST | `tokenCSRF`, `nom`, `email`, `ville`, `telephone` | Gestionnaire | Crée le fournisseur |
| `supprimerFournisseur` | GET | `id` | Gestionnaire | Supprime le fournisseur |
| `ajouterProduitFournisseur` | POST | GET `idFournisseur` ; `idProduit` | Gestionnaire | Lie un produit à un fournisseur |

## Landing page

`index.php` sans session → `modules/landingPage` (`action=landingPage`, seule action).

## Points d'attention

- Plusieurs actions qui modifient l'état sont en **GET** et sans jeton CSRF (`validerPanier`, `enleverProduit`, `refuserCommande`, `SuprimerProfil`, actions d'`admin`…) : ne pas en ajouter de nouvelles, préférer POST + `tokenCSRF`.
- Les noms `SuprimerProfil` / `modalSuprimerProfil` sont écrits tels quels dans le code (faute incluse).
