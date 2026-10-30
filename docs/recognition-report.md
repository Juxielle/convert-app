# Livraison — OCR et transcription audio/vidéo

29 octobre 2026. Les deux services demandés sont développés, testés et activés sur ce poste.

## Livré

- **OCR d’image** : JPG/JPEG, PNG et WebP fixes, français/anglais, trois modes de mise en page, sortie TXT UTF-8.
- **Texte depuis audio ou vidéo** : sept formats audio et cinq conteneurs vidéo selon les codecs vérifiés, langue automatique/français/anglais, piste audio extraite localement puis transcrite, sortie TXT UTF-8 sans traduction.
- Interface de dépôt unique, options, quota réel, suivi, annulation, téléchargement privé, aperçu de texte brut et copie. Résultats à relire ; avertissements sur OCR imparfait, bruit et hallucinations possibles de transcription.
- Pipeline privé existant, queue conversions, propriétés/quotas/nettoyage conservés, réservation des intermédiaires et un traitement de reconnaissance à la fois. Aucun appel à une API tierce. Catalogue : **18 fiches** sur ce poste.

## Fichiers et dépendances

Nouveaux `config/recognition.php`, `RecognitionEngine`, `VerifyRecognition`, `bin/recognition-worker.py`, `bin/recognition_limits.py`, page/test React RecognitionTool, tests PHP et fixtures, guide recognition.md. Intégrations ciblées dans registre, Form Request, catalogue/API, job, précontrôle de production, configuration, routes React, styles et copie d’aide. README/architecture/API/exploitation/déploiement actualisés. Aucun changement aux routes de téléchargement ou aux policies existantes.

Pas de migration, pas de nouveau paquet Composer/npm/pip. Tesseract 5.5.3 et données fra/eng, Whisper.cpp b5454/v1.9.5 CPU et modèle tiny multilingue ajoutés sous `.tools`. Archives et modèle comparés aux SHA-256 publiés ; licences conservées. L’installateur OCR a été extrait, pas installé globalement. L’extracteur 7-Zip a lui-même été extrait en mode administratif MSI dans le projet, sans service permanent ni changement du PATH. Les détails et empreintes figurent dans [recognition.md](recognition.md).

## Vérification réelle

| Contrôle | Résultat |
| --- | --- |
| `recognition:verify` | 19 scénarios natifs réussis, 95 assertions, puis marqueur créé ; le scénario d’auto-détection ajouté ensuite passe dans la suite complète |
| Suite PHP complète finale | **140 tests réussis, 1 575 assertions**, 199,62 s |
| Dont reconnaissance | 20 scénarios : OCR trois formats et accents, douze formats audio/vidéo, français, auto-détection, silence/image vide, limites et refus d’options/batches/MIME falsifié, timeout natif |
| Tests React | **23 tests réussis**, huit fichiers ; nouveaux parcours OCR/transcription et aperçu HTML apparent rendu comme texte |
| TypeScript / build / lint | Réussis |
| Pint / Composer validate | Réussis |
| HTTP réel session/CSRF | PNG → TXT 103 octets ; MP4 → TXT 108 octets. Les mots attendus sont contrôlés, téléchargement privé 200 ; tâches synthétiques supprimées via leur session (200) |

Les essais natifs ont identifié le besoin de commit mémoire supérieur de Whisper : la reconnaissance possède donc son propre sandbox, sans relever celui des conversions médias existantes. Mesure du CLI sur le fixture de onze secondes : working set échantillonné environ 162,5 Mio, mémoire privée environ 653,1 Mio. Le plafond Windows est 1 Gio/processus et 1 280 Mio/groupe pour la parole ; OCR conserve 512/768 Mio. Cette mesure ne remplace pas celle du VPS partagé.

Les fichiers de test sont publics ou synthétiques : échantillon JFK du dépôt whisper.cpp et voix française générée localement. Aucun fichier utilisateur envoyé ou utilisé pour les tests. Les serveurs HTTP de vérification sont arrêtés après usage.

## Limites et exploitation

OCR : 10 Mo / 6 MP. Parole : 25 Mo / 60 s de source, restrictions des codecs/pistes/résolution du moteur média. TXT ≤ 1 Mo, timeout du rôle et conservation existants. Le marqueur privé recognition-ready.json conditionne la disponibilité et doit être régénéré après changement de script/moteur/modèle. Le worker conversions existant suffit.

La qualité du modèle tiny et de l’OCR dépend des fichiers ; aucune conversion parfaite garantie. Tests effectués sous Windows/SQLite et jsdom, sans automatisation dans un vrai navigateur. Linux, mémoire/CPU réels du VPS, restrictions du service et qualité sur des corpus métier restent à vérifier avant ouverture publique. Aucun déploiement, service distant ou serveur de production modifié.
