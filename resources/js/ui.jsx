import React from 'react';

export const Card = ({ title, action, children, className = '' }) => (
    <section className={`bg-white rounded-lg border border-slate-200 shadow-sm ${className}`}>
        {title && (
            <header className="flex items-center justify-between px-5 py-3 border-b border-slate-200">
                <h2 className="font-semibold text-slate-800">{title}</h2>
                {action}
            </header>
        )}
        <div className="p-5">{children}</div>
    </section>
);

export const Stat = ({ label, value, sub, tone = 'slate' }) => {
    const tones = { slate: 'text-slate-900', red: 'text-red-700', amber: 'text-amber-700', green: 'text-emerald-700', blue: 'text-sky-800' };
    return (
        <div className="bg-white rounded-lg border border-slate-200 shadow-sm px-5 py-4">
            <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
            <div className={`text-2xl font-bold mt-1 ${tones[tone]}`}>{value}</div>
            {sub && <div className="text-xs text-slate-500 mt-1">{sub}</div>}
        </div>
    );
};

export const Badge = ({ children, tone = 'slate' }) => {
    const t = {
        slate: 'bg-slate-100 text-slate-700', green: 'bg-emerald-100 text-emerald-800', red: 'bg-red-100 text-red-800',
        amber: 'bg-amber-100 text-amber-800', blue: 'bg-sky-100 text-sky-800', violet: 'bg-violet-100 text-violet-800',
    };
    return <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${t[tone]}`}>{children}</span>;
};

export const statusTone = (s) => ({ scheduled: 'blue', boarding: 'amber', departed: 'violet', arrived: 'green', cancelled: 'slate', incident: 'red', compliant: 'green', expiring: 'amber', 'non-compliant': 'red', issued: 'blue', boarded: 'green', requested: 'amber', approved: 'blue', transmitted: 'green', rejected: 'red' }[s] || 'slate');

export const Field = ({ label, error, children, className = '' }) => (
    <label className={`block ${className}`}>
        <span className="block text-xs font-medium text-slate-600 mb-1">{label}</span>
        {children}
        {error && <span className="block text-xs text-red-600 mt-1">{error}</span>}
    </label>
);

const base = 'w-full rounded border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-600 bg-white';
export const Input = (p) => <input {...p} className={`${base} ${p.className || ''}`} />;
export const Select = ({ children, ...p }) => <select {...p} className={`${base} ${p.className || ''}`}>{children}</select>;
export const Textarea = (p) => <textarea {...p} className={`${base} ${p.className || ''}`} />;

export const Button = ({ variant = 'primary', className = '', ...p }) => {
    const v = {
        primary: 'bg-sky-800 hover:bg-sky-900 text-white', secondary: 'bg-white border border-slate-300 hover:bg-slate-50 text-slate-800',
        danger: 'bg-red-700 hover:bg-red-800 text-white', success: 'bg-emerald-700 hover:bg-emerald-800 text-white',
    };
    return <button {...p} className={`inline-flex items-center justify-center gap-2 rounded px-4 py-2 text-sm font-medium disabled:opacity-50 ${v[variant]} ${className}`} />;
};

export const Alert = ({ tone = 'red', children }) => {
    const t = { red: 'bg-red-50 border-red-300 text-red-800', green: 'bg-emerald-50 border-emerald-300 text-emerald-800', amber: 'bg-amber-50 border-amber-300 text-amber-900', blue: 'bg-sky-50 border-sky-300 text-sky-900' };
    return <div className={`border rounded px-4 py-3 text-sm ${t[tone]}`}>{children}</div>;
};

export const Meter = ({ value, max, label }) => {
    const pct = max > 0 ? Math.min(100, (value / max) * 100) : 0;
    const color = pct >= 100 ? 'bg-red-600' : pct >= 85 ? 'bg-amber-500' : 'bg-emerald-600';
    return (
        <div>
            <div className="flex justify-between text-xs text-slate-600 mb-1">
                <span>{label}</span>
                <span className="font-semibold">{value.toLocaleString()} / {max.toLocaleString()} ({pct.toFixed(0)}%)</span>
            </div>
            <div className="h-3 bg-slate-200 rounded"><div className={`h-3 rounded ${color}`} style={{ width: `${pct}%` }} /></div>
        </div>
    );
};

export const Table = ({ cols, rows, empty = 'No records.' }) => (
    <div className="overflow-x-auto">
        <table className="w-full text-sm">
            <thead>
                <tr className="text-left text-xs uppercase text-slate-500 border-b border-slate-200">
                    {cols.map((c) => <th key={c.key} className="py-2 pr-4 font-medium whitespace-nowrap">{c.label}</th>)}
                </tr>
            </thead>
            <tbody>
                {rows.length === 0 && <tr><td colSpan={cols.length} className="py-4 text-slate-500">{empty}</td></tr>}
                {rows.map((r, i) => (
                    <tr key={i} className="border-b border-slate-100 last:border-0">
                        {cols.map((c) => <td key={c.key} className="py-2 pr-4 align-top">{c.render ? c.render(r) : r[c.key]}</td>)}
                    </tr>
                ))}
            </tbody>
        </table>
    </div>
);
