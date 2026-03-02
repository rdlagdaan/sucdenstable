import { useEffect, useMemo, useRef, useState } from 'react';
import dayjs from 'dayjs';
import napi from '../../../../utils/axiosnapi';
import DropdownWithHeaders, { type DropdownItem } from '../../../components/DropdownWithHeaders';

type Status = {
  status: 'queued'|'running'|'done'|'error';
  progress: number;
  format: 'pdf'|'xls';
  file?: string|null;
  error?: string|null;
  start_date?: string;
  end_date?: string;
  vend_id?: string;
};

function normText(v: any): string {
  return String(v ?? '').replace(/\s+/g, ' ').trim();
}

export default function VendorSummaryReport() {
  const [startDate, setStartDate] = useState(dayjs().startOf('month').format('YYYY-MM-DD'));
  const [endDate,   setEndDate]   = useState(dayjs().endOf('month').format('YYYY-MM-DD'));

  const [vendId, setVendId] = useState('');
  const [vendSearch, setVendSearch] = useState('');
  const [vendors, setVendors] = useState<DropdownItem[]>([]);

  const [ticket, setTicket] = useState<string|null>(null);
  const [status, setStatus] = useState<Status|null>(null);
  const [showModal, setShowModal] = useState(false);
  const [busy, setBusy] = useState(false);
  const pollRef = useRef<number|null>(null);

  const user = useMemo(() => {
    try {
      const s = localStorage.getItem('user');
      return s ? JSON.parse(s) : null;
    } catch {
      return null;
    }
  }, []);

  const companyId =
    Number(localStorage.getItem('company_id')) ||
    Number(user?.company_id ?? user?.companyId ?? user?.company?.id) ||
    0;

  // ✅ load vendors (customer pattern) + normalize + dedupe (vendor data commonly has duplicates)
  useEffect(() => {
    if (!companyId) return; // IMPORTANT: avoid calling /vendors with 0

    (async () => {
      try {
        const { data } = await napi.get('/vendors', { params: { company_id: companyId } });

        const raw: DropdownItem[] = (data || []).map((v: any) => ({
          code: normText(v.vend_id ?? v.vend_code ?? v.code),
          description: normText(v.vend_name ?? v.description ?? ''),
        }));

        // remove blanks
        const cleaned = raw.filter(x => x.code && x.description);

        // dedupe by normalized code+desc
        const uniq = new Map<string, DropdownItem>();
        for (const it of cleaned) {
const key = `${String(it.code ?? '')}||${String(it.description ?? '')}`;
          if (!uniq.has(key)) uniq.set(key, it);
        }

const list = Array.from(uniq.values()).sort((a, b) => {
  // DropdownItem.description is typed as string | undefined
  // so always coalesce to '' before calling string methods
  const ad = String(a.description ?? '').toLowerCase();
  const bd = String(b.description ?? '').toLowerCase();
  if (ad < bd) return -1;
  if (ad > bd) return 1;

  const ac = String(a.code ?? '').toLowerCase();
  const bc = String(b.code ?? '').toLowerCase();
  return ac < bc ? -1 : ac > bc ? 1 : 0;
});


        setVendors(list);
      } catch {
        setVendors([]);
      }
    })();
  }, [companyId]);








  const selectedVendorName = useMemo(() => {
    const hit = vendors.find(v => String(v.code) === String(vendId));
    return hit?.description || '';
  }, [vendors, vendId]);

  const begin = async (requested: 'pdf'|'excel') => {
    if (busy) return;
    if (!companyId) return alert('Missing company_id. Please re-login.');
    if (!vendId) return alert('Please select a Vendor.');

    setBusy(true);
    try {
      const { data } = await napi.post('/vendor-summary/report', {
        start_date: startDate,
        end_date: endDate,
        vend_id: vendId,
        format: requested,
        company_id: companyId,
        user_id: user?.id,
      });

      setTicket(data.ticket);
      setStatus({ status: 'queued', progress: 1, format: requested === 'pdf' ? 'pdf' : 'xls' });
      setShowModal(true);
    } catch (e: any) {
      console.error(e);
      alert(e?.response?.data?.message || 'Failed to start report. Please check inputs and try again.');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (!ticket) return;

    const poll = async () => {
      try {
        const { data } = await napi.get(`/vendor-summary/report/${ticket}/status?company_id=${companyId}`);
        setStatus(data);
        if (data.status === 'done' || data.status === 'error') {
          if (pollRef.current) window.clearInterval(pollRef.current);
          pollRef.current = null;
        }
      } catch (e: any) {
        setStatus({
          status: 'error',
          progress: 100,
          format: status?.format ?? 'pdf',
          error: e?.response?.data?.error || e?.response?.data?.message || e?.message || 'Polling failed',
        });
        if (pollRef.current) {
          window.clearInterval(pollRef.current);
          pollRef.current = null;
        }
      }
    };

    poll();
    pollRef.current = window.setInterval(poll, 1500) as unknown as number;

    return () => {
      if (pollRef.current) window.clearInterval(pollRef.current);
      pollRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ticket]);

  const download = async () => {
    if (!ticket || !status || status.status !== 'done') return;
    const res = await napi.get(
      `/vendor-summary/report/${ticket}/download?company_id=${companyId}`,
      { responseType: 'blob' }
    );
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = status.format === 'pdf' ? 'vendor_summary_report.pdf' : 'vendor_summary_report.xls';
    a.click();
    URL.revokeObjectURL(url);
  };

  const viewPdf = async () => {
    if (!ticket || !status || status.status !== 'done' || status.format !== 'pdf') return;
    const res = await napi.get(
      `/vendor-summary/report/${ticket}/view?company_id=${companyId}`,
      { responseType: 'blob' }
    );
    const url = URL.createObjectURL(res.data);
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="p-4 space-y-3">
      <div className="text-lg font-semibold">VENDOR SUMMARY REPORT</div>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div>
          <label className="block text-sm mb-1">Start Date</label>
          <input
            type="date"
            className="w-full border p-2 rounded"
            value={startDate}
            onChange={(e)=>setStartDate(e.target.value)}
          />
        </div>

        <div>
          <label className="block text-sm mb-1">End Date</label>
          <input
            type="date"
            className="w-full border p-2 rounded"
            value={endDate}
            onChange={(e)=>setEndDate(e.target.value)}
          />
        </div>

        <div className="md:col-span-2">
          <DropdownWithHeaders
         


            label="Vendor"
            value={vendId}
            onChange={(v) => setVendId(String(v))}
            // ✅ pass filtered list so results update LIVE while typing
            items={vendors}
            search={vendSearch}
            onSearchChange={setVendSearch}
            headers={['Code','Description']}
            columnWidths={['140px','520px']}
            dropdownPositionStyle={{ width: '700px' }}
            inputClassName="p-2 text-sm bg-white"
          />
          {selectedVendorName && (
            <div className="text-xs text-gray-600 mt-1">
              Selected: <b>{selectedVendorName}</b>
            </div>
          )}
        </div>
      </div>

      <div className="flex items-end gap-2">
        <button
          className={`px-4 py-2 rounded text-white ${busy?'bg-emerald-400':'bg-emerald-600 hover:bg-emerald-700'}`}
          onClick={()=>begin('pdf')}
          disabled={busy}
        >
          Generate
        </button>
        <button
          className={`px-4 py-2 rounded border ${busy?'opacity-50 cursor-not-allowed':''}`}
          onClick={()=>begin('excel')}
          disabled={busy}
        >
          EXCEL
        </button>
      </div>

      <div className="text-sm text-blue-700">
        After clicking the EXCEL button, use the modal’s Download when ready.
      </div>

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
          <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-5">
            <div className="flex items-center justify-between mb-3">
              <div className="font-semibold">
                {status?.format === 'pdf' ? 'Generating PDF…' : 'Generating Excel…'}
              </div>

              <button
                className="text-gray-500"
                onClick={() => {
                  setShowModal(false);
                  if (pollRef.current) {
                    window.clearInterval(pollRef.current);
                    pollRef.current = null;
                  }
                }}
              >
                ✕
              </button>
            </div>

            <div className="mb-2 text-sm">
              Range: <b>{startDate}</b> to <b>{endDate}</b>
              {selectedVendorName && <> <span className="mx-2">•</span> Vendor: <b>{selectedVendorName}</b> </>}
            </div>

            <Progress value={status?.progress ?? 0} />

            <div className="mt-4 flex items-center justify-between">
              <div className="text-sm">
                Status: <b>{status?.status ?? '—'}</b>
                {status?.error && <div className="text-red-600 mt-1">{status.error}</div>}
                {ticket && <div className="text-xs text-gray-500 mt-1">Ticket: {ticket}</div>}
              </div>

              <div className="flex gap-2">
                <button className="px-3 py-2 border rounded" onClick={()=>setShowModal(false)}>
                  Close
                </button>

                <button
                  className={`px-3 py-2 rounded ${status?.status==='done' ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                  disabled={status?.status!=='done'}
                  onClick={download}
                >
                  Download
                </button>

                <button
                  className={`px-3 py-2 rounded ${status?.status==='done' && status?.format==='pdf' ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                  disabled={status?.status!=='done' || status?.format!=='pdf'}
                  onClick={viewPdf}
                >
                  View
                </button>
              </div>
            </div>

          </div>
        </div>
      )}
    </div>
  );
}

function Progress({ value }: { value:number }) {
  return (
    <div>
      <div className="w-full h-3 bg-gray-200 rounded">
        <div className="h-3 rounded bg-emerald-600 transition-all" style={{ width: `${Math.max(3, value)}%` }} />
      </div>
      <div className="mt-1 text-right text-xs text-gray-600">{value}%</div>
    </div>
  );
}
