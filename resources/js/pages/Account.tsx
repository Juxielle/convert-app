import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { ApiError, jsonApi, useSession, type Session } from '../lib/api';
import { ErrorState, Loading, Notice } from '../components/ui';

export function FormError({ error }: { error: Error | null }) {
  return error && <Notice tone="error">{error.message}{error instanceof ApiError && error.fields && <ul>{Object.entries(error.fields).map(([key, values]) => <li key={key}>{values.join(' ')}</li>)}</ul>}</Notice>;
}

export function AuthPage({ mode }: { mode: 'login' | 'register' | 'forgot' | 'reset' }) {
  const location = useLocation();
  const params = new URLSearchParams(location.hash.slice(1));
  const [name, setName] = useState('');
  const [email, setEmail] = useState(params.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const navigate = useNavigate();
  const client = useQueryClient();
  const title = { login: 'Connexion', register: 'Créer un compte', forgot: 'Mot de passe oublié', reset: 'Nouveau mot de passe' }[mode];
  const mutation = useMutation({ mutationFn: () => jsonApi<Session & { message?: string }>(`/auth/${{ login: 'login', register: 'register', forgot: 'forgot-password', reset: 'reset-password' }[mode]}`, { email, ...(mode === 'register' ? { name } : {}), ...(mode !== 'forgot' ? { password } : {}), ...(['register', 'reset'].includes(mode) ? { password_confirmation: confirmation } : {}), ...(mode === 'reset' ? { token: params.get('token') } : {}) }), onSuccess: data => {
    setPassword(''); setConfirmation('');
    if (data.user) { client.clear(); client.setQueryData(['session'], data); const target = new URLSearchParams(location.search).get('retour'); navigate(target && /^\/souscription(?:\?|$)/.test(target) ? target : '/profil'); }
  } });
  return <section className="container page-section narrow"><title>{title} — Convert</title><span className="eyebrow">VOTRE ESPACE</span><h1>{title}</h1><p className="page-lead">{mode === 'register' ? 'Un historique personnel et un accès aux essais des formules Pro et Business. Les fichiers de votre session visiteur ne sont pas transférés au compte.' : mode === 'forgot' ? 'Recevez un lien de réinitialisation par email si un compte existe.' : 'Vos fichiers restent privés et soumis à leur expiration.'}</p><form className="panel account-form" onSubmit={event => { event.preventDefault(); mutation.mutate(); }}>
    <FormError error={mutation.error} />{mutation.data?.message && <Notice>{mutation.data.message}</Notice>}
    <fieldset disabled={mutation.isPending}><legend className="sr-only">{title}</legend>
      {mode === 'register' && <div className="field"><label htmlFor="account-name">Nom</label><input id="account-name" autoComplete="name" required maxLength={100} value={name} onChange={event => setName(event.target.value)} /></div>}
      <div className="field"><label htmlFor="account-email">Adresse email</label><input id="account-email" type="email" autoComplete="email" required maxLength={254} value={email} onChange={event => setEmail(event.target.value)} /></div>
      {mode !== 'forgot' && <div className="field"><label htmlFor="account-password">Mot de passe</label><input id="account-password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} minLength={mode === 'login' ? 1 : 12} maxLength={72} required value={password} onChange={event => setPassword(event.target.value)} />{mode !== 'login' && <small>12 caractères minimum. Évitez les phrases très longues.</small>}</div>}
      {['register', 'reset'].includes(mode) && <div className="field"><label htmlFor="account-confirmation">Confirmer le mot de passe</label><input id="account-confirmation" type="password" autoComplete="new-password" required maxLength={72} value={confirmation} onChange={event => setConfirmation(event.target.value)} /></div>}
      <button className="button primary" type="submit">{mutation.isPending ? 'En cours…' : title}</button>
    </fieldset>
  </form><div className="account-links"><Link to="/connexion">Se connecter</Link><Link to="/inscription">Créer un compte gratuit</Link><Link to="/mot-de-passe-oublie">Mot de passe oublié</Link></div>{mode === 'reset' && <Notice>Le lien est à usage unique et expire après une heure.</Notice>}</section>;
}

export default function Profile() {
  const session = useSession();
  const client = useQueryClient();
  const navigate = useNavigate();
  const logout = useMutation({ mutationFn: () => jsonApi<Session>('/auth/logout', {}), onSuccess: data => { client.clear(); client.setQueryData(['session'], data); navigate('/connexion'); } });
  if (session.isPending) return <div className="container page-section"><Loading /></div>;
  if (session.error) return <div className="container page-section"><ErrorState message={session.error.message} retry={() => { void session.refetch(); }} /></div>;
  if (!session.data?.user) return <section className="container page-section narrow"><h1>Votre profil</h1><Notice>Connectez-vous pour accéder à votre compte.</Notice><Link className="button primary" to="/connexion">Se connecter</Link></section>;
  const user = session.data.user;
  return <section className="container page-section narrow"><title>Mon profil — Convert</title><span className="eyebrow">VOTRE COMPTE</span><h1>Bonjour {user.name}.</h1><p className="page-lead">La durée de votre historique dépend de votre formule. Consultez Mon abonnement pour vos limites et échéances.</p><div className="account-links"><Link to="/mes-fichiers">Mes fichiers et mes quotas</Link><Link to="/mon-abonnement">Mon abonnement</Link><Link to="/tarifs">Comparer les formules</Link>{user.role === 'admin' && <Link to="/administration">Administration</Link>}</div><ProfileForm key={user.id} name={user.name} email={user.email} /><PasswordForm /><FormError error={logout.error} /><button className="button secondary" disabled={logout.isPending} onClick={() => logout.mutate()}>Se déconnecter</button></section>;
}

function ProfileForm({ name: originalName, email: originalEmail }: { name: string; email: string }) {
  const [name, setName] = useState(originalName);
  const [email, setEmail] = useState(originalEmail);
  const [password, setPassword] = useState('');
  const client = useQueryClient();
  const update = useMutation({ mutationFn: () => jsonApi<Session>('/me/profile', { name, email, current_password: password }, 'PATCH'), onSuccess: data => { client.setQueryData(['session'], data); setPassword(''); } });
  return <form className="panel account-form" onSubmit={event => { event.preventDefault(); update.mutate(); }}><h2>Informations du compte</h2><FormError error={update.error} />{update.isSuccess && <Notice>Profil enregistré.</Notice>}<fieldset disabled={update.isPending}><legend className="sr-only">Modifier le profil</legend><div className="field"><label htmlFor="profile-name">Nom</label><input id="profile-name" required maxLength={100} autoComplete="name" value={name} onChange={event => setName(event.target.value)} /></div><div className="field"><label htmlFor="profile-email">Adresse email</label><input id="profile-email" type="email" required autoComplete="email" value={email} onChange={event => setEmail(event.target.value)} /></div><div className="field"><label htmlFor="profile-password">Mot de passe actuel</label><input id="profile-password" type="password" required autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} /></div><button className="button primary">Enregistrer le profil</button></fieldset></form>;
}

function PasswordForm() {
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const update = useMutation({ mutationFn: () => jsonApi<Session>('/me/password', { current_password: current, password, password_confirmation: confirmation }, 'PATCH'), onSuccess: () => { setCurrent(''); setPassword(''); setConfirmation(''); } });
  return <form className="panel account-form" onSubmit={event => { event.preventDefault(); update.mutate(); }}><h2>Sécurité</h2><FormError error={update.error} />{update.isSuccess && <Notice>Mot de passe modifié. Les autres sessions sont révoquées.</Notice>}<fieldset disabled={update.isPending}><legend className="sr-only">Changer le mot de passe</legend><div className="field"><label htmlFor="password-current">Mot de passe actuel</label><input id="password-current" type="password" required autoComplete="current-password" value={current} onChange={event => setCurrent(event.target.value)} /></div><div className="field"><label htmlFor="password-new">Nouveau mot de passe</label><input id="password-new" type="password" required minLength={12} maxLength={72} autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} /></div><div className="field"><label htmlFor="password-confirm">Confirmer le nouveau mot de passe</label><input id="password-confirm" type="password" required autoComplete="new-password" value={confirmation} onChange={event => setConfirmation(event.target.value)} /></div><button className="button primary">Changer le mot de passe</button></fieldset></form>;
}
