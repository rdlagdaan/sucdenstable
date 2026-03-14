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

  particulars: string;      // ✅ NEW
  mill: string;
  quantity: number;

  price: number;            // ✅ renamed from unit_cost
  handling_fee: number;     // ✅ NEW input
  commission: number;

  cost: number;
  total_commission: number;

  handling: number;         // ✅ NEW readonly computed
  total_cost: number;

  pbn_entry_id?: number;
  pbn_number?: string;      // keep for now, but backend may return po_number too
  persisted?: boolean;
}


type MillLite = { mill_id: string | number; mill_name: string; company_id: number };



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
const [note, setNote] = useState('');

const [selectedTerms, setSelectedTerms] = useState('');
const [termsSearch, setTermsSearch] = useState('');
const [termsOptions, setTermsOptions] = useState<{ code: string; description: string }[]>([]);

  //Establishing data state for the Handsontable
  const [handsontableEnabled, setHandsontableEnabled] = useState(false);
  const [mainEntryId, setMainEntryId] = useState<number | null>(null);

  const [_pdfModalOpen, setPdfModalOpen] = useState(false);
  // ✅ PDF modal state (these MUST exist if you use showPdf/setShowPdf in JSX)
const [showPdf, setShowPdf] = useState(false);

  const [pdfUrl, setPdfUrl] = useState<string>(''); // never undefined

  // ⬇️ add these two right after your other useState hooks
  const pdfBlobUrlRef = useRef<string | null>(null);
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
const [particularsList, setParticularsList] = useState<string[]>([]);

  const [pbnOptions, setPbnOptions] = useState<PbnDropdownItem[]>([]);
  const [selectedPbnNo, setSelectedPbnNo] = useState('');
  const [pbnSearch, setPbnSearch] = useState('');
  const [includePosted, setIncludePosted] = useState(false); // for checkbox

  const [_pbnEntryMain, setPbnEntryMain] = useState<any>(null);
  const [isExistingRecord, setIsExistingRecord] = useState(false);

  const sugarTypes = useDropdownOptions('/sugar-types');
  //const cropYears = useDropdownOptions('/crop-years');
  
  // ✅ Crop years MUST include begin_year/end_year (hook likely strips these),
  // so we load them raw here.
  const [cropYearSearch, setCropYearSearch] = useState('');
  const [cropYearRawItems, setCropYearRawItems] = useState<any[]>([]);



  // ✅ Normalize CropYears to what DropdownWithHeaders expects
  // ✅ Crop Year: show BYear=begin_year and EYear=end_year
  // ✅ Crop Year dropdown items: Byear=begin_year, Eyear=end_year (and local search)
  // ✅ Crop Year dropdown: code = crop_year, description = "begin_year - end_year"
  // This avoids relying on extra columns that DropdownWithHeaders is not rendering correctly.
  const cropYearItems = useMemo(() => {
    const src = Array.isArray(cropYearRawItems) ? cropYearRawItems : [];

    return src
      .map((cy: any) => {
        const crop  = String(cy?.crop_year ?? '').trim();
        const begin = String(cy?.begin_year ?? '').trim();
        const end   = String(cy?.end_year ?? '').trim();

        return {
          code: crop,                           // ✅ 1st column: crop_year
          description: `${begin} - ${end}`,     // ✅ 2nd/3rd shown together reliably
          begin_year: begin,                    // keep available if needed later
          end_year: end,
        };
      })
      .filter(x => !!x.code);
  }, [cropYearRawItems]);






  // ✅ Vendors: load ONCE (same pattern as CashDisbursement), then DropdownWithHeaders filters locally
const [vendors, setVendors] = useState<{ code: string; description: string }[]>([]);
const [vendSearch, setVendSearch] = useState('');

useEffect(() => {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  let cancelled = false;

  (async () => {
    try {
      const { data } = await napi.get('/vendors', {
        params: { company_id: user?.company_id || '' },
      });

      const src = Array.isArray(data) ? data : [];

      // normalize -> {code, description} then dedupe by code
      const seen = new Set<string>();
      const out: { code: string; description: string }[] = [];

      for (const v of src) {
        const code = String(v?.vend_code ?? v?.vend_id ?? v?.code ?? '').trim();
        const name = String(v?.vend_name ?? v?.vendor_name ?? v?.description ?? '').trim();
        if (!code) continue;

        const k = code.toUpperCase(); // dedupe by code
        if (seen.has(k)) continue;
        seen.add(k);

        out.push({ code, description: name });
      }

      if (!cancelled) setVendors(out);
    } catch (e) {
      console.error('Failed to load vendors', e);
      if (!cancelled) setVendors([]);
    }
  })();

  return () => { cancelled = true; };
}, []);

    // ✅ Company scope for vendor filtering (prevents cross-company duplicates if payload includes company_id)
  //const companyId = useMemo(() => {
  //  const storedUser = localStorage.getItem('user');
  //  const user = storedUser ? JSON.parse(storedUser) : null;
  //  return Number(user?.company_id || 0);
  //}, []);



    // ✅ Normalize Vendors to what DropdownWithHeaders expects so search works reliably
  






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
    row.particulars &&
    row.mill &&
    row.quantity !== undefined &&
    row.price !== undefined &&
    row.handling_fee !== undefined &&
    row.commission !== undefined &&
    row.particulars !== '' &&
    row.mill !== '' &&
    row.quantity !== '' &&
    row.price !== '' &&
    row.handling_fee !== '' &&
    row.commission !== ''
  );
};


  // ---- Trailing blank rows helpers (add below isRowComplete) ----
  const TRAILING_BUFFER_ROWS = 4;

  const gridLocked = posted === true; // lock when posted


const emptyRow = (): PbnDetailRow => ({
  particulars: '',
  mill: '',
  quantity: 0,

  price: 0,
  handling_fee: 0,
  commission: 0,

  cost: 0,
  total_commission: 0,
  handling: 0,
  total_cost: 0,

  persisted: false,
});


const isRowEmpty = (r?: PbnDetailRow) =>
  !r?.particulars && !r?.mill && !r?.quantity && !r?.price && !r?.commission && !r?.handling_fee;


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
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    let cancelled = false;

    async function loadCropYears() {
      try {
        const { data } = await napi.get('/crop-years', {
          params: { company_id: user?.company_id || '' },
        });

        if (!cancelled) {
          setCropYearRawItems(Array.isArray(data) ? data : []);
        }
      } catch (e) {
        console.error('Failed to load crop years', e);
        if (!cancelled) setCropYearRawItems([]);
      }
    }

    loadCropYears();
    return () => { cancelled = true; };
  }, []);


  useEffect(() => {
    fetchPbnEntries();
  }, [includePosted]);


useEffect(() => {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  let cancelled = false;

  async function loadParticulars() {
    try {
      const { data } = await napi.get('/pbn/particulars', {
        params: { company_id: user?.company_id || '' },
      });

      // Expect backend to return: [{particular_name: 'RAW SUGAR'}, ...]
      const names = Array.isArray(data)
        ? data.map((x: any) => String(x?.particular_name || '').trim()).filter(Boolean)
        : [];

      if (!cancelled) setParticularsList(names);
    } catch (e) {
      console.error('Failed to load particulars', e);
      if (!cancelled) setParticularsList([]);
    }
  }

  loadParticulars();
  return () => { cancelled = true; };
}, []);


useEffect(() => {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  let cancelled = false;

  async function loadTerms() {
    try {
      const { data } = await napi.get('/pbn/terms', {
        params: { company_id: user?.company_id || '' },
      });

      const items = Array.isArray(data)
        ? data
            .map((x: any) => ({
              code: String(x?.term_code || '').trim(),
              description: String(x?.term_name || '').trim(),
            }))
            .filter((x: any) => !!x.code)
        : [];

      if (!cancelled) setTermsOptions(items);
    } catch (e) {
      console.error('Failed to load terms', e);
      if (!cancelled) setTermsOptions([]);
    }
  }

  loadTerms();
  return () => { cancelled = true; };
}, []);

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
// ✅ Auto-fill Vendor Name based on selected vendor code
useEffect(() => {
  if (!selectedVendor) return;
  const sel = vendors.find(v => String(v.code) === String(selectedVendor));
  if (sel) setVendorName(sel.description || '');
}, [selectedVendor, vendors]);


  /* ------------------------------------------------------------------------------------------ */



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
    setNote('');
    setSelectedTerms('');
    setPosted(false);
    setHandsontableEnabled(false);
    setMainEntryId(null);

    setTableData(ensureTrailingBuffer([emptyRow()]));

    setIsExistingRecord(false);
    toast.success('✅ PBN Entry form is now ready for new entry');
  };


const handleSaveMain = async () => {
  if (!mainEntryId) {
    toast.info('Please select or save a PO first.');
    return;
  }
  if (posted) {
    toast.info('This PO is posted and cannot be updated.');
    return;
  }

  const confirm = await Swal.fire({
    title: 'Save Main Changes?',
    text: 'This will update Sugar Type, Crop Year, PO Date, Vendor, Note and Terms.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, Save Main',
  });

  if (!confirm.isConfirmed) return;

  try {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    await napi.post('/pbn/update-main', {
  id: mainEntryId,
  sugar_type: selectedSugarType,
  crop_year: selectedCropYear,
  pbn_date: pbnDate,
  vend_code: selectedVendor,
  vendor_name: vendorName,
  note,
  terms: selectedTerms || null,
  company_id: user?.company_id || '',
});

    await fetchPbnEntries(); // refresh dropdown list display
    toast.success('✅ Main updated successfully');
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to update main.');
    console.error(e);
  }
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

// ✅ PO number is now generated by backend using application_settings (PONoImpExp)
// No separate "generate" call here.



const res = await napi.post('/pbn/save-main', {
  sugar_type: selectedSugarType,
  crop_year: selectedCropYear,
  pbn_date: pbnDate,
  vend_code: selectedVendor,
  vendor_name: vendorName,
  note,
  terms: selectedTerms || null,
  posted_flag: posted,
  company_id: companyId,
});


      await fetchPbnEntries();

const { id, po_number } = res.data;

setMainEntryId(id);
setSelectedPbnNo(id.toString());

// ✅ display/store PO number in state
setPbnNumber(String(po_number || ''));


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
cost: Math.round((rowData.quantity * rowData.price) * 100) / 100,
total_commission: Math.round((rowData.quantity * rowData.commission) * 100) / 100,
handling: Math.round((rowData.price * rowData.handling_fee) * 100) / 100,
total_cost: Math.round((
  (rowData.quantity * rowData.price) +
  (rowData.quantity * rowData.commission) +
  (rowData.price * rowData.handling_fee)
) * 100) / 100,

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
updatedData.push(emptyRow());

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

      const res = await napi.get(`/pbn/${selectedId}`, {
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
setNote(data.main.note || '');
setSelectedTerms(data.main.terms || '');

setPosted(data.main.posted_flag === 1);
      setPbnDate(formatDateToYYYYMMDD(data.main.pbn_date));
      setIsExistingRecord(true);

      // 🔁 Fallback if vendor_name missing in the record: derive from vendors list
// 🔁 Fallback if vendor_name missing in the record: derive from loaded vendors list
if (!data.main.vendor_name) {
  const sel = vendors.find(v => String(v.code) === String(data.main.vend_code || ''));
  if (sel) setVendorName(sel.description || '');
}


      const normalizedDetails: PbnDetailRow[] = Array.isArray(data.details) && data.details.length > 0
        ? data.details.map((detail: any) => ({
particulars: detail.particulars || '',
            
            mill: detail.mill || '',
            quantity: detail.quantity ?? 0,
            price: detail.price ?? detail.unit_cost ?? 0,
            commission: detail.commission ?? 0,
            handling_fee: detail.handling_fee ?? 0,

            cost: Math.round((detail.quantity ?? 0) * ((detail.price ?? detail.unit_cost) ?? 0) * 100) / 100,
            total_commission: (detail.quantity ?? 0) * (detail.commission ?? 0),
handling: Math.round((((detail.price ?? detail.unit_cost) ?? 0) * (detail.handling_fee ?? 0)) * 100) / 100,
total_cost:
  ((detail.quantity ?? 0) * (((detail.price ?? detail.unit_cost) ?? 0))) +
  ((detail.quantity ?? 0) * (detail.commission ?? 0)) +
  ((((detail.price ?? detail.unit_cost) ?? 0) * (detail.handling_fee ?? 0))),

            id: detail.id,
            row: detail.row,
            pbn_entry_id: detail.pbn_entry_id,
            pbn_number: detail.pbn_number,
            persisted: true,
          }))
        : [
emptyRow(),

          ];

      // ❗ Step 1: Set the normalized details, but don't enable yet
      setPendingDetails(normalizedDetails);

      // ❗ Step 2: Set the ID — and wait for React to complete this state change
      setMainEntryId(data.main.id);
      setPbnNumber(data.main.po_number || data.main.pbn_number || '');
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
const handleOpenPbnPdf = async () => {
  if (!selectedPbnNo) {
    toast.error('Please select or save a PBN first.');
    return;
  }

  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  // cleanup previous blob url if any
  if (pdfBlobUrlRef.current) {
    URL.revokeObjectURL(pdfBlobUrlRef.current);
    pdfBlobUrlRef.current = null;
  }

  try {
    // IMPORTANT: use axios instance so baseURL + cookies are correct
    const res = await napi.get(`/pbn/form-pdf/${selectedPbnNo}`, {
      responseType: 'blob',
      params: {
        company_id: user?.company_id || '',
        _: Date.now(),
      },
    });

    const ct = String(res.headers['content-type'] || '');

    // If server returned HTML/JSON, that means redirect/404/error, not a PDF
    if (!ct.includes('application/pdf')) {
      // Try to decode error message
      try {
        const txt = await (res.data as Blob).text();
        // JSON?
        if (ct.includes('application/json')) {
          const j = JSON.parse(txt);
          toast.error(j?.message || 'PDF export failed.');
        } else {
          toast.error('PDF export failed (server returned non-PDF response).');
          console.error('Non-PDF response:', txt);
        }
      } catch {
        toast.error('PDF export failed (unexpected response).');
      }
      return;
    }

    const blob = new Blob([res.data], { type: 'application/pdf' });
    const blobUrl = URL.createObjectURL(blob);
    pdfBlobUrlRef.current = blobUrl;

    setPdfUrl(blobUrl);
    setShowPdf(true);
    setPdfModalOpen(false);
  } catch (e: any) {
    // If backend returned JSON error as blob, show message
    const blob = e?.response?.data;
    if (blob instanceof Blob) {
      try {
        const txt = await blob.text();
        const j = JSON.parse(txt);
        toast.error(j?.message || 'PDF export failed.');
        return;
      } catch {/* ignore */}
    }

    toast.error(e?.response?.data?.message || 'PDF export failed.');
    console.error(e);
  }
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
        const name = decodeURIComponent(m[2] || `PO_${pbnNumber || mainEntryId}.xls`);


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


const cellProps: Handsontable.GridSettings['cells'] = (_row, col) => {
  // return only overrides; HOT merges this with defaults
// When posted, lock ALL input columns (Particulars..Commission)
if (posted && (col >= 0 && col <= 5)) {
  return { readOnly: true };
}

  return {};
};



  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-yellow-50 shadow-md rounded-lg p-6 space-y-4 border border-yellow-400">
        <h2 className="text-xl font-bold text-green-800 mb-4">Purchase Order Entry Main</h2>

        <div className="flex items-end gap-2 w-full">
          {/* Checkbox and Label */}
          <div className="flex items-center space-x-2">
            <input
              type="checkbox"
              checked={includePosted}
              onChange={() => setIncludePosted(!includePosted)}
              className="form-checkbox h-4 w-4 text-blue-600"
            />
            <label className="text-sm font-medium text-gray-700">PO #</label>
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
              headers={['ID', 'PBN Number', 'Sugar Type',  'Vendor Name', 'Crop Year', 'PO Date']}
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
          <DropdownWithHeaders
            label="Crop Year"
            value={selectedCropYear}
            onChange={setSelectedCropYear}
            items={cropYearItems}
            search={cropYearSearch}
            onSearchChange={setCropYearSearch}
            headers={['Crop Year', 'Begin - End']}   // ✅ matches what we reliably show
            customKey="cropYear"
          />




          <div>
            <label>PO Date</label>
            <input type="date" value={pbnDate} onChange={(e) => setPbnDate(e.target.value)} className="w-full border p-2 bg-green-100 text-green-900" />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
<DropdownWithHeaders
  label="Vendor"
  value={selectedVendor}
  onChange={(v) => {
    setSelectedVendor(v);
    const sel = vendors.find(x => String(x.code) === String(v));
    setVendorName(sel?.description || '');
  }}
  items={vendors}
  search={vendSearch}
  onSearchChange={setVendSearch}
  headers={['Vendor Code', 'Vendor Name']}
  customKey="vendor"
/>







          <div>
            <label className="block">Vendor Name</label>
            <input disabled className="w-full border p-2 bg-green-100 text-green-900" value={vendorName} />
          </div>
        </div>

<div className="mt-2">
  <div className="grid grid-cols-4 gap-4 items-start">
    <div className="col-span-3">
      <label className="block">Note</label>
      <textarea
        value={note}
        onChange={(e) => setNote(e.target.value)}
        className="w-full border p-2 bg-white text-gray-900 min-h-[90px]"
        placeholder="Enter note..."
        disabled={posted}
      />
    </div>

    <div className="col-span-1">
<DropdownWithHeaders
  label="Terms"
  value={selectedTerms}
  onChange={(v) => {
    if (posted) return;
    setSelectedTerms(v);
  }}
  items={termsOptions}
  search={termsSearch}
  onSearchChange={(v) => {
    if (posted) return;
    setTermsSearch(v);
  }}
  headers={['Terms Code', 'Description']}
  customKey="pbnTerms"
/>
    </div>
  </div>

  <div className="mt-3 flex justify-end">
    <button
      type="button"
      onClick={handleSaveMain}
      disabled={!isExistingRecord || !mainEntryId || posted}
      className={`inline-flex items-center gap-2 px-4 py-2 rounded ${
        (!isExistingRecord || !mainEntryId || posted)
          ? 'bg-gray-300 cursor-not-allowed text-white'
          : 'bg-emerald-600 text-white hover:bg-emerald-700'
      }`}
      title={
        posted
          ? 'Posted PO cannot be updated'
          : (!mainEntryId ? 'Save or select a PO first' : 'Save Main')
      }
    >
      <CheckCircleIcon className="h-5 w-5" />
      <span>Save Main</span>
    </button>
  </div>
</div>


      </div>

      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-6">
          Purchase Order Details
        </h2>
        {handsontableEnabled && (
          <HotTable
            ref={hotRef}
            data={tableData}
            readOnly={posted}            // global lock (good baseline)
            cells={cellProps}  
            colHeaders={[
  'Particulars',
  'Mill',
  'Quantity',
  'Price',
  'Handling Fee',
  'Facilitation',
  'Cost',
  'Total Facilitation',
  'Handling',
  'Total Cost',
]}
columns={[
  {
    data: 'particulars',
    type: 'autocomplete',
    source: (query, cb) => {
      const list = particularsList;
      if (!query) return cb(list);
      const q = String(query).toLowerCase();
      cb(list.filter(name => name.toLowerCase().includes(q)));
    },
    strict: true,
    allowInvalid: false,
    filter: true,
    visibleRows: 10,
    trimDropdown: false,
    readOnly: gridLocked,
  },
  {
    data: 'mill',
    type: 'autocomplete',
    source: (query, cb) => {
      const list = mills.map(m => m.mill_name);
      if (!query) return cb(list);
      const q = String(query).toLowerCase();
      cb(list.filter(name => name.toLowerCase().includes(q)));
    },
    strict: false,
    filter: true,
    allowInvalid: false,
    visibleRows: 10,
    trimDropdown: false,
    readOnly: gridLocked,
  },
  { data: 'quantity', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
  { data: 'price', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
  { data: 'handling_fee', type: 'numeric', numericFormat: { pattern: '0,0.00' } },
  { data: 'commission', type: 'numeric', numericFormat: { pattern: '0,0.00' } },

  { data: 'cost', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
  { data: 'total_commission', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },

  { data: 'handling', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
  { data: 'total_cost', type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00' } },
]}


            afterBeginEditing={(row, col) => {
              isEditingRef.current = true;
              // Create room once; Handsontable will open the dropdown below by default
              requestAnimationFrame(() => ensureRoomBelow(row, col));
            }}

            beforeKeyDown={(e: KeyboardEvent) => {

              if (gridLocked) {
                // block Delete/Backspace edits when posted
                if (e.key === 'Delete' || e.key === 'Backspace') {
                  e.preventDefault();
                  e.stopPropagation();
                  return;
                }
              }
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
              if (posted) return;
              if (gridLocked) return; 
              if (!changes || source !== 'edit') return;

              const newData = [...tableData];
              let shouldAppendRow = false;

              changes.forEach(([rowIndex]) => {
                const row = newData[rowIndex];
                if (!row) return;

                const qty = row.quantity ?? 0;
const price = row.price ?? 0;
const hf    = row.handling_fee ?? 0;
const com   = row.commission ?? 0;

row.cost = Math.round(qty * price * 100) / 100;
row.total_commission = Math.round(qty * com * 100) / 100;

// Handling = Price * Handling Fee (as required)
row.handling = Math.round(price * hf * 100) / 100;

// Total Cost includes handling (recommended; otherwise Handling is ignored)
// If you want Total Cost to exclude handling, tell me and I will adjust.
row.total_cost = Math.round((row.cost + row.total_commission + row.handling) * 100) / 100;


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
newData.push(emptyRow());

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
                  onMouseDown={(e) => {
  e.preventDefault();
  e.stopPropagation();
  handleOpenPbnPdf();
}}
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
        onClick={() => {
  setShowPdf(false);
  if (pdfBlobUrlRef.current) {
    URL.revokeObjectURL(pdfBlobUrlRef.current);
    pdfBlobUrlRef.current = null;
  }
  setPdfUrl('');
}}
        className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200"
        aria-label="Close"
      >
        ✕
      </button>

      <div className="h-full w-full pt-8">
        {!pdfUrl ? (
          <div className="h-full w-full flex items-center justify-center text-gray-600">
            Loading PDF…
          </div>
        ) : (
          <iframe
            key={pdfUrl} // rerender when url changes
            title="PBN Form PDF"
            src={pdfUrl}
            className="w-full h-full"
            style={{ border: 'none' }}
            onLoad={() => console.log('✅ iframe loaded:', pdfUrl)}
            onError={() => console.log('❌ iframe error:', pdfUrl)}
          />
        )}
      </div>
    </div>
  </div>
)}

    </div>
  );
}
