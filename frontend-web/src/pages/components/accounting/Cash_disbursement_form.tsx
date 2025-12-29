import { useEffect, useMemo, useRef, useState, useLayoutEffect, useCallback } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.css';

import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';

import DropdownWithHeaders from '../../components/DropdownWithHeaders';
import type { DropdownItem } from '../../components/DropdownWithHeaders';

import {
  PrinterIcon, ChevronDownIcon, DocumentTextIcon,
  CheckCircleIcon, PlusIcon,
  ArrowDownTrayIcon, DocumentArrowDownIcon,
  XMarkIcon, ArrowUturnLeftIcon, TrashIcon,
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

/* ---------------- helpers ---------------- */
type Totals =
  Partial<Record<'sum_debit' | 'sum_credit' | 'debit' | 'credit' | 'total_debit' | 'total_credit', number>>;

const onlyCode = (v?: string) => (v || '').split(';')[0];

function chunkToWords(n: number) {
  const ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
  const tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
  let words = '';
  const hundred = Math.floor(n / 100);
  const rest = n % 100;
  if (hundred) words += ones[hundred] + ' hundred' + (rest ? ' ' : '');
  if (rest) {
    if (rest < 20) words += ones[rest];
    else {
      const t = Math.floor(rest / 10);
      const o = rest % 10;
      words += tens[t] + (o ? '-' + ones[o] : '');
    }
  }
  return words;
}
function numberToWords(n: number) {
  if (n === 0) return 'zero';
  const scales = ['', ' thousand', ' million', ' billion', ' trillion'];
  let words = '';
  let scale = 0;
  while (n > 0) {
    const chunk = n % 1000;
    if (chunk) {
      const chunkWords = chunkToWords(chunk) + scales[scale];
      words = chunkWords + (words ? ' ' + words : '');
    }
    n = Math.floor(n / 1000);
    scale++;
  }
  return words;
}
function pesoWords(amount: number) {
  const [intStr, fracStr] = Number(amount || 0).toFixed(2).split('.');
  const intPart = parseInt(intStr, 10) || 0;
  const cents = parseInt(fracStr, 10) || 0;
  const words = numberToWords(intPart).toUpperCase();
  const tail = cents === 0 ? ' PESOS ONLY' : ` PESOS AND ${String(cents).padStart(2, '0')}/100 ONLY`;
  return `*** ${words}${tail} ***`;
}
const fmtMoney = (n: number) =>
  (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const msToMin = (ms: number) => Math.max(0, Math.floor(ms / 60000));

/* ---------------- types ---------------- */
type DetailRow = {
  id?: number;
  acct_code: string;      // "code;desc" in the grid
  acct_desc?: string;
  debit?: number;
  credit?: number;
  workstation_id?: string; // "BANK" row locked
  persisted?: boolean;
};

type DisbursementListRow = {
  id: number | string;
  cd_no: string;
  vend_id: string;
  vend_name?: string;
  disburse_date: string;
  disburse_amount: number;
  bank_id?: string;
  check_ref_no?: string;
  is_cancel?: 'y' | 'n';
};

type ApprovalStatus = {
  exists: boolean;
  id?: number;
  status?: 'pending' | 'approved' | 'rejected' | 'expired' | string;
  reason?: string | null;
  expires_at?: string | null;
  approved_active?: boolean;
  action?: string | null;
};

/* ---------------- component ---------------- */

export default function CashDisbursementForm() {
  const hotRef = useRef<HotTableClass>(null);

  // header
  const [mainId, setMainId] = useState<number | null>(null);
  const [cdNo, setCdNo] = useState('');
  const [vendId, setVendId] = useState('');
  const [vendName, setVendName] = useState('');
  const [disburseDate, setDisburseDate] = useState<string>(() => new Date().toISOString().slice(0, 10));
  const [disburseAmount, setDisburseAmount] = useState<number>(0);
  const [amountWords, setAmountWords] = useState<string>('*** ZERO PESOS ONLY ***');
  const [bankId, setBankId] = useState('');
  const [payMethodId, setPayMethodId] = useState('');
  const [explanation, setExplanation] = useState('');
  const [checkRefNo, setCheckRefNo] = useState('');
  const [_locked, setLocked] = useState(false);       // legacy (we will still set it)
  const [_gridLocked, setGridLocked] = useState(true); // grid lock (separate)
  const [isCancelled, setIsCancelled] = useState(false);

  const isSaved = useMemo(() => mainId != null, [mainId]);

  // dropdown data
  const [vendors, setVendors] = useState<DropdownItem[]>([]);
  const [banks, setBanks] = useState<DropdownItem[]>([]);
  const [payMethods, setPayMethods] = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const acctSource = useMemo(() => accounts.map(a => `${a.acct_code};${a.acct_desc}`), [accounts]);
  const findDesc = (code: string) => accounts.find(a => a.acct_code === code)?.acct_desc || '';

  const [vendSearch, setVendSearch] = useState('');
  const [bankSearch, setBankSearch] = useState('');
  const [paySearch, setPaySearch] = useState('');



  // search transaction
  const [searchId, setSearchId] = useState('');
  const [txSearch, setTxSearch] = useState('');
  const [txOptions, setTxOptions] = useState<DisbursementListRow[]>([]);
  const txDropdownItems = useMemo<DropdownItem[]>(() =>
    (txOptions || []).map(o => ({
      code: String(o.id),
      cd_no: o.cd_no,
      vend_name: o.vend_name || '',
      disburse_date: (o.disburse_date || '').slice(0, 10),
      disburse_amount: fmtMoney(Number(o.disburse_amount || 0)),
      check_ref_no: o.check_ref_no || '',
      label: o.cd_no,
      description: o.vend_name || o.vend_id,
    })), [txOptions]);

  // grid
  const [tableData, setTableData] = useState<DetailRow[]>([{ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false }]);
  const emptyRow = (): DetailRow => ({ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false });

  // layout / height
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
  const ROW_H = 28, HEADER_H = 32, DROPDOWN_ROOM = 240;
  const dynamicHeight = useMemo(() => {
    const rows = Math.max(tableData.length, 6);
    const desired = HEADER_H + rows * ROW_H + DROPDOWN_ROOM;
    return Math.min(desired, maxGridHeight);
  }, [tableData.length, maxGridHeight]);

  // user/company from localStorage
  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);
  const companyId = user?.company_id;

  // amount words follow header amount
  useEffect(() => { setAmountWords(pesoWords(disburseAmount)); }, [disburseAmount]);

  /* ---------------- approval (EDIT/CANCEL/DELETE/UNCANCEL) ---------------- */
  const MODULE = 'cash_disbursement'; // must match what backend will use later

  const [apprEdit, setApprEdit] = useState<ApprovalStatus>({ exists: false });
  const [apprCancel, setApprCancel] = useState<ApprovalStatus>({ exists: false });
  const [apprDelete, setApprDelete] = useState<ApprovalStatus>({ exists: false });
  const [apprUncancel, setApprUncancel] = useState<ApprovalStatus>({ exists: false });

const fetchApproval = useCallback(
  async (recordId: number, action: string): Promise<ApprovalStatus> => {
    if (!recordId) return { exists: false };
    try {
      const { data } = await napi.get('/approvals/status', {
        params: {
          module: MODULE,
          record_id: recordId,          // ✅ use argument, NOT mainId state
          company_id: companyId ?? '',
          action,
        },
      });
      return (data || { exists: false }) as ApprovalStatus;
    } catch {
      return { exists: false };
    }
  },
  [companyId]
);

const refreshApprovals = useCallback(
  async (recordId?: number | null) => {
    const rid = recordId ?? mainId;

    if (!rid) {
      setApprEdit({ exists: false });
      setApprCancel({ exists: false });
      setApprDelete({ exists: false });
      setApprUncancel({ exists: false });
      return;
    }

    const [e, c, d, u] = await Promise.all([
      fetchApproval(rid, 'edit'),
      fetchApproval(rid, 'CANCEL'),
      fetchApproval(rid, 'DELETE'),
      fetchApproval(rid, 'UNCANCEL'),
    ]);

    setApprEdit(e);
    setApprCancel(c);
    setApprDelete(d);
    setApprUncancel(u);
  },
  [mainId, fetchApproval]
);

  const approvalText = (a: ApprovalStatus) => {
    if (!a?.exists) return 'none';
    const st = String(a.status || '').toLowerCase();
    if (st === 'pending') return 'pending';
    if (st === 'rejected') return 'rejected';
    if (st === 'expired') return 'expired';
    if (st === 'approved') {
      if (a.approved_active && a.expires_at) {
        const mins = msToMin(new Date(a.expires_at).getTime() - Date.now());
        return `approved (active – ${mins} min left)`;
      }
      return 'approved';
    }
    return st || 'unknown';
  };

  const isEditApprovedActive = !!apprEdit?.approved_active;
  const isBalanced = useMemo(() => {
    const sumD = Number((tableData || []).reduce((a, r) => a + Number(r.debit || 0), 0));
    const sumC = Number((tableData || []).reduce((a, r) => a + Number(r.credit || 0), 0));
    return Math.abs(sumD - sumC) < 0.005;
  }, [tableData]);

  const totalDebit = useMemo(() => (tableData || []).reduce((a, r) => a + Number(r.debit || 0), 0), [tableData]);
  const totalCredit = useMemo(() => (tableData || []).reduce((a, r) => a + Number(r.credit || 0), 0), [tableData]);

  const canEditNow = isSaved && !isCancelled && isEditApprovedActive;

  // enforce locks whenever state changes
  useEffect(() => {
    const shouldLock = !(canEditNow);
    setLocked(shouldLock);
    setGridLocked(shouldLock);
    hotRef.current?.hotInstance?.updateSettings?.({ readOnly: shouldLock });
  }, [canEditNow]);

  const requestApprovalWithReason = async (action: 'edit' | 'CANCEL' | 'DELETE' | 'UNCANCEL', title: string) => {
    if (!mainId) return toast.info('Save or select a disbursement first.');

    const res = await Swal.fire({
      title,
      input: 'textarea',
      inputLabel: 'Reason (required)',
      inputPlaceholder: 'Enter reason...',
      inputAttributes: { 'aria-label': 'Reason' },
      showCancelButton: true,
      confirmButtonText: action === 'edit' ? 'OK' : 'Request',
      preConfirm: (val) => {
        const v = String(val || '').trim();
        if (!v) {
          Swal.showValidationMessage('Reason is required');
          return;
        }
        return v;
      },
    });

    if (!res.isConfirmed) return;

    const reason = String(res.value || '').trim();

    try {
      await napi.post('/approvals/request-edit', {
        module: MODULE,
        record_id: mainId,
        company_id: companyId,
        action,
        reason,
      });

      toast.success('Approval request submitted.');
      await refreshApprovals();

      // waiting mode: lock everything
      setLocked(true);
      setGridLocked(true);
      hotRef.current?.hotInstance?.updateSettings?.({ readOnly: true });
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to submit approval request.');
    }
  };

const handleSaveChanges = async () => {
  if (!mainId) return toast.info('Save or select a disbursement first.');
  if (isCancelled) return toast.error('Cannot save changes on cancelled transaction.');

  try {
    const { data } = await napi.post('/cash-disbursement/update-main', {
      id: mainId,
      vend_id: vendId,
      disburse_date: disburseDate,
      pay_method: payMethodId,
      bank_id: bankId,
      check_ref_no: checkRefNo,
      explanation,
      amount_in_words: pesoWords(disburseAmount).replace(/^\*\*\*\s|\s\*\*\*$/g, ''),
      company_id: companyId,
      user_id: user?.id,
    });

    // ✅ if backend really saved, these MUST be the new values
    if (data?.main) {
      const m = data.main;
      setVendId(String(m.vend_id || ''));
      setDisburseDate((m.disburse_date || '').slice(0, 10));
      setBankId(String(m.bank_id || ''));
      setPayMethodId(String(m.pay_method || ''));
      setCheckRefNo(m.check_ref_no || '');
      setExplanation(m.explanation || '');
    }

    await napi.post('/approvals/release', {
      module: MODULE,
      record_id: mainId,
      company_id: companyId,
      action: 'edit',
    });

    // ✅ still reload, but now you already confirmed what DB returned
    await loadDisbursement(mainId);

    setLocked(true);
    setGridLocked(true);
    hotRef.current?.hotInstance?.updateSettings?.({ readOnly: true });

    toast.success('Changes saved.');
    await refreshApprovals(mainId);
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save changes.');
  }
};


const handleSaveMainNoApproval = async () => {
  if (!mainId) return toast.info('Save or select a disbursement first.');
  if (isCancelled) return toast.error('Cancelled transaction cannot be modified.');

  const ok = await Swal.fire({
    title: 'Save main header changes?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Save Main',
  });
  if (!ok.isConfirmed) return;

  try {
    await napi.post('/cash-disbursement/update-main-no-approval', {
      id: mainId,
      vend_id: vendId,
      disburse_date: disburseDate,
      pay_method: payMethodId,
      bank_id: bankId,
      check_ref_no: checkRefNo,
      explanation,
      amount_in_words: pesoWords(disburseAmount).replace(/^\*\*\*\s|\s\*\*\*$/g, ''),
      company_id: companyId,
      user_id: user?.id,
    });

    // reload to confirm server saved
    await loadDisbursement(mainId);

    // force view mode (details still approval-locked)
    setLocked(true);
    setGridLocked(true);
    hotRef.current?.hotInstance?.updateSettings?.({ readOnly: true });

    toast.success('Main saved.');
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save main.');
  }
};





  /* --------- fetch dropdowns --------- */
  useEffect(() => {
    (async () => {
      try {
        const [vendRes, bankRes, pmRes, acctRes] = await Promise.all([
          napi.get('/cd/vendors', { params: { company_id: companyId } }),
          napi.get('/cd/banks', { params: { company_id: companyId } }),
          napi.get('/cd/payment-methods'),
          napi.get('/cd/accounts', { params: { company_id: companyId } }),
        ]);

        setVendors((vendRes.data || []).map((v: any) => ({
          code: String(v.vend_id ?? v.code),
          description: v.vend_name ?? v.description ?? '',
        })));

        setBanks((bankRes.data || []).map((b: any) => ({
          code: String(b.bank_id ?? b.code),
          description: b.bank_name ?? b.description ?? '',
        })));

        setPayMethods((pmRes.data || []).map((p: any) => ({
          code: String(p.pay_method_id ?? p.code),
          description: p.pay_method ?? p.description ?? '',
        })));

        setAccounts(Array.isArray(acctRes.data) ? acctRes.data : []);
      } catch {
        setVendors([]); setBanks([]); setPayMethods([]); setAccounts([]);
      }
    })();
  }, [companyId]);

  /* --------- search list --------- */
  const fetchTransactions = useCallback(async () => {
    try {
      const { data } = await napi.get<DisbursementListRow[]>('/cash-disbursement/list', {
        params: { company_id: companyId || '', q: txSearch || '' },
      });
      setTxOptions(Array.isArray(data) ? data : []);
    } catch {
      setTxOptions([]);
    }
  }, [companyId, txSearch]);
  useEffect(() => { fetchTransactions(); }, [fetchTransactions]);

  const ensureAccountInSource = useCallback((code: string, desc?: string) => {
    if (!code) return;
    setAccounts(prev => {
      if (prev.some(a => a.acct_code === code)) return prev;
      return [...prev, { acct_code: code, acct_desc: desc || '' }];
    });
  }, []);

  /* --------- load one disbursement --------- */
  const loadDisbursement = useCallback(async (rid: string | number) => {
    try {
      const { data } = await napi.get(`/cash-disbursement/${rid}`, {
        params: { company_id: companyId },
      });
      const m = data.main ?? data;

      setMainId(m.id);
      setCdNo(m.cd_no || '');
      setVendId(String(m.vend_id || ''));
      const sel = vendors.find(v => String(v.code) === String(m.vend_id));
      setVendName(sel?.description || '');

      setDisburseDate((m.disburse_date || '').slice(0, 10));
      setDisburseAmount(Number(m.disburse_amount || 0));
      setBankId(String(m.bank_id || ''));
      setPayMethodId(String(m.pay_method || ''));
      setCheckRefNo(m.check_ref_no || '');
      setExplanation(m.explanation || '');
      setIsCancelled((m.is_cancel || m.is_cancelled) === 'y');

      const details = (data.details || []).map((d: any) => {
        const code = String(d.acct_code ?? '');
        const desc = findDesc(code) || String(d.acct_desc ?? '');
        ensureAccountInSource(code, desc);
        return {
          id: d.id,
          acct_code: desc ? `${code};${desc}` : `${code};`,
          acct_desc: desc,
          debit: Number(d.debit ?? 0),
          credit: Number(d.credit ?? 0),
          workstation_id: d.workstation_id || '',
          persisted: true,
        } as DetailRow;
      });

      setTableData(details.length ? details.concat([emptyRow()]) : [emptyRow()]);

      // lock by default until approvals say otherwise
      setLocked(true);
      setGridLocked(true);
      hotRef.current?.hotInstance?.updateSettings?.({ readOnly: true });

      await refreshApprovals(m.id);

      toast.success('Disbursement loaded.');
    } catch {
      toast.error('Unable to load the selected disbursement.');
    }
  }, [companyId, vendors, findDesc, ensureAccountInSource, refreshApprovals]);

  const handleSelectTransaction = async (selId: string) => {
    if (!selId) return;
    setSearchId(selId);
    await loadDisbursement(selId);
  };

  /* --------- main actions --------- */
  const handleSaveMain = async () => {
    if (!vendId || !disburseDate || !bankId || !payMethodId || !checkRefNo) {
      return toast.error('Please complete Vendor, Date, Bank, Payment Method, and Check/Ref #.');
    }

    const ok = await Swal.fire({ title: 'Confirm Save?', icon: 'question', showCancelButton: true, confirmButtonText: 'Save' });
    if (!ok.isConfirmed) return;

    try {
      const res = await napi.post('/cash-disbursement/save-main', {
        cd_no: cdNo || undefined,
        vend_id: vendId,
        disburse_date: disburseDate,
        disburse_amount: 0, // backend recalculates from details
        pay_method: payMethodId,
        bank_id: bankId,
        check_ref_no: checkRefNo,
        explanation,
        amount_in_words: pesoWords(disburseAmount).replace(/^\*\*\*\s|\s\*\*\*$/g, ''),
        company_id: companyId,
        user_id: user?.id,
      });

      const newId = res.data.id;

      setMainId(newId);
      setCdNo(res.data.cd_no || '');
      setLocked(true);
      setGridLocked(true);

      // reload so BANK row appears + approval state is fetched
      await loadDisbursement(newId);

      toast.success('Main saved.');
      fetchTransactions();
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save.');
    }
  };

  /* --------- Cancel/Delete/Uncancel (approval-driven) --------- */
  const handleCancelTxn = async () => {
    if (!mainId) return;
    await requestApprovalWithReason('CANCEL', 'Request approval to CANCEL this disbursement?');
  };

  const handleUncancelTxn = async () => {
    if (!mainId) return;
    await requestApprovalWithReason('UNCANCEL', 'Request approval to UNCANCEL this disbursement?');
  };

  const handleDeleteTxn = async () => {
    if (!mainId) return;
    await requestApprovalWithReason('DELETE', 'Request approval to DELETE this disbursement?');
  };

  // totals + new
  const getTotals = (rows: DetailRow[]) => {
    const sumD = rows.reduce((a, r) => a + Number(r.debit || 0), 0);
    const sumC = rows.reduce((a, r) => a + Number(r.credit || 0), 0);
    const balanced = Math.abs(sumD - sumC) < 0.005;
    return { sumD, sumC, balanced };
  };

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

    setSearchId('');
    setTxSearch('');

    setMainId(null);
    setCdNo('');

    setVendId('');
    setVendName('');

    setDisburseDate(new Date().toISOString().slice(0, 10));
    setDisburseAmount(0);
    setExplanation('');
    setCheckRefNo('');

    setBankId('');
    setPayMethodId('');

    setIsCancelled(false);

    setLocked(false);
    setGridLocked(true);

    setApprEdit({ exists: false });
    setApprCancel({ exists: false });
    setApprDelete({ exists: false });
    setApprUncancel({ exists: false });

    setTableData([emptyRow()]);
    hotRef.current?.hotInstance?.loadData?.([emptyRow()]);
    toast.success('Ready for a new transaction.');
  };

  /* --------- detail autosave --------- */
  const isRowValid = (r: DetailRow) =>
    !!onlyCode(r.acct_code) && ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

  const refreshHeaderTotals = (totals?: Totals) => {
    if (!totals) return;
    // Amount box shows total DEBIT (disbursement)
    setDisburseAmount(
      Number(
        totals.sum_debit ??
        totals.total_debit ??
        totals.debit ??
        0
      )
    );
  };

  /*const deleteDetail = async (payload: any) => {
    const { data } = await napi.post('/cash-disbursement/delete-detail', payload);
    refreshHeaderTotals(data?.totals);
    await loadDisbursement(payload.transaction_id);
  };*/

  const handleAutoSave = async (row: DetailRow, _rowIndex: number) => {
    if (!mainId) return;
    if (!canEditNow) return; // ✅ only autosave when edit approval is active
    if (!isRowValid(row)) return;

    const code = onlyCode(row.acct_code);

    try {
      if (!row.persisted) {
        const { data } = await napi.post('/cash-disbursement/save-detail', {
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
          company_id: companyId,
          user_id: user?.id,
        });

        refreshHeaderTotals(data?.totals);
        await loadDisbursement(mainId);
      } else {
        const { data } = await napi.post('/cash-disbursement/update-detail', {
          id: row.id,
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
        });

        refreshHeaderTotals(data?.totals);
        await loadDisbursement(mainId);
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Row save failed');
    }
  };

  /* --------- HOT behavior --------- */

  const ACCT_COL_INDEX = 0;
  const afterBeginEditingOpenAll = (_row: number, col: number) => {
    if (!canEditNow || col !== ACCT_COL_INDEX) return;
    const hot = hotRef.current?.hotInstance;
    const ed: any = hot?.getActiveEditor();
    if (ed?.cellProperties?.type === 'autocomplete') {
      ed.TEXTAREA.value = '';
      ed.query = '';
      ed.open();
      ed.refreshDropdown?.();
    }
  };

  // LOCK the BANK row (workstation_id === 'BANK')
  const bankRowCells: Handsontable.GridSettings['cells'] = (row, _col) => {
    const cellProps: Partial<Handsontable.CellProperties> = {};
    if (!canEditNow) {
      cellProps.readOnly = true;
      return cellProps as Handsontable.CellProperties;
    }
    const src = (hotRef.current?.hotInstance?.getSourceData() as DetailRow[]) ?? tableData;
    const r = src?.[row];
    if (r?.workstation_id === 'BANK') {
      cellProps.readOnly = true;
      cellProps.className = 'htDimmed';
    }
    return cellProps as Handsontable.CellProperties;
  };

  /* --------- print & download --------- */
  const [printOpen, setPrintOpen] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  const [showPdf, setShowPdf] = useState(false);

  const canExport = isSaved && !isCancelled && isBalanced;

  useEffect(() => {
    if (!canExport) {
      setPrintOpen(false);
      setDownloadOpen(false);
    }
  }, [canExport]);

  const handleOpenPdf = () => {
    if (!mainId) return toast.info('Save or select a disbursement first.');
    if (!canExport) return toast.info('Cannot print: transaction is cancelled or unbalanced.');
    const url = `/api/cash-disbursement/form-pdf/${mainId}?company_id=${encodeURIComponent(companyId || '')}`;
    setPdfUrl(url);
    setShowPdf(true);
  };

  const handleDownloadExcel = async () => {
    if (!mainId) return toast.info('Save or select a disbursement first.');
    if (!canExport) return toast.info('Cannot download: transaction is cancelled or unbalanced.');
    const res = await napi.get(`/cash-disbursement/form-excel/${mainId}`, {
      responseType: 'blob',
      params: { company_id: companyId || '' },
    });
    const name = res.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1] || `DisbursementVoucher_${cdNo || mainId}.xlsx`;
    const blob = new Blob([res.data], { type: res.headers['content-type'] || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  };

  /* ---------------- UI ---------------- */

  const balancedPill = (
    <span className={`inline-flex items-center rounded px-3 py-1 text-xs font-semibold ${isBalanced ? 'bg-green-600 text-white' : 'bg-red-600 text-white'}`}>
      {isBalanced ? 'Balanced' : 'Unbalanced'}
    </span>
  );

  const approvalStrip = (
    <div className="flex flex-wrap items-center gap-4 text-sm font-semibold text-gray-700">
      <div>DV No: <span className="text-gray-900">{cdNo || '-'}</span></div>
      <div>Total Debit: <span className="text-gray-900">{fmtMoney(totalDebit)}</span></div>
      <div>Total Credit: <span className="text-gray-900">{fmtMoney(totalCredit)}</span></div>
      <div>{balancedPill}</div>
      <div>Approval: <span className="text-gray-900">{approvalText(apprEdit)}</span></div>
    </div>
  );

  const editButton = (() => {
    if (!isSaved) return null;
    if (isCancelled) return null;

    const st = String(apprEdit?.status || '').toLowerCase();
    if (st === 'pending') {
      return (
        <button
          disabled
          className="inline-flex items-center gap-2 px-4 py-2 rounded bg-purple-200 text-purple-700 cursor-not-allowed"
          title={apprEdit?.reason ? `Reason: ${apprEdit.reason}` : undefined}
        >
          <CheckCircleIcon className="h-5 w-5" />
          Edit Request Pending
        </button>
      );
    }

    if (apprEdit?.approved_active) {
      return (
        <button
          onClick={handleSaveChanges}
          className="inline-flex items-center gap-2 px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700"
        >
          <CheckCircleIcon className="h-5 w-5" />
          Save Changes
        </button>
      );
    }

    return (
      <button
        onClick={() => requestApprovalWithReason('edit', 'Request Edit Approval')}
        className="inline-flex items-center gap-2 px-4 py-2 rounded bg-purple-600 text-white hover:bg-purple-700"
      >
        <CheckCircleIcon className="h-5 w-5" />
        Request to Edit
      </button>
    );
  })();

  const cancelButton = (() => {
    if (!isSaved) return null;

    if (!isCancelled) {
      const st = String(apprCancel?.status || '').toLowerCase();
      const pending = st === 'pending';
      return (
        <button
          onClick={handleCancelTxn}
          disabled={pending || isCancelled}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded ${pending ? 'bg-amber-200 text-amber-800 cursor-not-allowed' : 'bg-amber-500 text-white hover:bg-amber-600'}`}
          title={pending && apprCancel?.reason ? `Reason: ${apprCancel.reason}` : undefined}
        >
          <XMarkIcon className="h-5 w-5" />
          {pending ? 'Cancel Request Pending' : 'Cancel'}
        </button>
      );
    }

    const stU = String(apprUncancel?.status || '').toLowerCase();
    const pendingU = stU === 'pending';
    return (
      <button
        onClick={handleUncancelTxn}
        disabled={pendingU}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded ${pendingU ? 'bg-amber-200 text-amber-800 cursor-not-allowed' : 'bg-amber-600 text-white hover:bg-amber-700'}`}
        title={pendingU && apprUncancel?.reason ? `Reason: ${apprUncancel.reason}` : undefined}
      >
        <ArrowUturnLeftIcon className="h-5 w-5" />
        {pendingU ? 'Uncancel Request Pending' : 'Uncancel'}
      </button>
    );
  })();

  const deleteButton = (() => {
    if (!isSaved) return null;
    const st = String(apprDelete?.status || '').toLowerCase();
    const pending = st === 'pending';
    return (
      <button
        onClick={handleDeleteTxn}
        disabled={pending}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded ${pending ? 'bg-red-200 text-red-800 cursor-not-allowed' : 'bg-red-600 text-white hover:bg-red-700'}`}
        title={pending && apprDelete?.reason ? `Reason: ${apprDelete.reason}` : undefined}
      >
        <TrashIcon className="h-5 w-5" />
        {pending ? 'Delete Request Pending' : 'Delete'}
      </button>
    );
  })();

  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      {/* HEADER */}
      <div className="bg-blue-50 shadow-md rounded-lg p-6 space-y-4 border border-blue-200">
        <h2 className="text-xl font-bold text-blue-800 mb-2">CASH DISBURSEMENT</h2>

        <div className="grid grid-cols-3 auto-rows-auto gap-4">
          {/* Search Transaction */}
          <div className="col-span-3">
            <DropdownWithHeaders
              label="Search Transaction"
              value={searchId}
              onChange={handleSelectTransaction}
              items={txDropdownItems}
              search={txSearch}
              onSearchChange={setTxSearch}
              headers={['ID', 'DV #', 'Vendor', 'Date', 'Amount', 'Check/Ref #']}
              columnWidths={['60px', '90px', '250px', '110px', '110px', '110px']}
              dropdownPositionStyle={{ width: '750px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Vendor */}
          <div className="col-span-2 row-span-2">
            <DropdownWithHeaders
              label="Vendor"
              value={vendId}
              onChange={(v) => {
                setVendId(v);
                const sel = vendors.find(x => String(x.code) === String(v));
                setVendName(sel?.description || '');
              }}
              items={vendors}
              search={vendSearch}
              onSearchChange={setVendSearch}
              headers={['Code', 'Description']}
              columnWidths={['140px', '520px']}
              dropdownPositionStyle={{ width: '700px' }}
              inputClassName="p-2 text-sm bg-white"
              // lock vendor unless edit approved
              // (when not saved yet, allow selection)
            />
          </div>

          {/* Date */}
          <div className="col-span-1">
            <label className="block mb-1">Date</label>
            <input
              type="date"
              value={disburseDate}
              disabled={isCancelled}
              onChange={(e) => setDisburseDate(e.target.value)}
              className="w-full border p-2 bg-blue-100 text-blue-900"
            />
          </div>

          {/* Bank */}
          <div className="col-span-2 row-span-2">
            <DropdownWithHeaders
              label="Bank Name"
              value={bankId}
              onChange={setBankId}
              items={banks.map(b => ({ code: String((b as any).bank_id ?? b.code), description: (b as any).bank_name ?? b.description }))}
              search={bankSearch}
              onSearchChange={setBankSearch}
              headers={['Code', 'Bank']}
              columnWidths={['100px', '350px']}
              dropdownPositionStyle={{ width: '500px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Payment Method */}
          <div className="col-span-1">
            <DropdownWithHeaders
              label="Payment Method"
              value={payMethodId}
              onChange={setPayMethodId}
              items={payMethods.map(p => ({ code: String((p as any).pay_method_id ?? p.code), description: (p as any).pay_method ?? p.description }))}
              search={paySearch}
              onSearchChange={setPaySearch}
              headers={['Code', 'Method']}
              columnWidths={['80px', '200px']}
              dropdownPositionStyle={{ width: '280px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Explanation */}
          <div className="col-span-2 row-span-2">
            <label className="block mb-1">Explanation</label>
            <input
              value={explanation}
              disabled={isCancelled}
              onChange={(e) => setExplanation(e.target.value)}
              className="w-full border p-2 bg-blue-100 text-blue-900"
            />
          </div>

          {/* Check/Ref # */}
          <div className="col-span-1">
            <label className="block mb-1">Check / Ref #</label>
            <input
              value={checkRefNo}
              disabled={isCancelled}
              onChange={(e) => setCheckRefNo(e.target.value)}
              className="w-full border p-2 bg-blue-100 text-blue-900"
            />
          </div>

          {/* Amount in words */}
          <div className="col-span-2 row-span-2">
            <label className="block mb-1">Amount in Words</label>
            <div className="w-full border p-2 bg-white text-blue-900 text-sm font-semibold">
              {amountWords}
            </div>
          </div>

          {/* Amount */}
          <div className="col-span-1">
            <label className="block mb-1">Amount</label>
            <input
              value={fmtMoney(disburseAmount)}
              readOnly
              className="w-full border p-2 bg-yellow-50 text-right font-bold"
            />
          </div>

          {!!vendName && (
            <div className="col-span-3 text-sm font-semibold text-gray-700">
              Vendor: {vendName}
            </div>
          )}
        </div>

        {/* Status strip (DV No / Totals / Balanced / Approval) */}
        {isSaved && (
          <div className="mt-2">
            {approvalStrip}
            {String(apprEdit?.status || '').toLowerCase() === 'pending' && apprEdit?.reason && (
              <div className="mt-1 text-sm text-amber-700 font-semibold">
                Edit approval is pending — Reason: {apprEdit.reason}
              </div>
            )}
          </div>
        )}

        {/* Actions */}
        <div className="flex flex-wrap gap-2 mt-3 items-center">
          {!isSaved ? (
            <button onClick={handleSaveMain} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">
              <CheckCircleIcon className="h-5 w-5" />
              Save
            </button>
          ) : (
            <>
              {editButton}
              {cancelButton}
              {deleteButton}

{/* ✅ Save Main — works without approvals; disappears only when cancelled */}
{!isCancelled && (
  <button
    type="button"
    onClick={handleSaveMainNoApproval}
    className="inline-flex items-center gap-2 px-4 py-2 rounded text-white bg-slate-600 hover:bg-slate-700"
  >
    Save Main
  </button>
)}



            </>
          )}
        </div>

        {isCancelled && (
          <>
            <div className="text-red-600 font-bold mt-2">CANCELLED</div>
            <div className="text-red-600 font-semibold mt-1">
              This transaction is CANCELLED. It cannot be edited, printed, or downloaded.
            </div>
          </>
        )}

        {!isCancelled && isSaved && !isBalanced && (
          <div className="text-red-600 font-semibold mt-2">
            This transaction is UNBALANCED. Please ensure Debit = Credit before printing or downloading.
          </div>
        )}




      </div>

      {/* DETAILS */}
      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-4">Details</h2>

        {isSaved && (
          <HotTable
            className="hot-enhanced hot-zebra"
            ref={hotRef}
            data={tableData}
            readOnly={!canEditNow}
            colHeaders={['Account Code', 'Account Description', 'Debit', 'Credit']}
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
                strict: true,
                allowInvalid: false,
                visibleRows: 12,
                readOnly: !canEditNow,
                renderer: (inst, td, row, col, prop, value, cellProps) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(inst, td, row, col, prop, display, cellProps);
                },
              },
              { data: 'acct_desc', readOnly: true },
              { data: 'debit', type: 'numeric', numericFormat: { pattern: '0,0.00' }, readOnly: !canEditNow },
              { data: 'credit', type: 'numeric', numericFormat: { pattern: '0,0.00' }, readOnly: !canEditNow },
            ]}
            cells={bankRowCells}
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot) return;
              if (!canEditNow) return;
              if (isCancelled) return;

              if (source === 'edit') {
                changes.forEach(([rowIndex, prop, _oldVal, newVal]) => {
                  const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as DetailRow;

                  if (prop === 'acct_code') {
                    const full = String(newVal || '');
                    const code = onlyCode(full);
                    hot.setDataAtRowProp(rowIndex, 'acct_desc', findDesc(code));
                    const payload: DetailRow = {
                      ...rowObj,
                      acct_code: full,
                      acct_desc: findDesc(code),
                      debit: Number(rowObj.debit || 0),
                      credit: Number(rowObj.credit || 0),
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }

                  if (prop === 'debit' || prop === 'credit') {
                    const payload: DetailRow = {
                      ...rowObj,
                      debit: Number(prop === 'debit' ? newVal : rowObj.debit || 0),
                      credit: Number(prop === 'credit' ? newVal : rowObj.credit || 0),
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }
                });

                requestAnimationFrame(() => {
                  const src = hot.getSourceData() as DetailRow[];
                  if (!src.find(r => !r.acct_code)) src.push(emptyRow());
                  setTableData([...src]);
                });
              }
            }}
            contextMenu={{
              items: {
remove_row: {
  name: '🗑️ Remove row',
  callback: async (_key, selection) => {
    if (!canEditNow) return;

    const hot = hotRef.current?.hotInstance;
    const rowIndex = selection?.[0]?.start?.row ?? -1;
    if (!hot || rowIndex < 0) return;

    const src = (hot.getSourceData() as DetailRow[]) || [];
    const row = src[rowIndex];

    // BANK line cannot be deleted
    if (row?.workstation_id === 'BANK') {
      toast.info('Bank line cannot be deleted.');
      return;
    }

    // If it's not persisted yet, just remove locally (safe)
    if (!row?.id) {
      src.splice(rowIndex, 1);

      // keep trailing empty row
      if (!src.find(r => !r.acct_code)) src.push(emptyRow());

      setTableData([...src]);
      return;
    }

    const ok = await Swal.fire({
      title: 'Delete this line?',
      icon: 'warning',
      showCancelButton: true,
    });
    if (!ok.isConfirmed) return;

    try {
      // ✅ Server delete triggers adjustBankCredit + recalcTotals in backend
      await napi.post('/cash-disbursement/delete-detail', {
        id: row.id,
        transaction_id: mainId,
      });

      // ✅ SINGLE source of truth: reload from server so BANK auto-adjusts
      await loadDisbursement(mainId!);

      toast.success('Row deleted.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Delete failed.');
    }
  },
},

              },
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
        <div
          className="relative inline-block"
          onMouseEnter={() => { if (canExport) setDownloadOpen(true); }}
          onMouseLeave={() => setDownloadOpen(false)}
        >
          <button
            type="button"
            disabled={!canExport}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              canExport ? 'bg-white text-blue-700 border-blue-300 hover:bg-blue-50' : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
            }`}
          >
            <ArrowDownTrayIcon className={`h-5 w-5 ${canExport ? 'text-blue-600' : 'text-gray-400'}`} />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {canExport && downloadOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadExcel}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-blue-50"
                >
                  <DocumentArrowDownIcon className="h-5 w-5 text-blue-600" />
                  <span className="truncate">Disbursement Voucher – Excel</span>
                  <span className="ml-auto text-[10px] font-semibold">XLSX</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* PRINT */}
        <div
          className="relative inline-block"
          onMouseEnter={() => { if (canExport) setPrintOpen(true); }}
          onMouseLeave={() => setPrintOpen(false)}
        >
          <button
            type="button"
            disabled={!canExport}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              canExport ? 'bg-white text-gray-700 hover:bg-gray-50' : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
            }`}
          >
            <PrinterIcon className="h-5 w-5" />
            <span>Print</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {canExport && printOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleOpenPdf}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Disbursement Voucher – PDF</span>
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
          className="inline-flex items-center gap-2 rounded border px-4 py-2 bg-blue-600 text-white hover:bg-blue-700"
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
            <button onClick={() => setShowPdf(false)} className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200" aria-label="Close">✕</button>
            <div className="h-full w-full pt-8">
              <iframe title="Disbursement Voucher PDF" src={pdfUrl} className="w-full h-full" style={{ border: 'none' }} />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
