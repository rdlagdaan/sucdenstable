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
const fmtDate = (d?: string) => (!d ? '' : (new Date(d).toString() === 'Invalid Date' ? (d || '').split('T')[0] : new Date(d).toISOString().slice(0,10)));

type SalesDetailRow = {
  id?: number;
  acct_code: string;      // stores "code;desc"
  acct_desc?: string;
  debit?: number;
  credit?: number;
  persisted?: boolean;
};

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
  purchase_date: string;
  purchase_amount: number;
  invoice_no: string;
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

  const [locked, setLocked] = useState(false);
  const [gridLocked, setGridLocked] = useState(true);

  // dropdown data
  const [vendors, setVendors]   = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const [sugarTypes, setSugarTypes] = useState<DropdownItem[]>([]);
  const [cropYears, setCropYears]   = useState<DropdownItem[]>([]);
  const [mills, setMills]           = useState<DropdownItem[]>([]);

  // dropdown search bindings
  const [vendorSearch, setVendorSearch] = useState('');
  const [sugarSearch, setSugarSearch]   = useState('');
  const [cropSearch, setCropSearch]     = useState('');
  const [millSearch, setMillSearch]     = useState('');

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

  // -------------------------------
  // Dropdown data loads + mapping
  // -------------------------------
  useEffect(() => {
    (async () => {
      try {
        // Vendors: controller already shapes { code, label, description }
        const { data } = await napi.get('/pj/vendors', { params: { company_id: user?.company_id }});
        const items: DropdownItem[] = (data || []).map((v: any) => ({
          code: String(v.code ?? v.vend_code ?? v.vend_id ?? ''),
          description: v.description ?? v.vend_name ?? v.vendor_name ?? '',
          label: v.label ?? v.code ?? '',
        }));
        setVendors(items);
      } catch { setVendors([]); }
    })();
  }, [user?.company_id]);

  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/pj/accounts', { params: { company_id: user?.company_id }});
        setAccounts(Array.isArray(data) ? data : []);
      } catch { setAccounts([]); }
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
      } catch { setSugarTypes([]); }
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
      } catch { setCropYears([]); }
    })();
  }, []);

  /*const loadMills = async (asOf?: string) => {
    try {
      const url = asOf ? '/api/mills/effective' : '/api/mills';
      const { data } = await napi.get(url, { params: asOf ? { as_of: asOf } : {} });
      const items: DropdownItem[] = (Array.isArray(data) ? data : []).map((r: any) => ({
        code: String(r.code ?? r.mill_id ?? r.code_id ?? ''),
        description: String(r.description ?? r.mill_name ?? r.name ?? ''),
        label: String(r.code ?? r.mill_id ?? ''),
      }));
      setMills(items);
    } catch { setMills([]); }
  };

  // load mills initially and whenever purchase date changes (for "effective" list)
  useEffect(() => { loadMills(purchaseDate || undefined); }, [purchaseDate]);*/

// REMOVE normalizeMillCode and any previous loadMills definitions

const loadMills = async (asOf?: string) => {
  try {
    const params: any = { company_id: user?.company_id };
    if (asOf) params.as_of = asOf;

    const url = asOf ? '/mills/effective' : '/mills';
    const { data } = await napi.get(url, { params });

    // Map to exactly two fields in a fixed order: code, description
    const items = (Array.isArray(data) ? data : [])
      .map((r: any) => {
        const id = String(r.mill_id ?? '').trim();
        const code =
          id && id !== '0' && id.toLowerCase() !== 'null'
            ? id
            : String(r.prefix ?? '').trim(); // fallback only if id is unusable

        return {
          code,                                        // what you save/return
          description: String(r.mill_name ?? '').trim() // what you display
        };
      })
      .filter(it => it.code); // drop empties just in case

    setMills(items);
  } catch {
    setMills([]);
  }
};

// keep this
useEffect(() => { loadMills(purchaseDate || undefined); }, [purchaseDate]);





  // -------------------------------
  // Search transaction dropdown
  // -------------------------------
  const fetchTransactions = async () => {
    try {
      const { data } = await napi.get<TxOption[]>('/purchase/list', {
        params: { company_id: user?.company_id || '', q: txSearch || '' },
      });
      setTxOptions(Array.isArray(data) ? data : []);
    } catch { setTxOptions([]); }
  };
  useEffect(() => { fetchTransactions(); /* eslint-disable-next-line */ }, [txSearch]);

  const txDropdownItems = useMemo<DropdownItem[]>(() => {
    return (txOptions || []).map((o) => ({
      code: String(o.id),
      cp_no: o.cp_no,
      vend_id: o.vend_id,
      purchase_date: fmtDate(o.purchase_date),
      purchase_amount: o.purchase_amount,
      invoice_no: o.invoice_no,
      label: o.cp_no,
      description: o.vend_id,
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
    setMainId(null); setLocked(false); setGridLocked(false); setHotEnabled(false);
    setTableData([emptyRow()]);
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
    } catch (_) {}

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

        // 🔹 extra header fields
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

  const handleUpdateMain = () => { setLocked(false); setGridLocked(false); toast.success('Editing enabled.'); };

  const handleCancelTxn = async () => {
    if (!mainId) return;
    const confirmed = await Swal.fire({ title: 'Cancel this transaction?', text: 'This will mark the transaction as CANCELLED.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, cancel it' });
    if (!confirmed.isConfirmed) return;
    try {
      await napi.post('/purchase/cancel', { id: mainId, flag: '1', company_id: user?.company_id || '' });
      setLocked(true); setGridLocked(true); toast.success('Transaction has been cancelled.'); fetchTransactions();
    } catch { toast.error('Failed to cancel transaction.'); }
  };

  const handleDeleteTxn = async () => {
    if (!mainId) return;
    const confirmed = await Swal.fire({ title: 'Delete this transaction?', text: 'This action is irreversible.', icon: 'error', showCancelButton: true, confirmButtonText: 'Delete' });
    if (!confirmed.isConfirmed) return;
    try { await napi.delete(`/purchase/${mainId}`); resetForm(); toast.success('Transaction deleted.'); fetchTransactions(); }
    catch { toast.error('Failed to delete transaction.'); }
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
  const handleSelectTransaction = async (selectedId: string) => {
    if (!selectedId) return;
    try {
      setSearchId(selectedId);
      const { data } = await napi.get(`/purchase/${selectedId}`, { params: { company_id: user?.company_id } });

      const m = data.main ?? data;
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
      toast.success('Transaction loaded.');
    } catch {
      toast.error('Unable to load the selected transaction.');
    }
  };

  const apiBase = (napi.defaults.baseURL || '/api').replace(/\/+$/,'');


  // -------------------------------
  // Print / Download helpers
  // -------------------------------
const handleOpenPdf = () => {
  if (!mainId) return toast.info('Select or save a transaction first.');
  const url = `${apiBase}/purchase/form-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id||'')}`;
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

    // If server returned HTML (e.g., unbalanced warning), show it
    const ct = String(res.headers['content-type'] || '');
    if (!ct.includes('sheet') && !ct.includes('excel') && !ct.includes('octet-stream')) {
      // Try to read error text (best-effort)
      const text = await (res.data?.text?.().catch(()=>null));
      toast.error(text ? `Download failed: ${text.slice(0,200)}…` : 'Download failed.');
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


const getTotals = (rows: SalesDetailRow[]) => {
  const sumD = rows.reduce((t, r) => t + (Number(r.debit)  || 0), 0);
  const sumC = rows.reduce((t, r) => t + (Number(r.credit) || 0), 0);
  const balanced = Math.abs(sumD - sumC) < 0.005; // 0.5 cent tolerance
  return { sumD, sumC, balanced };
};

const fmtMoney = (n: number) =>
  (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });


// Start a brand-new transaction
const handleNew = async () => {
  // any meaningful content in the grid?
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
      return; // stop — user must balance first
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

  // Clear the form (use your existing helper)
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
    if (vendorId)  params.vend_code  = vendorId;   // your vendor dropdown stores vend_code
    if (sugarType) params.sugar_type = sugarType;
    if (cropYear)  params.crop_year  = cropYear;

    const { data } = await napi.get('/pbn/list', { params });

    const items: DropdownItem[] = (Array.isArray(data) ? data : []).map((r: any) => ({
      code: String(r.pbn_number),              // what you’ll save into booking_no
      label: String(r.pbn_number),
      description: fmtMDY(r.pbn_date),         // 2nd column
      // (keep extras if you ever want to show more columns)
      vendor_name: r.vendor_name,
      vend_code:   r.vend_code,
      sugar_type:  r.sugar_type,
      crop_year:   r.crop_year,
    }));
    setPbns(items);
  } catch {
    setPbns([]);
  }
};

// Load when key filters or search change
useEffect(() => { loadPbns(); },
  [user?.company_id, vendorId, sugarType, cropYear, pbnSearch]);




  // -------------------------------
  // Render
  // -------------------------------
  return (
    <div className="space-y-4 p-6">
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
      headers={["Id","Purchase No","Vendor","Date","Amount","Vendor Inv #"]}
      columnWidths={["60px","120px","160px","110px","120px","120px"]}
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
      disabled={locked}
      onChange={(e) => setPurchaseDate(e.target.value)}
      className="w-full border p-2 bg-blue-100 text-blue-900"
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
      headers={["Code","Description"]}
      columnWidths={["120px","260px"]}
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
      headers={["Code","Description"]}
      columnWidths={["120px","260px"]}
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
      headers={["Code","Description"]}
      columnWidths={["120px","260px"]}
      dropdownPositionStyle={{ width: '420px' }}
      inputClassName="p-2 text-sm bg-white"
    />
  </div>

  <div>
    <label className="block mb-1">Explanation</label>
    <input
      value={explanation}
      disabled={locked}
      onChange={(e) => setExplanation(e.target.value)}
      className="w-full border p-2 bg-blue-100 text-blue-900"
    />
  </div>

  {/* Row 5 — Booking # (single field) */}
  <div>
{/* Row 5 — Booking # */}
    <DropdownWithHeaders
    label="Booking #"
    value={bookingNo}
    onChange={setBookingNo}
    items={pbns}
    search={pbnSearch}
    onSearchChange={setPbnSearch}
    headers={["PBN No","PBN Date"]}          // 2 columns like your old app
    columnWidths={["140px","140px"]}
    dropdownPositionStyle={{ width: '360px' }}
    inputClassName="p-2 text-sm bg-white"
    />
  </div>
  {/* spacer to keep grid alignment */}
  <div></div>
</div>


        {/* Actions */}
        <div className="flex gap-2 mt-3">
          {!mainId ? (
            <button onClick={handleSaveMain} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">
              <CheckCircleIcon className="h-5 w-5" />
              Save
            </button>
          ) : (
            <>
              <button onClick={handleUpdateMain} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                <CheckCircleIcon className="h-5 w-5" />
                Update
              </button>

              <button onClick={handleCancelTxn} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-amber-500 text-white hover:bg-amber-600">
                Cancel
              </button>

              <button onClick={handleDeleteTxn} className="inline-flex items-center gap-2 px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700">
                Delete
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
            colHeaders={["Account Code","Account Description","Debit","Credit"]}
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
              { data: 'debit',  type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
              { data: 'credit', type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
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
                    if (!row?.id) { src.splice(rowIndex,1); setTableData([...src]); return; }
                    const ok = await Swal.fire({ title:'Delete this line?', icon:'warning', showCancelButton:true });
                    if (!ok.isConfirmed) return;
                    await napi.post('/purchase/delete-detail',{ id: row.id, transaction_id: mainId });
                    src.splice(rowIndex,1);
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
        <div className="relative inline-block" onMouseEnter={() => setDownloadOpen(true)} onMouseLeave={() => setDownloadOpen(false)}>
          <button type="button" disabled={!mainId} className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${mainId ? 'bg-white text-blue-700 border-blue-300 hover:bg-blue-50' : 'bg-gray-100 text-gray-400 border-gray-200'}`}>
            <ArrowDownTrayIcon className={`h-5 w-5 ${mainId ? 'text-blue-600' : 'text-gray-400'}`} />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {downloadOpen && (
            <div className="absolute left-0 top-full z-50" onClick={(e)=>e.stopPropagation()}>
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button type="button" onClick={handleDownloadExcel} disabled={!mainId} className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${mainId ? 'text-gray-800 hover:bg-blue-50' : 'text-gray-400 cursor-not-allowed'}`}>
                  <DocumentArrowDownIcon className={`h-5 w-5 ${mainId ? 'text-blue-600' : 'text-gray-400'}`} />
                  <span className="truncate">Purchase Voucher – Excel</span>
                  <span className="ml-auto text-[10px] font-semibold">XLSX</span>
                </button>
              </div>
            </div>
          )}

        </div>

        {/* PRINT */}
        <div className="relative inline-block" onMouseEnter={() => setPrintOpen(true)} onMouseLeave={() => setPrintOpen(false)}>
          <button type="button" className="inline-flex items-center gap-2 rounded border px-3 py-2 bg-white text-gray-700 hover:bg-gray-50">
            <PrinterIcon className="h-5 w-5" /><span>Print</span><ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>
          {printOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button type="button" onClick={handleOpenPdf} className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100">
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Purchase Voucher – PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">PDF</span>
                </button>
                <button
                  type="button"
                  onClick={()=>{
                    if (!mainId) return toast.info('Select or save a transaction.');
                    
                    window.open(
                      `${apiBase}/purchase/check-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id||'')}`,
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
            <button onClick={()=>setShowPdf(false)} className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200" aria-label="Close">✕</button>
            <div className="h-full w-full pt-8">
              <iframe title="Purchase Voucher PDF" src={pdfUrl} className="w-full h-full" style={{border:'none'}}/>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
