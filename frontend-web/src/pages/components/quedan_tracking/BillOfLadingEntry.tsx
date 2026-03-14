import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import {
  PlusIcon,
  CheckCircleIcon,
  TrashIcon,
  ChevronDownIcon,
  ChevronRightIcon,
} from '@heroicons/react/24/outline';

import napi from '../../../utils/axiosnapi';
import DropdownWithHeadersDynamic from '../DropdownWithHeadersDynamic';
import AttachedDropdown from '../../components/AttachedDropdown';

type PoDropdownItem = {
  id: number;
  po_no: string;
  vendor_code?: string;
  vendor_name: string;
};

type PoItemDropdownItem = {
  id: number;
  row: number;
  pbn_entry_id: number;
  pbn_number: string;
  particulars: string;
  item_label: string;
  mill_code?: string;
  mill?: string;
  quantity?: number;
  price?: number;
  cost?: number;
  total_cost?: number;
  is_refined_sugar?: boolean;
};

type BlHeaderItem = {
  id: number;
  bl_entry_no: string;
  po_no: string;
  vendor_name: string;
  bl_date?: string;
  status?: string;
  line_count?: number;
  processed_flag?: boolean;
  processed_by?: string | null;
  processed_at?: string | null;
  cash_purchase_id?: number | null;
  cp_no?: string | null;
};


type PaymentMethodItem = {
  id: number;
  pay_method_id: string;
  pay_method: string;
};

type BankItem = {
  id: number;
  bank_id: string;
  bank_name: string;
  bank_account_number?: string;
};

type ProcessPreviewLine = {
  acct_code: string;
  acct_desc: string;
  debit: number;
  credit: number;
};

type ProcessPreviewData = {
  cp_no: string;
  vend_id: string;
  purchase_date: string | null;
  explanation: string;
  amount_in_words: string;
  booking_no: string | null;
  crop_year: string | null;
  sugar_type: string | null;
  mill_id: string | null;
  rr_no: string | null;
  lines: ProcessPreviewLine[];
  sum_debit: number;
  sum_credit: number;
};


type BillOfLadingRow = {
  id?: number;
  line_no?: number;
  item_no?: number;
  bl_no?: string;
  mt?: number;
  bags?: number;
  cif_price?: number;
  cif_usd?: number;
  fx_rate?: number;
  cif_php?: number;
  sad_no?: string;
  ssdt_no?: string;
  fan_no?: string;
  registration_date?: string | null;
  assessment_date?: string | null;
  pay_date?: string | null;
  si_no?: string;
  dutiable_value?: number;
  duty?: number;
  brokerage?: number;
  wharfage?: number;
  arrastre?: number;
  other_charges?: number;
  adjustment?: number;
  landed_cost?: number;
  vat?: number;
  other_taxes?: number;
  boc_total?: number;
  remarks?: string;
  purchase_order_line_id?: number | null;
  consumed_qty_mt?: number;
  consumed_bags?: number;
  persisted?: boolean;

  // UX-only fields
  isCollapsed?: boolean;
  isDirty?: boolean;
  savedAtLabel?: string;
};

const currency = (n: number) =>
  Number(Math.round((n || 0) * 100) / 100).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

const toISO = (d: string | null | undefined) =>
  !d ? '' : new Date(d).toISOString().slice(0, 10);

const normalizeItemName = (v: any) => String(v ?? '').trim().toLowerCase();
const isRefinedSugarItem = (v: any) => normalizeItemName(v).includes('refined sugar');

const nowTimeLabel = () =>
  new Date().toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  });

const rowStatus = (row: BillOfLadingRow): 'saved' | 'unsaved' | 'new' => {
  if (row.persisted && !row.isDirty) return 'saved';
  if (row.persisted && row.isDirty) return 'unsaved';
  return 'new';
};

const statusBadgeClass = (row: BillOfLadingRow) => {
  const status = rowStatus(row);
  if (status === 'saved') {
    return 'bg-emerald-100 text-emerald-700 border border-emerald-300';
  }
  if (status === 'unsaved') {
    return 'bg-amber-100 text-amber-700 border border-amber-300';
  }
  return 'bg-slate-100 text-slate-700 border border-slate-300';
};

const statusLabel = (row: BillOfLadingRow) => {
  const status = rowStatus(row);
  if (status === 'saved') return 'SAVED';
  if (status === 'unsaved') return 'UNSAVED';
  return 'NEW';
};

const shipmentCardClass = (row: BillOfLadingRow) => {
  const status = rowStatus(row);
  if (status === 'saved') {
    return 'rounded-lg border border-emerald-300 bg-emerald-50 shadow-sm p-4 space-y-4';
  }
  if (status === 'unsaved') {
    return 'rounded-lg border border-amber-300 bg-amber-50 shadow-sm p-4 space-y-4';
  }
  return 'rounded-lg border border-slate-300 bg-white shadow-sm p-4 space-y-4';
};


const toNum = (v: any, decimals = 2) => {
  const n = Number(v || 0);
  if (!Number.isFinite(n)) return 0;
  const factor = Math.pow(10, decimals);
  return Math.round(n * factor) / factor;
};

function computeRow(row: BillOfLadingRow): BillOfLadingRow {
  const bags = Math.round(Number(row.bags || 0));
  const mt = toNum(bags / 20, 3);
  const cifPrice = toNum(row.cif_price || 0, 6);
  const fxRate = toNum(row.fx_rate || 0, 6);
  const cifUsd = toNum(cifPrice * mt, 2);
  const cifPhp = toNum(cifUsd * fxRate, 2);

  const dutiableValue = toNum(row.dutiable_value || 0, 2);
  const duty = toNum(dutiableValue * 0.05, 2);

  const brokerage = toNum(row.brokerage || 0, 2);
  const wharfage = toNum(row.wharfage || 0, 2);
  const arrastre = toNum(row.arrastre || 0, 2);
  const otherCharges = toNum(row.other_charges || 0, 2);
  const adjustment = toNum(row.adjustment || 0, 2);

  const landedCost = toNum(
    dutiableValue + duty + brokerage + wharfage + arrastre + otherCharges + adjustment,
    2
  );

  const vat = toNum(landedCost * 0.12, 2);
  const otherTaxes = toNum(row.other_taxes || 0, 2);
  const bocTotal = toNum(otherTaxes + vat + duty, 2);

  return {
    ...row,
    bags,
    mt,
    cif_price: cifPrice,
    fx_rate: fxRate,
    cif_usd: cifUsd,
    cif_php: cifPhp,
    dutiable_value: dutiableValue,
    duty,
    brokerage,
    wharfage,
    arrastre,
    other_charges: otherCharges,
    adjustment,
    landed_cost: landedCost,
    vat,
    other_taxes: otherTaxes,
    boc_total: bocTotal,
    consumed_qty_mt: mt,
    consumed_bags: bags,
  };
}

export default function BillOfLadingEntry() {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = user?.company_id;
  const userId = user?.id ?? user?.user_id;

  const [headerOptions, setHeaderOptions] = useState<BlHeaderItem[]>([]);
  const [headerSearch, setHeaderSearch] = useState('');
  const [selectedHeaderId, setSelectedHeaderId] = useState<number | ''>('');

  const [poOptions, setPoOptions] = useState<PoDropdownItem[]>([]);
  const [poSearch, setPoSearch] = useState('');
  const [poNo, setPoNo] = useState('');
  const [purchaseOrderId, setPurchaseOrderId] = useState<number | ''>('');
  const [vendorCode, setVendorCode] = useState('');
  const [vendorName, setVendorName] = useState('');

  const [itemOptions, setItemOptions] = useState<PoItemDropdownItem[]>([]);
  const [itemSearch, setItemSearch] = useState('');
  const [selectedItemId, setSelectedItemId] = useState<number | ''>('');
  const [selectedItemLabel, setSelectedItemLabel] = useState('');
  const [selectedItemIsRefinedSugar, setSelectedItemIsRefinedSugar] = useState(false);

  const [_blEntryNo, setBlEntryNo] = useState('');
  const [postedFlag, setPostedFlag] = useState(false);
  const [processedFlag, setProcessedFlag] = useState(false);
  const [_cashPurchaseId, setCashPurchaseId] = useState<number | null>(null);
  const [linkedCpNo, setLinkedCpNo] = useState('');
  const [blDate, setBlDate] = useState('');
  const [remarks, setRemarks] = useState('');

  const [rows, setRows] = useState<BillOfLadingRow[]>([]);

  const [paymentMethods, setPaymentMethods] = useState<PaymentMethodItem[]>([]);
  const [banks, setBanks] = useState<BankItem[]>([]);
  const [showProcessModal, setShowProcessModal] = useState(false);
  const [processPreview, setProcessPreview] = useState<ProcessPreviewData | null>(null);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState('');
  const [selectedBankId, setSelectedBankId] = useState('');
  const [postingBusy, setPostingBusy] = useState(false);
  const [processingBusy, setProcessingBusy] = useState(false);

  const prominentInput =
    'w-full border-2 border-slate-400 bg-white rounded px-3 py-2.5 text-[15px] text-slate-800 shadow-sm focus:border-blue-600 focus:ring-2 focus:ring-blue-200 outline-none';
  const prominentReadonly =
    'w-full border-2 border-slate-300 bg-slate-100 rounded px-3 py-2.5 text-[15px] text-slate-800 shadow-sm';
  const prominentTextarea =
    'w-full border-2 border-slate-400 bg-white rounded px-3 py-2.5 text-[15px] text-slate-800 shadow-sm focus:border-blue-600 focus:ring-2 focus:ring-blue-200 outline-none';
  
  const [tCifPhp, setTCifPhp] = useState(0);
  const [tDuty, setTDuty] = useState(0);
  const [tBrokerage, setTBrokerage] = useState(0);
  const [tWharfage, setTWharfage] = useState(0);
  const [tArrastre, setTArrastre] = useState(0);
  const [tOtherCharges, setTOtherCharges] = useState(0);
  const [tAdjustment, setTAdjustment] = useState(0);
  const [tLandedCost, setTLandedCost] = useState(0);
  const [tVat, setTVat] = useState(0);
  const [tOtherTaxes, setTOtherTaxes] = useState(0);
  const [tBoc, setTBoc] = useState(0);

  const fieldSize = 'h-8 px-3 py-2.5 text-base leading-6 rounded border border-gray-300';
  const poDisplay = poNo ? `${poNo} — ${vendorName || ''}` : '';
  const itemDisplay = selectedItemLabel || '';

  const requireCompany = (): boolean => {
    if (!companyId) {
      toast.error('Missing company id; please sign in again.');
      return false;
    }
    return true;
  };

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

  const recomputeTotals = useCallback((data: BillOfLadingRow[]) => {
    let cifPhp = 0;
    let duty = 0;
    let brokerage = 0;
    let wharfage = 0;
    let arrastre = 0;
    let otherCharges = 0;
    let adjustment = 0;
    let landedCost = 0;
    let vat = 0;
    let otherTaxes = 0;
    let boc = 0;

    data.forEach((r) => {
      cifPhp += Number(r.cif_php || 0);
      duty += Number(r.duty || 0);
      brokerage += Number(r.brokerage || 0);
      wharfage += Number(r.wharfage || 0);
      arrastre += Number(r.arrastre || 0);
      otherCharges += Number(r.other_charges || 0);
      adjustment += Number(r.adjustment || 0);
      landedCost += Number(r.landed_cost || 0);
      vat += Number(r.vat || 0);
      otherTaxes += Number(r.other_taxes || 0);
      boc += Number(r.boc_total || 0);
    });

    setTCifPhp(cifPhp);
    setTDuty(duty);
    setTBrokerage(brokerage);
    setTWharfage(wharfage);
    setTArrastre(arrastre);
    setTOtherCharges(otherCharges);
    setTAdjustment(adjustment);
    setTLandedCost(landedCost);
    setTVat(vat);
    setTOtherTaxes(otherTaxes);
    setTBoc(boc);
  }, []);

  useEffect(() => {
    recomputeTotals(rows);
  }, [rows, recomputeTotals]);

  const makeBlankRow = useCallback(
    (nextLineNo?: number): BillOfLadingRow =>
      computeRow({
        line_no: nextLineNo,
        item_no: nextLineNo,
        bl_no: '',
        bags: 0,
        mt: 0,
        cif_price: 0,
        cif_usd: 0,
        fx_rate: 0,
        cif_php: 0,
        sad_no: '',
        ssdt_no: '',
        fan_no: '',
        registration_date: '',
        assessment_date: '',
        pay_date: '',
        si_no: '',
        dutiable_value: 0,
        duty: 0,
        brokerage: 0,
        wharfage: 0,
        arrastre: 0,
        other_charges: 0,
        adjustment: 0,
        landed_cost: 0,
        vat: 0,
        other_taxes: 0,
        boc_total: 0,
        remarks: '',
        purchase_order_line_id: selectedItemId ? Number(selectedItemId) : null,
        consumed_qty_mt: 0,
        consumed_bags: 0,
        persisted: false,
        isDirty: false,
        isCollapsed: false,
        savedAtLabel: '',
      }),
    [selectedItemId]
  );

  const loadHeaderList = useCallback(async () => {
    if (!requireCompany()) return;
    try {
      const resp = await napi.get('/bill-of-lading/list', {
        params: { company_id: companyId, q: headerSearch },
      });

      const arr = Array.isArray(resp.data)
        ? resp.data
        : Array.isArray(resp.data?.data)
        ? resp.data.data
        : [];

      setHeaderOptions(arr);
    } catch (e) {
      console.error(e);
      setHeaderOptions([]);
    }
  }, [companyId, headerSearch]);

  const loadPoList = useCallback(async () => {
    if (!requireCompany()) return;
    try {
      const resp = await napi.get('/bill-of-lading/po-list', {
        params: { company_id: companyId, q: poSearch },
      });

      const arr = Array.isArray(resp.data)
        ? resp.data
        : Array.isArray(resp.data?.data)
        ? resp.data.data
        : [];

      setPoOptions(arr);
    } catch (e) {
      console.error(e);
      setPoOptions([]);
    }
  }, [companyId, poSearch]);

  const loadPoItems = useCallback(
    async (poId?: number, poNumber?: string, preferredItemId?: number | null) => {
      if (!requireCompany()) return;

      const effectivePoId = Number(poId || purchaseOrderId || 0);
      const effectivePoNo = String(poNumber || poNo || '').trim();

      if (!effectivePoId && !effectivePoNo) {
        setItemOptions([]);
        setSelectedItemId('');
        setSelectedItemLabel('');
        setSelectedItemIsRefinedSugar(false);
        return;
      }

      try {
        const resp = await napi.get('/bill-of-lading/po-items', {
          params: {
            company_id: companyId,
            purchase_order_id: effectivePoId || undefined,
            po_no: effectivePoNo || undefined,
          },
        });

        const arr: PoItemDropdownItem[] = Array.isArray(resp.data)
          ? resp.data
          : Array.isArray(resp.data?.data)
          ? resp.data.data
          : [];

        setItemOptions(arr);

        if (!arr.length) {
          setSelectedItemId('');
          setSelectedItemLabel('');
          setSelectedItemIsRefinedSugar(false);
          return;
        }

        let picked: PoItemDropdownItem | undefined;

        if (preferredItemId) {
          picked = arr.find((x) => Number(x.id) === Number(preferredItemId));
        }

        if (!picked && selectedItemId) {
          picked = arr.find((x) => Number(x.id) === Number(selectedItemId));
        }

        if (!picked) {
          picked = arr.find((x) => isRefinedSugarItem(x.particulars || x.item_label)) || arr[0];
        }

        if (picked) {
          setSelectedItemId(picked.id);
          setSelectedItemLabel(picked.item_label || picked.particulars || '');
          setSelectedItemIsRefinedSugar(isRefinedSugarItem(picked.particulars || picked.item_label));
        } else {
          setSelectedItemId('');
          setSelectedItemLabel('');
          setSelectedItemIsRefinedSugar(false);
        }
      } catch (e) {
        console.error(e);
        setItemOptions([]);
        setSelectedItemId('');
        setSelectedItemLabel('');
        setSelectedItemIsRefinedSugar(false);
      }
    },
    [companyId, purchaseOrderId, poNo, selectedItemId]
  );

  useEffect(() => {
    loadHeaderList();
  }, [loadHeaderList]);

  useEffect(() => {
    loadPoList();
  }, [loadPoList]);

  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/bill-of-lading/payment-methods', {
        params: { company_id: companyId },
      })
      .then((resp) => {
        const arr = Array.isArray(resp.data)
          ? resp.data
          : Array.isArray(resp.data?.data)
          ? resp.data.data
          : [];
        setPaymentMethods(arr);
      })
      .catch(() => setPaymentMethods([]));

    napi
      .get('/bill-of-lading/banks', {
        params: { company_id: companyId },
      })
      .then((resp) => {
        const arr = Array.isArray(resp.data)
          ? resp.data
          : Array.isArray(resp.data?.data)
          ? resp.data.data
          : [];
        setBanks(arr);
      })
      .catch(() => setBanks([]));
  }, [companyId]);




  const reloadDetailsFromServer = useCallback(
    async (headerId?: number) => {
      const id = headerId ?? (selectedHeaderId ? Number(selectedHeaderId) : 0);
      if (!id) return;

      const { data: details } = await napi.get('/bill-of-lading/details', {
        params: { company_id: companyId, id },
      });

      const loaded: BillOfLadingRow[] = Array.isArray(details)
        ? details.map((d: any, idx: number) =>
            computeRow({
              ...d,
              line_no: idx + 1,
              item_no: idx + 1,
              persisted: true,
              isDirty: false,
              isCollapsed: true,
              savedAtLabel: 'Saved',
            })
          )
        : [];

      const existingSourceLineId = loaded.find((r) => Number(r.purchase_order_line_id || 0) > 0)
        ?.purchase_order_line_id;

      if (purchaseOrderId || poNo) {
        await loadPoItems(
          Number(purchaseOrderId || 0),
          poNo,
          Number(existingSourceLineId || 0) || undefined
        );
      }

      setRows(loaded.length ? loaded : []);
    },
    [companyId, loadPoItems, poNo, purchaseOrderId, selectedHeaderId]
  );

  const onSelectHeader = async (value: string | number) => {
    const id = Number(value || 0);
    if (!id) return;

    try {
      setSelectedHeaderId(id);

      const { data } = await napi.get('/bill-of-lading/entry', {
        params: { company_id: companyId, id },
      });

      const nextPurchaseOrderId = Number(data?.purchase_order_id ?? 0) || '';
      const nextPoNo = data?.po_no || '';
      const nextVendorCode = data?.vendor_code || '';
      const nextVendorName = data?.vendor_name || '';

      setBlEntryNo(data?.bl_entry_no || '');
      setPostedFlag(!!data?.posted_flag);
      setProcessedFlag(!!data?.processed_flag);
      setCashPurchaseId(data?.cash_purchase_id ? Number(data.cash_purchase_id) : null);
      setLinkedCpNo(data?.cp_no || '');


      setPurchaseOrderId(nextPurchaseOrderId);
      setPoNo(nextPoNo);
      setVendorCode(nextVendorCode);
      setVendorName(nextVendorName);
      setBlDate(data?.bl_date ? toISO(data.bl_date) : '');
      setRemarks(data?.remarks || '');

      await loadPoItems(Number(nextPurchaseOrderId || 0), nextPoNo);
      await reloadDetailsFromServer(id);
    } catch (e) {
      console.error(e);
      toast.error('Failed to load Bill of Lading entry.');
    }
  };

  const onSelectPo = async (value: string | number) => {
    const picked = String(value || '').trim();

    const po = poOptions.find(
      (x) => String(x.po_no).trim() === picked || String(x.id).trim() === picked
    );

    if (!po) {
      setPurchaseOrderId('');
      setPoNo(picked);
      setVendorCode('');
      setVendorName('');
      setItemOptions([]);
      setSelectedItemId('');
      setSelectedItemLabel('');
      setSelectedItemIsRefinedSugar(false);
      setRows([]);
      return;
    }

    setPurchaseOrderId(Number(po.id));
    setPoNo(po.po_no || '');
    setVendorCode(po.vendor_code || '');
    setVendorName(po.vendor_name || '');

    setItemOptions([]);
    setSelectedItemId('');
    setSelectedItemLabel('');
    setSelectedItemIsRefinedSugar(false);
    setRows([]);

    await loadPoItems(Number(po.id), po.po_no);
  };

  const onSelectItem = async (value: string | number) => {
    const id = Number(value || 0);
    const item = itemOptions.find((x) => Number(x.id) === id);

    if (!item) {
      setSelectedItemId('');
      setSelectedItemLabel('');
      setSelectedItemIsRefinedSugar(false);
      setRows([]);
      return;
    }

    const isRefined = isRefinedSugarItem(item.particulars || item.item_label);

    setSelectedItemId(item.id);
    setSelectedItemLabel(item.item_label || item.particulars || '');
    setSelectedItemIsRefinedSugar(isRefined);

    if (!isRefined) {
      setRows([]);
      toast.info('Bill of Lading sub form appears only for Refined Sugar.');
      return;
    }

    if (!selectedHeaderId) {
      setRows([makeBlankRow(1)]);
      return;
    }

    await reloadDetailsFromServer(Number(selectedHeaderId));
  };

  const handleRowChange = (index: number, field: keyof BillOfLadingRow, value: any) => {
    setRows((prev) =>
      prev.map((row, idx) => {
        if (idx !== index) return row;

        const next = computeRow({
          ...row,
          [field]: value,
          line_no: idx + 1,
          item_no: idx + 1,
          purchase_order_line_id: selectedItemId ? Number(selectedItemId) : null,
          isDirty: true,
          isCollapsed: false,
        });

        return next;
      })
    );
  };

  const handleAutoSave = async (rowData: BillOfLadingRow, rowIndex: number) => {
    const headerId = Number(selectedHeaderId || 0);
    if (!headerId) return;
    if (!selectedItemId) return;

    try {
      const payload = {
        company_id: companyId,
        user_id: userId,
        bill_of_lading_id: headerId,
        row_index: rowIndex,
        row: {
          ...computeRow(rowData),
          purchase_order_line_id: Number(selectedItemId),
        },
      };

      const { data } = await napi.post('/bill-of-lading/batch-insert', payload);
      const server = data || {};
      const savedTime = nowTimeLabel();

      setRows((prev) => {
        const updated = prev.map((row, idx) => {
          if (idx !== rowIndex) return row;
          return computeRow({
            ...row,
            ...server,
            id: server?.id || row.id,
            purchase_order_line_id: Number(selectedItemId),
            persisted: true,
            isDirty: false,
            isCollapsed: true,
            savedAtLabel: `Saved ${savedTime}`,
          });
        });

        const nextIndex = rowIndex + 1;
        if (updated[nextIndex]) {
          updated[nextIndex] = {
            ...updated[nextIndex],
            isCollapsed: false,
          };
        }

        return updated;
      });

      toast.success(`Shipment ${rowIndex + 1} saved.`);
    } catch (e) {
      console.error(e);
      toast.error(`Save failed for shipment ${rowIndex + 1}.`);
    }
  };

  const onSaveNew = async () => {
    const ok = requireFields(
      ['PO #', poNo],
      ['Vendor Name', vendorName],
      ['Item', selectedItemLabel]
    );
    if (!ok) return;
    if (!requireCompany()) return;

    if (!selectedItemIsRefinedSugar) {
      toast.error('Please select an item containing Refined Sugar.');
      return;
    }

    if (selectedHeaderId) {
      toast.info('Bill of Lading already created.');
      return;
    }

    try {
      const payload = {
        company_id: companyId,
        user_id: userId,
        purchase_order_id: purchaseOrderId || null,
        po_no: poNo,
        vendor_code: vendorCode,
        vendor_name: vendorName,
        bl_date: blDate || null,
        remarks: remarks || null,
      };

      const { data } = await napi.post('/bill-of-lading/create-entry', payload);
      const newId = Number(data?.id || 0);

      if (!newId) throw new Error('Server did not return id');

      setSelectedHeaderId(newId);
      setBlEntryNo(data?.bl_entry_no || '');

      setPostedFlag(false);
      setProcessedFlag(false);
      setCashPurchaseId(null);
      setLinkedCpNo('');

      setHeaderOptions((prev) => [
        {
          id: newId,
          bl_entry_no: data?.bl_entry_no || '',
          po_no: data?.po_no || poNo,
          vendor_name: data?.vendor_name || vendorName,
          bl_date: data?.bl_date || blDate,
          status: 'draft',
          line_count: 0,
        },
        ...prev,
      ]);

      if (data?.reused) {
        toast.info(`Existing ${data?.bl_entry_no || 'Bill of Lading'} loaded.`);
        await reloadDetailsFromServer(newId);
      } else {
        if (!rows.length) {
          setRows([makeBlankRow(1)]);
        }
        toast.success(`Created ${data?.bl_entry_no || 'Bill of Lading'}`);
      }
    } catch (e) {
      console.error(e);
      toast.error('Failed to create Bill of Lading entry.');
    }
  };

  const saveHeaderChanges = async () => {
    const id = Number(selectedHeaderId || 0);
    if (!id) return;

    try {
      await napi.post('/bill-of-lading/update-main', {
        company_id: companyId,
        id,
        bl_date: blDate || null,
        remarks: remarks || null,
      });
      toast.success('Header updated');
      loadHeaderList();
    } catch (e) {
      console.error(e);
      toast.error('Failed to update header.');
    }
  };

  const addShipmentRow = () => {
    if (!selectedItemIsRefinedSugar) {
      toast.error('Please select a Refined Sugar item first.');
      return;
    }

    setRows((prev) => [
      ...prev.map((r) => ({ ...r, isCollapsed: true })),
      makeBlankRow(prev.length + 1),
    ]);
  };

  const removeShipmentRow = (index: number) => {
    setRows((prev) =>
      prev
        .filter((_, idx) => idx !== index)
        .map((row, idx) =>
          computeRow({
            ...row,
            line_no: idx + 1,
            item_no: idx + 1,
            isCollapsed: idx === 0 ? false : row.isCollapsed,
          })
        )
    );
  };


  const toggleShipmentCollapse = (index: number) => {
    setRows((prev) =>
      prev.map((row, idx) =>
        idx === index ? { ...row, isCollapsed: !row.isCollapsed } : row
      )
    );
  };

  const expandAllShipments = () => {
    setRows((prev) => prev.map((row) => ({ ...row, isCollapsed: false })));
  };

  const collapseAllShipments = () => {
    setRows((prev) => prev.map((row) => ({ ...row, isCollapsed: true })));
  };

  const resetForm = () => {
    setSelectedHeaderId('');
    setBlEntryNo('');
    setPurchaseOrderId('');
    setPoNo('');
    setVendorCode('');
    setVendorName('');
    setItemOptions([]);
    setItemSearch('');
    setSelectedItemId('');
    setSelectedItemLabel('');
    setSelectedItemIsRefinedSugar(false);
    setBlDate('');
    setRemarks('');
    setRows([]);
    setPostedFlag(false);
    setProcessedFlag(false);
    setCashPurchaseId(null);
    setLinkedCpNo('');
    setShowProcessModal(false);
    setProcessPreview(null);
    setSelectedPaymentMethod('');
    setSelectedBankId('');
    toast.success('Ready for new Bill of Lading entry');
  };

  const handlePostEntry = async () => {
    const id = Number(selectedHeaderId || 0);
    if (!id) {
      toast.error('Please save Bill of Lading first.');
      return;
    }
    if (processedFlag) {
      toast.error('Processed Bill of Lading cannot be posted again.');
      return;
    }

    try {
      setPostingBusy(true);

      await napi.post('/bill-of-lading/post-entry', {
        company_id: companyId,
        id,
        user_id: userId,
      });

      setPostedFlag(true);
      toast.success('Bill of Lading posted.');
      await loadHeaderList();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to post Bill of Lading.');
    } finally {
      setPostingBusy(false);
    }
  };

  const openProcessModal = async () => {
    const id = Number(selectedHeaderId || 0);
    if (!id) {
      toast.error('Please save Bill of Lading first.');
      return;
    }
    if (!postedFlag) {
      toast.error('Please post the Bill of Lading first.');
      return;
    }
    if (processedFlag) {
      toast.error('This Bill of Lading is already processed.');
      return;
    }

    try {
      setProcessingBusy(true);

      const { data } = await napi.get('/bill-of-lading/process-preview', {
        params: {
          company_id: companyId,
          id,
        },
      });

      setProcessPreview(data || null);
      setSelectedPaymentMethod('');
      setSelectedBankId('');
      setShowProcessModal(true);
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to load process preview.');
    } finally {
      setProcessingBusy(false);
    }
  };

  const confirmProcessEntry = async () => {
    const id = Number(selectedHeaderId || 0);
    if (!id) {
      toast.error('Missing Bill of Lading id.');
      return;
    }
    if (!selectedPaymentMethod) {
      toast.error('Please select payment method.');
      return;
    }
    if (!selectedBankId) {
      toast.error('Please select bank.');
      return;
    }

    try {
      setProcessingBusy(true);

      const { data } = await napi.post('/bill-of-lading/process-entry', {
        company_id: companyId,
        id,
        user_id: userId,
        payment_method: selectedPaymentMethod,
        bank_id: selectedBankId,
      });

      setProcessedFlag(true);
      setCashPurchaseId(data?.cash_purchase_id ? Number(data.cash_purchase_id) : null);
      setLinkedCpNo(data?.cp_no || '');
      setShowProcessModal(false);
      toast.success(`Processed to Purchase Journal ${data?.cp_no || ''}`);
      await loadHeaderList();
    } catch (e: any) {
      console.error(e);
      toast.error(e?.response?.data?.message || 'Failed to process Bill of Lading.');
    } finally {
      setProcessingBusy(false);
    }
  };


  const headerDropdownItems = useMemo(() => {
    return headerOptions.map((r) => ({
      code: String(r.id),
      label: r.bl_entry_no,
      description: r.vendor_name,
      po_no: r.po_no,
      bl_date: r.bl_date || '',
      line_count: r.line_count || 0,
      status: r.status || '',
    }));
  }, [headerOptions]);

  const poDropdownItems = useMemo(() => {
    return poOptions.map((p) => ({
      code: p.po_no,
      po_no: p.po_no,
      vendor_code: p.vendor_code || '',
      vendor_name: p.vendor_name || '',
      description: p.vendor_name || '',
    }));
  }, [poOptions]);

  const itemDropdownItems = useMemo(() => {
    return itemOptions.map((p) => ({
      code: String(p.id),
      item_label: p.item_label || p.particulars || '',
      particulars: p.particulars || '',
      mill: p.mill || '',
      quantity: Number(p.quantity || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }),
      description: p.particulars || '',
    }));
  }, [itemOptions]);

  const showSubForm = !!selectedItemId && selectedItemIsRefinedSugar;

  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={2500} />

      <div className="bg-yellow-50 shadow rounded-lg p-4 border border-yellow-300 space-y-4">
        <h2 className="text-lg font-bold text-slate-700">BILL OF LADING ENTRY</h2>

        <div className="grid grid-cols-12 gap-3 items-end">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">BL Entry #</label>
            <DropdownWithHeadersDynamic
              label=""
              value={selectedHeaderId ? String(selectedHeaderId) : ''}
              onChange={onSelectHeader}
              items={headerDropdownItems}
              search={headerSearch}
              onSearchChange={setHeaderSearch}
              headers={['BL Entry #', 'Vendor Name', 'PO #', 'BL Date', 'Lines', 'Status']}
              columnWidths={['140px', '280px', '140px', '110px', '70px', '100px']}
              customKey="bill_of_lading_header"
              inputClassName={`${fieldSize} bg-green-100 text-green-1000`}
            />
          </div>

          <div className="col-span-4">
            <label className="block text-sm text-slate-600">BL Date</label>
            <div className="flex gap-2">
            <input
              type="date"
              value={blDate}
              onChange={(e) => setBlDate(e.target.value)}
              className={prominentInput}
            />
              <button
                type="button"
                disabled={!selectedHeaderId}
                onClick={saveHeaderChanges}
                className={`inline-flex items-center text-xs px-2 rounded border ${
                  selectedHeaderId ? 'bg-white hover:bg-slate-50' : 'bg-gray-200 text-gray-500 cursor-not-allowed'
                }`}
                title="Update Header"
              >
                UH
              </button>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">PO #</label>
            <AttachedDropdown
              value={poNo}
              displayValue={poDisplay}
              readOnlyInput
              onChange={onSelectPo}
              items={poDropdownItems}
              headers={['PO #', 'Vendor Code', 'Vendor Name']}
              columns={['po_no', 'vendor_code', 'vendor_name']}
              search={poSearch}
              onSearchChange={setPoSearch}
              inputClassName="bg-yellow-100"
              dropdownClassName="min-w-[900px]"
              columnWidths={['160px', '140px', '520px']}
            />
          </div>

          <div className="col-span-4">
            <label className="block text-sm text-slate-600">Vendor Code</label>
            <input value={vendorCode} disabled className={prominentReadonly} />
          </div>
        </div>

        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-8">
            <label className="block text-sm text-slate-600">Item #</label>
            <AttachedDropdown
              value={selectedItemId ? String(selectedItemId) : ''}
              displayValue={itemDisplay}
              readOnlyInput
              onChange={onSelectItem}
              items={itemDropdownItems}
              headers={['Item', 'Particulars', 'Mill', 'Qty']}
              columns={['item_label', 'particulars', 'mill', 'quantity']}
              search={itemSearch}
              onSearchChange={setItemSearch}
              inputClassName="bg-yellow-100"
              dropdownClassName="min-w-[900px]"
              columnWidths={['240px', '260px', '220px', '120px']}
            />
          </div>

          <div className="col-span-4">
            <label className="block text-sm text-slate-600">Vendor Name</label>
            <input value={vendorName} disabled className={prominentReadonly} />
          </div>
        </div>

        {!selectedItemIsRefinedSugar && selectedItemId ? (
          <div className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Bill of Lading sub form will appear only when the selected item contains <b>Refined Sugar</b>.
          </div>
        ) : null}

        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-12">
            <div className="flex flex-wrap gap-2">
              <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border ${
                postedFlag ? 'bg-blue-100 text-blue-700 border-blue-300' : 'bg-slate-100 text-slate-700 border-slate-300'
              }`}>
                {postedFlag ? 'POSTED' : 'NOT POSTED'}
              </span>

              <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border ${
                processedFlag ? 'bg-emerald-100 text-emerald-700 border-emerald-300' : 'bg-slate-100 text-slate-700 border-slate-300'
              }`}>
                {processedFlag ? 'PROCESSED' : 'NOT PROCESSED'}
              </span>

              {linkedCpNo ? (
                <span className="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-violet-100 text-violet-700 border-violet-300">
                  CP #: {linkedCpNo}
                </span>
              ) : null}
            </div>
          </div>
        </div>


        <div className="grid grid-cols-12 gap-3">
          <div className="col-span-12">
            <label className="block text-sm text-slate-600">Remarks</label>
            <textarea
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              rows={2}
              className={prominentTextarea}
            />
          </div>
        </div>
      </div>

      <div className="space-y-4">
        <div>
          <h3 className="font-semibold text-gray-800">Details</h3>
        </div>

        {showSubForm && (
          <>
            <div className="flex items-center gap-2 flex-wrap">
              <button
                type="button"
                className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
                onClick={addShipmentRow}
              >
                <PlusIcon className="h-5 w-5" />
                Add Shipment
              </button>

              <button
                type="button"
                className="px-3 py-2 rounded border border-slate-300 bg-white hover:bg-slate-50"
                onClick={expandAllShipments}
              >
                Expand All
              </button>

              <button
                type="button"
                className="px-3 py-2 rounded border border-slate-300 bg-white hover:bg-slate-50"
                onClick={collapseAllShipments}
              >
                Collapse All
              </button>
            </div>

            {rows.map((row, idx) => (
              <div key={idx} className={shipmentCardClass(row)}>
                <div className="flex items-start justify-between gap-3 flex-wrap">
                  <div className="flex items-center gap-3 flex-wrap">
                    <button
                      type="button"
                      onClick={() => toggleShipmentCollapse(idx)}
                      className="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-2 hover:bg-slate-50"
                    >
                      {row.isCollapsed ? (
                        <ChevronRightIcon className="h-4 w-4" />
                      ) : (
                        <ChevronDownIcon className="h-4 w-4" />
                      )}
                      {row.isCollapsed ? 'Expand' : 'Collapse'}
                    </button>

                    <h4 className="text-base font-semibold text-slate-800">
                      Shipment #{idx + 1}
                    </h4>

                    <span className={`text-xs font-semibold px-2.5 py-1 rounded-full ${statusBadgeClass(row)}`}>
                      {statusLabel(row)}
                    </span>

                    {row.savedAtLabel ? (
                      <span className="text-xs text-slate-500">{row.savedAtLabel}</span>
                    ) : null}
                  </div>

                  <div className="flex items-center gap-2 flex-wrap">
                    <button
                      type="button"
                      onClick={() => handleAutoSave(row, idx)}
                      disabled={!selectedHeaderId}
                      className={`px-3 py-2 rounded text-white font-medium ${
                        selectedHeaderId ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-300 cursor-not-allowed'
                      }`}
                    >
                      {rowStatus(row) === 'saved' ? 'Save Changes' : 'Save Line'}
                    </button>

                    <button
                      type="button"
                      onClick={() => removeShipmentRow(idx)}
                      className="inline-flex items-center gap-1 px-3 py-2 rounded bg-red-600 text-white hover:bg-red-700"
                    >
                      <TrashIcon className="h-4 w-4" />
                      Remove
                    </button>
                  </div>
                </div>

                {row.isCollapsed ? (
                  <div className="grid grid-cols-6 gap-3 rounded-md border border-slate-200 bg-white p-3">
                    <div>
                      <div className="text-xs text-slate-500">BL No.</div>
                      <div className="font-medium text-slate-800">{row.bl_no || '—'}</div>
                    </div>
                    <div>
                      <div className="text-xs text-slate-500">BAGS</div>
                      <div className="font-medium text-slate-800">{Number(row.bags || 0).toLocaleString()}</div>
                    </div>
                    <div>
                      <div className="text-xs text-slate-500">MT</div>
                      <div className="font-medium text-slate-800">{Number(row.mt || 0).toFixed(3)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-slate-500">CIF PHP</div>
                      <div className="font-medium text-slate-800">{currency(Number(row.cif_php || 0))}</div>
                    </div>
                    <div>
                      <div className="text-xs text-slate-500">BOC</div>
                      <div className="font-medium text-slate-800">{currency(Number(row.boc_total || 0))}</div>
                    </div>
                    <div>
                      <div className="text-xs text-slate-500">SI No.</div>
                      <div className="font-medium text-slate-800">{row.si_no || '—'}</div>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="grid grid-cols-12 gap-3">
                      <div className="col-span-2">
                        <label className="block text-sm font-medium text-slate-700 mb-1">Item</label>
                        <input
                          value={row.item_no || idx + 1}
                          disabled
                          className={prominentReadonly}
                        />
                      </div>

                      <div className="col-span-4">
                        <label className="block text-sm font-medium text-slate-700 mb-1">BL No.</label>
                        <input
                          value={row.bl_no || ''}
                          onChange={(e) => handleRowChange(idx, 'bl_no', e.target.value)}
                          className={prominentInput}
                        />
                      </div>

                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">BAGS</label>
                        <input
                          type="number"
                          value={row.bags || 0}
                          onChange={(e) => handleRowChange(idx, 'bags', e.target.value)}
                          className={prominentInput}
                        />
                      </div>

                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">MT</label>
                        <input
                          value={Number(row.mt || 0).toFixed(3)}
                          disabled
                          className={prominentReadonly}
                        />
                      </div>
                    </div>

                    <div className="grid grid-cols-12 gap-3">
                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">CIF / Price</label>
                        <input
                          type="number"
                          step="0.000001"
                          value={row.cif_price || 0}
                          onChange={(e) => handleRowChange(idx, 'cif_price', e.target.value)}
                          className={prominentInput}
                        />
                      </div>

                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">FX</label>
                        <input
                          type="number"
                          step="0.000001"
                          value={row.fx_rate || 0}
                          onChange={(e) => handleRowChange(idx, 'fx_rate', e.target.value)}
                          className={prominentInput}
                        />
                      </div>

                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">CIF USD</label>
                        <input
                          value={Number(row.cif_usd || 0).toFixed(2)}
                          disabled
                          className={prominentReadonly}
                        />
                      </div>

                      <div className="col-span-3">
                        <label className="block text-sm font-medium text-slate-700 mb-1">CIF PHP</label>
                        <input
                          value={Number(row.cif_php || 0).toFixed(2)}
                          disabled
                          className={prominentReadonly}
                        />
                      </div>
                    </div>

                    <div className="border-t pt-4">
                      <h5 className="font-semibold text-slate-700 mb-3">Customs References</h5>

                      <div className="grid grid-cols-12 gap-3">
                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">SAD</label>
                          <input
                            value={row.sad_no || ''}
                            onChange={(e) => handleRowChange(idx, 'sad_no', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">SSDT</label>
                          <input
                            value={row.ssdt_no || ''}
                            onChange={(e) => handleRowChange(idx, 'ssdt_no', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">FAN</label>
                          <input
                            value={row.fan_no || ''}
                            onChange={(e) => handleRowChange(idx, 'fan_no', e.target.value)}
                            className={prominentInput}
                          />
                        </div>
                      </div>

                      <div className="grid grid-cols-12 gap-3 mt-3">
                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Registration Date</label>
                          <input
                            type="date"
                            value={row.registration_date || ''}
                            onChange={(e) => handleRowChange(idx, 'registration_date', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Assessment Date</label>
                          <input
                            type="date"
                            value={row.assessment_date || ''}
                            onChange={(e) => handleRowChange(idx, 'assessment_date', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Pay Date</label>
                          <input
                            type="date"
                            value={row.pay_date || ''}
                            onChange={(e) => handleRowChange(idx, 'pay_date', e.target.value)}
                            className={prominentInput}
                          />
                        </div>
                      </div>

                      <div className="grid grid-cols-12 gap-3 mt-3">
                        <div className="col-span-4">
                          <label className="block text-sm font-medium text-slate-700 mb-1">SI No.</label>
                          <input
                            value={row.si_no || ''}
                            onChange={(e) => handleRowChange(idx, 'si_no', e.target.value)}
                            className={prominentInput}
                          />
                        </div>
                      </div>
                    </div>

                    <div className="border-t pt-4">
                      <h5 className="font-semibold text-slate-700 mb-3">Charges</h5>

                      <div className="grid grid-cols-12 gap-3">
                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Dutiable Value</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.dutiable_value || 0}
                            onChange={(e) => handleRowChange(idx, 'dutiable_value', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Duty</label>
                          <input
                            value={Number(row.duty || 0).toFixed(2)}
                            disabled
                            className={prominentReadonly}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Brokerage</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.brokerage || 0}
                            onChange={(e) => handleRowChange(idx, 'brokerage', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Wharfage</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.wharfage || 0}
                            onChange={(e) => handleRowChange(idx, 'wharfage', e.target.value)}
                            className={prominentInput}
                          />
                        </div>
                      </div>

                      <div className="grid grid-cols-12 gap-3 mt-3">
                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Arrastre</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.arrastre || 0}
                            onChange={(e) => handleRowChange(idx, 'arrastre', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Others</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.other_charges || 0}
                            onChange={(e) => handleRowChange(idx, 'other_charges', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Adjustment</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.adjustment || 0}
                            onChange={(e) => handleRowChange(idx, 'adjustment', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Landed Cost</label>
                          <input
                            value={Number(row.landed_cost || 0).toFixed(2)}
                            disabled
                            className={prominentReadonly}
                          />
                        </div>
                      </div>

                      <div className="grid grid-cols-12 gap-3 mt-3">
                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">VAT</label>
                          <input
                            value={Number(row.vat || 0).toFixed(2)}
                            disabled
                            className={prominentReadonly}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">Others</label>
                          <input
                            type="number"
                            step="0.01"
                            value={row.other_taxes || 0}
                            onChange={(e) => handleRowChange(idx, 'other_taxes', e.target.value)}
                            className={prominentInput}
                          />
                        </div>

                        <div className="col-span-3">
                          <label className="block text-sm font-medium text-slate-700 mb-1">BOC</label>
                          <input
                            value={Number(row.boc_total || 0).toFixed(2)}
                            disabled
                            className={prominentReadonly}
                          />
                        </div>
                      </div>
                    </div>

                    <div className="grid grid-cols-12 gap-3">
                      <div className="col-span-12">
                        <label className="block text-sm font-medium text-slate-700 mb-1">Line Remarks</label>
                        <textarea
                          rows={2}
                          value={row.remarks || ''}
                          onChange={(e) => handleRowChange(idx, 'remarks', e.target.value)}
                          className={prominentTextarea}
                        />
                      </div>
                    </div>
                  </>
                )}
              </div>
            ))}

            <div className="mt-4 border border-slate-300 rounded-lg bg-white shadow-sm p-4 space-y-4">
              <div className="grid grid-cols-4 gap-4 text-slate-800">
                <div>
                  CIF PHP <span className="font-semibold">{currency(tCifPhp)}</span>
                </div>
                <div>
                  Duty <span className="font-semibold">{currency(tDuty)}</span>
                </div>
                <div>
                  Brokerage <span className="font-semibold">{currency(tBrokerage)}</span>
                </div>
                <div>
                  Wharfage <span className="font-semibold">{currency(tWharfage)}</span>
                </div>
                <div>
                  Arrastre <span className="font-semibold">{currency(tArrastre)}</span>
                </div>
                <div>
                  Others <span className="font-semibold">{currency(tOtherCharges)}</span>
                </div>
                <div>
                  Adjustment <span className="font-semibold">{currency(tAdjustment)}</span>
                </div>
                <div>
                  Landed Cost <span className="font-semibold">{currency(tLandedCost)}</span>
                </div>
                <div>
                  VAT <span className="font-semibold">{currency(tVat)}</span>
                </div>
                <div>
                  Other Taxes <span className="font-semibold">{currency(tOtherTaxes)}</span>
                </div>
                <div>
                  BOC <span className="font-semibold">{currency(tBoc)}</span>
                </div>
              </div>
            </div>
          </>
        )}
      </div>

      <div className="flex items-center gap-2">
        <button
          className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
          onClick={resetForm}
        >
          <PlusIcon className="h-5 w-5" />
          New
        </button>

        <button
          disabled={!!selectedHeaderId}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded font-medium ${
            selectedHeaderId ? 'bg-green-400 cursor-not-allowed opacity-60' : 'bg-green-600 hover:bg-green-700'
          } text-white`}
          onClick={onSaveNew}
        >
          <CheckCircleIcon className="h-5 w-5" />
          Save
        </button>

        <button
          disabled={!selectedHeaderId}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded font-medium ${
            selectedHeaderId ? 'bg-slate-700 hover:bg-slate-800' : 'bg-gray-300 cursor-not-allowed'
          } text-white`}
          onClick={saveHeaderChanges}
        >
          Update Header
        </button>

        <button
          disabled={!selectedHeaderId || postedFlag || processedFlag || postingBusy}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded font-medium ${
            !selectedHeaderId || postedFlag || processedFlag || postingBusy
              ? 'bg-gray-300 cursor-not-allowed'
              : 'bg-blue-600 hover:bg-blue-700'
          } text-white`}
          onClick={handlePostEntry}
        >
          {postingBusy ? 'Posting...' : 'Post'}
        </button>

        <button
          disabled={!selectedHeaderId || !postedFlag || processedFlag || processingBusy}
          className={`inline-flex items-center gap-2 px-4 py-2 rounded font-medium ${
            !selectedHeaderId || !postedFlag || processedFlag || processingBusy
              ? 'bg-gray-300 cursor-not-allowed'
              : 'bg-violet-600 hover:bg-violet-700'
          } text-white`}
          onClick={openProcessModal}
        >
          {processingBusy ? 'Loading...' : 'Process'}
        </button>



      </div>
      {showProcessModal && processPreview ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-5xl rounded-lg bg-white shadow-xl border border-slate-300">
            <div className="border-b px-6 py-4">
              <h3 className="text-lg font-bold text-slate-800">Process to Purchase Journal</h3>
              <p className="text-sm text-slate-600">
                Please review the accounting entries and complete the required fields.
              </p>
            </div>

            <div className="p-6 space-y-5 max-h-[80vh] overflow-auto">
              <div className="grid grid-cols-12 gap-3">
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">CP No</label>
                  <input value={processPreview.cp_no || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Vendor Code</label>
                  <input value={processPreview.vend_id || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Purchase Date</label>
                  <input value={processPreview.purchase_date || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">RR No</label>
                  <input value={processPreview.rr_no || ''} disabled className={prominentReadonly} />
                </div>
              </div>

              <div className="grid grid-cols-12 gap-3">
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Booking No</label>
                  <input value={processPreview.booking_no || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Crop Year</label>
                  <input value={processPreview.crop_year || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Sugar Type</label>
                  <input value={processPreview.sugar_type || ''} disabled className={prominentReadonly} />
                </div>
                <div className="col-span-3">
                  <label className="block text-sm text-slate-600">Mill ID</label>
                  <input value={processPreview.mill_id || ''} disabled className={prominentReadonly} />
                </div>
              </div>

              <div className="grid grid-cols-12 gap-3">
                <div className="col-span-6">
                  <label className="block text-sm text-slate-600">Explanation</label>
                  <textarea
                    value={processPreview.explanation || ''}
                    disabled
                    rows={2}
                    className={prominentTextarea + ' bg-slate-100'}
                  />
                </div>
                <div className="col-span-6">
                  <label className="block text-sm text-slate-600">Amount in Words</label>
                  <textarea
                    value={processPreview.amount_in_words || ''}
                    disabled
                    rows={2}
                    className={prominentTextarea + ' bg-slate-100'}
                  />
                </div>
              </div>

              <div className="grid grid-cols-12 gap-3">
                <div className="col-span-6">
                  <label className="block text-sm text-slate-600 mb-1">Payment Method</label>
                  <select
                    value={selectedPaymentMethod}
                    onChange={(e) => setSelectedPaymentMethod(e.target.value)}
                    className={prominentInput}
                  >
                    <option value="">Select payment method</option>
                    {paymentMethods.map((pm) => (
                      <option key={pm.id} value={pm.pay_method_id}>
                        {pm.pay_method_id} - {pm.pay_method}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="col-span-6">
                  <label className="block text-sm text-slate-600 mb-1">Bank</label>
                  <select
                    value={selectedBankId}
                    onChange={(e) => setSelectedBankId(e.target.value)}
                    className={prominentInput}
                  >
                    <option value="">Select bank</option>
                    {banks.map((b) => (
                      <option key={b.id} value={b.bank_id}>
                        {b.bank_id} - {b.bank_name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="overflow-x-auto border rounded-lg">
                <table className="min-w-full text-sm">
                  <thead className="bg-slate-100">
                    <tr>
                      <th className="text-left px-4 py-3 border-b">Account Code</th>
                      <th className="text-left px-4 py-3 border-b">Account Name</th>
                      <th className="text-right px-4 py-3 border-b">Debit</th>
                      <th className="text-right px-4 py-3 border-b">Credit</th>
                    </tr>
                  </thead>
                  <tbody>
                    {processPreview.lines.map((line, idx) => (
                      <tr key={idx} className="odd:bg-white even:bg-slate-50">
                        <td className="px-4 py-3 border-b">{line.acct_code}</td>
                        <td className="px-4 py-3 border-b">{line.acct_desc}</td>
                        <td className="px-4 py-3 border-b text-right">{currency(Number(line.debit || 0))}</td>
                        <td className="px-4 py-3 border-b text-right">{currency(Number(line.credit || 0))}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-slate-100 font-semibold">
                    <tr>
                      <td className="px-4 py-3" colSpan={2}>Totals</td>
                      <td className="px-4 py-3 text-right">{currency(Number(processPreview.sum_debit || 0))}</td>
                      <td className="px-4 py-3 text-right">{currency(Number(processPreview.sum_credit || 0))}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>

            <div className="border-t px-6 py-4 flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowProcessModal(false)}
                className="px-4 py-2 rounded border border-slate-300 bg-white hover:bg-slate-50"
              >
                Cancel
              </button>

              <button
                type="button"
                onClick={confirmProcessEntry}
                disabled={processingBusy}
                className={`px-4 py-2 rounded text-white font-medium ${
                  processingBusy ? 'bg-gray-300 cursor-not-allowed' : 'bg-violet-600 hover:bg-violet-700'
                }`}
              >
                {processingBusy ? 'Processing...' : 'Confirm Process'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}