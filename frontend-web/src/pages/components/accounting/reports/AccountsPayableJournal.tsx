// src/pages/components/accounting/AccountsPayableJournal.tsx
import { useEffect, useRef, useState } from 'react';
import dayjs from 'dayjs';
import napi from '../../../../utils/axiosnapi';

type Status = {
  status: 'queued' | 'running' | 'done' | 'error';
  progress: number;
  format: 'pdf' | 'xls';
  file?: string | null;
  error?: string | null;
};

const POLL_MS = 1500;

export default function AccountsPayableJournal() {
  const [startDate, setStartDate] = useState(dayjs().startOf('month').format('YYYY-MM-DD'));
  const [endDate,   setEndDate]   = useState(dayjs().endOf('month').format('YYYY-MM-DD'));
  const [ticket, setTicket]       = useState<string|null>(null);
  const [status, setStatus]       = useState<Status|null>(null);
  const [showModal, setShowModal] = useState(false);
  const [busy, setBusy]           = useState(false);
  const pollRef = useRef<number | null>(null);

  const friendly = () =>
    `AccountsPayableJournal_${startDate}_to_${endDate}.${status?.format === 'pdf' ? 'pdf' : 'xls'}`;

  async function begin(requested: 'pdf'|'excel') {
    if (busy) return;
    setBusy(true);
    try {
      const { data } = await napi.post('/accounts-payable/report', {
        start_date: startDate,
        end_date: endDate,
        format: requested, // controller will normalize 'excel' → 'xls'
      });
      setTicket(data.ticket);
      setStatus({ status: 'queued', progress: 1, format: requested === 'pdf' ? 'pdf' : 'xls' } as Status);
      setShowModal(true);
    } catch (e) {
      console.error(e);
      alert('Failed to start report. Please check inputs and try again.');
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    if (!ticket) return;
    const poll = async () => {
      try {
        const { data } = await napi.get<Status>(`/accounts-payable/report/${ticket}/status`);
        setStatus(data);
        if (data.status === 'done' || data.status === 'error') {
          if (pollRef.current) window.clearInterval(pollRef.current);
          pollRef.current = null;
        }
      } catch {/* ignore transient */}
    };
    poll();
    pollRef.current = window.setInterval(poll, POLL_MS) as unknown as number;
    return () => { if (pollRef.current) window.clearInterval(pollRef.current); };
  }, [ticket]);

  const download = async () => {
    if (!ticket || !status || status.status !== 'done') return;
    const res = await napi.get(`/accounts-payable/report/${ticket}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = friendly();
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  const viewPdf = async () => {
    if (!ticket || !status || status.status !== 'done' || status.format !== 'pdf') return;
    const res = await napi.get(`/accounts-payable/report/${ticket}/view`, { responseType: 'blob' });
    const url = URL.createObjectURL(res.data);
    window.open(url, '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="p-4 space-y-3">
      <div className="text-lg font-semibold">ACCOUNTS PAYABLE JOURNAL</div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label className="block text-sm mb-1">Start Date</label>
          <input type="date" className="w-full border p-2 rounded"
                 value={startDate} onChange={(e)=>setStartDate(e.target.value)} />
        </div>
        <div>
          <label className="block text-sm mb-1">End Date</label>
          <input type="date" className="w-full border p-2 rounded"
                 value={endDate} onChange={(e)=>setEndDate(e.target.value)} />
        </div>
        <div className="flex items-end gap-2">
          <button className={`px-4 py-2 rounded text-white ${busy ? 'bg-emerald-400' : 'bg-emerald-600 hover:bg-emerald-700'}`}
                  onClick={()=>begin('pdf')} disabled={busy}>Generate</button>
          <button className={`px-4 py-2 rounded border ${busy ? 'opacity-50 cursor-not-allowed' : ''}`}
                  onClick={()=>begin('excel')} disabled={busy}>EXCEL</button>
        </div>
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
              <button className="text-gray-500" onClick={()=>setShowModal(false)}>✕</button>
            </div>

            <div className="mb-2 text-sm">
              Range: <b>{startDate}</b> to <b>{endDate}</b>
            </div>

            <Progress value={status?.progress ?? 0} />

            <div className="mt-4 flex items-center justify-between">
              <div className="text-sm">
                Status: <b>{status?.status ?? '—'}</b>
                {status?.error && <div className="text-red-600 mt-1">{status.error}</div>}
                {ticket && <div className="text-xs text-gray-500 mt-1">Ticket: {ticket}</div>}
              </div>
              <div className="flex gap-2">
                <button className="px-3 py-2 border rounded" onClick={()=>setShowModal(false)}>Close</button>
                <button className={`px-3 py-2 rounded ${status?.status==='done' ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                        disabled={status?.status!=='done'} onClick={download}>Download</button>
                <button className={`px-3 py-2 rounded ${status?.status==='done' && status?.format==='pdf' ? 'bg-indigo-600 text-white' : 'bg-gray-300 text-gray-600 cursor-not-allowed'}`}
                        disabled={status?.status!=='done' || status?.format!=='pdf'} onClick={viewPdf}>View</button>
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
