import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import napi from '../utils/axiosnapi';
import { toast } from 'react-toastify';

type GateOpts = {
  subjectType: string;
  recordId: number | string | null;
  companyId?: number | string | null;   // <—
  onUnlock?: () => void;
};

type StatusPayload = {
  exists: boolean;
  id?: number;
  status?: 'pending'|'approved'|'rejected';
  reason?: string | null;
  approved_at?: string | null;
  expires_at?: string | null;
  approved_active?: boolean;
};

export function useApprovalGate({ subjectType, recordId, companyId, onUnlock }: GateOpts) {
  const [status, setStatus] = useState<StatusPayload | null>(null);

  const pollRef      = useRef<number | null>(null);
  const inFlightRef  = useRef(false);
  const unlockRef    = useRef<(() => void) | undefined>(onUnlock);

  // keep latest unlock callback without re-wiring effects
  useEffect(() => { unlockRef.current = onUnlock; }, [onUnlock]);

  const clearPoll = useCallback(() => {
    if (pollRef.current) {
      window.clearTimeout(pollRef.current);
      pollRef.current = null;
    }
  }, []);

  const fetchStatus = useCallback(async () => {
    if (!subjectType || !recordId) {
      setStatus(null);
      clearPoll();
      return;
    }
    if (inFlightRef.current) return;       // prevent overlapping calls
    inFlightRef.current = true;

    try {
      const params: any = { module: subjectType, record_id: recordId };
      if (companyId != null && companyId !== '') params.company_id = companyId;
      const { data } = await napi.get('/api/approvals/status', { params });

      
      const s = data || { exists: false };
      setStatus(s);

      // Decide (re)polling
      clearPoll();

      if (!s.exists) {
        // nothing to poll
        return;
      }

      if (s.status === 'pending') {
        // light poll while pending
        pollRef.current = window.setTimeout(fetchStatus, 8000);
        return;
      }

      if (s.status === 'approved' && s.approved_active) {
        // unlock and schedule a single wake-up at expiry
        unlockRef.current?.();
        if (s.expires_at) {
          const msLeft = Math.max(1000, new Date(s.expires_at).getTime() - Date.now());
          pollRef.current = window.setTimeout(fetchStatus, msLeft + 1000);
        }
        return;
      }
      // rejected or expired -> no polling
    } catch {
      // stay quiet; next user action will retry
    } finally {
      inFlightRef.current = false;
    }
  }, [subjectType, recordId, clearPoll]);

  // kick when subject/record changes; cleanup on unmount
  useEffect(() => {
    clearPoll();
    fetchStatus();
    return clearPoll;
  }, [fetchStatus, clearPoll]);

// before
// const requestEdit = useCallback(async () => {
const requestEdit = useCallback(async (reason: string = '') => {
  if (!recordId) return toast.info('Save or select a record first.');
  try {
    await napi.post('/api/approvals/request', {
      module: subjectType,
      record_id: recordId,
      company_id: companyId ?? undefined,
      action: 'edit',
      reason,                      // <— pass reason
    });
    toast.success('Request sent. Awaiting approval…');
    clearPoll();
    pollRef.current = window.setTimeout(fetchStatus, 1500);
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Request failed');
  }
}, [subjectType, recordId, fetchStatus, clearPoll]);


const releaseNow = useCallback(async () => {
  if (!recordId) return;
  try {
    await napi.post('/api/approvals/release', {
      module: subjectType,
      record_id: recordId,
      company_id: companyId ?? undefined,
    });
  } catch {
    /* swallow */
  }
}, [recordId, subjectType, companyId]);




  const Banner = useMemo(() => {
    const s = status;
    if (!recordId) return () => null;
    return function BannerInner() {
      if (!s?.exists) return null;
      if (s.status === 'pending') {
        return <div className="mt-3 rounded border border-amber-300 bg-amber-50 text-amber-800 px-3 py-2">Awaiting supervisor approval to edit…</div>;
      }
      if (s.status === 'approved' && s.approved_active) {
        return <div className="mt-3 rounded border border-emerald-300 bg-emerald-50 text-emerald-800 px-3 py-2">Approved. Edit window active until {s.expires_at ? new Date(s.expires_at).toLocaleString() : '—'}.</div>;
      }
      if (s.status === 'approved' && !s.approved_active) {
        return <div className="mt-3 rounded border border-gray-300 bg-gray-50 text-gray-700 px-3 py-2">Approval expired. Please request again.</div>;
      }
      if (s.status === 'rejected') {
        return <div className="mt-3 rounded border border-red-300 bg-red-50 text-red-700 px-3 py-2">Request rejected{s.reason ? `: ${s.reason}` : ''}.</div>;
      }
      return null;
    };
  }, [status, recordId]);

  return { requestEdit, releaseNow, Banner, status };
}
