// frontend-web/src/pages/components/references/AccountsWindow.tsx
import React, { useEffect, useMemo, useState } from 'react';
import napi from '../../../utils/axiosnapi';
import FloatingWindow from '../ui/FloatingWindow';
import MainAccountsWindow from './MainAccountsWindow';

type Account = {
  id: number;
  acct_number: number;
  main_acct: string;
  main_acct_code: string;
  acct_code: string;
  acct_desc: string;
  fs?: string | null;
  acct_group?: string | null;
  acct_group_sub1?: string | null;
  acct_group_sub2?: string | null;
  normal_bal?: string | null;
  acct_type?: string | null;
  cash_disbursement_flag?: string | null; // '1' or ''
  bank_id?: string | null;                // or numeric id if you’ve aligned it
  vessel_flag?: string | null;            // '1' or ''
  booking_no?: string | null;
  ap_ar?: string | null;
  active_flag?: number;                   // 0/1
  exclude?: number;                       // 0/1
  created_at?: string;
  updated_at?: string;
};

type PagePayload<T> = {
  data: T[]; current_page: number; per_page: number; total: number; last_page: number; from: number|null; to: number|null;
};

type Meta = {
  fs: string[];
  acct_group: string[];
  acct_group_sub1: string[];
  acct_group_sub2: string[];
  normal_bal: string[];
  acct_type: string[];
};

const PER_PAGE = 10;

const AccountsWindow: React.FC = () => {
  const [rows, setRows] = useState<Account[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [from, setFrom] = useState<number|null>(null);
  const [to, setTo] = useState<number|null>(null);
  const [loading, setLoading] = useState(false);

  // form state
  const [editingId, setEditingId] = useState<number|null>(null);
  const [main_acct, setMainAcct] = useState('');
  const [main_acct_code, setMainAcctCode] = useState('');
  const [acct_code, setAcctCode] = useState(''); // can show suggestion; server is final authority
  const [acct_desc, setAcctDesc] = useState('');
  const [fs, setFs] = useState('');
  const [acct_group, setAcctGroup] = useState('');
  const [acct_group_sub1, setAcctGroupSub1] = useState('');
  const [acct_group_sub2, setAcctGroupSub2] = useState('');
  const [normal_bal, setNormalBal] = useState('');
  const [acct_type, setAcctType] = useState('');
  const [cash_disbursement_flag, setCDF] = useState(false); // toggle → '1' or ''
  const [vessel_flag, setVF] = useState(false);
  const [active_flag, setActive] = useState(true);
  const [exclude, setExclude] = useState(false);
  const [bank_id, setBankId] = useState(''); // optional
  const [booking_no, setBookingNo] = useState('');
  const [ap_ar, setApAr] = useState('');

  // meta (distincts)
  const [meta, setMeta] = useState<Meta>({
    fs: [], acct_group: [], acct_group_sub1: [], acct_group_sub2: [], normal_bal: [], acct_type: [],
  });

  // account_main lookup (simple client cache)
  const [mainOptions, setMainOptions] = useState<Array<{id:number; main_acct:string; main_acct_code:string}>>([]);
  const [mainSearch, setMainSearch] = useState('');
  const [showMainManager, setShowMainManager] = useState(false);

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
      const res = await napi.get('/references/accounts', {
        params: { search: q, page: nextPage, per_page: PER_PAGE },
        headers: companyHeader,
      });
      const payload: PagePayload<Account> | Account[] = res.data;
      if (Array.isArray(payload)) {
        setRows(payload); setTotal(payload.length); setLastPage(1);
        setFrom(payload.length ? 1 : 0); setTo(payload.length);
      } else {
        setRows(payload.data ?? []); setTotal(payload.total ?? 0); setLastPage(payload.last_page ?? 1);
        setFrom(payload.from ?? null); setTo(payload.to ?? null); setPage(payload.current_page ?? nextPage);
      }
    } finally { setLoading(false); }
  };

  const loadMeta = async () => {
    try {
      const res = await napi.get('/references/accounts/meta', { headers: companyHeader });
      setMeta(res.data || meta);
    } catch {}
  };

  const lookupMain = async (term: string) => {
    const res = await napi.get('/references/account-main', {
      params: { search: term, page: 1, per_page: 10 },
      headers: companyHeader,
    });
    const payload: PagePayload<any> = res.data;
    setMainOptions(payload?.data?.map((r:any)=>({ id:r.id, main_acct:r.main_acct, main_acct_code:r.main_acct_code })) ?? []);
  };

  const fetchNextCode = async (mac: string) => {
    if (!mac) return;
    try {
      const res = await napi.get('/references/accounts/next-code', {
        params: { main_acct_code: mac },
        headers: companyHeader,
      });
      setAcctCode(res.data?.next_code || '');
    } catch { setAcctCode(''); }
  };

  useEffect(() => { load({ page: 1 }); /* eslint-disable */ }, []);
  useEffect(() => { loadMeta(); /* eslint-disable */ }, []);
  useEffect(() => { lookupMain(mainSearch); /* eslint-disable */ }, [mainSearch]);

  const onSearch = async () => { setPage(1); await load({ page: 1, q: search }); };

  const resetForm = () => {
    setEditingId(null);
    setMainAcct(''); setMainAcctCode('');
    setAcctCode(''); setAcctDesc('');
    setFs(''); setAcctGroup(''); setAcctGroupSub1(''); setAcctGroupSub2('');
    setNormalBal(''); setAcctType('');
    setCDF(false); setVF(false); setActive(true); setExclude(false);
    setBankId(''); setBookingNo(''); setApAr('');
  };


 // ===== [ACCOUNTS_HANDLERS_CREATE_UPDATE_START] =====
const handleCreate = async () => {
  setLoading(true);
  try {
    const payload = {
      main_acct,
      main_acct_code,
      acct_code, // server can validate/override
      acct_desc,
      fs: fs || null,
      acct_group: acct_group || null,
      acct_group_sub1: acct_group_sub1 || null,
      acct_group_sub2: acct_group_sub2 || null,
      normal_bal: normal_bal || null,
      acct_type: acct_type || null,
      cash_disbursement_flag: cash_disbursement_flag ? '1' : '',
      vessel_flag: vessel_flag ? '1' : '',
      active_flag: active_flag ? 1 : 0,
      exclude: exclude ? 1 : 0,
      bank_id: bank_id || null,
      booking_no: booking_no || null,
      ap_ar: ap_ar || null,
    };

    await napi.post('/references/accounts', payload, { headers: companyHeader });
    resetForm();
    await load({ page: 1 }); // show newest on first page
  } catch (err:any) {
    alert(err?.response?.data?.message ?? 'Add failed');
  } finally {
    setLoading(false);
  }
};

const handleUpdate = async () => {
  if (!editingId) return;
  setLoading(true);
  try {
    const payload = {
      main_acct,
      main_acct_code,
      acct_code,
      acct_desc,
      fs: fs || null,
      acct_group: acct_group || null,
      acct_group_sub1: acct_group_sub1 || null,
      acct_group_sub2: acct_group_sub2 || null,
      normal_bal: normal_bal || null,
      acct_type: acct_type || null,
      cash_disbursement_flag: cash_disbursement_flag ? '1' : '',
      vessel_flag: vessel_flag ? '1' : '',
      active_flag: active_flag ? 1 : 0,
      exclude: exclude ? 1 : 0,
      bank_id: bank_id || null,
      booking_no: booking_no || null,
      ap_ar: ap_ar || null,
    };

    await napi.put(`/references/accounts/${editingId}`, payload, { headers: companyHeader });
    resetForm();
    await load({ page }); // keep current page
  } catch (err:any) {
    alert(err?.response?.data?.message ?? 'Update failed');
  } finally {
    setLoading(false);
  }
};
// ===== [ACCOUNTS_HANDLERS_CREATE_UPDATE_END] =====
 





// ===== [ACCOUNTS_ONSUBMIT_DELEGATE_START] =====
const onSubmit = async (e: React.FormEvent) => {
  e.preventDefault();
  if (editingId) return handleUpdate();
  return handleCreate();
};
// ===== [ACCOUNTS_ONSUBMIT_DELEGATE_END] =====


  const onEdit = (r: Account) => {
    setEditingId(r.id);
    setMainAcct(r.main_acct || '');
    setMainAcctCode(r.main_acct_code || '');
    setAcctCode(r.acct_code || '');
    setAcctDesc(r.acct_desc || '');
    setFs(r.fs || '');
    setAcctGroup(r.acct_group || '');
    setAcctGroupSub1(r.acct_group_sub1 || '');
    setAcctGroupSub2(r.acct_group_sub2 || '');
    setNormalBal(r.normal_bal || '');
    setAcctType(r.acct_type || '');
    setCDF((r.cash_disbursement_flag || '') === '1');
    setVF((r.vessel_flag || '') === '1');
    setActive((r.active_flag ?? 1) === 1);
    setExclude((r.exclude ?? 0) === 1);
    setBankId(r.bank_id || '');
    setBookingNo(r.booking_no || '');
    setApAr(r.ap_ar || '');
  };

  const onDelete = async (id: number) => {
    if (!confirm('Delete this account?')) return;
    setLoading(true);
    try {
      await napi.delete(`/references/accounts/${id}`, { headers: companyHeader });
      const nextPage = rows.length === 1 && page > 1 ? page - 1 : page;
      await load({ page: nextPage });
    } catch (e:any) { alert(e?.response?.data?.message ?? 'Delete failed'); }
    finally { setLoading(false); }
  };

  const selectMain = async (opt: {main_acct:string; main_acct_code:string}) => {
    setMainAcct(opt.main_acct);
    setMainAcctCode(opt.main_acct_code);
    await fetchNextCode(opt.main_acct_code);
  };

  return (
    <div className="p-4">
      {/* Search */}
      <div className="flex items-center gap-2 mb-3">
        <input
          value={search}
          onChange={(e)=>setSearch(e.target.value)}
          placeholder="Search accounts (code, desc, main acct, type...)"
          className="border rounded px-3 py-2 text-sm w-[36rem] max-w-[60vw]"
        />
        <button onClick={onSearch} className="px-3 py-2 rounded bg-blue-600 text-white text-sm hover:bg-blue-500">
          Search
        </button>
        {loading && <span className="text-xs text-gray-500">Loading…</span>}
      </div>

      {/* Form */}
      <form onSubmit={onSubmit} className="grid grid-cols-12 gap-3 mb-4 text-sm">
        {/* Main account select + quick manage */}
        <div className="col-span-4">
          <div className="flex gap-2">
            <input
              value={mainSearch}
              onChange={(e)=>setMainSearch(e.target.value)}
              placeholder="Search Main Account…"
              className="border rounded px-3 py-2 w-full"
            />
            <button type="button" onClick={()=>setShowMainManager(true)} className="px-3 py-2 bg-gray-700 text-white rounded">
              Manage
            </button>
          </div>
          {/* Simple dropdown list */}
          {mainSearch && mainOptions.length>0 && (
            <div className="mt-1 border rounded max-h-40 overflow-auto bg-white shadow">
              {mainOptions.map(o=>(
                <button key={o.id} type="button" onClick={()=>selectMain(o)} className="block w-full text-left px-3 py-1 hover:bg-gray-100">
                  {o.main_acct} <span className="text-gray-500">({o.main_acct_code})</span>
                </button>
              ))}
            </div>
          )}
        </div>

<input
  value={main_acct}
  onChange={(e)=>setMainAcct(e.target.value.toUpperCase())}
  placeholder="Main Account"
  className="border rounded px-3 py-2 col-span-3"
/>

<input
  value={main_acct_code}
  onChange={(e)=>{ setMainAcctCode(e.target.value); }}
  onBlur={() => fetchNextCode(main_acct_code)}
  placeholder="Main Account Code"
  className="border rounded px-3 py-2 col-span-2"
/>

        <div className="col-span-3 flex gap-2">
          <input value={acct_code} onChange={(e)=>setAcctCode(e.target.value)} placeholder="Acct Code" className="border rounded px-3 py-2 w-full"/>
          <button type="button" onClick={()=>fetchNextCode(main_acct_code)} className="px-3 py-2 bg-indigo-600 text-white rounded">Suggest</button>
        </div>

        <input required value={acct_desc} onChange={(e)=>setAcctDesc(e.target.value)} placeholder="Account Description *" className="border rounded px-3 py-2 col-span-6"/>

        <select value={fs} onChange={(e)=>setFs(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">FS</option>
          {meta.fs.map(x=><option key={x} value={x}>{x}</option>)}
        </select>
        <select value={acct_group} onChange={(e)=>setAcctGroup(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Group</option>
          {meta.acct_group.map(x=><option key={x} value={x}>{x}</option>)}
        </select>
        <select value={acct_group_sub1} onChange={(e)=>setAcctGroupSub1(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Sub-group 1</option>
          {meta.acct_group_sub1.map(x=><option key={x} value={x}>{x}</option>)}
        </select>
        <select value={acct_group_sub2} onChange={(e)=>setAcctGroupSub2(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Sub-group 2</option>
          {meta.acct_group_sub2.map(x=><option key={x} value={x}>{x}</option>)}
        </select>
        <select value={normal_bal} onChange={(e)=>setNormalBal(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Normal Bal</option>
          {meta.normal_bal.map(x=><option key={x} value={x}>{x}</option>)}
        </select>
        <select value={acct_type} onChange={(e)=>setAcctType(e.target.value)} className="border rounded px-3 py-2 col-span-2">
          <option value="">Acct Type</option>
          {meta.acct_type.map(x=><option key={x} value={x}>{x}</option>)}
        </select>

        <input value={bank_id} onChange={(e)=>setBankId(e.target.value)} placeholder="Bank ID (optional)" className="border rounded px-3 py-2 col-span-2"/>
        <input value={booking_no} onChange={(e)=>setBookingNo(e.target.value)} placeholder="Booking No" className="border rounded px-3 py-2 col-span-2"/>
        <input value={ap_ar} onChange={(e)=>setApAr(e.target.value)} placeholder="AP/AR" className="border rounded px-3 py-2 col-span-2"/>

        <div className="col-span-6 flex flex-wrap items-center gap-4">
          <label className="flex items-center gap-2"><input type="checkbox" checked={cash_disbursement_flag} onChange={(e)=>setCDF(e.target.checked)} /> Cash Disb Flag</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={vessel_flag} onChange={(e)=>setVF(e.target.checked)} /> Vessel Flag</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={active_flag} onChange={(e)=>setActive(e.target.checked)} /> Active</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={exclude} onChange={(e)=>setExclude(e.target.checked)} /> Exclude</label>
        </div>

<div className="col-span-6 flex items-center justify-end gap-2">
  {/* Show New when editing to exit edit mode quickly */}
  {editingId && (
    <button
      type="button"
      onClick={resetForm}
      className="px-3 py-2 bg-gray-200 rounded"
      title="Exit edit mode and prepare a new record"
    >
      New
    </button>
  )}

  <button
    type="button"
    onClick={() => {
      setMainAcct(''); setMainAcctCode('');
      setAcctCode(''); setAcctDesc('');
      setFs(''); setAcctGroup(''); setAcctGroupSub1(''); setAcctGroupSub2('');
      setNormalBal(''); setAcctType('');
      setCDF(false); setVF(false); setActive(true); setExclude(false);
      setBankId(''); setBookingNo(''); setApAr('');
    }}
    className="px-3 py-2 bg-gray-200 rounded"
  >
    Clear
  </button>

  {!editingId ? (
    <button
      type="submit"
      className="px-3 py-2 bg-green-600 text-white rounded"
      disabled={loading || !acct_desc || !main_acct_code || !main_acct}
      title={!acct_desc || !main_acct || !main_acct_code ? 'Main Account, Main Code, and Description are required' : ''}
    >
      Add
    </button>
  ) : (
    <button
      type="submit"
      className="px-3 py-2 bg-blue-600 text-white rounded"
      disabled={loading || !acct_desc || !main_acct_code || !main_acct}
    >
      Update
    </button>
  )}
</div>



      </form>

      {/* Grid */}
      <div className="border rounded overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-100 sticky top-0">
            <tr className="text-left">
              <th className="p-2">Acct #</th>
              <th className="p-2">Acct Code</th>
              <th className="p-2">Description</th>
              <th className="p-2">Main Account</th>
              <th className="p-2">Main Code</th>
              <th className="p-2">Type</th>
              <th className="p-2 w-40">Actions</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r)=>(
              <tr key={r.id} className="border-t">
                <td className="p-2">{r.acct_number}</td>
                <td className="p-2">{r.acct_code}</td>
                <td className="p-2">{r.acct_desc}</td>
                <td className="p-2">{r.main_acct}</td>
                <td className="p-2">{r.main_acct_code}</td>
                <td className="p-2">{r.acct_type}</td>
                <td className="p-2">
                  <div className="flex gap-2">
                    <button onClick={()=>onEdit(r)} className="px-2 py-1 text-blue-600 hover:underline">Edit</button>
                    <button onClick={()=>onDelete(r.id)} className="px-2 py-1 text-red-600 hover:underline">Delete</button>
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
          <button onClick={()=>load({page:1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>First</button>
          <button onClick={()=>load({page:page-1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page<=1}>Prev</button>
          <span className="px-2">{page} / {lastPage}</span>
          <button onClick={()=>load({page:page+1})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Next</button>
          <button onClick={()=>load({page:lastPage})} className="px-2 py-1 border rounded disabled:opacity-40" disabled={page>=lastPage}>Last</button>
        </div>
      </div>

      {/* MAIN ACCOUNTS MANAGER (Floating inside this window) */}
      {showMainManager && (
        <FloatingWindow
          title="Main Accounts"
          defaultWidth={640}
          defaultHeight={460}
          onClose={async ()=>{ setShowMainManager(false); await lookupMain(mainSearch); }}
        >
          <MainAccountsWindow onSaved={async ()=>{
            // when a new main acct is saved, refresh lookup list and suggest code
            await lookupMain(mainSearch);
            if (main_acct_code) await fetchNextCode(main_acct_code);
          }} />
        </FloatingWindow>
      )}
    </div>
  );
};

export default AccountsWindow;
