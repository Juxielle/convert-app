# Audit de l’environnement

Date : 29 octobre 2026. Phase 0 du cahier des charges fourni dans `C:\Users\juxih\Downloads\cahier_des_charges_saas_conversion_codex.md`.

## Projet inspecté

`C:\laragon\www\convert-app` est vide au début de l’audit, y compris les fichiers cachés. Aucun dépôt Git, manifeste Composer/npm, code, route, composant, test ou configuration de déploiement existant. Aucune instruction AGENTS.md trouvée dans le projet ou dans les parents Laragon inspectés. Il s’agit donc d’une création, sans comportement existant à préserver.

## Commandes réellement exécutées

| Vérification | Résultat |
| --- | --- |
| `php -v` | PHP CLI 8.3.16, Windows x64, ZTS |
| `php -m` | Extensions disponibles : bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, PDO, pdo_mysql, pdo_sqlite, session, sodium, tokenizer, xml, zip, notamment |
| `composer --version` | Composer 2.8.9 |
| `node --version` | Node 22.16.0 |
| `npm --version` | Échec EPERM sur `C:\Users\juxih` dans l’environnement restreint |
| Exécution directe du CLI npm ci-dessous | npm 10.9.2, succès |
| `git --version` | Git 2.49.0.windows.1 |
| `git rev-parse --is-inside-work-tree` | Aucun dépôt Git |
| `php -r 'echo json_encode(gd_info(), JSON_PRETTY_PRINT);'` | GD disponible, lecture/écriture JPEG, PNG, WebP, GIF déclarées ; aucune conversion réelle encore testée |
| Recherche via `Get-Command` | mysql, mariadb, httpd, ffmpeg, ffprobe, magick, vips, soffice, qpdf, pdftoppm et redis-server absents du PATH |
| Inspection de `C:\laragon\bin` | Répertoires MySQL 8.4.3, Apache 2.4.62 et Redis 5.0.14.1 présents ; versions indiquées par les noms des dossiers, binaires non exécutés et services non vérifiés |
| `Get-CimInstance` système et mémoire | Accès refusé ; aucune mesure CPU/RAM exploitable |
| `Get-PSDrive C` | Valeurs non exploitables ; espace disponible non établi |

Contournement npm vérifié :

```powershell
& 'C:\Program Files\nodejs\node.exe' 'C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js' --version
```

La présence d’un dossier Laragon ne prouve pas qu’un service est démarré. L’absence du PATH ne prouve pas qu’un logiciel est absent du disque entier.

## Limites de cet audit

Cette machine Windows est un environnement de développement, pas le VPS Linux cible. Aucun accès au VPS, identifiant de base ou domaine de production n’a été fourni. Aucun service installé ou démarré, aucune connexion à une base tentée, aucun secret lu. Aucun test applicatif ni build possible : il n’existe pas encore d’application. Aucune version de Laravel, React ou Vite n’est installée ou validée.

Avant installation, vérifier les versions stables et leurs exigences officielles, puis verrouiller les dépendances. Vérifier également les licences des convertisseurs effectivement retenus. Avant production, mesurer les ressources sur le VPS et inventorier les services partagés.
