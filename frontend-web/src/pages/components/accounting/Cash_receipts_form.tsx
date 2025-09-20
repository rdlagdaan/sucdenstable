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

type ReceiptListRow = {
  id: number | string;
  cr_no: string;
  cust_id: string;
  cust_name?: string;
  receipt_date: string;
  receipt_amount: number;
  bank_id?: string;
  collection_receipt?: string;
  is_cancel?: 'y' | 'n';
};

/* ---------------- component ---------------- */

export default function CashReceiptsForm() {
  const hotRef = useRef<HotTableClass>(null);

  // header
  const [mainId, setMainId] = useState<number | null>(null);
  const [crNo, setCrNo] = useState('');
  const [custId, setCustId] = useState('');
  const [custName, setCustName] = useState('');
  const [receiptDate, setReceiptDate] = useState<string>(() => new Date().toISOString().slice(0, 10));
  const [receiptAmount, setReceiptAmount] = useState<number>(0);
  const [amountWords, setAmountWords] = useState<string>('*** ZERO PESOS ONLY ***');
  const [bankId, setBankId] = useState('');
  const [payMethodId, setPayMethodId] = useState('');
  const [details, setDetails] = useState('');
  const [collectionReceipt, setCollectionReceipt] = useState('');
  const [_locked, setLocked] = useState(false);       // header lock
  const [gridLocked, setGridLocked] = useState(true); // grid lock (separate)
  const [isCancelled, setIsCancelled] = useState(false);

  const isSaved = useMemo(() => mainId != null, [mainId]);

  // dropdown data
  const [customers, setCustomers] = useState<DropdownItem[]>([]);
  const [banks, setBanks] = useState<DropdownItem[]>([]);
  const [payMethods, setPayMethods] = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const acctSource = useMemo(() => accounts.map(a => `${a.acct_code};${a.acct_desc}`), [accounts]);
  const findDesc = (code: string) => accounts.find(a => a.acct_code === code)?.acct_desc || '';

    const [custSearch, setCustSearch] = useState('');
    const [bankSearch, setBankSearch] = useState('');
    const [paySearch,  setPaySearch]  = useState('');

  // search transaction
  const [searchId, setSearchId] = useState('');
  const [txSearch, setTxSearch] = useState('');
  const [txOptions, setTxOptions] = useState<ReceiptListRow[]>([]);
 
  
  const txDropdownItems = useMemo<DropdownItem[]>(() =>
    (txOptions || []).map(o => ({
      code: String(o.id),
      cr_no: o.cr_no,
      //cust_id: o.cust_id,
      cust_name: o.cust_name || '',
      receipt_date: (o.receipt_date || '').slice(0, 10),
      receipt_amount: fmtMoney(Number(o.receipt_amount || 0)),
      collection_receipt: o.collection_receipt || '',
      label: o.cr_no,
      description: o.cust_name || o.cust_id,
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

  // user/company from localStorage like your working page
  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);
  const companyId = user?.company_id;

  // amount words
  useEffect(() => {
    setAmountWords(pesoWords(receiptAmount));
  }, [receiptAmount]);

  /* --------- fetch dropdowns --------- */
  useEffect(() => {
    (async () => {
      try {
        const [custRes, bankRes, pmRes, acctRes] = await Promise.all([
          napi.get('/api/cr/customers', { params: { company_id: companyId } }),
          napi.get('/api/cr/banks', { params: { company_id: companyId } }),
          napi.get('/api/cr/payment-methods'),
          napi.get('/api/cr/accounts', { params: { company_id: companyId } }),
        ]);

        setCustomers((custRes.data || []).map((c: any) => ({
          code: String(c.cust_id ?? c.code),
          description: c.cust_name ?? c.description ?? '',
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
      } catch (e) {
        setCustomers([]); setBanks([]); setPayMethods([]); setAccounts([]);
      }
    })();
  }, [companyId]);

  /* --------- search list --------- */
  const fetchTransactions = useCallback(async () => {
    try {
      const { data } = await napi.get<ReceiptListRow[]>('/api/cash-receipt/list', {
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



  /* --------- load one receipt --------- */
  const loadReceipt = useCallback(async (rid: string | number) => {
    try {
      const { data } = await napi.get(`/api/cash-receipt/${rid}`, {
        params: { company_id: companyId },
      });
      const m = data.main ?? data;
      setMainId(m.id);
      setCrNo(m.cr_no || '');
      setCustId(String(m.cust_id || ''));
      const sel = customers.find(c => String(c.code) === String(m.cust_id));
      setCustName(sel?.description || '');

      setReceiptDate((m.receipt_date || '').slice(0, 10));
      setReceiptAmount(Number(m.receipt_amount || 0));
      setBankId(String(m.bank_id || ''));
      setPayMethodId(String(m.pay_method || ''));
      setCollectionReceipt(m.collection_receipt || '');
      setDetails(m.details || '');
      setIsCancelled((m.is_cancel || m.is_cancelled) === 'y');

      /*const details = (data.details || []).map((d: any) => ({
        id: d.id,
        acct_code: `${d.acct_code};${d.acct_desc || findDesc(d.acct_code)}`,
        acct_desc: d.acct_desc,
        debit: Number(d.debit || 0),
        credit: Number(d.credit || 0),
        workstation_id: d.workstation_id || '',
        persisted: true,
      })) as DetailRow[];*/

const details = (data.details || []).map((d: any) => {
  const code = String(d.acct_code ?? '');
  // Always prefer the description from the accounts list so it matches acctSource exactly.
  const desc = findDesc(code) || String(d.acct_desc ?? '');

  // Make sure this code is present in the autocomplete source (strict mode needs this).
  ensureAccountInSource(code, desc);

  return {
    id: d.id,
    // Value must be identical to one of the source items: `${acct_code};${acct_desc}`
    acct_code: desc ? `${code};${desc}` : `${code};`,
    acct_desc: desc,
    debit: Number(d.debit ?? 0),
    credit: Number(d.credit ?? 0),
    workstation_id: d.workstation_id || '',
    persisted: true,
  } as DetailRow;
});




      setTableData(details.length ? details.concat([emptyRow()]) : [emptyRow()]);
      setLocked(true);
      //setGridLocked(!!((m.is_cancel || m.is_cancelled) === 'y'));
      toast.success('Receipt loaded.');
    } catch {
      toast.error('Unable to load the selected receipt.');
    }
  }, [companyId, customers, findDesc, ensureAccountInSource]);

  const handleSelectTransaction = async (selId: string) => {
    if (!selId) return;
    setSearchId(selId);
    await loadReceipt(selId);
    setGridLocked(true);
    hotRef.current?.hotInstance?.updateSettings?.({ readOnly: true });
  };

  /* --------- main actions --------- */
  const handleSaveMain = async () => {
    if (!custId || !receiptDate || !bankId || !payMethodId || !collectionReceipt) {
      return toast.error('Please complete Customer, Date, Bank, Payment Method, and Collection Receipt.');
    }

    const ok = await Swal.fire({ title: 'Confirm Save?', icon: 'question', showCancelButton: true, confirmButtonText: 'Save' });
    if (!ok.isConfirmed) return;

    try {
      const res = await napi.post('/api/cash-receipt/save-main', {
        cr_no: crNo || undefined,
        cust_id: custId,
        receipt_date: receiptDate,
        receipt_amount: 0, // backend will recompute from details
        pay_method: payMethodId,
        bank_id: bankId,
        collection_receipt: collectionReceipt,
        details,
        amount_in_words: pesoWords(receiptAmount).replace(/^\*\*\*\s|\s\*\*\*$/g, ''),
        company_id: companyId,
        user_id: user?.id,
      });

        const newId = res.data.id;

        setMainId(newId);
        setCrNo(res.data.cr_no || '');
        setLocked(true);
        setGridLocked(false);

        // ✅ immediately load from server so the BANK row appears
        await loadReceipt(newId);

        toast.success('Main saved. You can now input details.');
        fetchTransactions(); // optional: refresh the search dropdown

    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save.');
    }
  };

  const handleUpdateMain = () => {
    // unlock header & grid for edits (grid still keeps BANK row read-only)
    setLocked(false);
    setGridLocked(false);
    toast.success('Editing enabled.');
  };

  const handleCancelTxn = async () => {
    if (!mainId) return;
    const ok = await Swal.fire({ title: 'Cancel this receipt?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Cancel' });
    if (!ok.isConfirmed) return;
    try {
      const { data } = await napi.post('/api/cash-receipt/cancel', { id: mainId, flag: 1 });
      setIsCancelled((data?.is_cancel || data?.is_cancelled) === 'y');
      setLocked(true);
      setGridLocked(true);
      toast.success('Receipt marked CANCELLED.');
      fetchTransactions();
    } catch {
      toast.error('Failed to cancel receipt.');
    }
  };

  const handleUncancelTxn = async () => {
    if (!mainId) return;
    const ok = await Swal.fire({ title: 'Uncancel this receipt?', icon: 'question', showCancelButton: true, confirmButtonText: 'Uncancel' });
    if (!ok.isConfirmed) return;
    try {
      const { data } = await napi.post('/api/cash-receipt/cancel', { id: mainId, flag: 0 });
      setIsCancelled((data?.is_cancel || data?.is_cancelled) !== 'y');
      setLocked(true);
      setGridLocked(false);
      toast.success('Receipt is now ACTIVE.');
      fetchTransactions();
    } catch {
      toast.error('Failed to uncancel receipt.');
    }
  };

  const handleDeleteTxn = async () => {
    if (!mainId) return;
    const ok = await Swal.fire({ title: 'Delete this receipt?', text: 'This action is irreversible.', icon: 'error', showCancelButton: true, confirmButtonText: 'Delete' });
    if (!ok.isConfirmed) return;
    try {
      await napi.delete(`/api/cash-receipt/${mainId}`);
      handleNew(); // reset
      toast.success('Receipt deleted.');
      fetchTransactions();
    } catch {
      toast.error('Failed to delete receipt.');
    }
  };

// ⬇️ Put this near your other helpers (top of file)
/** Sum debit/credit and check balance (tolerance 0.005) */
const getTotals = (rows: DetailRow[]) => {
  const sumD = rows.reduce((a, r) => a + Number(r.debit || 0), 0);
  const sumC = rows.reduce((a, r) => a + Number(r.credit || 0), 0);
  const balanced = Math.abs(sumD - sumC) < 0.005;
  return { sumD, sumC, balanced };
};


// ⬇️ Replace your current handleNew with this version
const handleNew = async () => {
  // 1) Detect if there’s any meaningful content in the grid
  const hasAnyDetail = tableData.some(
    r =>
      (r.acct_code && r.acct_code.trim() !== '') ||
      (Number(r.debit) || 0) > 0 ||
      (Number(r.credit) || 0) > 0
  );

  // 2) If there’s content, require balance first (mirrors Sales Journal UX)
  if (hasAnyDetail) {
    const { sumD, sumC, balanced } = getTotals(tableData);

    if (!balanced) {
      await Swal.fire({
        title: 'Transaction not balanced',
        html: `Debit <b>${fmtMoney(sumD)}</b> must equal Credit <b>${fmtMoney(sumC)}</b> before starting a new one.`,
        icon: 'error',
      });
      return; // stop — user must balance first
    }

    // 3) Ask confirmation before clearing a balanced form
    const ok = await Swal.fire({
      title: 'Start a new transaction?',
      text: 'This will clear the current form.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, New',
    });
    if (!ok.isConfirmed) return;
  } else {
    // No details — still confirm to avoid accidental clearing
    const ok = await Swal.fire({
      title: 'Start a new transaction?',
      text: 'This will clear the current form.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, New',
    });
    if (!ok.isConfirmed) return;
  }

  // 4) Clear everything (same reset behavior you already had)
  setSearchId(''); 
  setTxSearch('');

  setMainId(null);
  setCrNo('');

  setCustId('');
  setCustName('');

  setReceiptDate(new Date().toISOString().slice(0, 10));
  setReceiptAmount(0);
  setDetails('');
  setCollectionReceipt('');

  setBankId('');
  setPayMethodId('');

  setIsCancelled(false);

  // Header editable; grid locked until user saves/updates (your current pattern)
  setLocked(false);
  setGridLocked(true);

  // Reset grid to one empty row
  setTableData([emptyRow()]);
  // (optional) force Handsontable to reload immediately
  hotRef.current?.hotInstance?.loadData?.([emptyRow()]);

  toast.success('Ready for a new transaction.');
};


  /* --------- detail autosave --------- */
  const isRowValid = (r: DetailRow) =>
    !!onlyCode(r.acct_code) && ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

const refreshHeaderTotals = (totals?: Totals) => {
  if (!totals) return;
  // Amount box shows total CREDIT per legacy rule
  setReceiptAmount(
    Number(
      totals.sum_credit ??
      totals.total_credit ??
      totals.credit ??
      0
    )
  );
};


  const deleteDetail = async (payload: any) => {
    const { data } = await napi.post('/api/cash-receipt/delete-detail', payload);
    refreshHeaderTotals(data?.totals);
    await loadReceipt(payload.transaction_id);
  };

// Replace your existing handleAutoSave with this version
const handleAutoSave = async (row: DetailRow, _rowIndex: number) => {
  if (!mainId) return;
  if (!isRowValid(row)) return;

  // Handsontable stores "code;desc" in acct_code → send only the code to the API
  const code = onlyCode(row.acct_code);

  try {
    if (!row.persisted) {
      // ⬇️ CREATE: insert a new detail row
      const { data } = await napi.post('/api/cash-receipt/save-detail', {
        transaction_id: mainId,
        acct_code: code,
        debit: row.debit || 0,
        credit: row.credit || 0,
        company_id: companyId,
        user_id: user?.id,
      });

      // ✅ Update header "Amount" box from server totals (if returned)
      //    (Your backend returns { totals: { sum_debit, sum_credit, ... } })
      refreshHeaderTotals(data?.totals);

      // 🔄 IMPORTANT: reload the whole receipt so we:
      //    - show the server-computed BANK debit immediately
      //    - keep the BANK row locked (workstation_id === 'BANK')
      //    - normalize descriptions to match the autocomplete source
      //    - append the trailing empty row via loadReceipt()
      await loadReceipt(mainId);

      // NOTE: We intentionally removed the local mutation:
      //   - setting persisted/id on the row
      //   - pushing an extra empty row
      // loadReceipt() now owns the source of truth and keeps UI consistent.

    } else {
      // ⬇️ UPDATE: modify an existing detail row
      const { data } = await napi.post('/api/cash-receipt/update-detail', {
        id: row.id,
        transaction_id: mainId,
        acct_code: code,
        debit: row.debit || 0,
        credit: row.credit || 0,
      });

      // ✅ Refresh header totals if backend returned them
      refreshHeaderTotals(data?.totals);

      // 🔄 Reload so BANK debit reflects the new balance immediately
      await loadReceipt(mainId);
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Row save failed');
  }
};

  /* --------- HOT behavior --------- */

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

  /* --------- print & download --------- */
  const [printOpen, setPrintOpen] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  const [showPdf, setShowPdf] = useState(false);

  const handleOpenPdf = () => {
    if (!mainId) return toast.info('Save or select a receipt first.');
    const url = `/api/cash-receipt/form-pdf/${mainId}?company_id=${encodeURIComponent(companyId||'')}`;
    setPdfUrl(url);
    setShowPdf(true);
  };
  const handleDownloadExcel = async () => {
    if (!mainId) return toast.info('Save or select a receipt first.');
    const res = await napi.get(`/api/cash-receipt/form-excel/${mainId}`, {
      responseType: 'blob',
      params: { company_id: companyId||'' },
    });
    const name = res.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1] || `ReceiptVoucher_${crNo||mainId}.xlsx`;
    const blob = new Blob([res.data], { type: res.headers['content-type'] || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  };

// add this helper ABOVE your return (anywhere in the component scope)
const bankRowCells: Handsontable.GridSettings['cells'] = (row, _col) => {
  // Build a *partial* CellProperties object, then cast on return
  const cellProps: Partial<Handsontable.CellProperties> = {};

  // If grid is locked (view mode), everything is read-only
  if (gridLocked) {
    cellProps.readOnly = true;
    return cellProps as Handsontable.CellProperties;
  }

  const src =
    (hotRef.current?.hotInstance?.getSourceData() as DetailRow[]) ?? tableData;
  const r = src?.[row];

  // 🔒 Lock the entire BANK row (acct_code, desc, debit, credit)
  if (r?.workstation_id === 'BANK') {
    cellProps.readOnly = true;
    cellProps.className = 'htDimmed'; // optional: greyed style
  }

  // Handsontable's type signature expects a full CellProperties,
  // but returning a partial is fine at runtime—just cast it.
  return cellProps as Handsontable.CellProperties;
};



  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

     {/* HEADER */}
<div className="bg-blue-50 shadow-md rounded-lg p-6 space-y-4 border border-blue-200">
  <h2 className="text-xl font-bold text-blue-800 mb-2">CASH RECEIPTS</h2>

  {/* Grid container with explicit columns; row spans control the vertical layout */}
  <div className="grid grid-cols-3 auto-rows-auto gap-4">

    {/* ROW 1 — Search Transaction (full width) */}
    <div className="col-span-3">
      <DropdownWithHeaders
        label="Search Transaction"
        value={searchId}
        onChange={handleSelectTransaction}
        items={txDropdownItems}
        search={txSearch}
        onSearchChange={setTxSearch}
        headers={['ID','RV #','Customer','Date','Amount','Collection Rcpt']}
        columnWidths={['60px','90px','250px','110px','110px','110px']}
        dropdownPositionStyle={{ width: '750px' }}
        inputClassName="p-2 text-sm bg-white"
      />
    </div>

    {/* ROW 2 — Customer (row-span:2) */}
    <div className="col-span-2 row-span-2">
      <DropdownWithHeaders
        label="Customer"
        value={custId}
        onChange={(v) => {
          setCustId(v);
          const sel = customers.find(c => String(c.code) === String(v));
          setCustName(sel?.description || '');
        }}
        items={customers} // [{ code, description }]
        search={custSearch}
        onSearchChange={setCustSearch}
        headers={['Code','Description']}
        columnWidths={['140px','520px']}
        dropdownPositionStyle={{ width: '700px' }}
        inputClassName="p-2 text-sm bg-white"
      />
    </div>

    {/* ROW 2 — Date (row-span:1) */}
    <div className="col-span-1">
      <label className="block mb-1">Date</label>
      <input
        type="date"
        value={receiptDate}
        disabled={isSaved}
        onChange={(e) => setReceiptDate(e.target.value)}
        className="w-full border p-2 bg-blue-100 text-blue-900"
      />
    </div>

    {/* ROW 3 — Bank Name (row-span:2) */}
    <div className="col-span-2 row-span-2">
      <DropdownWithHeaders
        label="Bank Name"
        value={bankId}
        onChange={setBankId}
        items={banks.map(b => ({ code: String(b.bank_id ?? b.code), description: b.bank_name ?? b.description }))}
        search={bankSearch}
        onSearchChange={setBankSearch}
        headers={['Code','Bank']}
        columnWidths={['100px','350px']}
        dropdownPositionStyle={{ width: '500px' }}
        inputClassName="p-2 text-sm bg-white"
      />
    </div>

    <div className="col-span-1">
      <DropdownWithHeaders
        label="Payment Method"
        value={payMethodId}
        onChange={setPayMethodId}
        items={payMethods.map(p => ({ code: String(p.pay_method_id ?? p.code), description: p.pay_method ?? p.description }))}
        search={paySearch}
        onSearchChange={setPaySearch}
        headers={['Code','Method']}
        columnWidths={['80px','200px']}
        dropdownPositionStyle={{ width: '280px' }}
        inputClassName="p-2 text-sm bg-white"
      />
    </div>


    {/* ROW 4 — Payment Method (row-span:1) */}


    {/* ROW 4–5 — Details (row-span:2) */}
    <div className="col-span-2  row-span-2">
      <label className="block mb-1">Details</label>
      <input
        value={details}
        disabled={isSaved && isCancelled}
        onChange={(e) => setDetails(e.target.value)}
        className="w-full border p-2 bg-blue-100 text-blue-900"
      />
    </div>

    {/* ROW 3 — Collection Receipt # (row-span:1) */}
    <div className="col-span-1">
      <label className="block mb-1">Collection Receipt #</label>
      <input
        value={collectionReceipt}
        disabled={isSaved && isCancelled}
        onChange={(e) => setCollectionReceipt(e.target.value)}
        className="w-full border p-2 bg-blue-100 text-blue-900"
      />
    </div>

    {/* ROW 5 — Amount (row-span:1) */}
    {/* ROW 6–7 — Amount in Words (row-span:2) */}
    <div className="col-span-2   row-span-2">
      <label className="block mb-1">Amount in Words</label>
      <div className="w-full border p-2 bg-white text-blue-900 text-sm font-semibold">
        {amountWords}
      </div>
    </div>

    <div className="col-span-1">
      <label className="block mb-1">Amount</label>
      <input
        value={fmtMoney(receiptAmount)}
        readOnly
        className="w-full border p-2 bg-yellow-50 text-right font-bold"
      />
    </div>



    {/* FOOTER — Customer name (full width) */}
    {!!custName && (
      <div className="col-span-3 text-sm font-semibold text-gray-700">
        Customer: {custName}
      </div>
    )}
  </div>


        {/* Actions */}
        <div className="flex gap-2 mt-3">
          {!isSaved ? (
            <button onClick={handleSaveMain} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">
              <CheckCircleIcon className="h-5 w-5" />
              Save
            </button>
          ) : (
            <>
              {!isCancelled && (
                <button onClick={handleUpdateMain} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700">
                  <CheckCircleIcon className="h-5 w-5" />
                  Update
                </button>
              )}
              {!isCancelled ? (
                <button onClick={handleCancelTxn} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-amber-500 text-white hover:bg-amber-600">
                  <XMarkIcon className="h-5 w-5" />
                  Cancel
                </button>
              ) : (
                <button onClick={handleUncancelTxn} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-amber-600 text-white hover:bg-amber-700">
                  <ArrowUturnLeftIcon className="h-5 w-5" />
                  Uncancel
                </button>
              )}
              <button onClick={handleDeleteTxn} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700">
                <TrashIcon className="h-5 w-5" />
                Delete
              </button>
            </>
          )}
        </div>

        {isCancelled && <div className="text-red-600 font-bold mt-2">CANCELLED</div>}
      </div>

      {/* DETAILS */}
      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-4">Details</h2>

        {isSaved && (
          <HotTable
            className="hot-enhanced hot-zebra"
            ref={hotRef}
            data={tableData}
            readOnly={gridLocked}
            colHeaders={['Account Code','Account Description','Debit','Credit']}
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
                readOnly: gridLocked,
                renderer: (inst, td, row, col, prop, value, cellProps) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(inst, td, row, col, prop, display, cellProps);
                },
              },
              { data: 'acct_desc', readOnly: true },
              { data: 'debit',  type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
              { data: 'credit', type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
            ]}

            /* 🔒 Per-row lock: if workstation_id === 'BANK', the entire row is read-only
              (this overrides the column-level readOnly above) */
            /*cells={(row) => {
              const cp: Handsontable.CellProperties = {};
              const src = (hotRef.current?.hotInstance?.getSourceData() as DetailRow[]) ?? tableData;
              const r = src?.[row];
              if (r?.workstation_id === 'BANK') {
                cp.readOnly = true;      // lock acct code, desc, debit, credit
                cp.className = 'htDimmed'; // optional: greyed style for locked row
              }
              return cp;
            }}*/

            cells={bankRowCells}
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot || isCancelled) return;

              if (source === 'edit') {
                changes.forEach(([rowIndex, prop, _oldVal, newVal]) => {
                  const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as DetailRow;

                  // lock BANK row edits (server recomputes its debit)
                 // if (rowObj.workstation_id === 'BANK') {
                    // revert any local edit by reloading
                    //if (mainId) loadReceipt(mainId);
                    //return;
                  //}

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

                // keep React state synced & ensure trailing empty row
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
                    const hot = hotRef.current?.hotInstance;
                    const rowIndex = selection[0].start.row;
                    const src = (hot?.getSourceData() as DetailRow[]) || [];
                    const row = src[rowIndex];

                    // bank line cannot be deleted
                    if (row?.workstation_id === 'BANK') {
                      toast.info('Bank line cannot be deleted.');
                      return;
                    }

                    if (!row?.id) { src.splice(rowIndex,1); setTableData([...src]); return; }

                    const ok = await Swal.fire({ title: 'Delete this line?', icon: 'warning', showCancelButton: true });
                    if (!ok.isConfirmed) return;

                    await deleteDetail({ id: row.id, transaction_id: mainId });
                    src.splice(rowIndex, 1);
                    setTableData([...src]);
                    toast.success('Row deleted.');
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
          onMouseEnter={() => setDownloadOpen(true)}
          onMouseLeave={() => setDownloadOpen(false)}
        >
          <button
            type="button"
            disabled={!isSaved}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              isSaved ? 'bg-white text-blue-700 border-blue-300 hover:bg-blue-50' : 'bg-gray-100 text-gray-400 border-gray-200'
            }`}
          >
            <ArrowDownTrayIcon className={`h-5 w-5 ${isSaved ? 'text-blue-600' : 'text-gray-400'}`} />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {downloadOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadExcel}
                  disabled={!isSaved}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    isSaved ? 'text-gray-800 hover:bg-blue-50' : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentArrowDownIcon className={`h-5 w-5 ${isSaved ? 'text-blue-600' : 'text-gray-400'}`} />
                  <span className="truncate">Receipt Voucher – Excel</span>
                  <span className="ml-auto text-[10px] font-semibold">XLSX</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* PRINT */}
        <div
          className="relative inline-block"
          onMouseEnter={() => setPrintOpen(true)}
          onMouseLeave={() => setPrintOpen(false)}
        >
          <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-2 bg-white text-gray-700 hover:bg-gray-50">
            <PrinterIcon className="h-5 w-5" /><span>Print</span><ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>
          {printOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleOpenPdf}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Receipt Voucher – PDF</span>
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
            <button onClick={()=>setShowPdf(false)} className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200" aria-label="Close">✕</button>
            <div className="h-full w-full pt-8">
              <iframe title="Receipt Voucher PDF" src={pdfUrl} className="w-full h-full" style={{border:'none'}}/>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
