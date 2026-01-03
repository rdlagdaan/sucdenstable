import { useEffect, useRef, useState } from 'react';
import napi from '../../../../utils/axiosnapi';

type Status = {
  status: 'queued' | 'running' | 'done' | 'error';
  progress: number;
  format: 'pdf' | 'xls'; // ✅ normalized like other modules
  file?: string | null;
  error?: string | null;
  message?: string | null;
};

type MonthRow = { month_num?: string | number; monthNum?: string | number; month_desc?: string; monthDesc?: string };
type YearRow  = { year?: number; year_val?: number } | number;

const POLL_MS = 1200;

export default function ReceiptRegister() {
  const [months, setMonths] = useState<{ value: number; label: string }[]>([]);
  const [years, setYears]   = useState<{ value: number; label: string }[]>([]);
  const [month, setMonth]   = useState<number | ''>('');
  const [year, setYear]     = useState<number | ''>('');
  const [query, setQuery]   = useState<string>('');

  const [loading, setLoading] = useState(true);
  const [loadErr, setLoadErr] = useState<string | null>(null);

  const [ticket, setTicket] = useState<string | null>(null);
  const [status, setStatus] = useState<Status | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [busy, setBusy] = useState(false);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // ✅ companyId (same safe pattern as other modules)
  const user = (() => {
    try {
      const s = localStorage.getItem('user');
      return s ? JSON.parse(s) : null;
    } catch {
      return null;
    }
  })();

  const companyId =
    Number(localStorage.getItem('company_id')) ||
    Number(user?.company_id ?? user?.companyId ?? user?.company?.id) ||
    0;

  // ---- bootstrap month/year ----
  useEffect(() => {
    let live = true;

    (async () => {
      try {
        setLoading(true);

        // ✅ enforce company scope
        const [m, y] = await Promise.all([
          napi.get(`receipt-register/months?company_id=${companyId}`),
          napi.get(`receipt-register/years?company_id=${companyId}`),
        ]);

        // be defensive in case a wrapper {data:[]} is returned
        const monthsRaw = Array.isArray(m.data) ? m.data : (Array.isArray(m.data?.data) ? m.data.data : []);
        const yearsRaw  = Array.isArray(y.data) ? y.data : (Array.isArray(y.data?.data) ? y.data.data : []);

        if (!Array.isArray(monthsRaw) || !Array.isArray(yearsRaw)) {
          throw new Error('Months/Years endpoint did not return an array.');
        }

        const mOpts = monthsRaw
          .map((r: MonthRow) => ({
            value: Number((r.month_num ?? r.monthNum) as any),
            label: String(r.month_desc ?? r.monthDesc ?? ''),
          }))
          .filter(o => Number.isFinite(o.value) && o.label);

        const yOpts = yearsRaw
          .map((r: YearRow) => {
            const val = typeof r === 'number' ? r : (r.year ?? r.year_val);
            return { value: Number(val), label: String(val) };
          })
          .filter(o => Number.isFinite(o.value));

        if (!live) return;

        setMonths(mOpts);
        setYears(yOpts);

        const now = new Date();
        if (mOpts.some(o => o.value === now.getMonth() + 1)) setMonth(now.getMonth() + 1);
        if (yOpts.some(o => o.value === now.getFullYear())) setYear(now.getFullYear());
      } catch (e: any) {
        console.error(e);
        if (live) setLoadErr(e?.message || 'Failed to load Month/Year options.');
      } finally {
        if (live) setLoading(false);
      }
    })();

    return () => { live = false; };
  }, [companyId]);

  // ---- begin generation ----
  async function begin(requested: 'pdf' | 'excel') {
    if (busy || !month || !year) return;
    setBusy(true);

    try {
      const { data } = await napi.post('receipt-register/report', {
        month: Number(month),
        year: Number(year),
        format: requested,              // BE will normalize excel->xls
        query: query?.trim() || undefined,
        company_id: companyId,          // ✅ tenant scope
      });

      setTicket(data.ticket);

      // ✅ optimistic normalized format
      setStatus({
        status: 'queued',
        progress: 1,
        format: requested === 'pdf' ? 'pdf' : 'xls',
      });

      setShowModal(true);
    } catch (e) {
      console.error(e);
      alert('Failed to start report.');
    } finally {
      setBusy(false);
    }
  }

  // ---- poll status ----
  useEffect(() => {
    if (!ticket) return;

    const poll = async () => {
      try {
        const { data } = await napi.get<Status>(
          `receipt-register/report/${ticket}/status?company_id=${companyId}`
        );
        setStatus(data);

        if (data.status === 'done' || data.status === 'error') {
          if (pollRef.current) clearInterval(pollRef.current);
          pollRef.current = null;
        }
      } catch {
        /* transient */
      }
    };

    poll();
    pollRef.current = setInterval(poll, POLL_MS);

    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
      pollRef.current = null;
    };
  }, [ticket, companyId]);

  const friendly = () => {
    const m = months.find(x => x.value === month)?.label ?? month;
    const y = year ?? '';
    const ext = status?.format === 'pdf' ? 'pdf' : 'xls';
    return `ReceiptRegister_${m}_${y}.${ext}`;
  };

  // ---- download / view (blob pattern used in other working modules) ----
  const download = async () => {
    if (!ticket || !status || status.status !== 'done') return;

    const res = await napi.get(
      `receipt-register/report/${ticket}/download?company_id=${companyId}`,
      { responseType: 'blob' }
    );

    const type =
      (res.headers?.['content-type'] as string) ||
      (status.format === 'pdf' ? 'application/pdf' : 'application/vnd.ms-excel');

    const blob = new Blob([res.data], { type });
    const href = URL.createObjectURL(blob);

    const a = document.createElement('a');
    a.href = href;
    a.download = friendly();
    document.body.appendChild(a);
    a.click();
    a.remove();

    URL.revokeObjectURL(href);
  };

  const viewPdf = async () => {
    if (!ticket || !status || status.status !== 'done' || status.format !== 'pdf') return;

    const res = await napi.get(
      `receipt-register/report/${ticket}/view?company_id=${companyId}`,
      { responseType: 'blob' }
    );

    const type = (res.headers?.['content-type'] as string) || 'application/pdf';
    const blob = new Blob([res.data], { type });
    const url  = URL.createObjectURL(blob);

    window.open(url, '_blank', 'noopener,noreferrer');
  };

  const reset = () => {
    setTicket(null);
    setStatus(null);
    setShowModal(false);
  };

  return (
    <div className="p-4 space-y-4">
      <div className="text-xl font-semibold">RECEIPT REGISTER</div>

      {loading ? (
        <div className="text-sm text-gray-600">Loading Month/Year…</div>
      ) : loadErr ? (
        <div className="text-sm text-red-600">{loadErr}</div>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label className="block text-sm mb-1">Month</label>
              <select
                className="w-full border p-2 rounded"
                value={month}
                onChange={(e) => setMonth(Number(e.target.value))}
              >
                <option value="">— Select —</option>
                {months.map(m => (
                  <option key={m.value} value={m.value}>{m.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm mb-1">Year</label>
              <select
                className="w-full border p-2 rounded"
                value={year}
                onChange={(e) => setYear(Number(e.target.value))}
              >
                <option value="">— Select —</option>
                {years.map(y => (
                  <option key={y.value} value={y.value}>{y.label}</option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm mb-1">Filter (optional)</label>
              <input
                className="w-full border p-2 rounded"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="CR No / Details / Customer / Bank"
              />
            </div>
          </div>

          <div className="flex items-end gap-2">
            <button
              className={`px-4 py-2 rounded text-white ${busy ? 'bg-emerald-400' : 'bg-emerald-600 hover:bg-emerald-700'}`}
              onClick={() => begin('pdf')}
              disabled={busy || !month || !year}
            >
              Generate
            </button>

            <button
              className={`px-4 py-2 rounded border ${busy ? 'opacity-50 cursor-not-allowed' : ''}`}
              onClick={() => begin('excel')}
              disabled={busy || !month || !year}
            >
              EXCEL
            </button>
          </div>

          <div className="text-sm text-blue-700">
            After clicking the EXCEL button, use the modal’s Download when ready.
          </div>
        </>
      )}

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-5">
            <div className="flex items-center justify-between mb-3">
              <div className="font-semibold">
                {status?.format === 'pdf' ? 'Generating PDF…' : 'Generating Excel…'}
              </div>
              <button className="text-gray-500" onClick={() => setShowModal(false)}>✕</button>
            </div>

            <div className="mb-2 text-sm">
              Period: <b>{months.find(m => m.value === month)?.label ?? month}</b> {year}
              {query?.trim() ? (
                <span className="ml-2 text-gray-600">
                  • Filter: <b>{query.trim()}</b>
                </span>
              ) : null}
            </div>

            <Progress value={status?.progress ?? 0} />

            <div className="mt-4 flex items-center justify-between">
              <div className="text-sm">
                Status: <b>{status?.status ?? '—'}</b>
                {status?.message && <div className="text-gray-600 mt-1">{status.message}</div>}
                {status?.error && <div className="text-red-600 mt-1">{status.error}</div>}
                {ticket && <div className="text-xs text-gray-500 mt-1">Ticket: {ticket}</div>}
              </div>

              <div className="flex gap-2">
                <button className="px-3 py-2 border rounded" onClick={() => setShowModal(false)}>Close</button>

                <button
                  className={`px-3 py-2 rounded ${status?.status === 'done' ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                  disabled={status?.status !== 'done'}
                  onClick={download}
                >
                  Download
                </button>

                <button
                  className={`px-3 py-2 rounded ${status?.status === 'done' && status?.format === 'pdf' ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                  disabled={status?.status !== 'done' || status?.format !== 'pdf'}
                  onClick={viewPdf}
                >
                  View
                </button>
              </div>
            </div>

            <div className="mt-3 flex justify-end">
              <button className="px-3 py-2 text-sm text-gray-600 underline" onClick={reset}>
                Start another
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Progress({ value }: { value: number }) {
  const pct = Math.min(100, Math.max(3, Math.floor(value || 0)));
  return (
    <div>
      <div className="w-full h-3 bg-gray-200 rounded">
        <div className="h-3 rounded bg-emerald-600 transition-all" style={{ width: `${pct}%` }} />
      </div>
      <div className="mt-1 text-right text-xs text-gray-600">{pct}%</div>
    </div>
  );
}
