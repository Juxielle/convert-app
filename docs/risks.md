# Risques et décisions à valider

## Actualisation après phase 4

Huit outils documents/PDF/tableurs sont validés localement, avec LibreOffice isolé, Python/PDFium/pypdf/openpyxl, budgets et contrôle des documents protégés/complexes. Leur activation dépend d’une vérification réelle et d’un marqueur privé. Voir `phase-4-report.md` et `documents.md`. Les formats anciens Office, PDF vers DOCX et certaines ressources actives restent refusés. Les politiques réseau natives Linux, la configuration MySQL, la charge et les ressources du VPS sont toujours à valider avant production. Les constats antérieurs sont conservés pour traçabilité.

## Actualisation après phase 3

Les trois outils image JPEG/PNG/WebP sont maintenant actifs et testés, y compris le parcours HTTP avec un worker réel. Les limites source de pixels/dimensions, la sortie bornée et les lots atomiques ont été ajoutés. Voir `phase-3-report.md` et `images.md`. Les contrôles navigateur réel, MySQL/Linux, RSS/CPU natifs et charge du VPS restent en attente. GD supprime les métadonnées et ne corrige pas l’orientation EXIF ; le moteur n’est pas destiné à une restitution photographique avec gestion des couleurs. Les sections antérieures restent des constats historiques.

## Actualisation après phase 2

Le socle et le pipeline sont maintenant implémentés. Quotas, propriété, publication transactionnelle database, nettoyage et annulation ont passé les tests SQLite ; trois courses entre processus distincts sont vérifiées. Les chemins API personnels sont sous session/CSRF, non cacheables, et CORS est explicitement fermé. Voir `phase-2-report.md` pour les résultats et les fichiers concernés.

Restent à vérifier avant activation de moteurs lourds/production : MySQL et concurrence sur le moteur cible, timeouts natifs, limites Linux CPU/RAM/processus, ressources et espace partagé du VPS, scheduler réellement installé, Apache/HTTPS et navigateur réel. Aucun convertisseur public n’est actif. L’analyse initiale ci-dessous est conservée pour traçabilité.

État au 29 octobre 2026, avant tout développement métier.

| Risque / incertitude | Conséquence | Réponse prévue |
| --- | --- | --- |
| Dossier vide | Aucune application utilisable à ce stade | Initialiser le socle après examen de la phase 0 |
| Ressources du VPS inconnues | Saturation possible du serveur partagé | Mesurer CPU/RAM/disque et usages existants avant activation de conversions ; un worker initial et back-pressure |
| Convertisseurs absents du PATH | Aucun format réellement validé | Inventorier/installer dans une phase dédiée, vérifier versions/licences, tester chaque couple annoncé |
| Wrapper npm EPERM | Commandes habituelles non utilisables | CLI npm direct vérifié ; installation/build encore à vérifier |
| Versions de dépendances non choisies | Incompatibilité PHP/Node possible | Vérifier documentation officielle actuelle puis verrouiller les versions compatibles |
| Services Laragon non vérifiés | MySQL/Apache/Redis pas nécessairement actifs | Tester dans le socle ; aucune dépendance Redis imposée |
| Écart Windows/Linux | Limites et processus différents en production | Tests locaux puis validation Linux des permissions, signaux, profils et limites |
| Admission concurrente | Dépassement de quota ou disque | Réservations atomiques propriétaire/global, ordre de verrous fixe et tests sur MySQL |
| Crash entre stockage, commit et queue | Fichiers orphelins ou tâches bloquées | Compensation, dispatch après commit, réconciliation et tests de crash |
| Parseurs de fichiers malveillants | Déni de service ou vulnérabilité native | Complexité bornée, outils à jour, worker isolé sans privilèges ni réseau inutile |
| Annulation/nettoyage en course | Résultat publié après annulation ou fichier actif supprimé | Transitions atomiques, heartbeat, contrôle avant publication et nettoyage des seuls espaces inactifs |
| Liens privés et IDOR | Fuite de fichiers | Policies systématiques, stockage privé, téléchargement contrôlé, tests entre visiteurs et comptes |
| Formats propriétaires complexes | Mise en page dégradée | Fixtures représentatives et avertissements ; aucune promesse de conversion parfaite |
| SSRF et restrictions des plateformes | Accès au réseau interne ou source non autorisée | Module externe désactivé, allowlist vide, validation par source avant activation |
| Données légales de l’exploitant inconnues | Pages légales incomplètes | Renseigner identité, contact et règles applicables avant publication |
| HTTPS, domaine et email inconnus | Cookies/réinitialisation non validés en production | Choisir origine unique, configurer TLS et transport email puis tester |

## Informations nécessaires selon l’étape

Pas de blocage pour préparer le socle local après examen de ces documents. Les versions exactes seront choisies après vérification de compatibilité, sans supposer que la dernière version prend en charge cet environnement.

Avant déploiement : accès et inventaire du VPS Linux, version PHP web/CLI, ressources et espace disponibles, moteur de base réellement déployé, domaine HTTPS, moyen de supervision du worker/cron, sauvegarde et transport email. L’audit local ne remplace pas ces mesures.

Avant activation externe : sources précises, permission de récupération, conditions applicables et validation des API/outils retenus. En leur absence, conserver le module désactivé.

## Validation actuelle

Les phases 1 à 5 disposent maintenant de tests locaux Laravel/React, conversions natives, contrôle des sorties et tests de concurrence. Voir les rapports de phases et `media.md`. Les protections et limites ont été exercées sous Windows/SQLite ; aucun déploiement, test MySQL/Linux ou dimensionnement du VPS partagé n’a été effectué. L’accès public reste conditionné au durcissement de phase 8. Les risques du tableau initial ci-dessus décrivent l’audit de phase 0 ; les rapports suivants consignent les réponses réalisées.
