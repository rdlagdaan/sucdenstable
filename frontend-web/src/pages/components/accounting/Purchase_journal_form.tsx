// components/accounting/Purchase_journal_form.tsx
// Thin header with: Vendor, Date, Crop Year, Sugar Type, Mill Code, Explanation, Booking #, Vendor Invoice #
// Handsontable details with autocomplete (acct_code;acct_desc)

import { useEffect, useMemo, useRef, useState, useLayoutEffect } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';

import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';
import DropdownWithHeaders, { type DropdownItem } from '../../components/DropdownWithHeaders';

import {
  PrinterIcon, ChevronDownIcon, DocumentTextIcon,
  CheckCircleIcon, PlusIcon,
  ArrowDownTrayIcon, DocumentArrowDownIcon,
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

const onlyCode = (v?: string) => (v || '').split(';')[0];
const fmtDate = (d?: string) =>
  (!d ? '' : (new Date(d).toString() === 'Invalid Date' ? (d || '').split('T')[0] : new Date(d).toISOString().slice(0, 10)));

type PurchaseDetailRow = {
  id?: number;
  acct_code: string;
  acct_desc?: string;
  debit?: number;
  credit?: number;
  persisted?: boolean;
};

type TxOption = {
  id: number | string;
  cp_no: string;
  vend_id: string;
  vend_name?: string;
  purchase_date: string;
  purchase_amount: number;
  rr_no?: string;
  explanation?: string;
};

export default function Purchase_journal_form() {
  const hotRef = useRef<HotTableClass>(null);

  // header form state
  const [cpNo, setCpNo] = useState('');
  const [vendorId, setVendorId] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [purchaseDate, setPurchaseDate] = useState('');
  const [cropYear, setCropYear] = useState('');
  const [sugarType, setSugarType] = useState('');
  const [millCode, setMillCode] = useState('');
  const [explanation, setExplanation] = useState('');
  const [bookingNo, setBookingNo] = useState('');
  const [_invoiceNo, setInvoiceNo] = useState('');
  const [mainId, setMainId] = useState<number | null>(null);

  // ---- status flags (from backend) ----
  const [isCancelled, setIsCancelled] = useState(false);
  const [isBalanced, setIsBalanced] = useState(true);

  // ---- approvals (edit window) ----
  const MODULE = 'purchase_journal'; // must match backend approvals module name
  const APPROVAL_STATUS_URL = '/approvals/status'; // ✅ same endpoint used by working modules

  const [editApproval, setEditApproval] = useState<{
    exists: boolean;
    id?: number;
    status?: string;
    approved_active?: boolean;
    expires_at?: string | null;
    reason?: string | null;
  }>({ exists: false });


  // ---- approvals (one-shot actions: cancel/delete/uncancel) ----
  const [actionApproval, setActionApproval] = useState<Record<'cancel' | 'delete' | 'uncancel', ApprovalStatus>>({
    cancel: { exists: false },
    delete: { exists: false },
    uncancel: { exists: false },
  });


  const [_locked, setLocked] = useState(false);
  const [gridLocked, setGridLocked] = useState(true);

  // dropdown data
  const [vendors, setVendors] = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const [sugarTypes, setSugarTypes] = useState<DropdownItem[]>([]);
  const [cropYears, setCropYears] = useState<DropdownItem[]>([]);
  const [mills, setMills] = useState<DropdownItem[]>([]);

  // dropdown search bindings
  const [vendorSearch, setVendorSearch] = useState('');
  const [sugarSearch, setSugarSearch] = useState('');
  const [cropSearch, setCropSearch] = useState('');
  const [millSearch, setMillSearch] = useState('');

  // search transaction dropdown
  const [searchId, setSearchId] = useState<string>('');
  const [txSearch, setTxSearch] = useState<string>('');
  const [txOptions, setTxOptions] = useState<TxOption[]>([]);

  // grid
  const [tableData, setTableData] = useState<PurchaseDetailRow[]>([{ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false }]);
  const [hotEnabled, setHotEnabled] = useState(false);

  // menus / modal
  const [printOpen, setPrintOpen] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  const [showPdf, setShowPdf] = useState(false);

  // state for PBN dropdown
  const [pbns, setPbns] = useState<DropdownItem[]>([]);
  const [pbnSearch, setPbnSearch] = useState('');

  // layout helpers
  const detailsWrapRef = useRef<HTMLDivElement>(null);
  const [maxGridHeight, setMaxGridHeight] = useState<number>(600);
  useLayoutEffect(() => {
    const update = () => {
      const rect = detailsWrapRef.current?.getBoundingClientRect();
      const top = rect?.top ?? 0;
      const available = window.innerHeight - top - 140;
      setMaxGridHeight(Math.max(320, Math.floor(available)));
    };
    update();
    window.addEventListener('resize', update);
    return () => window.removeEventListener('resize', update);
  }, []);
  const ROW_H = 28, HEADER_H = 32, DD_ROOM = 240;
  const dynamicHeight = useMemo(() => {
    const rows = Math.max(tableData.length, 6);
    const desired = HEADER_H + rows * ROW_H + DD_ROOM;
    return Math.min(desired, maxGridHeight);
  }, [tableData.length, maxGridHeight]);

  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);

const companyIdParam: number | null = user?.company_id ? Number(user.company_id) : null;


  // -------------------------------
  // Dropdown data loads + mapping
  // -------------------------------
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/pj/vendors', { params: { company_id: user?.company_id } });
        const items: DropdownItem[] = (data || []).map((v: any) => ({
          code: String(v.code ?? v.vend_code ?? v.vend_id ?? ''),
          description: v.description ?? v.vend_name ?? v.vendor_name ?? '',
          label: v.label ?? v.code ?? '',
        }));
        setVendors(items);
      } catch {
        setVendors([]);
      }
    })();
  }, [user?.company_id]);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/pj/accounts', { params: { company_id: user?.company_id } });
        setAccounts(Array.isArray(data) ? data : []);
      } catch {
        setAccounts([]);
      }
    })();
  }, [user?.company_id]);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/sugar-types');
        const items: DropdownItem[] = (Array.isArray(data) ? data : []).map((r: any) => ({
          code: String(r.code ?? r.sugar_code ?? r.sugar_type ?? r.type ?? ''),
          description: String(r.description ?? r.sugar_desc ?? r.name ?? r.code ?? ''),
          label: String(r.code ?? r.sugar_code ?? r.sugar_type ?? ''),
        }));
        setSugarTypes(items);
      } catch {
        setSugarTypes([]);
      }
    })();
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/crop-years');
        const items: DropdownItem[] = (Array.isArray(data) ? data : []).map((r: any) => ({
          code: String(r.code ?? r.crop_year ?? r.year ?? ''),
          description: String(r.description ?? r.crop_year ?? r.year ?? ''),
          label: String(r.code ?? r.crop_year ?? r.year ?? ''),
        }));
        setCropYears(items);
      } catch {
        setCropYears([]);
      }
    })();
  }, []);

  const loadMills = async () => {
    try {
      const { data } = await napi.get('/pj/mills', {
        params: { company_id: user?.company_id },
      });
      setMills(Array.isArray(data) ? data : []);
    } catch {
      setMills([]);
    }
  };
  useEffect(() => { loadMills(); }, [user?.company_id]);

  useEffect(() => {
    if (mainId) refreshEditApproval();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [mainId]);

useEffect(() => {
  if (!mainId) return;

  // refresh when user comes back to the tab/window
  const onFocus = () => refreshEditApproval();
  window.addEventListener('focus', onFocus);

  // refresh when switching tabs (visibility change)
  const onVis = () => {
    if (!document.hidden) refreshEditApproval();
  };
  document.addEventListener('visibilitychange', onVis);

  // polling safety net (approval can happen anytime)
const t = window.setInterval(() => {
  refreshEditApproval();

  // ✅ if cancelled, also poll uncancel approval status
  if (isCancelled) {
    refreshActionApproval('uncancel', mainId);
  }
}, 3000);


  return () => {
    window.removeEventListener('focus', onFocus);
    document.removeEventListener('visibilitychange', onVis);
    window.clearInterval(t);
  };
}, [mainId, isCancelled]);


  // -------------------------------
  // Search transaction dropdown
  // -------------------------------
  const fetchTransactions = async () => {
    try {
      const { data } = await napi.get<TxOption[]>('/purchase/list', {
        params: { company_id: user?.company_id || '', q: txSearch || '' },
      });
      setTxOptions(Array.isArray(data) ? data : []);
    } catch {
      setTxOptions([]);
    }
  };
  useEffect(() => { fetchTransactions(); /* eslint-disable-next-line */ }, [txSearch]);

  const txDropdownItems = useMemo<DropdownItem[]>(() => {
    return (txOptions || []).map((o) => ({
      code: String(o.id),
      id: String(o.id),
      cp_no: String(o.cp_no || ''),
      vend_id: String(o.vend_id || ''),
      vend_name: String(o.vend_name || ''),
      purchase_date: fmtDate(o.purchase_date),
      purchase_amount: Number(o.purchase_amount || 0),
      rr_no: String(o.rr_no || ''),
      label: String(o.cp_no || ''),
      description: String(o.vend_name || o.vend_id || ''),
    }));
  }, [txOptions]);

  // helpers
  const acctSource = useMemo(() => accounts.map(a => `${a.acct_code};${a.acct_desc}`), [accounts]);
  const findDesc = (code: string) => accounts.find(a => a.acct_code === code)?.acct_desc || '';
  const emptyRow = (): PurchaseDetailRow => ({ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false });

  const resetForm = () => {
    setSearchId(''); setTxSearch('');
    setCpNo(''); setVendorId(''); setVendorName('');
    setPurchaseDate(''); setCropYear(''); setSugarType(''); setMillCode('');
    setExplanation(''); setBookingNo(''); setInvoiceNo('');
    setMainId(null);

    setIsCancelled(false);
    setIsBalanced(true);
    setEditApproval({ exists: false });

    setLocked(false);
    setGridLocked(false);
    setHotEnabled(false);
    setTableData([emptyRow()]);
    setPrintOpen(false);
    setDownloadOpen(false);
  };

  // -------------------------------
  // Save / Update / Cancel / Delete
  // -------------------------------
  const handleSaveMain = async () => {
    const ok = await Swal.fire({ title: 'Confirm Save?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, Save' });
    if (!ok.isConfirmed) return;

    // preflight: other unbalanced purchases
    try {
      const existsResp = await napi.get('/purchase/unbalanced-exists', { params: { company_id: user?.company_id || '' } });
      if (existsResp.data?.exists) {
        const listResp = await napi.get('/purchase/unbalanced', { params: { company_id: user?.company_id || '', limit: 20 } });
        const items = Array.isArray(listResp.data?.items) ? listResp.data.items : [];
        const htmlRows = items.map((r: any) => `
          <tr>
            <td style="padding:6px 8px">${r.cp_no}</td>
            <td style="padding:6px 8px">${r.vend_id}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_debit || 0).toLocaleString()}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_credit || 0).toLocaleString()}</td>
          </tr>`).join('');
        const html = `
          <div style="text-align:left">
            <div style="margin-bottom:8px">There are unbalanced purchase transactions. Please balance them first.</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px">
              <thead>
                <tr>
                  <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">CP #</th>
                  <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">Vendor</th>
                  <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Debit</th>
                  <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Credit</th>
                </tr>
              </thead>
              <tbody>${htmlRows || '<tr><td colspan="4" style="padding:6px 8px;color:#6b7280">No detail</td></tr>'}</tbody>
            </table>
          </div>`;
        await Swal.fire({ icon: 'warning', title: 'Unbalanced transactions found', html, confirmButtonText: 'OK' });
        return;
      }
    } catch (_) { }

    try {
      const gen = await napi.get('/purchase/generate-cp-number', { params: { company_id: user?.company_id } });
      const nextNo = gen.data?.cp_no ?? gen.data;
      setCpNo(nextNo);

      const res = await napi.post('/purchase/save-main', {
        cp_no: nextNo,
        vend_id: vendorId,
        purchase_date: purchaseDate,
        explanation,
        company_id: user?.company_id,
        user_id: user?.id,

        crop_year: cropYear,
        sugar_type: sugarType,
        mill_id: millCode,
        booking_no: bookingNo,
      });

      setMainId(res.data.id);
      setHotEnabled(true);
      setTableData([emptyRow(), emptyRow()]);
      toast.success('Main saved. You can now input details.');
      fetchTransactions();

      setLocked(true);
      setGridLocked(false);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save.');
    }
  };

const handleUpdateMain = async () => {
  if (!mainId) return;
  if (isCancelled) return toast.error('Cancelled transaction cannot be modified.');

  try {
    // 1) Require ACTIVE edit approval window (same as Cash Receipt)
    const st = await getApprovalStatus('edit', mainId);
    if (!st?.approved_active) {
      toast.error('No active edit approval window. Please request edit again.');
      setLocked(true);
      setGridLocked(true);
      return;
    }

    // 2) Save header changes
    await napi.post('/purchase/update-main', {
      id: mainId,
      cp_no: cpNo,
      vend_id: vendorId,
      purchase_date: purchaseDate,
      explanation,
      crop_year: cropYear,
      sugar_type: sugarType,
      mill_id: millCode,
      booking_no: bookingNo,
      company_id: companyIdParam,

      user_id: user?.id,
    });

    // 3) Consume/release the approval window (important)
    await releaseEditApproval(mainId);

    // 4) Reload from server so you immediately see updated values
    await loadPurchase(String(mainId));

    // 5) Force back to view mode
    setLocked(true);
    setGridLocked(true);

    toast.success('Changes saved.');
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save changes.');
  }
};


const handleSaveMainNoApproval = async () => {
  if (!mainId) return;
  if (isCancelled) return toast.error('Cancelled transaction cannot be modified.');

  try {
await napi.post('/purchase/update-main-no-approval', {
  id: mainId,
  company_id: companyIdParam,
  purchase_date: purchaseDate,
  explanation,

  // optional (only if you allowed them backend-side):
  vend_id: vendorId,
  crop_year: cropYear,
  sugar_type: sugarType,
  mill_id: millCode,
  booking_no: bookingNo,
});


    // reload so values are consistent
    await loadPurchase(String(mainId));

    // keep in view mode
    setLocked(true);
    setGridLocked(true);

    toast.success('Main saved.');
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save main.');
  }
};




// -------------------------------
// Approval helpers (same idea as Cash Receipt)
// -------------------------------
// -------------------------------
// Approval helpers (Purchase Journal) - FIXED (no stale mainId)
// -------------------------------
type ApprovalStatus = {
  exists?: boolean;
  status?: string;
  approved_active?: boolean;
  expires_at?: string | null;
  id?: number;
  reason?: string | null;
  consumed_at?: string | null; // ✅ add this line
};


const getApprovalStatus = async (action: string, recordId?: number): Promise<ApprovalStatus> => {
  const rid = recordId ?? mainId ?? undefined;
  if (!rid) return {};
  const { data } = await napi.get(APPROVAL_STATUS_URL, {

    params: {
      module: MODULE,
      record_id: rid,
      company_id: companyIdParam, // number|null
      action,
    },
  });
  return data || {};
};

const releaseEditApproval = async (recordId?: number) => {
  const rid = recordId ?? mainId ?? undefined;
  if (!rid) return;
  await napi.post('/approvals/release-by-subject', {
    module: MODULE,
    record_id: rid,
    company_id: companyIdParam, // number|null
    action: 'edit',
  });
};

const refreshEditApproval = async (recordId?: number) => {
  const rid = recordId ?? mainId ?? undefined;
  if (!rid) return;

  try {
    const { data } = await napi.get(APPROVAL_STATUS_URL, {

      params: {
        module: MODULE,
        record_id: rid,
        company_id: companyIdParam, // number|null
        action: 'edit',
      },
    });

    // if API says none exists, hard lock (view mode)
    if (!data?.exists) {
      setEditApproval({ exists: false });
      setLocked(true);
      setGridLocked(true);
      return;
    }

    const active = !!data?.approved_active;

    setEditApproval({
      exists: true,
      id: data?.id ? Number(data.id) : undefined,
      status: data?.status,
      approved_active: active,
      expires_at: data?.expires_at ?? null,
      reason: data?.reason ?? null,
    });

    // single source of truth
    setLocked(!active);
    setGridLocked(!active);
  } catch {
    setEditApproval({ exists: false });
    setLocked(true);
    setGridLocked(true);
  }
};


const statusLower = (s?: string) => String(s || '').toLowerCase();

const refreshActionApproval = async (action: 'cancel' | 'delete' | 'uncancel', recordId?: number) => {
  const rid = recordId ?? mainId ?? undefined;
  if (!rid) return;

  try {
    const data = await getApprovalStatus(action, rid);
    setActionApproval(prev => ({
      ...prev,
      [action]: {
        exists: !!data?.exists,
        status: data?.status,
        reason: data?.reason ?? null,
        consumed_at: (data as any)?.consumed_at ?? null,
      },
    }));
  } catch {
    setActionApproval(prev => ({ ...prev, [action]: { exists: false } }));
  }
};






  const approvalLabel = useMemo(() => {
    if (!mainId) return '';
    if (!editApproval?.exists) return 'Approval: none';
    const s = String(editApproval.status || '').toLowerCase();
    return `Approval: ${s || 'unknown'}`;
  }, [mainId, editApproval]);



  // -------------------------------
  // Approval Request Modals
  // -------------------------------



const requestApprovalModal = async (action: 'edit' | 'cancel' | 'delete' | 'uncancel') => {
    if (!mainId) return toast.info('Select a transaction first.');

// cancelled rules:
// - edit/cancel blocked
// - delete allowed
// - uncancel allowed (approval-based)
if (isCancelled && (action === 'edit' || action === 'cancel')) {
  return toast.error('Cancelled transaction cannot be edited or cancelled again.');
}


    // EDIT pending should not open modal (matches other modules)
    if (action === 'edit' && isEditPending) {
      return;
    }

const title =
  action === 'edit'
    ? 'Request Edit Approval'
    : action === 'cancel'
      ? 'Request approval to CANCEL this purchase?'
      : action === 'uncancel'
        ? 'Request approval to UNCANCEL this purchase?'
        : 'Request approval to DELETE this purchase?';


    const placeholder =
      action === 'edit'
        ? 'Explain why you need to edit this entry...'
        : 'Enter reason...';

    const required = true; // ✅ always required (matches Cash Receipt / Sales Journal)

    const res = await Swal.fire({
      title,
      input: 'textarea',
      inputPlaceholder: placeholder,
      inputAttributes: { 'aria-label': placeholder },
      showCancelButton: true,
      confirmButtonText: action === 'edit' ? 'OK' : 'Request',
      cancelButtonText: 'Cancel',
      preConfirm: (val) => {
        const reason = String(val || '').trim();
        if (required && !reason) {
          Swal.showValidationMessage('Reason is required.');
          return;
        }
        return reason;
      },
    });

    if (!res.isConfirmed) return;

    const reason = String(res.value || '').trim();

    try {
await napi.post('/approvals/request-edit', {
  module: MODULE,
  record_id: mainId,
  company_id: companyIdParam,
  action,
  reason,
});


      setEditApproval(prev => ({
        ...prev,
        exists: true,
        status: 'pending',
        approved_active: false,
        reason,
      }));

      setLocked(true);
      setGridLocked(true);


toast.success(
  action === 'edit'
    ? 'Edit approval request submitted.'
    : action === 'cancel'
      ? 'Cancel approval request submitted.'
      : action === 'uncancel'
        ? 'Uncancel approval request submitted.'
        : 'Delete approval request submitted.'
);


if (action === 'edit') {
  await refreshEditApproval(mainId);
} else {
  await refreshActionApproval(action as any, mainId);
}

    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to submit approval request.');
    }
  };

  // -------------------------------
  // Grid autosave
  // -------------------------------
  const isRowValid = (r: PurchaseDetailRow) => !!onlyCode(r.acct_code) && ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

  const handleAutoSave = async (row: PurchaseDetailRow, rowIndex: number) => {
    if (!mainId) return;
    if (!isRowValid(row)) return;

    const code = onlyCode(row.acct_code);
    try {
      if (!row.persisted) {
        const res = await napi.post('/purchase/save-detail', {
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
          company_id: user?.company_id,
          user_id: user?.id,
        });
        const src = (hotRef.current?.hotInstance?.getSourceData() as PurchaseDetailRow[]) || [];
        if (src[rowIndex]) src[rowIndex].persisted = true, src[rowIndex].id = res.data.detail_id;
        setTableData([...src, ...(src.find(r => !r.acct_code) ? [] : [emptyRow()])]);
      } else {
        await napi.post('/purchase/update-detail', {
          id: row.id, transaction_id: mainId, acct_code: code,
          debit: row.debit || 0, credit: row.credit || 0,
        });
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Row save failed');
    }
  };

  // -------------------------------
  // Load existing transaction
  // -------------------------------
  const normalizeCancel = (flag: any) => {
    const v = String(flag ?? '').toLowerCase();
    return v === 'y' || v === 'c' || v === 'd';
  };

  const normalizeBalanced = (v: any) => {
    if (typeof v === 'boolean') return v;
    const s = String(v ?? '').toLowerCase();
    return s === '1' || s === 'true' || s === 'y';
  };

const loadPurchase = async (selectedId: string) => {
  const { data } = await napi.get(`/purchase/${selectedId}`, { params: { company_id: user?.company_id } });

  const m = data.main ?? data;
  const cancelled = normalizeCancel(m.is_cancel);
  const balanced = normalizeBalanced(m.is_balanced);

  setIsCancelled(cancelled);
  setIsBalanced(balanced);

  setMainId(m.id);
  setCpNo(m.cp_no);
  setVendorId(String(m.vend_id || ''));
  const selV = vendors.find((v) => String(v.code) === String(m.vend_id));
  setVendorName(selV?.description || selV?.label || '');
  setPurchaseDate(fmtDate(m.purchase_date));
  setExplanation(m.explanation ?? '');
  setInvoiceNo(m.invoice_no ?? '');
  setCropYear(String(m.crop_year ?? ''));
  setSugarType(String(m.sugar_type ?? ''));
  setMillCode(String(m.mill_id ?? ''));
  setBookingNo(String(m.booking_no ?? ''));

  const details = (data.details || []).map((d: any) => ({
    id: d.id,
    acct_code: `${d.acct_code};${d.acct_desc || findDesc(d.acct_code)}`,
    acct_desc: d.acct_desc,
    debit: Number(d.debit ?? 0),
    credit: Number(d.credit ?? 0),
    persisted: true,
  }));
  setTableData(details.length ? details.concat([emptyRow()]) : [emptyRow()]);
  setHotEnabled(true);

  setLocked(true);
  setGridLocked(true);

  await refreshActionApproval('uncancel', m.id);
await refreshActionApproval('delete', m.id);
await refreshActionApproval('cancel', m.id);

};




const handleSelectTransaction = async (selectedId: string) => {
  if (!selectedId) return;
  try {
    setSearchId(selectedId);
    await loadPurchase(selectedId);
    toast.success('Transaction loaded.');
  } catch {
    toast.error('Unable to load the selected transaction.');
  }
};


  const apiBase = (napi.defaults.baseURL || '/api').replace(/\/+$/, '');

const canExport = !!mainId && !isCancelled && !!isBalanced;

// ✅ single source of truth
const editStatus = String(editApproval?.status || '').toLowerCase();
const isEditPending = !!mainId && !!editApproval?.exists && editStatus === 'pending';
const isEditApprovedActive = !!mainId && !!editApproval?.approved_active; // edit window open

const uncancelStatus = statusLower(actionApproval.uncancel?.status);
const isUncancelPending = !!mainId && !!actionApproval.uncancel?.exists && uncancelStatus === 'pending';

const deleteStatus = statusLower(actionApproval.delete?.status);
const isDeletePending = !!mainId && !!actionApproval.delete?.exists && deleteStatus === 'pending';


  // -------------------------------
  // Print / Download helpers
  // -------------------------------
  const handleOpenPdf = () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    const url = `${apiBase}/purchase/form-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id || '')}`;
    setPdfUrl(url);
    setShowPdf(true);
  };

  const handleDownloadExcel = async () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    try {
      const res = await napi.get(`/purchase/form-excel/${mainId}`, {
        responseType: 'blob',
        params: { company_id: user?.company_id || '' },
        withCredentials: true,
      });

      const ct = String(res.headers['content-type'] || '');
      if (!ct.includes('sheet') && !ct.includes('excel') && !ct.includes('octet-stream')) {
        const text = await (res.data?.text?.().catch(() => null));
        toast.error(text ? `Download failed: ${text.slice(0, 200)}…` : 'Download failed.');
        return;
      }

      const name =
        res.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1]
        || `PurchaseVoucher_${cpNo || mainId}.xlsx`;

      const blob = new Blob([res.data], {
        type: ct || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = name;
      document.body.appendChild(a); a.click(); a.remove();
      URL.revokeObjectURL(url);
      toast.success('Excel downloaded.');
    } catch (e: any) {
      const msg = e?.response?.status
        ? `HTTP ${e.response.status}: ${e.response.statusText || 'Error'}`
        : (e?.message || 'Download failed.');
      toast.error(msg);
    }
  };

  // -------------------------------
  // UI callbacks
  // -------------------------------
  const ACCT_COL_INDEX = 0;
  const afterBeginEditingOpenAll = (_row: number, col: number) => {
    if (gridLocked || col !== ACCT_COL_INDEX) return;
    const hot = hotRef.current?.hotInstance;
    const ed: any = hot?.getActiveEditor();
    if (ed?.cellProperties?.type === 'autocomplete') {
      ed.TEXTAREA.value = '';
      ed.query = '';
      ed.open();
      ed.refreshDropdown?.();
    }
  };

  const getTotals = (rows: PurchaseDetailRow[]) => {
    const valid = (rows || []).filter(r =>
      (r.acct_code && r.acct_code.trim() !== '') ||
      (Number(r.debit) || 0) > 0 ||
      (Number(r.credit) || 0) > 0
    );
    const sumD = valid.reduce((t, r) => t + (Number(r.debit) || 0), 0);
    const sumC = valid.reduce((t, r) => t + (Number(r.credit) || 0), 0);
    const balanced = Math.abs(sumD - sumC) < 0.005;
    return { sumD, sumC, balanced };
  };

  const fmtMoney = (n: number) =>
    (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const totals = useMemo(() => getTotals(tableData), [tableData]);

  // keep isBalanced in sync with current grid totals (best UX match to other modules)
  useEffect(() => {
    if (!mainId) return;
    if (isCancelled) return;
    setIsBalanced(totals.balanced);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [totals.balanced, mainId, isCancelled]);

  // Start a brand-new transaction
  const handleNew = async () => {
    const hasAnyDetail = tableData.some(
      r =>
        (r.acct_code && r.acct_code.trim() !== '') ||
        (Number(r.debit) || 0) > 0 ||
        (Number(r.credit) || 0) > 0
    );

    if (hasAnyDetail) {
      const { sumD, sumC, balanced } = getTotals(tableData);
      if (!balanced) {
        await Swal.fire({
          title: 'Transaction not balanced',
          html: `Debit <b>${fmtMoney(sumD)}</b> must equal Credit <b>${fmtMoney(sumC)}</b> before starting a new one.`,
          icon: 'error',
          confirmButtonText: 'OK',
        });
        return;
      }

      const ok = await Swal.fire({
        title: 'Start a new transaction?',
        text: 'This will clear the current form.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, New',
      });
      if (!ok.isConfirmed) return;
    } else {
      const ok = await Swal.fire({
        title: 'Start a new transaction?',
        text: 'This will clear the current form.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, New',
      });
      if (!ok.isConfirmed) return;
    }

    resetForm();
    toast.success('Form is ready for a new entry.');
  };

  const fmtMDY = (d?: string) => d ? new Date(d).toLocaleDateString() : '';

  const loadPbns = async () => {
    try {
      const params: any = {
        company_id: user?.company_id || '',
        q: pbnSearch || '',
      };
      if (vendorId) params.vend_code = vendorId;
      if (sugarType) params.sugar_type = sugarType;
      if (cropYear) params.crop_year = cropYear;

      const { data } = await napi.get('/pbn/list', { params });

      const items: DropdownItem[] = (Array.isArray(data) ? data : []).map((r: any) => ({
        code: String(r.pbn_number),
        label: String(r.pbn_number),
        description: fmtMDY(r.pbn_date),
        vendor_name: r.vendor_name,
        vend_code: r.vend_code,
        sugar_type: r.sugar_type,
        crop_year: r.crop_year,
      }));
      setPbns(items);
    } catch {
      setPbns([]);
    }
  };

  useEffect(() => { loadPbns(); },
    [user?.company_id, vendorId, sugarType, cropYear, pbnSearch]);

  // -------------------------------
  // Render
  // -------------------------------
  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-blue-50 shadow-md rounded-lg p-6 space-y-4 border border-blue-300">
        <h2 className="text-xl font-bold text-blue-800 mb-2">PURCHASE JOURNAL</h2>

        {/* Header form (thin, 2-column layout) */}
        <div className="grid grid-cols-2 gap-4">
          {/* Row 1 — Search Transaction (full width) */}
          <div className="col-span-2">
            <DropdownWithHeaders
              label="Search Transaction"
              value={searchId}
              onChange={(v) => handleSelectTransaction(v)}
              items={txDropdownItems}
              search={txSearch}
              onSearchChange={setTxSearch}
              headers={["Id", "Purchase No", "Vendor", "Date", "Amount", "Receiving #"]}
              columnKeys={["id", "cp_no", "vend_name", "purchase_date", "purchase_amount", "rr_no"]}
              columnWidths={["60px", "120px", "160px", "110px", "120px", "120px"]}
              dropdownPositionStyle={{ width: '820px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Row 2 — Vendor | Date */}
          <div>
            <DropdownWithHeaders
              label="Vendor"
              value={vendorId}
              onChange={(v) => {
                setVendorId(v);
                const sel = vendors.find((vv) => String(vv.code) === String(v));
                setVendorName(sel?.description || '');
              }}
              items={vendors}
              search={vendorSearch}
              onSearchChange={setVendorSearch}
              headers={["Code", "Description"]}
              columnWidths={["140px", "520px"]}
              dropdownPositionStyle={{ width: '700px' }}
              inputClassName="p-2 text-sm bg-white"
            />
            {vendorName && (
              <div className="mt-1 text-xs font-semibold text-gray-700">Vendor: {vendorName}</div>
            )}
          </div>

          <div>
            <label className="block mb-1">Date</label>
<input
  type="date"
  value={purchaseDate}
  disabled={isCancelled}
  onChange={(e) => setPurchaseDate(e.target.value)}
  className={`w-full border p-2 ${isCancelled ? 'bg-gray-100 text-gray-400' : 'bg-blue-100 text-blue-900'}`}
/>

          </div>

          {/* Row 3 — Crop Year | Sugar Type */}
          <div>
            <DropdownWithHeaders
              label="Crop Year"
              value={cropYear}
              onChange={setCropYear}
              items={cropYears}
              search={cropSearch}
              onSearchChange={setCropSearch}
              headers={["Code", "Description"]}
              columnWidths={["120px", "260px"]}
              dropdownPositionStyle={{ width: '420px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          <div>
            <DropdownWithHeaders
              label="Sugar Type"
              value={sugarType}
              onChange={setSugarType}
              items={sugarTypes}
              search={sugarSearch}
              onSearchChange={setSugarSearch}
              headers={["Code", "Description"]}
              columnWidths={["120px", "260px"]}
              dropdownPositionStyle={{ width: '420px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Row 4 — Mill Code | Explanation */}
          <div>
            <DropdownWithHeaders
              label="Mill Code"
              value={millCode}
              onChange={setMillCode}
              items={mills}
              search={millSearch}
              onSearchChange={setMillSearch}
              headers={["Code", "Description"]}
              columnWidths={["120px", "260px"]}
              dropdownPositionStyle={{ width: '420px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          <div>
            <label className="block mb-1">Explanation</label>
<input
  value={explanation}
  disabled={isCancelled}
  onChange={(e) => setExplanation(e.target.value)}
  className={`w-full border p-2 ${isCancelled ? 'bg-gray-100 text-gray-400' : 'bg-blue-100 text-blue-900'}`}
/>

          </div>

          {/* Row 5 — Booking # */}
          <div>
            <DropdownWithHeaders
              label="Booking #"
              value={bookingNo}
              onChange={setBookingNo}
              items={pbns}
              search={pbnSearch}
              onSearchChange={setPbnSearch}
              headers={["PBN No", "PBN Date"]}
              columnKeys={["label", "description"]}
              columnWidths={["140px", "140px"]}
              dropdownPositionStyle={{ width: '360px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>
          <div></div>
        </div>

        {/* ✅ Header summary row (Balanced pill + totals + approval) — matches other modules */}
        {mainId && (
          <div className="mt-2">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-gray-800">
              <div className="font-semibold">
                Vendor: <span className="font-normal">{vendorName || vendorId || ''}</span>
              </div>

              <div>CP No: <span className="font-semibold">{cpNo || ''}</span></div>

              <div>Total Debit: <span className="font-semibold">{fmtMoney(totals.sumD)}</span></div>
              <div>Total Credit: <span className="font-semibold">{fmtMoney(totals.sumC)}</span></div>

              {/* pill in the same row */}
              <div className="ml-2">
                {isCancelled ? (
                  <span className="px-2 py-1 rounded bg-red-100 text-red-700 border border-red-200 text-xs font-semibold">CANCELLED</span>
                ) : isBalanced ? (
                  <span className="px-2 py-1 rounded bg-green-100 text-green-700 border border-green-200 text-xs font-semibold">Balanced</span>
                ) : (
                  <span className="px-2 py-1 rounded bg-amber-100 text-amber-700 border border-amber-200 text-xs font-semibold">Unbalanced</span>
                )}
              </div>

              <div className="ml-2 text-sm text-gray-700">{approvalLabel}</div>
            </div>

{isEditPending && (
  <div className="text-sm text-amber-700 mt-1">
    Edit approval is pending — Reason: {editApproval?.reason || '—'}
  </div>
)}

          </div>
        )}

        {/* Actions */}
{/* Actions */}
<div className="flex gap-2 mt-3 items-center">
  {!mainId ? (
    <button
      onClick={handleSaveMain}
      className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
    >
      <CheckCircleIcon className="h-5 w-5" />
      Save
    </button>
  ) : isCancelled ? (
    <>
      {/* ✅ CANCELLED: show only Uncancel + Delete (Sales Journal behavior) */}
      <button
        type="button"
        onClick={() => requestApprovalModal('uncancel')}
        disabled={isUncancelPending}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded text-white ${
          isUncancelPending ? 'bg-gray-400 cursor-not-allowed' : 'bg-gray-700 hover:bg-gray-800'
        }`}
      >
        {isUncancelPending ? 'Uncancel Request Pending' : 'Uncancel'}
      </button>

      <button
        type="button"
        onClick={() => requestApprovalModal('delete')}
        disabled={isDeletePending}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded text-white ${
          isDeletePending ? 'bg-red-300 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700'
        }`}
      >
        {isDeletePending ? 'Delete Request Pending' : 'Delete'}
      </button>
    </>
  ) : (
    <>
      {/* ✅ NORMAL (not cancelled): keep your existing buttons */}
      {isEditApprovedActive ? (
        <button
          type="button"
          onClick={handleUpdateMain}
          className="inline-flex items-center gap-2 px-4 py-2 rounded text-white bg-green-600 hover:bg-green-700"
        >
          Save Changes
        </button>
      ) : (
        <button
          type="button"
          onClick={() => requestApprovalModal('edit')}
          disabled={isEditPending}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded text-white ${
            isEditPending ? 'bg-purple-300 cursor-not-allowed opacity-60' : 'bg-purple-700 hover:bg-purple-800'
          }`}
        >
          {isEditPending ? 'Edit Request Pending' : 'Request to Edit'}
        </button>
      )}

      <button
        type="button"
        onClick={() => requestApprovalModal('cancel')}
        className="inline-flex items-center gap-2 px-4 py-2 rounded text-white bg-amber-500 hover:bg-amber-600"
      >
        Cancel
      </button>

      <button
        type="button"
        onClick={() => requestApprovalModal('delete')}
        className="inline-flex items-center gap-2 px-4 py-2 rounded text-white bg-red-600 hover:bg-red-700"
      >
        Delete
      </button>
{/* ✅ Save Main (same handler as Save Changes) — only shown when NOT cancelled */}
<button
  type="button"
  onClick={handleSaveMainNoApproval}
  className="inline-flex items-center gap-2 px-4 py-2 rounded text-white bg-slate-600 hover:bg-slate-700"
>
  Save Main
</button>




    </>
  )}
</div>

      </div>

      {/* DETAILS */}
      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-4">Details</h2>
        {hotEnabled && (
          <HotTable
            className="hot-enhanced"
            ref={hotRef}
            data={tableData}
            colHeaders={["Account Code", "Account Description", "Debit", "Credit"]}
            columns={[
              {
                data: 'acct_code',
                type: 'autocomplete',
                source: (q: string, cb: (s: string[]) => void) => {
                  const list = acctSource;
                  if (!q) return cb(list);
                  const term = String(q).toLowerCase();
                  cb(list.filter(t => t.toLowerCase().includes(term)));
                },
                filter: true,
                strict: true,
                allowInvalid: false,
                visibleRows: 12,
                readOnly: gridLocked,
                renderer: (inst, td, row, col, prop, value, cellProps) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(inst, td, row, col, prop, display, cellProps);
                },
              },
              { data: 'acct_desc', readOnly: true },
              { data: 'debit', type: 'numeric', numericFormat: { pattern: '0,0.00' }, readOnly: gridLocked },
              { data: 'credit', type: 'numeric', numericFormat: { pattern: '0,0.00' }, readOnly: gridLocked },
            ]}
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot) return;

              if (source === 'edit') {
                changes.forEach(([rowIndex, prop, _oldVal, newVal]) => {
                  if (prop === 'acct_code') {
                    const full = String(newVal || '');
                    const code = onlyCode(full);
                    hot.setDataAtRowProp(rowIndex, 'acct_desc', findDesc(code));

                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as PurchaseDetailRow;
                    const payload: PurchaseDetailRow = {
                      id: rowObj.id,
                      acct_code: full,
                      acct_desc: findDesc(code),
                      debit: Number(rowObj.debit || 0),
                      credit: Number(rowObj.credit || 0),
                      persisted: !!rowObj.persisted,
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }

                  if (prop === 'debit' || prop === 'credit') {
                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as PurchaseDetailRow;
                    const payload: PurchaseDetailRow = {
                      ...rowObj,
                      acct_code: rowObj.acct_code,
                      debit: Number(prop === 'debit' ? newVal : rowObj.debit || 0),
                      credit: Number(prop === 'credit' ? newVal : rowObj.credit || 0),
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }
                });

                requestAnimationFrame(() => {
                  const src = hot.getSourceData() as PurchaseDetailRow[];
                  if (!src.find(r => !r.acct_code)) src.push(emptyRow());
                  setTableData([...src]);
                });
              }
            }}
            contextMenu={{
              items: {
                'remove_row': {
                  name: '🗑️ Remove row',
                  callback: async (_key, selection) => {
                    const hot = hotRef.current?.hotInstance;
                    const rowIndex = selection[0].start.row;
                    const src = (hot?.getSourceData() as PurchaseDetailRow[]) || [];
                    const row = src[rowIndex];
                    if (!row?.id) { src.splice(rowIndex, 1); setTableData([...src]); return; }
                    const ok = await Swal.fire({ title: 'Delete this line?', icon: 'warning', showCancelButton: true });
                    if (!ok.isConfirmed) return;
                    await napi.post('/purchase/delete-detail', { id: row.id, transaction_id: mainId });
                    src.splice(rowIndex, 1);
                    setTableData([...src]);
                    toast.success('Row deleted');
                  }
                }
              }
            }}
            manualColumnResize
            stretchH="all"
            height={dynamicHeight}
            rowHeaders
            licenseKey="non-commercial-and-evaluation"
          />
        )}
      </div>

      {/* Download / Print / New */}
      <div className="flex gap-3 mt-4 items-center">
        {/* DOWNLOAD */}
        <div className="relative inline-block">
          <button
            type="button"
            disabled={!canExport}
            onClick={() => {
              if (!canExport) return;
              setPrintOpen(false);
              setDownloadOpen(v => !v);
            }}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${canExport
              ? 'bg-white text-blue-700 border-blue-300 hover:bg-blue-50'
              : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
              }`}
          >
            <ArrowDownTrayIcon className={`h-5 w-5 ${canExport ? 'text-blue-600' : 'text-gray-400'}`} />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {canExport && downloadOpen && (
            <div className="absolute left-0 top-full z-50" onClick={(e) => e.stopPropagation()}>
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={() => { setDownloadOpen(false); handleDownloadExcel(); }}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-blue-50"
                >
                  <DocumentArrowDownIcon className="h-5 w-5 text-blue-600" />
                  <span className="truncate">Purchase Voucher – Excel</span>
                  <span className="ml-auto text-[10px] font-semibold">XLSX</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* PRINT (toggle on click; dropdown only if canExport) */}
        <div className="relative inline-block">
          <button
            type="button"
            disabled={!canExport}
            onClick={() => {
              if (!canExport) return;
              setDownloadOpen(false);
              setPrintOpen(v => !v);
            }}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${canExport
              ? 'bg-white text-gray-700 hover:bg-gray-50'
              : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
              }`}
            title={
              !mainId ? 'Select or save a transaction first.'
                : isCancelled ? 'Cancelled transactions cannot be printed.'
                  : !isBalanced ? 'Unbalanced transactions cannot be printed.'
                    : ''
            }
          >
            <PrinterIcon className={`h-5 w-5 ${canExport ? '' : 'text-gray-400'}`} />
            <span>Print</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {canExport && printOpen && (
            <div className="absolute left-0 top-full z-50" onClick={(e) => e.stopPropagation()}>
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={() => { setPrintOpen(false); handleOpenPdf(); }}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Purchase Voucher – PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">PDF</span>
                </button>

                <button
                  type="button"
                  onClick={() => {
                    setPrintOpen(false);
                    if (!mainId) return toast.info('Select or save a transaction.');
                    window.open(
                      `${apiBase}/purchase/check-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id || '')}`,
                      '_blank',
                      'noopener'
                    );
                  }}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Print Check – PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">PDF</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* NEW */}
        <button
          type="button"
          onClick={handleNew}
          className="inline-flex items-center gap-2 rounded border px-4 py-2 bg-indigo-600 text-white hover:bg-indigo-700"
          title="Start a new transaction"
        >
          <PlusIcon className="h-5 w-5" />
          <span>New</span>
        </button>
      </div>

      {/* PDF modal */}
      {showPdf && (
        <div className="fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center">
          <div className="bg-white rounded-lg shadow-xl w-[90vw] h-[85vh] relative">
            <button
              onClick={() => setShowPdf(false)}
              className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200"
              aria-label="Close"
            >
              ✕
            </button>
            <div className="h-full w-full pt-8">
              <iframe title="Purchase Voucher PDF" src={pdfUrl} className="w-full h-full" style={{ border: 'none' }} />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
