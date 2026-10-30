# Déploiement Linux / Apache et reprise

Guide préparé en phase 8. **Aucun VPS n’a été modifié et ces exemples ne sont pas encore validés sur votre serveur.** Les tâches locales fonctionnent sous Windows/SQLite. Garder le site fermé à l’indexation et au public tant que les contrôles ci-dessous ne sont pas validés. Les domaines `convert.example`, utilisateurs et chemins sont des exemples à remplacer, pas des valeurs d’exploitation.

## Préparation et prérequis

Inventorier d’abord le VPS partagé : `php -v`, `php -m`, `apache2ctl -v`, `mysql --version`, `free -m`, `df -h`, `nproc`, `systemctl --version`, processus et sites existants. Ne pas modifier leurs ports, utilisateurs ou quotas. Laravel 13 exige PHP ≥ 8.3 et ses extensions ; voir la [documentation de déploiement Laravel](https://laravel.com/framework/docs/deployment). Vérifier `composer check-platform-reqs` sur l’hôte, PHP CLI et PHP-FPM. Extensions : PDO MySQL, GD JPEG/PNG/WebP, ZIP, Fileinfo, XML/DOM, Mbstring, cURL, OpenSSL et celles requises par Composer ; `pcntl`/`posix` pour le worker Linux.

Sur une distribution Debian/Ubuntu compatible, les paquets à adapter sont `apache2`, PHP-FPM/CLI 8.3+, `php-mysql`, `php-gd`, `php-zip`, `php-xml`, `php-mbstring`, `php-curl`, `ffmpeg`, `libreoffice`, `python3`, `python3-venv`, fontes et certificats CA. Ne pas lancer aveuglément une installation ou une mise à niveau sur un serveur existant. FFmpeg et LibreOffice de la distribution doivent être réinventoriés et testés : leurs versions/codecs diffèrent de Windows. Python et les paquets verrouillés doivent être compatibles avec la distribution ; un échec d’installation ou de test impose de garder les moteurs concernés désactivés.

Ports publics : 443 HTTPS, 80 pour redirection et renouvellement ACME. SSH uniquement depuis les adresses d’administration nécessaires. MySQL 3306 reste local ou sur socket, jamais exposé au public. Aucun port Vite, serveur artisan, queue ou Redis public. Node 22.16+ et npm servent au build sur machine de construction ; ils ne sont pas nécessaires pour servir les assets.

## Arborescence et comptes

Préparer manuellement `/srv/convert/releases/<version>`, `/srv/convert/shared/storage`, `/srv/convert/shared/.env`, `/srv/convert/current` (lien vers la release active), et un compte système `convert` sans login interactif. Le compte de déploiement possède les sources, le compte de conversion les lit ; aucune conversion sous root. `storage` est partagé entre releases pour les fichiers privés, logs et sessions fichier éventuelles ; la configuration cible utilise toutefois les sessions en base.

Le déploiement crée les liens `storage` et `.env` de chaque nouvelle release vers les répertoires partagés, après vérification des chemins et absence de données locales à préserver. **Ne pas déplacer ou remplacer un stockage existant sans sauvegarde et examen.** Le groupe `convert` peut écrire uniquement `storage` et `bootstrap/cache` ; l’utilisateur PHP-FPM dédié au site appartient à ce groupe. Répertoires 2770, fichiers privés 0660, umask 0007. `.env` 0640 avec groupe nécessaire. Sources et outils natifs en lecture/exécution, jamais modifiables par HTTP. Pas de `chmod -R 777`, pas de `storage:link` vers le disque `conversions` et pas de DocumentRoot sur la racine Laravel.

## Configuration

Créer la configuration de production hors du dépôt et préserver l’`APP_KEY` existante lors des mises à jour. Générer une clé seulement pour une nouvelle installation. Paramètres attendus :

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://convert.example
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_CONNECTION=mysql
# Hôte local/socket, base et identifiants dédiés renseignés hors Git.
DB_QUEUE_RETRY_AFTER=360
CONVERSION_MAX_RUNNING_JOBS=1
CONVERSION_MEDIA_MAX_RUNNING=1
EXTERNAL_DOWNLOADS_ENABLED=false
SEO_INDEXING_ENABLED=false
CONVERSION_PHP_BINARY=/usr/bin/php
CONVERSION_DOCUMENT_PYTHON=/opt/convert/tools/python/bin/python
CONVERSION_MEDIA_PYTHON=/opt/convert/tools/python/bin/python
CONVERSION_LIBREOFFICE=/usr/bin/libreoffice
CONVERSION_FFMPEG=/usr/bin/ffmpeg
CONVERSION_FFPROBE=/usr/bin/ffprobe
MAIL_MAILER=smtp
MAIL_TIMEOUT=10
```

Configurer aussi SMTP réel, expéditeur, chiffrement et certificats. Aucun secret en ligne de commande ou dans les exemples versionnés. Les mailers log/array/failover sont refusés pour les liens de réinitialisation afin de ne pas journaliser de jeton. Les liens utilisent un fragment navigateur, pas une query transmise aux logs HTTP. Les proxy/load balancers éventuels doivent être explicitement déclarés comme fiables ; ne pas faire confiance à tous les `X-Forwarded-*`. En accès Apache direct, aucun proxy de confiance supplémentaire n’est requis. `APP_URL` définit les canoniques et les liens email, pas le Host fourni par un client.

Régler PHP-FPM `upload_max_filesize=100M`, `post_max_size=110M`, `max_file_uploads=5`, `memory_limit=256M` et un répertoire d’upload temporaire privé surveillé. Les limites du rôle et du moteur sont en octets décimaux et restent plus strictes. `max_execution_time` HTTP raisonnable (30 s) : aucun transcodage dans une requête. Les quotas de disque applicatifs n’incluent pas les temporaires PHP ni les logs système ; garder une réserve disque extérieure au budget de 2 Go initial.

## Préparer une release

Exécuter depuis la nouvelle release, avec le compte de déploiement non privilégié. Avant toute migration, sauvegarder et restaurer un exemplaire de la base dans un environnement de test séparé.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Les tests natifs `documents:verify` et `media:verify` exigent les dépendances de développement, Python et leurs fixtures : les exécuter **avant de retirer les dépendances de développement**, dans la release finale avec sa configuration de test séparée. Ne jamais lancer un `migrate:fresh` contre la base réelle. Installer d’abord `composer install` avec les dépendances de développement dans la release non publique, préparer le venv, exécuter les tests, créer les marqueurs puis lancer `composer install --no-dev`. Configurer les chemins définitifs des binaires avant vérification. Ne pas créer un cache de configuration de production avant les tests PHPUnit (SQLite mémoire/sessions array doivent rester effectifs).

```bash
composer install --prefer-dist
python3 -m venv /opt/convert/tools/python
/opt/convert/tools/python/bin/python -m pip install -r requirements-documents.lock.txt
/opt/convert/tools/python/bin/python -m pip install -r requirements-document-tests.txt
php artisan documents:verify
php artisan media:verify --full
```

Le compte qui vérifie les outils doit pouvoir écrire temporairement dans `.tools` pour les marqueurs ; les rendre ensuite non modifiables par HTTP et par le service. Refaire les vérifications dans le sandbox systemd pour les moteurs avant ouverture : `PrivateTmp`, mémoire, réseau et permissions peuvent changer leur fonctionnement. Ne pas réutiliser les marqueurs Windows. Vérifier `php artisan migrate:status`, les six migrations, puis `php artisan accounts:admin adresse-du-compte` après création volontaire du premier compte. Le rôle est attribué uniquement par terminal et audité ; aucun mot de passe administrateur par défaut.

## Apache, HTTPS et worker

Adapter [apache-convert.conf.example](../deploy/apache-convert.conf.example) dans un nouveau virtual host dédié. Activer les modules rewrite, headers, ssl, proxy_fcgi et HTTP/2 selon l’installation existante, sans changer les autres virtual hosts. Installer un certificat valide, puis `apache2ctl configtest` avant tout rechargement manuel. La racine est `/srv/convert/current/public`. Les journaux d’accès dédiés omettent query strings, cookies, corps, Referer et IP ; appliquer une rotation bornée. Vérifier aussi la journalisation du proxy, du pare-feu/WAF et du fournisseur d’email. Une CSP est émise par Laravel en production ; le script de thème est externe pour éviter `unsafe-inline` sur les scripts.

Adapter [convert-worker.service.example](../deploy/convert-worker.service.example). Limites initiales à mesurer : un worker, CPUQuota 75 % d’un cœur, MemoryMax 1 Go pour le groupe (PHP + outils), TasksMax 64, arrêt de tout le groupe de processus. Ce sont des valeurs prudentes de départ, pas une capacité garantie du VPS. Le service n’accède qu’aux données inscriptibles nécessaires. Le réseau sortant est bloqué sauf loopback : utiliser MySQL local/socket. Avec une base distante, définir son adresse exacte et réévaluer ce contrôle ; ne pas supprimer tout le filtrage par facilité. La disponibilité de `IPAddressDeny` dépend de systemd/cgroups/BPF : vérifier son application effective, compléter par filtrage de compte/conteneur si nécessaire. Le compte de conversion n’a pas besoin de SMTP ni d’Internet.

Après installation explicite par l’exploitant :

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now convert-worker
sudo systemctl status convert-worker
journalctl -u convert-worker --since '10 minutes ago'
```

Le timeout métier est ≤ 180 s, le worker 240 s, l’arrêt du service 260 s et retry_after 360 s. Ne pas inverser cet ordre. Les réglages DB sont relus à chaque tâche ; les changements de code/configuration/exécutables imposent un redémarrage des workers. `php artisan queue:restart` provoque un arrêt propre que systemd relance (`Restart=always`).

## Scheduler, surveillance et sauvegarde

Installer [convert-cron.example](../deploy/convert-cron.example) après adaptation, une seule entrée par installation. `schedule:run` lance la réconciliation minute, la suppression des tokens expirés toutes les 15 minutes et la purge des audits après 90 jours. Tester sur des tâches synthétiques l’expiration, l’annulation et le nettoyage après arrêt/redémarrage du worker. Ne pas purger un répertoire de tâche dont un processus détient encore le verrou.

Surveiller : `systemctl status`, signal worker dans l’administration (expire après 120 s), longueur/ancienneté de queue, statuts failed, espace disque réel, mémoire/CPU du cgroup via `systemctl show convert-worker -p MemoryCurrent -p CPUUsageNSec`, `df -h`, taille des temporaires PHP et des logs. Le dashboard expose la mémoire du PHP de requête, pas la RAM totale du serveur. Alerter avant 80 % de disque et avant épuisement du budget ; arrêter les admissions en désactivant les outils si besoin. Ne jamais afficher les fichiers pour diagnostiquer par défaut.

Sauvegarder uniquement les données utiles : utilisateurs, réglages, audits selon leur rétention et `APP_KEY`/configuration chiffrées séparément ; base complète chiffrée à accès restreint si la procédure de reprise l’exige. Éviter de sauvegarder durablement les conversions éphémères. Les lignes de tâches restaurées sans fichiers doivent être expirées/réconciliées avant réouverture. Exemple MySQL avec fichier d’options protégé (pas de mot de passe dans les arguments) :

```bash
mysqldump --defaults-extra-file=/etc/convert/backup.cnf --single-transaction convert > /srv/backups/convert/database.sql
```

Le répertoire de sauvegarde n’est ni public ni dans le stockage de conversions ; chiffrer et copier vers une destination validée, définir rétention, puis restaurer dans une base temporaire. Le chiffrement, la destination, les clés et le test de restauration doivent être décidés et réalisés par l’exploitant ; une commande de dump seule ne valide pas la sauvegarde.

## Bascule et retour arrière

Après validation du staging, mettre le site en maintenance avec `php artisan down`, laisser les conversions en cours se terminer, arrêter le worker, sauvegarder, appliquer les migrations additives, basculer le lien `current` vers la release vérifiée, recharger PHP-FPM si nécessaire pour l’OPcache, démarrer le worker, tester puis `php artisan up`. Les liens et chemins doivent être vérifiés avant manipulation. Conserver la release précédente et le stockage partagé. Prévoir une page de maintenance accessible au support.

Retour arrière : maintenance, arrêt propre du worker, lien `current` vers l’ancienne release compatible, restauration de ses caches de configuration/vue/routes, redémarrage PHP-FPM/worker, tests HTTP et réouverture. **Pas de `migrate:rollback` automatique** : les anciennes versions 0–5 tolèrent les colonnes et tables ajoutées en phase 7. Une restauration de base n’est effectuée que si nécessaire, depuis une sauvegarde vérifiée, en acceptant explicitement la perte éventuelle des écritures postérieures. Ne jamais changer l’APP_KEY durant un retour arrière.

## Contrôles de sortie

```bash
php artisan app:production-check
php artisan conversions:reconcile
php artisan schedule:list
curl --head https://convert.example/
curl --head https://convert.example/.env
curl --head https://convert.example/storage/private-test
```

Le contrôle automatique doit réussir, puis toute la [checklist](production-checklist.md) doit être validée. Les chemins privés doivent renvoyer 404/403 ; créer uniquement un fichier synthétique contrôlé pour la vérification. Vérifier les cookies Secure/HttpOnly/SameSite, le CSRF, la connexion, les rôles, une conversion réelle de chaque famille, l’expiration, le SMTP réel et la révocation après reset. Les pages légales et contacts doivent être complétés. Activer `SEO_INDEXING_ENABLED=true` uniquement après ouverture voulue et domaine canonique HTTPS validé. Ne pas activer le téléchargement externe sans adaptateur autorisé et testé.

## Option vidéothèque Commons

Une intégration Commons a été vérifiée et activée sur le poste Windows ; ce n’est pas une validation Linux. Sur le VPS, laisser EXTERNAL_DOWNLOADS_ENABLED=false jusqu’au test réel `external:verify` avec le User-Agent de contact, aux contrôles du service séparé et à la validation des droits/politiques. Voir [external.md](external.md). Ne pas ajouter Internet au service de conversion : adapter [convert-external-worker.service.example](../deploy/convert-external-worker.service.example), compte convert-external, queue external-downloads, limites CPU/RAM et egress distincts. Configurer le contact hors Git, vérifier DNS/TLS/CA et les domaines exacts Commons/CDN. Démarrer un seul worker externe ; le plafond global de traitements reste partagé. L’interrupteur de l’administration permet de stopper les transferts sans modifier le réseau ou les domaines.

## Option OCR et transcription

Installer Tesseract avec les modèles fra/eng, Whisper.cpp CPU et le modèle tiny vérifié, puis configurer RECOGNITION_* selon recognition.md. Exécuter `php artisan recognition:verify` comme utilisateur non privilégié avant activation. Les fichiers ne sortent pas de l’hôte et la queue reste conversions ; conserver la restriction réseau du service. Mesurer les allocations natives : la parole emploie un plafond distinct de l’image/média, 1 Gio/processus sous Windows et 1 536 Mio de mémoire virtuelle sous Linux. La MemoryMax de l’exemple systemd est un plafond du groupe ; vérifier son adéquation plutôt que l’augmenter automatiquement sur le VPS partagé. Désactiver RECOGNITION_ENABLED si les ressources sont insuffisantes. Les fixtures exigent DejaVu Sans sur Linux. Les outils restent indisponibles sans marqueur local valide ; ne pas copier les marqueurs Windows.
