import type { Category } from './api';

export const categories: { id: Category; name: string; short: string; description: string }[] = [
  { id: 'images', name: 'Images', short: 'Images', description: 'Des visuels au bon format, au bon poids.' },
  { id: 'documents', name: 'PDF & documents', short: 'Documents', description: 'Vos documents, prêts à être partagés.' },
  { id: 'audio', name: 'Audio', short: 'Audio', description: 'Le bon format pour chaque écoute.' },
  { id: 'video', name: 'Vidéo', short: 'Vidéo', description: 'Des vidéos adaptées à vos usages.' },
  { id: 'external', name: 'Téléchargement autorisé', short: 'Téléchargement', description: 'Uniquement des sources validées et autorisées.' },
];

export const faqs = [
  { question: 'Puis-je déjà convertir mes fichiers ?', answer: 'Oui : images JPEG/PNG/WebP, documents DOCX/XLSX/PPTX vers PDF, opérations PDF, CSV/XLSX, audio et vidéo, ainsi que l’OCR et la transcription pour les outils activés dans le catalogue. La mise en page Office peut changer. Les résultats sont accessibles dans Mes fichiers.' },
  { question: 'Faut-il créer un compte ?', answer: 'Vous pouvez utiliser les outils en visiteur. Un compte gratuit augmente les quotas et permet de retrouver vos tâches après connexion. Les fichiers visiteurs ne sont pas transférés au compte.' },
  { question: 'Quelles seront les limites gratuites ?', answer: 'Les limites initiales sont de 25 Mo par fichier, 5 opérations par heure et 10 par jour pour les visiteurs. Elles seront ajustées aux capacités réelles du serveur. Un Mo correspond ici à 1 000 000 octets.' },
  { question: 'Combien de temps mes fichiers seront-ils conservés ?', answer: 'Les fichiers visiteurs sont conservés au maximum une heure et ceux des comptes au maximum 24 heures selon la configuration du service. L’accès au résultat expire automatiquement. L’expiration est contrôlée à chaque téléchargement. La durée commence à l’envoi ; le nettoyage physique dépend du scheduler du serveur.' },
  { question: 'Tous les formats seront-ils disponibles ?', answer: 'Seuls les formats testés de bout en bout seront activés. Les fiches disponibles affichent leurs formats actifs ; les autres fiches présentent les formats envisagés. La mise en page des documents complexes peut être altérée lors d’une conversion.' },
  { question: 'Quelles vidéos pourrai-je télécharger ?', answer: 'Le module externe accepte uniquement les vidéos WebM Commons marquées CC0 1.0 si sa vérification et son activation sont effectives sur cet hôte. Les autres plateformes et licences restent refusées. Consultez les droits et la provenance de chaque fichier. Aucun contournement de connexion, de DRM ou de restriction d’accès ne sera proposé.' },
];

// Centralized French copy keeps the first release ready for future translations.
export const fr = {
  preparing: 'En préparation', disabled: 'Désactivé', allTools: 'Tous les outils',
  search: 'Rechercher un outil', clearSearch: 'Effacer la recherche',
  loadError: 'Impossible de charger les outils.', retry: 'Réessayer',
};
