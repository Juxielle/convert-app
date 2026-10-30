# Vidéothèque publique : Wikimedia Commons

Le module externe est indépendant des conversions, avec sa propre entrée `/api/v1/social-downloads`, sa queue **external-downloads** et ses compteurs horaires/journaliers. Il réutilise les garanties du pipeline privé (propriété, réservation atomique, annulation, stockage, expiration, téléchargement autorisé), sans réencoder la vidéo.

## Périmètre vérifié

Source unique : **Wikimedia Commons**, fichiers originaux **WebM marqués CC0 1.0**. Les politiques officielles [d’accès aux API](https://www.mediawiki.org/wiki/Wikimedia_APIs/Access_policy) demandent un User-Agent identifiable. Les [règles de réutilisation](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia) et les informations de chaque fichier restent à consulter ; CC0 ne supprime pas les éventuels droits à l’image, marques ou restrictions autres que le copyright.

Les formats et licences non vérifiés restent refusés. Pas d’OGV/Theora, MP4, HLS/DASH, recherche de plateformes, cookies sociaux, authentification tierce ou yt-dlp. Aucune promesse de compatibilité avec toute la vidéothèque. Pas de récupération de YouTube ou de source autre que Commons.

Le serveur consulte l’API officielle Imageinfo pour le fichier demandé : MIME video/webm, taille positive ≤ 25 Mo et URL officielle de la licence CC0. Il accepte les variantes officielles de cette URL, dont `/deed.en`. L’URL du média vient exclusivement de l’API, jamais directement du navigateur. Les paramètres UTM ajoutés par Imageinfo sont retirés de l’URL statique. Après transfert, taille et MIME sont revérifiés, puis ffprobe isolé contrôle codec, durée, dimensions et pistes. Le résultat n’est publié qu’après succès.

Limites : 25 Mo, 120 s de vidéo, Full HD/60 images/s au plus, une piste vidéo VP8/VP9 et une piste audio compatible au maximum, mono/stéréo ≤ 48 kHz. Métadonnées réseau ≤ 1 Mo, trois redirections maximum, connexion 5 s, tâche externe 30 s ou plafond du rôle inférieur, débit de réception 1 Mo/s maximum. Au maximum un téléchargement externe en cours ; le plafond global de traitements reste partagé et vaut un par défaut. Les réponses 429/503 entraînent un cooldown partagé basé sur Retry-After (60 s à 24 h), sans boucle de retry automatique.

Quotas séparés : initialement deux demandes/h et cinq/jour, plafonnés aussi par les limites du rôle. Une annulation, licence incompatible ou fichier invalide reste compté ; les échecs internes peuvent être remboursés selon les règles du pipeline. Stockage et créneaux actifs restent partagés pour empêcher un contournement des budgets du VPS. `GET /me/usage` expose `used` pour les conversions et `external_used` pour les téléchargements. Aucun fichier externe n’est accepté via `/conversions` et aucun manifeste JSON brut client ne peut être envoyé au worker.

## Protection réseau

HTTPS/443 uniquement ; URL source exacte sous `https://commons.wikimedia.org/wiki/File:...webm`, sans query, fragment, identifiants ni caractères ambigus. L’API est fixée à commons.wikimedia.org ; le média est fixé à upload.wikimedia.org sous /wikipedia/commons/. Aucun sous-domaine arbitraire ou domaine saisi en administration.

Le garde rejette IP littérales, réseaux privés/réservés/link-local, réponses DNS mixtes publiques/privées, IPv4 ambiguës et plages IPv6 de transition. IPv6 est volontairement plus restrictif que l’ensemble des adresses publiquement routables. Toutes les réponses A/AAAA doivent être admissibles, puis l’adresse choisie est **épinglée à la connexion TLS avec CURLOPT_RESOLVE**. Les proxies d’environnement sont désactivés. TLS et le hostname sont vérifiés. Pas de suivi automatique des redirections : chaque destination complète est revalidée/résolue/épinglée, avec la même allowlist. Aucun fallback vers une résolution non contrôlée ou vers HTTP.

Les callbacks cURL bornent les octets, le temps et le débit et interrogent l’annulation toutes les 100 ms au plus ; les détails natifs et URL ne sont pas transmis aux logs. L’interrupteur administrateur de l’outil externe est relu pendant le transfert : le désactiver arrête la tâche sans publier de résultat. Le probe natif possède son propre délai/mémoire et conserve un verrou de fichiers protégeant les travaux vivants du nettoyeur.

## Configuration et vérification

Le clone neuf reste désactivé par défaut. Configurer dans `.env` (pas dans le dépôt) le contact public de l’exploitant :

```dotenv
EXTERNAL_DOWNLOADS_ENABLED=false
EXTERNAL_USER_AGENT="Convert/1.0 (mailto:support@example.org)"
```

Une URL publique de contact HTTPS peut remplacer mailto. Le contact fourni dans cette session a été configuré uniquement dans le `.env` local, sans email envoyé à cette adresse. Puis :

```bash
php artisan external:verify
```

Cette commande retire l’ancien marqueur, exécute les tests Commons/SSRF, puis télécharge réellement le fichier de contrôle [Frounhofer02.webm](https://commons.wikimedia.org/wiki/File:Frounhofer02.webm) (CC0, diffraction, auteur indiqué Jacopo Bertolotti). Elle valide les métadonnées et le média natif, nettoie son répertoire de vérification, puis crée `.tools/commons-ready.json` avec empreintes des sources/protections/probe, identité du binaire et empreinte du User-Agent. Une modification impose une nouvelle vérification. Le marqueur n’est pas copiable à un autre hôte sans refaire les tests.

Après succès, activer `EXTERNAL_DOWNLOADS_ENABLED=true`, reconstruire le cache de configuration s’il existe et redémarrer le worker. **Cette activation a été réalisée localement après contrôle réel réussi**, pas sur un VPS. L’interrupteur de l’administration ne remplace ni le marqueur, ni le User-Agent, ni l’activation d’environnement. Les nouveaux clones restent fermés et les autres sources ne sont jamais autorisées par une simple variable de configuration.

Worker séparé :

```bash
php artisan queue:work database --queue=external-downloads --sleep=1 --timeout=240 --memory=256 --max-time=3600
```

Le worker `conversions` seul ne consomme pas cette queue. Le service de conversion peut rester sans Internet ; le service externe nécessite DNS/HTTPS vers les domaines validés et MySQL local. Voir [convert-external-worker.service.example](../deploy/convert-external-worker.service.example) et compléter la politique d’egress de l’hôte. Ne pas élargir le réseau du service de conversion par défaut.

## Provenance et usage

Le propriétaire voit auteur, titre, page source, licence et lien CC0 dans la réponse `Job.attribution` et dans l’écran de téléchargement. Les valeurs HTML d’extmetadata sont converties en texte borné, puis rendues comme texte React, sans HTML injecté. Conserver la provenance lors de la réutilisation, même si CC0 n’impose pas une attribution de copyright. Le fichier original peut contenir ses propres métadonnées ; il est servi en attachment privé, jamais exécuté ou publié automatiquement.

Tests locaux : SSRF/DNS/redirections simulés, métadonnées et transfert substitués avec un vrai WebM synthétique et vrai ffprobe, licence refusée, quota séparé, accès tiers refusé, annulation pendant transfert et kill switch. Validation réseau réelle : API et CDN officiels, 934 415 octets ; parcours HTTP/session/CSRF → queue externe → téléchargement privé 200 → décodage FFmpeg intégral ; tâche de contrôle supprimée. Les tests automatiques usuels restent hors réseau. Disponibilité et conformité de l’hôte Linux restent à revalider avant mise en production.
