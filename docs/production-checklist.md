# Checklist de mise en production

État au 29 octobre 2026 : développement local livré, **ouverture publique non validée**. Cocher uniquement après observation sur l’hôte cible. Le guide [deployment-apache.md](deployment-apache.md) et les exemples `deploy/` n’ont pas été installés sur un serveur distant.

## Validé localement

- [x] Conversions réelles images, documents et médias ; résultats privés et téléchargements contrôlés.
- [x] Quotas atomiques, concurrence, annulation, délai et réconciliation testés.
- [x] Comptes, rôles, CSRF réel, accès inter-propriétaires, administration sans accès aux fichiers.
- [x] Commons CC0 vérifié localement ; aucune requête externe sans activation/contact/marqueur et contrôles réseau.
- [x] URL/DNS IPv4/IPv6 et redirections testés hors réseau pour le garde de sources futures.
- [x] Métadonnées HTML par route, canoniques, robots/sitemap et plans gratuits contrôlés côté serveur.
- [x] Parcours HTTP réel avec serveur local, cookies, CSRF, worker séparé et décodage du résultat.
- [x] Tests PHP/React, TypeScript, lint, build et style exécutés ; résultats dans les rapports.

## Obligatoire avant ouverture

- [ ] Choisir l’origine HTTPS définitive et configurer APP_URL ; vérifier certificats et renouvellement.
- [ ] Inventorier PHP web/CLI, extensions, Apache, MySQL/MariaDB, Python, LibreOffice et codecs FFmpeg Linux.
- [ ] Exécuter les tests de verrouillage/concurrence contre une base MySQL **de test distincte**, sans réinitialiser la base réelle.
- [ ] Tester chaque moteur dans le service Linux non privilégié, son sandbox, ses limites mémoire/CPU et ses permissions.
- [ ] Mesurer le VPS partagé en charge bornée, garantir la réserve disque et surveiller les autres sites.
- [ ] Vérifier filtrage réseau réel du worker ; pas d’accès Internet pour les conversions locales.
- [ ] Installer worker supervisé et cron ; observer signal worker, redémarrage, annulation et nettoyage effectif.
- [ ] Valider APP_DEBUG=false, cookies Secure/HttpOnly/SameSite, CORS fermé et proxies explicitement fiables.
- [ ] Vérifier 404/403 sur .env, racine du dépôt, fichiers privés et éventuels liens symboliques.
- [ ] Tester en vrai navigateur mobile/desktop : clavier, focus, contraste, thèmes, dépôt, réglages, téléchargement, historique et administration.
- [ ] Vérifier absence d’erreurs CSP en production, métadonnées de navigation et comportement des canoniques.
- [ ] Configurer SMTP réel et tester l’envoi/réinitialisation sans journaliser de secret ni envoyer à des tiers non autorisés.
- [ ] Créer le premier compte volontairement, attribuer le rôle via `accounts:admin` et vérifier son audit.
- [ ] Sauvegarder les données utiles et la clé d’application ; exécuter une restauration et un retour arrière contrôlés.
- [ ] Définir rotation des logs, contacts/alertes et rétention des backups ; vérifier aussi les logs du proxy et de l’hébergeur.
- [ ] Compléter identité, contact, hébergeur, modalités d’exercice des droits et pages légales avec l’exploitant.
- [ ] Exécuter `php artisan app:production-check` avec succès et résoudre les validations manuelles affichées.
- [ ] Ouvrir le service après ces contrôles ; activer l’indexation seulement si souhaitée.

## Sources externes et paiement

- [x] Commons/WebM/CC0 seul adaptateur autorisé localement ; autres sources fermées, aucun paiement intégré.
- [ ] Si une source est demandée : valider ses droits/API, développer l’adaptateur et le transport DNS épinglé, tester limites, redirections et quotas externes atomiques séparés **avant toute activation**.
- [ ] Si une offre premium est demandée : choisir le prestataire compatible avec le pays, tester les webhooks et l’attribution de plan, puis seulement publier l’offre. Le plan premium actuel n’est ni public ni assignable depuis le navigateur.
