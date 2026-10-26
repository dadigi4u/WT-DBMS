import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { api } from './api';
import { Alert, Button, Field, Input } from './ui';
import { Audit, Border, Cargo, Dashboard, Emergency, Fleet, Manifests, Ticketing, Voyages } from './pages';

const NAV = [
    { id: 'dashboard', label: 'Dashboard', perm: ['report.view', 'voyage.view'], C: Dashboard },
    { id: 'fleet', label: 'Fleet & Certificates', perm: ['fleet.view'], C: Fleet },
    { id: 'voyages', label: 'Voyage Scheduling', perm: ['voyage.view'], C: Voyages },
    { id: 'ticketing', label: 'Passenger Ticketing', perm: ['ticket.issue'], C: Ticketing },
    { id: 'cargo', label: 'Cargo Acceptance', perm: ['cargo.accept'], C: Cargo },
    { id: 'manifests', label: 'Manifests', perm: ['manifest.view'], C: Manifests },
    { id: 'emergency', label: 'Emergency Retrieval', perm: ['emergency.view'], C: Emergency },
    { id: 'border', label: 'Cross-Border Disclosure', perm: ['border.request', 'border.approve', 'border.view'], C: Border },
    { id: 'audit', label: 'Audit Trail', perm: ['audit.view'], C: Audit },
];

function Login({ onLogin }) {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [err, setErr] = useState(null);
    const [busy, setBusy] = useState(false);
    const submit = async (e) => {
        e.preventDefault(); setBusy(true); setErr(null);
        try { await fetch('/', { credentials: 'same-origin' }); onLogin(await api('/login', { method: 'POST', body: { email, password } })); }
        catch (x) { setErr(x.message); } finally { setBusy(false); }
    };
    return (
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-sky-950 to-slate-800 p-4">
            <form onSubmit={submit} className="bg-white rounded-lg shadow-xl w-full max-w-md p-8 space-y-5">
                <div>
                    <div className="text-xs uppercase tracking-widest text-sky-800 font-semibold">Oron–Idenua Waterway</div>
                    <h1 className="text-2xl font-bold text-slate-900 mt-1">Secure Water Transport DBMS</h1>
                    <p className="text-sm text-slate-500 mt-1">Fleet, passenger and cargo information management</p>
                </div>
                {err && <Alert>{err}</Alert>}
                <Field label="Email address"><Input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required /></Field>
                <Field label="Password"><Input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required /></Field>
                <Button type="submit" disabled={busy} className="w-full">{busy ? 'Signing in…' : 'Sign in'}</Button>
                <p className="text-xs text-slate-400">Authorised personnel only. All access is logged. Accounts lock for 15 minutes after 5 failed attempts.</p>
            </form>
        </div>
    );
}

function App() {
    const [me, setMe] = useState(undefined);
    const [page, setPage] = useState(() => window.location.hash.slice(1) || 'dashboard');
    useEffect(() => { api('/me').then(setMe).catch(() => setMe(null)); }, []);
    useEffect(() => { const h = () => setPage(window.location.hash.slice(1) || 'dashboard'); window.addEventListener('hashchange', h); return () => window.removeEventListener('hashchange', h); }, []);
    if (me === undefined) return null;
    if (!me) return <Login onLogin={setMe} />;
    const allowed = NAV.filter((n) => me.permissions.includes('*') || n.perm.some((p) => me.permissions.includes(p)));
    const current = allowed.find((n) => n.id === page) || allowed[0];
    const logout = async () => { await api('/logout', { method: 'POST' }); setMe(null); };
    return (
        <div className="min-h-screen flex">
            <aside className="no-print w-64 shrink-0 bg-sky-950 text-sky-100 flex flex-col">
                <div className="px-5 py-5 border-b border-sky-900">
                    <div className="text-[10px] uppercase tracking-widest text-sky-400">Oron–Idenua Waterway</div>
                    <div className="font-bold text-white leading-tight mt-1">Secure Water Transport DBMS</div>
                </div>
                <nav className="flex-1 py-3">
                    {allowed.map((n) => (
                        <a key={n.id} href={`#${n.id}`} className={`block px-5 py-2.5 text-sm ${current.id === n.id ? 'bg-sky-800 text-white font-medium border-l-4 border-amber-400' : 'hover:bg-sky-900 border-l-4 border-transparent'}`}>{n.label}</a>
                    ))}
                </nav>
                <div className="px-5 py-4 border-t border-sky-900 text-xs">
                    <div className="text-white font-medium">{me.name}</div>
                    <div className="text-sky-300">{me.role_name}</div>
                    <div className="text-sky-400 truncate">{me.organisation}</div>
                    <button onClick={logout} className="mt-3 text-amber-300 hover:text-amber-200">Sign out</button>
                </div>
            </aside>
            <main className="flex-1 min-w-0">
                <header className="no-print bg-white border-b border-slate-200 px-8 py-4 flex justify-between items-center">
                    <h1 className="text-xl font-semibold text-slate-800">{current.label}</h1>
                    <span className="text-xs text-slate-500">Session secured · idle timeout 15 min</span>
                </header>
                <div className="p-8"><current.C me={me} /></div>
            </main>
        </div>
    );
}

createRoot(document.getElementById('root')).render(<App />);
