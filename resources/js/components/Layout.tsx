import { useEffect, useState } from 'react';
import { ArrowUpRight, Menu, Moon, Sun, X } from 'lucide-react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { Logo } from './ui';
import { useSession } from '../lib/api';
import RouteMetadata from './RouteMetadata';
import TrialAlert from './TrialAlert';

export default function Layout() {
  const session = useSession();
  const [dark, setDark] = useState(() => document.documentElement.dataset.theme === 'dark');
  const [menu, setMenu] = useState(false);
  const location = useLocation();
  useEffect(() => {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    try { localStorage.setItem('convert-theme', dark ? 'dark' : 'light'); } catch { /* Theme remains available without storage. */ }
  }, [dark]);
  useEffect(() => {
    window.scrollTo?.({ top: 0 });
    if (location.key !== 'default') document.getElementById('main-content')?.focus();
  }, [location]);

  return <><RouteMetadata /><a className="skip-link" href="#main-content">Aller au contenu</a><header className="site-header"><div className="container header-inner"><Logo /><nav className="desktop-nav" aria-label="Navigation principale"><NavLink to="/" end>Accueil</NavLink><NavLink to="/outils">Les outils</NavLink><NavLink to="/mes-fichiers">Mes fichiers</NavLink><NavLink to="/tarifs">Tarifs</NavLink><NavLink to="/faq">Comment ça marche</NavLink></nav><div className="header-actions"><Link className="account-nav" to={session.data?.user ? "/profil" : "/connexion"}>{session.data?.user ? "Mon compte" : "Connexion"}</Link><button className="icon-button theme-button" aria-label={dark ? 'Activer le mode clair' : 'Activer le mode sombre'} onClick={() => setDark(!dark)}>{dark ? <Sun size={19} /> : <Moon size={19} />}</button><button className="icon-button menu-button" aria-label={menu ? 'Fermer le menu' : 'Ouvrir le menu'} aria-expanded={menu} aria-controls="mobile-menu" onClick={() => setMenu(!menu)}>{menu ? <X /> : <Menu />}</button></div></div>{menu && <nav id="mobile-menu" className="mobile-nav" aria-label="Navigation mobile"><Link to="/" onClick={() => setMenu(false)}>Accueil</Link><Link to="/outils" onClick={() => setMenu(false)}>Les outils</Link><Link to="/mes-fichiers" onClick={() => setMenu(false)}>Mes fichiers</Link><Link to="/faq" onClick={() => setMenu(false)}>Comment ça marche</Link><Link to="/tarifs" onClick={() => setMenu(false)}>Tarifs</Link><Link to={session.data?.user ? "/profil" : "/connexion"} onClick={() => setMenu(false)}>{session.data?.user ? "Mon compte" : "Connexion"}</Link></nav>}</header><TrialAlert enabled={!!session.data?.user} /><main id="main-content" tabIndex={-1}><Outlet /></main><footer className="site-footer"><div className="container footer-top"><div><Logo /><p>Un nouvel élan pour vos fichiers.<br />Simple, pratique, au même endroit.</p></div><div className="footer-links"><div><strong>Explorer</strong><Link to="/outils">Tous les outils <ArrowUpRight size={13} /></Link><Link to="/mes-fichiers">Mes fichiers</Link><Link to="/mon-abonnement">Mon abonnement</Link><Link to="/faq">Questions fréquentes</Link><Link to="/tarifs">Tarifs</Link></div><div><strong>En toute transparence</strong><Link to="/confidentialite">Confidentialité</Link><Link to="/conditions">Conditions d’utilisation</Link><Link to="/mentions-legales">Mentions légales</Link></div></div></div><div className="container footer-bottom"><span>© {new Date().getFullYear()} Convert. Projet en développement.</span><span>Pensé pour vos fichiers, conçu avec soin.</span></div></footer></>;
}
