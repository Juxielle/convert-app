# Rapport de phase 5 — audio et vidéo

Phase terminée localement le 29 octobre 2026. Quatre outils supplémentaires sont activés après tests réels : conversion audio, extraction audio depuis vidéo, conversion vidéo et compression vidéo. Le catalogue expose quinze outils actifs et conserve le téléchargement externe désactivé.

## Livraison

- `app/Services/Conversions/MediaEngine.php`, `bin/media-worker.py`, `bin/media_limits.py` : exécution FFmpeg/ffprobe hors HTTP, profils et arguments fixes, inspection asynchrone des codecs/pistes/durée, contrôle des ressources et validation avant publication.
- `app/Console/Commands/VerifyMedia.php` : `media:verify` et `--full`, activation conditionnée à une campagne réelle réussie et au marqueur privé d’empreintes.
- `EngineRegistry`, `StoreConversionRequest`, `ToolController`, `ProcessConversion`, `config/conversion.php`, `.env.example`, `bootstrap/app.php` : capacités, limites, erreurs françaises, refus des lots médias, concurrence média propre et progression native conservée entre heartbeats.
- `Pipeline::finish` : après succès et arrêt des processus, réservation du stockage réellement conservé dans le répertoire de tâche, y compris les intermédiaires restants. La réservation maximale reste imposée pendant le traitement. Régressions des quotas existants validées.
- `resources/js/pages/MediaTool.tsx`, `App.tsx`, client API et enveloppe Blade : dépôt unique, formats, profils vidéo, réglages audio, extrait facultatif, quota, progression mesurée, annulation, téléchargement privé et messages d’échec. Copie de disponibilité mise à jour.
- `MediaConversionTest`, `MediaLoadTest`, scripts de fixtures/workers et `media-tool.test.tsx` : conversions natives, limites, annulation, délai et suivi concurrent. `CatalogTest` tient compte des capacités réellement vérifiées.
- README, architecture, plan, contrat API, opérations et risques actualisés ; dépendances, licences et limites détaillées dans [media.md](media.md).

Aucune migration créée ou appliquée en phase 5. Aucun paquet Composer/npm/pip ajouté. FFmpeg/ffprobe 9.0.2 ont été extraits dans `.tools/ffmpeg` après vérification SHA-256 de l’archive officielle Gyan. Aucun changement global de PATH ni service installé. Les binaires et le marqueur ne sont pas suivis par Git. Les cinq migrations applicatives existantes restent inchangées.

## Formats et restrictions

Audio : MP3, WAV PCM 16 bits, AAC, M4A/AAC, FLAC, OGG/Vorbis, Opus. Vidéo : MP4/MOV/MKV en H.264, AVI en MPEG-4, WebM en VP9. Décodeurs d’entrée limités à la liste publiée dans `media.md` ; un conteneur accepté ne garantit pas la compatibilité de tous ses codecs. Extraction audio testée depuis MP4. Les variantes d’entrée/sortie ont été exercées via WAV ou MP4, sans annoncer une validation exhaustive de tous les couples ni des codecs existants.

Entrées : audio 25 Mo/300 s, vidéo 50 Mo/120 s ; le visiteur reste limité à 25 Mo. Full HD, 60 images/s, une piste vidéo et une piste audio mono/stéréo au plus. Sortie 64 Mo, vidéo 720p/30 images/s maximum. Un média en exécution, même si plusieurs workers tournent. Les plafonds du rôle, le timeout, le stockage privé et les policies restent applicables. Les combinaisons audio incompatibles avec un encodeur échouent sans fichier publié. Sous-titres, pistes supplémentaires et codecs non autorisés sont refusés. GIF non activé.

## Vérifications exécutées

| Vérification | Résultat |
| --- | --- |
| `php artisan media:verify --full` | **96 tests PHP réussis, 1 249 assertions**, 147,64 s ; marqueur créé après succès |
| Dont conversions médias | 26 tests, 234 assertions : treize cas audio, neuf cas vidéo, extraction/extrait et trois profils, limites et inventaire codecs |
| Dont charge médias | Trois scénarios, deux workers PHP indépendants sur SQLite temporaire : succès nominal, annulation après progression native, timeout ; au maximum un média actif et suivi HTTP < 1 s |
| `npm test -- --run` via CLI npm direct | **16 tests React réussis** : cinq fichiers, dont deux parcours médias |
| `npm run lint` | Réussi |
| `npm run build` | TypeScript et build Vite réussis |
| `php vendor/bin/pint --test` | Réussi après formatage |
| `composer validate --strict`, `composer check-platform-reqs` | Réussis |

Les compteurs d’assertions de charge peuvent varier avec le nombre d’échantillons pendant l’encodage ; le tableau donne le résultat de la suite complète exécutée. Les tests n’utilisent aucun fichier personnel et ne touchent pas aux données métier locales.

Essai HTTP réel sous session/CSRF sur `127.0.0.1:8015` : WAV → MP3 (33 061 octets) et MP4 → WebM (265 214 octets), admission queued, worker CLI séparé, completed, téléchargement HTTP 200 puis décodage FFmpeg intégral. Les deux tâches synthétiques ont été supprimées via leur session (200). Le catalogue HTTP annonçait quinze outils actifs.

### Mesure locale contrôlée

Un clip synthétique silencieux H.264 **1 920 × 1 080, 60 images/s, 6 s**, 19 279 090 octets, a été soumis par HTTP et converti en MP4 profil équilibré par un worker séparé. Résultat : 422 335 octets, terminé en **2,95 s** avec le démarrage du worker. Pic de working set FFmpeg échantillonné : **107,5 Mio** ; temps CPU FFmpeg échantillonné : **1,56 s**. Sept requêtes HTTP de suivi, latence maximale **349,1 ms**, progression native observée 7/23/54/84 %. La tâche synthétique a ensuite été supprimée (200).

Cette mesure et les deux workers concurrents vérifient la charge nominale locale bornée ; ils ne constituent ni un benchmark du VPS ni un test de stress. Les valeurs CPU/RAM échantillonnées peuvent manquer le tout dernier pic. Les limites OS et la sérialisation restent nécessaires indépendamment de ces chiffres.

## Limites et suite

Les tests ont été exécutés sous Windows/SQLite. Le Job Object, les conversions, l’annulation et le timeout sont exercés localement. Les limites Linux, le fonctionnement sous MySQL, les cgroups/restrictions réseau et le dimensionnement CPU/RAM/disque du VPS partagé restent à valider avant production. Les tests d’interface utilisent jsdom et les essais de transport HTTP ; aucune automatisation dans un vrai navigateur n’a été exécutée.

La compilation FFmpeg locale est GPL v3 ou ultérieure ; ses notices doivent être conservées et les obligations de redistribution respectées. Voir les sources et l’empreinte dans `media.md`. Aucun déploiement, domaine, service système, cron ou serveur de production modifié. Le serveur HTTP temporaire de vérification a été arrêté.

Prochaine étape du cahier des charges : **phase 6 — téléchargement externe**, avec évaluation de sources autorisées, protection SSRF et activation uniquement des sources effectivement validées. Le module reste désactivé à la fin de cette phase 5.
