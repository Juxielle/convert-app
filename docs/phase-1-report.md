# Rapport de phase 1 — Socle applicatif

Date : 29 octobre 2026. Périmètre : prompt 1 du cahier des charges. Aucun convertisseur, upload métier, compte public ou administration activé. Les phases suivantes restent indépendantes.

## Livraison

Laravel 13.39.0 et PHP 8.3.16 ; React 19.3.1, TypeScript 5.9.3, Vite 7.3.7, Tailwind 4.3.3, React Router 7.18.5, TanStack Query 5.105.0, Radix Select et Lucide. Versions installées consignées dans `composer.lock` et `package-lock.json`.

Compatibilité vérifiée avant installation : [Laravel 13 exige PHP 8.3](https://laravel.com/framework/docs/releases) ; [Vite documente Node 22.12+](https://vite.dev/guide/), compatible avec Node 22.16.0 local. L’installation utilise des dépendances compatibles résolues, sans mise à jour globale de PHP ou Node.

Interface française aux tons verts et ivoire : accueil, catalogue filtrable/recherchable, page d’outil, FAQ, confidentialité, conditions et mentions légales provisoires. Thèmes clair par défaut et sombre mémorisé ; navigation mobile, lien d’évitement, focus visible, labels accessibles et préférence de mouvement réduit. Illustration réalisée en CSS, sans ressource distante.

Composants réutilisables : navigation/pied de page, carte d’outil, dépôt, sélection de format Radix, état de tâche avec progression seulement mesurée, notification, chargement/erreur et quota prévu. Les outils sont tous inactifs, le dépôt et le lancement désactivés, les formats envisagés séparés des capacités réellement disponibles. Aucun chiffre de consommation inventé.

API : catalogue, détail et limites sous `/api/v1`, erreur JSON française homogène, limitation de fréquence, absence de fuite de traces. Les chemins API inconnus ne servent pas le frontend. Stockage local privé avec service HTTP automatique désactivé. Sessions Laravel et middleware CSRF standard conservés pour les pages ; les mutations sous session seront ajoutées avec leurs tests dans les prochaines phases. En-têtes nosniff, SAMEORIGIN et referrer-policy pour les réponses Laravel.

## Fichiers concernés

- Socle Laravel généré : `artisan`, `app/Models/User.php`, `bootstrap/`, `config/`, `database/`, `public/index.php`, `public/.htaccess`, `routes/console.php`, `storage/` et infrastructure `tests/`.
- Backend spécifique : `config/conversion.php`, `app/Http/Controllers/ToolController.php`, `app/Http/Middleware/SecurityHeaders.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `config/filesystems.php`.
- Frontend : `resources/views/app.blade.php`, `resources/js/main.tsx`, `resources/js/App.tsx`, `resources/js/lib/{api.ts,content.ts}`, `resources/js/components/{Layout.tsx,ui.tsx}`, `resources/css/app.css`, `public/favicon.svg`.
- Outils : `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js`, `.gitignore`, `.env.example`, `.env` local non versionné.
- Tests : `tests/Feature/CatalogTest.php`, adaptation de `tests/Feature/ExampleTest.php`, `resources/js/tests/{setup.ts,app.test.tsx}`.
- Documentation : `README.md`, `docs/api.md`, ce rapport, mise à jour de l’architecture et du plan. Les documents d’audit de phase 0 sont conservés comme photographie initiale.

Les entrées générées `vite.config.js`, `resources/js/app.js` et `bootstrap.js` ont été remplacées par l’entrée React TypeScript. L’ancien `app.js` entrait en collision avec `App.tsx` sur Windows et importait Axios, inutile avec le client fetch retenu. Le script Composer dev démarre seulement Laravel ; Vite se lance dans un second terminal. Aucun worker sans limite de durée n’est lancé par ce socle.

## Migrations

Le script officiel de création Laravel a exécuté sur une **nouvelle base SQLite locale** `database/database.sqlite` : `0001_01_01_000000_create_users_table`, `0001_01_01_000001_create_cache_table`, `0001_01_01_000002_create_jobs_table`. `php artisan migrate:status` les confirme appliquées. Aucune migration métier et aucune base existante ou de production modifiée. PHPUnit utilise une configuration de test distincte, SQLite mémoire et sessions/cache en mémoire.

## Commandes et vérifications exécutées

Le premier téléchargement Composer a échoué dans le sandbox réseau. Le téléchargement autorisé ensuite a réussi. Le wrapper npm échoue toujours avec EPERM ; le CLI direct fonctionne :

```powershell
$env:COMPOSER_CACHE_DIR = 'C:\laragon\www\convert-app\.cache\composer'
composer create-project laravel/laravel .scaffold '^13.0' --no-interaction --prefer-dist
$env:npm_config_cache = 'C:\laragon\www\convert-app\.cache\npm'
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' install --no-fund --no-audit
```

Le socle a ensuite été déplacé à la racine sans écraser `docs/` ni les fichiers frontend préparés. Les commandes npm du tableau ont été exécutées via le même CLI direct.

| Commande / vérification | Résultat |
| --- | --- |
| `php artisan test` | 9 tests réussis, 203 assertions, dont 7 tests spécifiques API/pages et 2 tests du squelette |
| `npm test` | 8 tests frontend réussis sous jsdom |
| `npm run lint` | ESLint sans erreur après correction |
| `npm run build` | TypeScript strict et compilation Vite réussis |
| `php vendor/bin/pint` | Formatage PHP corrigé |
| `composer validate --strict` | Manifeste valide |
| `composer check-platform-reqs` | PHP/extensions compatibles avec les dépendances installées |
| `php artisan route:list --path=api` | Trois endpoints catalogue/limites |
| `php artisan migrate:status` | Trois migrations standard appliquées |
| `npm ls --depth=0` | Arbre des dépendances directes installé |
| `php artisan serve --host=127.0.0.1 --port=8000` et requêtes HTTP | Sept pages publiques, deux endpoints et deux assets compilés répondent 200 ; types MIME attendus, en-tête nosniff sur les réponses Laravel |

Les premières exécutions ont identifié puis permis de corriger : collision Windows d’entrée JS, type de requête de test, réponse de rate limiting transformée en 500 par le renderer, service automatique du stockage privé, et formatage/lint. Aucun échec connu laissé dans les contrôles terminés.

## Limites et suite

Pas de navigateur automatisé connecté : pas de capture, test visuel réel mobile/desktop ou audit complet d’accessibilité. Les tests DOM ne remplacent pas cette validation. Ni MySQL, Apache, HTTPS, VPS, charge, conversion réelle ou sécurité du pipeline futur testés. Le téléchargement externe reste désactivé. Les pages juridiques doivent être complétées par l’exploitant avant publication. L’installateur signale ESLint 9 déprécié ; migration à planifier avec ses plugins, sans incidence observée sur le lint exécuté.

La phase 2 devra implémenter stockage privé, migrations métier, réservation atomique des quotas, jobs asynchrones, propriété visiteur/compte, annulation, téléchargement et nettoyage, puis valider le pipeline avec un moteur réservé aux tests avant toute conversion réelle.
