import { useEffect, useMemo, useRef, useState, useLayoutEffect } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';

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
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

// --- utils ---
function formatDateToYYYYMMDD(dateStr: string): string {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return (dateStr || '').split('T')[0] || ''; // fallback
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}


const onlyCode = (v?: string) => (v || '').split(';')[0];

type SalesDetailRow = {
  id?: number;
  acct_code: string;      // stores "code;desc"
  acct_desc?: string;
  debit?: number;
  credit?: number;
  persisted?: boolean;
};

type TxOption = {
  id: number | string;
  cs_no: string;
  cust_id: string;
  sales_date: string;
  sales_amount: number;
  si_no: string;
  explanation?: string;
  is_cancel?: string; // 'y' | 'n'
};

export default function SalesJournalForm() {
  const hotRef = useRef<HotTableClass>(null);

  // header form state
  const [csNo, setCsNo] = useState('');
  const [custId, setCustId] = useState('');
  const [custName, setCustName] = useState('');
  const [salesDate, setSalesDate] = useState('');
  const [explanation, setExplanation] = useState('');
  const [siNo, setSiNo] = useState('');
  const [mainId, setMainId] = useState<number | null>(null);
  const [locked, setLocked] = useState(false);

  // grid lock (separate from header lock)
  const [gridLocked, setGridLocked] = useState(true);

  // dropdown data
  const [customers, setCustomers] = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const [custSearch, setCustSearch] = useState('');

  // search transaction dropdown
  const [searchId, setSearchId] = useState<string>('');
  const [txSearch, setTxSearch] = useState<string>('');
  const [txOptions, setTxOptions] = useState<TxOption[]>([]);

  // grid
  const [tableData, setTableData] = useState<SalesDetailRow[]>([{ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false }]);
  const [hotEnabled, setHotEnabled] = useState(false);

  // menus
  const [printOpen, setPrintOpen] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  const [showPdf, setShowPdf] = useState(false);

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
  const ROW_HEIGHT = 28, HEADER_HEIGHT = 32, DROPDOWN_ROOM = 240;
  const dynamicHeight = useMemo(() => {
    const rows = Math.max(tableData.length, 6);
    const desired = HEADER_HEIGHT + rows * ROW_HEIGHT + DROPDOWN_ROOM;
    return Math.min(desired, maxGridHeight);
  }, [tableData.length, maxGridHeight]);

  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);

  // fetch customers (map to { code,label,description })
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/customers', { params: { company_id: user?.company_id }});
        const mapped: DropdownItem[] = (data || []).map((c: any) => ({
          code: String(c.cust_id ?? c.code),
          description: c.cust_name ?? c.description ?? '',
        }));
        setCustomers(mapped);
      } catch {
        setCustomers([]);
      }
    })();
  }, [user?.company_id]);

  // fetch account codes for grid autocomplete
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/accounts', { params: { company_id: user?.company_id }});
        setAccounts(Array.isArray(data) ? data : []);
      } catch {
        setAccounts([]);
      }
    })();
  }, [user?.company_id]);

  // search transaction (cash_sales) list
  const fetchTransactions = async () => {
    try {
      const { data } = await napi.get<TxOption[]>('/sales/list', {
        params: { company_id: user?.company_id || '', q: txSearch || '' },
      });
      setTxOptions(Array.isArray(data) ? data : []);
    } catch {
      setTxOptions([]);
    }
  };
  useEffect(() => {
    fetchTransactions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [txSearch]);

  // map TxOption[] -> DropdownItem[]
const txDropdownItems = useMemo<DropdownItem[]>(() => {
  return (txOptions || []).map((o) => ({
    // selection value
    code: String(o.id),

    // columns (in the same order as headers)
    cs_no: o.cs_no,
    cust_id: o.cust_id,
    sales_date: formatDateToYYYYMMDD(o.sales_date),        // <— date only
    sales_amount: o.sales_amount,                           // or: Number(o.sales_amount).toLocaleString()
    si_no: o.si_no,

    // what to show in the closed button
    label: o.cs_no,
    description: o.cust_id,
  }));
}, [txOptions]);

  const emptyRow = (): SalesDetailRow => ({ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false });

  const resetForm = () => {
    setSearchId('');
    setTxSearch('');
    setCsNo('');
    setCustId('');
    setCustName('');
    setSalesDate('');
    setExplanation('');
    setSiNo('');
    setMainId(null);
    setLocked(false);
    setGridLocked(false);
    setHotEnabled(false);
    setTableData([emptyRow()]);
  };

  // Save main
const handleSaveMain = async () => {
  // 1) Ask user first
  const ok = await Swal.fire({
    title: 'Confirm Save?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, Save',
  });
  if (!ok.isConfirmed) return;

  // 2) Preflight: block if there are OTHER unbalanced transactions in this company
  try {
    const existsResp = await napi.get('/sales/unbalanced-exists', {
      params: { company_id: user?.company_id || '' },
    });

    if (existsResp.data?.exists) {
      // fetch a short list to show in the popup
      const listResp = await napi.get('/sales/unbalanced', {
        params: { company_id: user?.company_id || '', limit: 20 },
      });
      const items = Array.isArray(listResp.data?.items) ? listResp.data.items : [];

      const htmlRows = items
        .map(
          (r: any) => `
          <tr>
            <td style="padding:6px 8px">${r.cs_no}</td>
            <td style="padding:6px 8px">${r.cust_id}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_debit || 0).toLocaleString()}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_credit || 0).toLocaleString()}</td>
          </tr>`
        )
        .join('');

      const html = `
        <div style="text-align:left">
          <div style="margin-bottom:8px">
            There are unbalanced transactions. Please balance them first before creating a new one.
          </div>
          <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
              <tr>
                <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">CS #</th>
                <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">Customer</th>
                <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Debit</th>
                <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Credit</th>
              </tr>
            </thead>
            <tbody>
              ${htmlRows || '<tr><td colspan="4" style="padding:6px 8px;color:#6b7280">No detail available</td></tr>'}
            </tbody>
          </table>
        </div>`;

      await Swal.fire({
        icon: 'warning',
        title: 'Unbalanced transactions found',
        html,
        confirmButtonText: 'OK',
      });

      return; // stop: don't proceed to save
    }
  } catch (err) {
    console.error('Unbalanced check failed', err);
    // If you prefer strict behavior, uncomment the next line to block on errors:
    // return;
  }

  // 3) Proceed with normal save
  try {
    const gen = await napi.get('/sales/generate-cs-number', {
      params: { company_id: user?.company_id },
    });
    const nextNo = gen.data?.cs_no ?? gen.data;
    setCsNo(nextNo);

    const res = await napi.post('/sales/save-main', {
      cs_no: nextNo,
      cust_id: custId,
      sales_date: salesDate,
      explanation,
      si_no: siNo,
      company_id: user?.company_id,
      user_id: user?.id,
    });

    setMainId(res.data.id);
    setHotEnabled(true);
    setTableData([emptyRow(), emptyRow()]);
    toast.success('Main saved. You can now input details.');
    fetchTransactions();

    // lock header after save; grid becomes editable for details
    setLocked(true);
    setGridLocked(false);
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save.');
  }
};


  // Update (unlock fields / grid)
  const handleUpdateMain = () => {
    setLocked(false);
    setGridLocked(false);
    toast.success('Editing enabled. You can now update this transaction.');
  };

  // Cancel
  const handleCancelTxn = async () => {
    if (!mainId) return;
    const confirmed = await Swal.fire({
      title: 'Cancel this transaction?',
      text: 'This will mark the transaction as CANCELLED.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, cancel it',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.post('/sales/cancel', {
        id: mainId,
        is_cancel: 'y',
        company_id: user?.company_id || '',
      });
      setLocked(true);
      setGridLocked(true);
      toast.success('Transaction has been cancelled.');
      fetchTransactions();
    } catch {
      toast.error('Failed to cancel transaction.');
    }
  };

  // Delete
  const handleDeleteTxn = async () => {
    if (!mainId) return;
    const confirmed = await Swal.fire({
      title: 'Delete this transaction?',
      text: 'This action is irreversible.',
      icon: 'error',
      showCancelButton: true,
      confirmButtonText: 'Delete',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.delete(`/sales/${mainId}`);
      resetForm();
      toast.success('Transaction deleted.');
      fetchTransactions();
    } catch {
      toast.error('Failed to delete transaction.');
    }
  };

  // row validator: only debit or credit > 0 (not both/zero) AND acct_code present
  const isRowValid = (r: SalesDetailRow) =>
    !!onlyCode(r.acct_code) && ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

  // autosave detail (note: send clean code)
  const handleAutoSave = async (row: SalesDetailRow, rowIndex: number) => {
    if (!mainId) return;
    if (!isRowValid(row)) return;

    const code = onlyCode(row.acct_code);
    try {
      if (!row.persisted) {
        const res = await napi.post('/sales/save-detail', {
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
          company_id: user?.company_id,
          user_id: user?.id,
        });
        const src = (hotRef.current?.hotInstance?.getSourceData() as SalesDetailRow[]) || [];
        // patch persisted flag in the HOT source, then sync React state
        if (src[rowIndex]) src[rowIndex].persisted = true, src[rowIndex].id = res.data.detail_id;
        setTableData([...src, ...(src.find(r => !r.acct_code) ? [] : [emptyRow()])]);
      } else {
        await napi.post('/sales/update-detail', {
          id: row.id,
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
        });
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Row save failed');
    }
  };

  // select transaction from search dropdown
  const handleSelectTransaction = async (selectedId: string) => {
    if (!selectedId) return;
    try {
      setSearchId(selectedId);
      const { data } = await napi.get(`/sales/${selectedId}`, {
        params: { company_id: user?.company_id },
      });

      const m = data.main ?? data;
      setMainId(m.id);
      setCsNo(m.cs_no);
      setCustId(String(m.cust_id || ''));
      const selected = customers.find((c) => String(c.code) === String(m.cust_id));
      setCustName(selected?.description || selected?.label || '');
      setSalesDate(formatDateToYYYYMMDD(m.sales_date));
      setExplanation(m.explanation ?? '');
      setSiNo(m.si_no ?? '');

      const details = (data.details || []).map((d: any) => ({
        id: d.id,
        acct_code: `${d.acct_code};${d.acct_desc || findDesc(d.acct_code)}`, // keep full value
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

  const acctSource = useMemo(() => {
    return accounts.map(a => `${a.acct_code};${a.acct_desc}`);
  }, [accounts]);

  const findDesc = (code: string) => {
    const hit = accounts.find(a => a.acct_code === code);
    return hit?.acct_desc || '';
  };

  // Download & Print
  const handleOpenPdf = () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    
    const url = `/api/sales/form-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id || '')}&t=${Date.now()}`;    
    
    setPdfUrl(url);
    setShowPdf(true);
  };
  const handleDownloadExcel = async () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    const res = await napi.get(`/sales/form-excel/${mainId}`, {
      responseType: 'blob',
      params: { company_id: user?.company_id||'' }
    });
    const name = res.headers['content-disposition']?.match(/filename="?([^"]+)"?/)?.[1] || `SalesVoucher_${csNo||mainId}.xlsx`;
    const blob = new Blob([res.data], { type: res.headers['content-type'] || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
  };

// totals helper
const getTotals = (rows: SalesDetailRow[]) => {
  const sumD = rows.reduce((t, r) => t + (Number(r.debit)  || 0), 0);
  const sumC = rows.reduce((t, r) => t + (Number(r.credit) || 0), 0);
  const balanced = Math.abs(sumD - sumC) < 0.005; // 0.5 cent tolerance
  return { sumD, sumC, balanced };
};

const fmtMoney = (n: number) =>
  (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });




  // New
const handleNew = async () => {
  // any meaningful content in the grid?
  const hasAnyDetail = tableData.some(
    r => (r.acct_code && r.acct_code.trim() !== '') || (Number(r.debit) || 0) > 0 || (Number(r.credit) || 0) > 0
  );

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

  // proceed to clear — same behavior you already had
  setSearchId('');
  setTxSearch('');
  setMainId(null);
  setCsNo('');
  setCustId('');
  setCustName('');
  setSalesDate('');
  setExplanation('');
  setSiNo('');
  setLocked(false);
  setGridLocked(false);
  setTableData([emptyRow(), emptyRow(), emptyRow(), emptyRow()]);
  setHotEnabled(false);
  toast.success('Form is ready for a new entry.');
};


  // auto-show list when entering the Account Code cell
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





  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-yellow-50 shadow-md rounded-lg p-6 space-y-4 border border-yellow-400">
        <h2 className="text-xl font-bold text-green-800 mb-2">SALES JOURNAL</h2>

        {/* DEBUG – remove later */}
        <div className="text-xs text-gray-500">
          accounts: {accounts.length} | hotEnabled: {String(hotEnabled)} | locked: {String(locked)} | gridLocked: {String(gridLocked)}
        </div>

        {/* Header form */}
        <div className="grid grid-cols-3 gap-4">
          {/* Row 1 — Search Transaction */}
          <div className="col-span-3">
            <DropdownWithHeaders
              label="Search Transaction"
              value={searchId}
              onChange={(v) => handleSelectTransaction(v)}
              items={txDropdownItems}
              search={txSearch}
              onSearchChange={setTxSearch}
              headers={['Id','Sales No','Customer','Date','Amount','S.I. #']}
              columnWidths={['60px','100px','160px','100px','110px','100px']}   // <— add this
              dropdownPositionStyle={{ width: '750px' }}                         // (optional) wider popup
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Row 2 — Customer (2 cols) + Date (1 col) */}
          <div className="col-span-2">
            <DropdownWithHeaders
              label="Customer"
              value={custId}
              onChange={(v) => {
                setCustId(v);
                const sel = customers.find((c) => String(c.code) === String(v));
                setCustName(sel?.description || '');
              }}
              items={customers}                 // [{ code, description }]
              search={custSearch}
              onSearchChange={setCustSearch}
              headers={['Code', 'Description']}
              columnWidths={['140px', '520px']} // <- set column widths here
              dropdownPositionStyle={{ width: '700px' }} // <- (optional) overall popup width
              inputClassName="p-2 text-sm bg-white"
            />

          </div>

          <div>
            <label className="block mb-1">Date</label>
            <input
              type="date"
              value={salesDate}
              disabled={locked}
              onChange={(e) => setSalesDate(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {/* Row 3 — Explanation (2 cols) + SI # (1 col) */}
          <div className="col-span-2">
            <label className="block mb-1">Explanation</label>
            <input
              value={explanation}
              disabled={locked}
              onChange={(e) => setExplanation(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          <div>
            <label className="block mb-1">S.I. #</label>
            <input
              value={siNo}
              disabled={locked}
              onChange={(e) => setSiNo(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {custName && (
            <div className="col-span-3 text-sm font-semibold text-gray-700">
              Customer: {custName}
            </div>
          )}
        </div>

        {/* Actions */}
        <div className="flex gap-2 mt-3">
          {!mainId ? (
            <button
              onClick={handleSaveMain}
              className="inline-flex items-center gap-2 px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700"
            >
              <CheckCircleIcon className="h-5 w-5" />
              Save
            </button>
          ) : (
            <>
              <button
                onClick={handleUpdateMain}
                className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
              >
                <CheckCircleIcon className="h-5 w-5" />
                Update
              </button>

              <button
                onClick={handleCancelTxn}
                className="inline-flex items-center gap-2 px-4 py-2 rounded bg-amber-500 text-white hover:bg-amber-600"
              >
                Cancel
              </button>

              <button
                onClick={handleDeleteTxn}
                className="inline-flex items-center gap-2 px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700"
              >
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
                filter: true,
                strict: true,            // MUST be one of 'source'
                allowInvalid: false,
                visibleRows: 12,
                readOnly: gridLocked,
                // show only the code though we store "code;desc"
                renderer: (inst, td, row, col, prop, value, cellProps) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(inst, td, row, col, prop, display, cellProps);
                },
              },
              { data: 'acct_desc', readOnly: true },
              { data: 'debit',  type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
              { data: 'credit', type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked },
            ]}
            // open full list when start editing the Account Code cell
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot) return;

              if (source === 'edit') {
                // Only programmatically set acct_desc and autosave; DO NOT overwrite acct_code here.
                changes.forEach(([rowIndex, prop, _oldVal, newVal]) => {
                  if (prop === 'acct_code') {
                    const full = String(newVal || '');
                    const code = onlyCode(full);
                    // update description programmatically so we don't fight the editor
                    hot.setDataAtRowProp(rowIndex, 'acct_desc', findDesc(code));

                    // read the current row from HOT source for autosave
                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as SalesDetailRow;
                    const payload: SalesDetailRow = {
                      id: rowObj.id,
                      acct_code: full,                 // keep full in grid
                      acct_desc: findDesc(code),
                      debit: Number(rowObj.debit || 0),
                      credit: Number(rowObj.credit || 0),
                      persisted: !!rowObj.persisted,
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }

                  if (prop === 'debit' || prop === 'credit') {
                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as SalesDetailRow;
                    const payload: SalesDetailRow = {
                      ...rowObj,
                      acct_code: rowObj.acct_code,
                      debit: Number(prop === 'debit' ? newVal : rowObj.debit || 0),
                      credit: Number(prop === 'credit' ? newVal : rowObj.credit || 0),
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }
                });

                // keep React state in sync after the editor finishes
                requestAnimationFrame(() => {
                  const src = hot.getSourceData() as SalesDetailRow[];
                  // ensure at least one empty row
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
                    const src = (hot?.getSourceData() as SalesDetailRow[]) || [];
                    const row = src[rowIndex];
                    if (!row?.id) { src.splice(rowIndex,1); setTableData([...src]); return; }
                    const ok = await Swal.fire({ title:'Delete this line?', icon:'warning', showCancelButton:true });
                    if (!ok.isConfirmed) return;
                    await napi.post('/sales/delete-detail',{ id: row.id, transaction_id: mainId });
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
        <div
          className="relative inline-block"
          onMouseEnter={() => setDownloadOpen(true)}
          onMouseLeave={() => setDownloadOpen(false)}
        >
          <button
            type="button"
            disabled={!mainId}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              mainId ? 'bg-white text-emerald-700 border-emerald-300 hover:bg-emerald-50' : 'bg-gray-100 text-gray-400 border-gray-200'
            }`}
          >
            <ArrowDownTrayIcon className={`h-5 w-5 ${mainId ? 'text-emerald-600' : 'text-gray-400'}`} />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {downloadOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadExcel}
                  disabled={!mainId}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    mainId ? 'text-gray-800 hover:bg-emerald-50' : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentArrowDownIcon className={`h-5 w-5 ${mainId ? 'text-emerald-600' : 'text-gray-400'}`} />
                  <span className="truncate">Sales Voucher – Excel</span>
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
                  <span className="truncate">Sales Voucher – PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">PDF</span>
                </button>
                <button
                  type="button"
                  onClick={()=>{
                    if (!mainId) return toast.info('Select or save a transaction.');            
                    window.open(`/api/sales/check-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id || '')}&t=${Date.now()}`, '_blank');                  
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
          className="inline-flex items-center gap-2 rounded border px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 focus:outline-none"
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
              <iframe title="Sales Voucher PDF" src={pdfUrl} className="w-full h-full" style={{border:'none'}}/>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
