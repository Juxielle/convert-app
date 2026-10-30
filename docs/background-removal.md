# Détourage et retouches d’image

Outil : `/outils/supprimer-fond`. Entrées JPEG, PNG et WebP statiques, limites images existantes (6 mégapixels, 8192 pixels par côté). Sortie PNG RGBA aux dimensions d’origine. Les transparences initiales sont conservées ; les métadonnées sont retirées.

Le détourage automatique utilise le modèle léger U²-Net (u2netp), exécuté sur CPU dans un processus Python borné et sur la queue `conversions`. Il compte comme une opération normale, avec les mêmes propriétaires, quotas, annulation, durée et expiration. Aucun téléchargement de modèle ni envoi vers une API ne se produit pendant le traitement d’une image.

L’éditeur permet ensuite de sélectionner une zone contiguë de couleurs similaires (surlignée en rouge avant confirmation), de supprimer/restaurer cette zone, ou d’effacer/restaurer au pinceau. Il conserve cinq retouches annulables et propose la comparaison avec l’original. Cette sélection est fondée sur les couleurs ; elle ne constitue pas une segmentation sémantique de chaque objet. Le résultat automatique peut nécessiter des corrections sur les cheveux, transparences et fonds complexes.

Les retouches sont locales au navigateur et ne consomment pas de quota supplémentaire. Le téléchargement PNG inclut les retouches, jamais la sélection rouge ni le damier. Le résultat automatique non retouché reste dans Mes fichiers ; les retouches doivent être téléchargées avant de quitter la page. Relancer le détourage travaille sur l’original et remplace les retouches (annulable).

## Installation sur un autre poste

Installer les dépendances dans l’environnement isolé existant :

```powershell
.tools/python/Scripts/python.exe -m pip install -r requirements-background.txt
```

Sur Linux, utiliser `.tools/python/bin/python`. Télécharger le [modèle u2netp officiel distribué par rembg](https://github.com/danielgatis/rembg/releases/download/v0.0.0/u2netp.onnx) dans `.tools/background/u2netp.onnx`. SHA-256 attendu : `309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8`. Le registre refuse un fichier d’une autre empreinte. Code et modèle U²-Net : [dépôt officiel et licence Apache 2.0](https://github.com/xuebinqin/U-2-Net). Prétraitement compatible avec [la session u2netp de rembg](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/u2netp.py) ; inférence via [ONNX Runtime](https://onnxruntime.ai/docs/api/python/api_summary.html).

```powershell
php artisan background:verify
php artisan queue:restart
php artisan app:dev
```

`background:verify` exécute réellement le modèle, vérifie transparence/sujet, dimensions, accès privé et validation, puis crée le marqueur local `.tools/background-ready.json`. Ne pas exécuter cette commande simultanément avec une autre suite PHP : elles partagent le disque de test. Modèle, environnement Python et marqueur ne sont pas suivis par Git. Refaire la vérification après modification du worker.

Variables facultatives : `CONVERSION_BACKGROUND_ENABLED=false`, `CONVERSION_BACKGROUND_PYTHON`, `CONVERSION_BACKGROUND_MODEL`. Si les dépendances, le modèle ou la vérification manquent, l’outil reste indisponible. Le plafond mémoire enfant est 512 Mo et le worker existant limite les tâches simultanées. Mesurer les ressources sur le serveur cible avant activation.
