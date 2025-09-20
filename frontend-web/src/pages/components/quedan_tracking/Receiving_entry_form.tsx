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
import DropdownWithHeaders from '../DropdownWithHeaders';
import { ChevronDownIcon, PrinterIcon, PlusIcon, CheckCircleIcon } from '@heroicons/react/24/outline';
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
  const [_itemSearch, _setItemSearch] = useState('');

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



  // RR list
  useEffect(() => {
    (async () => {
      const { data } = await napi.get('/api/receiving/rr-list', {
        params: { include_posted: includePosted ? '1' : '0', q: rrSearch },
      });
      setRrOptions(data || []);
    })().catch(console.error);
  }, [includePosted, rrSearch]);

  // PBN list (always include posted=1, per legacy)
// PBN list (always include posted=1, per legacy)
useEffect(() => {
  (async () => {
    const resp = await napi.get('/api/pbn/list', { params: { q: pbnSearch } });
    // Accept both: `[]` or `{ data: [] }`
    const arr =
      Array.isArray(resp.data) ? resp.data :
      Array.isArray(resp.data?.data) ? resp.data.data :
      [];


    setPbnOptions(arr);
    console.log('PBN items →', pbnOptions.length, pbnOptions[0]);

  })().catch(console.error);
}, [pbnSearch]);





  const filteredRR = useMemo(() => {
    const term = rrSearch.toLowerCase();
    return rrOptions.filter(
      (r) =>
        (r.receipt_no || '').toLowerCase().includes(term) ||
        (r.pbn_number || '').toLowerCase().includes(term) ||
        (r.vendor_name || '').toLowerCase().includes(term),
    );
  }, [rrOptions, rrSearch]);


const applyItemSelection = async (i: PBNItemRow) => {
  setItemNumber(String(i.row));
  setMill(i.mill || '');
  setUnitCost(Number(i.unit_cost || 0));
  setCommission(Number(i.commission || 0));

  try {
    const { data: rate } = await napi.get('/api/mills/rate', {
      params: { mill_name: i.mill, as_of: dateReceived || undefined },
    });
    setInsuranceRate(Number(rate?.insurance_rate || 0));
    setStorageRate(Number(rate?.storage_rate || 0));
    setDaysFree(Number(rate?.days_free || 0));
  } catch { /* keep previous rates */ }
};




  // When RR selected (pre-fill everything)
  const onSelectRR = async (receiptNo: string) => {
    try {
      setSelectedRR(receiptNo);

      const { data: entry } = await napi.get('/api/receiving/entry', { params: { receipt_no: receiptNo } });
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
        const [{ data: pbnItems }, { data: pbnItemOne }, { data: rate }] = await Promise.all([
          napi.get('/api/pbn/items', { params: { pbn_number: entry.pbn_number } }),
          napi.get('/api/receiving/pbn-item', { params: { pbn_number: entry.pbn_number, item_no: entry.item_number } }),
          napi.get('/api/mills/rate', { params: { mill_name: entry.mill, as_of: toISO(entry.receipt_date) } }),
        ]);

        setItemOptions(Array.isArray(pbnItems) ? pbnItems : []);
        setUnitCost(Number(pbnItemOne?.unit_cost || 0));
        setCommission(Number(pbnItemOne?.commission || 0));
        setInsuranceRate(Number(rate?.insurance_rate || 0));
        setStorageRate(Number(rate?.storage_rate || 0));
        setDaysFree(Number(rate?.days_free || 0));
      }

      // load detail rows
      const { data: details } = await napi.get('/api/receiving/details', { params: { receipt_no: receiptNo } });
      setTableData(Array.isArray(details) ? details.map((d) => ({ ...d, persisted: true })) : []);
      setHandsontableEnabled(true);
    } catch (e) {
      console.error(e);
      toast.error('Failed to load Receiving Entry.');
    }
  };

  // ----------------- PBN + ITEM BEHAVIOR -----------------

const onSelectPBN = async (pbn: string) => {
  try {
    setPbnNumber(pbn);

    const row = pbnOptions.find(r => r.pbn_number === pbn);
    if (row) setVendorName(row.vendor_name || '');

    const { data } = await napi.get('/api/pbn/items', { params: { pbn_number: pbn } });
    const itemsArr: PBNItemRow[] = Array.isArray(data) ? data : [];
    setItemOptions(itemsArr);

    if (itemsArr.length === 1) {
      // ✅ apply directly from the fresh array (no race with setItemOptions)
      await applyItemSelection(itemsArr[0]);
    } else {
      // reset + focus Item # so the user picks
      setItemNumber('');
      setMill('');
      setUnitCost(0);
      setCommission(0);
      setTimeout(() => {
        const input = itemDropdownRef.current?.querySelector('input');
        input?.focus();
      }, 0);
    }
  } catch (e) {
    console.error(e);
    toast.error('Failed to load PBN items.');
  }
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




  // ----------------- FORMULAS -----------------

  const calcMonthsCeil = (fromISO: string, toISOdate: string) => {
    const diffDays = (new Date(toISOdate).getTime() - new Date(fromISO).getTime()) / 86400000;
    return Math.ceil(Math.abs(diffDays) / 30);
  };
  const calcMonthsFloorStorage = (fromISO: string, toISOdate: string, freeDays: number) => {
    let diffDays = (new Date(toISOdate).getTime() - new Date(fromISO).getTime()) / 86400000;
    diffDays -= freeDays;
    if (diffDays < 0) diffDays = 0;
    return Math.floor(diffDays / 30);
  };

  const recomputeTotals = (rows: ReceivingDetailRow[]) => {
    let totalQty = 0,
      totalLiens = 0,
      totalSto = 0,
      totalIns = 0,
      totalUnit = 0,
      totalAP = 0;

    const rrDate = dateReceived || '';
    const insOverride = insuranceWeek || '';
    const stoOverride = storageWeek || '';

    rows.forEach((r) => {
      const qty = Number(r.quantity || 0);
      const li = Number(r.liens || 0);
      totalQty += qty;
      totalLiens += li;

      const weekIns = insOverride || (r.week_ending ? toISO(r.week_ending) : '');
      const weekSto = stoOverride || (r.week_ending ? toISO(r.week_ending) : '');

      let ins = 0;
      if (weekIns && rrDate && !noInsurance) {
        const m = calcMonthsCeil(weekIns, rrDate);
        ins = qty * (insuranceRate || 0) * m;
      }

      let sto = 0;
      if (weekSto && rrDate && !noStorage) {
        const m = calcMonthsFloorStorage(weekSto, rrDate, daysFree || 0);
        sto = qty * (storageRate || 0) * m;
      }

      const uc = qty * (unitCost || 0) - (commission || 0);

      totalIns += ins;
      totalSto += sto;
      totalUnit += uc;
      totalAP += qty * (unitCost || 0) - ins - sto;
    });

    setTQty(totalQty);
    setTLiens(totalLiens);
    setTStorage(totalSto);
    setTInsurance(totalIns);
    setTUnitCost(totalUnit);
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
    await napi.post('/api/receiving/update-flag', {
      receipt_no: selectedRR,
      field,
      value: val ? 1 : 0,
    });
  };

  const pushDate = async (field: 'storage_week' | 'insurance_week' | 'receipt_date', valISO: string) => {
    if (!selectedRR) return;
    await napi.post('/api/receiving/update-date', {
      receipt_no: selectedRR,
      field,
      value: valISO || null,
    });
  };

  const onChangeNoStorage = async (val: boolean) => {
    setNoStorage(val);
    try {
      await pushFlag('no_storage', val);
      toast.success('Storage flag updated');
    } catch {
      toast.error('Failed to update storage flag');
    }
  };
  const onChangeNoInsurance = async (val: boolean) => {
    setNoInsurance(val);
    try {
      await pushFlag('no_insurance', val);
      toast.success('Insurance flag updated');
    } catch {
      toast.error('Failed to update insurance flag');
    }
  };

  const onChangeStorageWeek = async (val: string) => {
    setStorageWeek(val);
    try {
      await pushDate('storage_week', val);
    } catch {
      toast.error('Failed to update Storage Week');
    }
  };
  const onChangeInsuranceWeek = async (val: string) => {
    setInsuranceWeek(val);
    try {
      await pushDate('insurance_week', val);
    } catch {
      toast.error('Failed to update Insurance Week');
    }
  };

  const onChangeDateReceived = async (val: string) => {
    setDateReceived(val);
    try {
      await pushDate('receipt_date', val);
      toast.success('Date Received updated');
    } catch {
      toast.error('Failed to update Date Received');
    }
  };

  const handleAutoSave = async (rowData: ReceivingDetailRow, rowIndex: number) => {
    if (!selectedRR) return;
    try {
      const payload = { receipt_no: selectedRR, row_index: rowIndex, row: rowData };
      const { data } = await napi.post('/api/receiving/batch-insert', payload);
      const id = data?.id;
      if (id) {
        const updated = [...tableData];
        updated[rowIndex] = { ...rowData, id, persisted: true };
        setTableData(updated);
      }
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

    const { data } = await napi.post('/api/receiving/create-entry', payload);

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
      const { data: rr } = await napi.get('/api/receiving/rr-list', {
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



// === Receiving grid helpers (drop-in) ===
const gridWrapRef = useRef<HTMLDivElement>(null);
const [dynamicHeight, setDynamicHeight] = useState(520);

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

// when grid first turns on and there are no rows, add a blank buffer row
useEffect(() => {
  if (handsontableEnabled && tableData.length === 0) {
    setTableData(ensureTrailingBuffer(tableData));
  }
  // eslint-disable-next-line react-hooks/exhaustive-deps
}, [handsontableEnabled]);


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




  // ----------------- UI -----------------
  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={2500} />

      {/* Header card */}
      <div className="bg-yellow-50 shadow rounded-lg p-4 border border-yellow-300 space-y-4">
        <h2 className="text-lg font-bold text-slate-700">RECEIVING ENTRY</h2>

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
               
                <DropdownWithHeaders
                  label=""
                  value={selectedRR}
                  onChange={onSelectRR}
                  items={filteredRR.map((r) => ({
                    code: r.receipt_no,
                    label: r.receipt_no,
                    description: r.vendor_name,
                    quantity: r.quantity,
                    pbn_number: r.pbn_number,
                    sugar_type: r.sugar_type,
                    receipt_date: r.receipt_date,
                  }))}
                  search={rrSearch}
                  onSearchChange={setRrSearch}
                  headers={['Receipt No', 'Quantity', 'Sugar Type', 'PBN No', 'RDate', 'Vendor ID', 'Vendor Name']}
                  columnWidths={['120px', '90px', '70px', '110px', '110px', '110px', '220px']}
                  customKey="rr"
                  inputClassName={`${fieldSize} bg-green-100 text-green-900`}
                />
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
              <span className="inline-flex items-center text-xs px-2 rounded border">UD</span>
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
              <span className="inline-flex items-center text-xs px-2 rounded border">UM</span>
            </div>
          </div>
        </div>




      </div>

{/* Details */}
<div>
  <h3 className="font-semibold text-gray-800 mb-2">Details</h3>

  {handsontableEnabled && (
    // Outer wrapper can look nice (padding/border) — this does NOT host HotTable directly

  <div className="rounded-md border border-slate-300 bg-white p-2">
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
            { data: 'planter_tin', type: 'text', className: 'htCenter' },
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
            if (!changes || source !== 'edit') return;

            const updated: ReceivingDetailRow[] = [...tableData];
            let shouldAppendRow = false;

            changes.forEach(([rowIndex]) => {
              const r = { ...(updated[rowIndex] || {}) };
              const qty = Number(r.quantity || 0);

              // sync from header state
              r.item_no    = itemNumber || r.item_no;
              r.mill       = mill || r.mill;
              r.unit_cost  = unitCost || 0;
              r.commission = commission || 0;

              // compute based on your helpers/flags
              const rrDateISO  = dateReceived || '';
              const rowWeekISO = r.week_ending ? toISO(r.week_ending) : '';

              let ins = 0;
              const insFrom = (insuranceWeek || rowWeekISO);
              if (insFrom && rrDateISO && !noInsurance) {
                const m = calcMonthsCeil(insFrom, rrDateISO);
                ins = qty * (insuranceRate || 0) * m;
              }

              let sto = 0;
              const stoFrom = (storageWeek || rowWeekISO);
              if (stoFrom && rrDateISO && !noStorage) {
                const m = calcMonthsFloorStorage(stoFrom, rrDateISO, daysFree || 0);
                sto = qty * (storageRate || 0) * m;
              }

              r.storage   = sto;
              r.insurance = ins;
              r.total_ap  = qty * (unitCost || 0) - sto - ins;

              updated[rowIndex] = r;

              if (r.quedan_no && qty > 0) {
                setTimeout(() => handleAutoSave(r, rowIndex), 0);
                if (rowIndex === updated.length - 1) shouldAppendRow = true;
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
        <input
          value={glAccountKey}
          onChange={(e) => setGlAccountKey(e.target.value)}
          onBlur={async () => {
            if (!selectedRR) return;
            try {
              await napi.post('/api/receiving/update-gl', {
                receipt_no: selectedRR,
                gl_account_key: glAccountKey,
              });
              toast.success('GL Account updated');
            } catch {
              toast.error('Failed to update GL Account');
            }
          }}
          className="w-full border p-2 rounded bg-yellow-50"
        />
      </div>

      <div className="col-span-3">
        <label className="block text-xs font-semibold text-slate-600">Assoc Dues</label>
        <input
          type="number"
          step="0.01"
          value={assocDues}
          onChange={(e) => setAssocDues(e.target.value === '' ? '' : Number(e.target.value))}
          onBlur={async () => {
            if (!selectedRR) return;
            try {
              await napi.post('/api/receiving/update-assoc-others', {
                receipt_no: selectedRR,
                assoc_dues: Number(assocDues || 0),
                others: Number(others || 0),
              });
              toast.success('Assoc/Others saved');
            } catch {
              toast.error('Failed to save Assoc/Others');
            }
          }}
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
          onBlur={async () => {
            if (!selectedRR) return;
            try {
              await napi.post('/api/receiving/update-assoc-others', {
                receipt_no: selectedRR,
                assoc_dues: Number(assocDues || 0),
                others: Number(others || 0),
              });
              toast.success('Assoc/Others saved');
            } catch {
              toast.error('Failed to save Assoc/Others');
            }
          }}
          className="w-full border p-2 rounded bg-yellow-50"
        />
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
</div>




      {/* Actions */}
      <div className="flex items-center gap-2">
        <button className="inline-flex items-center gap-2 rounded border px-3 py-2 bg-white">
          <PrinterIcon className="h-5 w-5" />
          <span>Print</span>
          <ChevronDownIcon className="h-4 w-4" />
        </button>
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
    </div>
  );
  
}
