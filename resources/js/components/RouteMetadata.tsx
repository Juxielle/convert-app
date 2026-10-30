import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { useTools } from '../lib/api';

const descriptions: Record<string, string> = { '/tarifs': 'La formule gratuite et les offres Pro et Business avec essai gratuit, avec des quotas transparents.',
  '/': 'Convertissez vos fichiers avec des formats vérifiés, des limites claires et des téléchargements privés.',
  '/outils': 'Choisissez parmi les outils images, PDF, documents, audio et vidéo disponibles.',
  '/faq': 'Formats, quotas et conservation : les réponses pour utiliser Convert.',
  '/offres': 'Comparez les limites du visiteur et du compte gratuit, sans paiement obligatoire.',
};
const titles: Record<string, string> = { '/souscription': 'Votre souscription — Convert', '/tarifs': 'Tarifs — Convert', '/mon-abonnement': 'Mon abonnement — Convert', '/': 'Convert — Convertir vos fichiers', '/outils': 'Tous les outils — Convert', '/faq': 'Questions fréquentes — Convert', '/offres': 'Tarifs — Convert', '/profil': 'Mon profil — Convert', '/administration': 'Administration — Convert', '/connexion': 'Connexion — Convert', '/inscription': 'Créer un compte — Convert', '/mot-de-passe-oublie': 'Mot de passe oublié — Convert', '/reinitialiser-mot-de-passe': 'Nouveau mot de passe — Convert', '/confidentialite': 'Confidentialité — Convert', '/conditions': 'Conditions d’utilisation — Convert', '/mentions-legales': 'Mentions légales — Convert' };

export default function RouteMetadata() {
  const { pathname } = useLocation();
  const tools = useTools();
  useEffect(() => {
    const tool = tools.data?.find(item => pathname === `/outils/${item.slug}`);
    const description = tool?.description ?? descriptions[pathname] ?? 'Votre espace Convert.';
    const title = tool ? `${tool.name} — Convert` : titles[pathname];
    if (title) { document.title = title; document.querySelector('meta[property="og:title"]')?.setAttribute('content', title); }
    const canonical = document.querySelector<HTMLLinkElement>('link[rel="canonical"]');
    const base = document.querySelector<HTMLMetaElement>('meta[name="canonical-base"]')?.content;
    const indexing = document.querySelector<HTMLMetaElement>('meta[name="indexing-enabled"]')?.content === 'true';
    document.querySelector('meta[name="description"]')?.setAttribute('content', description);
    document.querySelector('meta[property="og:description"]')?.setAttribute('content', description);
    const url = base ? base.replace(/\/$/, '') + pathname : undefined;
    if (canonical && url) canonical.href = url;
    if (url) document.querySelector('meta[property="og:url"]')?.setAttribute('content', url);
    const publicPage = !!descriptions[pathname] || !!tool?.enabled;
    document.querySelector('meta[name="robots"]')?.setAttribute('content', indexing && publicPage ? 'index, follow' : 'noindex, nofollow');
    let schema = document.querySelector<HTMLScriptElement>('script[type="application/ld+json"]');
    if (publicPage && url) {
      if (!schema) { schema = document.createElement('script'); schema.type = 'application/ld+json'; document.head.append(schema); }
      schema.textContent = JSON.stringify({ '@context': 'https://schema.org', '@type': tool ? 'WebApplication' : 'WebPage', name: tool?.name ?? document.title, description, url, inLanguage: 'fr', ...(tool ? { applicationCategory: 'UtilitiesApplication', operatingSystem: 'Web' } : {}) });
    } else schema?.remove();
  }, [pathname, tools.data]);
  return null;
}
