import { lazy, Suspense, useEffect, useState } from 'react';
import { ArrowDown, ArrowRight, ArrowUpRight, Check, ChevronRight, Clock3, Feather, FolderOpen, Layers3, LockKeyhole, Search, ShieldCheck, SlidersHorizontal, Sparkles, X } from 'lucide-react';
import { Link, Route, Routes, useLocation, useParams, useSearchParams } from 'react-router-dom';
import Layout from './components/Layout';
import Activity from './pages/Activity';
import ImageTool from './pages/ImageTool';
import DocumentTool from './pages/DocumentTool';
import MediaTool from './pages/MediaTool';
import Profile, { AuthPage } from './pages/Account';
import Plans from './pages/Plans';
import SubscriptionPage from './pages/Subscription';
import ExternalTool from './pages/ExternalTool';
import RecognitionTool from './pages/RecognitionTool';
import { CategoryIcon, Dropzone, ErrorState, FormatSelect, Loading, Notice, QuotaIndicator, ToolCard } from './components/ui';
import { useLimits, useTools } from './lib/api';
import { categories, faqs, fr } from './lib/content';

const BackgroundTool = lazy(() => import('./pages/BackgroundTool'));
const Admin = lazy(() => import('./pages/Admin'));
const Subscribe = lazy(() => import('./pages/Subscribe'));

function PageTitle({ title }: { title: string }) {
  useEffect(() => { document.title = `${title} — Convert`; }, [title]);
  return null;
}

function SearchBox({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  return <div className="search-box"><Search size={21} aria-hidden="true" /><input type="search" aria-label={fr.search} placeholder="Que souhaitez-vous faire avec vos fichiers ?" value={value} onChange={event => onChange(event.target.value)} />{value && <button className="icon-button" aria-label={fr.clearSearch} onClick={() => onChange('')}><X size={17} /></button>}<span className="search-hint">Trouvez votre outil</span></div>;
}

function FileIllustration() {
  return <div className="file-illustration" aria-hidden="true"><div className="orbit orbit-one" /><div className="orbit orbit-two" /><span className="art-spark spark-one">✳</span><span className="art-spark spark-two">+</span><div className="file-sheet sheet-back"><div className="sheet-corner" /><span className="file-type">JPG</span><div className="mini-landscape"><div /><i /></div><span className="sheet-line" /><span className="sheet-line short" /></div><div className="conversion-bubble"><ArrowRight size={30} /></div><div className="file-sheet sheet-front"><div className="sheet-corner" /><span className="file-type">WEBP</span><div className="mini-landscape"><div /><i /></div><span className="sheet-line" /><span className="sheet-line short" /><span className="file-check"><Check size={17} /></span></div><div className="floating-label"><Feather size={17} />Le bon format. Tout simplement.</div></div>;
}

function Home() {
  const [search, setSearch] = useState('');
  return <><PageTitle title="Un nouvel élan pour vos fichiers" /><section className="hero container"><div className="hero-copy"><span className="eyebrow"><span className="tiny-line" />VOS FICHIERS, DE NOUVELLES POSSIBILITÉS</span><h1>Le bon format.<br />Le bon poids.<br /><span>En toute simplicité.</span></h1><p>Images, documents, audio, vidéo : un seul espace<br className="desktop-break" /> pour donner une nouvelle forme à vos fichiers.</p><div className="hero-buttons"><a href="#outils" className="button primary">Explorer les outils <ArrowDown size={17} /></a><Link to="/faq" className="quiet-link">Comment ça marche <ArrowUpRight size={16} /></Link></div><div className="hero-note"><span className="dot" />Images, documents, audio et vidéo disponibles</div></div><FileIllustration /></section><section className="trust-strip"><div className="container trust-inner"><span><ShieldCheck size={18} />Stockage privé</span><span><Layers3 size={18} />Tous vos outils réunis</span><span><Feather size={18} />Sans installation sur votre appareil</span><span><LockKeyhole size={18} />Votre confidentialité en priorité</span></div></section><section id="outils" className="container tools-section"><div className="section-heading"><div><span className="eyebrow">UNE BOÎTE À OUTILS, MILLE POSSIBILITÉS</span><h2>Que voulez-vous créer aujourd’hui ?</h2><p>Images, documents, audio et vidéo : choisissez votre outil.</p></div><Link to="/outils" className="quiet-link">Voir tout le catalogue <ArrowUpRight size={17} /></Link></div><SearchBox value={search} onChange={setSearch} /><ToolExplorer search={search} compact={!search} /></section><section className="container workflow-section"><div className="workflow-intro"><span className="eyebrow">MOINS D’ÉTAPES, PLUS DE POSSIBILITÉS</span><h2>Vos fichiers.<br />Un parcours tout simple.</h2><p>Déposez vos fichiers, réglez le format et récupérez vos résultats.</p><Link className="quiet-link" to="/faq">Tout savoir sur Convert <ArrowRight size={17} /></Link></div><div className="workflow-steps">{[{ icon: FolderOpen, title: 'Déposez votre fichier', text: 'Choisissez un outil et ajoutez vos fichiers dans les limites indiquées.' }, { icon: SlidersHorizontal, title: 'Choisissez vos réglages', text: 'Sélectionnez le format de sortie et les options adaptées.' }, { icon: ArrowDown, title: 'Récupérez le résultat', text: 'Téléchargez votre fichier avant son expiration automatique.' }].map(({ icon: Icon, title, text }, index) => <div className="workflow-step" key={title}><span className="step-number">0{index + 1}</span><Icon size={21} aria-hidden="true" /><div><h3>{title}</h3><p>{text}</p></div></div>)}</div></section><section className="container bottom-callout"><span className="callout-symbol"><Sparkles size={27} /></span><div><h2>La simplicité, jusque dans les détails.</h2><p>Des limites claires. Des formats testés. Un lancement progressif.</p></div><Link to="/faq" className="button secondary">Les réponses à vos questions <ArrowUpRight size={16} /></Link></section></>;
}

function ToolExplorer({ search, compact = false }: { search: string; compact?: boolean }) {
  const [params, setParams] = useSearchParams();
  const selected = params.get('categorie') ?? 'all';
  const { data, isPending, error, refetch } = useTools();
  const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const tools = data?.filter(tool => (selected === 'all' || tool.category === selected) && normalize(`${tool.name} ${tool.description} ${tool.planned_formats.join(' ')}`).includes(normalize(search.trim()))) ?? [];
  const visible = compact && selected === 'all' ? tools.slice(0, 6) : tools;
  return <><div className="category-filters" role="group" aria-label="Filtrer par catégorie"><button className={selected === 'all' ? 'selected' : ''} aria-pressed={selected === 'all'} onClick={() => setParams({})}><Layers3 size={16} />Tous les outils</button>{categories.map(category => <button key={category.id} className={selected === category.id ? 'selected' : ''} aria-pressed={selected === category.id} onClick={() => setParams({ categorie: category.id })}><CategoryIcon category={category.id} size={16} />{category.short}</button>)}</div><div className="catalog-caption"><span>{isPending ? 'Chargement du catalogue' : `${tools.length} outil${tools.length > 1 ? 's' : ''} · ${tools.filter(tool => tool.enabled).length} disponible(s)`}</span><span><span className="small-dot" />Formats actifs testés</span></div>{isPending ? <Loading /> : error ? <ErrorState message={error.message} retry={() => { void refetch(); }} /> : visible.length ? <div className="tool-grid">{visible.map(tool => <ToolCard key={tool.slug} tool={tool} />)}</div> : <div className="empty-state"><Search size={27} aria-hidden="true" /><h3>Aucun outil trouvé</h3><p>Essayez un autre mot-clé ou une autre catégorie.</p></div>}{compact && selected === 'all' && tools.length > 6 && <div className="view-all"><Link to="/outils" className="button secondary">Découvrir les {tools.length} outils <ArrowRight size={16} /></Link></div>}<p className="catalog-disclaimer">Les outils marqués « Disponible » sont opérationnels. Les autres formats restent en préparation.</p></>;
}

function Catalog() {
  const [search, setSearch] = useState('');
  return <section className="container page-section"><PageTitle title="Tous les outils" /><span className="eyebrow">À CHAQUE FICHIER, SON OUTIL</span><h1>Votre boîte à outils.</h1><p className="page-lead">Convertissez vos images et documents avec les outils disponibles.</p><SearchBox value={search} onChange={setSearch} /><ToolExplorer search={search} /></section>;
}

function ToolPage() {
  const { slug } = useParams();
  const { data, isPending, error, refetch } = useTools();
  const limits = useLimits();
  const [format, setFormat] = useState('');
  const tool = data?.find(item => item.slug === slug);
  if (isPending) return <div className="container page-section"><Loading /></div>;
  if (error) return <div className="container page-section"><ErrorState message={error.message} retry={() => { void refetch(); }} /></div>;
  if (!tool) return <NotFound />;
  if (tool.category === 'external') return <ExternalTool />;
  if (tool.enabled && ['ocr', 'transcribe'].includes(tool.operation)) return <RecognitionTool key={tool.slug} tool={tool} />;
  if (tool.enabled && tool.operation === 'remove-background') return <Suspense fallback={<Loading />}><BackgroundTool tool={tool} /></Suspense>;
  if (tool.enabled && tool.category === 'images') return <ImageTool tool={tool} />;
  if (tool.enabled && tool.category === 'documents') return <DocumentTool tool={tool} />;
  if (tool.enabled && ['audio', 'video'].includes(tool.category)) return <MediaTool key={tool.slug} tool={tool} />;
  return <div className="container page-section"><PageTitle title={tool.name} /><nav className="breadcrumb" aria-label="Fil d’Ariane"><Link to="/outils">Les outils</Link><ChevronRight size={14} /><span>{tool.name}</span></nav><div className="tool-page-heading"><CategoryIcon category={tool.category} size={30} /><div><span className="eyebrow">{categories.find(category => category.id === tool.category)?.name}</span><h1>{tool.name}</h1><p className="page-lead">{tool.description}</p></div></div><div className="tool-workspace"><div><Notice><strong>{tool.status === 'disabled' ? 'Ce module est désactivé.' : 'Cet outil est en préparation.'}</strong><p>{'Le traitement et l’envoi de fichiers seront activés après les tests de sécurité et de conversion.'}</p></Notice>{<Dropzone disabled maxBytes={limits.data?.plans.guest.max_file_bytes ?? 25_000_000} accept="" onFiles={() => undefined} />}<section className="panel expectations"><h2>Ce que nous préparons</h2><p>{tool.planned_formats.length ? `Formats envisagés : ${tool.planned_formats.join(', ')}. Ils seront proposés uniquement après des tests concluants.` : 'Un registre limité de sources compatibles et autorisées, avec des contrôles de sécurité.'}</p>{tool.category === 'documents' && <p>La mise en page des documents complexes peut être altérée. Vérifiez toujours vos résultats.</p>}</section></div><aside className="tool-sidebar"><div className="panel settings-panel"><h2>Vos réglages</h2><FormatSelect formats={tool.target_formats} value={format} onChange={setFormat} disabled /><div className="settings-note"><ShieldCheck size={19} /><p>Les futurs fichiers seront conservés dans un stockage privé.</p></div><button className="button primary full-width" disabled>{tool.status === 'disabled' ? 'Module désactivé' : 'Conversion en préparation'}<ArrowRight size={16} /></button><small>Aucun traitement disponible actuellement.</small></div>{limits.isPending ? <Loading label="Chargement des limites…" /> : limits.error ? <ErrorState message={limits.error.message} retry={() => { void limits.refetch(); }} /> : limits.data && <QuotaIndicator limits={limits.data.plans.guest} />}<div className="retention-note"><Clock3 size={18} /><p>Vous garderez le contrôle : suppression manuelle et expiration automatique prévues.</p></div></aside></div></div>;
}

function FAQ() {
  return <section className="container page-section narrow"><PageTitle title="Questions fréquentes" /><span className="eyebrow">ON VOUS EXPLIQUE</span><h1>Simple, et transparent.</h1><p className="page-lead">Le fonctionnement et les réponses aux questions les plus fréquentes.</p><div className="faq-list">{faqs.map(({ question, answer }, index) => <details key={question} open={index === 0 ? true : undefined}><summary>{question}<span aria-hidden="true">+</span></summary><p>{answer}</p></details>)}</div><div className="help-note"><ShieldCheck size={23} /><p>Le service est en développement. Les formats actifs et quotas sont affichés dans chaque outil.</p></div></section>;
}

const legalContent = {
  privacy: { title: 'Confidentialité', intro: 'Comprendre les données utilisées et les protections prévues.', sections: [
    ['La version actuelle', 'Cette version permet la conversion d’images, de PDF, de documents Office, de tableaux, d’audio et de vidéo selon les formats activés dans le catalogue. Vos fichiers sont privés et peuvent être supprimés depuis Mes fichiers. Le serveur utilise une session technique et votre navigateur peut mémoriser votre préférence de thème. Aucun outil publicitaire ni outil de mesure d’audience n’a été ajouté au socle.'],
    ['Les journaux techniques', 'L’hébergement peut produire des journaux de requêtes et d’erreurs pour assurer son fonctionnement. Les durées de conservation et les modalités d’exercice de vos droits devront être précisées par l’exploitant avant l’ouverture publique.'],
    ['La gestion des fichiers', 'Les fichiers utilisent un stockage privé, des téléchargements autorisés par propriétaire et une suppression automatique : une heure pour les visiteurs et 24 heures pour les comptes. Le propriétaire et l’expiration sont contrôlés à chaque téléchargement. Le nettoyage physique dépend du scheduler du serveur.'],
    ['Contact et droits', 'L’identité du responsable de traitement, son contact, les prestataires, les bases légales et les durées définitives restent à renseigner avant publication. Cette page est une information de développement, à compléter selon le déploiement réel.'],
  ] },
  terms: { title: 'Conditions d’utilisation', intro: 'Les règles du service et ses limites.', sections: [
    ['Disponibilité du service', 'Le catalogue actuel présente des fonctionnalités en préparation. Les outils images, documents, audio et vidéo validés sont disponibles. Le téléchargement externe est limité aux WebM Commons CC0 lorsque sa vérification est active. Les comptes gratuits donnent accès à un historique personnel et à des quotas supérieurs. Les formats et limites pourront évoluer après validation technique.'],
    ['Vos fichiers et vos droits', 'N’utilisez le service que pour des contenus que vous êtes autorisé à traiter. Les contenus illicites, les tentatives d’accès à des fichiers tiers et les usages visant à saturer l’infrastructure sont interdits.'],
    ['Qualité et limites', 'Les conversions ne garantissent pas une reproduction parfaite des formats complexes. Vérifiez vos résultats et conservez vos originaux. Les tailles, durées, quotas et délais de conservation seront précisés avant chaque traitement.'],
    ['Récupération de vidéos', 'Aucun contournement de DRM, paywall, authentification, CAPTCHA ou restriction d’accès n’est proposé. Seule la source Commons CC0 vérifiée peut être activée. Les autres plateformes ne sont pas prises en charge.'],
    ['Avant l’ouverture publique', 'L’exploitant doit compléter l’identité du fournisseur, les règles applicables, les modalités de contact et les conditions définitives avant ouverture du service.'],
  ] },
  legal: { title: 'Mentions légales', intro: 'Informations à compléter avant l’ouverture publique.', sections: [
    ['Éditeur', 'La raison sociale ou l’identité de l’exploitant, son adresse, ses coordonnées et ses identifiants d’immatriculation ne sont pas encore renseignés.'],
    ['Responsable de publication', 'Le nom et les coordonnées du responsable de publication doivent être renseignés par l’exploitant.'],
    ['Hébergement', 'Le prestataire d’hébergement, son adresse et son contact seront précisés après choix et validation du serveur de production.'],
    ['État du projet', 'Convert est actuellement un projet en développement. Cette page signale les informations manquantes et ne constitue pas une validation juridique pour une mise en production.'],
  ] },
};

function LegalPage({ kind }: { kind: keyof typeof legalContent }) {
  const page = legalContent[kind];
  return <section className="container page-section narrow legal-page"><PageTitle title={page.title} /><span className="eyebrow">EN TOUTE TRANSPARENCE</span><h1>{page.title}</h1><p className="page-lead">{page.intro}</p><Notice>Document de travail — informations de l’exploitant à compléter avant publication.</Notice>{page.sections.map(([title, text]) => <section key={title}><h2>{title}</h2><p>{text}</p></section>)}</section>;
}

function NotFound() {
  return <section className="container page-section empty-state"><PageTitle title="Page introuvable" /><span className="eyebrow">ERREUR 404</span><h1>Cette page s’est égarée.</h1><p>Retrouvez tous les outils depuis le catalogue.</p><Link className="button primary" to="/outils">Explorer les outils <ArrowRight size={17} /></Link></section>;
}

export default function App() {
  const { pathname } = useLocation();
  return <Routes><Route element={<Layout />}><Route index element={<Home />} /><Route path="outils" element={<Catalog />} /><Route path="outils/:slug" element={<ToolPage key={pathname} />} /><Route path="mes-fichiers" element={<Activity />} /><Route path="connexion" element={<AuthPage mode="login" />} /><Route path="inscription" element={<AuthPage mode="register" />} /><Route path="mot-de-passe-oublie" element={<AuthPage mode="forgot" />} /><Route path="reinitialiser-mot-de-passe" element={<AuthPage mode="reset" />} /><Route path="profil" element={<Profile />} /><Route path="administration" element={<Suspense fallback={<Loading />}><Admin /></Suspense>} /><Route path="souscription" element={<Suspense fallback={<Loading />}><Subscribe /></Suspense>} /><Route path="tarifs" element={<Plans />} /><Route path="offres" element={<Plans />} /><Route path="mon-abonnement" element={<SubscriptionPage />} /><Route path="faq" element={<FAQ />} /><Route path="confidentialite" element={<LegalPage kind="privacy" />} /><Route path="conditions" element={<LegalPage kind="terms" />} /><Route path="mentions-legales" element={<LegalPage kind="legal" />} /><Route path="*" element={<NotFound />} /></Route></Routes>;
}
