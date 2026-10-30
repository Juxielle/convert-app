# SasPay

L’intégration utilise exclusivement l’API de production `https://api.saspay.me/api/v1`. La clé `sk_live_...` est lue depuis `SASPAY_API_KEY` dans `.env`, jamais exposée au navigateur. Les tests utilisent des réponses HTTP simulées et ne lisent pas la clé réelle.

## Paiement

Après acceptation du récapitulatif, `POST /api/v1/subscriptions/checkout` crée une souscription en attente puis une session via `POST /checkout-sessions/`. Le navigateur rejoint l’URL `https://pay.saspay.me/checkout/...` renvoyée par SasPay. Carte et Mobile Money sont des préférences enregistrées ; le checkout propose les moyens réellement disponibles dans le pays du client. La documentation ne fournit pas de paramètre pour imposer cette préférence à la session.

Le prix et la devise viennent de la souscription, jamais du navigateur. Une tentative conserve une clé d’idempotence UUID et le corps exact envoyé pour réutiliser la même intention après une coupure réseau. Les coordonnées bancaires sont recueillies par SasPay.

Le retour `/souscription?payment=<id>` appelle le statut de la session puis `GET /payments/{id}/verify/`. L’accès est activé seulement si la session est payée, la transaction réussie et entrante, et si le montant, la devise et la référence de commande correspondent. Les vérifications répétées ne prolongent pas la période. Un paiement tardif sur une demande remplacée est enregistré avec `payment_review_required` et nécessite une intervention du support.

Un paiement confirmé ouvre un mois ou un an, sans renouvellement automatique. Les éventuels frais sont présentés par SasPay avant paiement. Un accès payé conserve sa validité si la clé API est temporairement absente.

## Configuration du webhook

1. Définir `APP_URL` avec l’adresse publique HTTPS du site ; l’adresse locale actuelle ne reçoit pas les notifications externes.
2. Dans le tableau de bord SasPay, créer un webhook de production vers `https://<domaine>/api/v1/billing/saspay/webhook` et l’abonner à `transaction.success`.
3. Copier son secret de signature dans `SASPAY_WEBHOOK_SECRET`, côté serveur.
4. Exécuter `php artisan config:clear` après changement des variables.

La signature HMAC-SHA256 est vérifiée sur `<timestamp>.<corps brut>`, avec une tolérance de cinq minutes. Le webhook vérifie également la transaction via l’API et son appartenance au checkout. Sans secret, il est refusé ; la vérification authentifiée au retour sur le site reste utilisable.

Pour rattraper des notifications manquées sans initier de paiement :

```sh
php artisan billing:saspay:reconcile
```

Cette commande n’est pas ajoutée automatiquement au planificateur.

## Essai gratuit

Les informations de paiement sont requises pour l’essai. L’API publique consultée ne documente pas de validation sans débit ou de tokenisation de moyen de paiement. La confirmation de nouveaux essais est donc bloquée, y compris si la clé API manque. Les essais existants conservent leur cycle de vie. Aucun paiement de production n’est lancé pour simuler un essai gratuit.

Les tests du cycle de vie des anciens essais désactivent explicitement cette exigence dans leur configuration isolée. Les tests SasPay vérifient son blocage par défaut.

## Documentation fournisseur

- [Introduction](https://docs.saspay.me/api-reference/introduction)
- [Créer un checkout](https://docs.saspay.me/api-reference/payments/checkout-create)
- [Statut du checkout](https://docs.saspay.me/api-reference/payments/checkout-status)
- [Vérifier un paiement](https://docs.saspay.me/api-reference/payments/verify)
- [Webhooks et signatures](https://docs.saspay.me/api-reference/webhooks)

Le service n’implémente pas les retraits, remboursements ni prélèvements récurrents.
