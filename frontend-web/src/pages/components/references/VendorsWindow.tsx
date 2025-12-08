// frontend-web/src/pages/components/references/VendorsWindow.tsx
import React, { useEffect, useState } from 'react';
import napi from '../../../utils/axiosnapi';

type Vendor = {
  id: number;
  vend_code: string;
  vend_name: string;
  company_id?: number | null;
  vendor_tin?: string | null;
  vendor_address?: string | null;
  vatable?: string | null;          // 'YES' | 'NO' or similar
  workstation_id?: string | null;
  user_id?: number | null;
  created_at?: string;
  updated_at?: string;
};

type PagePayload<T> = {
  data: T[]; current_page: number; per_page: number; total: number; last_page: number; from: number|null; to: number|null;
};

const PER_PAGE = 10;

const VendorsWindow: React.FC = () => {
  const [rows, setRows] = useState<Vendor[]>([]);
  const [search, setSearch] = useState('');
  const [editingId, setEditingId] = useState<number|null>(null);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [from, setFrom] = useState<number|null>(null);
  const [to, setTo] = useState<number|null>(null);

  const [vend_code, setVendCode] = useState('');
  const [vend_name, setVendName] = useState('');
  const [vendor_tin, setTin] = useState('');
  const [vendor_address, setAddress] = useState('');
  const [vatable, setVatable] = useState<'YES' | 'NO' | ''>('');

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
      const res = await napi.get('/references/vendors', {
        params: { search: q, page: nextPage, per_page: PER_PAGE },
        headers: companyHeader(),
      });
      const payload: PagePayload<Vendor> | Vendor[] = res.data;
      if (Array.isArray(payload)) {
        setRows(payload); setTotal(payload.length); setLastPage(1);
        setFrom(payload.length ? 1 : 0); setTo(payload.length);
      } else {
        setRows(payload.data ?? []); setTotal(payload.total ?? 0); setLastPage(payload.last_page ?? 1);
        setFrom(payload.from ?? null); setTo(payload.to ?? null); setPage(payload.current_page ?? nextPage);
      }
    } finally { setLoading(false); }
  };

  useEffect(() => { load({ page: 1 }); /* eslint-disable react-hooks/exhaustive-deps */ }, []);

  const onSearch = async () => { setPage(1); await load({ page: 1, q: search }); };

  const resetForm = () => {
    setEditingId(null); setVendCode(''); setVendName(''); setTin(''); setAddress(''); setVatable('');
  };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setLoading(true);
    try {
      const payload = {
        vend_code: (vend_code || '').toUpperCase(),
        vend_name: vend_name,
        vendor_tin: vendor_tin || '',
        vendor_address: vendor_address || '',
        vatable: vatable || '',
      };
      const headers = companyHeader();
      if (editingId) {
        await napi.put(`/references/vendors/${editingId}`, payload, { headers });
      } else {
        await napi.post('/references/vendors', payload, { headers });
      }
      resetForm();
      await load({ page });
    } catch (err:any) {
      alert(err?.response?.data?.message ?? 'Save failed');
    } finally { setLoading(false); }
  };

  const onEdit = (v: Vendor) => {
    setEditingId(v.id);
    setVendCode(v.vend_code || '');
    setVendName(v.vend_name || '');
    setTin(v.vendor_tin || '');
    setAddress(v.vendor_address || '');
    setVatable((v.vatable as any) || '');
  };

  const onDelete = async (id: number) => {
    if (!confirm('Delete this vendor?')) return;
    setLoading(true);
    try {
      await napi.delete(`/references/vendors/${id}`, { headers: companyHeader() });
      const nextPage = rows.length === 1 && page > 1 ? page - 1 : page;
      await load({ page: nextPage });
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Delete failed'); }
    finally { setLoading(false); }
  };

  const goFirst = async () => { if (page > 1) await load({ page: 1 }); };
  const goPrev  = async () => { if (page > 1) await load({ page: page - 1 }); };
  const goNext  = async () => { if (page < lastPage) await load({ page: page + 1 }); };
  const goLast  = async () => { if (page < lastPage) await load({ page: lastPage }); };

  const downloadExport = async (fmt: 'xls' | 'xlsx') => {
    try {
      const res = await napi.get('/references/vendors/export', {
        params: { format: fmt, search },
        headers: companyHeader(),
        responseType: 'blob',
      });
      const stamp = new Date().toISOString().replace(/[-:T]/g,'').slice(0,15);
      const filename = `vendors_${stamp}.${fmt}`;
      const url = URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a'); a.href = url; a.download = filename;
      document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Export failed'); }
  };

  return (
    <div className="p-4">
      {/* Search + Print */}
      <div className="flex items-center gap-2 mb-3">
        <input
          value={search}
          onChange={(e)=>setSearch(e.target.value)}
          placeholder="Search vendors (Code, Name, TIN, Address, Workstation)…"
          className="border rounded px-3 py-2 text-sm w-[36rem] max-w-[60vw]"
        />
        <button onClick={onSearch} className="px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-500">
          Search
        </button>

        <div className="relative inline-block text-left">
          <button
            className="px-3 py-2 rounded bg-gray-700 text-white text-sm hover:bg-gray-600"
            onClick={() => { const el=document.getElementById('print-menu-vendors'); if(el) el.classList.toggle('hidden'); }}
          >
            Print
          </button>
          <div
            id="print-menu-vendors"
            className="hidden absolute z-10 mt-1 w-32 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black/5"
            onMouseLeave={() => { const el=document.getElementById('print-menu-vendors'); if(el) el.classList.add('hidden'); }}
          >
            <button onClick={() => { downloadExport('xlsx'); const el=document.getElementById('print-menu-vendors'); if(el) el.classList.add('hidden'); }}
                    className="w-full text-left px-3 py-2 hover:bg-gray-100 text-sm">Export .xlsx</button>
            <button onClick={() => { downloadExport('xls'); const el=document.getElementById('print-menu-vendors'); if(el) el.classList.add('hidden'); }}
                    className="w-full text-left px-3 py-2 hover:bg-gray-100 text-sm">Export .xls</button>
          </div>
        </div>
        {loading && <span className="text-xs text-gray-500">Loading…</span>}
      </div>

      {/* Form */}
      <form onSubmit={onSubmit} className="grid grid-cols-12 gap-3 mb-4 text-sm">
        <input required value={vend_code} onChange={(e)=>setVendCode(e.target.value.toUpperCase())} placeholder="Vendor Code *" className="border rounded px-3 py-2 col-span-2"/>
        <input required value={vend_name} onChange={(e)=>setVendName(e.target.value)} placeholder="Vendor Name *" className="border rounded px-3 py-2 col-span-4"/>
        <input value={vendor_tin} onChange={(e)=>setTin(e.target.value)} placeholder="TIN" className="border rounded px-3 py-2 col-span-2"/>
        <select value={vatable} onChange={(e)=>setVatable(e.target.value as any)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Vatable?</option>
          <option value="YES">YES</option>
          <option value="NO">NO</option>
        </select>
        <div className="col-span-2 flex items-center justify-end">
          <button type="submit" className="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-500">
            {editingId ? 'Update' : 'Add'}
          </button>
        </div>
        <input value={vendor_address} onChange={(e)=>setAddress(e.target.value)} placeholder="Address" className="border rounded px-3 py-2 col-span-12"/>
      </form>

      {/* Grid */}
      <div className="border rounded overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-100 sticky top-0">
            <tr className="text-left">
              <th className="p-2">Vendor Code</th>
              <th className="p-2">Name</th>
              <th className="p-2">TIN</th>
              <th className="p-2">Address</th>
              <th className="p-2">Vatable</th>
              <th className="p-2">Workstation</th>
              <th className="p-2 w-40">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((v)=>(
              <tr key={v.id} className="border-t">
                <td className="p-2">{v.vend_code}</td>
                <td className="p-2">{v.vend_name}</td>
                <td className="p-2">{v.vendor_tin}</td>
                <td className="p-2">{v.vendor_address}</td>
                <td className="p-2">{v.vatable}</td>
                <td className="p-2">{v.workstation_id}</td>
                <td className="p-2">
                  <div className="flex gap-2">
                    <button onClick={()=>onEdit(v)} className="px-2 py-1 text-blue-600 hover:underline">Edit</button>
                    <button onClick={()=>onDelete(v.id)} className="px-2 py-1 text-red-600 hover:underline">Delete</button>
                  </div>
                </td>
              </tr>
            ))}
            {rows.length===0 && (<tr><td colSpan={7} className="p-4 text-center text-gray-500">No records</td></tr>)}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      <div className="mt-3 flex items-center justify-between text-sm">
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
  );
};

export default VendorsWindow;
