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


const handleApprove = async (row: ApprovalRow) => {
  const action = (row.action || '').toUpperCase();

  if (action === '' || action === 'EDIT') {
    // EDIT – ask for edit window in minutes
    const { value: minutes } = await Swal.fire({
      title: `Approve request #${row.id}?`,
      input: 'number',
      inputLabel: 'Set how many minutes this approval will remain valid.',
      inputAttributes: { min: '1', max: '240', step: '1' },
      inputValue: 60,          // 👈 no row.edit_window_minutes
      showCancelButton: true,
    });

    if (minutes === undefined) return; // cancelled
    const mins = Math.max(1, Math.min(240, Number(minutes)));
    await napi.post(`/approvals/${row.id}/approve`, {
    edit_window_minutes: mins,
    });


    await napi.post(`/approvals/${row.id}/approve`, {
      edit_window_minutes: Number(minutes), // 👈 match backend approve()
    });
  } else {
    // CANCEL / DELETE (or any other one-shot action) – no time limit
    const { isConfirmed } = await Swal.fire({
      title: `Approve ${action.toLowerCase()} request #${row.id}?`,
      text:
        action === 'CANCEL'
          ? 'This will cancel the transaction.'
          : action === 'DELETE'
          ? 'This will permanently delete the transaction.'
          : 'This request has no edit window.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Approve',
    });

    if (!isConfirmed) return;

    // no minutes sent for CANCEL / DELETE
    await napi.post(`/approvals/${row.id}/approve`, {});
  }

  toast.success('Request approved.');
  await load();  // 👈 use existing load(), not refresh()
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
