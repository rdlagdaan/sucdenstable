import { useEffect, useMemo, useState } from 'react';
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

type OutboxResponse = {
  data: ApprovalRow[];
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
};

const statusLabelClass: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800 border border-amber-300',
  approved: 'bg-emerald-100 text-emerald-800 border border-emerald-300',
  rejected: 'bg-rose-100 text-rose-800 border border-rose-300',
};

export default function ApprovalsOutbox() {
  const [rows, setRows] = useState<ApprovalRow[]>([]);
  const [loading, setLoading] = useState(false);

  // 🔍 search + pagination state
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const perPage = 20; // fixed page size
  const [totalPages, setTotalPages] = useState(1);
  const [totalRows, setTotalRows] = useState(0);

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
        per_page: perPage,
      };

      // just to be safe if auth()->user() is not wired
      if (user?.id) {
        params.requester_id = user.id;
      }

      const { data } = await napi.get<OutboxResponse>('/approvals/outbox', {
        params,
      });

      const list = Array.isArray(data.data) ? data.data : [];
      setRows(list);
      setTotalPages(data.total_pages || 1);
      setTotalRows(data.total || 0);
    } catch (e: any) {
      toast.error(
        e?.response?.data?.message || 'Failed to load my approval requests.'
      );
    } finally {
      setLoading(false);
    }
  };

  // When search text changes → reset to first page
  useEffect(() => {
    setPage(1);
  }, [search]);

  // When page or search changes → reload
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, search]);

  const from = totalRows === 0 ? 0 : (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, totalRows);

  return (
    <div className="p-6 space-y-4">
      {/* If you already have a global ToastContainer, you can remove this */}
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold text-gray-800">
            My Approval Requests
          </h1>
          {user && (
            <div className="text-xs text-gray-500 mt-1">
              User:{' '}
              <span className="font-semibold">
                {user.username || user.name || user.id}
              </span>
            </div>
          )}
        </div>

        <div className="flex items-center gap-3">
          {/* 🔍 Search box */}
          <div className="relative">
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search module / reason / status…"
              className="border rounded-md px-3 py-1.5 text-sm pr-8"
            />
            <span className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs">
              ⌕
            </span>
          </div>

          <button
            type="button"
            onClick={load}
            className="px-3 py-1.5 text-sm rounded-md border bg-white hover:bg-gray-50"
          >
            Refresh
          </button>
        </div>
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
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td
                  colSpan={7}
                  className="px-3 py-6 text-center text-gray-500"
                >
                  Loading...
                </td>
              </tr>
            )}

            {!loading && rows.length === 0 && (
              <tr>
                <td
                  colSpan={7}
                  className="px-3 py-6 text-center text-gray-400"
                >
                  You have no approval requests yet.
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
                  </tr>
                );
              })}
          </tbody>
        </table>

        {/* 🔢 Pagination footer */}
        {totalRows > 0 && (
          <div className="flex items-center justify-between px-3 py-2 border-t bg-gray-50 text-xs text-gray-600">
            <div>
              Showing <span className="font-semibold">{from}</span>–
              <span className="font-semibold">{to}</span> of{' '}
              <span className="font-semibold">{totalRows}</span>
            </div>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className={`px-2 py-1 rounded border text-xs ${
                  page <= 1
                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                    : 'bg-white text-gray-700 hover:bg-gray-100'
                }`}
              >
                Previous
              </button>
              <span>
                Page <span className="font-semibold">{page}</span> of{' '}
                <span className="font-semibold">{totalPages}</span>
              </span>
              <button
                type="button"
                onClick={() =>
                  setPage((p) => Math.min(totalPages, p + 1))
                }
                disabled={page >= totalPages}
                className={`px-2 py-1 rounded border text-xs ${
                  page >= totalPages
                    ? 'bg-gray-100 text-gray-400 cursor-not-allowed'
                    : 'bg-white text-gray-700 hover:bg-gray-100'
                }`}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
