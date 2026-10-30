# Images — moteur et exploitation

## Choix du moteur

Inventaire local : ImageMagick (`magick`) et libvips (`vips`) ne sont pas dans le PATH ; aucun répertoire correspondant dans `C:\laragon\bin`. Cette vérification n’est pas une recherche exhaustive de tout le disque. PHP 8.3.16 fournit GD avec JPEG, PNG et WebP. GD est retenu pour éviter un service ou paquet natif supplémentaire. Les neuf couples source/destination sont validés par conversion réelle et réouverture de la sortie.

Les outils disponibles sont convertir-image, compresser-image et redimensionner-image. Entrées jpg/jpeg/png/webp ; sorties jpg/png/webp. GIF, BMP, TIFF, ICO, AVIF et images animées ne sont pas annoncés. APNG et WebP animé sont refusés explicitement.

L’inspection des dimensions ne valide pas seule le fichier, comme le rappelle [la documentation PHP de getimagesize](https://www.php.net/getimagesize). Elle est associée à Fileinfo, aux contrôles de conteneur puis au décodage réel GD dans un processus enfant. La sortie est contrôlée par taille, format, dimensions et décodage. Ce contrôle est nécessaire notamment car [imagewebp peut signaler un succès sans sortie exploitable](https://www.php.net/manual/en/function.imagewebp.php).

## Traitement

Le worker Laravel lance `bin/image-worker.php` via Symfony Process avec tableau d’arguments fixes :

```text
<PHP CLI> -d memory_limit=128M -d allow_url_fopen=0 -d display_errors=0 -d log_errors=0 <projet>/bin/image-worker.php
```

Le manifeste JSON est construit sur le serveur et envoyé sur stdin. Le navigateur ne choisit ni exécutable, chemin, filtre ni argument système. Le processus enfant ne lance pas de sous-processus. Le parent impose un timeout indépendant de pcntl, appelle les checkpoints d’annulation et ferme l’enfant avant de nettoyer ses fichiers. Parent et enfant détiennent chacun un verrou partagé ; le nettoyeur demande un verrou exclusif, protégeant aussi les fichiers d’un enfant vivant après un crash du parent. Aucun pourcentage intermédiaire fictif : l’état est indéterminé jusqu’au résultat.

Une limite d’écriture stream empêche l’encodeur de dépasser le budget de sortie. Le plafond de réservation est basé sur les pixels de sortie possibles (5 octets/pixel + 65 536 octets, plafonné à 32 Mo), pas sur le seul poids compressé de l’entrée. La réservation est conservée jusqu’à suppression des fichiers.

Valeurs initiales : 6 000 000 pixels source, 8192 pixels par dimension, cinq fichiers par lot. Le lot est atomique à l’admission : une erreur ou un quota insuffisant annule l’ensemble, sans tâches/files/usage partiels. Chaque fichier consomme une opération. Un lot occupe un créneau propriétaire, ses tâches sont traitées une par une sous le plafond global. Les résultats de traitement peuvent réussir ou échouer individuellement après admission.

Le redimensionnement utilise une boîte maximale largeur/hauteur, conserve les proportions et n’agrandit jamais l’image. Une dimension absente utilise la dimension source. Les options de compression acceptent quality entre 1 et 100 pour JPEG/WebP, 82 par défaut. PNG utilise une compression sans perte au niveau 9 ; quality n’a pas d’effet sur PNG. Le poids peut augmenter : le taux de réduction est calculé sur les octets réels, avec une valeur négative en cas d’augmentation.

La transparence est conservée en PNG/WebP ; elle est aplatie sur fond blanc en JPEG. Métadonnées EXIF et profils ICC non préservés ; l’orientation EXIF n’est pas appliquée et le rendu de fichiers avec profils couleur particuliers peut changer. Ce moteur fournit une conversion raster simple, pas une chaîne photographique avec gestion des couleurs.

## Configuration et démarrage

```dotenv
CONVERSION_IMAGES_ENABLED=true
CONVERSION_PHP_BINARY=
CONVERSION_IMAGE_MAX_PIXELS=6000000
CONVERSION_IMAGE_MAX_DIMENSION=8192
CONVERSION_IMAGE_BATCH_FILES=5
```

`CONVERSION_IMAGES_ENABLED=false` désactive immédiatement les trois outils après rechargement de la configuration/redémarrage des workers. Sous Apache, renseigner le chemin absolu du PHP **CLI** avec les codecs GD, par exemple le PHP Laragon existant. Ne pas y mettre le binaire Apache ou PHP-FPM. En CLI, le défaut est `PHP_BINARY`. Vérifier aussi les codecs du PHP web : le catalogue se désactive s’ils manquent.

```powershell
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
# Dans un autre terminal :
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
# Nettoyage dans un troisième terminal de développement :
php artisan schedule:work
```

Ouvrir `/outils/convertir-image`, choisir les images/réglages et lancer le traitement. Les résultats apparaissent sur la fiche et dans `/mes-fichiers`, où ils peuvent être annulés ou supprimés. Une fiche seule ne démarre pas automatiquement le worker.

La configuration PHP CLI locale annonce upload_max_filesize=2G et post_max_size=2G ; l’API impose ses limites de 25/100 Mo, mais cela ne protège pas à lui seul les fichiers temporaires reçus avant validation. Adapter les limites PHP/Apache au déploiement réel avant exposition publique. Aucun php.ini ni service système n’a été modifié ici.

Le plafond mémoire PHP ne constitue pas une limite RSS imposée par l’OS aux allocations natives de GD. Les limites Linux CPU/mémoire, les permissions, les versions des bibliothèques natives et les ressources partagées du VPS restent à contrôler dans les phases de durcissement. Les valeurs de pixels/concurrence doivent être augmentées uniquement après mesure.
