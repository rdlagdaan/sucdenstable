import { useEffect, useState, useRef, useMemo, useLayoutEffect  } from 'react';

import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';

import Swal from 'sweetalert2';

import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';
import DropdownWithHeaders from '../../components/DropdownWithHeaders';
import { useDropdownOptions } from '../../../hooks/useDropdownOptions';
import {
  PrinterIcon,
  ChevronDownIcon,
  DocumentTextIcon,
  PlusIcon,
  CheckCircleIcon,
  //XMarkIcon,             // 👈 close icon for modal
  ArrowDownTrayIcon,       // ✅ NEW (Download button icon)
  DocumentArrowDownIcon,   // ✅ NEW (Excel menu item icon)
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

interface PbnDropdownItem {
  code: string;
  id: number;
  pbn_number: string;
  sugar_type: string;
  //vend_code: string;
  vendor_name: string;
  crop_year: string;
  pbn_date: string;
  posted_flag: number;
}

interface PbnDetailRow {
  id?: number;
  row?: number;
  mill: string;
  quantity: number;
  unit_cost: number;
  commission: number;
  cost: number;
  total_commission: number;
  total_cost: number;
  pbn_entry_id?: number;
  pbn_number?: string;
  persisted?: boolean;
}

type MillLite = { mill_id: string | number; mill_name: string; company_id: number };

/* ---------------- Vendor normalization helpers (FIXED) ---------------- */
type AnyVendor = Record<string, unknown>;

function asString(x: unknown): string {
  return typeof x === 'string' ? x : (x == null ? '' : String(x));
}

function pickVendorCode(v: AnyVendor): string {
  // try common keys in order
  const val =
    v['code'] ??
    v['value'] ??
    v['vend_code'] ??
    v['vendor_code'];
  return asString(val).trim();
}

function pickVendorName(v: AnyVendor): string {
  const val =
    v['description'] ??
    v['label'] ??
    v['vend_name'] ??
    v['vendor_name'];
  return asString(val).trim();
}

function findVendorByCode(items: AnyVendor[] | undefined, code: string): AnyVendor | undefined {
  if (!items || !code) return undefined;
  const want = code.trim().toUpperCase();
  for (const v of items) {
    const have = pickVendorCode(v).toUpperCase();
    if (have && have === want) return v;
  }
  return undefined;
}
/* --------------------------------------------------------------------- */


//This will become a part of the utilities component
function formatDateToYYYYMMDD(dateStr: string): string {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  const dd = String(date.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

//PurchaseBookNote() is the main function
export default function PurchaseBookNote() {
  //In reference to Handsontable
  const hotRef = useRef<HotTableClass>(null);

  //Establishing data state for the Main Entry form
  const [posted, setPosted] = useState(false);
  const [pbnNumber, setPbnNumber] = useState('');
  const [selectedSugarType, setSelectedSugarType] = useState('');
  const [selectedCropYear, setSelectedCropYear] = useState('');
  const [selectedVendor, setSelectedVendor] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [pbnDate, setPbnDate] = useState('');

  //Establishing data state for the Handsontable
  const [handsontableEnabled, setHandsontableEnabled] = useState(false);
  const [mainEntryId, setMainEntryId] = useState<number | null>(null);

  const [_pdfModalOpen, setPdfModalOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  // ⬇️ add these two right after your other useState hooks
  const [showPdf, setShowPdf] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const isPbnReady = !!mainEntryId;   // true when a PBN is saved/selected

  // ---- Viewport-anchored placement for the autocomplete popup ----
  const ROW_HEIGHT = 28;           // keep your existing constant or set it here
  const TARGET_LIST_HEIGHT = 320;  // cap the dropdown height
  const GAP = 6;                   // gap between cell and popup
  const MIN_BELOW = 220;           // preferred min space below for "open down"

  const placeAutocompletePopup = (row: number, col: number) => {
    const hot = hotRef.current?.hotInstance;
    if (!hot) return;

    const td = hot.getCell(row, col);
    const editor = document.querySelector<HTMLElement>('.handsontableEditor.autocompleteEditor');
    if (!td || !editor) return;

    const cellRect = td.getBoundingClientRect();

    // Available space above & below in the viewport
    const spaceAbove = cellRect.top - GAP;                       // px above the cell
    const spaceBelow = window.innerHeight - cellRect.bottom - GAP; // px below the cell

    // Decide whether to open below or above, and how tall it can be
    const openDown = spaceBelow >= MIN_BELOW || spaceBelow >= spaceAbove;
    const maxHeight = Math.min(TARGET_LIST_HEIGHT, openDown ? spaceBelow : spaceAbove, 420);

    // Width: at least cell width, up to a sensible max
    const width = Math.max(cellRect.width, 300);

    // Left aligned with the cell, but keep in viewport
    const left = Math.max(8, Math.min(cellRect.left, window.innerWidth - width - 8));

    // Top is either just below or just above the cell
    const top = openDown
      ? (cellRect.bottom + GAP)
      : Math.max(8, cellRect.top - maxHeight - GAP);

    // Apply styles
    editor.style.left = `${left}px`;
    editor.style.top = `${top}px`;
    editor.style.maxHeight = `${Math.max(160, maxHeight)}px`;
    editor.style.width = `${width}px`;

    // Ensure the text input still has focus
    const input = document.querySelector<HTMLInputElement>('.handsontableInput');
    input?.focus();
  };

  //const [tableData, setTableData] = useState<any[]>([{}]);
  const [tableData, setTableData] = useState<PbnDetailRow[]>([]);

  //Establishing the data state for the Handsontable's mills
  const [mills, setMills] = useState<{ mill_id: string; mill_name: string }[]>([]);

  const [pbnOptions, setPbnOptions] = useState<PbnDropdownItem[]>([]);
  const [selectedPbnNo, setSelectedPbnNo] = useState('');
  const [pbnSearch, setPbnSearch] = useState('');
  const [includePosted, setIncludePosted] = useState(false); // for checkbox

  const [_pbnEntryMain, setPbnEntryMain] = useState<any>(null);
  const [isExistingRecord, setIsExistingRecord] = useState(false);

  const sugarTypes = useDropdownOptions('/sugar-types');
  const cropYears = useDropdownOptions('/crop-years');
  const vendors = useDropdownOptions('/vendors');
  const [pendingDetails, setPendingDetails] = useState<PbnDetailRow[]>([]);

  // NEW ref — used to safely detect when mainEntryId is updated
  const mainEntryIdRef = useRef<number | null>(null);

  // Controls the Print hover menu (prevents flicker between button and menu)
  const [printOpen, setPrintOpen] = useState(false);

  // Tracks if a cell editor is currently open
  const isEditingRef = useRef(false);

  // Ensure there's at least ROOM_BELOW px of space under the edited cell
  const ROOM_BELOW = 260;   // tweak if you want a taller dropdown
  const ROW_PX = 28;        // row height used by the grid

  const ensureRoomBelow = (row: number, col: number) => {
    const hot = hotRef.current?.hotInstance;
    if (!hot) return;

    const td = hot.getCell(row, col);
    if (!td) return;

    const rect = td.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom;

    if (spaceBelow >= ROOM_BELOW) return;

    // Scroll up just enough rows to create ROOM_BELOW
    const needed = ROOM_BELOW - spaceBelow;
    const rowsToScroll = Math.ceil(needed / ROW_PX) + 1;
    const target = Math.max(0, row - rowsToScroll);

    // Gentle scroll that does not yank focus away
    hot.scrollViewportTo(target, col);
  };

  // ---- Details wrapper & max grid height (add here) ----
  const detailsWrapRef = useRef<HTMLDivElement>(null);
  const [maxGridHeight, setMaxGridHeight] = useState<number>(600);

  useLayoutEffect(() => {
    const update = () => {
      const rect = detailsWrapRef.current?.getBoundingClientRect();
      const top = rect?.top ?? 0;
      const bottomPadding = 140; // room for buttons/toasts
      const available = window.innerHeight - top - bottomPadding;
      setMaxGridHeight(Math.max(320, Math.floor(available)));
    };

    update();
    window.addEventListener('resize', update);
    return () => window.removeEventListener('resize', update);
  }, []);

  // ---- Dynamic table height constants (add here) ----
  //const ROW_HEIGHT = 28;
  const HEADER_HEIGHT = 32;
  const DROPDOWN_ROOM = 240;

  const dynamicHeight = useMemo(() => {
    const rows = Math.max(tableData.length, 6);
    const desired = HEADER_HEIGHT * 1 + rows * ROW_HEIGHT + DROPDOWN_ROOM;
    return Math.min(desired, maxGridHeight);
  }, [tableData.length, maxGridHeight]);

  const isRowComplete = (row: any) => {
    return (
      row.mill &&
      row.quantity !== undefined &&
      row.unit_cost !== undefined &&
      row.commission !== undefined &&
      row.mill !== '' &&
      row.quantity !== '' &&
      row.unit_cost !== '' &&
      row.commission !== ''
    );
  };

  // ---- Trailing blank rows helpers (add below isRowComplete) ----
  const TRAILING_BUFFER_ROWS = 4;

  const emptyRow = (): PbnDetailRow => ({
    mill: '',
    quantity: 0,
    unit_cost: 0,
    commission: 0,
    cost: 0,
    total_commission: 0,
    total_cost: 0,
    persisted: false,
  });

  const isRowEmpty = (r?: PbnDetailRow) =>
    !r?.mill && !r?.quantity && !r?.unit_cost && !r?.commission;

  const ensureTrailingBuffer = (data: PbnDetailRow[]) => {
    const out = [...data];
    // count how many empties at the end
    let blanksAtEnd = 0;
    for (let i = out.length - 1; i >= 0; i--) {
      if (isRowEmpty(out[i])) blanksAtEnd++;
      else break;
    }
    const need = Math.max(0, TRAILING_BUFFER_ROWS - blanksAtEnd);
    for (let i = 0; i < need; i++) out.push(emptyRow());
    return out;
  };

  const fetchPbnEntries = async () => {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    const res = await napi.get('/pbn/dropdown-list', {
      params: {
        include_posted: includePosted.toString(),
        company_id: user?.company_id || '',
      },
    });

    const mapped = (res.data as PbnDropdownItem[]).map((item) => ({
      ...item,
      code: item.id.toString(),
      label: item.pbn_number,              // this is what will be shown in the dropdown
      description: item.vendor_name ?? '', // optional: display vendor name next to it
    }));
    console.log(res);
    setPbnOptions(mapped);
  };

  useEffect(() => {
    fetchPbnEntries();
  }, [includePosted]);

  /*useEffect(() => {
    napi.get('/api/mills').then(res => setMills(res.data));
  }, []);*/

  useEffect(() => {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;
    const companyId = user?.company_id;

    let cancelled = false;

    async function loadMills() {
      try {
        const { data } = await napi.get<MillLite[]>('/mills', {
          params: { company_id: companyId },       // keep this endpoint light for dropdown
        });
        if (!cancelled) {
          setMills(
            (data || []).map(m => ({
              mill_id: String(m.mill_id),
              mill_name: m.mill_name,
            }))
          );
        }
      } catch (e) {
        console.error('Failed to load mills', e);
      }
    }

    loadMills();
    return () => { cancelled = true; };
  }, []);

  /* -------- Auto-populate Vendor Name when vendor changes or list updates (REPLACED) -------- */
useEffect(() => {
  if (!selectedVendor || !Array.isArray(vendors.items) || vendors.items.length === 0) return;
  const v = findVendorByCode(vendors.items as AnyVendor[], selectedVendor);
  if (v) {
    setVendorName(pickVendorName(v)); // only set when found; never blank out
  }
}, [selectedVendor, vendors.items]);

  /* ------------------------------------------------------------------------------------------ */

  useEffect(() => {
    if (mainEntryId && pendingDetails.length > 0) {
      setTableData(pendingDetails);
      setHandsontableEnabled(true);
      setPendingDetails([]);
    }
  }, [mainEntryId, pendingDetails]);

  useEffect(() => {
    mainEntryIdRef.current = mainEntryId;
  }, [mainEntryId]);

  useEffect(() => {
    if (mainEntryId && pendingDetails.length > 0) {
      // CHANGE THIS LINE:
      setTableData(ensureTrailingBuffer(pendingDetails));
      setHandsontableEnabled(true);
      setPendingDetails([]);
    }
  }, [mainEntryId, pendingDetails]);

  const handleNew = () => {
    setPbnNumber('');
    setSelectedPbnNo('');
    setSelectedSugarType('');
    setSelectedCropYear('');
    setSelectedVendor('');
    setVendorName('');
    setPbnDate('');
    setPosted(false);
    setHandsontableEnabled(false);
    setMainEntryId(null);

    setTableData(ensureTrailingBuffer([emptyRow()]));

    setIsExistingRecord(false);
    toast.success('✅ PBN Entry form is now ready for new entry');
  };

  const handleSave = async () => {
    const confirm = await Swal.fire({
      title: 'Confirm Save?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Save it!',
    });
    if (!confirm.isConfirmed) return;

    try {
      const storedUser = localStorage.getItem('user');
      const user = storedUser ? JSON.parse(storedUser) : null;
      const companyId = user?.company_id;
      if (!companyId) {
        toast.error('Company ID not found in session.');
        return;
      }

      const { data } = await napi.get('/pbn/generate-pbn-number', {
        params: {
          company_id: companyId,
          sugar_type: selectedSugarType,
        },
      });

      const generatedPbnNumber = data.pbn_number;
      setPbnNumber(generatedPbnNumber);

      const res = await napi.post('/pbn/save-main', {
        sugar_type: selectedSugarType,
        crop_year: selectedCropYear,
        pbn_date: pbnDate,
        vend_code: selectedVendor,
        vendor_name: vendorName,
        pbn_number: generatedPbnNumber,
        posted_flag: posted,
        company_id: companyId,
      });

      await fetchPbnEntries();

      const { id } = res.data;
      setMainEntryId(id);
      setSelectedPbnNo(id.toString());

      setTableData(ensureTrailingBuffer([emptyRow()]));

      // ✅ 👇 Only now, enable the Handsontable
      setHandsontableEnabled(true);

      setIsExistingRecord(true);
      toast.success('Main PBN Entry saved. You can now input details.');

    } catch (err) {
      toast.error('Failed to save PBN Entry.');
      console.error(err);
    }
  };

  const handleAutoSaveDetail = async (rowData: any, rowIndex: number) => {
    const currentEntryId = mainEntryIdRef.current;

    console.log('here');
    console.log('mainEntryId:', currentEntryId);
    console.log('pbnNumber:', pbnNumber);
    console.log('row complete?', isRowComplete(rowData));

    if (!currentEntryId || !pbnNumber || !isRowComplete(rowData)) return;

    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    const payload = {
      ...rowData,
      mill_code: mills.find(m => m.mill_name === rowData.mill)?.mill_id || '',
      pbn_entry_id: currentEntryId,
      pbn_number: pbnNumber,
      row: rowIndex,
      company_id: user.company_id,
      user_id: user.id,
      cost: Math.round(rowData.quantity * rowData.unit_cost * 100) / 100,
      total_commission: rowData.quantity * rowData.commission,
      total_cost: (rowData.quantity * rowData.unit_cost) + (rowData.quantity * rowData.commission),
    };

    try {
      if (!rowData.persisted) {
        const response = await napi.post('/pbn/save-detail', payload);
        const updatedRow = {
          ...rowData,
          id: response.data.detail_id,
          persisted: true,
        };

        const updatedData = [...tableData];
        updatedData[rowIndex] = updatedRow;

        if (rowIndex === updatedData.length - 1) {
          updatedData.push({
            mill: '',
            quantity: 0,
            unit_cost: 0,
            commission: 0,
            cost: 0,
            total_commission: 0,
            total_cost: 0,
          });
        }

        setTableData(updatedData);
      } else {
        await napi.post('/pbn/update-detail', payload);
      }
    } catch (err) {
      console.error('Auto-save failed', err);
    }
  };

  const handlePbnSelect = async (selectedId: string) => {
    try {
      const storedUser = localStorage.getItem('user');
      const user = storedUser ? JSON.parse(storedUser) : null;

      const res = await napi.get(`/id/${selectedId}`, {
        params: { company_id: user?.company_id },
      });

      const data = res.data;

      // Set main form
      setPbnEntryMain(data);
      setSelectedPbnNo(data.main.id.toString());
      setSelectedSugarType(data.main.sugar_type);
      setSelectedCropYear(data.main.crop_year);
      setSelectedVendor(data.main.vend_code);
      setVendorName(data.main.vendor_name);
      setPosted(data.main.posted_flag === 1);
      setPbnDate(formatDateToYYYYMMDD(data.main.pbn_date));
      setIsExistingRecord(true);

      // 🔁 Fallback if vendor_name missing in the record: derive from vendors list
      if (!data.main.vendor_name && Array.isArray(vendors.items)) {
        const v = findVendorByCode(vendors.items as AnyVendor[], data.main.vend_code || '');
        if (v) setVendorName(pickVendorName(v));
      }

      const normalizedDetails: PbnDetailRow[] = Array.isArray(data.details) && data.details.length > 0
        ? data.details.map((detail: any) => ({
            mill: detail.mill || '',
            quantity: detail.quantity ?? 0,
            unit_cost: detail.unit_cost ?? 0,
            commission: detail.commission ?? 0,
            cost: Math.round((detail.quantity ?? 0) * (detail.unit_cost ?? 0) * 100) / 100,
            total_commission: (detail.quantity ?? 0) * (detail.commission ?? 0),
            total_cost:
              (detail.quantity ?? 0) * (detail.unit_cost ?? 0) +
              (detail.quantity ?? 0) * (detail.commission ?? 0),
            id: detail.id,
            row: detail.row,
            pbn_entry_id: detail.pbn_entry_id,
            pbn_number: detail.pbn_number,
            persisted: true,
          }))
        : [
            {
              mill: '',
              quantity: 0,
              unit_cost: 0,
              commission: 0,
              cost: 0,
              total_commission: 0,
              total_cost: 0,
              persisted: false,
            },
          ];

      // ❗ Step 1: Set the normalized details, but don't enable yet
      setPendingDetails(normalizedDetails);

      // ❗ Step 2: Set the ID — and wait for React to complete this state change
      setMainEntryId(data.main.id);
      setPbnNumber(data.main.pbn_number); // ✅ This is the missing line
    } catch (err) {
      console.error('Failed to fetch PBN data:', err);
    }
  };

  const filteredPbnOptions = useMemo(() => {
    const term = pbnSearch.toLowerCase();

    return pbnOptions.filter((item) =>
      (item.pbn_number || '').toLowerCase().includes(term) ||
      (item.vendor_name || '').toLowerCase().includes(term) ||
      (item.pbn_date || '').toLowerCase().includes(term) ||
      new Date(item.pbn_date).toLocaleDateString('en-US').includes(term)
    );
  }, [pbnOptions, pbnSearch]);

// OPEN PBN PDF (fixed: use API route + cache-buster)
const handleOpenPbnPdf = () => {
  if (!selectedPbnNo) {
    toast.error('Please select or save a PBN first.');
    return;
  }

  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  // Use the API prefix + cache-buster
  const url =
    `/api/pbn/form-pdf/${selectedPbnNo}` +
    `?company_id=${encodeURIComponent(user?.company_id || '')}` +
    `&_=${Date.now()}`;

  setPdfUrl(url);

  // Consolidate to a single modal
  setShowPdf(true);
  setPdfModalOpen(false); // ensure the old modal stays closed
};




  // ✅ Download Excel handler
// DOWNLOAD PBN EXCEL (hardened like GA)
const handleDownloadPbnExcel = async () => {
  if (!mainEntryId) {
    toast.info('Please save or select a PBN first.');
    return;
  }

  try {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    // axios (napi) already has baseURL '/api' in most setups; '/pbn/...' resolves to '/api/pbn/...'
    const res = await napi.get(`/pbn/form-excel/${mainEntryId}`, {
      responseType: 'blob',
      params: { company_id: user?.company_id || '' },
    });

    const ct = String(res.headers['content-type'] || '');

    // Guard: if backend sent JSON (422/404 messages), show toast instead of downloading a broken file
    if (
      !ct.includes('spreadsheet') &&
      !ct.includes('octet-stream') &&
      !ct.includes('application/vnd')
    ) {
      if (ct.includes('application/json')) {
        try {
          const txt = await (res.data.text?.() ?? new Response(res.data).text());
          const j = JSON.parse(txt);
          toast.error(j?.message || 'Excel export failed.');
        } catch {
          toast.error('Excel export failed (unexpected response).');
        }
      } else {
        toast.error('Excel export failed (unexpected response).');
      }
      return;
    }

    // Filename from Content-Disposition or fallback
    const cd = String(res.headers['content-disposition'] || '');
    const m  = cd.match(/filename\*?=(?:UTF-8'')?("?)([^";]+)\1/i) || [];
    const name = decodeURIComponent(m[2] || `PBN_${pbnNumber || mainEntryId}.xlsx`);

    const blob = new Blob([res.data], {
      type: ct || 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  } catch (e: any) {
    // If backend returned 4xx JSON as a Blob, surface the message
    const blob = e?.response?.data;
    if (blob instanceof Blob) {
      try {
        const txt = await blob.text();
        const j = JSON.parse(txt);
        toast.error(j?.message || 'Excel export failed.');
        return;
      } catch {/* ignore */}
    }
    toast.error(e?.response?.data?.message || 'Excel export failed.');
  }
};


  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-yellow-50 shadow-md rounded-lg p-6 space-y-4 border border-yellow-400">
        <h2 className="text-xl font-bold text-green-800 mb-4">Purchase Book Note Entry Main</h2>

        <div className="flex items-end gap-2 w-full">
          {/* Checkbox and Label */}
          <div className="flex items-center space-x-2">
            <input
              type="checkbox"
              checked={includePosted}
              onChange={() => setIncludePosted(!includePosted)}
              className="form-checkbox h-4 w-4 text-blue-600"
            />
            <label className="text-sm font-medium text-gray-700">PBN #</label>
          </div>

          {/* Dropdown */}
          <div className="flex-grow">
            <DropdownWithHeaders
              label=""
              value={selectedPbnNo}
              onChange={handlePbnSelect}
              items={filteredPbnOptions}
              search={pbnSearch}
              onSearchChange={setPbnSearch}
              headers={['ID', 'PBN Number', 'Sugar Type',  'Vendor Name', 'Crop Year', 'PBN Date']}
              dropdownPositionStyle={{ minWidth: '1200px' }}
              columnWidths={[
                '30px', '150px', '60px',  '200px', '80px', '120px'
              ]}
              customKey="pbn" // ✅ Add this line
            />
          </div>
        </div>

        <div className="grid grid-cols-3 gap-4">
          <DropdownWithHeaders label="Sugar Type" value={selectedSugarType} onChange={setSelectedSugarType} items={sugarTypes.items} search={sugarTypes.search} onSearchChange={sugarTypes.setSearch} headers={['Sugar Type', 'Description']} />
          <DropdownWithHeaders label="Crop Year" value={selectedCropYear} onChange={setSelectedCropYear} items={cropYears.items} search={cropYears.search} onSearchChange={cropYears.setSearch} headers={['Crop Year', 'FYear', 'TYear']} />
          <div>
            <label>PBN Date</label>
            <input type="date" value={pbnDate} onChange={(e) => setPbnDate(e.target.value)} className="w-full border p-2 bg-green-100 text-green-900" />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <DropdownWithHeaders
            label="Vendor"
            value={selectedVendor}
            onChange={setSelectedVendor}
            items={vendors.items}
            search={vendors.search}
            onSearchChange={vendors.setSearch}
            headers={['Vendor Code', 'Vendor Name']}
          />
          <div>
            <label className="block">Vendor Name</label>
            <input disabled className="w-full border p-2 bg-green-100 text-green-900" value={vendorName} />
          </div>
        </div>
      </div>

      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-6">
          Purchase Book Note Details
        </h2>
        {handsontableEnabled && (
          <HotTable
            ref={hotRef}
            data={tableData}
            colHeaders={['Mill', 'Quantity', 'Unit Cost', 'Commission', 'Cost', 'Total Commission', 'Total Cost']}
            columns={[
              {
                data: 'mill',
                type: 'autocomplete',        // base editor
                //source: mills.map(m => m.mill_name),
                source: (query, cb) => {
                  const list = mills.map(m => m.mill_name);
                  if (!query) return cb(list);
                  const q = String(query).toLowerCase();
                  cb(list.filter(name => name.toLowerCase().includes(q)));
                },

                strict: false,
                filter: true,
                allowInvalid: false,
                visibleRows: 10,              // show 8 items
                trimDropdown: false          // optionally allow wider dropdown
              },
              { data: 'quantity', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
              { data: 'unit_cost', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
              { data: 'commission', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
              { data: 'cost', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
              { data: 'total_commission', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
              { data: 'total_cost', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
            ]}

            afterBeginEditing={(row, col) => {
              isEditingRef.current = true;
              // Create room once; Handsontable will open the dropdown below by default
              requestAnimationFrame(() => ensureRoomBelow(row, col));
            }}

            beforeKeyDown={(e: KeyboardEvent) => {
              // If the autocomplete input is focused, let it own navigation keys
              const editor = document.querySelector('.handsontableEditor.autocompleteEditor');
              const active = document.activeElement;
              if (editor && active && editor.contains(active)) {
                const k = e.key;
                if (k === 'ArrowDown' || k === 'ArrowUp' || k === 'PageDown' || k === 'PageUp' || k === 'Home' || k === 'End') {
                  e.stopPropagation(); // keep navigation inside the dropdown
                }
              }

              // After first keystroke, the list pops in; make room below once more
              if (isEditingRef.current) {
                const hot = hotRef.current?.hotInstance;
                const sel = hot?.getSelectedLast();
                if (hot && sel) {
                  const [r, c] = sel;
                  requestAnimationFrame(() => ensureRoomBelow(r, c));
                }
              }
            }}

            afterSelectionEnd={() => {
              isEditingRef.current = false;
            }}

            afterDeselect={() => {
              isEditingRef.current = false;
            }}

            afterScrollVertically={() => {
              if (!isEditingRef.current) return;
              const hot = hotRef.current?.hotInstance;
              const sel = hot?.getSelectedLast();
              if (!hot || !sel) return;
              const [r, c] = sel;
              requestAnimationFrame(() => placeAutocompletePopup(r, c));
            }}

            afterRender={() => {
              if (!isEditingRef.current) return;
              const hot = hotRef.current?.hotInstance;
              const sel = hot?.getSelectedLast();
              if (!hot || !sel) return;
              const [r, c] = sel;
              requestAnimationFrame(() => placeAutocompletePopup(r, c));
            }}

            afterChange={(changes, source) => {
              if (!changes || source !== 'edit') return;

              const newData = [...tableData];
              let shouldAppendRow = false;

              changes.forEach(([rowIndex]) => {
                const row = newData[rowIndex];
                if (!row) return;

                const qty = row.quantity ?? 0;
                const uc = row.unit_cost ?? 0;
                const com = row.commission ?? 0;

                row.cost = Math.round(qty * uc * 100) / 100;
                row.total_commission = qty * com;
                row.total_cost = row.cost + row.total_commission;

                console.log(row);
                if (isRowComplete(row)) {
                  console.log('is it complete');
                  setTimeout(() => {
                    setTimeout(() => {
                      if (mainEntryId && isRowComplete(row)) {
                        handleAutoSaveDetail(row, rowIndex);
                      }
                    }, 0);
                  }, 0);

                  if (rowIndex === newData.length - 1) {
                    shouldAppendRow = true;
                  }
                }
              });

              if (shouldAppendRow) {
                newData.push({
                  mill: '',
                  quantity: 0,
                  unit_cost: 0,
                  commission: 0,
                  cost: 0,
                  total_commission: 0,
                  total_cost: 0,
                });
              }

              setTableData(ensureTrailingBuffer(newData));
            }}

            contextMenu={{
              items: {
                'remove_row': {
                  name: '🗑️ Remove row',
                  callback: async function (_key, selection) {
                    const rowIndex = selection[0].start.row;
                    const rowData = tableData[rowIndex];
                    const confirm = await Swal.fire({
                      title: 'Confirm Deletion?',
                      text: `Do you want to delete this record?`,
                      icon: 'warning',
                      showCancelButton: true,
                      confirmButtonText: 'Delete',
                      cancelButtonText: 'Cancel',
                    });

                    if (!confirm.isConfirmed) return;

                    try {
                      await napi.post('/pbn/delete-detail', {
                        ...rowData,
                        pbn_entry_id: rowData.pbn_entry_id,
                        pbn_number: rowData.pbn_number,
                        row: rowData.id,
                      });
                      const updatedData = [...tableData];
                      updatedData.splice(rowIndex, 1);
                      setTableData(updatedData);
                      toast.success('✅ Row deleted successfully');
                    } catch (err) {
                      toast.error('❌ Failed to delete record');
                      console.error(err);
                    }
                  },
                }
              }
            }}

            manualColumnResize={true} // 👈 allows drag-to-resize column width
            stretchH="all"
            width="100%"
            height={dynamicHeight}
            rowHeaders={true}
            licenseKey="non-commercial-and-evaluation"
          />
        )}
      </div>

      <div className="flex gap-3 mt-4 items-start">
        {/* DOWNLOAD (hover dropdown that stays open while hovering the menu) */}
        <div
          className="relative inline-block"
          onMouseEnter={() => setDownloadOpen(true)}
          onMouseLeave={() => setDownloadOpen(false)}
        >
          <button
            type="button"
            disabled={!isPbnReady}
            title={isPbnReady ? 'Download' : 'Save/select a PBN first'}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 focus:outline-none ${
              isPbnReady
                ? 'bg-white text-emerald-700 border-emerald-300 hover:bg-emerald-50 focus:ring-2 focus:ring-emerald-400'
                : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
            }`}
          >
            <ArrowDownTrayIcon
              className={`h-5 w-5 ${isPbnReady ? 'text-emerald-600' : 'text-gray-400'}`}
            />
            <span>Download</span>
            <ChevronDownIcon
              className={`h-4 w-4 opacity-70 ${isPbnReady ? 'text-emerald-700' : 'text-gray-400'}`}
            />
          </button>

          {/* Dropdown (no external gap so it doesn't flicker) */}
          {downloadOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadPbnExcel}
                  disabled={!isPbnReady}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    isPbnReady
                      ? 'text-gray-800 hover:bg-emerald-50'
                      : 'text-gray-400 cursor-not-allowed pointer-events-none'
                  }`}
                >
                  <DocumentArrowDownIcon
                    className={`h-5 w-5 ${isPbnReady ? 'text-emerald-600' : 'text-gray-400'}`}
                  />
                  <span className="truncate">PBN Form - Excel</span>
                  <span
                    className={`ml-auto text-[10px] font-semibold ${
                      isPbnReady ? 'text-emerald-600' : 'text-gray-400'
                    }`}
                  >
                    XLSX
                  </span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* PRINT (hover dropdown that stays open while hovering the menu) */}
        <div
          className="relative inline-block"
          onMouseEnter={() => setPrintOpen(true)}
          onMouseLeave={() => setPrintOpen(false)}
        >
          <button
            type="button"
            className="inline-flex items-center gap-2 rounded border px-3 py-2 bg-white text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-400"
          >
            <PrinterIcon className="h-5 w-5" />
            <span>Print</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {/* Dropdown (no external gap so it doesn't flicker) */}
          {printOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleOpenPbnPdf}
                  className="flex w-full items-center gap-3 px-3 py-2 text-sm text-gray-800 hover:bg-gray-100"
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">PBN Form - PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">PDF</span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* NEW (with icon) */}
        <button
          onClick={handleNew}
          className="inline-flex items-center gap-2 bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"
        >
          <PlusIcon className="h-5 w-5" />
          <span>New</span>
        </button>

        {/* SAVE (with icon) */}
        <button
          onClick={handleSave}
          disabled={isExistingRecord}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded ${
            isExistingRecord
              ? 'bg-gray-400 cursor-not-allowed text-white'
              : 'bg-green-600 text-white hover:bg-green-700'
          }`}
        >
          <CheckCircleIcon className="h-5 w-5" />
          <span>Save</span>
        </button>
      </div>

      {/* --- PDF Modal --- */}
     {/*} {pdfModalOpen && (
        <div
          className="fixed inset-0 z-[1000] flex items-center justify-center bg-black/50"
          onClick={() => setPdfModalOpen(false)}                // click on backdrop closes
        >
          <div
            className="bg-white rounded-lg shadow-2xl w-[90vw] max-w-[1100px] h-[88vh] flex flex-col"
            onClick={(e) => e.stopPropagation()}               // prevent backdrop close when clicking inside
          >
            
            <div className="flex items-center justify-between px-4 py-2 border-b">
              <div className="font-semibold">PBN Form – PDF</div>
              <button
                onClick={() => setPdfModalOpen(false)}
                className="p-2 hover:bg-gray-100 rounded"
                aria-label="Close"
              >
                <XMarkIcon className="h-6 w-6" />
              </button>
            </div>

           
            <div className="flex-1">
              {pdfUrl ? (
                <iframe
                  title="PBN PDF"
                  src={pdfUrl}
                  className="w-full h-full"
                />
              ) : (
                <div className="h-full flex items-center justify-center text-gray-500">
                  Loading PDF…
                </div>
              )}
            </div>
          </div>
        </div>
      )} */}

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
        <iframe
          key={pdfUrl}                        // <- forces rerender on URL change
          title="PBN Form PDF"
          src={pdfUrl}
          className="w-full h-full"
          style={{ border: 'none' }}
        />
      </div>
    </div>
  </div>
)}

    </div>
  );
}
