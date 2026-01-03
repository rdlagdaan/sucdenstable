import { useEffect, useMemo, useState } from 'react';
import Swal from 'sweetalert2';
import { ToastContainer, toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../../utils/axiosnapi';

type ApprovalRow = {
  id: number;
  subject_type: string;
  subject_id: number;
  action: string | null;
  reason: string | null;
  status: string;
  created_at: string;
};

const statusLabelClass: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800 border border-amber-300',
  approved: 'bg-emerald-100 text-emerald-800 border border-emerald-300',
  rejected: 'bg-rose-100 text-rose-800 border border-rose-300',
  expired: 'bg-gray-200 text-gray-700 border border-gray-300',
};


export default function ApprovalsInbox() {
  const [rows, setRows] = useState<ApprovalRow[]>([]);
  const [loading, setLoading] = useState(false);
const [statusFilter, setStatusFilter] = useState<
'pending' | 'approved' | 'rejected' | 'expired' | 'all'
>('pending');


const [search, setSearch] = useState('');
const [page, setPage] = useState(1);
const perPage = 20;

const [totalPages, setTotalPages] = useState(1);

const [_processPreviewOpen, setProcessPreviewOpen] = useState(false);
const [_processPreview, setProcessPreview] = useState<any>(null);
const [_processApprovalId, setProcessApprovalId] = useState<number | null>(null);

  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);

const load = async () => {
  try {
    setLoading(true);

    const params: any = {
      search,
      page,
      per_page: perPage,   // 👈 backend expects "per_page"
    };

    if (statusFilter !== 'all') {
      params.status = statusFilter;
    }

    if (user?.company_id) {
      params.company_id = user.company_id;
    }

    const { data } = await napi.get('/approvals/inbox', { params });

    const rows = Array.isArray(data.data) ? data.data : [];
    setRows(rows);
    setTotalPages(data.total_pages || 1);
  } catch (e: any) {
    toast.error(
      e?.response?.data?.message || 'Failed to load approvals inbox.'
    );
  } finally {
    setLoading(false);
  }
};


// When filter or search changes, reset to page 1
useEffect(() => {
  setPage(1);
}, [statusFilter, search]);

// Whenever anything relevant changes, reload data
useEffect(() => {
  load();
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [page, perPage, statusFilter, search]);


/*useEffect(() => {
  if (processPreviewOpen) {
    confirmProcessApproval();
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [processPreviewOpen]);*/


const handleApprove = async (row: ApprovalRow) => {
  const action = (row.action || '').toUpperCase();
  const module = (row.subject_type || '').toLowerCase();

  // ✅ Special case: RECEIVING PROCESS must show Purchase Journal preview first
  if (module === 'receiving_entries' && action === 'PROCESS') {
    try {
const res = await napi.get(`/approvals/${row.id}`);

const previewErr = res?.data?.context?.purchase_journal_preview_error;
if (previewErr) {
  await Swal.fire({
    title: 'Purchase Journal preview error',
    text: previewErr,
    icon: 'error',
  });
  return;
}


const previewObj = res?.data?.context?.purchase_journal_preview;

const lines =
  previewObj?.lines ??
  previewObj?.entries ??
  previewObj?.details ??
  null;

if (!previewObj || !Array.isArray(lines)) {
  await Swal.fire({
    title: 'No Purchase Journal preview found',
    text: 'Cannot display entries. Please check server logs (Approval show(): buildJournalPreview result).',
    icon: 'error',
  });
  return;
}

// Normalize so confirmProcessApproval always uses preview.lines
const normalizedPreview = {
  ...previewObj,
  lines,
};

setProcessApprovalId(row.id);
setProcessPreview(normalizedPreview);

// ✅ open modal immediately (no state-trigger mystery)
await confirmProcessApproval(row.id, normalizedPreview);

return;


    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to load Purchase Journal preview.');
      return;
    }
  }

  // ✅ EDIT
  if (action === '' || action === 'EDIT') {
    const { value: minutes } = await Swal.fire({
      title: `Approve request #${row.id}?`,
      input: 'number',
      inputLabel: 'Set how many minutes this approval will remain valid.',
      inputAttributes: { min: '1', max: '240', step: '1' },
      inputValue: 60,
      showCancelButton: true,
    });

    if (minutes === undefined) return;

    const mins = Math.max(1, Math.min(240, Number(minutes)));

    await napi.post(`/approvals/${row.id}/approve`, {
      edit_window_minutes: mins,
    });

    toast.success('Request approved.');
    await load();
    return;
  }

  // ✅ One-shot actions (POST, UNPOST, PROCESS (non-receiving), SOFT DELETE, etc.)
  const { isConfirmed } = await Swal.fire({
    title: `Approve ${action.toLowerCase()} request #${row.id}?`,
    text: 'This request has no edit window.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: 'Approve',
  });

  if (!isConfirmed) return;

  await napi.post(`/approvals/${row.id}/approve`, {});
  toast.success('Request approved.');
  await load();
};


const confirmProcessApproval = async (approvalId: number, preview: any) => {
  const lines = Array.isArray(preview?.lines) ? preview.lines : [];
  const totals = preview?.totals || {};
  const balanced = !!totals.balanced;

  const defaultBookingNo = '';
  //const _defaultExplanation = '';

  // ✅ nicer, guided layout
  const html = `
    <div style="text-align:left; font-size:13px;">

      <!-- Totals bar -->
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

      <!-- Inputs card -->
      <div style="
        border:1px solid #e5e7eb; border-radius:8px; padding:12px;
        margin-bottom:12px; background:#fff;
      ">
        <div style="font-weight:700; margin-bottom:10px;">Processing Details</div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
          <div>
            <label style="font-size:12px; color:#374151;">Booking No <span style="color:#6b7280">(optional)</span></label>
            <input id="bk" class="swal2-input" value="${defaultBookingNo}"
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
            placeholder="Write a short explanation for this PROCESS approval..."
            style="margin:6px 0 0 0; height:95px; width:100%;"></textarea>
        </div>

        <div style="margin-top:8px; font-size:12px; color:#6b7280;">
          This will create/update the Purchase Journal based on the preview entries below.
        </div>
      </div>

      <!-- Entries table -->
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

  try {
    const { isConfirmed, value: formValues } = await Swal.fire({
      title: `Approve PROCESS #${approvalId}?`,
      html,
      width: 980,
      showCancelButton: true,
      confirmButtonText: balanced ? 'Approve' : 'Cannot Approve (Not Balanced)',
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

        // ✅ autofocus explanation to guide user
        setTimeout(() => {
          const exp = document.getElementById('exp') as HTMLTextAreaElement | null;
          exp?.focus();
        }, 0);
      },
    });

    if (!isConfirmed) return;

    await napi.post(`/approvals/${approvalId}/approve`, formValues || {});
    toast.success('Request approved.');
    await load();
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to approve PROCESS.');
  } finally {
    // ✅ always cleanup so repeated opens work
    setProcessPreview(null);
    setProcessApprovalId(null);
    setProcessPreviewOpen(false); // safe even if you stop using it
  }
};





  const handleReject = async (row: ApprovalRow) => {
    const { value: reason } = await Swal.fire({
      title: `Reject request #${row.id}?`,
      input: 'textarea',
      inputLabel: 'Reason (optional)',
      inputPlaceholder: 'Why is this request rejected?',
      showCancelButton: true,
      confirmButtonText: 'Reject',
      confirmButtonColor: '#dc2626',
    });

    if (reason === undefined) return; // cancelled

    try {
      await napi.post(`/approvals/${row.id}/reject`, {
        response_message: reason || null,
      });
      toast.success('Request rejected.');
      load();
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to reject request.');
    }
  };

  return (
    <div className="p-6 space-y-4">
      {/* If your layout already has a ToastContainer, you can remove this */}
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-gray-800">
          Supervisor Approvals – Inbox
        </h1>
        <button
          type="button"
          onClick={load}
          className="px-3 py-1.5 text-sm rounded-md border bg-white hover:bg-gray-50"
        >
          Refresh
        </button>
      </div>

<div className="flex gap-3 items-center">

  {/* Status Filter */}
  <label className="text-sm text-gray-700">
    Status:
    <select
      className="ml-2 border rounded px-2 py-1 text-sm"
      value={statusFilter}
      onChange={(e) =>
        setStatusFilter(
          e.target.value as 'pending' | 'approved' | 'rejected' | 'all'
        )
      }
    >
      <option value="pending">Pending</option>
      <option value="approved">Approved</option>
      <option value="expired">Expired</option>      
      <option value="rejected">Rejected</option>
      <option value="all">All</option>
    </select>
  </label>

  {/* 🔍 Search box */}
  <input
    type="text"
    value={search}
    onChange={(e) => setSearch(e.target.value)}
    placeholder="Search…"
    className="border rounded px-2 py-1 text-sm w-60"
  />

  {user?.company_id && (
    <div className="text-xs text-gray-500">
      Company: <span className="font-semibold">{user.company_id}</span>
    </div>
  )}
</div>



      <div className="bg-white shadow-sm rounded-lg border overflow-hidden">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-100 text-gray-700">
            <tr>
              <th className="px-3 py-2 text-left">#</th>
              <th className="px-3 py-2 text-left">Module</th>
              <th className="px-3 py-2 text-left">Record ID</th>
              <th className="px-3 py-2 text-left">Action</th>
              <th className="px-3 py-2 text-left">Reason</th>
              <th className="px-3 py-2 text-left">Status</th>
              <th className="px-3 py-2 text-left">Requested At</th>
              <th className="px-3 py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td
                  colSpan={8}
                  className="px-3 py-6 text-center text-gray-500"
                >
                  Loading...
                </td>
              </tr>
            )}

            {!loading && rows.length === 0 && (
              <tr>
                <td
                  colSpan={8}
                  className="px-3 py-6 text-center text-gray-400"
                >
                  No approvals found.
                </td>
              </tr>
            )}

            {!loading &&
              rows.map((row) => {
                const statusKey = (row.status || '').toLowerCase();
                const pillClass =
                  statusLabelClass[statusKey] ||
                  'bg-gray-100 text-gray-700 border';

                return (
                  <tr
                    key={row.id}
                    className="border-t hover:bg-gray-50"
                  >
                    <td className="px-3 py-2">{row.id}</td>
                    <td className="px-3 py-2">
                      {row.subject_type || '—'}
                    </td>
                    <td className="px-3 py-2">
                      {row.subject_id || '—'}
                    </td>
                    <td className="px-3 py-2">
                      <span className="uppercase text-xs font-semibold bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded">
                        {row.action || 'edit'}
                      </span>
                    </td>
                    <td className="px-3 py-2 max-w-xs">
                      <div
                        className="truncate"
                        title={row.reason || undefined}
                      >
                        {row.reason || (
                          <span className="text-gray-400">—</span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <span
                        className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${pillClass}`}
                      >
                        {row.status}
                      </span>
                    </td>
                    <td className="px-3 py-2">
                      {row.created_at
                        ? new Date(row.created_at).toLocaleString()
                        : '—'}
                    </td>
                    <td className="px-3 py-2 text-right">
                      {statusKey === 'pending' ? (
                        <div className="inline-flex gap-2">
                          <button
                            type="button"
                            onClick={() => handleApprove(row)}
                            className="px-2 py-1 text-xs rounded bg-emerald-600 text-white hover:bg-emerald-700"
                          >
                            Approve
                          </button>
                          <button
                            type="button"
                            onClick={() => handleReject(row)}
                            className="px-2 py-1 text-xs rounded bg-rose-600 text-white hover:bg-rose-700"
                          >
                            Reject
                          </button>
                        </div>
                      ) : (
                        <span className="text-xs text-gray-400">
                          No actions
                        </span>
                      )}
                    </td>
                  </tr>
                );
              })}
          </tbody>
        </table>

<div className="flex justify-between items-center mt-3">
  <button
    disabled={page <= 1}
    onClick={() => setPage(p => Math.max(1, p - 1))}
    className="px-3 py-1 border rounded disabled:opacity-50"
  >
    Prev
  </button>

  <span className="text-sm">
    Page {page} / {totalPages}
  </span>

  <button
    disabled={page >= totalPages}
    onClick={() => setPage(p => Math.min(totalPages, p + 1))}
    className="px-3 py-1 border rounded disabled:opacity-50"
  >
    Next
  </button>
</div>








      </div>
    </div>
  );
}
