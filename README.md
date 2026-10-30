# Convert

## Tarifs et abonnements

`/tarifs` présente Gratuit, Pro (3 900 FCFA/mois ou 39 000/an) et Business (9 900/mois ou 99 000/an). Les formules payantes proposent un essai gratuit de 15 jours, une seule fois par compte. `/souscription` affiche les conditions et demande confirmation ; `/mon-abonnement` suit l’essai et les quotas. Paiement non intégré : les demandes restent en attente, sans débit ni activation payante simulée. Appliquer les migrations avant utilisation. Voir le [guide tarifs et abonnements](docs/subscriptions-and-pricing.md) pour l’architecture, les endpoints et les fichiers modifiés.

Socle local d’un SaaS de conversion de fichiers : Laravel 13, React 19, TypeScript, Vite et Tailwind. Interface française avec thèmes clair/sombre.

Les développements locaux des phases 1 à 9 sont livrés : conversions, comptes/administration, plans gratuits, SEO et préparation du déploiement. **Wikimedia Commons est activé sur ce poste uniquement pour les vidéos WebM CC0 vérifiées** ; un clone neuf garde le module externe désactivé. La production reste à valider sur le VPS. Voir les [rapports 6](docs/phase-6-report.md), [7](docs/phase-7-report.md), [8](docs/phase-8-report.md), [9](docs/phase-9-report.md), le [guide Apache](docs/deployment-apache.md) et la [checklist de production](docs/production-checklist.md).

## Démarrer le projet déjà installé

Depuis la racine :

```powershell
php artisan app:dev
```

Ouvrir http://127.0.0.1:8000/outils/convertir-image. `app:dev` (ou `composer dev`) lance le serveur, les deux workers et le scheduler ensemble. Garder le terminal ouvert. Avec Apache/Laragon, lancer les workers dans des terminaux distincts :

```powershell
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

Le nettoyage nécessite le scheduler Laravel (`php artisan schedule:work` en développement) ou l’exécution manuelle de `php artisan conversions:reconcile`.

Pour modifier le frontend avec rechargement automatique, ouvrir un second terminal :

```powershell
npm run dev
```

Continuer à ouvrir le port 8000, pas le port Vite. Vite écoute par défaut sur 5173 uniquement pendant le développement. Si le wrapper npm provoque EPERM sur cette machine, remplacer chaque commande npm par le CLI direct :

```powershell
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' run dev
```

Après arrêt de Vite, si une page cherche encore le serveur de développement, supprimer uniquement le fichier généré `public/hot` s’il existe, puis lancer `npm run build`.

## Installer depuis une copie propre

Prérequis locaux validés : PHP 8.3 avec les extensions Laravel, Composer, Node 22.16 et npm 10.9. Ne pas utiliser de base existante ou de production pour les premières migrations.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
npm ci
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Copier `.env.example` seulement si `.env` n’existe pas déjà. Ne jamais écraser une configuration existante ni régénérer une clé d’application utilisée par des données chiffrées. SQLite est la configuration locale ; queue et sessions utilisent la base. GD avec JPEG/PNG/WebP est nécessaire dans le PHP web et le PHP CLI. Sous Apache, renseigner `CONVERSION_PHP_BINARY` avec le chemin absolu du PHP CLI. Les outils documents nécessitent aussi le Python isolé et LibreOffice, puis une vérification réelle sur la machine :

```powershell
python -m venv .tools/python
.\.tools\python\Scripts\python.exe -m pip install -r requirements-documents.lock.txt
# Préparer LibreOffice et configurer son chemin, puis :
php artisan documents:verify
```

Ces dépendances et leur marqueur de vérification sont déjà préparés sur ce poste. Elles ne sont pas suivies par Git ; refaire l’installation et la vérification sur un autre hôte. Les anciens DOC/XLS/PPT et PDF vers DOCX ne sont pas activés. Les limites et pertes de mise en page sont décrites dans [le guide documents](docs/documents.md).

Pour les médias, préparer FFmpeg et ffprobe, configurer leurs chemins si nécessaire, puis lancer `php artisan media:verify`. Les binaires 9.0.2 et leur empreinte sont déjà vérifiés sur ce poste ; installation, licences et profils dans [le guide médias](docs/media.md). Aucune conversion ne tourne dans le processus web.

`.env`, SQLite, caches, dépendances et assets générés sont ignorés par Git. Conserver `composer.lock` et `package-lock.json`. Aucune clé d’application partagée dans le code. Les migrations de phases 2 et 3 sont appliquées à la base SQLite locale.

## Vérifier

```powershell
php artisan test
php vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
npm run typecheck
npm run lint
npm test
npm run build
```

Les tests PHP utilisent SQLite mémoire et des sessions/cache de test ; les tests de concurrence et de charge média lancent aussi deux processus PHP sur des bases SQLite temporaires dédiées. Les tests de conversion utilisent réellement GD, LibreOffice, PDFium et FFmpeg. Les tests React utilisent jsdom. La validation MySQL et Linux reste à effectuer avant production.

## Organisation

- `resources/js` : pages, composants, client API et tests React.
- `resources/css/app.css` : styles et adaptations responsive.
- `resources/views/app.blade.php` : enveloppe française de l’application React.
- `routes/api.php` et `app/Http/Controllers/ToolController.php` : catalogue et limites sous `/api/v1`.
- `config/conversion.php` : outils prévus et limites configurables.
- `tests/Feature/CatalogTest.php` : catalogue, erreurs, rate limiting et pages.
- `docs` : audit initial, architecture, plan et rapports.

Le pipeline impose les quotas côté serveur ; `/api/v1/me/usage` et `/mes-fichiers` affichent la consommation réelle de la session. Le catalogue annonce uniquement les capacités actives et vérifiées. Les conversions utilisent un disque local dédié `storage/app/conversions`, hors du dossier public et sans service HTTP automatique.

Le worker traite les fichiers en arrière-plan, dans des processus enfants bornés en mémoire et en durée :

```powershell
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

Le nettoyage doit être exécuté par le scheduler Laravel (`php artisan schedule:work` en développement) ou manuellement avec `php artisan conversions:reconcile`. Aucun service Windows ni cron de production n’a été installé. Voir les limites Windows et la configuration des réservations dans [le guide du pipeline](docs/pipeline-operations.md).

## Déploiement ultérieur

Le déploiement cible est Linux/Apache avec `DocumentRoot` sur le dossier `public` et réécriture activée. Ne jamais exposer la racine du dépôt. Frontend et API partagent la même origine. Aucun service distant n’a été publié ni serveur de production modifié.

Avant ouverture publique : `APP_DEBUG=false`, HTTPS, cookies Secure, réglages des proxies de confiance, contrôle des ressources du VPS, tests des workers et du stockage, pages légales complétées et sauvegardes. Le guide de déploiement complet fait partie de la phase 8 ; cette version reste un socle de développement.

## OCR et transcription

Deux outils sont ajoutés et vérifiés sur ce poste : `/outils/ocr-image` (JPG/PNG/WebP vers texte) et `/outils/transcrire-media` (parole audio/vidéo vers texte). 20 langues de transcription et 21 variantes OCR, résultat TXT privé, aperçu et copie. Traitement local, sans API tierce, sur le worker `conversions` existant. Entrées bornées, une reconnaissance en cours au maximum ; relire les résultats.

Tesseract et Whisper.cpp/tiny sont installés uniquement dans `.tools`, avec modèles et empreintes vérifiés. Pour un autre hôte : installer/configurer les moteurs, puis `php artisan recognition:verify`. Le clone n’active aucun format sans vérification réussie. Voir [le guide reconnaissance](docs/recognition.md) et [le rapport](docs/recognition-report.md), notamment les besoins mémoire à mesurer avant activation sur un VPS partagé.

## Comptes, administration, vidéothèque et SEO

Pages : `/inscription`, `/connexion`, `/profil`, `/mes-fichiers`, `/administration` et `/offres`. Créer un compte gratuit, puis attribuer volontairement le premier rôle admin depuis le terminal : `php artisan accounts:admin adresse-du-compte`. Aucun admin/mot de passe par défaut. SMTP réel requis pour la réinitialisation ; un mailer de log est volontairement refusé. Voir [accounts-and-plans.md](docs/accounts-and-plans.md).

Commons : vérifier `php artisan external:verify`, puis configurer l’activation et le User-Agent de contact dans .env ; ils sont déjà préparés pour cette machine. Démarrer le worker séparé dans un autre terminal :

```powershell
php artisan queue:work database --queue=external-downloads --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

Le worker conversions ne traite pas les téléchargements externes. [external.md](docs/external.md) décrit les sources, limites, licences et quotas séparés. La migration de phase 7 est appliquée localement : six migrations au total.

L’indexation reste fermée avec `SEO_INDEXING_ENABLED=false`. Le contrôle non destructif `php artisan app:production-check` liste les éléments manquants pour la production ; son échec est attendu sur ce poste local Windows. Aucun hôte distant n’a été publié ou modifié.
