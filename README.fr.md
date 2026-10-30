# Azure DevOps – plugin GLPI 11

Créez un élément de travail Azure DevOps (User Story, Product Backlog Item, Issue…) depuis un ticket GLPI, éventuellement **sous un Epic ou une Feature**, ou liez un élément existant, puis **vérifiez toutes les 5 minutes** (action automatique GLPI) s'il est terminé. Quand il l'est, le plugin agit sur le ticket **via les objets GLPI** (suivi, solution) : historique, règles métier, notifications et autres actions automatiques (clôture automatique des tickets résolus, enquêtes de satisfaction…) s'appliquent comme pour une action manuelle.

Chaque projet Azure DevOps se configure avec **ses propres identifiants** — un jeton d'accès personnel, ou une **application Microsoft Entra** qui n'est liée au compte de personne — et peut être réservé à **des groupes et des utilisateurs choisis**.

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
| Authentification | **Jeton d'accès personnel (PAT)** ou **application Microsoft Entra** (voir ci-dessous) |
| Jeton d'accès (PAT) | Un jeton d'accès personnel avec les portées **Work Items (Read & write)** et **Project and Team (Read)**, dont le propriétaire peut travailler sur le projet. Stocké **chiffré** (clé de GLPI), jamais ré-affiché, utilisé **uniquement pour ce projet** |
| Type à créer | `User Story` (Agile), `Product Backlog Item` (Scrum), `Issue` (Basic)… à choisir parmi les types lus par *Enregistrer et tester la connexion* |
| Parents possibles | Types sous lesquels on peut créer un élément et que l'on peut lier (`Epic`, `Feature`…) |
| Types « suivi uniquement » | Types (Epic, Feature…) dont la fin ajoute seulement un suivi privé et ne résout jamais le ticket |
| Catégories « terminé » | `Completed` (toujours) et éventuellement `Resolved`. Les catégories viennent du modèle de processus : états et processus personnalisés fonctionnent sans configuration |
| Champs par défaut | Valeurs des champs que votre processus rend obligatoires (Priorité…), voir ci-dessous |
| Area path, tags par défaut | Facultatifs |

Les identifiants expirent ou sont révoqués : quand ils sont refusés, l'erreur s'affiche dans la liste des projets et sur les tickets concernés, les autres projets continuent d'être vérifiés, et saisir de nouveaux identifiants efface l'erreur.

### Champs exigés par votre processus (Priorité…)

Un processus personnalisé peut rendre des champs obligatoires, souvent avec une liste de valeurs autorisées. Azure DevOps refuse alors la création : `TF401320: Rule Error for field Priorité ... Required ... LimitedToValues`. Il ne signale qu'un champ à la fois (« One additional error occurred »), donc le plugin les lit tous pour vous :

1. Dans le projet, choisissez le **type d'élément à créer** et utilisez *Enregistrer et tester la connexion*.
2. La page du projet liste maintenant **les champs exigés par le processus pour ce type**, avec leur **nom de référence** (par exemple `Microsoft.VSTS.Common.Priority`), leurs valeurs autorisées, et s'ils ont encore *besoin d'une valeur*.
3. Dans **Champs par défaut**, donnez une valeur à chacun, une par ligne :

```
Microsoft.VSTS.Common.Priority = {priority}
Custom.Team = Support
Custom.Reference = GLPI-{id}: {title}
```

Variables : `{priority}` est la priorité du ticket sur l'échelle d'Azure DevOps (GLPI 5-6 → 1, 4 → 2, 3 → 3, 1-2 → 4 ; utilisez une valeur fixe si votre processus a d'autres valeurs), `{id}` le numéro du ticket, `{title}` son titre. Utilisez exactement l'une des **valeurs autorisées** listées. Les champs qui ont une valeur par défaut dans le processus la reçoivent d'Azure DevOps et n'ont besoin de rien.

### S'authentifier sans compte personnel (application Microsoft Entra)

Un jeton d'accès personnel appartient à une personne : si son compte est désactivé, ou si le jeton expire, le projet cesse de fonctionner. Avec une **application Entra** (un *principal de service*), le plugin se connecte avec les identifiants propres de l'application, sans aucun compte personnel. Cela se fait en trois étapes, **à trois endroits différents** :

**A. Dans Microsoft Entra** (`https://entra.microsoft.com`, ou le portail Azure), avec un compte autorisé à inscrire des applications :

1. *Applications > Inscriptions d'applications > Nouvelle inscription*. Donnez un nom reconnaissable, par exemple `GLPI Azure DevOps`.
2. Sur la page *Vue d'ensemble* de l'application, copiez l'**ID d'application (client)** et l'**ID de l'annuaire (tenant)**.
3. *Certificats et secrets > Nouveau secret client*. Copiez tout de suite la colonne **Valeur** (pas l'*ID du secret*) : elle ne sera plus jamais affichée.

**B. Dans Azure DevOps** (`https://dev.azure.com/<votre-organisation>`), avec un membre du groupe *Project Collection Administrators* (les propriétaires de l'organisation en font partie) :

1. Cliquez sur la roue dentée **Organization settings**, en bas à gauche.
2. **Users > Add users**.
3. Recherchez l'application **par le nom donné à l'étape A1**, puis réglez :
   - **Access level** : `Basic` (voir *Niveau d'accès* ci-dessous) ;
   - **Add to projects** : le projet Azure DevOps du projet du plugin ;
   - **Azure DevOps Groups** : laissez `Project Contributors` (c'est ce qui permet à l'application de créer et modifier des éléments).
4. Vérification : l'application apparaît dans *Organization settings > Users*, puis dans *Project settings > Permissions > Contributors > Members*.

L'inscription dans Entra **ne suffit pas** : sans l'étape B, Azure DevOps la refuse (HTTP 401) alors qu'Entra a délivré un jeton. L'organisation doit aussi être rattachée au **même tenant Entra** (une organisation liée à un compte Microsoft personnel ne peut pas héberger d'application) : voir *Organization settings > Microsoft Entra*.

**C. Dans le plugin** : *Configuration > Plugins > Azure DevOps*, ajoutez ou modifiez le projet, choisissez *Application Microsoft Entra*, saisissez l'ID du tenant, l'ID de l'application et la valeur du secret, puis *Enregistrer et tester la connexion*.

Le plugin obtient un jeton d'accès auprès d'Entra (valable environ une heure), le garde chiffré dans le projet tant qu'il est valable, le renouvelle avant son expiration, et en redemande un une fois si Azure DevOps le refuse plus tôt (révoqué). Le secret client a aussi une date d'expiration, mais il appartient à l'application et non à une personne : renouvelez-le avant l'échéance. Les deux modes peuvent être renseignés ; celui qui est sélectionné est utilisé, on peut donc basculer sans ressaisir l'autre. Les clouds souverains utilisent une autre adresse de connexion : *Avancé > Adresse de connexion Microsoft Entra*.

### Niveau d'accès du compte (Basic ou Stakeholder)

Le compte derrière le jeton ou l'application a un **niveau d'accès** Azure DevOps. **Basic** est le choix sûr. **Stakeholder** (gratuit) est limité, et Microsoft documente qu'un Stakeholder peut voir, ajouter et modifier des éléments de travail mais **ne peut attribuer que des tags qui existent déjà** (il ne peut pas en créer). Comme le plugin ajoute les tags par défaut du projet (`glpi` sauf changement), un compte Stakeholder peut échouer à créer un élément tant que le tag n'existe pas. Avec un compte Stakeholder, laissez les tags par défaut vides, ou créez d'abord le tag depuis un compte qui le peut. En cas d'échec, le message d'Azure DevOps s'affiche dans GLPI et est écrit dans `files/_log/azuredevops.log`.

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
| Identifiants refusés ou expirés (jeton, secret client), Azure DevOps ou Entra injoignable | Ce projet est retenté à l'exécution suivante (erreur affichée) ; les autres projets sont vérifiés |
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

Remplacez le dossier, videz le cache de GLPI (`php bin/console cache:clear`), puis dans *Configuration > Plugins* cliquez sur *Mettre à jour* puis *Activer*. Projets, identifiants, listes d'accès, liens, paramètres et droits sont conservés, et les projets existants restent en mode jeton personnel (`tests/upgrade_test.sh`).

## Limites connues

- Ticket → élément de travail uniquement ; les commentaires ne sont pas synchronisés. Un élément rouvert après avoir été terminé n'est plus suivi.
- Les pièces jointes des suivis ne sont pas copiées (seulement celles du ticket et les images de sa description). Les pull requests ne sont pas affichées.
- Délai de détection d'environ 5 minutes (interrogation périodique, pas de service hook).
- Azure DevOps Services (`dev.azure.com`). Azure DevOps Server (sur site) a une autre structure d'URL et n'est pas pris en charge.
- Les applications Entra se connectent avec un **secret client** (les certificats et les identifiants fédérés ne sont pas pris en charge).
- Les tests ci-dessous utilisent un **mock** de l'API Azure DevOps et de la connexion Microsoft Entra, pas les vrais services : essayez d'abord sur un projet bac à sable (voir *CONTRIBUTING* pour les points à vérifier).

## Tests (`tests/`)

`mock_ado.py` (mock de l'API Azure DevOps et de la connexion Entra, avec deux projets et deux formes de processus), `integration.php` (vrai GLPI, CLI, vrai cron : projets et chiffrement, matrice d'accès, création sous un parent, liaison, tous les cas de l'action automatique, la connexion Entra), `http_test.py` (vraie application web : connexion, onglet, configuration, restrictions d'accès, CSRF, langues), `upgrade_test.sh`, `run_all.sh`.
