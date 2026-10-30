# Extension des langues de reconnaissance

29 octobre 2026. Vingt langues explicitement sélectionnables en transcription : français, anglais, espagnol, portugais, allemand, italien, arabe, chinois/mandarin, hindi, russe, japonais, coréen, bengali, indonésien, turc, vietnamien, ourdou, pendjabi, persan et swahili. L’OCR ajoute la variante chinoise traditionnelle à la variante simplifiée : vingt-et-une variantes, plus le mode combiné français/anglais. Le mode automatique reste disponible pour la parole.

## Réalisation

- Liste unique dans `config/recognition-languages.json`, consommée par PHP, Python et exposée à React par `Tool.recognition_languages`. Validation fermée des codes et des options ; aucune injection de modèle, chemin ou argument natif.
- 21 modèles OCR officiels installés dans le répertoire privé : tessdata_fast, sauf persan et ourdou en tessdata_best pour améliorer leur rendu. Blobs comparés aux empreintes Git officielles ; commits et SHA-256 conservés dans `.tools/tesseract/language-provenance.json` et assets du marqueur de vérification. L’API GitHub étant temporairement limitée, la comparaison a utilisé les arbres Git officiels récupérés sans checkout des modèles ; aucune configuration Git globale modifiée.
- Même modèle Whisper tiny multilingue déjà installé, pas de nouvelle API ni de paiement. Les langues supplémentaires n’entraînent pas le chargement simultané de tous les modèles OCR : un modèle sélectionné, ou fra+eng, conserve les budgets d’exécution existants.
- Sélecteur d’interface alimenté par le catalogue serveur ; aperçu TXT en dir=auto pour arabe, persan et ourdou. Limites de durée/taille, quotas, annulation, confidentialité et stockage inchangés.
- Documentation et installation mises à jour dans recognition.md et api.md ; aucune migration et aucun nouveau paquet Composer/npm/pip.

## Validation réellement exécutée

`php artisan recognition:verify` : **43 tests réussis, 189 assertions**, 58,53 s, puis nouveau marqueur d’activation créé. Cela inclut lecture d’un mot attendu dans chacune des 21 variantes OCR, validation de la liste API et refus de codes arbitraires, acceptation native des 20 codes Whisper, régressions OCR/audio/vidéo, références vocales français/anglais, auto-détection, silence et délai maximal.

CatalogTest/ReleaseTest : **12 tests réussis, 252 assertions**. React : **25 tests réussis**, dont sélection OCR arabe, transcription espagnole et direction du texte. TypeScript/build, lint et Pint réussis. Les autres convertisseurs natifs n’ont pas été relancés dans cette extension ciblée ; la précédente suite globale de 140 tests est rapportée séparément dans recognition-report.md.

Les images multilingues sont synthétiques, versionnées sous tests/Fixtures/recognition/languages ; fontes locales ou Noto pour les écritures concernées. Aucun fichier client, ni voix privée, utilisé ou transmis à l’extérieur. Les modèles ne sont téléchargés qu’à l’installation, jamais pendant un traitement utilisateur.

## Limites de qualité

La reconnaissance d’un mot synthétique n’est pas un benchmark sur un corpus métier. Les styles Nastaliq/calligraphiques, les textes flous et les mises en page complexes peuvent produire des erreurs. Le test des 20 codes Whisper vérifie leur support natif sur un très court fragment de référence ; il **ne valide pas la précision sémantique sur 20 corpus vocaux**. Les références vocales sémantiques demeurent français/anglais. Le petit modèle reste plus sujet aux omissions et hallucinations selon langue, accent et bruit : choisir la bonne langue et relire le texte.

L’ajout est activé localement après vérification. Sur un autre hôte, installer les 21 fichiers traineddata et refaire recognition:verify avant activation ; ne pas copier le marqueur Windows. Les limites et la charge Linux/VPS restent à mesurer avant production.
