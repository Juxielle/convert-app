# Documents, PDF et tableaux — phase 4

## Formats et opérations validés

| Outil | Entrée | Sortie | Comportement |
| --- | --- | --- | --- |
| Documents vers PDF | DOCX, XLSX, PPTX | PDF | LibreOffice headless, profil isolé par tâche |
| PDF vers images | PDF | ZIP de PNG | Une image par page, 72/96/150 dpi |
| Fusionner des PDF | 2 à 5 PDF | PDF | Ordre de la liste conservé, une opération de quota |
| Extraire des pages PDF | PDF | PDF | Plage inclusive première/dernière page |
| Tourner un PDF | PDF | PDF | Toutes les pages, 90/180/270 degrés |
| Compresser un PDF | PDF | PDF | Recompression sans perte des flux ; réduction non garantie |
| CSV vers Excel | CSV UTF-8 | XLSX | Valeurs importées comme texte, zéros initiaux conservés |
| Excel vers CSV | XLSX | CSV UTF-8 avec BOM | Une feuille choisie par numéro ; formules exportées comme texte neutralisé |

Les formats binaires anciens DOC/XLS/PPT ne sont pas activés. PDF vers DOCX n’est pas proposé : aucun moteur donnant une qualité acceptable n’a été retenu pour cette phase. Aucun outil ne promet de reconstruire parfaitement la mise en page d’un PDF en Word ou Excel.

Les conversions Office peuvent modifier polices, sauts de page et mise en page. Les profils particuliers, tableaux complexes, graphiques et documents comportant des ressources refusées demandent une vérification manuelle du résultat. Les signets, métadonnées et fonctions interactives PDF ne sont pas garantis par les opérations qui reconstruisent les pages. Conserver les originaux.

## Dépendances locales

Inventaire initial : soffice/libreoffice, qpdf, pdfinfo, pdftoppm et Ghostscript absents du PATH ; aucun dossier LibreOffice dans les Program Files inspectés. Python 3.14.3 disponible. Cette inspection ne constitue pas une recherche exhaustive de tous les disques.

Un environnement Python isolé a été créé dans `.tools/python`. Versions verrouillées dans `requirements-documents.lock.txt`, dont pypdf 6.21.4, pypdfium2 5.14.0, Pillow 13.0.0, openpyxl 3.1.5 et defusedxml 0.7.1. python-docx/python-pptx génèrent les fixtures de validation. Aucun paquet ajouté au Python global, aucune API commerciale.

LibreOffice **26.8.1.1** a été extrait localement dans `.tools/libreoffice` depuis le [téléchargement officiel](https://www.libreoffice.org/download/). Signature Authenticode vérifiée valide, signataire The Document Foundation. SHA-256 du MSI téléchargé :

```text
C6298B10CFA1748BCBEDAD94C62CE771E240E5021BFD6D7A967CD571B04B5312
```

Extraction administrative exécutée avec `msiexec /a ... /qn TARGETDIR=<projet>/.tools/libreoffice`. Ce mode prépare une image locale ; aucune configuration de serveur de production ou association de fichiers n’a été demandée. Les dossiers `.tools` et `.cache` sont ignorés par Git. Un autre poste doit installer/préparer ses propres dépendances avant de vérifier ses capacités.

## Licences retenues

- LibreOffice : [MPL 2.0 et composants tiers](https://www.libreoffice.org/licenses/) ; avis complets inclus dans `.tools/libreoffice/license.txt` et `LICENSE.html`.
- pypdf : [BSD-3-Clause](https://github.com/py-pdf/pypdf/blob/main/pyproject.toml).
- pypdfium2 : [Apache-2.0 / BSD-3-Clause](https://github.com/pypdfium2-team/pypdfium2/blob/main/README.md), PDFium et dépendances natives avec leurs avis inclus dans les wheels. Préserver ces avis lors d’une redistribution binaire.
- openpyxl : [MIT/Expat](https://openpyxl.readthedocs.io/en/stable/) ; defusedxml installé pour protéger les parseurs XML.
- Pillow : licence HPND/MIT-CMU selon les composants ; conserver ses notices distribuées dans le paquet. python-docx/python-pptx : MIT, utilisés pour les fixtures.

Les versions et notices installées doivent être revérifiées après mise à jour et avant redistribution. Le choix de ces dépendances ne remplace pas les informations légales propres à l’exploitant.

## Installation reproductible

Depuis une copie propre du projet, sous Windows :

```powershell
python -m venv .tools/python
.\.tools\python\Scripts\python.exe -m pip install -r requirements-documents.lock.txt
# Préparer LibreOffice et renseigner son chemin si différent du défaut du projet.
php artisan documents:verify
```

Variables facultatives, avec chemins absolus :

```dotenv
CONVERSION_DOCUMENTS_ENABLED=true
CONVERSION_DOCUMENT_PYTHON="C:/laragon/www/convert-app/.tools/python/Scripts/python.exe"
CONVERSION_LIBREOFFICE="C:/laragon/www/convert-app/.tools/libreoffice/program/soffice.com"
```

Sur Linux, créer le venv avec le Python adapté et configurer `CONVERSION_LIBREOFFICE=/usr/bin/libreoffice` ou le chemin réellement installé. Exécuter conversions et vérification avec un compte non root. La compatibilité Linux doit être testée sur le serveur cible ; elle n’a pas été validée dans cette phase Windows.

`documents:verify` requiert les dépendances de développement Composer et les bibliothèques de fixtures. Il exécute les tests documents dans un sous-processus avec SQLite mémoire et cache/sessions de test, puis crée `.tools/documents-ready.json` seulement en cas de succès. `documents:verify --full` exécute toute la suite PHP avant activation. Le catalogue vérifie les empreintes du worker et du verrou de dépendances ainsi que les chemins des exécutables ; une modification de ces éléments impose une nouvelle vérification. Ne pas fabriquer manuellement ce fichier. Après installation/mise à jour sur un autre hôte, refaire la vérification sur cet hôte.

La désactivation d’urgence se fait avec `CONVERSION_DOCUMENTS_ENABLED=false`, suivie du rechargement de configuration et du redémarrage des workers. Le catalogue ne renvoie jamais les chemins d’exécutables.

## Exécution et commandes natives

Le worker Laravel appelle, avec un tableau d’arguments Symfony Process :

```text
<python du venv> -I <projet>/bin/document-worker.py
```

Les chemins, opérations et options sont transmis sur stdin par le serveur ; aucun shell ou argument système libre. Pour Office, le processus Python appelle :

```text
<soffice> -env:UserInstallation=file:///<profil-unique>
  --headless --nologo --nodefault --norestore --unaccept=all
  --convert-to pdf:<filtre-fixe> --outdir <répertoire-unique> <entrée-privée>
```

Filtres fixes : writer_pdf_Export pour DOCX, calc_pdf_Export pour XLSX, impress_pdf_Export pour PPTX. Le [profil et les paramètres sont documentés par LibreOffice](https://help.libreoffice.org/latest/en-GB/text/shared/guide/start_parameters.html). Les macros sont désactivées par profil à sécurité élevée et les mises à jour de documents externes désactivées. Le profil et la sortie temporaire sont propres à chaque tâche.

Les opérations PDF utilisent pypdf ; PDFium rend les pages, Pillow les encode en PNG. Aucun exécutable qpdf/Poppler/Ghostscript requis dans cette implémentation. Le worker ne charge aucune URL client.

## Limites et protections

- 10 Mo par document ; fusion limitée à 10 Mo cumulés, 2 à 5 fichiers. Les quotas utilisateur plus stricts restent prioritaires.
- 50 pages maximum par PDF et par résultat fusionné ; dimensions de page bornées. Plafond de 20 mégapixels cumulés pour le rendu PDF en images.
- 10 000 lignes, 100 colonnes ; taille d’une cellule limitée, sélection de feuille entre 1 et 20.
- Archives OOXML : au plus 1000 entrées, 32 Mo décompressés, ratio de compression borné, chemins dangereux, archives chiffrées, macros, OLE et ressources externes liés refusés. Médias Office limités aux PNG/JPEG. SVG, WMF/EMF et autres médias intégrés ne sont pas pris en charge.
- XML lu avec defusedxml, DTD/entités et instructions externes refusées. Formules pouvant déclencher des ressources externes refusées. L’import CSV ne crée aucune formule ; l’export CSV neutralise les cellules textuelles dangereuses et conserve les nombres négatifs numériques.
- PDF protégés/chiffrés, formulaires et certaines actions actives refusés ; filtres de décompression bornés. Les pages et ressources sont examinées avant rendu natif.
- Sortie plafonnée à 32 Mo, réservée avant admission. Écritures Python plafonnées et espace de travail surveillé côté parent ; une conversion native peut être interrompue si ses fichiers temporaires consomment le budget.
- Durée propre à la tâche et contrôle d’annulation par le parent. Sous Windows, Job Object : 512 Mio par processus, 768 Mio cumulés, huit processus maximum, arrêt des descendants à la fermeture. Sous Linux, limites AS/CPU héritées et groupe de processus LibreOffice arrêté avec le worker ; cette branche reste à valider en production.
- Verrou partagé de fichiers conservé par le worker Python, compatible avec le verrou du parent ; le nettoyeur ne purge pas un processus encore vivant. La fusion utilise un staging privé, compté dans le plafond global et récupéré après abandon, sans toucher à une préparation encore verrouillée.

Le blocage des sockets Python, le filtrage des ressources OOXML et la configuration LibreOffice ne constituent pas une politique réseau OS pour le processus natif. Avant exposition publique, appliquer les restrictions réseau, permissions, supervision et limites de service Linux et tester les bibliothèques natives sur le VPS. Une surveillance disque/RAM globale reste nécessaire pour un VPS partagé.

## Utilisation locale

```powershell
php artisan serve --host=127.0.0.1 --port=8000
# Deuxième terminal :
php artisan queue:work database --queue=conversions --sleep=1 --timeout=240 --memory=256 --max-time=3600
# Nettoyage en développement :
php artisan schedule:work
```

Ouvrir `/outils/office-vers-pdf`, `/outils/pdf-vers-images`, `/outils/fusionner-pdf`, `/outils/csv-vers-xlsx` ou une autre fiche active. Les résultats passent par le téléchargement privé de la tâche et restent accessibles depuis Mes fichiers pendant leur durée de conservation.
