# OCR d’image et transcription audio/vidéo

Deux outils ajoutés : `/outils/ocr-image` et `/outils/transcrire-media`. Ils réutilisent le pipeline de fichiers privé, les quotas du rôle, les policies et la queue **conversions**. Aucun appel à une API de reconnaissance distante, aucun compte fournisseur ni coût de transcription par requête. Les fichiers restent sur l’hôte qui exécute l’application.

## Fonctionnement

| Outil | Entrées vérifiées | Réglages | Résultat |
| --- | --- | --- | --- |
| OCR | JPG/JPEG, PNG, WebP fixes | 21 variantes OCR, dont chinois simplifié/traditionnel ; français + anglais combinés ; page automatique, bloc ou texte dispersé | TXT UTF-8 |
| Transcription | WAV PCM 16 bits, MP3, AAC, M4A/AAC, FLAC, OGG/Vorbis, Opus ; MP4/MOV/MKV H.264, AVI MPEG-4, WebM VP8/VP9 | Détection automatique ou choix parmi 20 langues | TXT UTF-8, sans traduction ni identification des locuteurs |

L’OCR est destiné au texte imprimé net. Manuscrits, faible contraste, tableaux, orientation et mises en page complexes peuvent dégrader le résultat ; la mise en page n’est pas conservée. La transcription emploie un petit modèle pour limiter le coût CPU : bruit, musique, accents et voix superposées peuvent entraîner omissions, erreurs ou hallucinations. La détection de silence numérique empêche un résultat sur une piste vide, mais n’est pas un détecteur fiable de parole dans tout bruit. **Toujours relire le texte.** Aucun taux de précision universel revendiqué.

Après succès : téléchargement privé TXT, affichage à la demande dans un textarea, copie manuelle ou via presse-papiers. Le texte n’est jamais injecté comme HTML. Le téléchargement et l’aperçu passent par la même policy et expirent ensemble. Le texte n’est pas exposé dans la liste de tâches ni stocké en base ; il reste un fichier privé soumis à la rétention du rôle. L’affichage local déjà récupéré n’est pas effacé du navigateur à distance lors de l’expiration ; la suppression/expiration révoque les récupérations suivantes.

## Limites

- OCR : 10 Mo, 6 mégapixels, 8 192 px par axe maximum ; animations refusées. Les plafonds inférieurs du rôle restent prioritaires.
- Audio/vidéo : 25 Mo, 60 s pour la source entière et sa piste décodée ; une piste audio mono/stéréo ≤ 48 kHz ; vidéo ≤ 1 920 px par axe, 2 073 600 pixels et 60 images/s. Les décodeurs restent limités à ceux vérifiés du moteur média ; pas de H.265/AV1, PCM 24 bits ou sous-titres/pistes multiples.
- Une tâche OCR/transcription en cours maximum, même si le plafond global est relevé. Le plafond global reste un par défaut sur le VPS partagé. Une opération de quota par fichier, sans lot.
- TXT ≤ 1 Mo, espace intermédiaire réservé 40 Mo + entrée. La réservation revient aux octets réellement conservés après succès. L’image normalisée et le WAV temporaire sont supprimés avant succès ; échec/annulation nettoient le répertoire via le pipeline.
- Délai métier du rôle : 60 s visiteur / 180 s compte par défaut, annulation et contrôles réguliers. Pas de pourcentage ni estimation inventés : l’interface affiche l’état de traitement.

Le WAV est normalisé localement en mono PCM 16 bits / 16 kHz, plafonné à environ 2 Mo et borné en durée avant Whisper. Une vidéo sans audio est refusée. L’original n’est ni traduit ni envoyé à un service tiers. Pour OCR, Pillow applique l’orientation EXIF, compose la transparence sur blanc et réencode une image PNG grise sans métadonnées avant Tesseract.

## Moteurs, versions et provenance

**Tesseract 5.5.3.20260724**, distribution Windows UB Mannheim référencée par [tessdoc](https://tesseract-ocr.github.io/tessdoc/Downloads). Archive obtenue depuis la [release officielle 5.5.3](https://github.com/tesseract-ocr/tesseract/releases/tag/5.5.3) ; SHA-256 comparé au digest publié par GitHub :

```text
bee9e3434bd94fd65387d9be28cd467a41f61b1275383b55b0f59a1331270ae4
```

L’installateur a été décompressé dans `.tools/tesseract` avec un extracteur local : aucun Tesseract installé globalement, aucun PATH modifié. Les fichiers de langues `fra` et `eng` proviennent de [tessdata_fast](https://github.com/tesseract-ocr/tessdata_fast), empreintes conservées par la vérification. Tesseract et ses modèles sont sous Apache 2.0 ; les bibliothèques embarquées conservent leurs propres licences. La licence principale est conservée dans `.tools/tesseract/LICENSE-APACHE-2.txt`.

**Whisper.cpp b5454 / version 1.9.5**, binaire Windows CPU x64 du [dépôt ggml-org](https://github.com/ggml-org/whisper.cpp/releases/tag/b5454), commit correspondant à v1.9.5. SHA-256 de l’archive comparé au digest GitHub :

```text
6ba69e3482d7826214f90a6a9c84ca07782aec1e1d0c6a7c30c994fd5d816ccb
```

Modèle **tiny multilingue** `ggml-tiny.bin` (77 691 713 octets) depuis [ggerganov/whisper.cpp](https://huggingface.co/ggerganov/whisper.cpp). SHA-256 comparé à l’objet LFS publié :

```text
be07e048e1e599ad46341c8d2a135645097a538221678b7acdd1b1919c6e1b21
```

Whisper.cpp est sous MIT ; licence conservée dans `.tools/whisper/LICENSE`. Aucun nouveau paquet npm/Composer/pip ajouté : Pillow et le Python isolé de la phase documents, FFmpeg et ffprobe déjà vérifiés sont réutilisés. Les moteurs/modèles et l’extracteur sont ignorés par Git ; les fixtures de voix sont documentées dans `tests/Fixtures/recognition/README.md`.

## Exécution et ressources

Symfony Process lance `python -I bin/recognition-worker.py`, manifeste construit côté serveur sur stdin et arguments séparés. Aucun shell, URL, modèle, prompt ou paramètre natif libre fourni par l’utilisateur. Les noms d’entrée/sortie sont générés par le pipeline. Le décodage complet et l’inférence restent hors HTTP. FFmpeg conserve sa liste fermée de démultiplexeurs, le protocole file uniquement et les références MOV externes désactivées.

Un thread de calcul et un processus d’inférence. Windows : OCR réutilise la limite 512 Mio/processus et 768 Mio/groupe ; la transcription utilise un Job Object dédié, 1 Gio/processus et 1 280 Mio/groupe, quatre processus maximum, destruction des enfants à l’arrêt de Python. Ce budget supérieur est nécessaire au commit mémoire natif, même si le working set est inférieur. Mesure ponctuelle du CLI sur le JFK de 11 s : environ 162,5 Mio de working set échantillonné et 653,1 Mio de mémoire privée ; ce n’est pas un dimensionnement du VPS ni un maximum garanti.

Linux : refus de root, mémoire virtuelle 512 Mio pour OCR / 1 536 Mio pour parole et budget CPU hérité par processus. Arrêt de l’enfant natif sur SIGTERM, délai du parent et verrou partagé de fichiers pour protéger le nettoyage. **Les limites Linux et la charge réelle restent à vérifier sur le VPS**. Le service système peut tuer le groupe si sa MemoryMax est insuffisante ; ne pas relever ses limites sur un serveur partagé sans mesure. Désactiver RECOGNITION_ENABLED si la mémoire n’est pas disponible.

## Installation sur un autre hôte

Configurer les chemins absolus dans `.env`, sans ajouter de secret au dépôt :

```dotenv
RECOGNITION_ENABLED=true
RECOGNITION_PYTHON=/opt/convert/tools/python/bin/python
RECOGNITION_TESSERACT=/usr/bin/tesseract
RECOGNITION_TESSDATA=/usr/share/tesseract-ocr/5/tessdata
RECOGNITION_WHISPER=/opt/convert/tools/whisper/whisper-cli
RECOGNITION_MODEL=/opt/convert/tools/whisper/ggml-tiny.bin
```

Installer Tesseract avec données française/anglaise, une build CPU compatible de Whisper.cpp et le modèle vérifié, Pillow/FFmpeg, puis :

```bash
php artisan recognition:verify
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

La vérification supprime l’ancien marqueur, exécute les tests natifs et active les deux outils uniquement après succès. `.tools/recognition-ready.json` conserve empreintes des scripts, chemins, tailles/dates et hashes des exécutables/modèles. Le catalogue compare les scripts et identités des assets ; une modification impose une nouvelle vérification. Réexécuter depuis l’hôte cible, pas en copiant le marqueur Windows. L’administrateur peut désactiver les deux outils comme les autres conversions.

Aucune migration nécessaire. Les limites sont dans `config/recognition.php`. API : POST `/api/v1/conversions`, tool=`ocr-image` ou `transcrire-media`, fichier unique, target_format=`txt`, options language et layout (OCR seulement). Options et formats inconnus refusés. Codes utiles : NO_TEXT_FOUND, NO_SPEECH, MISSING_MEDIA_STREAM, MEDIA_TOO_COMPLEX, INVALID_IMAGE, INVALID_MEDIA, PROCESS_TIMEOUT. L’indexation/sitemap et les réglages admin reprennent automatiquement les capacités effectivement vérifiées.

## Extension multilingue

Le catalogue de référence unique est `config/recognition-languages.json`. Il relie code public, libellé français, code Tesseract et code Whisper. PHP valide les options à partir de cette liste et l’expose dans `Tool.recognition_languages` ; Python charge le même fichier. React affiche ces options sans maintenir une deuxième liste de langues. Aucun code/modèle arbitraire n’est accepté depuis le navigateur.

Sélection explicite de vingt langues pour la parole : Français, Anglais, Espagnol, Portugais, Allemand, Italien, Arabe, Chinois simplifié (mandarin à l’oral), Hindi, Russe, Japonais, Coréen, Bengali, Indonésien, Turc, Vietnamien, Ourdou, Pendjabi, Persan, Swahili. Le mandarin utilise zh ; les caractères chinois traditionnels disposent d’un modèle OCR distinct zh-tw, non d’un second code oral. L’OCR propose donc vingt-et-une variantes, plus le réglage combiné français/anglais. Pour une image, choisir la langue du document : il n’y a pas de détection de langue OCR automatique. La transcription conserve son mode automatique et ne traduit pas le contenu.

Vingt-et-un modèles Tesseract sont installés. Dix-neuf proviennent de tessdata_fast, le persan et l’ourdou de [tessdata_best](https://github.com/tesseract-ocr/tessdata_best), retenus pour améliorer ces écritures. Leurs blobs sont comparés aux empreintes Git des dépôts officiels ; commits, tailles et SHA-256 sont conservés dans `.tools/tesseract/language-provenance.json`, ainsi que dans le marqueur de vérification des assets. Tous sont sous Apache 2.0. Sur un autre hôte, préparer tous ces fichiers .traineddata avant recognition:verify ; un modèle absent empêche la publication de la capacité OCR complète. Les budgets ne changent pas : seul le modèle sélectionné est chargé, ou fra+eng dans le mode combiné.

Le modèle Whisper tiny multilingue existant couvre ces codes : aucun nouveau modèle de transcription, API tierce ou abonnement. Le support d’un code ne garantit pas la même qualité dans toutes les langues. Les tests linguistiques de référence vocale restent français/anglais ; le test des vingt codes vérifie leur acceptation native sur un fragment de fixture, pas la précision sémantique sur vingt corpus. Les vingt-et-une variantes OCR lisent chacune un mot attendu dans une image synthétique de leur écriture. Les sorties arabe/persan/ourdou sont rendues dans un champ dir=auto pour respecter le sens du texte. Relire systématiquement, particulièrement les écritures calligraphiques et l’audio bruité.
