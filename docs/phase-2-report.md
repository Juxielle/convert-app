# Rapport de phase 2 — Pipeline privé, tâches et quotas

Date : 29 octobre 2026. Phase demandée après examen du socle. Aucun moteur de conversion réel ajouté.

## Fonctionnement livré

- Réception multipart avec Form Request, taille réelle, MIME détecté depuis le contenu, extension/format cible et options en liste blanche. Noms internes aléatoires, nom de téléchargement nettoyé et stockage local privé dédié hors `public/`.
- Modèles `ConversionJob` UUID et `UsageEvent`, états uploaded/queued/processing/completed/failed/cancelled/expired, dates et compteurs de taille. La réponse publique est filtrée par une Resource et ne contient aucun chemin ou secret.
- Quotas glissants horaires/24 h, plafond de tâches actives par propriétaire, plafonds globaux d’admission, d’exécution et de stockage. Réservation de l’entrée et d’une sortie bornée, comptage des fichiers orphelins. Le verrou global SQL est acquis par une écriture, y compris sous SQLite.
- Publication atomique dans la queue Laravel database sur la même connexion transactionnelle que la réservation. Rollback et nettoyage compensatoire en cas d’échec ; la file n’est visible par le worker qu’au commit. Cette décision remplace le dispatch après commit proposé dans l’audit et supprime sa fenêtre de panne. Une connexion de queue distincte ou `after_commit=true` est refusé explicitement.
- Traitement idempotent revendiqué une seule fois, capacité globale bornée, progression seulement mesurée, checkpoints d’annulation et de durée, validation du résultat, échec normalisé et remboursement idempotent des erreurs internes.
- Propriété par compte ou secret opaque en session visiteur, empreintes seules dans les tâches, policies retournant 404 pour une tâche étrangère. Pas de jeton propriétaire envoyé à JavaScript ni enregistré en clair dans les logs applicatifs.
- Endpoints de création, historique de session/compte, état, annulation, suppression, téléchargement contrôlé et quota réel. Session Laravel et CSRF conservés ; CORS explicitement fermé aux autres origines ; réponses personnelles non cacheables.
- Suppression différée pendant un traitement : demande d’arrêt, puis nettoyage après arrêt avant suppression des métadonnées. Un téléchargement expiré est interdit immédiatement, sans attendre le nettoyeur. Annuler/supprimer une tâche en attente retire sa ligne de queue non réservée.
- Commande `conversions:reconcile` planifiée chaque minute : expiration, workers perdus, admissions abandonnées, suppressions à réessayer, fichiers/verrous orphelins, purge de métadonnées et usages. Verrou OS de processus maintenu pendant le traitement : un heartbeat ancien seul ne suffit pas à autoriser la suppression d’un worker encore vivant.
- Page `/mes-fichiers`, historique paginé, consommation réelle, polling limité aux traitements actifs, téléchargement contrôlé, annulation/suppression avec jeton CSRF et erreurs uniformes. Le catalogue et le dépôt public restent désactivés.

Le moteur factice copie un fichier texte **uniquement dans la suite de tests**. Ses classes sont sous `tests/Support`, injectées par les tests et absentes du registre de production. La présence des routes ne permet donc pas d’utiliser un faux convertisseur en production.

## Migration exécutée

`2026_10_29_000003_create_conversion_pipeline` appliquée avec `php artisan migrate --no-interaction` à la base SQLite locale existante du projet. Tables créées : `conversion_locks` (mutex initialisé), `conversion_jobs`, `usage_events`, avec index et clés étrangères. Aucune donnée existante supprimée, aucune base externe modifiée. Le rollback `down` existe mais n’a pas été exécuté sur la base locale.

## Fichiers créés

- `database/migrations/2026_10_29_000003_create_conversion_pipeline.php`
- `app/Models/ConversionJob.php`, `app/Models/UsageEvent.php`
- `app/Policies/ConversionJobPolicy.php`
- `app/Http/Requests/StoreConversionRequest.php`, `app/Http/Resources/ConversionJobResource.php`, `app/Http/Controllers/ConversionController.php`
- `app/Services/Conversions/Owner.php`, `Engine.php`, `EngineRegistry.php`, `Pipeline.php`, `PipelineLock.php`, `PipelineException.php`, `WorkerLease.php`
- `app/Jobs/ProcessConversion.php`, `app/Console/Commands/ReconcileConversions.php`
- `routes/conversions.php`, `config/cors.php`
- `resources/js/pages/Activity.tsx`, `resources/js/tests/activity.test.tsx`
- `tests/Feature/PipelineTest.php`, `tests/Feature/ConcurrentAdmissionTest.php`, `tests/Support/FakeEngine.php`, `FakeEngineRegistry.php`, `concurrent-admission.php`
- `docs/pipeline-operations.md` et ce rapport.

## Fichiers modifiés

`bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Middleware/SecurityHeaders.php`, `config/{conversion,filesystems,queue,database}.php`, `routes/{web,console}.php`, `.env.example`, `tests/Feature/CatalogTest.php`, `resources/js/{App.tsx,lib/api.ts,components/Layout.tsx,components/ui.tsx}`, `resources/css/app.css`, `README.md`, `docs/{architecture,implementation-plan,api,risks}.md`. Aucune nouvelle dépendance requise ; lockfiles inchangés.

## Vérifications réellement exécutées

| Commande / vérification | Résultat final |
| --- | --- |
| `php artisan test` | **32 tests réussis, 350 assertions** |
| `php vendor/bin/pint`, puis `php vendor/bin/pint --test` | Formatage appliqué, contrôle final réussi |
| `npm test` via CLI npm direct | **10 tests frontend réussis**, dont 2 pour Mes fichiers |
| `npm run lint` via CLI direct | Réussi |
| `npm run build` via CLI direct | TypeScript strict et Vite réussis ; JS 426,54 Ko / 137,26 Ko gzip |
| `composer validate --strict` | Réussi |
| `php artisan migrate:status` | Quatre migrations appliquées, dont la nouvelle migration métier |
| `php artisan route:list --path=api` | Dix routes API enregistrées |
| `php artisan schedule:list` | Réconciliation prévue chaque minute |
| `php artisan conversions:reconcile` | Réussie sur la base locale |
| Serveur Laravel + requêtes HTTP réelles | `/mes-fichiers`, `/api/v1/jobs`, `/api/v1/me/usage` : 200 ; réponses personnelles no-store ; POST sans jeton : 419 ; POST avec jeton visant le moteur de test hors tests : 422 |

Les commandes npm ont utilisé :

```powershell
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' test
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' run lint
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' run build
```

Les tests couvrent : file database réellement consommée par `queue:work --once`, téléchargement de résultat, livraisons doubles, IDOR entre visiteurs/comptes, taille, fichier vide, MIME/extension falsifiés, options arbitraires, quotas, rollback de publication, erreurs de worker, remboursement unique, annulation avant/pendant traitement, suppression différée, sortie invalide/trop grande, timeout coopératif, concurrence d’exécution, CSRF réel, CORS, expiration avant nettoyage, workers disparus et vivants, orphelins et admissions abandonnées.

Trois scénarios démarrent **deux processus PHP distincts** face à une base SQLite temporaire dédiée : dernier créneau propriétaire, dernier créneau global et dernier budget de stockage global. Dans chaque cas, une seule admission et un seul événement/élément de queue sont créés. Les tests ne modifient pas la base locale du projet.

## Limites et risques restants

Pas de test MySQL/MariaDB, de validation Linux/Apache/HTTPS, de charge VPS ou de navigateur réel ; aucune ressource serveur mesurée. Le timeout dur du worker n’est pas validé sous Windows faute de pcntl. Le faux moteur valide les checkpoints, pas l’isolation d’un processus natif lourd. Les futurs moteurs devront appliquer leurs limites pendant l’inspection et l’écriture, interrompre proprement leurs sous-processus et adapter le plafond de sortie 2× si nécessaire.

La stratégie de verrou global et d’inventaire des fichiers orphelins favorise la simplicité sur un petit serveur ; son débit doit être mesuré avant augmentation de charge. Le stockage doit rester local avec `flock` fonctionnel. Le plafond privé ne remplace pas la surveillance de l’espace libre partagé ni celle des fichiers temporaires PHP.

Le scheduler est déclaré dans le code, mais aucun cron/service Windows ou serveur de production n’a été installé. Sans exécution effective du scheduler, les téléchargements expirés restent refusés, mais les octets attendent le nettoyage. Les sessions visiteurs ne donnent accès qu’à leurs propres tâches ; les parcours publics de connexion/inscription restent une phase ultérieure.

## Étape suivante

Phase 3 : évaluer les moteurs image disponibles, intégrer JPEG/PNG/WebP, inspection de dimensions/pixels, compression/redimensionnement et lots sous limites. Aucun format ne doit être activé avant ses tests de conversion réels.
