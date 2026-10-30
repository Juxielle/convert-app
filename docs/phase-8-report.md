# Rapport de phase 8 — durcissement et préparation de production

Préparation locale livrée ; **mise en production non réalisée ni déclarée validée**. L’absence d’accès/inventaire du VPS, de domaine HTTPS et de SMTP empêche les contrôles réels de l’hôte. Le cahier des charges demande un guide et interdit de modifier automatiquement le serveur de production : aucun serveur, service distant ou cron n’a été modifié.

## Réalisation

Revue des validations, policies, quotas, stockage, commandes natives, SSRF, cookies/CSRF/CORS, logs, nettoyage et autorisations, documentée dans [security-audit.md](security-audit.md). Ajout CSP de production, HSTS HTTPS, Permissions-Policy, Referrer-Policy no-referrer, cache privé, script de thème externe, refus des mailers de reset pouvant journaliser des tokens, limite bcrypt sans troncature et purge des audits/tokens.

Commande **app:production-check** en lecture seule, résultats structurés possibles avec --json et sans valeurs de secrets. Sur ce poste Windows/local, son échec est attendu et observé : Linux/MySQL, HTTPS/cookies Secure, SMTP, premier admin et signal de worker restent à préparer. Un succès futur des contrôles automatiques ne dispense pas des validations manuelles affichées.

[deployment-apache.md](deployment-apache.md), [production-checklist.md](production-checklist.md) et exemples `deploy/` livrés : Apache public/HTTPS, configuration, permissions, workers conversion/externe distincts, limites CPU/RAM, réseau, cron, logs minimaux, sauvegarde utile, restauration et retour arrière sans rollback destructif automatique.

## Vérifications

- Suite PHP complète après intégration Commons : **118 tests réussis, 1 469 assertions**, 161,12 s. Les derniers ajouts (kill switch et plafond du rôle) ont ensuite été vérifiés avec CommonsTest/ExternalTest/PipelineTest/AccountFlowTest/AdminTest : **35 tests réussis, 251 assertions**, couvrant aussi le parcours HTTP réel. La suite contient désormais 120 scénarios ; la dernière exécution complète portait sur 118, suivie de ces contrôles ciblés.
- Parcours HTTP complet AccountFlowTest réussi ; transport HTTP externe réel et décodage natif réussis. Les fixtures/tests s’exécutent sur bases/stockages temporaires distincts ; aucune donnée personnelle utilisée.
- Frontend : **21 tests React réussis**, TypeScript/build et lint réussis. Tests jsdom ; pas d’automatisation dans un vrai navigateur, pas de Lighthouse ni validation visuelle mobile réelle.
- Pint, composer validate --strict, composer check-platform-reqs réussis ; migrate:status confirme les six migrations locales ; schedule:list montre réconciliation minute, nettoyage des resets et purge des audits.
- Audit Composer : aucun avis ni paquet abandonné signalé. L’audit npm a détecté des avis sur Vitest 3/tinypool ; Vitest a été mis à jour en **4.1.12**, avec Testing Library DOM explicite et lockfile conservé. Un bogue de résolution npm 10 a été contourné lors de la mise à jour ; **npm ci standard a ensuite réussi**. Audit npm final : **zéro vulnérabilité signalée**. Les audits ponctuels ne garantissent pas l’absence de faille future. Sources : [Vitest](https://github.com/vitest-dev/vitest/security/advisories/GHSA-82fw-gwwq-j7x9), [tinypool](https://github.com/tinylibs/tinypool/security/advisories/GHSA-85c8-ppgw-ccpr).

Pas de nouvelle migration en phase 8. Aucun service Windows permanent installé. Les serveurs HTTP temporaires de vérification sont arrêtés après usage. Tests MySQL/Linux, restrictions systemd/egress, charge du VPS partagé, email réel, certificats, restauration et données légales restent **non validés** ; ils figurent sans case cochée dans la checklist. Les mesures Full HD locales de phase 5 et MediaLoadTest restent valables pour le contexte Windows, pas pour dimensionner le VPS.
