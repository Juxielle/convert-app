# Audio et vidéo — phase 5

Les quatre outils médias utilisent la queue `conversions` existante : `convertir-audio`, `extraire-audio`, `convertir-video`, `compresser-video`. Aucun encodage ni ffprobe dans le processus HTTP. Un seul fichier par tâche, sans lot. Les mêmes policies, CSRF, quotas et téléchargements privés s’appliquent.

## Dépendances et activation

Version locale : **FFmpeg et ffprobe 9.0.2 essentials, Gyan Windows x64**. Archive téléchargée depuis le fournisseur référencé par [FFmpeg](https://ffmpeg.org/download.html), puis son [miroir GitHub 9.0.2](https://github.com/GyanD/codexffmpeg/releases/tag/9.0.2). L’empreinte de l’archive correspond au SHA-256 publié par [Gyan](https://www.gyan.dev/ffmpeg/builds/) :

```text
60f467265b1e312373dbcd92200c2618a74850f98d3d078e94296bb3fa2047ba
```

Les exécutables sont extraits sous `.tools/ffmpeg/bin`, sans installation système ni modification du PATH. Le Python isolé de la phase 4 est réutilisé ; les scripts médias utilisent uniquement la bibliothèque standard. Aucun nouveau paquet Composer, npm ou pip.

Configurer `CONVERSION_MEDIA_PYTHON`, `CONVERSION_FFMPEG`, `CONVERSION_FFPROBE` si les chemins par défaut ne conviennent pas, puis :

```powershell
php artisan media:verify
# Ou inclure toutes les régressions PHP :
php artisan media:verify --full
```

La commande retire d’abord l’ancien marqueur et exécute les conversions et les tests de workers réels. Elle crée `.tools/media-ready.json` seulement après succès : outils, versions, empreintes des scripts et exécutables, chemins et date. Le catalogue vérifie les empreintes des scripts, les chemins, tailles et dates des exécutables. Remplacer un binaire ou un script impose une nouvelle vérification. `CONVERSION_MEDIA_ENABLED=false` désactive les quatre outils. Le marqueur et les binaires sont privés et exclus de Git ; refaire l’installation et les tests sur chaque hôte. Ne pas copier le marqueur d’une autre machine.

La compilation installée annonce `--enable-gpl --enable-version3` ; `ffmpeg -L` confirme **GPL v3 ou ultérieure**. Conserver `LICENSE`, les informations de compilation et les notices sous `.tools/ffmpeg`. Les bibliothèques incluses possèdent aussi leurs licences. La redistribution de binaires exige le respect des obligations applicables, dont la disponibilité du code source correspondant ; ne pas présenter cette compilation comme LGPL uniquement. Ces exécutables restent des programmes séparés du code applicatif. Aucun paquet redistribuable de l’application n’a été publié.

## Formats effectivement vérifiés

| Outil | Entrées | Sorties |
| --- | --- | --- |
| Conversion audio | MP3, WAV PCM 16 bits, AAC, M4A/AAC, FLAC, OGG/Vorbis, Opus | MP3, WAV, AAC, M4A, FLAC, OGG, Opus |
| Extraction audio | MP4, MOV, MKV, AVI, WebM avec une piste audio compatible | Les sept formats audio ci-dessus |
| Conversion / compression vidéo | MP4, MOV, MKV, AVI, WebM | MP4, MOV, MKV, AVI, WebM |

Chaque entrée audio a été convertie en WAV ; chaque sortie audio a été produite depuis WAV. Chaque entrée vidéo a été convertie en MP4 ; chaque sortie vidéo a été produite depuis MP4 et décodée intégralement. Cela valide ces conteneurs et les codecs décrits, sans promettre tous les codecs possibles dans ces conteneurs ni toutes les combinaisons de fichiers existants.

Décodeurs autorisés : H.264, MPEG-4 Part 2, VP8, VP9 ; MP3, PCM S16LE, AAC, FLAC, Vorbis, Opus. H.265/HEVC, AV1, PCM 24 bits, multicanal et autres codecs sont refusés. Sorties : MP4/MOV/MKV en H.264 + AAC ; AVI en MPEG-4 + MP3 ; WebM en VP9 + Opus. Sorties audio : libmp3lame, PCM S16LE, AAC, FLAC 16 bits, libvorbis et libopus. Inventaire des encodeurs contrôlé par test. GIF animé non activé.

## Réglages et limites

Les options sont des valeurs validées, jamais des arguments FFmpeg libres. Audio : débit 64/128/192/256/320 kbit/s (ignoré pour WAV/FLAC), fréquence 22 050/44 100/48 000 Hz, mono/stéréo. Opus impose 48 kHz. Certaines combinaisons de débit, fréquence et canaux peuvent être refusées par l’encodeur ; la tâche échoue alors sans publier de fichier. Les métadonnées et jaquettes ne sont pas conservées. Une extraction réencode la piste.

Vidéo : profils léger/équilibré/qualité, résolution maximale 360p/480p/720p, 15/24/30 images/s. H.264 : CRF 32/28/23, preset veryfast, plafond de débit 900/1 800/3 000 kbit/s. VP9 : encodeur temps réel, débit 700/1 400/2 200 kbit/s. MPEG-4 : qualité 12/8/4. Audio vidéo : 128 kbit/s stéréo. Aucun agrandissement spatial ; proportions préservées et dimensions paires. Compression avec perte ; le résultat peut être plus gros que la source. Les sous-titres, chapitres, métadonnées et pistes supplémentaires ne sont pas conservés ; les entrées avec sous-titres ou plusieurs pistes audio/vidéo sont refusées dès l’inspection.

`start` en secondes ≥ 0 et `duration` ≥ 0,1 s permettent un extrait ; une durée absente conserve le reste. Le début doit précéder la fin. Les limites portent sur la source entière même si un petit extrait est demandé.

| Limite | Valeur |
| --- | --- |
| Entrée audio | 25 Mo, 300 s |
| Entrée vidéo / extraction | 50 Mo, 120 s |
| Limite du rôle | Visiteur 25 Mo ; les plafonds moteur restent prioritaires pour les comptes |
| Vidéo source | 1 piste, 1 920 px par axe et 2 073 600 pixels, 60 images/s maximum |
| Audio source | 1 piste, 1–2 canaux, ≤ 48 kHz |
| Sortie | 64 Mo, vidéo ≤ 1 280 × 720, 30 images/s |
| Exécution média | Un traitement à la fois, même si le plafond global est relevé |
| Délai | Visiteur 60 s / compte 180 s, selon la configuration du rôle |

Réservation initiale : entrée + 64 Mo. Après succès et arrêt du processus natif, la réservation devient la somme réelle des fichiers privés du répertoire de tâche, y compris d’éventuels intermédiaires restants. Cette règle vaut aussi pour les autres moteurs. En cas d’échec ou annulation, le nettoyage libère les réservations après suppression effective. Les limites d’opérations et la conservation ne changent pas.

## Exécution bornée

Symfony Process lance Python avec `-I`, manifeste serveur sur stdin, puis Python lance FFmpeg/ffprobe avec des tableaux d’arguments et sans shell. Les fichiers d’entrée/sortie portent des chemins privés générés ; aucune URL ni commande client. Protocole autorisé : `file` uniquement ; liste fermée de démultiplexeurs, références externes MOV désactivées. Les listes de lecture et protocoles réseau sont exclus. L’inspection limite durée, pistes, pixels, fréquence et codecs avant transcodage.

FFmpeg utilise un thread de décodage, un thread de filtre et un thread d’encodage vidéo ; allocations natives individuelles limitées à 64 Mio. Windows : Job Object héritant aux enfants, 512 Mio par processus, 768 Mio pour le groupe, quatre processus maximum, destruction des enfants lorsque Python s’arrête. Linux : refus de root, limites mémoire virtuelle 512 Mio et CPU par processus héritées ; ces protections Linux restent à valider sur l’hôte cible. Les limites de service/cgroups et la restriction réseau du compte de conversion sont à configurer et mesurer en phase 8.

Le parent contrôle annulation et délai toutes les 100 ms et arrête le processus enfant avant nettoyage. Le verrou de fichier partagé protège les travaux vivants contre la réconciliation ; les enfants Windows sont rattachés au Job Object. `ffprobe` est borné à 8 s par inspection. Progression issue de `out_time_us / durée demandée`, plafonnée à 99 % jusqu’à validation ; aucun pourcentage simulé. La sortie est reprobée (codecs, durée, dimensions, pistes) avant publication. Les détails natifs ne sont jamais renvoyés à l’API.

## Validation et exploitation

`MediaConversionTest` vérifie conversions, durée, téléchargement, décodage vidéo, profils, extraction, limites et arguments interdits. `MediaLoadTest` lance deux vrais workers PHP sur une base temporaire, observe au maximum un média en cours, une progression native et le suivi HTTP inférieur à une seconde ; il couvre aussi annulation pendant encodage et timeout. Les fixtures sont synthétiques, sans fichier personnel. La charge locale utilise deux clips courts ; elle ne remplace pas les mesures CPU/RAM/disque sur le VPS partagé ni un test navigateur de bout en bout.

Worker et nettoyage : voir [pipeline-operations.md](pipeline-operations.md). Aucune migration, aucun service Windows, cron de production ou déploiement ajouté. Le téléchargement externe reste désactivé ; il relève de la phase 6.
