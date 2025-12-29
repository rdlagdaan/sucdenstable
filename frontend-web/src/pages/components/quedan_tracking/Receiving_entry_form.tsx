// src/pages/components/quedan_tracking/ReceivingEntry.tsx
import { useEffect, useMemo, useRef, useState, useCallback } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';

//import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';
//import DropdownWithHeaders from '../DropdownWithHeaders';
import DropdownWithHeadersDynamic from '../DropdownWithHeadersDynamic';

//import { ChevronDownIcon, PrinterIcon, PlusIcon, CheckCircleIcon } from '@heroicons/react/24/outline';
// ✅ ReceivingEntry.tsx — update to add legacy-like Download + Print dropdowns
// 1) Add imports (replace your heroicons import line with this)

import {
  ChevronDownIcon,
  PrinterIcon,
  PlusIcon,
  CheckCircleIcon,
  ArrowDownTrayIcon, // ✅ NEW
} from '@heroicons/react/24/outline';


import AttachedDropdown from '../../components/AttachedDropdown';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

type RRDropdownItem = {
  receipt_no: string;
  quantity: number;
  sugar_type: string;
  pbn_number: string;
  receipt_date: string;
  vendor_code: string;
  vendor_name: string;
};



type PBNItemRow = {
  row: number;
  mill: string;
  quantity: number;
  unit_cost: number;
  commission: number;
  mill_code?: string | null;
};

type ReceivingDetailRow = {
  id?: number;
  row?: number;
  reciben_no?: string;
  quedan_no?: string;
  quantity?: number;
  liens?: number;
  week_ending?: string | null;
  date_issued?: string | null;
  planter_tin?: string;
  planter_name?: string;
  item_no?: string;
  mill?: string;
  unit_cost?: number;
  commission?: number;
  storage?: number;
  insurance?: number;
  total_ap?: number;
  persisted?: boolean;
};

type PbnDropdownItem = {
  pbn_number: string;
  sugar_type: string;
  vendor_code: string;
  vendor_name: string;
  crop_year: string;
  pbn_date: string;
};



const currency = (n: number) =>
  Number(Math.round((n || 0) * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2 });

const toISO = (d: string | null | undefined) => (!d ? '' : new Date(d).toISOString().slice(0, 10));

export default function ReceivingEntry() {
  
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = user?.company_id;


  const requireCompany = (): boolean => {
    if (!companyId) {
      toast.error('Missing company id; please sign in again.');
      return false;
    }
    return true;
  };

  
  const hotRef = useRef<HotTableClass>(null);

  // ---- main header state ----
  const [includePosted, setIncludePosted] = useState(false);
  const [rrOptions, setRrOptions] = useState<RRDropdownItem[]>([]);
  const [rrSearch, setRrSearch] = useState('');
  const [selectedRR, setSelectedRR] = useState(''); // receipt_no

  const [dateReceived, setDateReceived] = useState('');
  const [pbnNumber, setPbnNumber] = useState('');
  const [itemNumber, setItemNumber] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [mill, setMill] = useState('');
  const [glAccountKey, setGlAccountKey] = useState('');

  // ---- GL Account dropdown ----


  const [assocDues, setAssocDues] = useState<number | ''>('');
  const [others, setOthers] = useState<number | ''>('');
  const [noStorage, setNoStorage] = useState(false);
  const [noInsurance, setNoInsurance] = useState(false);
  const [storageWeek, setStorageWeek] = useState<string>('');
  const [insuranceWeek, setInsuranceWeek] = useState<string>('');

  // ---- PBN + Item dropdowns ----
  const [pbnOptions, setPbnOptions] = useState<PbnDropdownItem[]>([]);
  const [pbnSearch, setPbnSearch] = useState('');
  const [itemOptions, setItemOptions] = useState<PBNItemRow[]>([]);

type GlAccountItem = { acct_code: string; acct_desc: string };

const [glOptions, setGlOptions] = useState<GlAccountItem[]>([]);
const [glSearch, setGlSearch] = useState('');

const glDisplay = useMemo(() => {
  if (!glAccountKey) return '';
  const row = glOptions.find((x) => String(x.acct_code) === String(glAccountKey));
  return `${glAccountKey} — ${row?.acct_desc ?? ''}`;
}, [glAccountKey, glOptions]);


  // ---- mill rates / formula inputs ----
  const [unitCost, setUnitCost] = useState(0);
  const [commission, setCommission] = useState(0);
  const [insuranceRate, setInsuranceRate] = useState(0);
  const [storageRate, setStorageRate] = useState(0);
  const [daysFree, setDaysFree] = useState(0);

  // ---- grid state ----
  const [tableData, setTableData] = useState<ReceivingDetailRow[]>([]);
  const [handsontableEnabled, setHandsontableEnabled] = useState(false);

  // ---- totals ----
  const [tQty, setTQty] = useState(0);
  const [tLiens, setTLiens] = useState(0);
  const [tStorage, setTStorage] = useState(0);
  const [tInsurance, setTInsurance] = useState(0);
  const [tUnitCost, setTUnitCost] = useState(0);
  const [tAP, setTAP] = useState(0);

const [cropYear, setCropYear] = useState<string>('');

// --- Receiving Report PDF modal state ---
const [showRrPdf, setShowRrPdf] = useState(false);
const [rrPdfUrl, setRrPdfUrl] = useState<string>('');



const requireFields = (...pairs: Array<[string, string | undefined | null]>) => {
  const missing = pairs
    .filter(([, v]) => !v || String(v).trim() === '')
    .map(([label]) => label);
  if (missing.length) {
    toast.error(`Please fill: ${missing.join(', ')}`);
    return false;
  }
  return true;
};




  // ----------------- DATA LOADING -----------------
  const fieldSize =
    "h-8 px-3 py-2.5 text-base leading-6 rounded border border-gray-300";


  const pbnDisplay = pbnNumber ? `${pbnNumber} — ${vendorName || ''}` : '';
  const itemDropdownRef = useRef<HTMLDivElement>(null);
  
  // (near other state)
  const itemDisplay = itemNumber ? `${itemNumber} — ${mill || ''}` : '';

const pct = (n: number) =>
  Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 4, maximumFractionDigits: 4 }) + '%';


  // RR list
useEffect(() => {
  (async () => {
    const resp = await napi.get('/receiving/rr-list', {
      params: { include_posted: includePosted ? '1' : '0', q: rrSearch },
    });

    const arr = Array.isArray(resp.data)
      ? resp.data
      : Array.isArray(resp.data?.data)
      ? resp.data.data
      : [];

    setRrOptions(arr);
  })().catch(console.error);
}, [includePosted, rrSearch]);


  // PBN list (always include posted=1, per legacy)
// PBN list (POSTED only; backend requires company_id)
useEffect(() => {
  (async () => {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;
    const companyId = user?.company_id;

    if (!companyId) {
      setPbnOptions([]);
      return;
    }

    const resp = await napi.get('/pbn/list', {
      params: {
        company_id: companyId,   // ✅ REQUIRED by backend
        q: pbnSearch,
      },
    });

    // Accept both: `[]` or `{ data: [] }`
    const arr =
      Array.isArray(resp.data) ? resp.data :
      Array.isArray(resp.data?.data) ? resp.data.data :
      [];

    setPbnOptions(arr);
    // optional debug
    console.log('PBN options loaded →', arr.length, arr[0]);
  })().catch((e) => {
    console.error(e);
    setPbnOptions([]);
  });
}, [pbnSearch]);


useEffect(() => {
  (async () => {
    if (!requireCompany()) {
      setGlOptions([]);
      return;
    }
    const resp = await napi.get('/references/accounts', {
      params: { q: glSearch, company_id: companyId },
    });

    const arr =
      Array.isArray(resp.data) ? resp.data :
      Array.isArray(resp.data?.data) ? resp.data.data :
      [];

    setGlOptions(arr);
  })().catch((e) => {
    console.error(e);
    setGlOptions([]);
  });
}, [glSearch, companyId]);




// ---- planter lookup cache + debounce ----
const planterNameCacheRef = useRef<Map<string, string>>(new Map());
const planterLookupTimerRef = useRef<Record<number, number>>({});
const planterLookupInFlightRef = useRef<Record<number, boolean>>({});

type MillOption = { mill_name: string };

const [millOptions, setMillOptions] = useState<MillOption[]>([]);
const [millSearch, setMillSearch] = useState('');
const [showMillPicker, setShowMillPicker] = useState(false);


const normalizeTin = (v: any) => String(v ?? '').trim();

const fetchPlanterNameByTin = useCallback(
  async (tin: string): Promise<string> => {
    const t = normalizeTin(tin);
    if (!t) return '';

    // cache hit
    const cached = planterNameCacheRef.current.get(t);
    if (cached !== undefined) return cached;

    // call your existing PlanterController index route
    // GET /api/references/planters?search=<tin>&per_page=10
    const resp = await napi.get('/references/planters', {
      params: { search: t, per_page: 10 },
    });

    // accept paginator or plain array
    const list = Array.isArray(resp.data)
      ? resp.data
      : Array.isArray(resp.data?.data)
      ? resp.data.data
      : [];

    const exact = list.find((x: any) => String(x?.tin ?? '').trim() === t);
    const name = String(exact?.display_name ?? '');

    planterNameCacheRef.current.set(t, name);
    return name;
  },
  []
);


// ---- planter TIN autocomplete options (async) ----
type PlanterOption = { tin: string; display_name: string };

const planterOptionsCacheRef = useRef<Map<string, PlanterOption[]>>(new Map());

const fetchPlanterOptions = useCallback(async (term: string): Promise<PlanterOption[]> => {
  const q = normalizeTin(term);
  const key = q.toLowerCase();

  // cache hit (including empty array)
  if (planterOptionsCacheRef.current.has(key)) {
    return planterOptionsCacheRef.current.get(key)!;
  }

  const resp = await napi.get('/references/planters', {
    params: { search: q, per_page: 20 },
  });

  const list = Array.isArray(resp.data)
    ? resp.data
    : Array.isArray(resp.data?.data)
    ? resp.data.data
    : [];

  const rows: PlanterOption[] = list
    .map((x: any) => ({
      tin: normalizeTin(x?.tin),
      display_name: String(x?.display_name ?? '').trim(),
    }))
    .filter((x: PlanterOption) => x.tin !== '');

  // also feed name cache for instant fill
  rows.forEach((r) => planterNameCacheRef.current.set(r.tin, r.display_name));

  planterOptionsCacheRef.current.set(key, rows);
  return rows;
}, []);



const onSelectGL = async (acct_code: string) => {
  setGlAccountKey(acct_code);
  if (!selectedRR) return;
  try {
    await napi.post('/receiving/update-gl', {
      receipt_no: selectedRR,
      gl_account_key: acct_code,
    });
    toast.success('GL Account updated');
  } catch {
    toast.error('Failed to update GL Account');
  }
};


const filteredRR = useMemo(() => {
  const term = rrSearch.toLowerCase();
  const list = Array.isArray(rrOptions) ? rrOptions : [];  // ✅ guard
  return list.filter(
    (r) =>
      (r.receipt_no || '').toLowerCase().includes(term) ||
      (r.pbn_number || '').toLowerCase().includes(term) ||
      (r.vendor_name || '').toLowerCase().includes(term),
  );
}, [rrOptions, rrSearch]);


const applyItemSelection = async (i: PBNItemRow) => {
  setItemNumber(String(i.row));
  setMill(i.mill || '');

  try {
    const { data } = await napi.get('/receiving/pricing-context', {
      params: {
        pbn_number: pbnNumber,
        item_no: String(i.row),
        mill_name: i.mill || mill || '',
        crop_year: cropYear || undefined, 
        // company_id not needed if X-Company-ID is attached by axiosnapi (yours is)
      },
    });

    setUnitCost(Number(data?.unit_cost || 0));
    setCommission(Number(data?.commission || 0));
    setInsuranceRate(Number(data?.insurance_rate || 0));
    setStorageRate(Number(data?.storage_rate || 0));
    setDaysFree(Number(data?.days_free || 0));

    // keep mill in sync if backend chooses pbn-detail mill
    if (data?.mill) setMill(String(data.mill));
  } catch {
    // fallback to local values if API fails
    setUnitCost(Number(i.unit_cost || 0));
    setCommission(Number(i.commission || 0));
  }
};





  // When RR selected (pre-fill everything)
  const onSelectRR = async (receiptNo: string) => {
    try {
      setSelectedRR(receiptNo);

      const { data: entry } = await napi.get('/receiving/entry', { params: { receipt_no: receiptNo } });
      setDateReceived(toISO(entry.receipt_date) || '');
      setPbnNumber(entry.pbn_number || '');
      setItemNumber(entry.item_number || '');
      setVendorName(entry.vendor_name || '');
      setMill(entry.mill || '');
      setGlAccountKey(entry.gl_account_key || '');
      setAssocDues(entry.assoc_dues ?? '');
      setOthers(entry.others ?? '');
      setNoStorage(!!entry.no_storage);
      setNoInsurance(!!entry.no_insurance);
      setInsuranceWeek(entry.insurance_week ? toISO(entry.insurance_week) : '');
      setStorageWeek(entry.storage_week ? toISO(entry.storage_week) : '');

      // preload PBN list row into dropdown (so it shows vendor etc.)
      if (entry.pbn_number) {
        // also fetch its items for the Item # combobox and preselect current item
const [{ data: pbnItems }, { data: pbnItemOne }, { data: ctx }] = await Promise.all([
  napi.get('/pbn/items', { params: { company_id: companyId, pbn_number: entry.pbn_number } }),
  napi.get('/receiving/pbn-item', { params: { pbn_number: entry.pbn_number, item_no: entry.item_number } }),
  napi.get('/receiving/pricing-context', {
    params: {
      pbn_number: entry.pbn_number,
      item_no: entry.item_number,
      mill_name: entry.mill,
      crop_year: entry.crop_year || cropYear || undefined,
      // company_id not required if axiosnapi sends X-Company-ID (yours does)
    },
  }),
]);

setItemOptions(Array.isArray(pbnItems) ? pbnItems : []);
setUnitCost(Number(pbnItemOne?.unit_cost || 0));
setCommission(Number(pbnItemOne?.commission || 0));

// ✅ authoritative rates + crop year for Rate Basis message
setInsuranceRate(Number(ctx?.insurance_rate || 0));
setStorageRate(Number(ctx?.storage_rate || 0));
setDaysFree(Number(ctx?.days_free || 0));
setCropYear(String(ctx?.crop_year || ''));

// keep mill synced if backend corrected it
if (ctx?.mill) setMill(String(ctx.mill));

      }





      // load detail rows
// load detail rows
// load detail rows
const { data: details } = await napi.get('/receiving/details', { params: { receipt_no: receiptNo } });

const loaded: ReceivingDetailRow[] = Array.isArray(details)
  ? details.map((d: any) => ({ ...d, persisted: true }))
  : [];

// ✅ ALWAYS keep one ready row for input
const withBuffer = ensureTrailingBuffer(loaded);
setTableData(withBuffer);


setHandsontableEnabled(true);

// (optional but helps HOT repaint instantly)
requestAnimationFrame(() => {
  hotRef.current?.hotInstance?.loadData(withBuffer as any);
});


    } catch (e) {
      console.error(e);
      toast.error('Failed to load Receiving Entry.');
    }
  };

  // ----------------- PBN + ITEM BEHAVIOR -----------------

// DROP-IN: replace your entire onSelectPBN with this version
const onSelectPBN = async (pbn: string) => {
  try {
    setPbnNumber(pbn);

    // ✅ use your existing helper (no duplicate localStorage checks needed here)
    if (!requireCompany()) return;

    const row = pbnOptions.find((r) => r.pbn_number === pbn);
    setVendorName(row?.vendor_name || '');
setCropYear(String(row?.crop_year || ''));
    // ✅ assume companyId is already available in component scope
    // (define once near top of component, from localStorage)
    const { data } = await napi.get('/pbn/items', {
      params: { company_id: companyId, pbn_number: pbn },
    });

    const itemsArr: PBNItemRow[] = Array.isArray(data) ? data : [];
    setItemOptions(itemsArr);

    if (itemsArr.length === 1) {
      await applyItemSelection(itemsArr[0]);
      return;
    }

    // reset + focus Item # so the user picks
    setItemNumber('');
    setMill('');
    setUnitCost(0);
    setCommission(0);

    setTimeout(() => {
      itemDropdownRef.current?.querySelector('input')?.focus();
    }, 0);
  } catch (e) {
    console.error(e);
    toast.error('Failed to load PBN items.');
  }
};




const RateBasisBar = () => (
  <div className="text-xs rounded border px-3 py-2 bg-slate-50 border-slate-300 flex flex-wrap items-center gap-2">
    <span className="font-semibold text-slate-700">Rate Basis:</span>

    <span className="px-2 py-0.5 rounded bg-indigo-100 text-indigo-800 border border-indigo-200">
      Mill: <b>{mill || '-'}</b>
    </span>

    <span className="px-2 py-0.5 rounded bg-violet-100 text-violet-800 border border-violet-200">
      Crop Year: <b>{cropYear || '-'}</b>
    </span>

    <span className="px-2 py-0.5 rounded bg-blue-100 text-blue-800 border border-blue-200">
      Storage: <b>{pct(storageRate)}</b>
    </span>

    <span className="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 border border-emerald-200">
      Insurance: <b>{pct(insuranceRate)}</b>
    </span>

    {daysFree > 0 && (
      <span className="px-2 py-0.5 rounded bg-amber-100 text-amber-800 border border-amber-200">
        Days Free: <b>{daysFree}</b>
      </span>
    )}

    {(storageRate === 0 && insuranceRate === 0) && (
      <span className="px-2 py-0.5 rounded bg-red-100 text-red-800 border border-red-200">
        ⚠️ Rates not loaded (check mill/crop_year/company)
      </span>
    )}
  </div>
);

// 2) Add these helpers INSIDE ReceivingEntry() (place near other helpers, before the return)

type ActionMenuItem = {
  label: string;
  onClick: () => void | Promise<void>;
};

function useOutsideClick(
  refs: Array<React.RefObject<HTMLElement | null>>,
  onOutside: () => void,
  enabled: boolean
) {
  useEffect(() => {
    if (!enabled) return;

    const handler = (e: MouseEvent) => {
      const t = e.target as Node;
      const inside = refs.some((r) => r.current && r.current.contains(t));
      if (!inside) onOutside();
    };

    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [refs, onOutside, enabled]);
}

const downloadBlob = (blob: Blob, filename: string) => {
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(url);
};

/** ✅ IMPORTANT: these MUST be above exportEndpoints (because exportEndpoints uses addQuery) */
const apiBase =
  (napi as any)?.defaults?.baseURL?.replace(/\/$/, '') || '';

const buildApiUrl = (pathWithLeadingSlash: string) => {
  if (!pathWithLeadingSlash.startsWith('/')) return pathWithLeadingSlash;
  if (apiBase) return `${apiBase}${pathWithLeadingSlash}`;
  return pathWithLeadingSlash;
};

const addQuery = (path: string, params: Record<string, any>) => {
  const u = new URL(buildApiUrl(path), window.location.origin);
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    u.searchParams.set(k, String(v));
  });
  return u.toString();
};

// ✅ NOTE: Replace these endpoints with your real backend routes.
// I’m wiring them in a clean centralized way so you only edit here.
const exportEndpoints = useMemo(() => {
  if (!selectedRR) {
    return {
      dl_quedan_excel: '',
      dl_quedan_inssto_excel: '',
      pr_quedan_pdf: '',
      pr_quedan_inssto_pdf: '',
      pr_receiving_report_pdf: '',
    };
  }

  const common = { company_id: companyId, receipt_no: selectedRR, _: Date.now() };

  return {
    // ✅ Downloads
    dl_quedan_excel: addQuery('/receiving/quedan-listing-excel', common),
    dl_quedan_inssto_excel: addQuery('/receiving/quedan-listing-insurance-storage-excel', common),

    // ✅ Prints
    pr_quedan_pdf: addQuery('/receiving/quedan-listing-pdf', common),
    pr_quedan_inssto_pdf: addQuery('/receiving/quedan-listing-insurance-storage-pdf', common),

    // ✅ Receiving Report PDF
    pr_receiving_report_pdf: addQuery(
      `/receiving/receiving-report-pdf/${encodeURIComponent(selectedRR)}`,
      { company_id: companyId, _: Date.now() }
    ),
  };
}, [selectedRR, companyId]);

const doExcelDownload = async (url: string, filename: string) => {
  if (!selectedRR || !url) return;
  try {
    const resp = await napi.get(url, { responseType: 'blob' as any });
    downloadBlob(resp.data, filename);
  } catch (e: any) {
    console.error(e);
    toast.error(e?.response?.data?.message || 'Download failed (check export endpoint).');
  }
};




const openReceivingReportPdfModal = async () => {
  if (!selectedRR) return;

  if (rrPdfUrl) {
    URL.revokeObjectURL(rrPdfUrl);
    setRrPdfUrl('');
  }

  try {
    const resp = await napi.get(
      `/receiving/receiving-report-pdf/${encodeURIComponent(selectedRR)}`,
      {
        params: { company_id: companyId, _: Date.now() },
        responseType: 'arraybuffer',     // ✅ best for PDFs
        withCredentials: true,           // ✅ if you use sanctum/cookies
        headers: { Accept: 'application/pdf' },
      }
    );

    const contentType =
      String(resp.headers?.['content-type'] || resp.headers?.['Content-Type'] || '').toLowerCase();

    // ✅ If server didn’t return a PDF, decode and show what it DID return
    if (!contentType.includes('application/pdf')) {
      const txt = new TextDecoder('utf-8').decode(resp.data);
      console.error('Receiving Report NOT PDF:', { contentType, txt });
      toast.error(`Server returned ${contentType || 'unknown'} (not PDF)`);
      if (txt) toast.info(txt.slice(0, 200));
      return;
    }

    const blob = new Blob([resp.data], { type: 'application/pdf' });

    // ✅ Signature check: PDFs start with "%PDF"
    const head = await blob.slice(0, 5).text();
    if (head !== '%PDF-') {
      const preview = await blob.slice(0, 300).text().catch(() => '');
      console.error('Invalid PDF signature:', { head, preview });
      toast.error('Response is not a valid PDF stream (corrupted output).');
      return;
    }

    const url = URL.createObjectURL(blob);
    setRrPdfUrl(url);
    setShowRrPdf(true);
  } catch (e: any) {
    console.error(e);
    toast.error(e?.response?.data?.message || 'Failed to load Receiving Report PDF.');
  }
};





type ActionDropdownProps = {
  disabled?: boolean;
  icon: React.ReactNode;
  label: string;
  items: ActionMenuItem[];
};

const ActionDropdown = ({ disabled, icon, label, items }: ActionDropdownProps) => {
  const [open, setOpen] = useState(false);
  const wrapRef = useRef<HTMLDivElement>(null);

  useOutsideClick([wrapRef], () => setOpen(false), open);

  return (
    <div
      ref={wrapRef}
      className="relative inline-flex"
      onMouseEnter={() => !disabled && setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      <button
        type="button"
        disabled={!!disabled}
        onClick={() => !disabled && setOpen((s) => !s)}
        className={[
          'inline-flex items-center gap-2 rounded border px-3 py-2',
          'bg-sky-100 border-sky-300 text-slate-800',
          'hover:bg-sky-200',
          disabled ? 'opacity-60 cursor-not-allowed hover:bg-sky-100' : '',
        ].join(' ')}
      >
        {icon}
        <span className="text-sm">{label}</span>
        <ChevronDownIcon className="h-4 w-4" />
      </button>

      {open && !disabled && (
        <div className="absolute left-0 top-full mt-1 w-[360px] rounded border bg-white shadow-lg z-50">
          <div className="py-1">
            {items.map((it) => (
              <button
                key={it.label}
                type="button"
                onClick={async () => {
                  setOpen(false);
                  await it.onClick();
                }}
                className="w-full text-left px-3 py-2 text-[14px] text-blue-600 hover:bg-slate-100"
              >
                {it.label}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};


const onSelectItem = async (item: string | PBNItemRow) => {
  const i = typeof item === 'string'
    ? itemOptions.find(x => String(x.row) === String(item))
    : item;

  if (!i) {
    if (typeof item === 'string') setItemNumber(item); // reflect typed value
    return;
  }
  await applyItemSelection(i);
};



const normQuedan = (v: any) => String(v ?? '').trim().toLowerCase();

const isDuplicateQuedan = (rows: ReceivingDetailRow[], rowIndex: number, quedan: string) => {
  const q = normQuedan(quedan);
  if (!q) return false;

  for (let i = 0; i < rows.length; i++) {
    if (i === rowIndex) continue;
    if (normQuedan(rows[i]?.quedan_no) === q) return true;
  }
  return false;
};








  // ----------------- FORMULAS -----------------



const recomputeTotals = (rows: ReceivingDetailRow[]) => {
  let totalQty = 0;
  let totalLiens = 0;
  let totalSto = 0;
  let totalIns = 0;
  let totalCost = 0;   // Σ(qty * unit_cost)
  let totalAP = 0;     // Σ(total_ap)

  rows.forEach((r) => {
    const qty = Number(r.quantity || 0);
    const li  = Number(r.liens || 0);

    // skip blank buffer row
    const isBlank = !String(r.quedan_no || '').trim() && qty === 0 && li === 0;
    if (isBlank) return;

    totalQty += qty;
    totalLiens += li;

    const rowUnitCost = Number(r.unit_cost ?? unitCost ?? 0);
    totalCost += qty * rowUnitCost;

    // ✅ server truth (no client recompute)
    totalIns += Number(r.insurance || 0);
    totalSto += Number(r.storage || 0);
    totalAP  += Number(r.total_ap || 0);
  });

  setTQty(totalQty);
  setTLiens(totalLiens);
  setTStorage(totalSto);
  setTInsurance(totalIns);
  setTUnitCost(totalCost);
  setTAP(totalAP);
};










  useEffect(() => {
    recomputeTotals(tableData);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    tableData,
    dateReceived,
    insuranceWeek,
    storageWeek,
    noInsurance,
    noStorage,
    unitCost,
    commission,
    insuranceRate,
    storageRate,
    daysFree,
  ]);

  // ----------------- SERVER UPDATES (flags/dates, autosave) -----------------

  const pushFlag = async (field: 'no_storage' | 'no_insurance', val: boolean) => {
    if (!selectedRR) return;
    await napi.post('/receiving/update-flag', {
      receipt_no: selectedRR,
      field,
      value: val ? 1 : 0,
    });
  };

  const pushDate = async (field: 'storage_week' | 'insurance_week' | 'receipt_date', valISO: string) => {
    if (!selectedRR) return;
    await napi.post('/receiving/update-date', {
      receipt_no: selectedRR,
      field,
      value: valISO || null,
    });
  };

const onChangeNoStorage = async (val: boolean) => {
  setNoStorage(val);

  try {
    await pushFlag('no_storage', val);
    await reloadDetailsFromServer(); // ✅ server-confirmed recalculation
    toast.success('Storage flag updated');
  } catch {
    toast.error('Failed to update storage flag');
  }
};


const onChangeNoInsurance = async (val: boolean) => {
  setNoInsurance(val);

  try {
    await pushFlag('no_insurance', val);
    await reloadDetailsFromServer(); // ✅ server-confirmed recalculation
    toast.success('Insurance flag updated');
  } catch {
    toast.error('Failed to update insurance flag');
  }
};



const onChangeStorageWeek = async (val: string) => {
  setStorageWeek(val);

  try {
    await pushDate('storage_week', val);
    await reloadDetailsFromServer(); // ✅ server-confirmed recalculation
  } catch {
    toast.error('Failed to update Storage Week');
  }
};


const onChangeInsuranceWeek = async (val: string) => {
  setInsuranceWeek(val);

  try {
    await pushDate('insurance_week', val);
    await reloadDetailsFromServer(); // ✅ server-confirmed recalculation
  } catch {
    toast.error('Failed to update Insurance Week');
  }
};



const onChangeDateReceived = async (val: string) => {
  setDateReceived(val);
  try {
    await pushDate('receipt_date', val);
    await reloadDetailsFromServer(); // ✅ server-confirmed recalculation
    toast.success('Date Received updated');
  } catch {
    toast.error('Failed to update Date Received');
  }
};


const handleAutoSave = async (rowData: ReceivingDetailRow, rowIndex: number) => {
  if (!selectedRR) return;

  try {
    const payload = { receipt_no: selectedRR, row_index: rowIndex, row: rowData };
    const { data } = await napi.post('/receiving/batch-insert', payload);

const server = data || {};
const id = server?.id;

setTableData((prev) => {
  const copy = [...prev];
  copy[rowIndex] = {
    ...(copy[rowIndex] || {}),
    ...rowData,
    ...server,           // ✅ authoritative computed fields from backend
    id: id || copy[rowIndex]?.id,
    persisted: true,
  };
  return copy;
});


    //const id = data?.id;

    // always mark persisted if we got an id
    //if (id) {
    //  setTableData((prev) => {
    //    const copy = [...prev];
    //    copy[rowIndex] = { ...(copy[rowIndex] || {}), ...rowData, id, persisted: true };
    //    return copy;
    //  });
    //}

    // ✅ MERGE authoritative server-computed fields (planter_name, storage, insurance, total_ap, etc.)
    // since backend currently returns only {id}, we pull the row back from /receiving/details

  } catch (e) {
    console.error(e);
    toast.error('Auto-save failed.');
  }
};



const onSaveNewReceiving = async () => {
  // 1) Required fields
  const ok = requireFields(
    ['Date Received', dateReceived],
    ['PBN #',          pbnNumber],
    ['Item #',         itemNumber],
    ['Vendor Name',    vendorName],
    ['Mill',           mill],
  );
  if (!ok) return;

  // If it already exists, just make sure the grid is usable
  if (selectedRR) {
    toast.info('Receiving already created.');
    setHandsontableEnabled(true);
    return;
  }

  // 2) Get company/user from localStorage (same pattern you already use)
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = user?.company_id;
  const userId    = user?.id ?? user?.user_id;

  if (!companyId) {
    toast.error('Missing company id; please sign in again.');
    return;
  }

  try {
    // 3) Create the entry — server will generate the RR number
    const payload = {
      company_id:   companyId,
      user_id:      userId,
      pbn_number:   pbnNumber,
      item_number:  itemNumber,
      receipt_date: dateReceived, // YYYY-MM-DD
      mill,
    };

    const { data } = await napi.post('/receiving/create-entry', payload);

    const newRR = data?.receipt_no;
    if (!newRR) throw new Error('Server did not return receipt_no');

    // 4) Immediately reflect the new RR in the input
    setSelectedRR(newRR);

    // 5) Ensure the Receiving# dropdown has an item that matches the value,
    //    so it renders right away (optimistic insert)
    setRrOptions(prev => [
      {
        receipt_no:  newRR,
        quantity:    0,
        sugar_type:  (pbnOptions.find(p => p.pbn_number === pbnNumber)?.sugar_type ?? ''),
        pbn_number:  pbnNumber,
        receipt_date: dateReceived,
        vendor_code: '',
        vendor_name: vendorName || '',
      },
      ...prev,
    ]);

    // 6) (Optional) refresh the RR list from the server so it’s 100% accurate
    try {
      const { data: rr } = await napi.get('/receiving/rr-list', {
        params: { include_posted: includePosted ? '1' : '0', q: '' },
      });
      setRrOptions(rr || []);
    } catch {
      // ignore; optimistic item above already shows the value
    }

    // 7) Enable the grid and celebrate
    setHandsontableEnabled(true);
    toast.success(`Created ${newRR}`);
  } catch (e) {
    console.error(e);
    toast.error('Failed to create Receiving Entry.');
  }
};



const saveAssocOthers = async () => {
  if (!selectedRR) return;
  try {
    await napi.post('/receiving/update-assoc-others', {
      receipt_no: selectedRR,
      assoc_dues: Number(assocDues || 0),
      others: Number(others || 0),
    });
    toast.success('Assoc/Others saved');
  } catch {
    toast.error('Failed to save Assoc/Others');
  }
};






// === Receiving grid helpers (drop-in) ===
const gridWrapRef = useRef<HTMLDivElement>(null);
const [dynamicHeight, setDynamicHeight] = useState(520);

useEffect(() => {
  if (!showMillPicker) return;

  (async () => {
    if (!requireCompany()) return;
const resp = await napi.get('/receiving/mills', {
  params: { q: millSearch, company_id: companyId },
});
    const arr = Array.isArray(resp.data) ? resp.data : (Array.isArray(resp.data?.data) ? resp.data.data : []);
    setMillOptions(arr);
  })().catch(() => setMillOptions([]));
}, [showMillPicker, millSearch]); // eslint-disable-line



// compute a nice grid height based on viewport
useEffect(() => {
  const compute = () => {
    const top = gridWrapRef.current?.getBoundingClientRect().top ?? 0;
    const h = Math.max(420, Math.floor(window.innerHeight - top - 220)); // bottom paddings/buttons
    setDynamicHeight(h);
  };
  compute();
  window.addEventListener('resize', compute);
  return () => window.removeEventListener('resize', compute);
}, []);



const isRowComplete = (r: ReceivingDetailRow | undefined) =>
  !!(r && r.quedan_no && Number(r.quantity || 0) > 0);

const makeBlankRow = (): ReceivingDetailRow => ({
  quedan_no: '',
  quantity: undefined,
  liens: undefined,
  week_ending: null,
  date_issued: null,
  planter_tin: '',
  planter_name: '',
  item_no: itemNumber || '',
  mill: mill || '',
  unit_cost: unitCost || 0,
  commission: commission || 0,
  storage: 0,
  insurance: 0,
  total_ap: 0,
});

const ensureTrailingBuffer = (rows: ReceivingDetailRow[]) => {
  const copy = [...rows];
  if (copy.length === 0 || isRowComplete(copy[copy.length - 1])) {
    copy.push(makeBlankRow());
  }
  return copy;
};

// ✅ NEW: server-confirmed refresh of details (single source of truth)
const reloadDetailsFromServer = useCallback(async (receiptNo?: string) => {
  const rr = receiptNo || selectedRR;
  if (!rr) return;

  const { data: details } = await napi.get('/receiving/details', { params: { receipt_no: rr } });

  const loaded: ReceivingDetailRow[] = Array.isArray(details)
    ? details.map((d: any) => ({ ...d, persisted: true }))
    : [];

  const withBuffer = ensureTrailingBuffer(loaded);
  setTableData(withBuffer);

  // optional: force HOT repaint
  requestAnimationFrame(() => {
    hotRef.current?.hotInstance?.loadData(withBuffer as any);
  });
}, [selectedRR]);




// when grid first turns on and there are no rows, add a blank buffer row
useEffect(() => {
  if (!handsontableEnabled) return;

  // ✅ if table ever becomes empty, re-add buffer row
  if (tableData.length === 0) {
    setTableData(ensureTrailingBuffer([]));
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [handsontableEnabled, tableData.length, itemNumber, mill, unitCost, commission]);

const refreshRatesAndDetails = useCallback(async () => {
  if (!selectedRR) return;

  // reload entry header (mill/date might have changed)
  const { data: entry } = await napi.get('/receiving/entry', { params: { receipt_no: selectedRR } });
  setDateReceived(toISO(entry.receipt_date) || '');
  setMill(entry.mill || '');
  setPbnNumber(entry.pbn_number || '');
  setItemNumber(entry.item_number || '');

  // reload pricing context (rates + crop year)
  const { data: ctx } = await napi.get('/receiving/pricing-context', {
    params: {
      pbn_number: entry.pbn_number,
      item_no: entry.item_number,
      mill_name: entry.mill,
    },
  });

  setInsuranceRate(Number(ctx?.insurance_rate || 0));
  setStorageRate(Number(ctx?.storage_rate || 0));
  setDaysFree(Number(ctx?.days_free || 0));
  setCropYear(String(ctx?.crop_year || ''));

  // reload computed details from server (storage/insurance/total_ap)
  await reloadDetailsFromServer(selectedRR);
}, [selectedRR, reloadDetailsFromServer]);


// put near: const hotRef = useRef<HotTableClass>(null);
const syncHeaderHeight = useCallback(() => {
  const hot = hotRef.current?.hotInstance as any;
  if (!hot) return;

  // Measure the real, computed height from the master header <th>
  const masterTh = hot?.rootElement?.querySelector('.ht_master thead th') as HTMLElement | null;
  const h = Math.round(masterTh?.getBoundingClientRect().height || 0);

  if (!h) {
    // Not rendered yet; try on next frame
    requestAnimationFrame(syncHeaderHeight);
    return;
  }

  // Tell HOT overlays (top/left clones) to use THIS exact height
  hot.updateSettings({ columnHeaderHeight: h });
}, []);

useEffect(() => {
  // initial sync + keep it synced on resize
  syncHeaderHeight();
  const onResize = () => syncHeaderHeight();
  window.addEventListener('resize', onResize);
  return () => window.removeEventListener('resize', onResize);
}, [syncHeaderHeight]);




const openQuedanListingPdfModal = async () => {
  if (!selectedRR) return;

  if (rrPdfUrl) {
    URL.revokeObjectURL(rrPdfUrl);
    setRrPdfUrl('');
  }

  try {
    const resp = await napi.get(
      `/receiving/quedan-listing-pdf/${encodeURIComponent(selectedRR)}`,
      {
        params: { company_id: companyId, _: Date.now() },
        responseType: 'arraybuffer',
        headers: { Accept: 'application/pdf' },
      }
    );

    const blob = new Blob([resp.data], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);

    setRrPdfUrl(url);
    setShowRrPdf(true);
  } catch (e) {
    console.error(e);
    toast.error('Failed to load Quedan Listing PDF.');
  }
};

const openQuedanListingInsStoPdfModal = async () => {
  if (!selectedRR) return;

  if (rrPdfUrl) {
    URL.revokeObjectURL(rrPdfUrl);
    setRrPdfUrl('');
  }

  try {
    const resp = await napi.get(
      `/receiving/quedan-listing-inssto-pdf/${encodeURIComponent(selectedRR)}`,
      {
        params: { company_id: companyId, _: Date.now() },
        responseType: 'arraybuffer',
        headers: { Accept: 'application/pdf' },
      }
    );

    const blob = new Blob([resp.data], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);

    setRrPdfUrl(url);
    setShowRrPdf(true);
  } catch (e) {
    console.error(e);
    toast.error('Failed to load Quedan Listing Insurance/Storage PDF.');
  }
};




  // ----------------- UI -----------------
  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={2500} />

      {/* Header card */}
      <div className="bg-yellow-50 shadow rounded-lg p-4 border border-yellow-300 space-y-4">
        <h2 className="text-lg font-bold text-slate-700">RECEIVING ENTRY</h2>
<RateBasisBar />

        {/* line 1: receiving + date */}
        <div className="grid grid-cols-12 gap-3 items-end">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">Receiving #</label>
            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                className="h-4 w-4"
                checked={includePosted}
                onChange={() => setIncludePosted((s) => !s)}
                title="Include posted"
              />
              <div className="flex-1">
               
                <DropdownWithHeadersDynamic
  label=""
  value={selectedRR}
  onChange={onSelectRR}
  items={filteredRR.map((r) => ({
    code: r.receipt_no,
    label: r.sugar_type,
    description: r.vendor_name,
    quantity: r.quantity,
    pbn_number: r.pbn_number,
    receipt_date: r.receipt_date,
  }))}
  search={rrSearch}
  onSearchChange={setRrSearch}
  headers={['Receipt No', 'Sugar Type', 'Vendor Name', 'Qty', 'PBN No', 'RDate']}
  columnWidths={['130px', '50px', '240px', '50px', '120px', '110px']}
  customKey="rr"
  inputClassName={`${fieldSize} bg-green-100 text-green-1000`}                />
              </div>
            </div>
          </div>

          <div className="col-span-4">
            <label className="block text-sm text-slate-600">Date Received</label>
            <div className="flex gap-2">
              <input
                type="date"
                value={dateReceived}
                onChange={(e) => onChangeDateReceived(e.target.value)}
                className="w-full border p-2 rounded bg-yellow-100"
              />
<button
  type="button"
  disabled={!selectedRR}
  onClick={async () => {
    if (!selectedRR) return;
    try {
      await pushDate('receipt_date', dateReceived);
      //await refreshRatesAndDetails(); // ✅ new helper below
      toast.success('Date updated');
    } catch {
      toast.error('Failed to update date');
    }
  }}
  className={`inline-flex items-center text-xs px-2 rounded border ${
    selectedRR ? 'bg-white hover:bg-slate-50' : 'bg-gray-200 text-gray-500 cursor-not-allowed'
  }`}
  title="Update Date (recompute rates)"
>
  UD
</button>
            </div>
          </div>
        </div>

        {/* line 2: PBN + Item */}
        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">PBN #</label>
            
<AttachedDropdown
  value={pbnNumber}
  displayValue={pbnDisplay}     // 👈 show PBN # — Vendor Name
  readOnlyInput                 // 👈 prevent typing in the PBN box itself  
  onChange={onSelectPBN}
  items={pbnOptions.map(p => ({
    code:        p.pbn_number,
    pbn_number:  p.pbn_number,
    sugar_type:  p.sugar_type,
    vendor_id:   p.vendor_code,
    vendor_name: p.vendor_name,
    crop_year:   p.crop_year,
    pbn_date:    p.pbn_date,
  }))}
  headers={['PBN #','Sugar Type','Vendor ID','Vendor Name','Crop Year','PBN Date']}
  columns={['pbn_number','sugar_type','vendor_id','vendor_name','crop_year','pbn_date']}
  search={pbnSearch}
  onSearchChange={setPbnSearch}
  inputClassName="bg-yellow-100"
  dropdownClassName="min-w-[1000px]"                 // 👈 allow it to be wider than the input
  columnWidths={['100px','70px','110px','350px','90px','110px']}  // 👈 make Vendor Name roomy
/>


          </div>

<div className="col-span-4">
  <label className="block text-sm text-slate-600">Item #</label>

  <AttachedDropdown
    value={itemNumber}
    displayValue={itemDisplay}           // shows "row — mill"
    readOnlyInput                        // prevent typing in the input itself
    onChange={onSelectItem}
    items={itemOptions.map(i => ({
      code: String(i.row),               // value returned to onSelectItem
      item: String(i.row),
      millmark: i.mill ?? '',
      quantity: i.quantity ?? 0,
      bcost: i.unit_cost ?? 0,
      ccost: i.commission ?? 0,
    }))}

    headers={['Item','MillMark','Quantity','BCost','CCost']}
    columns={['item','millmark','quantity','bcost','ccost']}

    inputClassName={`${fieldSize} bg-yellow-100`}
    dropdownClassName="min-w-[980px]"   // wider like legacy
    columnWidths={['50px','200px','60px','60px','60px']}
  />
</div>

        </div>

        {/* line 3: vendor + mill */}
        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">Vendor Name</label>
            <input value={vendorName} disabled className="w-full border p-2 bg-yellow-100 rounded" />
          </div>
          <div className="col-span-4">
            <label className="block text-sm text-slate-600">Mill</label>
            <div className="flex gap-2">
              <input value={mill} disabled className="w-full border p-2 bg-yellow-100 rounded" />
{/*<button
  type="button"
  disabled={!selectedRR}
  onClick={() => setShowMillPicker(true)}
  className={`inline-flex items-center text-xs px-2 rounded border ${
    selectedRR ? 'bg-white hover:bg-slate-50' : 'bg-gray-200 text-gray-500 cursor-not-allowed'
  }`}
  title="Update Mill (recompute rates)"
>
  UM
</button>*/}
            </div>
          </div>
        </div>




      </div>

{/* Details */}
<div>
  <h3 className="font-semibold text-gray-800 mb-2">Details</h3>

  {handsontableEnabled && (
    // Outer wrapper can look nice (padding/border) — this does NOT host HotTable directly

<div className="rec-grid rounded-md border border-slate-300 bg-white p-2">
      {/* keep the direct host clean; allow overlays */}
      <div ref={gridWrapRef} style={{ padding: 0, border: 0, overflow: 'visible', position: 'relative' }}>
        
        
        <HotTable
          ref={hotRef}
          data={tableData}

          /* Same safe defaults as your PBN grid */
          preventOverflow="horizontal"
          manualColumnResize
          stretchH="all"
          width="100%"
          height={dynamicHeight}
          rowHeaders
          licenseKey="non-commercial-and-evaluation"

          colHeaders={[
            'Quedan #',
            'Quantity',
            'Liens',
            'Week Ending',
            'Date Issued',
            'Planter TIN',
            'Planter Name',
            'Item',
        
            'Unit Cost',
            'Commission',
            'Storage',
            'Insurance',
            'Total AP',
          ]}
          columns={[
            { data: 'quedan_no', type: 'text' },
            { data: 'quantity', type: 'numeric', numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'liens',    type: 'numeric', numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'week_ending', type: 'date', dateFormat: 'YYYY-MM-DD', correctFormat: true, className: 'htCenter' },
            { data: 'date_issued', type: 'date', dateFormat: 'YYYY-MM-DD', correctFormat: true, className: 'htCenter' },
{
  data: 'planter_tin',
  type: 'autocomplete',
  strict: false,       // allow typing, not only pick
  filter: true,
  visibleRows: 8,
  className: 'htCenter',

  // Handsontable expects (query, process)
source: function (this: any, query: string, process: (items: string[]) => void) {
  process([]); // ✅ immediate response to keep editor alive
  fetchPlanterOptions(query)
    .then((rows) => process(rows.map((r) => r.tin)))
    .catch(() => process([]));
},

},
            { data: 'planter_name', readOnly: true },
            { data: 'item_no',     readOnly: true, className: 'htCenter' },
            { data: 'unit_cost',   type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'commission',  type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'storage',     type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'insurance',   type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
            { data: 'total_ap',    type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' }, className: 'htRight' },
          ]}

          /* Your existing autosave + recompute logic */
afterChange={(changes, source) => {
  if (!changes || !['edit', 'Autocomplete', 'paste'].includes(String(source))) return;

  // ✅ ADDED: prevent infinite loop when we revert a duplicate value programmatically
  if (String(source) === 'dupQuedanRevert') return;


  // ✅ ADDED: access HOT instance so we can revert value + re-focus cell (legacy behavior)
  const hot = hotRef.current?.hotInstance as any;

  const updated: ReceivingDetailRow[] = [...tableData];
  let shouldAppendRow = false;

  changes.forEach(([rowIndex, prop, oldValue, newValue]) => {
    const r = { ...(updated[rowIndex as number] || {}) };

    // ✅ ADDED: apply edited value into our local row copy FIRST
    (r as any)[prop as any] = newValue;

// ✅ DUPLICATE QUEDAN CHECK (legacy: alert + revert + keep cursor on the same cell)
if (prop === 'quedan_no') {
  const q = String(newValue ?? '').trim();

  if (q) {
    // ✅ CHANGED: use helper instead of inline updated.some(...)
    const dup = isDuplicateQuedan(updated, rowIndex as number, q);

    if (dup) {
      window.alert('No duplicate quedan number please!!!');

      // revert UI + local row to previous value
      const revertVal = String(oldValue ?? '').trim();
      hot?.setDataAtRowProp(rowIndex as number, 'quedan_no', revertVal, 'dupQuedanRevert');

      r.quedan_no = revertVal;
      updated[rowIndex as number] = r;
      setTableData(updated);

      // legacy feel: keep cursor and reopen editor
      const propToCol =
        typeof hot?.propToCol === 'function'
          ? hot.propToCol.bind(hot)
          : (p: string) => {
              const cols = hot?.getSettings?.()?.columns || [];
              const idx = cols.findIndex((c: any) => c?.data === p);
              return idx < 0 ? 0 : idx;
            };

      const colIndex = propToCol('quedan_no');

      requestAnimationFrame(() => {
        try {
          hot?.selectCell(rowIndex as number, colIndex);
          hot?.scrollViewportTo(rowIndex as number, colIndex);
          hot?.getActiveEditor?.()?.beginEditing?.();
        } catch {
          // ignore focus errors
        }
      });

      return; // stop processing
    }
  }
}


    const qty = Number(r.quantity || 0);

    // sync from header state
    r.item_no    = itemNumber || r.item_no;
    r.mill       = mill || r.mill;
    r.unit_cost  = unitCost || 0;
    r.commission = commission || 0;

    // compute based on your helpers/flags
    // ✅ server-confirmed mode:
    // do NOT compute insurance/storage/total_ap here.
    // keep unit_cost/commission in sync for display only.
    r.storage   = r.storage ?? 0;
    r.insurance = r.insurance ?? 0;
    r.total_ap  = r.total_ap ?? 0;


    updated[rowIndex as number] = r;

    if (r.quedan_no && qty > 0) {
      setTimeout(() => handleAutoSave(r, rowIndex as number), 0);
      if ((rowIndex as number) === updated.length - 1) shouldAppendRow = true;
    }

    // ---- planter TIN -> planter name (debounced) ----
    if (prop === 'planter_tin') {
      const tin = normalizeTin(newValue);

      // fast fill from cache (dropdown selections will already be cached)
      const cachedName = planterNameCacheRef.current.get(tin);
      if (cachedName !== undefined) {
        r.planter_tin = tin;
        r.planter_name = cachedName;
        updated[rowIndex as number] = r;
      }

      // clear existing timer for this row
      const prevTimer = planterLookupTimerRef.current[rowIndex as number];
      if (prevTimer) window.clearTimeout(prevTimer);

      planterLookupTimerRef.current[rowIndex as number] = window.setTimeout(async () => {
        if (planterLookupInFlightRef.current[rowIndex as number]) return;
        planterLookupInFlightRef.current[rowIndex as number] = true;

        try {
          const name = await fetchPlanterNameByTin(tin);

          setTableData((prev) => {
            const copy = [...prev];
            const cur = { ...(copy[rowIndex as number] || {}) };

            // keep latest tin in case user edited again quickly
            cur.planter_tin = tin;

            // only set name if tin still matches
            if (normalizeTin(cur.planter_tin) === tin) {
              cur.planter_name = name || '';
            }

            copy[rowIndex as number] = cur;
            return copy;
          });
        } catch {
          // ignore lookup errors; backend will still set planter_name during autosave if tin valid
        } finally {
          planterLookupInFlightRef.current[rowIndex as number] = false;
        }
      }, 250);
    }
  });

  if (shouldAppendRow) {
    updated.push({
      quedan_no: '',
      quantity: undefined,
      liens: undefined,
      week_ending: null,
      date_issued: null,
      planter_tin: '',
      planter_name: '',
      item_no: itemNumber || '',
      mill: mill || '',
      unit_cost: unitCost || 0,
      commission: commission || 0,
      storage: 0,
      insurance: 0,
      total_ap: 0,
    });
  }

  setTableData(updated);
}}

        />
<style>{`
  /* only affects this grid */
  .rec-grid .handsontable thead tr th,
  .rec-grid .handsontable .ht_clone_top thead tr th,
  .rec-grid .handsontable .ht_clone_top_left_corner thead tr th {
    font-size: 10px;         /* ← change this */
    line-height: 20px;       /* keep header compact & vertically centered */
    padding: 0 6px;          /* optional: tighten spacing */
  }
`}</style>

      </div>
    </div>
  )}
</div>





{/* Totals + Footer block (bordered) */}
<div className="mt-4 border border-slate-300 rounded-lg bg-white shadow-sm p-4 space-y-4">

  {/* Totals */}
  <div className="grid grid-cols-3 gap-4 text-slate-800">
    <div> Total Quantity__ <span className="font-semibold">{currency(tQty)}</span> </div>
    <div> Total Insurance_ <span className="font-semibold">{currency(tInsurance)}</span> </div>
    <div> Total Cost_____ <span className="font-semibold">{currency(tUnitCost)}</span> </div>
    <div> Total Liens____ <span className="font-semibold">{currency(tLiens)}</span> </div>
    <div> Total Storage__ <span className="font-semibold">{currency(tStorage)}</span> </div>
    <div>
      Total AP Cost___{' '}
      <span className="font-semibold">
        {currency(tAP + Number(assocDues || 0) + Number(others || 0))}
      </span>
    </div>
  </div>

  {/* Footer controls — same handlers, just styled/laid out */}
  <div className="grid grid-cols-12 gap-4">
    {/* LEFT: GL + Assoc + Others */}
    <div className="col-span-6 grid grid-cols-6 gap-4">
      <div className="col-span-6">
<label className="block text-xs font-semibold text-slate-600">GL Account</label>

<AttachedDropdown
  value={glAccountKey}
  displayValue={glDisplay}     // ✅ shows: "1001 — CASH IN BANK" etc
  readOnlyInput
  onChange={(code) => onSelectGL(String(code))}
  items={glOptions.map((a) => ({
    code: a.acct_code,
    acct_code: a.acct_code,
    acct_desc: a.acct_desc,
  }))}
  headers={['Account Code', 'Description']}
  columns={['acct_code', 'acct_desc']}
  search={glSearch}
  onSearchChange={setGlSearch}
  inputClassName="bg-yellow-50"
  dropdownClassName="min-w-[720px]"
  columnWidths={['140px', '560px']}
/>


      </div>

      <div className="col-span-3">
        <label className="block text-xs font-semibold text-slate-600">Assoc Dues</label>
        <input
          type="number"
          step="0.01"
          value={assocDues}
          onChange={(e) => setAssocDues(e.target.value === '' ? '' : Number(e.target.value))}
          className="w-full border p-2 rounded bg-yellow-50"
        />
      </div>

      <div className="col-span-3">
        <label className="block text-xs font-semibold text-slate-600">Others</label>
        <input
          type="number"
          step="0.01"
          value={others}
          onChange={(e) => setOthers(e.target.value === '' ? '' : Number(e.target.value))}

          className="w-full border p-2 rounded bg-yellow-50"
        />
      </div>

<div className="col-span-6 flex justify-end">
  <button
    type="button"
    disabled={!selectedRR}
    onClick={saveAssocOthers}
    className={`px-3 py-2 rounded border text-sm ${
      selectedRR ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-gray-200 text-gray-500 cursor-not-allowed'
    }`}
  >
    Save Assoc / Others
  </button>
</div>


    </div>

    {/* RIGHT: Weeks + Flags */}
    <div className="col-span-6 grid grid-cols-6 gap-4">
      <div className="col-span-3">
        <label className="block text-xs font-semibold text-slate-600">Storage Week</label>
        <input
          type="date"
          value={storageWeek}
          onChange={(e) => onChangeStorageWeek(e.target.value)}
          className="w-full border p-2 rounded bg-white"
        />
      </div>
      <div className="col-span-3">
        <label className="block text-xs font-semibold text-slate-600">Insurance Week</label>
        <input
          type="date"
          value={insuranceWeek}
          onChange={(e) => onChangeInsuranceWeek(e.target.value)}
          className="w-full border p-2 rounded bg-white"
        />
      </div>

      <div className="col-span-6 flex items-center gap-8 pt-1">
        <label className="inline-flex items-center gap-2 text-slate-700">
          <input
            type="checkbox"
            className="h-4 w-4 accent-blue-600"
            checked={noStorage}
            onChange={(e) => onChangeNoStorage(e.target.checked)}
          />
          <span className="text-sm font-medium">No Storage</span>
        </label>
        <label className="inline-flex items-center gap-2 text-slate-700">
          <input
            type="checkbox"
            className="h-4 w-4 accent-blue-600"
            checked={noInsurance}
            onChange={(e) => onChangeNoInsurance(e.target.checked)}
          />
          <span className="text-sm font-medium">No Insurance</span>
        </label>
      </div>
    </div>
  </div>
  <RateBasisBar />

</div>




      {/* Actions */}

{/* Actions (legacy-style Download + Print dropdowns) */}
<div className="flex items-center gap-2">
  <ActionDropdown
    disabled={!selectedRR}
    icon={<ArrowDownTrayIcon className="h-5 w-5" />}
    label="Download"
    items={[
      {
        label: 'Quedan Listing - Excel',
        onClick: () =>
          doExcelDownload(
            exportEndpoints.dl_quedan_excel,
            `Quedan_Listing_${selectedRR}.xlsx`
          ),
      },
      {
        label: 'Quedan Listing (Insurance/Storage) - Excel',
        onClick: () =>
          doExcelDownload(
            exportEndpoints.dl_quedan_inssto_excel,
            `Quedan_Listing_Insurance_Storage_${selectedRR}.xlsx`
          ),
      },
    ]}
  />

<ActionDropdown
  disabled={!selectedRR}
  icon={<PrinterIcon className="h-5 w-5" />}
  label="Print"
  items={[
    {
      label: 'Quedan Listing - PDF',
      onClick: openQuedanListingPdfModal,
    },
    {
      label: 'Quedan Listing Insurance/Storage - PDF',
      onClick: openQuedanListingInsStoPdfModal,
    },
    {
      label: 'Receiving Report - PDF',
      onClick: openReceivingReportPdfModal,
    },
  ]}
/>


  <button
    className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
    onClick={() => {
      setSelectedRR('');
      setHandsontableEnabled(false);
      setTableData([]);
      setDateReceived('');
      setPbnNumber('');
      setItemNumber('');
      setVendorName('');
      setMill('');
      setAssocDues('');
      setOthers('');
      setNoInsurance(false);
      setNoStorage(false);
      setInsuranceWeek('');
      setStorageWeek('');
      toast.success('Ready for new Receiving Entry');
    }}
  >
    <PlusIcon className="h-5 w-5" />
    New
  </button>

  <button
    disabled={!!selectedRR}
    className={`inline-flex items-center gap-2 px-4 py-2 rounded 
      ${selectedRR ? 'bg-green-400 cursor-not-allowed opacity-60' : 'bg-green-600 hover:bg-green-700'} 
      text-white`}
    onClick={onSaveNewReceiving}
  >
    <CheckCircleIcon className="h-5 w-5" />
    Save
  </button>
</div>

{showMillPicker && (
  <div className="fixed inset-0 bg-black/30 flex items-center justify-center z-50">
    <div className="bg-white rounded-lg shadow-lg w-[720px] max-w-[95vw] p-4 space-y-3">
      <div className="flex items-center justify-between">
        <div className="font-semibold text-slate-700">Select Mill</div>
        <button className="px-2 py-1 rounded border" onClick={() => setShowMillPicker(false)}>
          Close
        </button>
      </div>

      <input
        className="w-full border rounded px-3 py-2"
        placeholder="Search mill..."
        value={millSearch}
        onChange={(e) => setMillSearch(e.target.value)}
      />

      <div className="max-h-[320px] overflow-auto border rounded">
        {millOptions.map((m) => (
          <button
            key={m.mill_name}
            className="w-full text-left px-3 py-2 hover:bg-slate-50 border-b"
            onClick={async () => {
              try {
                await napi.post('/receiving/update-mill', {
                  receipt_no: selectedRR,
                  mill: m.mill_name,
                });

                setShowMillPicker(false);
                setMillSearch('');

                await refreshRatesAndDetails(); // ✅ rates + recompute cascade
                toast.success('Mill updated');
              } catch (e: any) {
                toast.error(e?.response?.data?.msg || 'Failed to update mill');
              }
            }}
          >
            {m.mill_name}
          </button>
        ))}

        {millOptions.length === 0 && (
          <div className="p-3 text-sm text-slate-500">No mills found.</div>
        )}
      </div>
    </div>
  </div>
)}

{showRrPdf && (
  <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
    <div className="bg-white rounded-lg shadow-lg w-[92vw] h-[92vh] overflow-hidden relative">
      <button
        className="absolute top-3 right-3 px-3 py-1 rounded border bg-white hover:bg-slate-50 z-10"
        onClick={() => {
          setShowRrPdf(false);
          if (rrPdfUrl) URL.revokeObjectURL(rrPdfUrl);
          setRrPdfUrl('');
        }}
      >
        ✕
      </button>

      <div className="h-full pt-0">
        <iframe
          title="Receiving Report PDF"
          src={rrPdfUrl}
          className="w-full h-full"
        />
      </div>
    </div>
  </div>
)}


    </div>
  );
  
}
