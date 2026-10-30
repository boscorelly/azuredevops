# Azure DevOps – plugin GLPI 11

Créez un élément de travail Azure DevOps (User Story, Product Backlog Item, Issue…) depuis un ticket GLPI, éventuellement **sous un Epic ou une Feature**, ou liez un élément existant, puis **vérifiez toutes les 5 minutes** (action automatique GLPI) s'il est terminé. Quand il l'est, le plugin agit sur le ticket **via les objets GLPI** (suivi, solution) : historique, règles métier, notifications et autres actions automatiques (clôture automatique des tickets résolus, enquêtes de satisfaction…) s'appliquent comme pour une action manuelle.

Chaque projet Azure DevOps se configure avec **son propre jeton d'accès**, et peut être réservé à **des groupes et des utilisateurs choisis**.

Testé sur **GLPI 11.0.11** (PHP 8.3, MariaDB 10.11) contre un mock de l'API REST Azure DevOps (voir *Tests*). Licence : AGPL-3.0-or-later.

## Installation

1. Placez le dossier `azuredevops` dans `plugins/` de GLPI, puis installez et activez-le dans *Configuration > Plugins*.
2. Vérifiez que le cron système de GLPI tourne (`* * * * * php /chemin/glpi/front/cron.php`) ; l'action automatique `CheckWorkItems` est en mode CLI.
3. *Configuration > Plugins > Azure DevOps* : ajoutez vos projets (ci-dessous), puis réglez le comportement à la création et à la fin.

## Configurer les projets

*Configuration > Plugins > Azure DevOps > Ajouter un projet*. Pour chaque projet :

| Champ | Signification |
|---|---|
| Nom | Affiché aux techniciens quand ils choisissent où créer l'élément |
| Organisation / projet | `mon-organisation` (ou son URL) et le nom du projet Azure DevOps |
| Jeton d'accès (PAT) | Un jeton d'accès personnel avec la portée **Work Items (Read & write)**, dont le propriétaire peut travailler sur le projet. Stocké **chiffré** (clé de GLPI), jamais ré-affiché, utilisé **uniquement pour ce projet** |
| Type à créer | `User Story` (Agile), `Product Backlog Item` (Scrum), `Issue` (Basic)… à choisir parmi les types lus par *Enregistrer et tester la connexion* |
| Parents possibles | Types sous lesquels on peut créer un élément et que l'on peut lier (`Epic`, `Feature`…) |
| Types « suivi uniquement » | Types (Epic, Feature…) dont la fin ajoute seulement un suivi privé et ne résout jamais le ticket |
| Catégories « terminé » | `Completed` (toujours) et éventuellement `Resolved`. Les catégories viennent du modèle de processus : états et processus personnalisés fonctionnent sans configuration |
| Area path, tags par défaut | Facultatifs |

Les jetons expirent : quand un jeton est refusé, l'erreur s'affiche dans la liste des projets et sur les tickets concernés, les autres projets continuent d'être vérifiés, et saisir un nouveau jeton efface l'erreur.

### Qui peut utiliser quel projet

Dans chaque projet, **Restreindre aux utilisateurs et groupes ci-dessous** :

- *coché* (par défaut) : seuls les membres des **groupes** listés et les **utilisateurs** listés peuvent utiliser le projet. Un technicien dans plusieurs groupes autorisés obtient tous leurs projets (groupe 1 → projet A, groupe 2 → projet B, groupe 3 → A et B) ;
- *décoché* : tout technicien ayant le droit « créer » du plugin peut l'utiliser ;
- un projet restreint sans personne dans la liste n'est utilisable par **personne**.

Un technicien ne **voit** que les liens des projets qu'il peut utiliser (une mention indique combien d'autres existent), et chaque action — créer, lier, rechercher, actualiser, délier — est vérifiée côté serveur par rapport à cet accès, en plus des droits du profil. Les groupes ne sont pas étendus à leurs sous-groupes : listez chaque groupe qui doit avoir accès.

## Utilisation

Onglet **Azure DevOps** du ticket :

- **Créer un élément de travail** : choisissez un projet, éventuellement un parent (Epic / Feature, recherché par titre ou numéro), des tags et le suivi (privé / public / aucun). L'élément contient les informations du ticket, la description (HTML), les images en ligne et les pièces jointes envoyées dans Azure DevOps, et un lien hypertexte vers le ticket.
- **Lier un élément existant** : choisissez un projet et recherchez par titre ou numéro. Un même Epic peut être lié à plusieurs tickets.
- L'onglet affiche la chaîne de parents (Epic › Feature), le type, l'état Azure DevOps et la dernière vérification.

| Situation | Comportement |
|---|---|
| L'élément atteint une catégorie « terminé » | Suivi privé (état, qui, quand). Le ticket est résolu / le demandeur informé (paramètres) **uniquement quand plus aucun autre élément du ticket n'est ouvert** |
| Epic / Feature (ou autre type « suivi uniquement ») terminé | Suivi privé seulement ; le ticket n'est jamais résolu |
| Élément déplacé vers un autre projet Azure DevOps | Ticket non résolu ; suivi privé informatif ; plus suivi |
| Élément en catégorie « Removed » | Idem : plus suivi, ticket inchangé |
| Élément introuvable (supprimé, accès retiré) | Abandon après 3 vérifications consécutives ; suivi privé informatif |
| Élément déjà terminé au moment de la liaison | Lié pour référence, aucune action sur le ticket |
| Ticket déjà clos / à la corbeille | Lien marqué terminé, ticket intact |
| Jeton refusé ou expiré, Azure DevOps injoignable | Ce projet est retenté à l'exécution suivante (erreur affichée) ; les autres projets sont vérifiés |
| Limite de débit (HTTP 429) | L'exécution s'arrête proprement et reprend à la suivante |
| Échec pendant l'action sur le ticket | Tout est annulé ; l'élément reste « ouvert » et est retenté, sans doublon |

## Informer le demandeur (non-techniciens)

Les demandeurs ne voient jamais l'onglet Azure DevOps. Par défaut ils ne savent rien. Deux options ajoutent des **suivis publics neutres**, sans aucun détail Azure DevOps (ni projet, ni lien, ni développeur) :

- à la création d'un élément (le technicien peut décocher « Informer le demandeur » pour un élément donné) ;
- quand tout est terminé : rien, un suivi public, ou **le ticket est résolu** avec ce message comme solution.

Les deux textes sont modifiables. Les détails techniques restent dans les suivis **privés**.

## Langues

Anglais (`en_GB`, `en_US`) et français (`fr_FR`). Le plugin utilise **la langue de chaque utilisateur s'il a une traduction pour elle, sinon l'anglais**. Pour ajouter une langue : copiez `locales/azuredevops.pot` vers `locales/<xx_XX>.po`, traduisez-le, lancez `python3 tools/locales.py` (nécessite `msgfmt`), videz le cache de GLPI.

## Droits

Onglet *Administration > Profils > (profil) > Azure DevOps* ou tableau **Droits par profil** de la page de configuration : lecture, créer ou lier, actualiser, délier. Les droits sont cumulatifs : **la lecture est requise** pour les autres, et les profils de l'interface simplifiée ne peuvent jamais utiliser le plugin. Les pages de configuration exigent le droit *Configuration > Mise à jour* de GLPI. Les formulaires utilisent la protection CSRF de GLPI. Les profils qui peuvent déjà modifier les tickets reçoivent les quatre droits à la première installation.

## Mise à jour

Remplacez le dossier, videz le cache de GLPI (`php bin/console cache:clear`), puis dans *Configuration > Plugins* cliquez sur *Mettre à jour* puis *Activer*. Projets, jetons, listes d'accès, liens, paramètres et droits sont conservés (`tests/upgrade_test.sh`).

## Limites connues

- Ticket → élément de travail uniquement ; les commentaires ne sont pas synchronisés. Un élément rouvert après avoir été terminé n'est plus suivi.
- Les pièces jointes des suivis ne sont pas copiées (seulement celles du ticket et les images de sa description). Les pull requests ne sont pas affichées.
- Délai de détection d'environ 5 minutes (interrogation périodique, pas de service hook).
- Azure DevOps Services (`dev.azure.com`). Azure DevOps Server (sur site) a une autre structure d'URL et n'est pas pris en charge.
- Les tests ci-dessous utilisent un **mock** de l'API Azure DevOps, pas le vrai service : essayez d'abord sur un projet bac à sable (voir *CONTRIBUTING* pour les points à vérifier).

## Tests (`tests/`)

`mock_ado.py` (mock de l'API Azure DevOps avec deux projets et deux formes de processus), `integration.php` (vrai GLPI, CLI, vrai cron : projets et chiffrement, matrice d'accès, création sous un parent, liaison, tous les cas de l'action automatique), `http_test.py` (vraie application web : connexion, onglet, configuration, restrictions d'accès, CSRF, langues), `upgrade_test.sh`, `run_all.sh`.
