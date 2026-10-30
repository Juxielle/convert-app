# Rapport de phase 4 — Documents, PDF et tableaux

Date : 29 octobre 2026. Livraison locale pour les formats effectivement validés, sur le pipeline privé existant.

## Fonctionnalités livrées

Huit outils documents actifs après vérification :

1. DOCX/XLSX/PPTX vers PDF avec LibreOffice.
2. PDF vers images PNG page par page, livrées dans une archive ZIP.
3. Fusion de 2 à 5 PDF dans l’ordre choisi.
4. Extraction d’une plage de pages vers un PDF.
5. Rotation de toutes les pages à 90/180/270 degrés.
6. Compression PDF sans perte des flux, sans promesse de réduction systématique.
7. CSV UTF-8 vers XLSX avec valeurs textuelles et conservation des zéros initiaux.
8. Une feuille XLSX vers CSV UTF-8 ; formules exportées comme texte neutralisé, nombres négatifs conservés.

Le catalogue comporte maintenant 16 fiches : 3 outils images et 8 outils documents actifs dans cet environnement, 5 fiches audio/vidéo/téléchargement encore désactivées. Les formats anciens DOC/XLS/PPT ne sont pas activés ; PDF vers DOCX n’est pas proposé faute de moteur retenu donnant une qualité acceptable. Aucune promesse de reconstruction parfaite Word/Excel depuis un PDF.

L’interface permet la sélection des fichiers, le choix de la plage de pages, rotation, résolution ou feuille, le réordonnancement des PDF à fusionner, le lancement asynchrone et le téléchargement contrôlé. Les erreurs des moteurs restent normalisées et compréhensibles. Les pages d’aide signalent les pertes de mise en page et les limites.

## Dépendances inspectées et préparées

Les outils LibreOffice, qpdf, Poppler et Ghostscript n’étaient pas accessibles dans le PATH ; aucun LibreOffice trouvé dans les Program Files inspectés. Python 3.14.3 était disponible.

Installation isolée des bibliothèques dans `.tools/python` : pypdf 6.21.4, pypdfium2 5.14.0, Pillow 13.0.0, openpyxl 3.1.5, defusedxml 0.7.1, bibliothèques de fixtures et dépendances transitives verrouillées. Aucun paquet ajouté au Python global.

LibreOffice 26.8.1.1 a été téléchargé depuis la source officielle, signature Authenticode valide The Document Foundation, puis extrait avec `msiexec /a` dans `.tools/libreoffice`. Aucune configuration du serveur de production ni association de fichiers modifiée par le projet. Les commandes exactes, chemins, empreinte SHA-256 et licences sont documentés dans `documents.md`. Les binaires et environnements locaux ne sont pas suivis par Git.

## Architecture et protections

`BudgetedEngine` généralise le budget de sortie, sans changer les policies ni la propriété des tâches. Les documents réservent une sortie bornée à 32 Mo, avec une entrée limitée à 10 Mo. La fusion est une seule tâche/opération, préparée dans un staging privé protégé par verrou, compté dans la capacité globale et récupéré après abandon. Chaque utilisateur ne peut télécharger, annuler ou supprimer que ses propres résultats.

Le worker Python ne reçoit que le manifeste généré côté serveur sur stdin. Les arguments de processus et filtres LibreOffice sont fixes. Chaque conversion Office a son profil privé avec macros et mise à jour de documents désactivées. Les ressources externes liées, objets actifs, archives protégées, DTD/entités et cas de décompression excessive sont refusés. Les documents endommagés ou trop complexes ne publient aucun résultat.

Limites initiales : 50 pages, 20 mégapixels cumulés au rendu PDF, 10 000 lignes/100 colonnes, 1000 entrées d’archive et 32 Mo décompressés. Des filtres bornent les décompressions PDF. Le processus parent contrôle délai, annulation et espace temporaire ; le worker Python limite ses écritures. Windows utilise un Job Object avec mémoire/processus bornés et arrêt des descendants ; les fichiers restent verrouillés jusqu’à la fin du processus. Les sockets Python sont bloqués. Le filtrage Office ne remplace pas une politique réseau OS pour le processus natif en production.

`documents:verify` désactive d’abord les outils documents, exécute leurs tests réels avec base SQLite mémoire, puis crée un marqueur privé uniquement en cas de succès. Le catalogue contrôle l’empreinte du worker, le verrou de dépendances et les chemins configurés. `--full` exécute toute la suite PHP avant activation. Une nouvelle machine doit préparer ses dépendances et refaire la vérification.

## Fichiers créés

- `app/Services/Conversions/BudgetedEngine.php`, `DocumentEngine.php`.
- `bin/document-worker.py`.
- `app/Console/Commands/VerifyDocuments.php`.
- `requirements-documents.txt`, `requirements-document-tests.txt`, `requirements-documents.lock.txt`.
- `resources/js/pages/DocumentTool.tsx`, `resources/js/tests/document-tool.test.tsx`.
- `tests/Feature/DocumentConversionTest.php`, `tests/Support/document-fixtures.py`.
- `pint.json` pour exclure explicitement les outils et dépendances tiers du formatage.
- `docs/documents.md` et ce rapport.

## Fichiers modifiés

Backend : `app/Services/Conversions/{EngineRegistry,ImageEngine,Pipeline}.php`, `app/Http/Requests/StoreConversionRequest.php`, `app/Http/Controllers/{ConversionController,ToolController}.php`, `app/Jobs/ProcessConversion.php`, `app/Console/Commands/ReconcileConversions.php`, `config/conversion.php`, `bootstrap/app.php`, `.env.example`, `.gitignore`.

Frontend : `resources/js/{App.tsx,lib/api.ts,lib/content.ts,pages/Activity.tsx}` et assets compilés ignorés dans `public/build`.

Tests/documentation : `tests/Feature/{CatalogTest,PipelineTest}.php`, `README.md`, `docs/{architecture,implementation-plan,api,pipeline-operations,risks}.md`.

**Aucune nouvelle migration nécessaire.** `migrate:status` confirme les cinq migrations précédentes appliquées. Aucune donnée existante supprimée ; les tâches synthétiques de vérification HTTP ont été supprimées par leur propre session et leurs événements de quota conservés normalement.

## Commandes et résultats

| Vérification réellement exécutée | Résultat |
| --- | --- |
| Inventaire des commandes et dossiers de logiciels | Outils natifs initialement absents des emplacements inspectés |
| `python -m venv .tools/python` et pip isolé | Dépendances installées puis verrouillées |
| Téléchargement officiel, `Get-AuthenticodeSignature`, `Get-FileHash`, extraction `msiexec /a` | Signature valide, extraction terminée avec code 0 |
| `soffice.com --version` | LibreOffice 26.8.1.1 |
| `python -m pip check` dans le venv | Aucune dépendance incohérente |
| Compilation Python avec `py_compile` | Réussie |
| `php artisan documents:verify --full` | **67 tests PHP réussis, 561 assertions**, huit outils documents activés |
| Contrôle ciblé de la fusion après ajustement du nom téléchargé | Réussi, 4 assertions |
| `npm test` via CLI direct | **14 tests frontend réussis** |
| `npm run lint` via CLI direct | Réussi |
| `npm run build` via CLI direct | TypeScript strict et Vite réussis ; JS final 445,63 Ko / 142,41 Ko gzip |
| `php vendor/bin/pint`, puis contrôle `--test` | Formatage et contrôle réussis |
| `composer validate --strict` | Réussi |
| `php artisan migrate:status` | Cinq migrations déjà appliquées, aucune nouvelle |
| `php artisan conversions:reconcile` | Réussie |

Les téléchargements nécessaires ont été exécutés après autorisation de l’accès réseau. Aucun service global ni dépendance Composer/npm supplémentaire n’a été ajouté.

Les tests couvrent les trois formats Office modernes vers PDF avec extraction du texte attendu, pages/dimensions des sorties, rendu PNG, fusion et ordre des pages, extraction, rotation, compression réelle, échanges CSV/XLSX, neutralisation des formules, nombres négatifs et zéros initiaux. Ils contrôlent aussi : PDF chiffré, PDF malformé, trop de pages, plage invalide, archive à forte expansion, taille d’entrée, limites de cellules/pixels, ressources Office externes, refus de fusion sans consommation ni staging résiduel et nettoyage d’un staging abandonné sans toucher au travail vivant.

Les premiers essais ont révélé des différences Windows (retours de ligne, verrou de fichier temporaire ZIP), un objet d’options vide à normaliser et une course entre surveillance et suppression du profil LibreOffice. Ces points ont été corrigés ; la surveillance tolère les fichiers temporaires disparus et relit leurs tailles courantes. Aucun échec laissé dans les contrôles finaux.

### Parcours HTTP réels

Depuis une session Laravel et un jeton CSRF, sur le serveur local :

- Catalogue : **8 outils documents actifs**.
- CSV→XLSX : POST multipart accepté en queued, consommation par `queue:work --once`, résultat completed de **4900 octets**, téléchargement, valeur `0012` vérifiée et suppression en 200.
- DOCX→PDF : même parcours, résultat completed de **35 483 octets**, téléchargement, texte « Bonjour » extrait et suppression en 200.
- Le PDF téléchargé a été rendu en PNG et inspecté visuellement : titre et texte lisibles, accents conservés, mise en page simple cohérente.

Les contrôles frontend sous jsdom vérifient l’envoi de la plage de pages, le réordonnancement des fichiers à fusionner et les téléchargements privés. Aucun navigateur automatisé n’était connecté : pas de validation visuelle du site mobile/desktop pendant cette phase.

## Limites et prochaine étape

DOC/XLS/PPT anciens, PDF→DOCX, OCR et restitution parfaite des mises en page ne sont pas activés. Certains médias intégrés Office et documents actifs sont volontairement refusés. La compression PDF peut ne pas réduire le poids ; l’export CSV ne calcule pas les formules. Les polices et documents complexes nécessitent une validation métier complémentaire.

MySQL, Linux/Apache/HTTPS, restrictions réseau du service natif, charge et ressources du VPS restent à vérifier avant production. Aucun déploiement de production, cron système ou service worker n’a été installé. Les dépendances locales Windows doivent être remplacées/configurées et revérifiées sur l’hôte cible.

Prochaine phase : audio/vidéo via FFmpeg, après inventaire de ffmpeg/ffprobe et des codecs disponibles.
