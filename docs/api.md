# Contrat API — phases 1 à 9

Origine identique à l’application, préfixe `/api/v1`. Réponses UTF-8 JSON. Le catalogue reste stateless. Les endpoints de conversion, tâches et consommation utilisent la session Laravel et la protection CSRF du groupe `web`. CORS explicitement fermé aux autres origines dans `config/cors.php` ; aucun token bearer visiteur à conserver dans localStorage.

| Méthode et chemin | Réponse |
| --- | --- |
| `GET /tools` | `{ "data": Tool[] }` — 18 fiches : trois outils images, huit outils documents et quatre outils médias actifs lorsque les dépendances/configuration/vérifications le permettent |
| `GET /tools/{slug}` | `{ "data": Tool }` ou 404 |
| `GET /limits` | `{ "data": { "enforced": true, "message": string, "plans": { "guest": PlanLimits, "registered": PlanLimits } } }` |

`Tool` : `slug`, `name`, `category` (images/documents/audio/video/external), `description`, `operation`, `planned_formats` (objectifs), `source_formats`, `target_formats`, `enabled`, `status` (available, planned ou disabled). Les outils images exposent aussi `image_limits` (pixels, dimensions, nombre de fichiers ; aucun chemin d’exécutable). Le module Commons externe devient available seulement après vérification réelle et activation locale ; les autres sources restent fermées. Aucun paramètre client ne permet d’activer un outil.

`PlanLimits` : `max_file_bytes`, `storage_bytes`, `hourly_jobs`, `daily_jobs`, `concurrent_jobs`, `timeout_seconds`, `retention_hours`. Tailles décimales : 1 Mo = 1 000 000 octets. Réservations et quotas effectifs côté serveur ; aucun compteur d’utilisation fictif.

Limitation commune du catalogue : 120 requêtes par minute par IP pour ces trois endpoints ; dépassement : 429 avec `Retry-After`. En production, configurer les proxies de confiance selon l’infrastructure réelle avant d’utiliser une adresse transmise par proxy.

Erreurs : `{ "error": { "code": string, "message": string, "fields"?: object } }`. Codes préparés : INVALID_REQUEST (400), AUTHENTICATION_REQUIRED (401), FORBIDDEN (403), NOT_FOUND (404), METHOD_NOT_ALLOWED (405), FILE_TOO_LARGE (413), SESSION_EXPIRED (419), VALIDATION_FAILED (422), RATE_LIMITED (429), INTERNAL_ERROR (500), SERVICE_UNAVAILABLE (503). `fields` est réservé aux erreurs de validation. Ni trace, ni chemin physique dans les erreurs API, même en mode debug.

Exemple :

```json
{"error":{"code":"NOT_FOUND","message":"Cette ressource est introuvable."}}
```

`GET /up` est le contrôle de santé Laravel hors contrat métier. Il ne vérifie pas les convertisseurs ni un worker.

## Pipeline sous session

Charger une page Laravel pour obtenir le cookie de session et le jeton CSRF de la balise meta `csrf-token`. Conserver le cookie, envoyer `Accept: application/json` et, pour les mutations, `X-CSRF-TOKEN`. Sans jeton valide ou vérification d’origine Laravel valide, la mutation échoue en 419. Le secret visiteur opaque est créé côté serveur, conservé en session, puis haché dans les tâches. Perdre les cookies fait perdre l’accès aux tâches visiteurs.

| Méthode et chemin | Contrat |
| --- | --- |
| `POST /conversions` | Multipart : `tool`, soit `file` soit `files[]`, `target_format`, `options` facultatif. Retour 202 `{data: Job}` en fichier unique ou `{data: Job[]}` pour un lot. Admission intégrale du lot ou refus sans réservation/fichier partiel. |
| `GET /jobs?page=1` | `{data: Job[], links, meta}` ; 20 éléments/page, propriétaire courant seulement. Les suppressions différées ne sont plus listées. |
| `GET /jobs/{uuid}` | `{data: Job}` ; 404 pour UUID absent ou tâche étrangère. |
| `POST /jobs/{uuid}/cancel` | 202 `{data: Job}` ; queued devient cancelled, processing conserve son statut et reçoit cancel_requested jusqu’à l’arrêt coopératif. Pas de publication après annulation. |
| `DELETE /jobs/{uuid}` | 200 `{data:{deleted:true,pending:false}}` après suppression ; 202 avec deleted=false et pending=true si un worker doit d’abord s’arrêter ou si le disque ne peut pas être nettoyé. |
| `GET /jobs/{uuid}/download` | Flux attachment privé, nom nettoyé, nosniff, no-store ; uniquement completed, non expiré et propriétaire autorisé. Sinon 404. |
| `GET /me/usage` | `{data:{limits:PlanLimits,used:{hourly_jobs,daily_jobs,active_jobs,reserved_bytes},window:"rolling"}}` ; consommation réelle, fenêtres glissantes. |

`Job` contient uniquement : id, batch_id nullable, tool, name nettoyé, source_format, target_format, status, input_size, output_size nullable, reduction_percent nullable, progress nullable, error_code nullable, cancel_requested, delete_requested, download_available et dates ISO created_at/started_at/completed_at/expires_at. reduction_percent est négatif si le résultat est plus volumineux. Aucune clé de stockage, empreinte propriétaire ou trace technique. Un résultat expiré est immédiatement non téléchargeable, même avant nettoyage.

Pour `fusionner-pdf`, `files[]` doit contenir 2 à 5 PDF et `file` est interdit. La réponse est `{data:[Job]}` : une seule tâche fusionnée, dans l’ordre des fichiers, une opération de quota. Le téléchargement porte le nom `fusion.pdf`. Les autres outils documents utilisent `file` pour une tâche unique ; leurs sorties/limites sont décrites ci-dessous.

Limiter à 120 requêtes/minute par IP et propriétaire sur ces routes ; l’upload est en plus limité à 15/minute. Ces limites anti-abus s’ajoutent aux quotas transactionnels horaires/journaliers, stockage et concurrence. Les réponses de session utilisent `Cache-Control: private, no-store`.

Codes complémentaires : TOOL_UNAVAILABLE (422), QUOTA_EXCEEDED et STORAGE_QUOTA_EXCEEDED (429), SERVER_BUSY et PIPELINE_UNAVAILABLE (503), RESULT_UNAVAILABLE (404). Un code interne de worker figure dans `Job.error_code` avec un état failed ; le détail du moteur n’est jamais renvoyé. La durée de conservation commence à l’admission. Le détail des remboursements et du nettoyage est dans `pipeline-operations.md`.

## Options images

Outils actifs : `convertir-image`, `compresser-image`, `redimensionner-image`. Entrées jpg/jpeg/png/webp ; sorties jpg/png/webp. `options[quality]` entier 1–100 (JPEG/WebP ; ignoré pour PNG, sans perte). Pour conversion/redimensionnement : `options[width]` et/ou `options[height]`, entiers 1–8192 par défaut, boîte maximale conservant les proportions sans agrandissement. Les options inconnues sont refusées.

Un lot accepte au plus cinq fichiers ; chaque fichier compte pour les quotas horaires/journaliers et le stockage, le lot partage un créneau propriétaire. Le plafond global d’admissions et d’exécution reste calculé par tâche réelle. Le serveur génère `batch_id` ; le client ne peut pas joindre un lot existant. Les pixels source restent bornés même si une petite taille de sortie est demandée.

Erreurs supplémentaires : INVALID_IMAGE, IMAGE_TOO_COMPLEX, ANIMATED_IMAGE_UNSUPPORTED (422 à l’admission, ou code d’échec du worker si détecté au décodage complet). Le traitement reste asynchrone ; le client interroge l’état puis télécharge uniquement via l’endpoint contrôlé. Les capacités des autres catégories sont exposées séparément par le catalogue.

## Options documents

| `tool` | Entrées | `target_format` | Options validées |
| --- | --- | --- | --- |
| `office-vers-pdf` | docx, xlsx, pptx | pdf | Aucune |
| `pdf-vers-images` | pdf | zip | dpi : 72, 96 (défaut) ou 150 ; pages PNG dans l’archive |
| `fusionner-pdf` | files[] PDF | pdf | Aucune, ordre des fichiers conservé |
| `diviser-pdf` | pdf | pdf | first_page et last_page obligatoires, bornes inclusives, dernière ≥ première |
| `tourner-pdf` | pdf | pdf | angle obligatoire : 90, 180 ou 270 |
| `compresser-pdf` | pdf | pdf | Aucune ; recompression des flux sans perte |
| `csv-vers-xlsx` | csv UTF-8 | xlsx | Aucune ; valeurs importées comme texte |
| `xlsx-vers-csv` | xlsx | csv | sheet : numéro 1–20, défaut 1 ; formules exportées comme texte neutralisé |

`Tool.document_limits` expose les limites non sensibles du moteur ; les chemins Python/LibreOffice sont exclus. Par défaut : entrée 10 Mo (total pour une fusion), sortie 32 Mo, 50 pages, 20 mégapixels cumulés au rendu, 10 000 lignes et 100 colonnes. Les quotas généraux restent applicables. Le PDF ou document protégé/invalide peut être détecté dans le worker après réponse 202 ; aucun téléchargement n’est alors publié.

Codes de refus/échec : INVALID_DOCUMENT, PROTECTED_DOCUMENT, DOCUMENT_TOO_COMPLEX, UNSAFE_DOCUMENT, EXTERNAL_REFERENCE, INVALID_OPTIONS, OUTPUT_LIMIT_EXCEEDED, PROCESS_TIMEOUT. À l’admission, les formats/options non conformes donnent 422 et les dépassements de taille 413. Les échecs asynchrones renvoient le code stable dans `Job.error_code`, jamais la sortie native ou une trace. Les outils restent indisponibles si `documents:verify` n’a pas réussi dans l’environnement correspondant.

## Options audio et vidéo

`Tool.media_limits` expose les plafonds non sensibles, sans les chemins des exécutables. Activation conditionnée à `media:verify`. Les quatre outils utilisent `file` uniquement ; `files[]` et toutes les options inconnues sont refusés. Réponse 202 `{data: Job}`. Le MIME et l’extension sont vérifiés à l’admission ; le codec, les pistes et la complexité sont vérifiés de façon asynchrone avec ffprobe.

| Outil | Entrées | Sorties |
| --- | --- | --- |
| `convertir-audio` | mp3, wav, aac, m4a, flac, ogg, opus | Les mêmes sept formats |
| `extraire-audio` | mp4, mov, mkv, avi, webm | Les sept formats audio |
| `convertir-video`, `compresser-video` | mp4, mov, mkv, avi, webm | Les mêmes cinq conteneurs |

Options communes : `start` numérique 0–300 s, `duration` numérique 0,1–300 s, facultatives. La source entière reste soumise à son plafond : 300 s pour audio, 120 s pour vidéo/extraction. Début hors source refusé. Audio/extraction : `bitrate` entier 64/128/192/256/320 (défaut 128), `sample_rate` entier 22050/44100/48000 (défaut 44100, Opus forcé à 48000), `channels` entier 1/2 (défaut 2). WAV/FLAC ignorent le débit. Vidéo : `profile` light/balanced/quality (défaut balanced), `resolution` 360/480/720 (défaut 480 pour light, 720 sinon), `fps` 15/24/30 (défaut 30). Aucun argument natif libre.

Tailles : audio 25 Mo, vidéo 50 Mo, toujours bornées par le quota du rôle (visiteur : 25 Mo). Sortie 64 Mo, 720p maximum. Décodeurs et restrictions des pistes détaillés dans [media.md](media.md). Une opération média à la fois globalement par défaut ; le surplus reste en queue. `Job.progress` reste nullable à l’inspection, puis suit le temps FFmpeg mesuré de 0 à 99 ; 100 uniquement après validation complète. Un encodeur peut refuser une combinaison de réglages : aucune sortie partielle n’est publiée.

Codes d’échec : INVALID_MEDIA, MEDIA_TOO_COMPLEX, UNSUPPORTED_MEDIA_CODEC, PROTECTED_MEDIA, MISSING_MEDIA_STREAM, INVALID_OPTIONS, OUTPUT_LIMIT_EXCEEDED, INVALID_OUTPUT, PROCESS_TIMEOUT. Annulation et suppression utilisent les mêmes endpoints et policies que les images/documents. Les réservations de stockage restent maximales pendant le traitement, puis correspondent aux fichiers réels conservés après succès.

## Comptes, administration, plans et vidéothèque

Tous ces endpoints utilisent les cookies de session de la même origine, avec `Accept: application/json` et le token CSRF courant pour les mutations JSON. Les réponses auth/profil/admin sont privées no-store. Les erreurs de validation contiennent des listes `error.fields`, sans valeur saisie ni mot de passe. Les messages sont en français.

| Méthode / chemin | Contrat |
| --- | --- |
| GET /auth/session | data : user null ou id/name/email/role/plan, csrf_token et password_reset_available (configuration de transport, pas délivrabilité) |
| POST /auth/register | name, email, password, password_confirmation ; 201 et nouvelle session. Rôle/plan interdits. |
| POST /auth/login | email, password ; 200 et session régénérée, erreurs d’identifiants génériques |
| POST /auth/logout | Auth requise ; session invalidée et nouveau token CSRF |
| PATCH /me/profile | Auth requise ; name, email, current_password ; rôle/plan interdits |
| PATCH /me/password | Auth requise ; current_password, password, password_confirmation ; autres sessions révoquées |
| POST /auth/forgot-password | email ; message générique, 503 MAIL_UNAVAILABLE si transport sans envoi réel ou en erreur |
| POST /auth/reset-password | email, token, password, password_confirmation ; lien à usage unique et expirant, aucune connexion automatique |
| GET /admin/overview | Auth + admin ; états, échecs/durée sur 24 h, stockage/disque, mémoire PHP de requête et signaux récents des workers |
| GET /admin/jobs | Auth + admin ; pagination 20 de métadonnées minimales, aucun contenu/chemin/nom original/téléchargement |
| GET/PATCH /admin/settings | Auth + admin ; limits.guest/registered avec clés numériques bornées, tools par slug connu avec booléens. Mise à jour et audit atomiques. |
| GET /admin/audit | Auth + admin ; vingt actions/page, métadonnées minimales |
| GET /plans | Plans publics guest/free et leurs limites effectives ; aucun premium public ni paiement |
| GET /social-downloads/sources | État d’activation, sources vérifiées et limites externes ; aucun contact ou chemin sensible |
| POST /social-downloads | url d’une page Commons File:*.webm, rights_confirmed accepté ; 202 data Job ou 503 EXTERNAL_DISABLED avant toute résolution si fermé |

Mots de passe nouveaux : au moins 12 caractères, au plus 72 octets avec bcrypt, pas de NUL ; confirmation requise. Limites auth : dix requêtes/minute/IP, cinq/minute/empreinte-email+IP. Admin et mutations de profil réutilisent les limiteurs de session du pipeline. Endpoint externe : quota séparé initial de deux/h et cinq/jour, stockage/concurrence partagés. Le quota de conversion `used` exclut social_download ; `external_used` donne sa consommation indépendante. Les limites de rôle restent un plafond supplémentaire.

Le Job externe utilise `source_format=commons-url`, target_format=webm, `reduction_percent=null` et un champ attribution nullable (source, title, url, artist, license, license_url). Ses endpoints d’état, annulation, suppression et téléchargement sont les mêmes endpoints privés /jobs et les mêmes policies. Le nom de source/URL brute/CDN avec tracking n’est pas journalisé ; les informations publiques de provenance sont exposées au propriétaire seulement. Codes : UNSAFE_SOURCE, LICENSE_NOT_ALLOWED, SOURCE_INCOMPATIBLE, REMOTE_UNAVAILABLE ; aucun résultat partiel après échec.

Les détails des limites admin sont dans accounts-and-plans.md ; le transport et l’activation Commons dans external.md. Le téléchargement externe n’est jamais accepté sur POST /conversions, même si son outil apparaît disponible dans le catalogue.

## OCR et transcription audio/vidéo

Deux outils supplémentaires sur le même POST /conversions, fichier unique et target_format=txt. `files[]`, modèles, URLs et options natives libres sont interdits. Quotas normaux de conversion, mêmes endpoints privés de suivi/annulation/suppression/téléchargement ; pas de contenu du texte dans les métadonnées Job. L’aperçu lit le TXT via le téléchargement autorisé existant, pas via une route publique.

| tool | Sources | Options |
| --- | --- | --- |
| ocr-image | jpg/jpeg, png, webp fixes | language : code publié dans Tool.recognition_languages ; fr-en par défaut ; layout : auto (défaut), block, sparse |
| transcrire-media | mp3, wav, aac, m4a, flac, ogg, opus, mp4, mov, mkv, avi, webm selon codecs testés | language : auto (défaut) ou un des 20 codes publiés dans Tool.recognition_languages |

`Tool.recognition_limits` expose seulement les limites non sensibles ; aucun chemin, binaire ou modèle. OCR : 10 Mo/6 MP, 8 192 px ; parole : 25 Mo/60 s, bornes du moteur média sur pistes/dimensions/fréquence ; quotas de rôle inférieurs prioritaires. TXT UTF-8 ≤ 1 Mo. L’état processing reste indéterminé : aucune progression/ETA simulée. Le TXT peut contenir du HTML apparent comme texte, jamais rendu par injection HTML.

NO_TEXT_FOUND et NO_SPEECH identifient un contenu non exploitable ; INVALID_IMAGE, IMAGE_TOO_COMPLEX, INVALID_MEDIA, MEDIA_TOO_COMPLEX, MISSING_MEDIA_STREAM, UNSUPPORTED_MEDIA_CODEC, PROCESS_TIMEOUT et OUTPUT_LIMIT_EXCEEDED restent des codes stables. Aucun résultat partiel ou texte natif dans les erreurs. Les langues/mises en page sont des valeurs fermées validées côté serveur. L’activation exige recognition:verify et le marqueur correspondant à l’hôte. Voir recognition.md.

Les langues des reconnaissances sont exposées par `Tool.recognition_languages` (code/label). OCR : 21 variantes + fr-en ; parole : 20 langues + auto. Liste unique versionnée dans config/recognition-languages.json, reprise par validation, worker et interface. Le code zh-tw est OCR seulement. Voir recognition.md pour la liste et les limites de qualité.
