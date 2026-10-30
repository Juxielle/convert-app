# Comptes, administration et plans

## Origine, session et fichiers

Frontend et API utilisent la même origine Laravel et les cookies de session, avec CSRF ; Sanctum n’est pas nécessaire. Garder CORS fermé, cookies HttpOnly/SameSite=lax et Secure en HTTPS. L’inscription et la connexion régénèrent la session et le token CSRF, renvoyé au client qui actualise sa balise meta. Déconnexion : invalidation complète et nouveau token. Les caches TanStack de l’ancien propriétaire sont vidés. Une session visiteur n’est jamais rattachée automatiquement à un compte ; les tâches visiteurs deviennent invisibles pendant la connexion et une déconnexion invalide cette ancienne session. Aucun transfert implicite ni fusion de quotas.

Un compte gratuit dispose du profil `registered` : initialement 100 Mo/fichier, 20 opérations/h, 50/jour, deux tâches, 180 s et 24 h de conservation. Les plafonds plus stricts des moteurs restent prioritaires. Les métadonnées de compte sont nettoyées après 30 jours ; les fichiers expirent bien avant. Le scheduler doit tourner. `/mes-fichiers` utilise le même historique privé et les mêmes téléchargements/suppressions que les visiteurs.

Les nouveaux mots de passe utilisent le hash Laravel bcrypt, 12 caractères minimum, 72 octets maximum sans NUL ; le serveur rejette les chaînes Unicode qui dépassent la limite au lieu de les tronquer. Nom/email et mot de passe se modifient depuis `/profil`, avec ancien mot de passe. Vérification d’email non obligatoire au MVP ; aucun badge d’email vérifié n’est revendiqué. Après changement/reset : rotation du remember token, suppression des autres sessions DB et vérification du hash par AuthenticateSession.

Réinitialisation : broker Laravel, table existante `password_reset_tokens`, expiration 60 min et usage unique. Lien français basé exclusivement sur `APP_URL`, token/email dans le fragment navigateur puis POST JSON protégé par CSRF. Les logs HTTP ne reçoivent pas le fragment. Les mailers log, array et composites/failover sont interdits pour cette opération. En local sans SMTP configuré : 503 MAIL_UNAVAILABLE, aucun lien écrit dans les logs. Les tests utilisent Notification::fake ; aucune livraison email réelle n’a été effectuée. Configurer et tester un vrai SMTP avant ouverture.

## Premier administrateur

Créer volontairement un compte dans l’interface, puis depuis le terminal de confiance de l’hôte :

```bash
php artisan accounts:admin adresse-du-compte
# Révocation :
php artisan accounts:admin adresse-du-compte --revoke
```

La commande ne crée ni compte ni mot de passe par défaut ; elle exige un compte existant et journalise le changement. Aucun administrateur n’a été ajouté à la base locale par cette livraison. Le rôle et le plan ne sont jamais des champs assignables par l’inscription ou le profil. Le dashboard `/administration` exige auth et Gate admin ; cacher le lien côté client ne constitue pas la protection.

Statistiques : états, taux d’échec sur 24 h, durée moyenne sur au plus 1 000 tâches récentes, octets réservés, disque libre si mesurable, mémoire du processus PHP de la requête et dernier signal d’un worker de la queue conversions (expiration du signal 120 s). Une absence de signal n’est pas une preuve de panne ; vérifier le superviseur. Les tâches admin exposent uniquement des métadonnées minimales, sans nom original, chemins, contenu, URL ou empreinte de propriétaire. Un admin ne dispose d’aucun bypass des policies de téléchargement/suppression des fichiers tiers.

Réglages `tool_settings` : quotas visiteurs/comptes et activation des quinze outils de conversion et interrupteur Commons. L’activation ne remplace jamais l’environnement, le contact et le marqueur de vérification externe. Les commandes, exécutables, domaines externes et critères de vérification ne sont pas éditables depuis l’administration. Bornes : fichier 1 000–100 000 000 octets, stockage 1–400 Mo, 1–100 opérations/h, 1–300/jour, 1–2 tâches, 1–180 s, 1–24 h de conservation. Journalier ≥ horaire, stockage ≥ un fichier. Les réglages peuvent être plus restrictifs que les plafonds moteurs et ne les augmentent pas. Transaction sous mutex du pipeline et audit dans la même transaction. Après modification, les prochaines admissions utilisent les nouvelles valeurs ; une tâche déjà admise conserve sa réservation/durée/expiration. Un outil désactivé ne démarre plus de nouvelle conversion, mais ne tue pas une conversion locale déjà en cours. Pour Commons, le kill switch est aussi relu pendant le transfert et empêche sa publication.

`audit_logs` : auteur, action, cible, date et valeurs minimales changées, aucun contenu de fichier ou secret. Conservation 90 jours via `model:prune` dans le scheduler ; nettoyage des tokens expirés toutes les 15 minutes. Les utilisateurs ordinaires n’accèdent pas aux audits.

## Plans et abonnements

Le catalogue est en base : Gratuit, Pro et Business. Les quotas gratuits conservent leur source centrale existante. Le checkout de production SasPay et la vérification serveur des paiements sont intégrés. Les nouveaux essais sont suspendus tant que la validation d’un moyen de paiement sans débit n’est pas disponible. Voir le [guide SasPay](saspay.md) pour la configuration, les endpoints et les limites, et le [guide tarifs et abonnements](subscriptions-and-pricing.md) pour le cycle de vie des accès.

Migration additive : `2026_10_29_000005_add_accounts_settings_and_audit.php`, appliquée localement. Ajoute users.role/plan, tool_settings et audit_logs ; aucun fichier/tâche existant modifié. La migration peut être annulée uniquement après sauvegarde et examen, car elle retire ces nouveaux réglages/audits.
