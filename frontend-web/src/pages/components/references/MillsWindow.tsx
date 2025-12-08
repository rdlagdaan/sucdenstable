import React, { useEffect, useState } from 'react';
import napi from '../../../utils/axiosnapi';

type Mill = {
  id: number;
  mill_id: string;
  mill_name: string;
  prefix: string;
  company_id?: number;
  workstation_id?: string | null;
  user_id?: number | null;
  created_at?: string;
  updated_at?: string;
};

type MillRate = {
  id: number;
  mill_record_id: number;
  mill_id: string;
  crop_year: string;
  insurance_rate: number | null;
  storage_rate: number | null;
  days_free: number | null;
  market_value: number | null;
  ware_house: string | null;
  shippable_flag: boolean;
  locked: number; // 0 = unlocked, non-zero locked
  workstation_id?: string | null;
  user_id?: number | null;
  created_at?: string;
  updated_at?: string;
};

type PagePayload<T> = {
  data: T[]; current_page: number; per_page: number; total: number; last_page: number; from: number|null; to: number|null;
};

const PER_PAGE = 10;

const MillsWindow: React.FC = () => {
  // --- master list state ---
  const [rows, setRows] = useState<Mill[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [from, setFrom] = useState<number|null>(null);
  const [to, setTo] = useState<number|null>(null);
  const [loading, setLoading] = useState(false);

  // --- form state (master add/update) ---
  const [editingId, setEditingId] = useState<number|null>(null);
  const [mill_id, setMillId] = useState('');
  const [mill_name, setMillName] = useState('');
  const [prefix, setPrefix] = useState('');

  // --- selection for detail panel ---
  const [selected, setSelected] = useState<Mill | null>(null);

  const companyHeader = () => {
    const cid =
      localStorage.getItem('company_id') ??
      JSON.parse(localStorage.getItem('auth') || '{}')?.company_id ?? '';
    return { 'X-Company-ID': cid || '' };
  };

  const load = async (opts?: { page?: number; q?: string }) => {
    setLoading(true);
    try {
      const nextPage = opts?.page ?? page;
      const q = opts?.q ?? search;
      const res = await napi.get('/references/mills', {
        params: { search: q, page: nextPage, per_page: PER_PAGE },
        headers: companyHeader(),
      });
      const payload: PagePayload<Mill> | Mill[] = res.data;
      if (Array.isArray(payload)) {
        setRows(payload); setTotal(payload.length); setLastPage(1);
        setFrom(payload.length ? 1 : 0); setTo(payload.length);
      } else {
        setRows(payload.data ?? []); setTotal(payload.total ?? 0); setLastPage(payload.last_page ?? 1);
        setFrom(payload.from ?? null); setTo(payload.to ?? null); setPage(payload.current_page ?? nextPage);
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load({ page: 1 }); /* eslint-disable react-hooks/exhaustive-deps */ }, []);

  const onSearch = async () => { setPage(1); await load({ page: 1, q: search }); };

  const resetForm = () => {
    setEditingId(null); setMillId(''); setMillName(''); setPrefix('');
  };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setLoading(true);
    try {
      const payload = {
        mill_id: (mill_id || '').toUpperCase().trim(),
        mill_name: mill_name.trim(),
        prefix: (prefix || '').toUpperCase().trim(),
      };
      const headers = companyHeader();
      if (editingId) {
        await napi.put(`/references/mills/${editingId}`, payload, { headers });
      } else {
        await napi.post('/references/mills', payload, { headers });
      }
      resetForm();
      await load({ page });
    } catch (err:any) {
      alert(err?.response?.data?.message ?? 'Save failed');
    } finally { setLoading(false); }
  };

  const onEdit = (m: Mill) => {
    setEditingId(m.id);
    setMillId(m.mill_id || '');
    setMillName(m.mill_name || '');
    setPrefix(m.prefix || '');
  };

  const onDelete = async (id: number) => {
    if (!confirm('Delete this mill?')) return;
    setLoading(true);
    try {
      await napi.delete(`/references/mills/${id}`, { headers: companyHeader() });
      const nextPage = rows.length === 1 && page > 1 ? page - 1 : page;
      if (selected?.id === id) setSelected(null);
      await load({ page: nextPage });
    } catch (e:any) {
      alert(e?.response?.data?.message ?? 'Delete failed');
    } finally { setLoading(false); }
  };

  // pagination
  const goFirst = async () => { if (page > 1) await load({ page: 1 }); };
  const goPrev  = async () => { if (page > 1) await load({ page: page - 1 }); };
  const goNext  = async () => { if (page < lastPage) await load({ page: page + 1 }); };
  const goLast  = async () => { if (page < lastPage) await load({ page: lastPage }); };

  return (
    <div className="p-4">
      {/* Search + status */}
      <div className="flex items-center gap-2 mb-3">
        <input
          value={search}
          onChange={(e)=>setSearch(e.target.value)}
          placeholder="Search mills (ID, Name, Prefix)…"
          className="border rounded px-3 py-2 text-sm w-[36rem] max-w-[60vw]"
        />
        <button onClick={onSearch} className="px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-500">
          Search
        </button>
        {loading && <span className="text-xs text-gray-500">Loading…</span>}
      </div>

      {/* Master form */}
      <form onSubmit={onSubmit} className="grid grid-cols-12 gap-3 mb-4 text-sm">
        <input required value={mill_id} onChange={(e)=>setMillId(e.target.value.toUpperCase())} placeholder="Mill ID *" className="border rounded px-3 py-2 col-span-3"/>
        <input required value={mill_name} onChange={(e)=>setMillName(e.target.value)} placeholder="Mill Name *" className="border rounded px-3 py-2 col-span-6"/>
        <input value={prefix} onChange={(e)=>setPrefix(e.target.value.toUpperCase())} placeholder="Prefix" className="border rounded px-3 py-2 col-span-1"/>
        <div className="col-span-2 flex items-center justify-end">
          <button type="submit" className="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-500">
            {editingId ? 'Update' : 'Add'}
          </button>
        </div>
      </form>

      <div className="grid grid-cols-12 gap-4">
        {/* Master grid */}
        <div className="col-span-6 border rounded overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-100 sticky top-0">
              <tr className="text-left">
                <th className="p-2">Mill ID</th>
                <th className="p-2">Name</th>
                <th className="p-2">Prefix</th>
                <th className="p-2 w-40">Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((m)=>(
                <tr key={m.id} className={`border-t ${selected?.id===m.id ? 'bg-blue-50' : ''}`}>
                  <td className="p-2">{m.mill_id}</td>
                  <td className="p-2">{m.mill_name}</td>
                  <td className="p-2">{m.prefix}</td>
                  <td className="p-2">
                    <div className="flex gap-2">
                      <button onClick={()=>setSelected(m)} className="px-2 py-1 text-indigo-600 hover:underline">View Rates</button>
                      <button onClick={()=>onEdit(m)} className="px-2 py-1 text-blue-600 hover:underline">Edit</button>
                      <button onClick={()=>onDelete(m.id)} className="px-2 py-1 text-red-600 hover:underline">Delete</button>
                    </div>
                  </td>
                </tr>
              ))}
              {rows.length===0 && (<tr><td colSpan={4} className="p-4 text-center text-gray-500">No records</td></tr>)}
            </tbody>
          </table>

          {/* Pagination */}
          <div className="p-2 flex items-center justify-between text-sm">
            <div className="text-gray-600">
              {total>0 ? <>Showing {from ?? 0}–{to ?? 0} of {total}</> : <>No results</>}
            </div>
            <div className="flex items-center gap-1">
              <button onClick={goFirst} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>First</button>
              <button onClick={goPrev}  className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>Prev</button>
              <span className="px-2">{page} / {lastPage}</span>
              <button onClick={goNext}  className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Next</button>
              <button onClick={goLast}  className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Last</button>
            </div>
          </div>
        </div>

        {/* Detail panel */}
        <div className="col-span-6">
          {selected ? <MillRatesPanel mill={selected} companyHeader={companyHeader} /> : (
            <div className="p-4 text-sm text-gray-600 border rounded">Select a mill to view and edit rates by crop year.</div>
          )}
        </div>
      </div>
    </div>
  );
};

const MillRatesPanel: React.FC<{ mill: Mill; companyHeader: () => Record<string,string> }> = ({ mill, companyHeader }) => {
  const [list, setList] = useState<MillRate[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedRateId, setSelectedRateId] = useState<number | null>(null);

  // form for selected crop year
  const [crop_year, setCropYear] = useState('');
  const [insurance_rate, setInsurance] = useState<string>('');
  const [storage_rate, setStorage] = useState<string>('');
  const [days_free, setDaysFree] = useState<string>('');
  const [market_value, setMarketValue] = useState<string>('');
  const [ware_house, setWareHouse] = useState<string>('');
  const [shippable_flag, setShippable] = useState<boolean>(false);
  const [locked, setLocked] = useState<number>(0);

  const isLocked = locked !== 0;

  const loadRates = async () => {
    setLoading(true);
    try {
      const res = await napi.get(`/references/mills/${mill.id}/rates`, {
        headers: companyHeader(),
      });
      setList(res.data ?? []);
      // if a selected rate id no longer exists, clear selection
      if (selectedRateId && !(res.data as MillRate[]).some((r)=>r.id===selectedRateId)) {
        clearForm();
      }
    } finally { setLoading(false); }
  };

  useEffect(() => { loadRates(); /* eslint-disable react-hooks/exhaustive-deps */ }, [mill.id]);

  const clearForm = () => {
    setSelectedRateId(null);
    setCropYear('');
    setInsurance('');
    setStorage('');
    setDaysFree('');
    setMarketValue('');
    setWareHouse('');
    setShippable(false);
    setLocked(0);
  };

  const onPick = (r: MillRate) => {
    setSelectedRateId(r.id);
    setCropYear(r.crop_year || '');
    setInsurance(r.insurance_rate!=null ? String(r.insurance_rate) : '');
    setStorage(r.storage_rate!=null ? String(r.storage_rate) : '');
    setDaysFree(r.days_free!=null ? String(r.days_free) : '');
    setMarketValue(r.market_value!=null ? String(r.market_value) : '');
    setWareHouse(r.ware_house || '');
    setShippable(!!r.shippable_flag);
    setLocked(r.locked || 0);
  };

  const onNew = () => {
    clearForm();
  };

  const onSave = async () => {
    if (!crop_year.trim()) { alert('Crop year is required.'); return; }
    const payload = {
      crop_year: crop_year.trim(),
      insurance_rate: insurance_rate === '' ? null : Number(insurance_rate),
      storage_rate: storage_rate === '' ? null : Number(storage_rate),
      days_free: days_free === '' ? null : Number(days_free),
      market_value: market_value === '' ? null : Number(market_value),
      ware_house: ware_house || null,
      shippable_flag: !!shippable_flag,
    };
    try {
      setLoading(true);
      if (selectedRateId) {
        await napi.put(`/references/mills/${mill.id}/rates/${selectedRateId}`, payload, { headers: companyHeader() });
      } else {
        await napi.post(`/references/mills/${mill.id}/rates`, payload, { headers: companyHeader() });
      }
      await loadRates();
    } catch (e:any) {
      alert(e?.response?.data?.message ?? 'Save failed');
    } finally { setLoading(false); }
  };

  const onDelete = async () => {
    if (!selectedRateId) return;
    if (!confirm('Delete this crop year rate?')) return;
    try {
      setLoading(true);
      await napi.delete(`/references/mills/${mill.id}/rates/${selectedRateId}`, { headers: companyHeader() });
      clearForm();
      await loadRates();
    } catch (e:any) {
      alert(e?.response?.data?.message ?? 'Delete failed');
    } finally { setLoading(false); }
  };

  const onLock = async () => {
    if (!selectedRateId) return;
    if (!confirm('Lock this crop year rate?')) return;
    try {
      setLoading(true);
      await napi.post(`/references/mills/${mill.id}/rates/${selectedRateId}/lock`, {}, { headers: companyHeader() });
      await loadRates();
      const updated = (list.find(r=>r.id===selectedRateId));
      if (updated) setLocked(1);
    } catch (e:any) {
      alert(e?.response?.data?.message ?? 'Lock failed');
    } finally { setLoading(false); }
  };

  const onUnlock = async () => {
    if (!selectedRateId) return;
    const reason = prompt('Unlock reason (required):')?.trim();
    if (!reason) return;
    try {
      setLoading(true);
      await napi.post(`/references/mills/${mill.id}/rates/${selectedRateId}/unlock`, { reason }, { headers: companyHeader() });
      await loadRates();
      const updated = (list.find(r=>r.id===selectedRateId));
      if (updated) setLocked(0);
    } catch (e:any) {
      alert(e?.response?.data?.message ?? 'Unlock failed');
    } finally { setLoading(false); }
  };

  return (
    <div className="border rounded p-3 text-sm">
      <div className="mb-2">
        <div className="font-semibold">{mill.mill_name}</div>
        <div className="text-xs text-gray-600">Mill ID: {mill.mill_id} &middot; Prefix: {mill.prefix || '-'}</div>
      </div>

      <div className="grid grid-cols-12 gap-3">
        {/* Left: Crop years list */}
        <div className="col-span-5 border rounded h-[420px] overflow-auto">
          <div className="flex items-center justify-between p-2 border-b">
            <div className="font-medium">Crop Years</div>
            <button onClick={onNew} className="px-2 py-1 text-xs bg-gray-800 text-white rounded hover:bg-gray-700">Add Crop Year</button>
          </div>
          <ul>
            {list.map((r)=>(
              <li key={r.id}
                  className={`px-3 py-2 border-b hover:bg-gray-50 cursor-pointer ${selectedRateId===r.id ? 'bg-blue-50' : ''}`}
                  onClick={()=>onPick(r)}>
                <div className="flex items-center justify-between">
                  <span className="font-medium">{r.crop_year}</span>
                  <span className={`text-xs ${r.locked ? 'text-red-600' : 'text-green-600'}`}>
                    {r.locked ? 'Locked' : 'Unlocked'}
                  </span>
                </div>
                <div className="text-xs text-gray-600">
                  Ins: {r.insurance_rate ?? '-'} · Stor: {r.storage_rate ?? '-'} · Free: {r.days_free ?? '-'}
                </div>
              </li>
            ))}
            {list.length===0 && <li className="p-3 text-gray-500 text-center">No crop year rates yet</li>}
          </ul>
        </div>

        {/* Right: Form */}
        <div className="col-span-7 border rounded p-3">
          <div className="mb-2 flex items-center justify-between">
            <div className="font-medium">{selectedRateId ? 'Edit Crop Year Rate' : 'New Crop Year Rate'}</div>
            <div className="flex items-center gap-2">
              {!!selectedRateId && (
                <>
                  <button onClick={onLock} disabled={isLocked} className="px-2 py-1 text-xs rounded border disabled:opacity-40">
                    Lock
                  </button>
                  <button onClick={onUnlock} disabled={!isLocked} className="px-2 py-1 text-xs rounded border disabled:opacity-40">
                    Unlock
                  </button>
                  <button onClick={onDelete} disabled={isLocked} className="px-2 py-1 text-xs rounded border border-red-300 text-red-600 disabled:opacity-40">
                    Delete
                  </button>
                </>
              )}
              <button onClick={onSave} disabled={isLocked || loading} className="px-3 py-1 text-xs rounded bg-green-600 text-white disabled:opacity-40">
                {selectedRateId ? 'Save' : 'Create'}
              </button>
            </div>
          </div>

          <div className="grid grid-cols-12 gap-3">
            <input value={crop_year} onChange={(e)=>setCropYear(e.target.value)} placeholder="Crop Year (e.g., 2024-2025)" className="border rounded px-3 py-2 col-span-6" disabled={!!selectedRateId}/>
            <label className="col-span-6 flex items-center justify-end text-xs">
              <span className={`px-2 py-1 rounded ${isLocked ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>
                {isLocked ? 'Locked' : 'Unlocked'}
              </span>
            </label>

            <input value={insurance_rate} onChange={(e)=>setInsurance(e.target.value)} placeholder="Insurance Rate" className="border rounded px-3 py-2 col-span-4" disabled={isLocked}/>
            <input value={storage_rate} onChange={(e)=>setStorage(e.target.value)} placeholder="Storage Rate" className="border rounded px-3 py-2 col-span-4" disabled={isLocked}/>
            <input value={days_free} onChange={(e)=>setDaysFree(e.target.value)} placeholder="Days Free" className="border rounded px-3 py-2 col-span-4" disabled={isLocked}/>

            <input value={market_value} onChange={(e)=>setMarketValue(e.target.value)} placeholder="Market Value" className="border rounded px-3 py-2 col-span-6" disabled={isLocked}/>
            <input value={ware_house} onChange={(e)=>setWareHouse(e.target.value)} placeholder="Warehouse" className="border rounded px-3 py-2 col-span-4" disabled={isLocked}/>
            <label className="col-span-2 flex items-center gap-2 text-sm">
              <input type="checkbox" checked={shippable_flag} onChange={(e)=>setShippable(e.target.checked)} disabled={isLocked}/>
              <span>Shippable</span>
            </label>
          </div>
        </div>
      </div>
    </div>
  );
};

export default MillsWindow;
