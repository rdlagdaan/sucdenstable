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

  if (del) return { text: 'DELETED', cls: 'bg-red-100 text-red-700' };
  if (posted && proc) return { text: 'POSTED + PROCESSED (LOCKED)', cls: 'bg-gray-200 text-gray-800' };
  if (posted) return { text: 'POSTED', cls: 'bg-green-100 text-green-800' };
  return { text: 'DRAFT', cls: 'bg-yellow-100 text-yellow-800' };
};


const performAction = async (action: 'POST'|'UNPOST'|'DELETE'|'PROCESS') => {
  if (!selected) return;

  const del = asBool(selected.deleted_flag);
  const posted = asBool(selected.posted_flag);
  const proc = asBool(selected.processed_flag);

  if (proc && action !== 'PROCESS') {
    toast.error('This RR is already processed. No further actions allowed.');
    return;
  }

  if (action === 'POST' && posted) {
    toast.error('Already posted.');
    return;
  }

  if (action === 'UNPOST' && !posted) {
    toast.error('Cannot unpost because this RR is not posted.');
    return;
  }

  if (action === 'DELETE' && del) {
    toast.error('Already deleted.');
    return;
  }

  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = Number(user?.company_id || localStorage.getItem('company_id') || 0);
  const userId = Number(user?.id || user?.user_id || 0);

  if (!companyId) {
    toast.error('Missing company id.');
    return;
  }

  if (action === 'POST') {
    const { isConfirmed } = await Swal.fire({
      title: 'Post this Receiving Entry?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Post',
    });

    if (!isConfirmed) return;

    try {
      await napi.post(`/receiving-posting/post/${selected.id}`, {
        company_id: companyId,
        user_id: userId,
      });
      toast.success('Receiving Entry posted.');
      await load();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to post Receiving Entry.');
    }
    return;
  }

  if (action === 'UNPOST') {
    const { isConfirmed } = await Swal.fire({
      title: 'Unpost this Receiving Entry?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Unpost',
    });

    if (!isConfirmed) return;

    try {
      await napi.post(`/receiving-posting/unpost/${selected.id}`, {
        company_id: companyId,
        user_id: userId,
      });
      toast.success('Receiving Entry unposted.');
      await load();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to unpost Receiving Entry.');
    }
    return;
  }

  if (action === 'DELETE') {
    const { isConfirmed } = await Swal.fire({
      title: 'Delete this Receiving Entry?',
      text: 'This is a soft delete.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Delete',
      confirmButtonColor: '#dc2626',
    });

    if (!isConfirmed) return;

    try {
      await napi.post(`/receiving-posting/delete/${selected.id}`, {
        company_id: companyId,
        user_id: userId,
      });
      toast.success('Receiving Entry deleted.');
      await load();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to delete Receiving Entry.');
    }
    return;
  }

  if (action === 'PROCESS') {
    try {
      const previewRes = await napi.get(`/receiving-posting/preview-journal/${selected.id}`, {
        params: { company_id: companyId },
      });

      const preview = previewRes.data || {};
      const lines = Array.isArray(preview?.lines)
        ? preview.lines
        : Array.isArray(preview?.entries)
        ? preview.entries
        : Array.isArray(preview?.details)
        ? preview.details
        : [];

      const totals = preview?.totals || {};
      const balanced = !!totals.balanced;

      const explanationSeed = String(preview?.explanation_seed || '').trim();

      const html = `
        <div style="text-align:left; font-size:13px;">
          <div style="
            display:flex; align-items:center; justify-content:space-between;
            padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px;
            background:#f9fafb; margin-bottom:12px;
          ">
            <div>
              <div style="font-weight:600; margin-bottom:2px;">Totals</div>
              <div style="color:#374151;">
                Debit: <b>${totals.debit ?? ''}</b> &nbsp; | &nbsp;
                Credit: <b>${totals.credit ?? ''}</b>
              </div>
            </div>

            <div style="
              padding:6px 10px; border-radius:999px; font-weight:700;
              border:1px solid ${balanced ? '#86efac' : '#fca5a5'};
              background:${balanced ? '#dcfce7' : '#fee2e2'};
              color:${balanced ? '#166534' : '#991b1b'};
            ">
              ${balanced ? 'BALANCED' : 'NOT BALANCED'}
            </div>
          </div>

          <div style="
            border:1px solid #e5e7eb; border-radius:8px; padding:12px;
            margin-bottom:12px; background:#fff;
          ">
            <div style="font-weight:700; margin-bottom:10px;">Processing Details</div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label style="font-size:12px; color:#374151;">Booking No <span style="color:#6b7280">(optional)</span></label>
                <input id="bk" class="swal2-input" value=""
                  placeholder="Booking no"
                  style="margin:6px 0 0 0; width:100%;" />
              </div>
              <div></div>
            </div>

            <div style="margin-top:10px;">
              <label style="font-size:12px; color:#374151;">
                Explanation <span style="color:#ef4444">*</span>
              </label>
              <textarea id="exp" class="swal2-textarea"
                placeholder="Write a short explanation for this process..."
                style="margin:6px 0 0 0; height:95px; width:100%;">${explanationSeed}</textarea>
            </div>
          </div>

          <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
            <div style="padding:10px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb; font-weight:700;">
              Journal Entries (Review)
            </div>

            <div style="max-height:260px; overflow:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:12px">
                <thead>
                  <tr style="background:#ffffff; position:sticky; top:0;">
                    <th style="border-bottom:1px solid #eee; padding:8px; text-align:left;">Acct</th>
                    <th style="border-bottom:1px solid #eee; padding:8px; text-align:left;">Description</th>
                    <th style="border-bottom:1px solid #eee; padding:8px; text-align:right;">Debit</th>
                    <th style="border-bottom:1px solid #eee; padding:8px; text-align:right;">Credit</th>
                  </tr>
                </thead>
                <tbody>
                  ${
                    lines.map((l:any, idx:number) => `
                      <tr style="background:${idx % 2 === 0 ? '#ffffff' : '#fafafa'};">
                        <td style="border-bottom:1px solid #f2f2f2; padding:8px;">${l.acct_code ?? ''}</td>
                        <td style="border-bottom:1px solid #f2f2f2; padding:8px;">${l.acct_desc ?? ''}</td>
                        <td style="border-bottom:1px solid #f2f2f2; padding:8px; text-align:right;">${l.debit ?? ''}</td>
                        <td style="border-bottom:1px solid #f2f2f2; padding:8px; text-align:right;">${l.credit ?? ''}</td>
                      </tr>
                    `).join('')
                  }
                </tbody>
              </table>
            </div>
          </div>
        </div>
      `;

      const { isConfirmed, value } = await Swal.fire({
        title: 'Process this Receiving Entry?',
        html,
        width: 980,
        showCancelButton: true,
        confirmButtonText: balanced ? 'Process' : 'Cannot Process (Not Balanced)',
        confirmButtonColor: balanced ? undefined : '#9ca3af',

        preConfirm: () => {
          const bk = (document.getElementById('bk') as HTMLInputElement)?.value?.trim() || '';
          const exp = (document.getElementById('exp') as HTMLTextAreaElement)?.value?.trim() || '';

          if (!balanced) return false;

          if (!exp) {
            Swal.showValidationMessage('Explanation is required.');
            return false;
          }

          return {
            booking_no: bk || null,
            explanation: exp,
          };
        },

        didOpen: () => {
          const btn = Swal.getConfirmButton();
          if (btn && !balanced) btn.setAttribute('disabled', 'true');

          setTimeout(() => {
            const exp = document.getElementById('exp') as HTMLTextAreaElement | null;
            exp?.focus();
          }, 0);
        },
      });

      if (!isConfirmed) return;

      await napi.post(`/receiving-posting/process/${selected.id}`, {
        company_id: companyId,
        user_id: userId,
        ...(value || {}),
      });

      toast.success('Receiving Entry processed.');
      await load();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to process Receiving Entry.');
    }
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
                  onClick={() => performAction('POST')}
                  disabled={
                    asBool(selected.posted_flag) ||
                    asBool(selected.processed_flag) ||
                    asBool(selected.deleted_flag)
                  }
                >
                  Post
                </button>

                <button
                  className="rounded bg-yellow-600 px-3 py-2 text-white disabled:bg-gray-300"
                  onClick={() => performAction('UNPOST')}
                  disabled={
                    !asBool(selected.posted_flag) ||
                    asBool(selected.processed_flag) ||
                    asBool(selected.deleted_flag)
                  }
                >
                  Unpost
                </button>

                <button
                  className="rounded bg-red-600 px-3 py-2 text-white disabled:bg-gray-300"
                  onClick={() => performAction('DELETE')}
                  disabled={
                    asBool(selected.deleted_flag) ||
                    asBool(selected.processed_flag)
                  }
                >
                  Delete
                </button>
<button
  className="rounded bg-blue-600 px-3 py-2 text-white disabled:bg-gray-300"
  onClick={() => performAction('PROCESS')}
  disabled={
    !asBool(selected.posted_flag) ||
    asBool(selected.processed_flag) ||
    asBool(selected.deleted_flag)
  }
>
  Process
</button>

              </div>

              <div className="mt-3 text-xs text-gray-500">
                Notes:
                <ul className="list-disc pl-5">
                  <li>Post, Unpost, Delete, and Process now execute directly in this module.</li>
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
