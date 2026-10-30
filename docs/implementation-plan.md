# Plan d’implémentation

Le développement local des phases 0 à 9 est livré, avec rapports et tests. Les phases 6 à 9 ont été enchaînées sur autorisation explicite de l’utilisateur. Commons WebM CC0 a été validé réellement et activé localement ; les autres sources restent fermées. Comptes, administration, SEO et plans gratuits sont implémentés. **La phase 8 fournit le durcissement et la préparation du déploiement, sans valider l’ouverture publique** : MySQL/Linux, ressources du VPS, SMTP, certificats, sauvegarde/restauration et mentions légales restent à vérifier selon production-checklist.md. Voir les rapports 6 à 9.

| Phase | Livrables | Condition de sortie |
| --- | --- | --- |
| 0 — Audit | architecture, plan, risques, audit local | Documents examinés ; aucun code métier modifié |
| 1 — Socle | Laravel, React/TS/Vite, API v1, composants accessibles, pages françaises, thèmes, erreurs uniformes | Application démarrable, tests du socle et build réussis ; outils indisponibles clairement indiqués |
| 2 — Pipeline | migrations, disque privé, policies, Form Requests, queue database, réservation atomique, téléchargement, annulation, expiration et réconciliation | Faux moteur uniquement dans les tests ; tests IDOR, quota concurrent, limites, panne worker et expiration réussis |
| 3 — Images | Évaluation des moteurs, JPEG/PNG/WebP, compression, dimensions, lots bornés | Sorties réelles ouvrables ; tests MIME falsifié, pixels excessifs, transparence et lots ; catalogue limité aux formats validés |
| 4 — Documents/PDF | LibreOffice isolé, conversions Office/PDF, CSV/XLSX, opérations PDF sélectionnées | Fixtures représentatives, limites de pages, documents invalides/protégés, vérification des sorties et licences |
| 5 — Médias | Inventaire codecs FFmpeg/ffprobe, profils fixes audio/vidéo, extraction, annulation | Tests réels des formats annoncés, durée/résolution/pistes, timeout et charge contrôlée |
| 6 — Sources externes | Registre de sources, arrêt immédiat, garde SSRF, quotas séparés | Activation seulement pour sources autorisées et testées ; tests DNS, IPv6 et redirections |
| 7 — Comptes/admin | Inscription, connexion, réinitialisation, profil, historique, métriques, réglages bornés et audit | Tests de rôles, CSRF, rate limiting et séparation des propriétaires |
| 8 — Production | Audit sécurité, E2E, charge, guide Apache/HTTPS, service worker, cron, sauvegarde et rollback | Checklist documentée, limites mesurées sur VPS, aucune modification automatique du serveur |
| 9 — SEO/plans | Métadonnées des outils fonctionnels, canonicals, sitemap, robots, abstraction de plans | Accessibilité/build/tests, aucune promesse de format non testé ou paiement obligatoire |

## Préparation de la phase 1

Vérifier les exigences des versions stables actuelles dans les sources officielles avant installation. Garder une seule racine Laravel, `resources/js` pour React et un build Vite commun. Utiliser l’exécution directe du CLI npm documentée dans l’audit si le wrapper reste bloqué. Vérifier l’accès aux registres Composer/npm et conserver les lockfiles. Ne pas installer de service système au titre du socle.

Configurer les bases de tests sans toucher à une base existante. Utiliser SQLite pour les tests rapides puis une base MySQL de test distincte pour les tests transactionnels. Aucun identifiant de production dans le dépôt. Ne pas initialiser des conversions synchrones dans les contrôleurs HTTP.

## Limites initiales à implémenter en phase 2

| Paramètre | Visiteur | Compte gratuit |
| --- | --- | --- |
| Taille par fichier | 25 Mo | 100 Mo |
| Opérations par heure/jour | 5 / 10 | 20 / 50 |
| Tâches simultanées | 1 | 2 |
| Timeout ordinaire | 60 s | 180 s |
| Conservation des fichiers | 1 h | 24 h |

Les budgets globaux et par propriétaire de stockage, pixels, pages et durées médias doivent être définis avant activation du moteur concerné. Commencer par un worker de conversion sur le VPS partagé, puis adapter aux mesures réelles. Fixer explicitement l’unité des tailles lors de la configuration et dans l’interface.

## Vérifications et rapports

Chaque phase fournit : fichiers modifiés, migrations et leur exécution réelle, commandes exécutées, résultats, tests non exécutables, risques et prochaine étape. Exécuter les tests Laravel, la vérification TypeScript, le lint et le build lorsqu’ils existent. Ajouter les tests d’intégration et E2E au moment où leurs parcours existent. Un test absent ou non exécuté ne vaut pas validation.

Phase 0 : quatre documents créés dans `docs/`, aucune migration, aucune installation, aucun test applicatif ni build existant. Les vérifications d’environnement sont consignées dans `environment-audit.md`.
