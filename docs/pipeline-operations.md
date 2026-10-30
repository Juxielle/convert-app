# Exploitation locale du pipeline

Depuis la phase 3, le registre public active les outils images JPEG/PNG/WebP lorsqu’ils sont disponibles. `FakeEngine` et son registre ne résident que dans `tests/` et ne sont jamais enregistrés en production. Voir `images.md` pour les limites, options et commandes du moteur réel.

La phase 4 ajoute huit outils documents/PDF/tableurs, activés après `php artisan documents:verify`. Voir `documents.md` pour Python/LibreOffice, les formats, les licences, les limites et le marqueur de vérification. Les mêmes commandes de queue et de nettoyage traitent les images et les documents.

## Démarrage

```powershell
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

L’interface de session se trouve à `/mes-fichiers`. Pour traiter les images, démarrer un terminal indépendant :

```powershell
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

Conserver un seul worker initialement. Un job réserve au plus 180 secondes de conversion ; le worker est borné à 240 secondes, `retry_after` à 360 secondes. Ces durées respectent [l’ordre recommandé par Laravel](https://laravel.com/framework/docs/queues). La réconciliation considère un heartbeat ancien après 300 secondes, mais refuse de supprimer les fichiers tant qu’un processus détient leur verrou local. Un moteur natif devra ajouter ses propres limites de processus et une interruption de l’arbre des processus.

Windows ne dispose pas ici de pcntl : le timeout dur Laravel n’est pas validé sur cet OS. Le moteur image utilise un processus PHP enfant dont le délai est imposé par Symfony Process, avec arrêt lors d’une annulation. Cela ne remplace pas les limites OS et le service Linux supervisé sous utilisateur non privilégié à valider avant production. Le disque doit rester **local**, avec `flock` fonctionnel ; pas de stockage réseau pour cette stratégie.

La queue est forcée sur la connexion `database` partageant exactement la même connexion SQL que les modèles. `DB_QUEUE_CONNECTION` doit être absent ou désigner la même connexion que `DB_CONNECTION`. Garder `queue.connections.database.after_commit=false` : les insertions transactionnelles dans la même base sont volontairement visibles seulement au commit. Les drivers sync, Redis ou une seconde base nécessitent une autre stratégie de publication avant utilisation.

## Nettoyage

```powershell
php artisan conversions:reconcile
php artisan schedule:list
```

La commande est planifiée chaque minute, sans chevauchement. Pour un poste de développement, lancer `php artisan schedule:work` dans un terminal. Rien n’a été installé dans le planificateur Windows ou le cron du VPS. En production, configurer explicitement le cron Laravel et la supervision des workers.

La commande :

- Refuse de toucher aux tâches processing ayant un heartbeat récent ou un verrou de processus détenu.
- Marque les workers disparus en échec et rembourse leur opération ; annule les demandes d’annulation en attente.
- Marque les admissions en attente depuis 30 minutes comme abandonnées et rembourse leur opération.
- Révoque et supprime les résultats expirés ; retire les lignes de queue non encore réservées.
- Réessaie les suppressions ayant échoué, sans libérer leur réservation de stockage prématurément.
- Supprime les répertoires UUID orphelins après dix minutes ; conserve les fichiers plus récents.
- Purge les métadonnées terminées à un jour pour les visiteurs / 30 jours pour les comptes et les usages après 31 jours. Une suppression manuelle ne réinitialise jamais le quota.

Les verrous de processus restent dans un répertoire privé distinct des fichiers et sont purgés après un jour si leur tâche n’existe plus et qu’aucun processus ne les détient. Les résultats ne sont jamais accessibles via `/storage`.

## Quotas

Fenêtres **glissantes** d’une heure et de 24 heures. Limites visiteurs : 25 Mo, 5/h, 10/24 h, un créneau actif ; comptes : 100 Mo, 20/h, 50/24 h, deux créneaux. Un lot partage un créneau, mais chaque image compte comme une opération et réserve son stockage. Un Mo = 1 000 000 octets. Le moteur factice réserve 2× l’entrée en sortie ; le moteur image réserve selon les pixels avec une marge et un plafond, pour permettre notamment JPEG vers PNG. Voir `images.md`.

Valeurs initiales configurables : 20 admissions actives globales, un traitement en cours global, 2 Go de stockage global, 100 Mo réservables par visiteur, 400 Mo par compte. Les fichiers orphelins consomment aussi le plafond global. Pendant le traitement, les réservations couvrent l’entrée et le plafond de sortie. Après succès et arrêt des processus natifs, elles correspondent à tous les fichiers réellement conservés dans le répertoire privé de la tâche (entrée, sortie et éventuels intermédiaires). Leur libération totale exige la suppression effective. Ces limites ne remplacent pas une surveillance de l’espace disque partagé du VPS et des fichiers temporaires PHP.

Les erreurs internes ENGINE_FAILED, TOOL_UNAVAILABLE, INVALID_OUTPUT, STORAGE_FAILED, WORKER_FAILED, WORKER_LOST et QUEUE_ABANDONED remboursent l’opération une seule fois. Une annulation, un fichier structurellement invalide admis ou un timeout de conversion ne la remboursent pas. Les erreurs avant admission ne créent aucun événement. Le rate limiting reste applicable même en cas de remboursement.

## Vérifications avant les moteurs réels

```powershell
php artisan test
php vendor/bin/pint --test
npm test
npm run lint
npm run build
```

Les tests de concurrence lancent deux processus PHP sur une base SQLite **temporaire dédiée**. Ils ne touchent pas `database/database.sqlite`. Avant production, répéter les tests métier sur une base MySQL de test dédiée et mesurer les limites sous Linux. Configurer aussi `upload_max_filesize`, `post_max_size`, les limites Apache et le répertoire temporaire PHP selon les tailles réellement autorisées. Aucun secret de production n’est nécessaire pour ces tests.

## Médias (phase 5)

Même queue et mêmes commandes de worker/nettoyage. `CONVERSION_MEDIA_MAX_RUNNING=1` limite aussi les médias si plusieurs workers ou un plafond global supérieur sont utilisés. Les fichiers audio/vidéo ne sont jamais traités en HTTP. Vérifier les exécutables avec `php artisan media:verify` après installation ou modification. Désactivation : `CONVERSION_MEDIA_ENABLED=false`, puis rechargement de la configuration et redémarrage des workers persistants. Les tâches encore en queue peuvent alors échouer sans résultat ; les fichiers déjà terminés restent soumis à leur conservation et aux policies. Voir [media.md](media.md) pour les limites natives et les tests locaux.

## Sources externes et comptes (phases 6–9)

Les téléchargements Commons utilisent la queue external-downloads et des événements social_download exclus des compteurs de conversion. Réservations de stockage et créneaux actifs restent communs. Même disque privé/policies/nettoyage, nouvelle entrée sociale sans upload de manifeste client. Voir external.md. Les plans et limites dynamiques sont contrôlés par Settings/PlanCatalog, les audits admin conservés 90 jours et les resets expirés nettoyés par le scheduler. Le rôle admin n’accorde pas le téléchargement des fichiers tiers.

## OCR et transcription

La queue conversions traite aussi ocr-image/transcrire-media. Un fichier, une opération, mêmes policies/retenues et nettoyage. Un créneau d’exécution dédié commun aux deux reconnaissances complète le plafond global. Les intermédiaires privés sont inclus dans la réservation maximale de 40 Mo ; après succès, seules l’entrée et la sortie TXT demeurent. Annulation et délai arrêtent l’enfant avant purge. Configurer/vérifier les moteurs via recognition:verify ; pas de modèle téléchargé pendant une requête. Voir recognition.md pour le budget mémoire et les chemins Linux.
