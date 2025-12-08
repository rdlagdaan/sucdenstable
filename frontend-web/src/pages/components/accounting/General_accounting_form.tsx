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
  CheckCircleIcon, PlusIcon, ArrowDownTrayIcon, DocumentArrowDownIcon,
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

// --- utils ---
function formatDateToYYYYMMDD(dateStr: string): string {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return (dateStr || '').split('T')[0] || ''; // fallback for already YYYY-MM-DD
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

const onlyCode = (v?: string) => (v || '').split(';')[0];

type GaDetailRow = {
  id?: number;
  acct_code: string;      // stores "code;desc" in the grid
  acct_desc?: string;
  debit?: number;
  credit?: number;
  persisted?: boolean;
};

type GaTxOption = {
  id: number | string;
  ga_no: string;
  gen_acct_date: string;
  explanation?: string;
  sum_debit?: number;
  sum_credit?: number;
  is_cancel?: 'y' | 'n';
};

export default function General_accounting_form() {
  const hotRef = useRef<HotTableClass>(null);

  // header
  const [mainId, setMainId] = useState<number | null>(null);
  const [gaNo, setGaNo] = useState('');
  const [genAcctDate, setGenAcctDate] = useState('');
  const [explanation, setExplanation] = useState('');
  const [locked, setLocked] = useState(false);      // header lock
  const [gridLocked, setGridLocked] = useState(true);
  const [isCancelled, setIsCancelled] = useState(false);

  // dropdowns
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const [txOptions, setTxOptions] = useState<GaTxOption[]>([]);
  const [txSearch, setTxSearch] = useState('');
  const [searchId, setSearchId] = useState('');

  // grid
  const [tableData, setTableData] = useState<GaDetailRow[]>([{ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false }]);
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

  // fetch accounts (active)
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/ga/accounts', { params: { company_id: user?.company_id }});
        setAccounts(Array.isArray(data) ? data : []);
      } catch {
        setAccounts([]);
      }
    })();
  }, [user?.company_id]);

  // fetch transactions for the JE searchable dropdown
  const fetchTransactions = async () => {
    try {
      const { data } = await napi.get<GaTxOption[]>('/ga/list', {
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

  // map -> DropdownWithHeaders rows (JE No, Date, Explanation)
  const txDropdownItems = useMemo<DropdownItem[]>(() => {
    return (txOptions || []).map((o) => ({
      code: String(o.id),
      ga_no: o.ga_no,
      gen_acct_date: formatDateToYYYYMMDD(o.gen_acct_date),
      explanation: o.explanation || '',
      label: o.ga_no,
      description: o.explanation || '',
    }));
  }, [txOptions]);

  // helpers
  const emptyRow = (): GaDetailRow => ({ acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false });

  const resetForm = () => {
    setSearchId('');
    setTxSearch('');
    setMainId(null);
    setGaNo('');
    setGenAcctDate('');
    setExplanation('');
    setLocked(false);
    setGridLocked(false);
    setIsCancelled(false);
    setHotEnabled(false);
    setTableData([emptyRow()]);
  };

  // Save main (create header)
  const handleSaveMain = async () => {
    const ok = await Swal.fire({
      title: 'Confirm Save?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Save',
    });
    if (!ok.isConfirmed) return;

    // preflight: block if other GA transactions are unbalanced
    try {
      const existsResp = await napi.get('/ga/unbalanced-exists', { params: { company_id: user?.company_id || '' }});
      if (existsResp.data?.exists) {
        const listResp = await napi.get('/ga/unbalanced', { params: { company_id: user?.company_id || '', limit: 20 }});
        const items = Array.isArray(listResp.data?.items) ? listResp.data.items : [];
        const htmlRows = items.map((r: any) => `
          <tr>
            <td style="padding:6px 8px">${r.ga_no}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_debit || 0).toLocaleString()}</td>
            <td style="padding:6px 8px;text-align:right">${Number(r.sum_credit || 0).toLocaleString()}</td>
          </tr>
        `).join('');
        const html = `
          <div style="text-align:left">
            <div style="margin-bottom:8px">There are unbalanced journal entries. Please balance them first before creating a new one.</div>
            <table style="width:100%;border-collapse:collapse;font-size:12px">
              <thead>
                <tr>
                  <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">JE #</th>
                  <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Debit</th>
                  <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Credit</th>
                </tr>
              </thead>
              <tbody>${htmlRows || '<tr><td colspan="3" style="padding:6px 8px;color:#6b7280">No detail available</td></tr>'}</tbody>
            </table>
          </div>`;
        await Swal.fire({ icon: 'warning', title: 'Unbalanced found', html, confirmButtonText: 'OK' });
        return;
      }
    } catch {
      // ignore preflight failure
    }

    try {
      const gen = await napi.get('/ga/generate-ga-number', { params: { company_id: user?.company_id }});
      const nextNo = gen.data?.ga_no ?? gen.data;
      setGaNo(nextNo);

      const res = await napi.post('/ga/save-main', {
        ga_no: nextNo,
        gen_acct_date: genAcctDate,
        explanation,
        company_id: user?.company_id,
        user_id: user?.id,
      });
      setMainId(res.data.id);
      setHotEnabled(true);
      setTableData([emptyRow(), emptyRow()]);
      toast.success('Journal header saved. You can now input details.');

      // lock header; unlock grid
      setLocked(true);
      setGridLocked(false);
      fetchTransactions();
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save.');
    }
  };

  // Update (unlock header + grid)
  const handleUpdateMain = () => {
    setLocked(false);
    setGridLocked(false);
    toast.success('Editing enabled.');
  };

  // Cancel / Uncancel
  const handleCancelToggle = async (cancel: boolean) => {
    if (!mainId) return;
    const confirmed = await Swal.fire({
      title: cancel ? 'Cancel this entry?' : 'Uncancel this entry?',
      icon: cancel ? 'warning' : 'question',
      showCancelButton: true,
      confirmButtonText: cancel ? 'Yes, Cancel' : 'Yes, Uncancel',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.post('/ga/cancel', { id: mainId, flag: cancel ? '1' : '0' });
      setIsCancelled(cancel);
      setGridLocked(true);
      setLocked(true);
      toast.success(cancel ? 'Entry cancelled.' : 'Entry reactivated.');
      fetchTransactions();
    } catch {
      toast.error('Failed to update cancel status.');
    }
  };

  // Delete transaction
  const handleDeleteTxn = async () => {
    if (!mainId) return;
    const confirmed = await Swal.fire({
      title: 'Delete this journal?',
      text: 'This action cannot be undone.',
      icon: 'error',
      showCancelButton: true,
      confirmButtonText: 'Delete',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.delete(`/ga/${mainId}`);
      resetForm();
      toast.success('Journal deleted.');
      fetchTransactions();
    } catch {
      toast.error('Failed to delete journal.');
    }
  };

  // row validator: exactly one of debit/credit > 0 and acct_code present
  const isRowValid = (r: GaDetailRow) =>
    !!onlyCode(r.acct_code) && ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

  // save or update a line
  const handleAutoSave = async (row: GaDetailRow, rowIndex: number) => {
    if (!mainId || isCancelled) return;
    if (!isRowValid(row)) return;

    const code = onlyCode(row.acct_code);
    try {
      if (!row.persisted) {
        const res = await napi.post('/ga/save-detail', {
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
          company_id: user?.company_id,
          user_id: user?.id,
        });
        const hot = hotRef.current?.hotInstance;
        const src = (hot?.getSourceData() as GaDetailRow[]) || [];
        if (src[rowIndex]) src[rowIndex].persisted = true, src[rowIndex].id = res.data.detail_id;
        if (!src.find(r => !r.acct_code)) src.push(emptyRow());
        setTableData([...src]);
      } else {
        await napi.post('/ga/update-detail', {
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

  // load a transaction from dropdown
  const handleSelectTransaction = async (selectedId: string) => {
    if (!selectedId) return;
    try {
      setSearchId(selectedId);
      const { data } = await napi.get(`/ga/${selectedId}`, { params: { company_id: user?.company_id }});
      const m = data.main ?? data;

      setMainId(m.id);
      setGaNo(m.ga_no);
      setGenAcctDate(formatDateToYYYYMMDD(m.gen_acct_date));
      setExplanation(m.explanation ?? '');
      setIsCancelled(m.is_cancel === 'y');

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
      toast.success('Journal loaded.');
    } catch {
      toast.error('Unable to load the selected journal.');
    }
  };

  // account source for autocomplete (code;desc)
  const acctSource = useMemo(() => accounts.map(a => `${a.acct_code};${a.acct_desc}`), [accounts]);
  const findDesc = (code: string) => accounts.find(a => a.acct_code === code)?.acct_desc || '';

  // totals
  const totals = useMemo(() => {
    const sumD = tableData.reduce((t, r) => t + (Number(r.debit)  || 0), 0);
    const sumC = tableData.reduce((t, r) => t + (Number(r.credit) || 0), 0);
    const balanced = Math.abs(sumD - sumC) < 0.005;
    return { sumD, sumC, balanced };
  }, [tableData]);
  const fmtMoney = (n: number) =>
    (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // New
  const handleNew = async () => {
    const hasAnyDetail = tableData.some(
      r => (r.acct_code && r.acct_code.trim() !== '') || (Number(r.debit) || 0) > 0 || (Number(r.credit) || 0) > 0
    );

    if (hasAnyDetail && !totals.balanced) {
      await Swal.fire({
        title: 'Not balanced',
        html: `Debit <b>${fmtMoney(totals.sumD)}</b> must equal Credit <b>${fmtMoney(totals.sumC)}</b> before starting a new one.`,
        icon: 'error',
      });
      return;
    }

    const ok = await Swal.fire({
      title: 'Start a new journal?',
      text: 'This will clear the current form.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, New',
    });
    if (!ok.isConfirmed) return;

    setSearchId('');
    setTxSearch('');
    setMainId(null);
    setGaNo('');
    setGenAcctDate('');
    setExplanation('');
    setLocked(false);
    setGridLocked(false);
    setIsCancelled(false);
    setTableData([emptyRow(), emptyRow(), emptyRow()]);
    setHotEnabled(false);
    toast.success('Form is ready for a new entry.');
  };

  // auto-show list when entering Account Code cell
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

  // Print / Download
const handleOpenPdf = () => {
  if (!mainId) return toast.info('Select or save a journal first.');
  const url = `/api/ga/form-pdf/${mainId}?company_id=${encodeURIComponent(user?.company_id||'')}&_=${Date.now()}`;
  setPdfUrl(url);
  setShowPdf(true);
};


  
const handleDownloadExcel = async () => {
  if (!mainId) return toast.info('Select or save a journal first.');

  try {
    const res = await napi.get(`/ga/form-excel/${mainId}`, {
      responseType: 'blob',
      params: { company_id: user?.company_id || '' },
    });

    const ct = String(res.headers['content-type'] || '');

    // Guard: only proceed for real file responses
    if (
      !ct.includes('spreadsheet') &&
      !ct.includes('octet-stream') &&
      !ct.includes('application/vnd')
    ) {
      // If server sent JSON error body, show it
      if (ct.includes('application/json')) {
        try {
          const txt = await res.data.text?.() ?? await new Response(res.data).text();
          const j = JSON.parse(txt);
          toast.error(j?.message || 'Export failed.');
        } catch {
          toast.error('Excel export failed (unexpected response).');
        }
      } else {
        toast.error('Excel export failed (unexpected response).');
      }
      return;
    }

    // Filename from header or fallback
    const cd = String(res.headers['content-disposition'] || '');
    const m  = cd.match(/filename\*?=(?:UTF-8'')?("?)([^";]+)\1/i) || [];
    const name = decodeURIComponent(m[2] || `JournalVoucher_${gaNo || mainId}.xlsx`);

    // Download
    const blob = new Blob([res.data], { type: ct || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e: any) {
    // If backend returned 422 with JSON, Axios still gives a Blob
    const blob = e?.response?.data;
    if (blob instanceof Blob) {
      try {
        const txt = await blob.text();
        const j = JSON.parse(txt);
        toast.error(j?.message || 'Export failed.');
        return;
      } catch {/* fall through */}
    }
    toast.error(e?.response?.data?.message || 'Export failed.');
  }
};


  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-yellow-50 shadow-md rounded-lg p-6 space-y-4 border border-yellow-400">
        <h2 className="text-xl font-bold text-green-800 mb-2">GENERAL ACCOUNTING — JOURNAL ENTRY</h2>

        {/* Header form */}
        <div className="grid grid-cols-3 gap-4">
          {/* Search Transaction (JE No, Date, Explanation) */}
          <div className="col-span-3">
            <DropdownWithHeaders
              label="Search JE"
              value={searchId}
              onChange={(v) => handleSelectTransaction(v)}
              items={txDropdownItems}
              search={txSearch}
              onSearchChange={setTxSearch}
              headers={['Id','JE No','Date','Explanation']}
              columnWidths={['60px','120px','120px','420px']}
              dropdownPositionStyle={{ width: '850px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Date */}
          <div>
            <label className="block mb-1">Date</label>
            <input
              type="date"
              value={genAcctDate}
              disabled={locked || isCancelled}
              onChange={(e) => setGenAcctDate(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {/* Explanation */}
          <div className="col-span-2">
            <label className="block mb-1">Explanation</label>
            <input
              value={explanation}
              disabled={locked || isCancelled}
              onChange={(e) => setExplanation(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {/* JE No + Totals */}
          <div className="col-span-3 flex items-center gap-6 text-sm">
            <div className="font-semibold">
              JE No: <span className="text-gray-800">{gaNo || '—'}</span>
            </div>
            <div className="font-semibold">
              Total Debit: <span className="text-blue-700">{fmtMoney(totals.sumD)}</span>
            </div>
            <div className="font-semibold">
              Total Credit: <span className="text-blue-700">{fmtMoney(totals.sumC)}</span>
            </div>
            <div className={`px-2 py-0.5 rounded text-white ${totals.balanced ? 'bg-emerald-600' : 'bg-red-500'}`}>
              {totals.balanced ? 'Balanced' : 'Unbalanced'}
            </div>
            {isCancelled && <div className="px-2 py-0.5 rounded bg-amber-600 text-white">CANCELLED</div>}
          </div>
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
              {!isCancelled && (
                <button
                  onClick={handleUpdateMain}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
                >
                  <CheckCircleIcon className="h-5 w-5" />
                  Update
                </button>
              )}

              {isCancelled ? (
                <button
                  onClick={() => handleCancelToggle(false)}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700"
                >
                  Uncancel
                </button>
              ) : (
                <button
                  onClick={() => handleCancelToggle(true)}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded bg-amber-500 text-white hover:bg-amber-600"
                >
                  Cancel
                </button>
              )}

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
                strict: true,
                allowInvalid: false,
                visibleRows: 14,
                readOnly: gridLocked || isCancelled,
                renderer: (inst, td, row, col, prop, value, cellProps) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(inst, td, row, col, prop, display, cellProps);
                },
              },
              { data: 'acct_desc', readOnly: true },
              { data: 'debit',  type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked || isCancelled },
              { data: 'credit', type:'numeric', numericFormat:{ pattern:'0,0.00' }, readOnly: gridLocked || isCancelled },
            ]}
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot || isCancelled) return;

              if (source === 'edit') {
                changes.forEach(([rowIndex, prop, _oldVal, newVal]) => {
                  if (prop === 'acct_code') {
                    const full = String(newVal || '');
                    const code = onlyCode(full);
                    hot.setDataAtRowProp(rowIndex, 'acct_desc', findDesc(code));

                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as GaDetailRow;
                    const payload: GaDetailRow = {
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
                    const rowObj = { ...(hot.getSourceDataAtRow(rowIndex) as any) } as GaDetailRow;
                    const payload: GaDetailRow = {
                      ...rowObj,
                      acct_code: rowObj.acct_code,
                      debit: Number(prop === 'debit' ? newVal : rowObj.debit || 0),
                      credit: Number(prop === 'credit' ? newVal : rowObj.credit || 0),
                    };
                    setTimeout(() => handleAutoSave(payload, rowIndex), 0);
                  }
                });

                requestAnimationFrame(() => {
                  const src = hot.getSourceData() as GaDetailRow[];
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
                    if (gridLocked || isCancelled) return;
                    const hot = hotRef.current?.hotInstance;
                    const rowIndex = selection[0].start.row;
                    const src = (hot?.getSourceData() as GaDetailRow[]) || [];
                    const row = src[rowIndex];
                    if (!row?.id) { src.splice(rowIndex,1); setTableData([...src]); return; }
                    const ok = await Swal.fire({ title:'Delete this line?', icon:'warning', showCancelButton:true });
                    if (!ok.isConfirmed) return;
                    await napi.post('/ga/delete-detail',{ id: row.id, transaction_id: mainId });
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
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadExcel}
                  disabled={!mainId}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    mainId ? 'text-gray-800 hover:bg-emerald-50' : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentArrowDownIcon className={`h-5 w-5 ${mainId ? 'text-emerald-600' : 'text-gray-400'}`} />
                  <span className="truncate">Journal Voucher – Excel</span>
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
              <div className="mt-1 w-72 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleOpenPdf}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Journal Voucher – PDF</span>
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
          title="Start a new journal"
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
              <iframe title="Journal Voucher PDF" src={pdfUrl} className="w-full h-full" style={{border:'none'}}/>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
