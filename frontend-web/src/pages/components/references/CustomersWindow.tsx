// frontend-web/src/modules/references/CustomersWindow.tsx
import React, { useEffect, useState } from 'react';
import napi from '../../../utils/axiosnapi';

type Customer = {
  id: number;
  cust_id: string;
  cust_name: string;
  company_id?: string | null;
  workstation_id?: string | null;
  user_id?: string | null;
  created_at?: string;
  updated_at?: string;
};

const emptyCustomer: Omit<Customer, 'id'> = {
  cust_id: '',
  cust_name: '',
  company_id: undefined,
  workstation_id: undefined,
  user_id: undefined,
};

type PagePayload<T> = {
  data: T[]; current_page: number; per_page: number; total: number; last_page: number; from: number|null; to: number|null;
};

const PER_PAGE = 10;

const CustomersWindow: React.FC = () => {
  const [rows, setRows] = useState<Customer[]>([]);
  const [search, setSearch] = useState('');
  const [form, setForm] = useState<Omit<Customer,'id'>>({...emptyCustomer});
  const [editingId, setEditingId] = useState<number|null>(null);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [from, setFrom] = useState<number|null>(null);
  const [to, setTo] = useState<number|null>(null);


    const companyHeader = () => {
    const cid =
        localStorage.getItem('company_id') ??
        JSON.parse(localStorage.getItem('auth') || '{}')?.company_id ?? '';
    return { 'X-Company-ID': cid || '' };
    };



  const load = async (opts?: {page?: number; q?: string}) => {
    setLoading(true);
    try {
      const nextPage = opts?.page ?? page;
      const q = opts?.q ?? search;
      
      //const res = await napi.get('/references/customers', { params: { search: q, page: nextPage, per_page: PER_PAGE }});
       const res = await napi.get('/references/customers', {
         params: { search: q, page: nextPage, per_page: PER_PAGE },
         headers: companyHeader(),              // ← add this
       });
      
      const payload: PagePayload<Customer> | Customer[] = res.data;

      if (Array.isArray(payload)) {
        setRows(payload); setTotal(payload.length); setLastPage(1); setFrom(payload.length?1:0); setTo(payload.length);
      } else {
        setRows(payload.data ?? []); setTotal(payload.total ?? 0); setLastPage(payload.last_page ?? 1);
        setFrom(payload.from ?? null); setTo(payload.to ?? null); setPage(payload.current_page ?? nextPage);
      }
    } finally { setLoading(false); }
  };

  useEffect(() => { load({page:1}); /* eslint-disable */ }, []);

  const onSearch = async () => { setPage(1); await load({page:1, q:search}); };

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault(); setLoading(true);
    try {
      const payload = { cust_id:(form.cust_id||'').toUpperCase(), cust_name:form.cust_name };
      const companyId =
        localStorage.getItem('company_id') ??
        JSON.parse(localStorage.getItem('auth') || '{}')?.company_id ?? '';

      if (editingId) {
        await napi.put(`/references/customers/${editingId}`, payload, { headers: { 'X-Company-ID': companyId }});
      } else {
        await napi.post('/references/customers', payload, { headers: { 'X-Company-ID': companyId }});
      }
      setForm({...emptyCustomer}); setEditingId(null); await load({page});
    } catch (err:any) {
      alert(err?.response?.data?.message ?? 'Save failed');
    } finally { setLoading(false); }
  };

  const onEdit = (c: Customer) => { const {id, ...rest} = c; setEditingId(id); setForm({...emptyCustomer, ...rest}); };
  const onDelete = async (id:number) => {
    if (!confirm('Delete this customer?')) return;
    setLoading(true);
    try {
      await napi.delete(`/references/customers/${id}`);
      const nextPage = rows.length === 1 && page > 1 ? page - 1 : page;
      await load({page: nextPage});
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Delete failed'); } finally { setLoading(false); }
  };

  const goFirst = async () => { if (page>1) await load({page:1}); };
  const goPrev  = async () => { if (page>1) await load({page:page-1}); };
  const goNext  = async () => { if (page<lastPage) await load({page:page+1}); };
  const goLast  = async () => { if (page<lastPage) await load({page:lastPage}); };

  const downloadExport = async (fmt:'xls'|'xlsx') => {
    try {
      //const res = await napi.get('/references/customers/export', { params:{format:fmt, search}, responseType:'blob' });

      const res = await napi.get('/references/customers/export', {
        params: { format: fmt, search },
        headers: companyHeader(),              // ← add this
        responseType: 'blob',
       });
      
      
      const stamp = new Date().toISOString().replace(/[-:T]/g,'').slice(0,15);
      const filename = `customers_${stamp}.${fmt}`;
      const url = URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a'); a.href = url; a.download = filename; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Export failed'); }
  };

  return (
    <div className="p-4">
      <div className="flex items-center gap-2 mb-3">
        <input value={search} onChange={(e)=>setSearch(e.target.value)} placeholder="Search customers (ID, Name, Workstation)…" className="border rounded px-3 py-2 text-sm w-[36rem] max-w-[60vw]"/>
        <button onClick={onSearch} className="px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-500">Search</button>

        <div className="relative inline-block text-left">
          <button className="px-3 py-2 rounded bg-gray-700 text-white text-sm hover:bg-gray-600" onClick={()=>{
            const el=document.getElementById('print-menu-customers'); if(el) el.classList.toggle('hidden');
          }}>Print</button>
          <div id="print-menu-customers" className="hidden absolute z-10 mt-1 w-32 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black/5"
               onMouseLeave={()=>{ const el=document.getElementById('print-menu-customers'); if(el) el.classList.add('hidden'); }}>
            <button onClick={()=>{downloadExport('xlsx'); const el=document.getElementById('print-menu-customers'); if(el) el.classList.add('hidden');}}
                    className="w-full text-left px-3 py-2 hover:bg-gray-100 text-sm">Export .xlsx</button>
            <button onClick={()=>{downloadExport('xls'); const el=document.getElementById('print-menu-customers'); if(el) el.classList.add('hidden');}}
                    className="w-full text-left px-3 py-2 hover:bg-gray-100 text-sm">Export .xls</button>
          </div>
        </div>
        {loading && <span className="text-xs text-gray-500">Loading…</span>}
      </div>

      <form onSubmit={onSubmit} className="grid grid-cols-6 gap-3 mb-4 text-sm">
        <input required value={form.cust_id} onChange={(e)=>setForm({...form, cust_id:e.target.value.toUpperCase()})} placeholder="Customer ID *" className="border rounded px-3 py-2 col-span-2"/>
        <input required value={form.cust_name} onChange={(e)=>setForm({...form, cust_name:e.target.value})} placeholder="Customer Name *" className="border rounded px-3 py-2 col-span-3"/>
        <div className="col-span-1 flex items-center justify-end">
          <button type="submit" className="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-500">{editingId ? 'Update' : 'Add'}</button>
        </div>
      </form>

      <div className="border rounded overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-100 sticky top-0">
            <tr className="text-left">
              <th className="p-2">Customer ID</th>
              <th className="p-2">Name</th>
              <th className="p-2">Workstation</th>
              <th className="p-2 w-40">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((c)=>(
              <tr key={c.id} className="border-t">
                <td className="p-2">{c.cust_id}</td>
                <td className="p-2">{c.cust_name}</td>
                <td className="p-2">{c.workstation_id ?? ''}</td>
                <td className="p-2">
                  <div className="flex gap-2">
                    <button onClick={()=>onEdit(c)} className="px-2 py-1 text-blue-600 hover:underline">Edit</button>
                    <button onClick={()=>onDelete(c.id)} className="px-2 py-1 text-red-600 hover:underline">Delete</button>
                  </div>
                </td>
              </tr>
            ))}
            {rows.length===0 && (<tr><td colSpan={4} className="p-4 text-center text-gray-500">No records</td></tr>)}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex items-center justify-between text-sm">
        <div className="text-gray-600">{total>0 ? <>Showing {from ?? 0}–{to ?? 0} of {total}</> : <>No results</>}</div>
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
export default CustomersWindow;
