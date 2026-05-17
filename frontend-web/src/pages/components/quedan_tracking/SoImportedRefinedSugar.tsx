import { useEffect, useMemo, useRef, useState } from 'react';

import { ToastContainer, toast } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';
import AttachedDropdown from '../../components/AttachedDropdown';
import DropdownWithHeadersDynamic from '../DropdownWithHeadersDynamic';

type SiItem = {
  id: number;
  si_no: string;
  si_date: string;
  po_no?: string;
  buyer_name?: string;
  quantity?: number;
};

type PoItem = {
  id: number;
  po_no: string;
  po_date: string;
  vendor_code?: string;
  vendor_name?: string;
  sugar_type?: string;
  crop_year?: string;
};

type PoDetailItem = {
  id: number;
  row: number;
  pbn_entry_id: number;
  pbn_number: string;
  item_label: string;
  particulars: string;
  mill_code?: string;
  mill?: string;
  quantity?: number;
  price?: number;
};

type BlItem = {
  id: number;
  po_no: string;
  bl_no: string;
  bl_date?: string;
  vendor_code?: string;
  vendor_name?: string;
  bags?: number;
};

type CustomerItem = {
  id: number;
  cust_id: string;
  cust_name: string;
};

type ProcessEntry = {
  line_no: number;
  acct_code: string;
  acct_desc: string;
  debit: number;
  credit: number;
  remarks?: string;
};

type BlLineRow = {
  id: number;
  bill_of_lading_id: number;
  bl_no: string;
  line_no?: number;
  item_no?: number;
  bags: number;
  mt?: number;
  selected_flag: boolean;
  override_flag: boolean;
  override_bags: number | string | null;
  selected_bags: number;
};

export default function SoImportedRefinedSugar() {
  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;
  const companyId = Number(user?.company_id || localStorage.getItem('company_id') || 0);

  const [mainId, setMainId] = useState<number | null>(null);
  const [postedFlag, setPostedFlag] = useState(false);

  const [siNo, setSiNo] = useState('');
  const [siDate, setSiDate] = useState('');
  const [siOptions, setSiOptions] = useState<SiItem[]>([]);
  const [siSearch, setSiSearch] = useState('');

  const [poOptions, setPoOptions] = useState<PoItem[]>([]);
  const [poSearch, setPoSearch] = useState('');
  const [selectedPoId, setSelectedPoId] = useState<number | ''>('');
  const [selectedPoNo, setSelectedPoNo] = useState('');
  const [poDate, setPoDate] = useState('');
  const [vendorCode, setVendorCode] = useState('');

  const [itemOptions, setItemOptions] = useState<PoDetailItem[]>([]);
  const [itemSearch, setItemSearch] = useState('');
  const [selectedItemId, setSelectedItemId] = useState<number | ''>('');
  const [selectedItemLabel, setSelectedItemLabel] = useState('');

  const [blNo, setBlNo] = useState('');
  const [blOptions, setBlOptions] = useState<BlItem[]>([]);
  const [blSearch, setBlSearch] = useState('');
  const [selectedBlId, setSelectedBlId] = useState<number | ''>('');

  const [selectedMillId, setSelectedMillId] = useState('');
  const [selectedMillName, setSelectedMillName] = useState('');

  const [sellingPrice, setSellingPrice] = useState('');
  const [quantity, setQuantity] = useState<number>(0);

  const [buyerName, setBuyerName] = useState('');
  const [buyerSearch, setBuyerSearch] = useState('');
  const [buyerOptions, setBuyerOptions] = useState<CustomerItem[]>([]);
  const [selectedBuyerId, setSelectedBuyerId] = useState('');
  const [buyerAddress, setBuyerAddress] = useState('');
  const [tin, setTin] = useState('');

  const [vatFlag, setVatFlag] = useState(false);

  const [showPdf, setShowPdf] = useState(false);
  const [pdfUrl, setPdfUrl] = useState('');
  const pdfBlobUrlRef = useRef<string | null>(null);

  const [showProcessModal, setShowProcessModal] = useState(false);
  const [processEntries, setProcessEntries] = useState<ProcessEntry[]>([]);
  const [processingToSalesJournal, setProcessingToSalesJournal] = useState(false);
  const [alreadyProcessedToSalesJournal, setAlreadyProcessedToSalesJournal] = useState(false);

  const [blLineRows, setBlLineRows] = useState<BlLineRow[]>([]);
  const [loadingBlLines, setLoadingBlLines] = useState(false);

  const siDropdownItems = useMemo(() => {
    return siOptions.map((s) => ({
      code: String(s.id),
      si_no: s.si_no || '',
      si_date: s.si_date || '',
      po_no: s.po_no || '',
      buyer_name: s.buyer_name || '',
      quantity: Number(s.quantity || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }),
      description: s.buyer_name || '',
    }));
  }, [siOptions]);

  const poDisplay = selectedPoNo
    ? `${selectedPoNo}${buyerName ? ` — ${buyerName}` : ''}`
    : '';

  const itemDisplay = selectedItemLabel || '';

  const formatMoney = (value: number | string) => {
    const n = Number(String(value || '0').replace(/,/g, ''));
    return n.toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  };

  const parseMoney = (value: number | string) => {
    return Number(String(value || '0').replace(/,/g, ''));
  };

  const computedTotalSales = Number(quantity || 0) * parseMoney(sellingPrice);
  const computedVat = vatFlag ? computedTotalSales * 0.12 : 0;

  const poDropdownItems = useMemo(() => {
    return poOptions.map((p) => ({
      code: p.po_no,
      po_no: p.po_no,
      vendor_code: p.vendor_code || '',
      vendor_name: p.vendor_name || '',
      description: p.vendor_name || '',
    }));
  }, [poOptions]);

  const buyerDropdownItems = useMemo(() => {
    return buyerOptions.map((c) => ({
      code: c.cust_id,
      cust_id: c.cust_id,
      cust_name: c.cust_name,
      description: c.cust_name,
    }));
  }, [buyerOptions]);

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

  const blDropdownItems = useMemo(() => {
    return blOptions.map((b) => ({
      code: String(b.id),
      bl_no: b.bl_no || '',
      bl_date: b.bl_date || '',
      po_no: b.po_no || '',
      vendor_name: b.vendor_name || '',
      bags: Number(b.bags || 0).toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      }),
      description: b.vendor_name || '',
    }));
  }, [blOptions]);

  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-imported-refined-sugar/dropdowns/si', {
        params: { company_id: companyId, q: siSearch },
      })
      .then((res) => setSiOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setSiOptions([]));
  }, [companyId, siSearch]);

  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-imported-refined-sugar/dropdowns/po', {
        params: { company_id: companyId, q: poSearch },
      })
      .then((res) => setPoOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setPoOptions([]));
  }, [companyId, poSearch]);

  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-imported-refined-sugar/dropdowns/customers', {
        params: { company_id: companyId, q: buyerSearch },
      })
      .then((res) => setBuyerOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setBuyerOptions([]));
  }, [companyId, buyerSearch]);

  useEffect(() => {
    if (!companyId) {
      setBlOptions([]);
      return;
    }

    napi
      .get('/so-imported-refined-sugar/dropdowns/bl', {
        params: {
          company_id: companyId,
          po_no: selectedPoNo || undefined,
          q: blSearch,
        },
      })
      .then((res) => setBlOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setBlOptions([]));
  }, [companyId, selectedPoNo, blSearch]);

  const loadPoItems = async (poId: number | '', poNo: string) => {
    if (!companyId || (!poId && !poNo)) {
      setItemOptions([]);
      return;
    }

    try {
      const res = await napi.get('/so-imported-refined-sugar/dropdowns/po-items', {
        params: {
          company_id: companyId,
          purchase_order_id: poId || undefined,
          po_no: poNo || undefined,
        },
      });

      setItemOptions(Array.isArray(res.data) ? res.data : []);
    } catch {
      setItemOptions([]);
    }
  };

  const onSelectPo = async (value: string | number) => {
    const picked = String(value || '').trim();

    const po = poOptions.find(
      (x) => String(x.po_no).trim() === picked || String(x.id).trim() === picked
    );

    if (!po) {
      setSelectedPoId('');
      setSelectedPoNo(picked);
      setPoDate('');
      setVendorCode('');
      setBuyerName('');
      setItemOptions([]);
      setSelectedItemId('');
      setSelectedItemLabel('');
      setSelectedMillId('');
      setSelectedMillName('');
      setQuantity(0);
      setBlNo('');
      setSelectedBlId('');
      setBlSearch('');
      setBlLineRows([]);
      return;
    }

    setSelectedPoId(Number(po.id));
    setSelectedPoNo(po.po_no || '');
    setPoDate(po.po_date || '');
    setVendorCode(po.vendor_code || '');
    setBuyerName(po.vendor_name || '');

    setItemOptions([]);
    setSelectedItemId('');
    setSelectedItemLabel('');
    setSelectedMillId('');
    setSelectedMillName('');
    setQuantity(0);
    setBlNo('');
    setSelectedBlId('');
    setBlSearch('');
    setBlLineRows([]);

    await loadPoItems(Number(po.id), po.po_no);
  };

  const onSelectItem = (value: string | number) => {
    const id = Number(value || 0);
    const item = itemOptions.find((x) => Number(x.id) === id);

    if (!item) {
      setSelectedItemId('');
      setSelectedItemLabel('');
      setSelectedMillId('');
      setSelectedMillName('');
      setQuantity(0);
      return;
    }

    setSelectedItemId(item.id);
    setSelectedItemLabel(item.item_label || item.particulars || 'REFINED SUGAR');
    setSelectedMillId(item.mill_code || '');
    setSelectedMillName(item.mill || '');

    if (!sellingPrice && item.price !== undefined) {
      setSellingPrice(formatMoney(item.price || 0));
    }
  };

  const onSelectBuyer = (value: string | number) => {
    const picked = String(value || '').trim();

    const buyer = buyerOptions.find(
      (c) => String(c.cust_id).trim() === picked || String(c.id).trim() === picked
    );

    if (!buyer) {
      setSelectedBuyerId('');
      setBuyerName(picked);
      return;
    }

    setSelectedBuyerId(String(buyer.cust_id || ''));
    setBuyerName(String(buyer.cust_name || ''));
  };

  const onSelectBl = async (value: string | number) => {
    const pickedId = Number(value || 0);
    const bl = blOptions.find((b) => Number(b.id) === pickedId);

    if (!bl) {
      setSelectedBlId('');
      setBlNo('');
      setBlLineRows([]);
      setQuantity(0);
      return;
    }

    setSelectedBlId(Number(bl.id));
    setBlNo(String(bl.bl_no || ''));
    setBlLineRows([]);
    setQuantity(0);

    await loadBlLines(mainId || undefined, Number(bl.id), String(bl.bl_no || ''));
  };

  const computeBlQuantity = (rows: BlLineRow[]) => {
    return rows.reduce((sum, row) => {
      if (!row.selected_flag) return sum;

      if (row.override_flag) {
        return sum + Number(row.override_bags || 0);
      }

      return sum + Number(row.bags || 0);
    }, 0);
  };

  const loadBlLines = async (
    targetMainId?: number,
    billOfLadingIdOverride?: number,
    blNoOverride?: string
  ) => {
    const targetBlId = billOfLadingIdOverride || selectedBlId;
    const targetBlNo = blNoOverride || blNo;

    if (!companyId || (!targetBlId && !targetBlNo)) {
      setBlLineRows([]);
      return;
    }

    setLoadingBlLines(true);

    try {
      const res = await napi.get('/so-imported-refined-sugar/bl-lines', {
        params: {
          company_id: companyId,
          bill_of_lading_id: targetBlId || undefined,
          bl_no: targetBlNo || undefined,
          so_imported_refined_sugar_id: targetMainId || mainId || undefined,
        },
      });

      const rows = Array.isArray(res.data) ? res.data : [];
      setBlLineRows(rows);
      setQuantity(computeBlQuantity(rows));
    } catch (e: any) {
      setBlLineRows([]);
      toast.error(e?.response?.data?.message || 'Failed to load Bill of Lading lines.');
    } finally {
      setLoadingBlLines(false);
    }
  };

  const saveBlSelections = async (rows: BlLineRow[]) => {
    if (!mainId || !companyId) {
      toast.info('Save the main entry first.');
      return;
    }

    try {
      const res = await napi.post('/so-imported-refined-sugar/save-bl-lines', {
        so_imported_refined_sugar_id: mainId,
        company_id: companyId,
        items: rows.map((row) => ({
          bill_of_lading_line_id: row.id,
          bill_of_lading_id: row.bill_of_lading_id,
          bl_no: row.bl_no,
          original_bags: Number(row.bags || 0),
          selected_flag: !!row.selected_flag,
          override_flag: !!row.override_flag,
          override_bags: row.override_flag ? Number(row.override_bags || 0) : null,
        })),
      });

      setQuantity(Number(res.data?.quantity || 0));
      toast.success('Bill of Lading selection saved.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save Bill of Lading selection.');
    }
  };

  const updateBlRows = async (nextRows: BlLineRow[]) => {
    const total = computeBlQuantity(nextRows);

    setBlLineRows(nextRows);
    setQuantity(total);

    if (mainId) {
      await saveBlSelections(nextRows);
    }
  };

  const toggleBlSelected = async (index: number, checked: boolean) => {
    const next = [...blLineRows];
    const row = { ...next[index] };

    row.selected_flag = checked;

    if (!checked) {
      row.override_flag = false;
      row.override_bags = null;
      row.selected_bags = 0;
    } else {
      row.selected_bags = row.override_flag
        ? Number(row.override_bags || 0)
        : Number(row.bags || 0);
    }

    next[index] = row;
    await updateBlRows(next);
  };

  const toggleBlOverride = async (index: number, checked: boolean) => {
    const next = [...blLineRows];
    const row = { ...next[index] };

    if (!row.selected_flag && checked) {
      toast.info('Check the BL line first before overriding bags.');
      return;
    }

    row.override_flag = checked;
    row.override_bags = checked ? Number(row.bags || 0) : null;
    row.selected_bags = checked ? Number(row.override_bags || 0) : Number(row.bags || 0);

    next[index] = row;
    await updateBlRows(next);
  };

  const changeBlOverrideBags = async (index: number, rawValue: string) => {
    const next = [...blLineRows];
    const row = { ...next[index] };

    const originalBags = Number(row.bags || 0);
    let value = Number(rawValue || 0);

    if (value < 0) value = 0;

    if (value > originalBags) {
      value = originalBags;
      toast.warning('Override bags cannot exceed original bags.');
    }

    row.override_bags = value;
    row.selected_bags = row.selected_flag ? value : 0;

    next[index] = row;
    await updateBlRows(next);
  };

  const loadSalesInvoice = async (id: string | number) => {
    if (!companyId || !id) return;

    try {
      const res = await napi.get(`/so-imported-refined-sugar/${id}`, {
        params: { company_id: companyId },
      });

      const main = res.data?.main;

      if (!main) {
        toast.error('Sales Invoice not found.');
        return;
      }

      setMainId(Number(main.id));
      setPostedFlag(!!main.posted_flag);
      setSiNo(String(main.si_no || ''));
      setSiDate(main.si_date ? String(main.si_date).slice(0, 10) : '');

      setSelectedPoId(main.po_entry_id ? Number(main.po_entry_id) : '');
      setSelectedPoNo(String(main.po_no || ''));
      setPoDate(main.po_date ? String(main.po_date).slice(0, 10) : '');
      setVendorCode(String(main.vendor_code || ''));

      setSelectedBlId(main.bl_id ? Number(main.bl_id) : '');
      setBlNo(String(main.bl_no || ''));

      setSelectedItemId(main.item_id ? Number(main.item_id) : '');
      setSelectedItemLabel(String(main.item_label || 'REFINED SUGAR'));

      setSelectedMillId(String(main.mill_id || ''));
      setSelectedMillName(String(main.mill_name || ''));

      setSellingPrice(formatMoney(main.selling_price ?? 0));
      setQuantity(Number(main.quantity || 0));

      setBuyerName(String(main.buyer_name || ''));
      setBuyerAddress(String(main.buyer_address || ''));
      setTin(String(main.tin || ''));

      setVatFlag(!!main.vatable_flag);
      setAlreadyProcessedToSalesJournal(!!main.processed_to_sales_journal);

      await loadPoItems(main.po_entry_id ? Number(main.po_entry_id) : '', String(main.po_no || ''));

      await loadBlLines(
        Number(main.id),
        main.bl_id ? Number(main.bl_id) : undefined,
        String(main.bl_no || '')
      );

      toast.success('Sales Invoice loaded.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to load Sales Invoice.');
    }
  };

  const onSelectSI = async (value: string | number) => {
    const id = String(value || '').trim();
    if (!id) return;

    await loadSalesInvoice(id);
  };

  const handleSaveMain = async () => {
    if (!companyId) {
      toast.error('Company ID not found.');
      return;
    }

    try {
      const payload = {
        company_id: companyId,
        si_date: siDate || null,

        po_entry_id: selectedPoId || null,
        po_no: selectedPoNo || null,
        po_date: poDate || null,

        bl_id: selectedBlId || null,
        bl_no: blNo || null,

        mill_id: selectedMillId || null,
        mill_name: selectedMillName || null,

        selling_price: parseMoney(sellingPrice),
        quantity: Number(quantity || 0),

        buyer_name: buyerName || null,
        buyer_address: buyerAddress || null,
        tin: tin || null,

        vatable_flag: vatFlag,
        withholding_tax_flag: false,
        withholding_tax_amount: 0,
        vat_amount: computedVat,
      };

      const res = await napi.post('/so-imported-refined-sugar/save-main', payload);

      const savedId = Number(res.data.id);

      setMainId(savedId);
      setSiNo(String(res.data.si_no || ''));

      toast.success('Sales Invoice main saved.');

      await loadBlLines(savedId);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save main.');
    }
  };

  const handleUpdateMain = async () => {
    if (!mainId) {
      toast.info('Save the main entry first.');
      return;
    }

    try {
      const payload = {
        id: mainId,
        company_id: companyId,
        si_date: siDate || null,

        po_entry_id: selectedPoId || null,
        po_no: selectedPoNo || null,
        po_date: poDate || null,

        bl_id: selectedBlId || null,
        bl_no: blNo || null,

        mill_id: selectedMillId || null,
        mill_name: selectedMillName || null,

        selling_price: parseMoney(sellingPrice),
        quantity: Number(quantity || 0),

        buyer_name: buyerName || null,
        buyer_address: buyerAddress || null,
        tin: tin || null,

        vatable_flag: vatFlag,
        withholding_tax_flag: false,
        withholding_tax_amount: 0,
        vat_amount: computedVat,
      };

      await napi.post('/so-imported-refined-sugar/update-main', payload);

      toast.success('Sales Invoice main updated.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to update main.');
    }
  };

  const openSalesInvoicePdf = async () => {
    const pdfId = mainId ? String(mainId) : 'template';

    if (pdfBlobUrlRef.current) {
      URL.revokeObjectURL(pdfBlobUrlRef.current);
      pdfBlobUrlRef.current = null;
    }

    try {
      const res = await napi.get(`/so-imported-refined-sugar/form-pdf/${pdfId}`, {
        responseType: 'blob',
        params: {
          company_id: companyId,
          _: Date.now(),
        },
      });

      const contentType = String(res.headers?.['content-type'] || '');

      if (!contentType.includes('application/pdf')) {
        toast.error('PDF export failed. Server returned non-PDF response.');
        return;
      }

      const blob = new Blob([res.data], { type: 'application/pdf' });
      const blobUrl = URL.createObjectURL(blob);

      pdfBlobUrlRef.current = blobUrl;
      setPdfUrl(blobUrl);
      setShowPdf(true);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'PDF export failed.');
    }
  };

  const handlePost = async () => {
    if (!mainId) {
      toast.info('Save the Sales Invoice first.');
      return;
    }

    try {
      await napi.post('/so-imported-refined-sugar/post', {
        id: mainId,
        company_id: companyId,
      });

      setPostedFlag(true);
      toast.success('Sales Invoice posted.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Posting failed.');
    }
  };

  const handleProcess = async () => {
    if (!mainId) {
      toast.info('Save the Sales Invoice first.');
      return;
    }

    try {
      const res = await napi.post('/so-imported-refined-sugar/process', {
        id: mainId,
        company_id: companyId,
      });

      const entries = Array.isArray(res.data?.entries) ? res.data.entries : [];

      setProcessEntries(entries);
      setShowProcessModal(true);

      toast.success('Sales Invoice processed.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Processing failed.');
    }
  };

  const handleProcessToSalesJournal = async () => {
    if (!mainId) {
      toast.info('Save/select the Sales Invoice first.');
      return;
    }

    setProcessingToSalesJournal(true);

    try {
      const res = await napi.post('/so-imported-refined-sugar/process-to-sales-journal', {
        id: mainId,
        company_id: companyId,
        user_id: user?.id || null,
      });

      toast.success(`Processed to Sales Journal. CS No: ${res.data?.cs_no || ''}`);
      setAlreadyProcessedToSalesJournal(true);
      setShowProcessModal(false);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to process to Sales Journal.');
    } finally {
      setProcessingToSalesJournal(false);
    }
  };

  const handleNew = () => {
    setMainId(null);
    setPostedFlag(false);
    setSiNo('');
    setSiDate('');

    setSelectedPoId('');
    setSelectedPoNo('');
    setPoDate('');
    setVendorCode('');

    setItemOptions([]);
    setItemSearch('');
    setSelectedItemId('');
    setSelectedItemLabel('');

    setBlNo('');
    setSelectedBlId('');
    setBlSearch('');
    setBlOptions([]);

    setSelectedMillId('');
    setSelectedMillName('');
    setSellingPrice('');
    setQuantity(0);

    setBuyerName('');
    setBuyerSearch('');
    setBuyerOptions([]);
    setSelectedBuyerId('');
    setBuyerAddress('');
    setTin('');

    setVatFlag(false);
    setBlLineRows([]);

    setProcessEntries([]);
    setProcessingToSalesJournal(false);
    setAlreadyProcessedToSalesJournal(false);
  };

  return (
    <div className="so-imported-refined-sugar-page min-h-screen pb-40 space-y-4 p-6 overflow-visible">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-white shadow-md rounded-lg border border-blue-300">
        <div className="px-4 py-2 border-b bg-blue-50">
          <h2 className="text-lg font-bold text-blue-900">
            SALES INVOICE ENTRY - IMPORTED REFINED SUGAR
          </h2>
        </div>

        <div className="p-5 space-y-4">
          {postedFlag && (
            <div className="rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800">
              This Sales Invoice is already posted. Editing and posting are disabled.
            </div>
          )}

          <div className="grid grid-cols-2 gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-start gap-2">
              <label className="font-semibold pt-2">SI #:</label>
              <AttachedDropdown
                value={mainId ? String(mainId) : ''}
                displayValue={siNo}
                readOnlyInput
                onChange={onSelectSI}
                items={siDropdownItems}
                headers={['SI #', 'SI Date', 'PO #', 'Buyer Name', 'Qty']}
                columns={['si_no', 'si_date', 'po_no', 'buyer_name', 'quantity']}
                search={siSearch}
                onSearchChange={setSiSearch}
                inputClassName="bg-yellow-100"
                dropdownClassName="min-w-[900px] z-[9999]"
                columnWidths={['120px', '130px', '120px', '360px', '120px']}
              />
            </div>

            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold">SI Date:</label>
              <input
                type="date"
                value={siDate}
                onChange={(e) => setSiDate(e.target.value)}
                className="border rounded px-2 py-1 bg-yellow-100"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-start gap-2">
              <label className="font-semibold pt-2">PO #:</label>
              <AttachedDropdown
                value={selectedPoNo}
                displayValue={poDisplay}
                readOnlyInput
                onChange={onSelectPo}
                items={poDropdownItems}
                headers={['PO #', 'Vendor Code', 'Vendor Name']}
                columns={['po_no', 'vendor_code', 'vendor_name']}
                search={poSearch}
                onSearchChange={setPoSearch}
                inputClassName="bg-yellow-100"
                dropdownClassName="min-w-[900px] z-[9999]"
                columnWidths={['160px', '140px', '520px']}
              />
            </div>

            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold">Vendor Code:</label>
              <input value={vendorCode} disabled className="border rounded px-2 py-1 bg-slate-100" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-start gap-2">
              <label className="font-semibold pt-2">Item #:</label>
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
                dropdownClassName="min-w-[900px] z-[9999]"
                columnWidths={['240px', '260px', '220px', '120px']}
              />
            </div>

            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold">PO Date:</label>
              <input value={poDate} disabled className="border rounded px-2 py-1 bg-yellow-100" />
            </div>
          </div>

          <div className="grid grid-cols-[1fr_260px] gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-start gap-2">
              <label className="font-semibold text-red-600 pt-2">BL #:</label>

              <DropdownWithHeadersDynamic
                label=""
                value={selectedBlId ? String(selectedBlId) : ''}
                onChange={(value) => onSelectBl(value)}
                items={blDropdownItems}
                search={blSearch}
                onSearchChange={setBlSearch}
                headers={['BL No.', 'BL Date', 'PO #', 'Vendor Name', 'Bags']}
                columnWidths={['180px', '130px', '130px', '360px', '100px']}
                customKey="so_imported_refined_bl"
                inputClassName="h-8 px-3 py-2.5 text-base leading-6 rounded border border-gray-300 bg-yellow-100 truncate"
              />
            </div>

            <div className="grid grid-cols-[80px_120px] items-center gap-2">
              <label className="font-semibold text-right">Quantity:</label>
              <input
                value={quantity}
                readOnly
                className="border rounded px-2 py-1 bg-yellow-100 text-right"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold text-green-600">Millmark:</label>
              <input
                value={selectedMillId ? `${selectedMillId} - ${selectedMillName}` : ''}
                disabled
                className="border rounded px-2 py-1 bg-yellow-100"
              />
            </div>

            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold">Selling Price:</label>
              <input
                value={sellingPrice}
                onFocus={() => setSellingPrice(String(sellingPrice).replace(/,/g, ''))}
                onChange={(e) => setSellingPrice(e.target.value)}
                onBlur={() => setSellingPrice(formatMoney(sellingPrice))}
                className="border rounded px-2 py-1 bg-yellow-100 text-right"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-x-10 gap-y-4">
            <div className="grid grid-cols-[130px_1fr] items-center gap-2">
              <label className="font-semibold">TIN:</label>
              <input
                value={tin}
                onChange={(e) => setTin(e.target.value)}
                className="border rounded px-2 py-1 bg-yellow-100"
              />
            </div>

            <div className="grid grid-cols-[130px_1fr] items-start gap-2">
              <label className="font-semibold pt-2">Buyer Name:</label>
              <AttachedDropdown
                value={selectedBuyerId}
                displayValue={buyerName}
                readOnlyInput={false}
                onChange={onSelectBuyer}
                items={buyerDropdownItems}
                headers={['Customer ID', 'Customer Name']}
                columns={['cust_id', 'cust_name']}
                search={buyerSearch}
                onSearchChange={setBuyerSearch}
                inputClassName="bg-yellow-100"
                dropdownClassName="min-w-[650px] z-[9999]"
                columnWidths={['160px', '460px']}
              />
            </div>
          </div>

          <div className="grid grid-cols-[130px_1fr] items-center gap-2">
            <label className="font-semibold">Buyer Address:</label>
            <input
              value={buyerAddress}
              onChange={(e) => setBuyerAddress(e.target.value)}
              className="border rounded px-2 py-1 bg-yellow-100"
            />
          </div>

          <div className="grid grid-cols-[130px_auto_auto_1fr] items-center gap-4">
            <label className="font-semibold">VATABLE:</label>

            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={vatFlag}
                onChange={(e) => setVatFlag(e.target.checked)}
              />
              <span>VAT</span>
            </label>

            <label className="flex items-center gap-2 whitespace-nowrap">
              <input
                type="checkbox"
                checked={!vatFlag}
                onChange={(e) => {
                  if (e.target.checked) {
                    setVatFlag(false);
                  }
                }}
              />
              <span>VAT Exempt Sales:</span>
            </label>

            <input
              value={vatFlag ? formatMoney(computedVat) : '0.00'}
              readOnly
              className="border rounded px-2 py-1 bg-yellow-100 text-right"
            />
          </div>
        </div>

        <div className="px-5 pb-5 flex gap-3">
          <button
            type="button"
            onClick={handleNew}
            className="px-4 py-2 rounded bg-blue-600 text-white hover:bg-blue-700"
          >
            New
          </button>

          <button
            type="button"
            onClick={handleSaveMain}
            disabled={!!mainId}
            className={`px-4 py-2 rounded text-white ${
              mainId ? 'bg-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700'
            }`}
          >
            Save Main
          </button>

          <button
            type="button"
            onClick={handleUpdateMain}
            disabled={!mainId || postedFlag}
            className={`px-4 py-2 rounded text-white ${
              !mainId || postedFlag
                ? 'bg-gray-400 cursor-not-allowed'
                : 'bg-emerald-600 hover:bg-emerald-700'
            }`}
          >
            Update Main
          </button>
        </div>
      </div>

      <div className="bg-white shadow-md rounded-lg border border-blue-300">
        <div className="px-4 py-2 border-b bg-blue-50">
          <h3 className="text-md font-bold text-blue-900">Bill of Lading Selection</h3>
        </div>

        <div className="p-4">
          {!mainId ? (
            <div className="text-sm text-gray-500">
              Save the Sales Invoice main entry first to enable Bill of Lading selection.
            </div>
          ) : !blNo ? (
            <div className="text-sm text-gray-500">
              Select BL # first to load related Bill of Lading lines.
            </div>
          ) : loadingBlLines ? (
            <div className="text-sm text-gray-500">Loading Bill of Lading lines...</div>
          ) : blLineRows.length === 0 ? (
            <div className="text-sm text-gray-500">
              No Bill of Lading lines found for the selected BL #.
            </div>
          ) : (
            <div className="overflow-auto border rounded">
              <table className="w-full text-sm">
                <thead className="bg-black text-white sticky top-0 z-10">
                  <tr>
                    <th className="px-2 py-2 border text-center w-[50px]">Use</th>
                    <th className="px-2 py-2 border text-left">BL No.</th>
                    <th className="px-2 py-2 border text-right">Bags</th>
                    <th className="px-2 py-2 border text-right">MT</th>
                    <th className="px-2 py-2 border text-center">Override</th>
                    <th className="px-2 py-2 border text-right">Override Bags</th>
                    <th className="px-2 py-2 border text-right">Used Bags</th>
                  </tr>
                </thead>

                <tbody>
                  {blLineRows.map((row, index) => {
                    const originalBags = Number(row.bags || 0);
                    const overrideBags = Number(row.override_bags || 0);
                    const usedBags = row.selected_flag
                      ? row.override_flag
                        ? overrideBags
                        : originalBags
                      : 0;

                    return (
                      <tr key={`${row.bl_no}-${row.id}`} className="hover:bg-yellow-50">
                        <td className="px-2 py-1 border text-center">
                          <input
                            type="checkbox"
                            checked={!!row.selected_flag}
                            onChange={(e) => toggleBlSelected(index, e.target.checked)}
                          />
                        </td>

                        <td className="px-2 py-1 border whitespace-nowrap">
                          {row.bl_no}
                        </td>

                        <td className="px-2 py-1 border text-right">
                          {originalBags.toLocaleString('en-US', {
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0,
                          })}
                        </td>

                        <td className="px-2 py-1 border text-right">
                          {Number(row.mt || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 3,
                            maximumFractionDigits: 3,
                          })}
                        </td>

                        <td className="px-2 py-1 border text-center">
                          <input
                            type="checkbox"
                            checked={!!row.override_flag}
                            disabled={!row.selected_flag}
                            onChange={(e) => toggleBlOverride(index, e.target.checked)}
                          />
                        </td>

                        <td className="px-2 py-1 border text-right">
                          <input
                            type="number"
                            min="0"
                            max={originalBags}
                            value={row.override_bags ?? ''}
                            disabled={!row.selected_flag || !row.override_flag}
                            onChange={(e) => changeBlOverrideBags(index, e.target.value)}
                            className="w-28 border rounded px-2 py-1 text-right bg-yellow-100 disabled:bg-gray-100"
                          />
                        </td>

                        <td className="px-2 py-1 border text-right font-semibold">
                          {usedBags.toLocaleString('en-US', {
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0,
                          })}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>

                <tfoot>
                  <tr className="bg-blue-50 font-bold">
                    <td colSpan={6} className="px-2 py-2 border text-right">
                      Total Selected Bags
                    </td>
                    <td className="px-2 py-2 border text-right">
                      {Number(quantity || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 0,
                      })}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </div>
      </div>

      <div className="flex gap-3">
        <button
          type="button"
          onClick={openSalesInvoicePdf}
          className="px-4 py-2 rounded bg-white border text-gray-800 hover:bg-gray-50"
        >
          Print Sales Invoice
        </button>

        <button
          type="button"
          disabled={!mainId || postedFlag}
          onClick={handlePost}
          className={`px-4 py-2 rounded ${
            mainId && !postedFlag
              ? 'bg-amber-600 text-white hover:bg-amber-700'
              : 'bg-gray-300 text-white cursor-not-allowed'
          }`}
        >
          Posting
        </button>

        <button
          type="button"
          disabled={!mainId}
          onClick={handleProcess}
          className={`px-4 py-2 rounded ${
            mainId
              ? 'bg-indigo-600 text-white hover:bg-indigo-700'
              : 'bg-gray-300 text-white cursor-not-allowed'
          }`}
        >
          Process
        </button>
      </div>

      {showProcessModal && (
        <div className="fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center">
          <div className="bg-white rounded-lg shadow-xl w-[900px] max-w-[96vw]">
            <div className="px-5 py-3 border-b bg-blue-50 flex items-center justify-between">
              <h3 className="font-bold text-blue-900">Processed Accounting Entries</h3>

              <button
                type="button"
                onClick={() => setShowProcessModal(false)}
                className="rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200"
              >
                ✕
              </button>
            </div>

            <div className="p-5 space-y-4">
              <div className="overflow-auto border rounded">
                <table className="w-full text-sm">
                  <thead className="bg-black text-white">
                    <tr>
                      <th className="px-3 py-2 border text-left">Account Code</th>
                      <th className="px-3 py-2 border text-left">Account Description</th>
                      <th className="px-3 py-2 border text-right">Debit</th>
                      <th className="px-3 py-2 border text-right">Credit</th>
                    </tr>
                  </thead>

                  <tbody>
                    {processEntries.map((entry, index) => (
                      <tr key={`${entry.acct_code}-${index}`}>
                        <td className="px-3 py-2 border">{entry.acct_code}</td>
                        <td className="px-3 py-2 border">{entry.acct_desc}</td>
                        <td className="px-3 py-2 border text-right">
                          {Number(entry.debit || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                        </td>
                        <td className="px-3 py-2 border text-right">
                          {Number(entry.credit || 0).toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                        </td>
                      </tr>
                    ))}
                  </tbody>

                  <tfoot>
                    <tr className="bg-blue-50 font-bold">
                      <td colSpan={2} className="px-3 py-2 border text-right">
                        Total
                      </td>
                      <td className="px-3 py-2 border text-right">
                        {processEntries
                          .reduce((sum, row) => sum + Number(row.debit || 0), 0)
                          .toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                      </td>
                      <td className="px-3 py-2 border text-right">
                        {processEntries
                          .reduce((sum, row) => sum + Number(row.credit || 0), 0)
                          .toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              {alreadyProcessedToSalesJournal && (
                <div className="rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800">
                  This transaction is already processed.
                </div>
              )}

              <div className="flex justify-end gap-3">
                <button
                  type="button"
                  onClick={() => setShowProcessModal(false)}
                  className="px-4 py-2 rounded bg-gray-200 text-gray-800 hover:bg-gray-300"
                >
                  Close
                </button>

                <button
                  type="button"
                  onClick={handleProcessToSalesJournal}
                  disabled={
                    processingToSalesJournal ||
                    processEntries.length === 0 ||
                    alreadyProcessedToSalesJournal
                  }
                  className={`px-4 py-2 rounded text-white ${
                    processingToSalesJournal || processEntries.length === 0 || alreadyProcessedToSalesJournal
                      ? 'bg-gray-400 cursor-not-allowed'
                      : 'bg-green-600 hover:bg-green-700'
                  }`}
                >
                  {processingToSalesJournal
                    ? 'Processing...'
                    : alreadyProcessedToSalesJournal
                      ? 'Already Processed'
                      : 'Process to Sales Journal'}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

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
              className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 z-[10001]"
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
                  key={pdfUrl}
                  title="Sales Invoice PDF"
                  src={pdfUrl}
                  className="w-full h-full"
                  style={{ border: 'none' }}
                />
              )}
            </div>
          </div>
        </div>
      )}

      <style>{`
        .so-imported-refined-sugar-page {
          overflow: visible;
        }

        .so-imported-refined-sugar-page [class*="absolute"] {
          z-index: 9999;
        }
      `}</style>
    </div>
  );
}