# Rapport de phase 6 — vidéothèque publique

Livraison locale du 29 octobre 2026. À la demande de l’utilisateur, **Wikimedia Commons est intégré et activé localement pour les WebM marqués CC0 1.0 uniquement**. Le clone neuf reste désactivé par défaut. Le contact fourni a été placé dans le .env local pour le User-Agent requis ; aucun email n’a été envoyé.

Les API et politiques officielles ont été examinées avant intégration : [accès Wikimedia](https://www.mediawiki.org/wiki/Wikimedia_APIs/Access_policy), [Imageinfo](https://www.mediawiki.org/wiki/API:Imageinfo), [réutilisation Commons](https://commons.wikimedia.org/wiki/Commons:Reusing_content_outside_Wikimedia). YouTube reste exclu conformément au périmètre et aux [restrictions officielles de son API](https://developers.google.com/youtube/terms/developer-policies-guide). Aucun yt-dlp installé ni contournement d’accès.

## Réalisation

SourceRegistry, CommonsSource, PinnedTransport, CommonsEngine et UrlGuard constituent le module indépendant. Entrée POST social-downloads, queue **external-downloads**, quotas horaires/journaliers distincts des conversions, stockage/concurrence globaux partagés, policies/CSRF/annulation/expiration existants réutilisés. L’entrée privée est un manifeste serveur, jamais un fichier ou une commande transmis librement au worker. Pas de migration supplémentaire : les tables du pipeline prennent en charge operation_type=social_download.

Filtrage strict HTTPS/443, domaines exacts, résolution IPv4/IPv6 publique vérifiée, connexion TLS épinglée à l’adresse validée, absence de proxy, redirections manuellement revalidées, octets/durée/débit bornés, cooldown Retry-After et un téléchargement en cours au plus. Les vidéos ne sont pas réencodées ; MIME, taille, licence CC0 et caractéristiques ffprobe sont contrôlés avant publication. Auteur/licence/source sont exposés au propriétaire en texte borné. Interrupteur administrateur relu pendant le transfert ; il ne permet jamais de contourner la vérification réelle et le marqueur d’empreintes.

Fichiers principaux : `app/Services/External/*`, `app/Console/Commands/VerifyCommons.php`, `bin/external-probe.py`, ExternalController, routes, pipeline/registre/job/resource, `config/external.php`, `resources/js/pages/ExternalTool.tsx`, tests Commons/External et test React, exemple de service externe. Voir [external.md](external.md) pour les paramètres exacts et limites.

## Tests réellement exécutés

- ExternalTest : trois tests SSRF/DNS/redirections et fermeture sans vérification, 37 assertions.
- CommonsTest : six tests, dont transfert avec vrai WebM synthétique/ffprobe, licence refusée, quotas séparés, accès tiers refusé, URL/manifeste interdits, annulation pendant transfert, interrupteur administrateur et plafond de taille du rôle.
- `php artisan external:verify` : neuf tests locaux, 71 assertions, puis API/CDN Wikimedia réels, licence CC0 et média vérifiés, marqueur créé après succès.
- Parcours HTTP/session/CSRF réel sur 127.0.0.1:8016 : demande → queued → worker CLI de la queue externe → completed → téléchargement privé HTTP 200 ; **934 415 octets** pour [Frounhofer02.webm](https://commons.wikimedia.org/wiki/File:Frounhofer02.webm), puis décodage FFmpeg intégral. Compteur de conversions inchangé à zéro, compteur externe à un. Tâche de contrôle supprimée (200).
- Test React : confirmation des droits requise, POST de la source et lien de téléchargement/provenance. Tous les tests React passent.

Les tests courants sont hors réseau ; seule la commande explicite external:verify et le contrôle HTTP ont accédé aux API/CDN officiels. Disponibilité réseau et licences ne sont pas garanties pour tous les fichiers ; seules les conditions vérifiées sont acceptées. Les autres licences, formats et plateformes restent refusés. Activation Linux et politique réseau du service externe à vérifier avant production. Aucun serveur distant modifié.
