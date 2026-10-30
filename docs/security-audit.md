# Audit local de préproduction — phases 6 à 9

Examen ciblé du dépôt, sans pentest d’un hôte distant. Les résultats de tests ne certifient pas l’absence de vulnérabilité. Voir [production-checklist.md](production-checklist.md) pour les validations non réalisées.

| Surface | Contrôle ou correction | Preuve locale / limite |
| --- | --- | --- |
| Session / CSRF | Session Laravel même origine, régénération à la connexion/inscription, invalidation à la déconnexion, mise à jour du token navigateur | AccountTest, AccountFlowTest, ReleaseTest ; cookies HTTPS réels à vérifier sur VPS |
| Mots de passe | Hash Laravel bcrypt ; création/changement/reset ≥ 12 caractères et ≤ 72 octets, pas de NUL ; confirmation ; ancien mot de passe pour profil et changement | AccountTest inclut entrées non textuelles et longueurs UTF-8 ; limite bcrypt sans troncature silencieuse |
| Réinitialisation | Broker Laravel, token haché/expirant/usage unique ; lien basé sur APP_URL, fragment navigateur ; mailers qui peuvent écrire le message dans un log refusés | Notifications simulées, aucune livraison SMTP réelle ; mailer disponible en configuration ne garantit pas sa délivrabilité |
| Brute force | Limites IP et empreinte d’email ; réponses d’identifiants invalides génériques ; endpoint reset à message générique | AccountTest ; règles appliquées aussi au POST HTTP réel |
| Révocation | AuthenticateSession vérifie le hash de mot de passe ; rotation remember_token ; autres sessions DB supprimées après changement/reset | Middleware Laravel et tests de changement ; validation multi-navigateurs restante |
| Rôles / fichiers | Gate admin, policies de propriété sans bypass admin, projections de métadonnées minimales | AdminTest et PipelineTest : admin ne peut ni lire ni supprimer un fichier tiers |
| Réglages | Valeurs numériques bornées, clés et outils fermés, pas d’exécutable ni domaine éditable ; transaction sous mutex du pipeline et audit atomique | AdminTest : refus des clés inconnues, rollback des mises à jour incohérentes |
| Stockage / quotas | Disque privé, identifiants aléatoires, réservations atomiques, nettoyeur respectant les processus vivants | PipelineTest, ConcurrentAdmissionTest, tests natifs ; disque/temporaire/logs système à surveiller séparément |
| Processus natifs | Aucun shell utilisateur, manifeste serveur, limites mémoire/temps/pistes/pages/pixels, un média en cours | Tests natifs et MediaLoadTest ; limites Linux/cgroups à exercer sur hôte cible |
| SSRF | Commons CC0 validé localement, registre vérifié ; HTTPS/domaines/DNS publics, connexion TLS épinglée et redirections contrôlées | ExternalTest/CommonsTest hors réseau et contrôle réel API/CDN réussi ; une seule source et licence acceptées |
| Logs | Pas de contenu de fichier, URL externe, mot de passe ou jeton d’auth dans l’audit ; suppression des détails d’exception SMTP ; Referrer-Policy no-referrer | Revue du code et test reset ; journaux Apache/proxy à configurer selon l’exemple minimal |
| En-têtes | CSP en production, pas de script exécutable inline, nosniff, Permissions-Policy, HSTS uniquement HTTPS, réponses privées no-store | ReleaseTest ; pas de test dans un navigateur de production |
| SEO / données privées | Métadonnées serveur sans query, canoniques via configuration ; profil/admin/reset noindex, sitemap limité aux capacités disponibles | ReleaseTest ; indexation désactivée par défaut |
| Dépendances | Composer audit et npm audit consultés ; mise à jour de l’outillage de test vulnérable | Voir rapport de phase 8 ; audits ponctuels, pas une supervision continue |

Le contrôle `app:production-check` reste volontairement négatif sur ce poste Windows/local, notamment pour HTTPS, Linux/MySQL, SMTP, premier admin et signal de worker. Il effectue des lectures seulement et ne révèle pas les valeurs de secrets. Un succès de ses contrôles automatisés ne dispense pas des validations manuelles listées.

Les exemples systemd prévoient un compte non privilégié, un groupe de processus détruit à l’arrêt, une limite mémoire/CPU et un réseau limité à loopback pour une base locale. Le support effectif des protections de service dépend du VPS et doit être contrôlé. Aucun ClamAV ni nouveau service permanent n’a été installé. Aucune modification de serveur de production n’a été effectuée.
