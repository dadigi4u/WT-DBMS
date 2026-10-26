import React, { useEffect, useState, useCallback } from 'react';
import { api, fmt } from './api';
import { Alert, Badge, Button, Card, Field, Input, Meter, Select, Stat, Table, Textarea, statusTone } from './ui';

const useLoad = (fn, deps = []) => {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const reload = useCallback(() => { fn().then(setData).catch((e) => setError(e.message)); }, deps); // eslint-disable-line
    useEffect(() => { reload(); }, [reload]);
    return [data, reload, error];
};

const errs = (e) => Object.fromEntries(Object.entries(e?.body?.errors || {}).map(([k, v]) => [k, v[0]]));

/* ------------------------------------------------------------------ Dashboard */
export function Dashboard() {
    const [d] = useLoad(() => api('/dashboard'));
    const [voyages] = useLoad(() => api('/voyages'));
    if (!d) return <p>Loading…</p>;
    const upcoming = (voyages || []).filter((v) => ['scheduled', 'boarding'].includes(v.status)).slice(-6).reverse();
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <Stat label="Active vessels" value={d.vessels_active} />
                <Stat label="Voyages today" value={d.voyages_today} tone="blue" />
                <Stat label="Passengers ticketed today" value={d.passengers_today} tone="blue" />
                <Stat label="Cargo accepted today" value={fmt.kg(d.cargo_kg_today)} tone="blue" />
                <Stat label="Avg. passenger load (30 d)" value={`${d.avg_pax_utilisation_30d}%`} />
                <Stat label="Avg. cargo load (30 d)" value={`${d.avg_cargo_utilisation_30d}%`} />
                <Stat label="Rule refusals (30 d)" value={d.refusals_30d} sub="capacity / certificate / DG blocks" tone="amber" />
                <Stat label="Open incidents" value={d.open_incidents} tone={d.open_incidents ? 'red' : 'green'} />
            </div>
            <div className="grid lg:grid-cols-2 gap-6">
                <Card title="Upcoming departures">
                    <Table cols={[
                        { key: 'voyage_ref', label: 'Voyage' },
                        { key: 'v', label: 'Vessel', render: (r) => r.vessel.vessel_name },
                        { key: 'dep', label: 'Departure', render: (r) => fmt.dt(r.scheduled_departure) },
                        { key: 'pax', label: 'Pax', render: (r) => `${r.passengers}/${r.passenger_capacity}` },
                        { key: 'status', label: 'Status', render: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                    ]} rows={upcoming} />
                </Card>
                <Card title="Certificate alerts (lapsed or expiring within 30 days)">
                    <Table cols={[
                        { key: 'vessel', label: 'Vessel' }, { key: 'cert_type', label: 'Certificate' },
                        { key: 'expiry_date', label: 'Expiry', render: (r) => fmt.d(r.expiry_date) },
                        { key: 'l', label: 'State', render: (r) => <Badge tone={r.lapsed ? 'red' : 'amber'}>{r.lapsed ? 'LAPSED' : 'expiring'}</Badge> },
                    ]} rows={d.certificate_alerts} empty="All certificates current." />
                </Card>
            </div>
            <Card title="Recent system alerts">
                <ul className="divide-y divide-slate-100 text-sm">
                    {d.notifications.length === 0 && <li className="py-2 text-slate-500">No alerts.</li>}
                    {d.notifications.map((n, i) => (
                        <li key={i} className="py-2 flex gap-3"><Badge tone={n.category === 'incident' ? 'red' : 'amber'}>{n.category.replaceAll('_', ' ')}</Badge><span>{n.message}</span></li>
                    ))}
                </ul>
            </Card>
        </div>
    );
}

/* ------------------------------------------------------------------ Fleet */
export function Fleet({ me }) {
    const [vessels, reload] = useLoad(() => api('/vessels'));
    const [ops] = useLoad(() => api('/operators'));
    const [form, setForm] = useState({ vessel_type: 'passenger_cargo' });
    const [e, setE] = useState({});
    const [msg, setMsg] = useState(null);
    const canManage = me.permissions.includes('*') || me.permissions.includes('fleet.manage');
    const submit = async (ev) => {
        ev.preventDefault(); setE({}); setMsg(null);
        try { const v = await api('/vessels', { method: 'POST', body: form }); setMsg({ tone: 'green', text: `Vessel ${v.vessel_name} (${v.registration_no}) registered.` }); setForm({ vessel_type: 'passenger_cargo' }); reload(); }
        catch (err) { setE(errs(err)); setMsg({ tone: 'red', text: err.message }); }
    };
    const set = (k) => (ev) => setForm({ ...form, [k]: ev.target.value });
    return (
        <div className="space-y-6">
            <Card title="Fleet register">
                <Table cols={[
                    { key: 'vessel_name', label: 'Vessel', render: (r) => <div><div className="font-medium">{r.vessel_name}</div><div className="text-xs text-slate-500">{r.registration_no}</div></div> },
                    { key: 'op', label: 'Operator', render: (r) => r.operator.operator_name },
                    { key: 'vessel_type', label: 'Type', render: (r) => r.vessel_type.replace('_', ' ') },
                    { key: 'passenger_capacity', label: 'Pax cap.' },
                    { key: 'cargo_capacity_kg', label: 'Cargo cap.', render: (r) => fmt.kg(r.cargo_capacity_kg) },
                    { key: 'compliance', label: 'Certification', render: (r) => <div><Badge tone={statusTone(r.compliance)}>{r.compliance}</Badge>{r.lapsed_certificates.length > 0 && <div className="text-xs text-red-700 mt-1">lapsed: {r.lapsed_certificates.join(', ')}</div>}{r.expiring_soon.length > 0 && <div className="text-xs text-amber-700 mt-1">expiring: {r.expiring_soon.join(', ')}</div>}</div> },
                    { key: 'status', label: 'Status', render: (r) => <Badge tone={r.status === 'active' ? 'green' : 'slate'}>{r.status}</Badge> },
                ]} rows={vessels || []} />
            </Card>
            {canManage && (
                <Card title="Register a vessel">
                    {msg && <div className="mb-4"><Alert tone={msg.tone}>{msg.text}</Alert></div>}
                    <form onSubmit={submit} className="grid md:grid-cols-4 gap-4">
                        <Field label="Operator" error={e.operator_id}><Select value={form.operator_id || ''} onChange={set('operator_id')}><option value="">Select…</option>{(ops || []).map((o) => <option key={o.operator_id} value={o.operator_id}>{o.operator_name}</option>)}</Select></Field>
                        <Field label="Vessel name" error={e.vessel_name}><Input value={form.vessel_name || ''} onChange={set('vessel_name')} /></Field>
                        <Field label="Registration no." error={e.registration_no}><Input value={form.registration_no || ''} onChange={set('registration_no')} placeholder="AKS/ORN/2026/001" /></Field>
                        <Field label="Vessel type" error={e.vessel_type}><Select value={form.vessel_type} onChange={set('vessel_type')}><option value="speedboat">Speedboat</option><option value="passenger_ferry">Passenger ferry</option><option value="passenger_cargo">Passenger & cargo</option><option value="cargo_boat">Cargo boat</option></Select></Field>
                        <Field label="Hull material" error={e.hull_material}><Input value={form.hull_material || ''} onChange={set('hull_material')} /></Field>
                        <Field label="Engine details" error={e.engine_details}><Input value={form.engine_details || ''} onChange={set('engine_details')} /></Field>
                        <Field label="Certified passenger capacity" error={e.passenger_capacity}><Input type="number" value={form.passenger_capacity || ''} onChange={set('passenger_capacity')} /></Field>
                        <Field label="Certified cargo capacity (kg)" error={e.cargo_capacity_kg}><Input type="number" value={form.cargo_capacity_kg || ''} onChange={set('cargo_capacity_kg')} /></Field>
                        <div className="md:col-span-4"><Button type="submit">Register vessel</Button></div>
                    </form>
                </Card>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ Voyages */
export function Voyages({ me }) {
    const [voyages, reload] = useLoad(() => api('/voyages'));
    const can = (p) => me.permissions.includes('*') || me.permissions.includes(p);
    const [vessels] = useLoad(() => (can('fleet.view') ? api('/vessels') : Promise.resolve([])));
    const [routes] = useLoad(() => api('/routes'));
    const [crew] = useLoad(() => (can('voyage.manage') ? api('/crew') : Promise.resolve([])));
    const [form, setForm] = useState({ voyage_type: 'scheduled', fare: 15000, master: '', second: '', second_role: 'engineer' });
    const [msg, setMsg] = useState(null);
    const [e, setE] = useState({});
    const set = (k) => (ev) => setForm({ ...form, [k]: ev.target.value });
    const submit = async (ev) => {
        ev.preventDefault(); setMsg(null); setE({});
        const crewList = [{ crew_id: Number(form.master), duty_role: 'master' }];
        if (form.second) crewList.push({ crew_id: Number(form.second), duty_role: form.second_role });
        try {
            const v = await api('/voyages', { method: 'POST', body: { vessel_id: form.vessel_id, route_id: form.route_id, voyage_type: form.voyage_type, scheduled_departure: form.scheduled_departure, fare: form.fare, crew: crewList } });
            setMsg({ tone: 'green', text: `Voyage ${v.voyage_ref} scheduled.` }); reload();
        } catch (err) { setE(errs(err)); setMsg({ tone: 'red', text: err.message }); }
    };
    const transition = async (v, status) => {
        try { const r = await api(`/voyages/${v.voyage_id}/status`, { method: 'PATCH', body: { status } }); setMsg({ tone: 'green', text: `${v.voyage_ref} → ${status}${r.final_manifests.length ? `. Final manifests issued: ${r.final_manifests.join(', ')}` : ''}` }); reload(); }
        catch (err) { setMsg({ tone: 'red', text: err.message }); }
    };
    return (
        <div className="space-y-6">
            {msg && <Alert tone={msg.tone}>{msg.text}</Alert>}
            {can('voyage.manage') && (
                <Card title="Schedule a voyage">
                    <form onSubmit={submit} className="grid md:grid-cols-4 gap-4">
                        <Field label="Vessel" error={e.vessel_id}><Select value={form.vessel_id || ''} onChange={set('vessel_id')}><option value="">Select…</option>{(vessels || []).map((v) => <option key={v.vessel_id} value={v.vessel_id}>{v.vessel_name} ({v.compliance})</option>)}</Select></Field>
                        <Field label="Route" error={e.route_id}><Select value={form.route_id || ''} onChange={set('route_id')}><option value="">Select…</option>{(routes || []).map((r) => <option key={r.route_id} value={r.route_id}>{r.route_code}: {r.origin.town} → {r.destination.town}</option>)}</Select></Field>
                        <Field label="Departure" error={e.scheduled_departure}><Input type="datetime-local" value={form.scheduled_departure || ''} onChange={set('scheduled_departure')} /></Field>
                        <Field label="Fare (₦)" error={e.fare}><Input type="number" value={form.fare} onChange={set('fare')} /></Field>
                        <Field label="Master" error={e['crew.0.crew_id']}><Select value={form.master} onChange={set('master')}><option value="">Select…</option>{(crew || []).filter((c) => c.rank === 'master').map((c) => <option key={c.crew_id} value={c.crew_id}>{c.full_name}</option>)}</Select></Field>
                        <Field label="Second crew member"><Select value={form.second} onChange={set('second')}><option value="">None</option>{(crew || []).filter((c) => c.rank !== 'master').map((c) => <option key={c.crew_id} value={c.crew_id}>{c.full_name} ({c.rank})</option>)}</Select></Field>
                        <Field label="Duty role"><Select value={form.second_role} onChange={set('second_role')}>{['mate', 'engineer', 'deckhand', 'steward'].map((r) => <option key={r}>{r}</option>)}</Select></Field>
                        <div className="flex items-end"><Button type="submit">Schedule voyage</Button></div>
                    </form>
                </Card>
            )}
            <Card title="Voyages">
                <Table cols={[
                    { key: 'voyage_ref', label: 'Voyage', render: (r) => <span className="font-mono text-xs">{r.voyage_ref}</span> },
                    { key: 'v', label: 'Vessel', render: (r) => r.vessel.vessel_name },
                    { key: 'r', label: 'Route', render: (r) => `${r.route.origin.town} → ${r.route.destination.town}` },
                    { key: 'd', label: 'Departure', render: (r) => fmt.dt(r.scheduled_departure) },
                    { key: 'p', label: 'Passengers', render: (r) => `${r.passengers}/${r.passenger_capacity}` },
                    { key: 'c', label: 'Cargo', render: (r) => `${fmt.kg(r.cargo_kg)} / ${fmt.kg(r.cargo_capacity_kg)}` },
                    { key: 's', label: 'Status', render: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                    { key: 'a', label: '', render: (r) => can('voyage.manage') && (
                        <div className="flex gap-2">
                            {r.status === 'scheduled' && <Button variant="secondary" className="!px-2 !py-1 text-xs" onClick={() => transition(r, 'boarding')}>Open boarding</Button>}
                            {r.status === 'boarding' && <Button variant="secondary" className="!px-2 !py-1 text-xs" onClick={() => transition(r, 'departed')}>Depart & close</Button>}
                            {r.status === 'departed' && <Button variant="secondary" className="!px-2 !py-1 text-xs" onClick={() => transition(r, 'arrived')}>Arrived</Button>}
                        </div>) },
                ]} rows={voyages || []} />
            </Card>
        </div>
    );
}

const VoyagePicker = ({ value, onChange, filter = (v) => ['scheduled', 'boarding'].includes(v.status), label = 'Voyage' }) => {
    const [voyages] = useLoad(() => api('/voyages'));
    return (
        <Field label={label}>
            <Select value={value || ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">Select a voyage…</option>
                {(voyages || []).filter(filter).map((v) => <option key={v.voyage_id} value={v.voyage_id}>{v.voyage_ref} · {v.vessel.vessel_name} · {fmt.dt(v.scheduled_departure)} ({v.status})</option>)}
            </Select>
        </Field>
    );
};

/* ------------------------------------------------------------------ Ticketing */
const blankPax = { surname: '', other_names: '', sex: 'M', date_of_birth: '', nationality: 'NG', id_type: 'ecowas_card', id_number: '', phone: '', next_of_kin_name: '', next_of_kin_phone: '' };
export function Ticketing({ initialVoyage }) {
    const [vid, setVid] = useState(initialVoyage || '');
    const [voyage, setVoyage] = useState(null);
    const [tickets, setTickets] = useState([]);
    const [f, setF] = useState(blankPax);
    const [e, setE] = useState({});
    const [msg, setMsg] = useState(null);
    const load = useCallback(async () => {
        if (!vid) return;
        setVoyage(await api(`/voyages/${vid}`)); setTickets(await api(`/voyages/${vid}/tickets`));
    }, [vid]);
    useEffect(() => { load(); }, [load]);
    const set = (k) => (ev) => setF({ ...f, [k]: ev.target.value });
    const submit = async (ev) => {
        ev.preventDefault(); setE({}); setMsg(null);
        try { const r = await api(`/voyages/${vid}/tickets`, { method: 'POST', body: f }); setMsg({ tone: 'green', text: `Ticket ${r.ticket.ticket_ref} issued to ${r.ticket.passenger.surname.toUpperCase()}, ${r.ticket.passenger.other_names}. Seat ${r.ticket.seat_no}. Load ${r.passengers}/${r.capacity}.` }); setF(blankPax); load(); }
        catch (err) { setE(errs(err)); setMsg({ tone: 'red', text: err.message }); }
    };
    const board = async (t) => { try { await api(`/tickets/${t.ticket_id}/board`, { method: 'POST' }); load(); } catch (err) { setMsg({ tone: 'red', text: err.message }); } };
    return (
        <div className="space-y-6">
            <Card><div className="grid md:grid-cols-2 gap-4 items-end"><VoyagePicker value={vid} onChange={setVid} />{voyage && <Meter label={`Passenger load — ${voyage.vessel.vessel_name}`} value={voyage.passengers} max={voyage.passenger_capacity} />}</div>
                {voyage?.lapsed_certificates?.length > 0 && <div className="mt-4"><Alert>Vessel certificate(s) lapsed: {voyage.lapsed_certificates.join(', ')}. Ticketing is blocked.</Alert></div>}
            </Card>
            {vid && (
                <Card title={`Issue ticket ${voyage?.route?.is_international ? '(international crossing — passport or ECOWAS card required)' : ''}`}>
                    {msg && <div className="mb-4"><Alert tone={msg.tone}>{msg.text}</Alert></div>}
                    <form onSubmit={submit} className="grid md:grid-cols-4 gap-4">
                        <Field label="Surname" error={e.surname}><Input value={f.surname} onChange={set('surname')} /></Field>
                        <Field label="Other names" error={e.other_names}><Input value={f.other_names} onChange={set('other_names')} /></Field>
                        <Field label="Sex" error={e.sex}><Select value={f.sex} onChange={set('sex')}><option value="M">Male</option><option value="F">Female</option></Select></Field>
                        <Field label="Date of birth" error={e.date_of_birth}><Input type="date" value={f.date_of_birth} onChange={set('date_of_birth')} /></Field>
                        <Field label="Nationality (ISO)" error={e.nationality}><Select value={f.nationality} onChange={set('nationality')}>{['NG', 'CM', 'GH', 'BJ', 'TG', 'GQ'].map((c) => <option key={c}>{c}</option>)}</Select></Field>
                        <Field label="Travel document type" error={e.id_type}><Select value={f.id_type} onChange={set('id_type')}><option value="passport">Passport</option><option value="ecowas_card">ECOWAS travel certificate</option><option value="nin">NIN slip</option><option value="national_id">National ID card</option><option value="voters_card">Voter's card</option></Select></Field>
                        <Field label="Document number" error={e.id_number}><Input value={f.id_number} onChange={set('id_number')} /></Field>
                        <Field label="Phone" error={e.phone}><Input value={f.phone} onChange={set('phone')} placeholder="+2348012345678" /></Field>
                        <Field label="Next of kin" error={e.next_of_kin_name}><Input value={f.next_of_kin_name} onChange={set('next_of_kin_name')} /></Field>
                        <Field label="Next-of-kin phone" error={e.next_of_kin_phone}><Input value={f.next_of_kin_phone} onChange={set('next_of_kin_phone')} /></Field>
                        <div className="md:col-span-2 flex items-end"><Button type="submit">Issue ticket</Button></div>
                    </form>
                </Card>
            )}
            {vid && (
                <Card title={`Ticketed passengers (${tickets.length})`}>
                    <Table cols={[
                        { key: 'seat_no', label: 'Seat' }, { key: 'ticket_ref', label: 'Ticket', render: (r) => <span className="font-mono text-xs">{r.ticket_ref}</span> },
                        { key: 'name', label: 'Passenger' }, { key: 'sex', label: 'Sex' }, { key: 'nationality', label: 'Nat.' },
                        { key: 'id', label: 'Document (masked)', render: (r) => <span className="font-mono text-xs">{r.id}</span> },
                        { key: 'status', label: 'Status', render: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                        { key: 'b', label: '', render: (r) => r.status === 'issued' && voyage?.status === 'boarding' && <Button variant="secondary" className="!px-2 !py-1 text-xs" onClick={() => board(r)}>Confirm boarding</Button> },
                    ]} rows={tickets} />
                </Card>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ Cargo */
export function Cargo() {
    const [vid, setVid] = useState('');
    const [voyage, setVoyage] = useState(null);
    const [items, setItems] = useState([]);
    const [shippers, reloadShippers] = useLoad(() => api('/shippers'));
    const [dg] = useLoad(() => api('/dg-classes'));
    const blank = { hs_category: 'foodstuff', package_count: 1, dg_class_id: '' };
    const [f, setF] = useState(blank);
    const [e, setE] = useState({});
    const [msg, setMsg] = useState(null);
    const load = useCallback(async () => { if (!vid) return; setVoyage(await api(`/voyages/${vid}`)); setItems(await api(`/voyages/${vid}/consignments`)); }, [vid]);
    useEffect(() => { load(); }, [load]);
    const set = (k) => (ev) => setF({ ...f, [k]: ev.target.value });
    const submit = async (ev) => {
        ev.preventDefault(); setE({}); setMsg(null);
        try { const r = await api(`/voyages/${vid}/consignments`, { method: 'POST', body: { ...f, dg_class_id: f.dg_class_id || null } }); setMsg({ tone: 'green', text: `Consignment ${r.consignment.consignment_ref} accepted. Load ${fmt.kg(r.cargo_kg)} of ${fmt.kg(r.capacity_kg)}.` }); setF(blank); load(); }
        catch (err) { setE(errs(err)); setMsg({ tone: 'red', text: err.message }); }
    };
    return (
        <div className="space-y-6">
            <Card><div className="grid md:grid-cols-2 gap-4 items-end"><VoyagePicker value={vid} onChange={setVid} />{voyage && <Meter label={`Cargo load — ${voyage.vessel.vessel_name}`} value={Math.round(voyage.cargo_kg)} max={Math.round(voyage.cargo_capacity_kg)} />}</div></Card>
            {vid && (
                <Card title="Accept consignment">
                    {msg && <div className="mb-4"><Alert tone={msg.tone}>{msg.text}</Alert></div>}
                    <form onSubmit={submit} className="grid md:grid-cols-4 gap-4">
                        <Field label="Shipper" error={e.shipper_id}><Select value={f.shipper_id || ''} onChange={set('shipper_id')}><option value="">Select…</option>{(shippers || []).map((s) => <option key={s.shipper_id} value={s.shipper_id}>{s.full_name} ({s.country_code})</option>)}</Select></Field>
                        <Field label="Consignee" error={e.consignee_id}><Select value={f.consignee_id || ''} onChange={set('consignee_id')}><option value="">Select…</option>{(shippers || []).map((s) => <option key={s.shipper_id} value={s.shipper_id}>{s.full_name} ({s.country_code})</option>)}</Select></Field>
                        <Field label="Description of goods" error={e.description} className="md:col-span-2"><Input value={f.description || ''} onChange={set('description')} /></Field>
                        <Field label="Category" error={e.hs_category}><Select value={f.hs_category} onChange={set('hs_category')}>{['foodstuff', 'textiles_footwear', 'building_materials', 'petroleum_products', 'household_goods', 'electronics', 'vehicles_parts', 'agricultural_produce', 'beverages', 'other'].map((c) => <option key={c} value={c}>{c.replace('_', ' ')}</option>)}</Select></Field>
                        <Field label="Marks & numbers" error={e.marks_numbers}><Input value={f.marks_numbers || ''} onChange={set('marks_numbers')} /></Field>
                        <Field label="Packages" error={e.package_count}><Input type="number" value={f.package_count} onChange={set('package_count')} /></Field>
                        <Field label="Declared weight (kg)" error={e.weight_kg}><Input type="number" step="0.01" value={f.weight_kg || ''} onChange={set('weight_kg')} /></Field>
                        <Field label="Dangerous-goods class" error={e.dg_class_id} className="md:col-span-2"><Select value={f.dg_class_id} onChange={set('dg_class_id')}><option value="">Not dangerous goods</option>{(dg || []).map((d) => <option key={d.dg_class_id} value={d.dg_class_id}>Class {d.class_code}: {d.description}</option>)}</Select></Field>
                        <Field label="Declared value (₦)" error={e.declared_value}><Input type="number" value={f.declared_value || ''} onChange={set('declared_value')} /></Field>
                        <div className="flex items-end"><Button type="submit">Accept consignment</Button></div>
                    </form>
                </Card>
            )}
            {vid && (
                <Card title={`Consignments (${items.length})`}>
                    <Table cols={[
                        { key: 'consignment_ref', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.consignment_ref}</span> },
                        { key: 's', label: 'Shipper → Consignee', render: (r) => `${r.shipper.full_name} → ${r.consignee.full_name}` },
                        { key: 'description', label: 'Goods' }, { key: 'package_count', label: 'Pkgs' },
                        { key: 'weight_kg', label: 'Weight', render: (r) => fmt.kg(r.weight_kg) },
                        { key: 'dg', label: 'DG', render: (r) => r.dg_class ? <Badge tone="red">Class {r.dg_class.class_code}</Badge> : '—' },
                    ]} rows={items} />
                </Card>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ Manifests */
export function ManifestView({ m }) {
    const c = m.content;
    const pax = m.manifest_type === 'passenger';
    return (
        <div className="bg-white border border-slate-300 rounded p-6 text-sm print:border-0" id="manifest-doc">
            <div className="flex justify-between items-start border-b-2 border-slate-800 pb-3 mb-3">
                <div>
                    <div className="text-xs uppercase tracking-widest text-slate-500">Secure Water Transport DBMS · Oron–Idenua Waterway</div>
                    <h3 className="text-lg font-bold">{pax ? 'PASSENGER MANIFEST (Passenger List)' : 'CARGO MANIFEST'}</h3>
                    <div className="text-xs text-slate-600">Ref <b className="font-mono">{m.manifest_ref}</b> · Issue {m.issue_no} · {m.is_final ? 'FINAL (closed at departure)' : 'Provisional'}</div>
                </div>
                <div className="text-right text-xs">
                    <div>Issued {fmt.dt(m.issued_at)}</div><div>by {m.issued_by}</div>
                    <div className="mt-1">Verification code</div><div className="font-mono font-bold text-base">{m.verification_code}</div>
                </div>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs mb-4">
                <div><span className="text-slate-500">Voyage</span><br /><b>{c.header.voyage_ref}</b></div>
                <div><span className="text-slate-500">Vessel</span><br /><b>{c.header.vessel_name}</b> ({c.header.registration_no})</div>
                <div><span className="text-slate-500">Route</span><br /><b>{c.header.route}</b></div>
                <div><span className="text-slate-500">Departure / Master</span><br /><b>{c.header.scheduled_departure}</b> · {c.header.master}</div>
            </div>
            {pax ? (
                <Table cols={[{ key: 'no', label: '#' }, { key: 'n', label: 'Surname, other names', render: (r) => `${r.surname}, ${r.other_names}` }, { key: 'sex', label: 'Sex' }, { key: 'date_of_birth', label: 'Date of birth' }, { key: 'nationality', label: 'Nat.' }, { key: 'd', label: 'Travel document', render: (r) => <span className="font-mono text-xs">{r.id_type} {r.id_number}</span> }, { key: 'next_of_kin', label: 'Next of kin' }, { key: 'next_of_kin_phone', label: 'NOK phone' }]} rows={c.entries} />
            ) : (
                <Table cols={[{ key: 'no', label: '#' }, { key: 'consignment_ref', label: 'Ref' }, { key: 'shipper', label: 'Shipper' }, { key: 'consignee', label: 'Consignee' }, { key: 'description', label: 'Description' }, { key: 'marks_numbers', label: 'Marks' }, { key: 'packages', label: 'Pkgs' }, { key: 'weight_kg', label: 'Weight (kg)' }, { key: 'dg_class', label: 'DG class', render: (r) => r.dg_class ? <b className="text-red-700">{r.dg_class}</b> : '—' }]} rows={c.entries} />
            )}
            <div className="mt-4 pt-3 border-t border-slate-300 text-xs flex flex-wrap gap-x-8 gap-y-1">
                {Object.entries(c.summary).map(([k, v]) => <span key={k}><span className="text-slate-500">{k.replaceAll('_', ' ')}:</span> <b>{String(v)}</b></span>)}
            </div>
            <div className="mt-2 text-[10px] text-slate-500 font-mono break-all">SHA-256 {m.content_hash}</div>
        </div>
    );
}

export function Manifests({ me }) {
    const [vid, setVid] = useState('');
    const [list, setList] = useState([]);
    const [current, setCurrent] = useState(null);
    const [msg, setMsg] = useState(null);
    const [ver, setVer] = useState({ manifest_ref: '', code: '' });
    const [verResult, setVerResult] = useState(null);
    const can = (p) => me.permissions.includes('*') || me.permissions.includes(p);
    const load = useCallback(async () => { if (vid) setList(await api(`/voyages/${vid}/manifests`)); }, [vid]);
    useEffect(() => { load(); setCurrent(null); }, [load]);
    const gen = async (type) => {
        setMsg(null);
        try { const m = await api(`/voyages/${vid}/manifests`, { method: 'POST', body: { type } }); setCurrent(m); setMsg({ tone: 'green', text: `${m.manifest_ref} generated from ${m.content.entries.length} primary records in ${m.generation_ms} ms.` }); load(); }
        catch (err) { setMsg({ tone: 'red', text: err.message }); }
    };
    const open = async (id) => setCurrent(await api(`/manifests/${id}`));
    const verify = async (ev) => { ev.preventDefault(); try { setVerResult(await api('/manifests/verify', { method: 'POST', body: ver })); } catch (err) { setVerResult({ error: err.message }); } };
    return (
        <div className="space-y-6">
            <Card>
                <div className="grid md:grid-cols-3 gap-4 items-end">
                    <VoyagePicker value={vid} onChange={setVid} filter={() => true} />
                    {vid && can('manifest.generate') && <div className="flex gap-2"><Button onClick={() => gen('passenger')}>Generate passenger manifest</Button><Button variant="secondary" onClick={() => gen('cargo')}>Generate cargo manifest</Button></div>}
                </div>
                {msg && <div className="mt-4"><Alert tone={msg.tone}>{msg.text}</Alert></div>}
                {list.length > 0 && <div className="mt-4"><Table cols={[
                    { key: 'manifest_ref', label: 'Manifest', render: (r) => <button className="font-mono text-xs text-sky-800 underline" onClick={() => open(r.manifest_id)}>{r.manifest_ref}</button> },
                    { key: 'manifest_type', label: 'Type' }, { key: 'entry_count', label: 'Entries' }, { key: 'verification_code', label: 'Verification code', render: (r) => <span className="font-mono text-xs">{r.verification_code}</span> },
                    { key: 'is_final', label: 'State', render: (r) => <Badge tone={r.is_final ? 'green' : 'amber'}>{r.is_final ? 'final' : 'provisional'}</Badge> },
                    { key: 'issued_at', label: 'Issued', render: (r) => fmt.dt(r.issued_at) },
                ]} rows={list} /></div>}
            </Card>
            {current && <><div className="flex justify-end no-print"><Button variant="secondary" onClick={() => window.print()}>Print</Button></div><ManifestView m={current} /></>}
            {can('manifest.verify') && (
                <Card title="Verify a printed manifest">
                    <form onSubmit={verify} className="grid md:grid-cols-3 gap-4 items-end">
                        <Field label="Manifest reference"><Input value={ver.manifest_ref} onChange={(e) => setVer({ ...ver, manifest_ref: e.target.value })} placeholder="PM-ORN-IDN-261012-01-01" /></Field>
                        <Field label="Verification code"><Input value={ver.code} onChange={(e) => setVer({ ...ver, code: e.target.value })} /></Field>
                        <Button type="submit">Verify</Button>
                    </form>
                    {verResult && <div className="mt-4">{verResult.error ? <Alert>{verResult.error}</Alert> : <Alert tone={verResult.valid ? 'green' : 'red'}>{verResult.valid ? 'AUTHENTIC' : 'NOT AUTHENTIC'} — stored record intact: {String(verResult.stored_record_intact)}; code matches: {String(verResult.verification_code_matches)}; consistent with live records: {String(verResult.consistent_with_live_records)}{verResult.issued_by && ` · issued ${verResult.issued_at} by ${verResult.issued_by}, ${verResult.entry_count} entries`}{verResult.reason && ` · ${verResult.reason}`}</Alert>}</div>}
                </Card>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ Emergency */
export function Emergency({ me }) {
    const [ref, setRef] = useState('');
    const [d, setD] = useState(null);
    const [err, setErr] = useState(null);
    const [inc, setInc] = useState({ incident_type: 'capsize', severity: 'critical', description: '', last_latitude: '', last_longitude: '' });
    const [incMsg, setIncMsg] = useState(null);
    const go = async (ev) => { ev?.preventDefault(); setErr(null); try { setD(await api(`/emergency/${encodeURIComponent(ref.trim().toUpperCase())}`)); } catch (e) { setD(null); setErr(e.message); } };
    const report = async (ev) => {
        ev.preventDefault();
        try {
            const v = (await api('/voyages')).find((x) => x.voyage_ref === d.voyage_ref);
            const r = await api(`/voyages/${v.voyage_id}/incidents`, { method: 'POST', body: { ...inc, last_latitude: inc.last_latitude || null, last_longitude: inc.last_longitude || null } });
            setIncMsg({ tone: 'green', text: `Incident ${r.incident_ref} recorded.` }); go();
        } catch (e) { setIncMsg({ tone: 'red', text: e.message }); }
    };
    return (
        <div className="space-y-6">
            <Card title="Emergency retrieval — persons and cargo at risk">
                <form onSubmit={go} className="flex gap-3 items-end"><Field label="Voyage reference" className="flex-1"><Input value={ref} onChange={(e) => setRef(e.target.value)} placeholder="ORN-IDN-261013-01" /></Field><Button variant="danger" type="submit">Retrieve</Button></form>
                {err && <div className="mt-4"><Alert>{err}</Alert></div>}
            </Card>
            {d && (
                <>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <Stat label="Persons on board" value={d.persons_on_board} sub={`${d.passengers.length} passengers + ${d.crew.length} crew`} tone="red" />
                        <Stat label="Vessel" value={d.vessel.name} sub={`${d.vessel.registration_no} · ${d.vessel.operator}`} />
                        <Stat label="Departed" value={fmt.dt(d.departed_at)} sub={`ETA ${fmt.dt(d.expected_arrival)}`} />
                        <Stat label="Dangerous goods aboard" value={d.dangerous_goods.length} sub={`total cargo ${fmt.kg(d.cargo_total_kg)}`} tone={d.dangerous_goods.length ? 'red' : 'green'} />
                    </div>
                    <div className="text-xs text-slate-500">Retrieved in {d.retrieval_ms} ms · this access has been logged.</div>
                    {d.dangerous_goods.length > 0 && <Alert>HAZARD: {d.dangerous_goods.map((g) => `${g.class} — ${g.description} (${g.weight_kg} kg). ${g.stowage}`).join(' | ')}</Alert>}
                    <Card title={`Passenger list with next of kin — ${d.route}`}>
                        <Table cols={[{ key: 'name', label: 'Name' }, { key: 'sex', label: 'Sex' }, { key: 'age', label: 'Age' }, { key: 'nationality', label: 'Nat.' }, { key: 'b', label: 'Boarded', render: (r) => r.boarded ? 'Yes' : 'Ticketed' }, { key: 'next_of_kin', label: 'Next of kin' }, { key: 'next_of_kin_phone', label: 'NOK phone' }]} rows={d.passengers} />
                        <div className="mt-3 text-sm"><b>Crew:</b> {d.crew.map((c) => `${c.name} (${c.duty})`).join(', ')}</div>
                    </Card>
                    {me.permissions.includes('incident.report') && (
                        <Card title="Record incident">
                            {incMsg && <div className="mb-3"><Alert tone={incMsg.tone}>{incMsg.text}</Alert></div>}
                            <form onSubmit={report} className="grid md:grid-cols-5 gap-3">
                                <Field label="Type"><Select value={inc.incident_type} onChange={(e) => setInc({ ...inc, incident_type: e.target.value })}>{['capsize', 'collision', 'engine_failure', 'piracy_abduction', 'fire', 'man_overboard', 'weather', 'other'].map((t) => <option key={t}>{t}</option>)}</Select></Field>
                                <Field label="Severity"><Select value={inc.severity} onChange={(e) => setInc({ ...inc, severity: e.target.value })}>{['low', 'medium', 'high', 'critical'].map((t) => <option key={t}>{t}</option>)}</Select></Field>
                                <Field label="Last latitude"><Input value={inc.last_latitude} onChange={(e) => setInc({ ...inc, last_latitude: e.target.value })} /></Field>
                                <Field label="Last longitude"><Input value={inc.last_longitude} onChange={(e) => setInc({ ...inc, last_longitude: e.target.value })} /></Field>
                                <div className="flex items-end"><Button variant="danger" type="submit">Record</Button></div>
                                <Field label="Description" className="md:col-span-5"><Textarea rows={2} value={inc.description} onChange={(e) => setInc({ ...inc, description: e.target.value })} /></Field>
                            </form>
                        </Card>
                    )}
                </>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ Border disclosure */
export function Border({ me }) {
    const can = (p) => me.permissions.includes('*') || me.permissions.includes(p);
    const [list, reload] = useLoad(() => api('/border-declarations'));
    const [vid, setVid] = useState('');
    const [f, setF] = useState({ direction: 'departure', recipient_authority: 'Cameroon Police Frontier Post, Idenau', recipient_country: 'CM', lawful_basis: 'Advance passenger list under bilateral border-control arrangement; NDPA 2023 s.41 adequacy safeguard', fields: ['surname', 'other_names', 'nationality', 'id_type', 'id_number'] });
    const [msg, setMsg] = useState(null);
    const [exp, setExp] = useState(null);
    const all = ['surname', 'other_names', 'sex', 'date_of_birth', 'nationality', 'id_type', 'id_number'];
    const toggle = (k) => setF({ ...f, fields: f.fields.includes(k) ? f.fields.filter((x) => x !== k) : [...f.fields, k] });
    const request = async (ev) => { ev.preventDefault(); try { const r = await api(`/voyages/${vid}/border-declarations`, { method: 'POST', body: f }); setMsg({ tone: 'green', text: `Disclosure ${r.declaration_ref} requested; awaiting independent approval.` }); reload(); } catch (e) { setMsg({ tone: 'red', text: e.message }); } };
    const decide = async (d, decision) => { try { await api(`/border-declarations/${d.declaration_id}/decision`, { method: 'POST', body: { decision } }); reload(); } catch (e) { setMsg({ tone: 'red', text: e.message }); } };
    const doExport = async (d) => { try { setExp(await api(`/border-declarations/${d.declaration_id}/export`)); reload(); } catch (e) { setMsg({ tone: 'red', text: e.message }); } };
    return (
        <div className="space-y-6">
            {msg && <Alert tone={msg.tone}>{msg.text}</Alert>}
            {can('border.request') && (
                <Card title="Request cross-border disclosure (minimised, approved, logged)">
                    <form onSubmit={request} className="grid md:grid-cols-3 gap-4">
                        <VoyagePicker value={vid} onChange={setVid} filter={() => true} />
                        <Field label="Recipient authority"><Input value={f.recipient_authority} onChange={(e) => setF({ ...f, recipient_authority: e.target.value })} /></Field>
                        <Field label="Country"><Input value={f.recipient_country} onChange={(e) => setF({ ...f, recipient_country: e.target.value })} /></Field>
                        <Field label="Lawful basis" className="md:col-span-3"><Input value={f.lawful_basis} onChange={(e) => setF({ ...f, lawful_basis: e.target.value })} /></Field>
                        <div className="md:col-span-3 flex flex-wrap gap-4 text-sm">{all.map((k) => <label key={k} className="flex items-center gap-1"><input type="checkbox" checked={f.fields.includes(k)} onChange={() => toggle(k)} />{k.replaceAll('_', ' ')}</label>)}</div>
                        <div><Button type="submit">Submit request</Button></div>
                    </form>
                </Card>
            )}
            <Card title="Disclosure register">
                <Table cols={[
                    { key: 'declaration_ref', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.declaration_ref}</span> },
                    { key: 'recipient_authority', label: 'Recipient' }, { key: 'fields', label: 'Fields', render: (r) => r.fields_disclosed.join(', ') },
                    { key: 'status', label: 'Status', render: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                    { key: 'a', label: '', render: (r) => <div className="flex gap-2">
                        {r.status === 'requested' && can('border.approve') && <><Button variant="success" className="!px-2 !py-1 text-xs" onClick={() => decide(r, 'approved')}>Approve</Button><Button variant="danger" className="!px-2 !py-1 text-xs" onClick={() => decide(r, 'rejected')}>Reject</Button></>}
                        {['approved', 'transmitted'].includes(r.status) && can('border.view') && <Button variant="secondary" className="!px-2 !py-1 text-xs" onClick={() => doExport(r)}>Export</Button>}
                    </div> },
                ]} rows={list || []} />
            </Card>
            {exp && <Card title={`Export ${exp.declaration_ref} — ${exp.passengers.length} records`}><div className="text-xs font-mono break-all mb-2">SHA-256 {exp.export_hash}</div><pre className="text-xs bg-slate-50 p-3 rounded max-h-64 overflow-auto">{JSON.stringify(exp.passengers.slice(0, 5), null, 2)}{exp.passengers.length > 5 ? '\n…' : ''}</pre></Card>}
        </div>
    );
}

/* ------------------------------------------------------------------ Audit */
export function Audit() {
    const [rows, reload] = useLoad(() => api('/audit'));
    const [chain, setChain] = useState(null);
    return (
        <div className="space-y-6">
            <Card title="Append-only audit trail" action={<Button variant="secondary" onClick={async () => setChain(await api('/audit/verify'))}>Verify hash chain</Button>}>
                {chain && <div className="mb-4"><Alert tone={chain.chain_valid ? 'green' : 'red'}>{chain.chain_valid ? `Hash chain intact: ${chain.rows_checked} audit records verified.` : `Chain broken at record ${chain.first_broken_row}.`}</Alert></div>}
                <Table cols={[
                    { key: 'created_at', label: 'Time', render: (r) => <span className="whitespace-nowrap">{fmt.dt(r.created_at)}</span> },
                    { key: 'user', label: 'User', render: (r) => r.user || '—' }, { key: 'event', label: 'Event' },
                    { key: 'outcome', label: 'Outcome', render: (r) => <Badge tone={r.outcome === 'success' ? 'green' : 'red'}>{r.outcome}</Badge> },
                    { key: 'ip_address', label: 'IP' }, { key: 'detail', label: 'Detail', render: (r) => <span className="text-xs">{r.detail}</span> },
                    { key: 'row_hash', label: 'Row hash', render: (r) => <span className="font-mono text-[10px]">{r.row_hash.slice(0, 16)}…</span> },
                ]} rows={rows || []} />
            </Card>
        </div>
    );
}
