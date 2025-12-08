// frontend-web/src/pages/components/references/MainAccountsWindow.tsx
import React, { useEffect, useMemo, useState } from 'react';
import napi from '../../../utils/axiosnapi';

type MainAcct = { id:number; main_acct:string; main_acct_code:string; created_at?:string; updated_at?:string; };

type PagePayload<T> = {
  data: T[]; current_page: number; per_page: number; total: number; last_page: number; from: number|null; to: number|null;
};

const PER_PAGE = 10;

const MainAccountsWindow: React.FC<{ onSaved?: () => void }> = ({ onSaved }) => {
  const [rows, setRows] = useState<MainAcct[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [from, setFrom] = useState<number|null>(null);
  const [to, setTo] = useState<number|null>(null);
  const [loading, setLoading] = useState(false);

  const [editingId, setEditingId] = useState<number|null>(null);
  const [main_acct, setMainAcct] = useState('');
  const [main_acct_code, setMainAcctCode] = useState('');

  const companyHeader = useMemo(() => {
    const cid =
      localStorage.getItem('company_id') ??
      JSON.parse(localStorage.getItem('auth') || '{}')?.company_id ?? '';
    return { 'X-Company-ID': cid || '' };
  }, []);

  const load = async (opts?: { page?: number; q?: string }) => {
    setLoading(true);
    try {
      const nextPage = opts?.page ?? page;
      const q = opts?.q ?? search;
      const res = await napi.get('/references/account-main', {
        params: { search: q, page: nextPage, per_page: PER_PAGE },
        headers: companyHeader,
      });
      const payload: PagePayload<MainAcct> = res.data;
      setRows(payload.data ?? []);
      setTotal(payload.total ?? 0);
      setLastPage(payload.last_page ?? 1);
      setFrom(payload.from ?? null);
      setTo(payload.to ?? null);
      setPage(payload.current_page ?? nextPage);
    } finally { setLoading(false); }
  };

  useEffect(() => { load({ page: 1 }); /* eslint-disable */ }, []);

  const resetForm = () => { setEditingId(null); setMainAcct(''); setMainAcctCode(''); };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setLoading(true);
    try {
      const payload = { main_acct: main_acct.trim(), main_acct_code: main_acct_code.trim() };
      if (editingId) {
        await napi.put(`/references/account-main/${editingId}`, payload, { headers: companyHeader });
      } else {
        await napi.post('/references/account-main', payload, { headers: companyHeader });
      }
      resetForm();
      await load({ page });
      onSaved?.();
    } catch (err:any) {
      alert(err?.response?.data?.message ?? 'Save failed');
    } finally { setLoading(false); }
  };

  const onEdit = (r: MainAcct) => {
    setEditingId(r.id);
    setMainAcct(r.main_acct || '');
    setMainAcctCode(r.main_acct_code || '');
  };

  const onDelete = async (id:number) => {
    if (!confirm('Delete this main account?')) return;
    setLoading(true);
    try {
      await napi.delete(`/references/account-main/${id}`, { headers: companyHeader });
      const nextPage = rows.length === 1 && page > 1 ? page - 1 : page;
      await load({ page: nextPage });
      onSaved?.();
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Delete failed'); }
    finally { setLoading(false); }
  };

  return (
    <div className="p-4">
      {/* Search */}
      <div className="flex items-center gap-2 mb-3">
        <input
          value={search}
          onChange={(e)=>setSearch(e.target.value)}
          placeholder="Search main accounts..."
          className="border rounded px-3 py-2 text-sm w-[24rem]"
        />
        <button onClick={()=>load({page:1, q:search})} className="px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-500">
          Search
        </button>
        {loading && <span className="text-xs text-gray-500">Loading…</span>}
      </div>

      {/* Form */}
      <form onSubmit={onSubmit} className="grid grid-cols-12 gap-3 mb-4 text-sm">
        <input required value={main_acct} onChange={(e)=>setMainAcct(e.target.value)} placeholder="Main Account *" className="border rounded px-3 py-2 col-span-6"/>
        <input required value={main_acct_code} onChange={(e)=>setMainAcctCode(e.target.value)} placeholder="Main Account Code *" className="border rounded px-3 py-2 col-span-4"/>
        <div className="col-span-2 flex items-center justify-end">
          <button type="submit" className="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-500">
            {editingId ? 'Update' : 'Add'}
          </button>
        </div>
      </form>

      {/* Grid */}
      <div className="border rounded overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-100 sticky top-0">
            <tr className="text-left">
              <th className="p-2">Main Account</th>
              <th className="p-2">Code</th>
              <th className="p-2 w-40">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r)=>(
              <tr key={r.id} className="border-t">
                <td className="p-2">{r.main_acct}</td>
                <td className="p-2">{r.main_acct_code}</td>
                <td className="p-2">
                  <div className="flex gap-2">
                    <button onClick={()=>onEdit(r)} className="px-2 py-1 text-blue-600 hover:underline">Edit</button>
                    <button onClick={()=>onDelete(r.id)} className="px-2 py-1 text-red-600 hover:underline">Delete</button>
                  </div>
                </td>
              </tr>
            ))}
            {rows.length===0 && (<tr><td colSpan={3} className="p-4 text-center text-gray-500">No records</td></tr>)}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="mt-3 flex items-center justify-between text-sm">
        <div className="text-gray-600">
          {total>0 ? <>Showing {from ?? 0}–{to ?? 0} of {total}</> : <>No results</>}
        </div>
        <div className="flex items-center gap-1">
          <button onClick={()=>load({page:1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>First</button>
          <button onClick={()=>load({page:page-1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>Prev</button>
          <span className="px-2">{page} / {lastPage}</span>
          <button onClick={()=>load({page:page+1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Next</button>
          <button onClick={()=>load({page:lastPage})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Last</button>
        </div>
      </div>
    </div>
  );
};

export default MainAccountsWindow;
