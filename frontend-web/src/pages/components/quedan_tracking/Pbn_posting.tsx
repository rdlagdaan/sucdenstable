import { useEffect, useMemo, useState } from 'react';
import napi from '../../../utils/axiosnapi';

type PbnRow = {
  id: number;
  pbn_number: string;
  pbn_date: string;
  sugar_type: string;
  crop_year: string;
  vend_code: string;
  vendor_name: string;
  posted_flag: number;
  close_flag: number;
};

type PbnDetail = {
  id: number;
  row: number;
  mill_code: string;
  mill: string;
  quantity: number;
  unit_cost: number;
  commission: number;
  used_qty: number;
  remaining_qty: number;
  usage_status: 'unused' | 'partial' | 'fully_used';
  selected_flag: number;
};

export default function Pbn_posting() {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = user?.company_id || '';

  const [status, setStatus] = useState<'unposted' | 'posted' | 'closed' | 'all'>('unposted');
  const [q, setQ] = useState('');
  const [list, setList] = useState<PbnRow[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const [main, setMain] = useState<any>(null);
  const [details, setDetails] = useState<PbnDetail[]>([]);
  const [loadingList, setLoadingList] = useState(false);
  const [loadingPbn, setLoadingPbn] = useState(false);


// ===== START ADD: approval request states =====
const [busyAction, setBusyAction] = useState<string>('');
const [approvalPost, setApprovalPost] = useState<any>(null);
const [approvalUnpost, setApprovalUnpost] = useState<any>(null);
const [approvalClose, setApprovalClose] = useState<any>(null);

const loadApprovals = async (pbnId: number) => {
  const base = { params: { module: 'pbn_posting', record_id: pbnId, company_id: companyId } };

  const [post, unpost, close] = await Promise.all([
    napi.get('/approvals/status', { ...base, params: { ...base.params, action: 'post' } }),
    napi.get('/approvals/status', { ...base, params: { ...base.params, action: 'unpost_unused' } }),
    napi.get('/approvals/status', { ...base, params: { ...base.params, action: 'close' } }),
  ]);

  setApprovalPost(post.data || null);
  setApprovalUnpost(unpost.data || null);
  setApprovalClose(close.data || null);
};

const requestAction = async (kind: 'post' | 'unpost_unused' | 'close') => {
  if (!selectedId) return;
  const reason = prompt(`Reason for ${kind.toUpperCase()} approval request:`) || '';

    if (reason === null) return;

    // user submitted blank
    if (reason.trim() === '') return;

  setBusyAction(kind);
  try {
    const url =
      kind === 'post'
        ? `/pbn/posting/${selectedId}/request-post`
        : kind === 'unpost_unused'
        ? `/pbn/posting/${selectedId}/request-unpost-unused`
        : `/pbn/posting/${selectedId}/request-close`;

    await napi.post(url, { company_id: companyId, reason });

    // refresh approval status
    await loadApprovals(selectedId);

    alert('Approval request submitted (or reused). Check Inbox for approval.');
  } catch (e: any) {
    alert(e?.response?.data?.message || 'Request failed.');
  } finally {
    setBusyAction('');
  }
};
// ===== END ADD: approval request states =====






  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    if (!term) return list;
    return list.filter(r =>
      (r.pbn_number || '').toLowerCase().includes(term) ||
      (r.vendor_name || '').toLowerCase().includes(term) ||
      (r.vend_code || '').toLowerCase().includes(term)
    );
  }, [list, q]);

  const loadList = async () => {
    if (!companyId) return;
    setLoadingList(true);
    try {
      const { data } = await napi.get('/pbn/posting/list', {
        params: { company_id: companyId, status }
      });
      setList(Array.isArray(data) ? data : []);
    } finally {
      setLoadingList(false);
    }
  };

  const loadPbn = async (id: number) => {
    if (!companyId) return;
    setSelectedId(id);
    setLoadingPbn(true);
    try {
      const { data } = await napi.get(`/pbn/posting/${id}`, {
        params: { company_id: companyId }
      });
      setMain(data?.main || null);
      setDetails(Array.isArray(data?.details) ? data.details : []);
      await loadApprovals(id);
    } finally {
      setLoadingPbn(false);
    }
  };

  useEffect(() => {
    loadList();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, companyId]);

  return (
    <div className="min-h-screen p-6 space-y-4">
      <div className="flex items-end gap-3">
        <div>
          <label className="block text-sm font-medium">Status</label>
          <select
            className="border rounded p-2"
            value={status}
            onChange={(e) => setStatus(e.target.value as any)}
          >
            <option value="unposted">Unposted</option>
            <option value="posted">Posted</option>
            <option value="closed">Closed</option>
            <option value="all">All</option>
          </select>
        </div>

        <div className="flex-1">
          <label className="block text-sm font-medium">Search</label>
          <input
            className="w-full border rounded p-2"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search PBN # / Vendor"
          />
        </div>

        <button
          className="border rounded px-4 py-2 bg-white hover:bg-gray-50"
          onClick={loadList}
          disabled={loadingList}
        >
          {loadingList ? 'Loading…' : 'Refresh'}
        </button>
      </div>

      <div className="grid grid-cols-12 gap-4">
        {/* LEFT LIST */}
        <div className="col-span-4 border rounded bg-white">
          <div className="p-2 font-semibold border-b">PBN List</div>
          <div className="max-h-[70vh] overflow-auto">
            {filtered.map(r => (
              <button
                key={r.id}
                onClick={() => loadPbn(r.id)}
                className={`w-full text-left p-3 border-b hover:bg-gray-50 ${
                  selectedId === r.id ? 'bg-yellow-50' : ''
                }`}
              >
                <div className="font-semibold">{r.pbn_number}</div>
                <div className="text-xs text-gray-600">{r.vendor_name}</div>
                <div className="text-xs text-gray-500">
                  {r.pbn_date} • {r.sugar_type} • {r.crop_year}
                </div>
              </button>
            ))}
            {filtered.length === 0 && (
              <div className="p-3 text-sm text-gray-500">No records.</div>
            )}
          </div>
        </div>

        {/* RIGHT PREVIEW */}
        <div className="col-span-8 space-y-3">
          <div className="border rounded bg-yellow-50">
            <div className="p-3 border-b font-semibold">PBN Information (Preview First)</div>

            {loadingPbn && <div className="p-3 text-sm">Loading…</div>}

            {!loadingPbn && !main && (
              <div className="p-3 text-sm text-gray-600">Select a PBN to preview.</div>
            )}

            {!loadingPbn && main && (
              <div className="p-3 grid grid-cols-2 gap-2 text-sm">
                <div><b>PBN #:</b> {main.pbn_number}</div>
                <div><b>PBN Date:</b> {String(main.pbn_date || '')}</div>
                <div><b>Vendor Code:</b> {main.vend_code}</div>
                <div><b>Vendor Name:</b> {main.vendor_name}</div>
                <div><b>Sugar Type:</b> {main.sugar_type}</div>
                <div><b>Crop Year:</b> {main.crop_year}</div>
                <div className="col-span-2">
                  <b>Status:</b>{' '}
                  {main.close_flag == 1 ? 'CLOSED' : main.posted_flag == 1 ? 'POSTED' : 'UNPOSTED'}
                </div>
              </div>
            )}
          </div>

          {/* DETAILS */}
          <div className="border rounded bg-white">
            <div className="p-3 border-b font-semibold">PBN Details (Remaining Qty)</div>

            {main && details.length === 0 && (
              <div className="p-3 text-sm text-gray-500">No detail rows.</div>
            )}

            {details.length > 0 && (
              <div className="max-h-[60vh] overflow-auto">
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-gray-50 border-b">
                    <tr>
                      <th className="p-2 text-left">Row</th>
                      <th className="p-2 text-left">Mill</th>
                      <th className="p-2 text-right">Qty</th>
                      <th className="p-2 text-right">Used</th>
                      <th className="p-2 text-right">Remaining</th>
                      <th className="p-2 text-left">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {details.map(d => (
                      <tr key={d.id} className="border-b">
                        <td className="p-2">{d.row}</td>
                        <td className="p-2">{d.mill_code || d.mill}</td>
                        <td className="p-2 text-right">{Number(d.quantity || 0).toFixed(2)}</td>
                        <td className="p-2 text-right">{Number(d.used_qty || 0).toFixed(2)}</td>
                        <td className="p-2 text-right">{Number(d.remaining_qty || 0).toFixed(2)}</td>
                        <td className="p-2">{d.usage_status}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {!main && (
              <div className="p-3 text-sm text-gray-500">
                Select a PBN first to show details.
              </div>
            )}
          </div>

          {/* Posting buttons will be added in the next drop (approval-aligned) */}
{/* ===== START REPLACE: Posting action buttons (approval-aligned) ===== */}
{main && (
  <div className="border rounded bg-white p-3 space-y-2">
    <div className="font-semibold">Posting Actions (Approval Required)</div>

    <div className="text-sm text-gray-600">
      Current approvals:
      <div className="mt-1 text-xs">
        POST: {approvalPost?.status || 'none'} | UNPOST_UNUSED: {approvalUnpost?.status || 'none'} | CLOSE: {approvalClose?.status || 'none'}
      </div>
    </div>

    <div className="flex gap-2 flex-wrap">
      <button
        className="px-4 py-2 rounded border bg-white hover:bg-gray-50 disabled:opacity-50"
        disabled={!selectedId || busyAction !== '' || main.posted_flag == 1 || main.close_flag == 1}
        onClick={() => requestAction('post')}
        title={main.close_flag == 1 ? 'Closed' : main.posted_flag == 1 ? 'Already posted' : 'Request POST approval'}
      >
        {busyAction === 'post' ? 'Requesting…' : 'Request POST Approval'}
      </button>

      <button
        className="px-4 py-2 rounded border bg-white hover:bg-gray-50 disabled:opacity-50"
        disabled={!selectedId || busyAction !== '' || main.posted_flag != 1 || main.close_flag == 1}
        onClick={() => requestAction('unpost_unused')}
        title={main.close_flag == 1 ? 'Closed' : main.posted_flag != 1 ? 'Must be posted first' : 'Request UNPOST_UNUSED approval'}
      >
        {busyAction === 'unpost_unused' ? 'Requesting…' : 'Request UNPOST_UNUSED Approval'}
      </button>

      <button
        className="px-4 py-2 rounded border bg-white hover:bg-gray-50 disabled:opacity-50"
        disabled={!selectedId || busyAction !== '' || main.posted_flag != 1 || main.close_flag == 1}
        onClick={() => requestAction('close')}
        title={main.close_flag == 1 ? 'Already closed' : main.posted_flag != 1 ? 'Must be posted first' : 'Request CLOSE approval'}
      >
        {busyAction === 'close' ? 'Requesting…' : 'Request CLOSE Approval'}
      </button>

      <button
        className="px-4 py-2 rounded border bg-yellow-50 hover:bg-yellow-100 disabled:opacity-50"
        disabled={!selectedId}
// ===== START FIX: refresh selectedId null guard =====
onClick={() => {
  if (selectedId != null) loadPbn(selectedId);
}}
// ===== END FIX: refresh selectedId null guard =====
        title="Refresh preview + remaining qty + approval status"
      >
        Refresh Preview
      </button>
    </div>

    <div className="text-xs text-gray-500">
      Note: Actions apply only after supervisor approves in Approvals Inbox.
    </div>
  </div>
)}
{/* ===== END REPLACE: Posting action buttons (approval-aligned) ===== */}

        </div>
      </div>
    </div>
  );
}
