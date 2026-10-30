# Tarifs, essais et abonnements

## Offres configurées

Les données sont en base (`plans`), exposées par `GET /api/v1/plans` et utilisées par `/tarifs`, `/souscription` et `/mon-abonnement`. React ne fixe ni les prix ni les quotas des offres. La migration additive `2026_10_30_000007_enable_subscription_trials.php` configure :

| Offre | Mensuel / annuel | Taille par fichier | Quota | Tâches simultanées | Historique |
| --- | --- | --- | --- | --- | --- |
| Gratuit | 0 FCFA, sans limite de durée | 25 Mo | 10 / 24 h glissantes | 1 | 24 h |
| Pro | 3 900 / 39 000 FCFA | 500 Mo | 300 / 30 jours glissants | 3 | 30 jours |
| Business | 9 900 / 99 000 FCFA | 2 Go | 1 500 / 30 jours glissants | 10 | 90 jours |

Les prix sont des montants entiers XAF (FCFA), en unités décimales pour Mo/Go. Les prix annuels initiaux économisent respectivement 7 800 et 19 800 FCFA, soit deux mensualités. `PlanCatalog` recalcule l’économie et le nombre de mensualités à partir des montants réellement configurés ; aucun avantage annuel n’est figé dans React.

Les profils gratuits `guest` et `registered` ont les mêmes quotas initiaux. Ils conservent leur configuration centrale existante (`Settings`, `tool_settings`, `conversion.limits`). Leur consommation reste liée à la session ou au compte. Une inscription ne transfère pas les fichiers d’une session visiteur.

## Limites réelles et fonctionnalités

Les plafonds d’abonnement ne suppriment pas les limites de sécurité propres aux formats : documents 10 Mo / 50 pages, médias avec leurs limites de durée/résolution et entrée désormais bornée à 2 Go, reconnaissance et images selon leurs budgets existants. Le plafond effectif d’un média est le minimum entre la formule et le plafond du moteur. Les médias restent traités en flux avec les mêmes plafonds mémoire, sortie et durée. Ces limites sont affichées sur les outils et signalées sur la grille tarifaire. **Aucun test d’envoi physique de 2 Go n’a été effectué** ; les limites PHP de ce poste sont `2G` pour fichier et requête, à revérifier sur le serveur web de déploiement. Un frontal Apache/Nginx ou PHP peut imposer un plafond supplémentaire. Les codecs, pixels, mémoire et délais ne sont pas augmentés artificiellement pour annoncer une capacité non vérifiée.

L’historique est la conservation des **métadonnées des tâches** ; les fichiers restent téléchargeables pendant leur conservation distincte (24 h initialement). `history_expires_at` est enregistré à l’admission. L’index et la politique d’accès vérifient à la fois cette date et la fenêtre du plan actuel : une mise à niveau ne ressuscite pas un ancien historique expiré, et le retour au Gratuit réduit la visibilité à 24 h. Le nettoyage respecte la date enregistrée, sans purge des fichiers détenus par un worker vivant.

Les tâches simultanées sont les admissions actives, en file ou en traitement. Chaque fichier d’un lot occupe une place : un lot ne permet pas de contourner les dix tâches Business. Les budgets globaux du serveur et des moteurs restent contraignants ; démarrer plusieurs workers ou augmenter la concurrence native exige une mesure des ressources. Le traitement prioritaire utilise la file existante : un travail standard récent cède devant un travail prioritaire en attente. Après une minute, les travaux standards ne sont plus différés pour cette raison, afin d’éviter leur famine.

Les droits sont stockés dans `plans.entitlements` :

- `priority_processing` : priorité réelle à l’admission et au traitement.
- `advanced_conversions` : détourage, OCR et transcription, liste centrale `billing.advanced_tools`.
- `batch_processing` : conversion de plusieurs fichiers en un lot ; réservée initialement à Business. Une fusion PDF reste une opération unique à entrées multiples, distincte d’un lot de conversions.
- `priority_support` : droit de support prioritaire, sans dépendance à un prestataire de support payant.

Les descriptions normalisées des droits sont dérivées des indicateurs effectifs. Les administrateurs peuvent ajouter des descriptions personnalisées et restreindre les outils (`allowed_tools`). Un droit ne rend jamais disponible un moteur non vérifié. Les vérifications ont lieu dans l’admission Laravel existante, pas seulement dans les composants React.

Les quotas mensuels utilisent une fenêtre de **30 jours glissants**, y compris pour une périodicité annuelle et pendant l’essai. La consommation déjà comptabilisée ne se remet pas à zéro lors d’un changement de périodicité ou d’une nouvelle demande de souscription. Les quotas distincts des téléchargements externes sont conservés. La suppression d’un résultat ne supprime pas la consommation facturable ; les refus internes remboursables suivent la logique existante.

## Souscription et essai sans paiement

La page `/souscription` reprend le plan et la périodicité choisis sur `/tarifs`. Elle exige une connexion et propose explicitement « Payer maintenant » et « Essayer gratuitement pendant N jours ». Le compte doit accepter le récapitulatif avant confirmation. Le retour de connexion/inscription est limité au chemin interne `/souscription`, sans redirection externe ouverte.

**Règle anti-abus : un seul essai par compte, toutes formules confondues, pour toute la durée de vie du compte.** Changer de formule, de périodicité, annuler un essai ou créer une autre demande ne permet pas de recommencer. Cette règle porte sur le compte ; elle ne prétend pas identifier une même personne créant plusieurs identités distinctes. Les protections d’inscription, unicité email, session, CSRF et limitations de débit existantes restent en place.

Cycle de confirmation :

1. `Subscriptions::quote()` vérifie disponibilité, prix, périodicité et éligibilité, puis crée un récapitulatif serveur valable dix minutes.
2. Le récapitulatif conserve le montant, la durée et l’empreinte des conditions du plan. Un changement de prix, quotas, droits ou durée invalide une confirmation non encore effectuée.
3. La confirmation s’exécute sous le mutex transactionnel existant du pipeline. Le récapitulatif doit appartenir à l’utilisateur et correspondre au mode (`trial` ou `pay`).
4. Un essai crée un abonnement `trialing`, ses dates de début et de fin, une attribution persistante dans `trial_grants`, des événements d’historique et le pointeur d’accès courant. Ses quotas et fonctionnalités sont immédiatement effectifs, sans fournisseur de paiement.
5. La contrainte unique `trial_grants.user_id` et le mutex empêchent deux essais concurrents. Rejouer une même confirmation retourne le même abonnement, sans prolonger sa date ni recréer de droits, même après son expiration.

La durée initiale est de 15 jours. Une modification admin ne touche que les nouveaux essais ; les dates du registre existant restent immuables. Une modification de périodicité pendant l’essai met à jour le tarif choisi après vérification du montant actuel, sans changer la fin ni la consommation. Changer de formule n’ouvre pas un nouvel essai. Un changement payant n’accorde de nouveaux droits qu’après une future confirmation réelle du prestataire.

`subscriptions:reconcile`, planifié chaque minute sans chevauchement, traite les comptes avec période ou état à réconcilier. À la fin exacte de l’essai, sans paiement confirmé, le statut devient `expired` et le compte retrouve une souscription gratuite. Chaque résolution de droits effectue aussi cette vérification : un scheduler arrêté ne prolonge pas l’essai. Les travaux déjà admis conservent leurs budgets et dates enregistrés.

Les rappels **J-3 et J-1** sont des notifications **dans l’application**, enregistrées une fois dans `subscription_notices` grâce à une clé unique par abonnement/seuil. Aucun email ni SMS n’est envoyé. En cas d’arrêt prolongé, le rappel correspondant à la période encore utile est créé à la reprise ; aucun rappel périmé n’est présenté comme envoyé à temps. `Mon abonnement` affiche ces rappels, les dates et le temps restant.

## Paiement honnête et états

`BillingGateway` utilise maintenant `SaspayGateway` : checkout hébergé de production, vérification du statut de session et de la transaction, webhook signé et registre idempotent `payment_attempts`. « Payer maintenant » crée d’abord une demande `pending_payment`, sans modifier l’accès courant, puis renvoie l’URL de paiement. L’activation intervient seulement après confirmation serveur. Sans clé configurée, le comportement de demande en attente (`202`) est conservé. Voir le [guide SasPay](saspay.md).

Les états pris en charge sont `trialing`, `active`, `expired`, `canceled`, `pending_payment`. Le statut d’un paiement en attente n’est pas le plan actif. Les anciens libellés d’annulation sont normalisés en `canceled` pour les abonnements ; le pipeline de fichiers garde son statut `cancelled` existant. Aucun prélèvement automatique ni passage payant implicite n’a été ajouté.

Le checkout envoie la devise XAF de la souscription ; les moyens proposés dépendent des pays et réseaux ouverts au compte SasPay. Aucun prélèvement récurrent ni remboursement automatique n’est implémenté. Un retour navigateur ou une simple modification de `users.plan` n’est jamais une preuve de paiement. Les nouveaux essais sont bloqués par l’exigence de validation des informations de paiement : aucune validation sans débit n’est documentée par SasPay. Les essais existants conservent leur cycle de vie.

## Endpoints et administration

Sous `/api/v1`, avec les protections web existantes :

| Endpoint | Comportement |
| --- | --- |
| `GET /plans` | Catalogue, prix, économies annuelles, essais, quotas et droits. |
| `GET /me/subscription` | Accès courant, essai, notices, historique privé et demandes de paiement séparées. |
| `POST /subscriptions/quote` | Authentifié, choix `plan`, `period`, `mode`, récapitulatif serveur. |
| `POST /subscriptions/trial` | Authentifié, `quote_id` et `confirmed=true`, confirmation idempotente. |
| `POST /subscriptions/checkout` | Authentifié, confirmation d’une demande de paiement, sans paiement simulé. |
| `PATCH /subscriptions/period` | Essai en cours, période et prix attendu, confirmation requise, sans prolongation. |
| `POST /subscriptions/cancel-trial` | Confirmation requise, retour immédiat au Gratuit, essai consommé définitivement. |
| `GET /admin/subscriptions?status=...` | Admin uniquement, vue paginée des cinq états, sans références de paiement ni contenu de fichiers. |
| `GET/POST /admin/plans`, `PATCH /admin/plans/{id}` | Gestion protégée et auditée des prix, périodes, quotas, droits, disponibilité et durée des essais. |

La durée admin est bornée de 1 à 60 jours pour un essai activable. Le Gratuit reste permanent à 0 FCFA et ne peut avoir d’essai. Les bornes communes de quotas sont dans `conversion.quota_bounds` ; le stockage global et les budgets des moteurs demeurent distincts. Aucun paramètre financier ou d’exécutable n’est accepté dans une requête de conversion.

## Fichiers modifiés

- Migration `2026_10_30_000007_enable_subscription_trials.php` ; modèles `TrialGrant`, `SubscriptionIntent`, `SubscriptionNotice` ; extensions de `Plan`, `Subscription`, `User`, `ConversionJob`.
- Services existants `Subscriptions`, `PlanCatalog`, `Conversions/Owner` et points d’admission/consommation de `Conversions/Pipeline` ; priorité dans `ProcessConversion`.
- Contrôleurs `SubscriptionController`, `AdminPlanController`, `ConversionController`, `ToolController`, `PageController` ; validateurs admin, politique de tâches, ressource de conversion, messages métier et routes.
- `ReconcileSubscriptions`, planification minute, nettoyage de l’historique dans `ReconcileConversions` ; configuration centrale `conversion.php` et nouveau `billing.php`.
- React : `Plans`, nouveau `Subscribe`, `Subscription`, `AdminPlans`, nouveau `AdminSubscriptions`, `PlanTrialFields`, types de plans/quotas, compteur réutilisable `QuotaMeter`, navigation/auth et styles.
- Les écrans de conversion réutilisent le quota journalier ou mensuel renvoyé par le serveur ; la sélection de lots d’images reflète les droits Business. Les codecs et workers natifs ne sont pas réécrits.
- Le contrôle des fichiers temporaires dans `RecognitionEngine` tolère leur suppression normale entre inventaire et lecture de taille : correction de la course `UnableToRetrieveMetadata` observée pendant les tests OCR/transcription, sans contourner les limites mémoire ni la validation finale.
- Tests : `TrialSubscriptionTest`, `ConcurrentTrialTest` et son helper de deux processus, tests de formulaire React, fixtures de plans et adaptations des assertions de l’ancienne grille. Les fixtures centrées sur les moteurs activent explicitement leurs capacités pour isoler leurs tests ; les tests d’abonnement vérifient les restrictions réelles par défaut.

## Déploiement et validation

Sauvegarder la base, appliquer les migrations (`php artisan migrate --force`), compiler (`npm run build`), redémarrer les workers et conserver le scheduler. La migration ne réécrit pas les conversions ; elle initialise leurs échéances d’historique et applique la grille demandée. Un rollback détruit notamment le registre anti-abus : ne pas le faire sur des essais vivants sans procédure de migration et sauvegarde.

Vérifier PHP et le frontal HTTP avant d’accepter de gros envois ; mesurer les ressources avant de relever la concurrence native. Validation locale sur Windows/SQLite ; le contrôle visuel navigateur et les charges de 2 Go nécessitent une validation sur l’hôte cible. Aucun paiement ni déploiement distant n’a été effectué.

Commandes : `php artisan test`, `php vendor/bin/pint --test`, `npm test`, `npm run lint`, `npm run build`. Ne pas exécuter deux suites PHP simultanément, car les tests de moteurs partagent des fixtures. Les tests de concurrence démarrent deux processus PHP indépendants sur une base temporaire dédiée et contrôlent même récapitulatif et récapitulatifs concurrents de formules différentes.

Résultats finaux : **187 tests PHP réussis (1 911 assertions)** et **35 tests React réussis** ; Pint, ESLint, TypeScript et build Vite réussis. Le parcours HTTP réel avec cookies et CSRF a vérifié inscription, essai Pro, confirmation répétée, maintien de la date en changeant de périodicité, demande Business en attente et rejet d’un second essai. Un fichier WAV réel de 26 Mo est refusé en Gratuit, puis converti en MP3 pendant un essai Pro. Les migrations sont appliquées localement et les workers relancés. Aucun paiement réel ni débit simulé n’a été effectué.
# Moyens de paiement : carte bancaire et Mobile Money

La souscription affiche, après « Payer maintenant », les choix Carte bancaire et Mobile Money fournis par `GET /api/v1/payment-methods`. Le récapitulatif reprend le moyen choisi. Les champs carte (titulaire, numéro, expiration, CVV) et Mobile Money (opérateur, téléphone) sont visibles en aperçu désactivé tant qu’aucun prestataire n’est intégré. Aucun numéro de carte, téléphone, PIN ou code de validation n’est collecté ; les endpoints refusent explicitement les champs sensibles connus.

La migration `2026_10_30_000008_add_subscription_payment_method.php` ajoute uniquement `payment_method` aux récapitulatifs et abonnements. Le backend exige une méthode autorisée pour une demande de paiement, interdit ce paramètre pour un essai, puis conserve la méthode du récapitulatif confirmé dans l’historique. La demande reste `pending_payment`, sans débit ni nouveaux droits.

Fichiers concernés : `config/billing.php`, `routes/accounts.php`, `SubscriptionController.php`, `Subscriptions.php`, la migration ci-dessus, `resources/js/pages/Subscribe.tsx`, `resources/css/app.css`, `TrialSubscriptionTest.php` et `resources/js/tests/subscribe.test.tsx`. Validation : 36 tests frontend, build TypeScript/Vite et ESLint ; aperçu navigateur local avec API simulée aux largeurs 1440, 390 et 320 px, sans débordement. Le prestataire devra ultérieurement fournir ses champs sécurisés et la confirmation serveur réelle avant toute activation payante.

