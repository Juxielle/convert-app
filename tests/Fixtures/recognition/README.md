# Fichiers de contrôle publics/synthétiques

- `english.wav` : échantillon JFK de [whisper.cpp v1.9.5](https://github.com/ggml-org/whisper.cpp/blob/v1.9.5/samples/jfk.wav), discours présidentiel public de 1961, environ onze secondes. Dépôt whisper.cpp sous licence MIT ; aucune voix utilisateur.
- `french.wav` : phrase synthétique générée localement avec la voix Windows Microsoft Hortense : « Bonjour le monde. Ceci est un test de transcription audio. Nous utilisons un service de conversion de fichiers. » Environ neuf secondes ; fixture de développement, aucun enregistrement personnel.

Les images et variantes des conteneurs audio/vidéo sont générées dans `.cache/recognition-fixtures` par `tests/Support/recognition-fixtures.py`. Le test OCR emploie une fonte locale : Arial sous Windows, DejaVu Sans sous Linux. Installer la fonte correspondante avant vérification sur un autre hôte.

`languages/` contient des images synthétiques et les mots attendus pour les 21 variantes OCR. Elles sont pré-générées pour tester les écritures même si la fonte n’est pas installée sur l’hôte de test. Polices de génération : Arial/Nirmala Windows, Noto Sans CJK et Noto Naskh Arabic (Noto sous SIL Open Font License). Ces fichiers ne contiennent ni capture utilisateur ni texte personnel. Ils vérifient une lecture élémentaire, pas un taux de reconnaissance sur un corpus représentatif. Les formes calligraphiques et le Nastaliq peuvent produire davantage d’erreurs que les caractères imprimés Naskh.
