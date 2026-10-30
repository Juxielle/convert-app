# Architecture proposée

Statut : développements locaux des phases 1 à 9 livrés. Quinze outils de conversion et un module Commons WebM CC0 activé après vérification locale. Comptes, administration, plans et métadonnées serveur implémentés ; production non validée. Voir les rapports 6 à 9 et deployment-apache.md.

## Application et déploiement

Un monolithe Laravel sert l’API `/api/v1` et une application React/TypeScript compilée par Vite. React Router gère les pages, TanStack Query les données serveur et Tailwind le style accessible. Interface française, thème clair initial et sombre optionnel. Le choix des versions exactes sera vérifié au démarrage du socle selon PHP et Node disponibles, avec fichiers de verrouillage conservés.

Privilégier une origine HTTPS unique : Apache pointe sur `public/`, sert les assets compilés et délègue les routes applicatives à Laravel. Les routes API inconnues renvoient du JSON, jamais la page React. Authentification par session Laravel avec protection CSRF, rotation de session, cookies HttpOnly et SameSite, Secure en production. Aucune ouverture CORS générale. Un déploiement multi-origines nécessiterait une configuration explicite réévaluée.

MySQL/MariaDB pour la production, queue et sessions en base. SQLite peut servir aux premiers tests locaux ; les tests de verrouillage/concurrence doivent aussi passer sur le moteur de production. Redis reste optionnel. Aucun paiement, microservice ou WebSocket nécessaire au MVP.

## Modèle et frontières de sécurité

Tables : `users`, `conversion_jobs` (UUID), `usage_events`, `tool_settings`, `audit_logs`, plus tables Laravel de sessions, queue, échecs et réinitialisation des mots de passe. Index sur propriétaire, statut, création et expiration. Le profil de plan détermine les limites ; il prépare une offre premium sans fournisseur de paiement.

Un visiteur reçoit une session opaque sécurisée. Les tâches conservent uniquement l’empreinte d’un secret visiteur distinct de l’identifiant public de tâche. Les policies contrôlent chaque lecture, suppression, annulation et téléchargement. Une tâche étrangère renvoie 404. L’administration donne accès aux métadonnées et métriques, sans téléchargement implicite des fichiers.

Les Form Requests valident fichiers et options autorisées ; le serveur détecte le MIME réel et vérifie les structures pertinentes. Les noms internes sont aléatoires, les originaux nettoyés pour l’affichage. Tous les fichiers restent sur un disque privé hors `public/`. Aucun lien symbolique public vers ce disque.

## Pipeline

1. Contrôler taille, MIME, format, options et complexité avant admission.
2. Dans une transaction, acquérir le verrou global durable par une écriture SQL. Pour ce MVP à faible concurrence, ce verrou commun sérialise aussi les admissions d’un même propriétaire. Vérifier quotas glissants, créneaux actifs et budgets disque, puis réserver l’entrée et une sortie bornée. Pour les images, réserver jusqu’à cinq octets par pixel et une marge de 65 536 octets, avec plafond de sortie à 32 Mo : une image JPEG très compressée peut donc être convertie en PNG sans dépendre d’un multiplicateur de son poids initial.
3. Stocker l’entrée privée et insérer la tâche Laravel `database` sur la même connexion SQL, dans la même transaction. Les lignes de quota, de conversion et de queue deviennent visibles ensemble au commit : le worker ne peut pas lire une tâche non validée. Cette stratégie remplace le dispatch après commit initialement envisagé et sa fenêtre de panne. Une autre connexion ou `after_commit=true` est refusé. En cas d’exception, rollback et suppression compensatoire ; après crash, le nettoyeur récupère les fichiers orphelins, qui restent comptés dans le plafond global.
4. Un worker revendique atomiquement la tâche et respecte le plafond global d’exécution. Seules les transitions attendues sont permises ; les livraisons doubles sont sans effet.
5. Examiner le fichier dans un processus limité, convertir avec des arguments prédéfinis, contrôler le format et la taille du résultat avant publication.
6. Le client interroge l’état par polling. Afficher un pourcentage uniquement si mesuré ; sinon un état indéterminé.
7. Un endpoint autorisé vérifie propriétaire, statut et expiration, puis transmet une pièce jointe avec nom nettoyé et `X-Content-Type-Options: nosniff`.

États : `uploaded`, `queued`, `processing`, `completed`, `failed`, `cancelled`, `expired`. Annulation coopérative avec interruption du processus enfant, contrôle avant publication et libération des réservations une seule fois. Timeout du processus inférieur à celui du worker ; délai de nouvelle livraison supérieur au timeout du worker. Heartbeat et réconciliation détectent les workers interrompus. Retry limité aux erreurs transitoires.

Cron Laravel nettoie les résultats expirés et les espaces orphelins sans toucher aux traitements actifs. Un worker conserve un verrou local `flock` ; un heartbeat ancien ne permet pas de purger tant que ce verrou est détenu. Téléchargements expirés refusés immédiatement, même avant nettoyage. Métadonnées des comptes : 30 jours ; visiteurs : un jour après admission au plus, sous réserve de nettoyage réussi et de l’absence de worker vivant ; usages : 31 jours pour préserver les quotas glissants après suppression. Les échecs internes remboursent par bascule idempotente de l’événement `billable=false`. Annulations, fichiers invalides et dépassements de durée restent comptés.

## Moteurs

Contrat de moteur commun : capacités vérifiées, inspection, conversion, annulation et validation de sortie. Faux moteur réservé aux tests, jamais présenté comme un convertisseur public.

Phase 3 : GD retenu car présent localement, ImageMagick/libvips absents du PATH. JPEG, PNG et WebP sont testés dans les neuf combinaisons et disponibles dans trois outils. Décodage/réencodage dans un processus PHP enfant, avec arguments Symfony Process fixes, mémoire PHP 128 Mo, délai borné et fermeture du processus avant nettoyage en cas d’annulation. Les chemins et le manifeste sont générés côté serveur et transmis sur stdin ; aucun shell ni URL. L’inspection HTTP se limite au MIME, aux en-têtes, aux dimensions/pixels et au rejet d’animation ; le décodage complet reste asynchrone.

Un lot comporte au plus cinq images et est admis intégralement dans une transaction. Chaque image réserve stockage et opération ; le lot partage un créneau propriétaire via `batch_id`, alors que l’exécution reste limitée globalement à un traitement. Les fichiers et lignes de queue du lot sont compensés en cas de refus. Aucun `batch_id` client n’est accepté. Les moteurs audio/vidéo suivent le même pipeline, avec leurs propres budgets et limites de concurrence. Les limites OS Linux et l’utilisateur non privilégié restent à valider avant production.

Phase 4 : les moteurs documents utilisent un Python isolé avec pypdf/PDFium/openpyxl et un LibreOffice local pour DOCX/XLSX/PPTX vers PDF. `BudgetedEngine` généralise la réservation de sortie sans changer les quotas ni les policies. Les profils Office sont isolés par tâche ; les opérations, filtres et chemins sont définis côté serveur. Limites : 10 Mo d’entrée, 32 Mo de sortie, 50 pages, 20 mégapixels de rendu et bornes de décompression/cellules. Les capacités ne sont publiées qu’après `documents:verify` et son marqueur d’empreinte privé. Les anciens DOC/XLS/PPT et PDF vers DOCX restent non activés.

La fusion de PDF crée une seule tâche/opération depuis 2 à 5 fichiers, dans l’ordre choisi. Le paquet intermédiaire est privé, protégé par verrou de processus, compté dans le budget global et nettoyé après abandon. Les autres opérations produisent un fichier unique ; PDF vers images fournit une archive de pages PNG. Les limites/processus Windows sont testés ; les protections Linux et le réseau natif du service restent à valider avant production.

Phase 5 : FFmpeg/ffprobe sont exécutés depuis Python dans un groupe de processus borné. MIME et options sont validés à l’admission ; ffprobe et le transcodage restent dans le worker. Un seul média s’exécute à la fois, même si le plafond global augmente. Les profils, codecs, démultiplexeurs et protocoles sont définis côté serveur ; seuls des fichiers locaux sont autorisés. Les quatre outils sont publiés après `media:verify` et contrôle du marqueur privé. La progression vient du temps encodé mesuré. Après succès et arrêt des écritures, le stockage réservé devient la somme réelle de tous les fichiers du répertoire privé, y compris les éventuels intermédiaires. Les sorties, restrictions et licences sont documentées dans `media.md`. Les tests locaux de deux workers couvrent disponibilité HTTP, sérialisation, progression, annulation et délai.

Le téléchargement externe est un module distinct désactivé par défaut dans un clone neuf. L’adaptateur Commons CC0 est activé sur ce poste après vérification réelle ; aucun autre domaine source autorisé. L’activation exige validation technique et juridique par source, quotas séparés, filtrage SSRF IPv4/IPv6, résolution et connexion liées à l’adresse validée pour éviter le rebinding DNS, contrôle de chaque redirection et limites de taille/durée/débit. Aucun contournement d’accès.

## API et interface

Contrat documenté dans api.md : outils, création de conversions, liste/état/annulation/suppression/téléchargement des tâches, quota courant, comptes, administration et module Commons sous contrôle. Erreurs JSON homogènes avec code stable et message français ; aucune trace ou chemin privé transmis. Rate limiting par endpoint, IP et propriétaire.

Pages prévues : accueil/recherche, catalogue et catégories, outil, FAQ, confidentialité, conditions, mentions légales, profil/historique/quotas et administration. Le catalogue ne propose une action que si le moteur et le format ont passé leurs tests. Les informations légales propres à l’exploitant devront être renseignées avant publication.

## Phases 6 à 9 implémentées

Source externe : API Commons officielle et CDN exact, DNS épinglé/TLS contrôlé, queue external-downloads séparée et quotas séparés des conversions, budgets privés partagés. Une activation exige le contact User-Agent et le marqueur de vérification ; l’admin peut arrêter l’outil sans contourner ces exigences. Les fichiers sources ne sont pas des commandes système et ne transitent jamais dans l’endpoint d’upload des conversions.

Comptes et administration : session Laravel même origine, CSRF, Gate admin, quotas Settings relus par le pipeline, réglages/audit atomiques. Tables et colonnes additives appliquées. Les policies n’accordent pas de bypass de contenu aux admins. Réinitialisation par broker Laravel sans token dans les logs ; SMTP réel restant à configurer. Plans publics guest/free, premium réservé sans paiement.

SEO : métadonnées HTML côté Laravel et suivi des navigations React, canoniques contrôlées par APP_URL, sitemap dynamique et indexation fermée par défaut. Production : CSP/HSTS/cookies, contrôle de préparation, exemples Apache/systemd/cron et reprise ; validations Linux/MySQL/VPS/navigateur en attente. Les exemples ne sont pas installés automatiquement.

## Extension OCR / parole

RecognitionEngine ajoute les opérations ocr et transcription au pipeline existant et à la queue conversions. Tesseract sur image normalisée et Whisper.cpp tiny sur PCM mono 16 kHz tournent dans un Python isolé, sans réseau, arguments fixés, verrou de fichiers, délai et mémoire bornés. Aucun contenu reconnu en base ; sortie TXT sur disque privé, accessible seulement au propriétaire. Un traitement de reconnaissance en cours au maximum, plafond global conservé. Le coût mémoire natif de la parole impose un budget dédié et une nouvelle mesure du VPS. Le catalogue passe à dix-huit fiches, activation conditionnée aux tests natifs de recognition:verify. Pas de nouvelle migration ni de changement aux modèles de comptes/plans.
