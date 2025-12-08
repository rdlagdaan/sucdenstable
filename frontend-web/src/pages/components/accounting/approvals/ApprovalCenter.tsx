import { useEffect, useMemo, useState } from 'react';
import napi from '../../../../utils/axiosnapi';
import { toast } from 'react-toastify';

type Row = {
  id: number;
  company_id: number;
  subject_type: string;   // e.g. "cash_disbursement" (comes from COALESCE(module,''))
  subject_id: number;     // record id (comes from COALESCE(record_id,0))
  action: string;         // e.g. "edit" (COALESCE(action,'edit'))
  reason?: string;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
};

type StatusFilter = 'pending' | 'approved' | 'rejected' | 'all';

export default function ApprovalCenter() {
  const user = useMemo(() => JSON.parse(localStorage.getItem('user') || 'null'), []);
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(false);
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('pending');
  const [lastRefreshedAt, setLastRefreshedAt] = useState<Date | null>(null);

  const load = async (quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const params: any = { company_id: user?.company_id ?? '' };
      if (statusFilter !== 'all') params.status = statusFilter;

      const { data } = await napi.get('/approvals/inbox', { params });

      // Accept [] or { data: [] }. Ignore accidental HTML payloads.
      const list: Row[] =
        Array.isArray(data) ? (data as Row[]) :
        Array.isArray((data as any)?.data) ? ((data as any).data as Row[]) :
        [];

      setRows(list);

      if (!list.length && typeof data === 'string') {
        console.warn('[ApprovalCenter] Expected JSON, got string/HTML. Ensure backend route is under /api/.');
      }
      setLastRefreshedAt(new Date());
    } catch (err: any) {
      console.error('[ApprovalCenter] Inbox fetch failed', err);
      const msg = err?.response?.data?.message || 'Couldn’t load approvals.';
      toast.error(msg);
      setRows([]);
    } finally {
      if (!quiet) setLoading(false);
    }
  };

  // Initial load + reload on filter change
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  // Gentle polling so the list updates after requests/approvals elsewhere
  useEffect(() => {
    const id = window.setInterval(() => load(true), 10000); // 10s
    return () => window.clearInterval(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [statusFilter]);

  const approve = async (id: number) => {
    try {
      await napi.post(`/approvals/${id}/approve`, { expires_minutes: 120 });
      toast.success('Approved');
      await load(true); // refresh quietly to reflect any server-side changes
      setRows(prev => prev.filter(r => r.id !== id)); // optimistic removal
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Approve failed');
    }
  };

  const reject = async (id: number) => {
    try {
      await napi.post(`/approvals/${id}/reject`, { response_message: 'Denied' });
      toast.info('Rejected');
      await load(true);
      setRows(prev => prev.filter(r => r.id !== id));
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Reject failed');
    }
  };

  return (
    <div className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-xl font-bold">Approval Center</h2>
        <div className="flex items-center gap-2">
          <div className="inline-flex rounded overflow-hidden border">
            {(['pending','approved','rejected','all'] as StatusFilter[]).map(s => (
              <button
                key={s}
                onClick={() => setStatusFilter(s)}
                className={`px-3 py-2 text-sm ${
                  statusFilter === s ? 'bg-blue-600 text-white' : 'bg-white hover:bg-blue-50'
                }`}
                title={`Show ${s}`}
              >
                {s[0].toUpperCase() + s.slice(1)}
              </button>
            ))}
          </div>
          <button
            onClick={() => load()}
            className="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
          >
            Refresh
          </button>
        </div>
      </div>

      {lastRefreshedAt && (
        <div className="text-xs text-gray-500 mb-2">
          Last updated: {lastRefreshedAt.toLocaleTimeString()}
        </div>
      )}

      {loading ? (
        <div className="bg-white border rounded shadow p-6">Loading…</div>
      ) : (
        <div className="bg-white border rounded shadow">
          <table className="w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-3 py-2 text-left w-[170px]">When</th>
                <th className="px-3 py-2 text-left">Module</th>
                <th className="px-3 py-2 text-left">Record</th>
                <th className="px-3 py-2 text-left">Action</th>
                <th className="px-3 py-2 text-left">Reason</th>
                <th className="px-3 py-2 text-right w-[160px]"></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                    No {statusFilter === 'all' ? '' : statusFilter + ' '}requests
                  </td>
                </tr>
              )}
              {rows.map(r => (
                <tr key={r.id} className="border-t">
                  <td className="px-3 py-2">{new Date(r.created_at).toLocaleString()}</td>
                  <td className="px-3 py-2 font-semibold">{r.subject_type}</td>
                  <td className="px-3 py-2">{r.subject_id}</td>
                  <td className="px-3 py-2">{r.action || 'edit'}</td>
                  <td className="px-3 py-2">{r.reason || '—'}</td>
                  <td className="px-3 py-2 text-right">
                    {r.status === 'pending' ? (
                      <>
                        <button
                          onClick={() => approve(r.id)}
                          className="mr-2 px-2 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700"
                        >
                          Approve
                        </button>
                        <button
                          onClick={() => reject(r.id)}
                          className="px-2 py-1 rounded bg-red-600 text-white hover:bg-red-700"
                        >
                          Reject
                        </button>
                      </>
                    ) : (
                      <span className={`px-2 py-1 rounded text-xs ${
                        r.status === 'approved' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' :
                        r.status === 'rejected' ? 'bg-red-50 text-red-700 border border-red-200' :
                        'bg-gray-50 text-gray-700 border border-gray-200'
                      }`}>
                        {r.status}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
