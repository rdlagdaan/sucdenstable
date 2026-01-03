import { useEffect, useMemo, useState } from 'react';
import napi from '../../../utils/axiosnapi';
import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

type Row = {
  id: number;
  receipt_no: string;
  pbn_number: string;
  receipt_date: string | null;
  mill: string | null;
  vendor_name?: string | null;
  vendor_code?: string | null;
  sugar_type?: string | null;
  crop_year?: string | null;
  quantity?: number;
  posted_flag?: boolean | number;
  processed_flag?: boolean | number;
  deleted_flag?: boolean | number;
  pending_post?: boolean | number;
  pending_unpost?: boolean | number;
  pending_delete?: boolean | number;
pending_process?: boolean | number;

};

const asBool = (v: any) => v === true || v === 1 || v === '1';

const normalizeArray = (payload: any): Row[] => {
  if (Array.isArray(payload)) return payload as Row[];
  if (Array.isArray(payload?.data)) return payload.data as Row[];
  if (Array.isArray(payload?.rows)) return payload.rows as Row[];
  if (Array.isArray(payload?.result)) return payload.result as Row[];
  return [];
};


export default function Receiving_posting() {
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(false);

  const [selectedId, setSelectedId] = useState<number | null>(null);
const selected = useMemo(() => {
  return Array.isArray(rows) ? (rows.find(r => r.id === selectedId) || null) : null;
}, [rows, selectedId]);

const load = async () => {
  setLoading(true);
  try {
    const companyId = Number(localStorage.getItem('company_id') || 0);

    const res = await napi.get('/receiving-posting/list', {
      params: { q, company_id: companyId },
    });

    const arr = normalizeArray(res.data);
    setRows(arr);

    if (selectedId && !arr.some(r => r.id === selectedId)) {
      setSelectedId(null);
    }
  } catch (e: any) {
    console.error(e);
    setRows([]);
    toast.error(e?.response?.data?.message || 'Failed to load receiving entries');
  } finally {
    setLoading(false);
  }
};



  useEffect(() => { load(); }, []); // initial

const statusLabel = (r: Row) => {
  const del = asBool(r.deleted_flag);
  const posted = asBool(r.posted_flag);
  const proc = asBool(r.processed_flag);

  // ✅ add these
  const pendingPost = asBool(r.pending_post);
  const pendingUnpost = asBool(r.pending_unpost);
  const pendingDelete = asBool(r.pending_delete);

  if (pendingDelete) return { text: 'DELETE REQUESTED', cls: 'bg-orange-100 text-orange-800' };
  if (pendingPost)   return { text: 'POST REQUESTED', cls: 'bg-orange-100 text-orange-800' };
  if (pendingUnpost) return { text: 'UNPOST REQUESTED', cls: 'bg-orange-100 text-orange-800' };
const pendingProcess = asBool(r.pending_process);

if (pendingProcess) return { text: 'PROCESS REQUESTED', cls: 'bg-orange-100 text-orange-800' };

  // existing logic
  if (del) return { text: 'DELETED', cls: 'bg-red-100 text-red-700' };
  if (posted && proc) return { text: 'POSTED + PROCESSED (LOCKED)', cls: 'bg-gray-200 text-gray-800' };
  if (posted) return { text: 'POSTED', cls: 'bg-green-100 text-green-800' };
  return { text: 'DRAFT', cls: 'bg-yellow-100 text-yellow-800' };
};


const requestAction = async (action: 'POST'|'UNPOST'|'DELETE'|'PROCESS') => {
    if (!selected) return;

    const del = asBool(selected.deleted_flag);
    const posted = asBool(selected.posted_flag);
    const proc = asBool(selected.processed_flag);

    // basic client guards (server will enforce later in Bite B/C too)
    if (proc) {
      toast.error('This RR is already processed. No further actions allowed.');
      return;
    }
    if (action === 'UNPOST' && !posted) {
      toast.error('Cannot request UNPOST because this RR is not posted.');
      return;
    }
    if (action === 'POST' && posted) {
      toast.error('Already posted.');
      return;
    }
    if (action === 'DELETE' && del) {
      toast.error('Already deleted.');
      return;
    }

    const { value: reason } = await Swal.fire({
      title: `Request ${action}`,
      input: 'textarea',
      inputLabel: 'Reason (required)',
      inputPlaceholder: 'Type your reason...',
      inputAttributes: { 'aria-label': 'Reason' },
      showCancelButton: true,
      confirmButtonText: 'Submit Request',
      preConfirm: (v) => {
        const t = (v || '').trim();
        if (!t) {
          Swal.showValidationMessage('Reason is required');
        }
        return t;
      },
    });

    if (!reason) return;

    try {
const companyId = Number(localStorage.getItem('company_id') || 0);

await napi.post('/approvals/request-edit', {
  module: 'receiving_entries',
  record_id: selected.id,
  company_id: companyId,   // ✅ REQUIRED so Inbox Company filter can find it
  action,
  reason,
});


      toast.success(`Request submitted: ${action}`);
      await load();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || `Failed to submit ${action} request`);
    }
  };

  return (
    <div className="p-4">
      <ToastContainer />

      <div className="mb-3 flex items-center gap-2">
        <div className="text-xl font-bold">Receiving Entry Posting</div>
        {loading && <div className="text-sm text-gray-500">Loading…</div>}
      </div>

      <div className="mb-3 flex gap-2">
        <input
          className="w-full rounded border px-3 py-2"
          placeholder="Search RR No / PBN / Vendor…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <button
          className="rounded bg-blue-600 px-4 py-2 text-white"
          onClick={load}
        >
          Search
        </button>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {/* Left: list */}
        <div className="rounded border bg-white">
          <div className="border-b px-3 py-2 font-semibold">Transactions</div>
          <div className="max-h-[520px] overflow-auto">
            {rows.map((r) => {
              const s = statusLabel(r);
              return (
                <button
                  key={r.id}
                  className={`w-full border-b px-3 py-2 text-left hover:bg-gray-50 ${
                    selectedId === r.id ? 'bg-blue-50' : ''
                  }`}
                  onClick={() => setSelectedId(r.id)}
                >
                  <div className="flex items-center justify-between">
                    <div className="font-semibold">{r.receipt_no}</div>
                    <span className={`rounded px-2 py-1 text-xs ${s.cls}`}>{s.text}</span>
                  </div>
                  <div className="text-sm text-gray-600">
                    {r.vendor_name || ''} {r.pbn_number ? `• PBN ${r.pbn_number}` : ''}
                  </div>
                  <div className="text-xs text-gray-500">
                    {r.sugar_type ? `Sugar ${r.sugar_type}` : ''} {r.crop_year ? `• CY ${r.crop_year}` : ''} {r.mill ? `• ${r.mill}` : ''}
                  </div>
                </button>
              );
            })}
            {rows.length === 0 && (
              <div className="p-3 text-sm text-gray-500">No results.</div>
            )}
          </div>
        </div>

        {/* Right: review + actions */}
        <div className="rounded border bg-white">
          <div className="border-b px-3 py-2 font-semibold">Review</div>

          {!selected ? (
            <div className="p-3 text-sm text-gray-500">Select a transaction to review.</div>
          ) : (
            <div className="p-3">
              <div className="mb-2 flex items-center justify-between">
                <div className="text-lg font-bold">{selected.receipt_no}</div>
                <span className={`rounded px-2 py-1 text-xs ${statusLabel(selected).cls}`}>
                  {statusLabel(selected).text}
                </span>
              </div>

              <div className="grid grid-cols-2 gap-2 text-sm">
                <div><span className="text-gray-500">Vendor:</span> {selected.vendor_name || ''}</div>
                <div><span className="text-gray-500">Vendor Code:</span> {selected.vendor_code || ''}</div>
                <div><span className="text-gray-500">PBN:</span> {selected.pbn_number || ''}</div>
                <div><span className="text-gray-500">Date:</span> {selected.receipt_date || ''}</div>
                <div><span className="text-gray-500">Sugar Type:</span> {selected.sugar_type || ''}</div>
                <div><span className="text-gray-500">Crop Year:</span> {selected.crop_year || ''}</div>
                <div className="col-span-2"><span className="text-gray-500">Mill:</span> {selected.mill || ''}</div>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                <button
                  className="rounded bg-green-600 px-3 py-2 text-white disabled:bg-gray-300"
                  onClick={() => requestAction('POST')}
disabled={
  asBool(selected.posted_flag) ||
  asBool(selected.processed_flag) ||
  asBool(selected.deleted_flag) ||
  asBool(selected.pending_post) ||
  asBool(selected.pending_process)
}

                >
                  Request POST
                </button>

                <button
                  className="rounded bg-yellow-600 px-3 py-2 text-white disabled:bg-gray-300"
                  onClick={() => requestAction('UNPOST')}
disabled={
  !asBool(selected.posted_flag) ||
  asBool(selected.processed_flag) ||
  asBool(selected.deleted_flag) ||
  asBool(selected.pending_unpost) ||
  asBool(selected.pending_process)
}

                >
                  Request UNPOST
                </button>

                <button
                  className="rounded bg-red-600 px-3 py-2 text-white disabled:bg-gray-300"
                  onClick={() => requestAction('DELETE')}
disabled={
  asBool(selected.deleted_flag) ||
  asBool(selected.processed_flag) ||
  asBool(selected.pending_delete) ||
  asBool(selected.pending_process)
}

                >
                  Request SOFT DELETE
                </button>
<button
  className="rounded bg-blue-600 px-3 py-2 text-white disabled:bg-gray-300"
  onClick={() => requestAction('PROCESS')}
disabled={
  !asBool(selected.posted_flag) ||
  asBool(selected.processed_flag) ||
  asBool(selected.deleted_flag) ||
  asBool(selected.pending_process) ||
  asBool(selected.pending_post) ||
  asBool(selected.pending_unpost) ||
  asBool(selected.pending_delete)
}

>
  Request PROCESS
</button>

              </div>

              <div className="mt-3 text-xs text-gray-500">
                Notes:
                <ul className="list-disc pl-5">
                  <li>Requests appear in Approvals Inbox for supervisor action.</li>
                  <li>Processed entries cannot be unposted or deleted.</li>
                </ul>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
