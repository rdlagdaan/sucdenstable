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
};

type RRDropdownItem = {
  receipt_no: string;
  quantity: number;
  sugar_type: string;
  pbn_number: string;
  receipt_date: string;
  vendor_code: string;
  vendor_name: string;
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

type QuedanRow = {
  id: number;
  receipt_no: string;
  quedan_no: string;
  quantity: number;
  planter_tin: string;
  planter_name: string;
  unit_cost: number;
  week_ending: string;
  date_issued: string;
  item_no: string;
  mill: string;

  selected_flag: boolean;
  override_flag: boolean;
  override_quantity: number | string | null;
  selected_quantity: number;
};



export default function SoDomesticRawSugar() {

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

  const [rrNo, setRrNo] = useState('');
  const [rrOptions, setRrOptions] = useState<RRDropdownItem[]>([]);
  const [rrSearch, setRrSearch] = useState('');

  const [_blOptions, setBlOptions] = useState<BlItem[]>([]);
  const [selectedBlNo, setSelectedBlNo] = useState('');

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

  const [withholdingTaxFlag, setWithholdingTaxFlag] = useState(false);

  const [showPdf, setShowPdf] = useState(false);
  const [pdfUrl, setPdfUrl] = useState('');
  const pdfBlobUrlRef = useRef<string | null>(null);

  const [showProcessModal, setShowProcessModal] = useState(false);
  const [processEntries, setProcessEntries] = useState<ProcessEntry[]>([]);  

  const [processingToSalesJournal, setProcessingToSalesJournal] = useState(false);  
  const [alreadyProcessedToSalesJournal, setAlreadyProcessedToSalesJournal] = useState(false);  
  const [quedanRows, setQuedanRows] = useState<QuedanRow[]>([]);
  const [loadingQuedans, setLoadingQuedans] = useState(false);




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
  const computedWithholdingTax = withholdingTaxFlag ? computedTotalSales * 0.01 : 0;

  const cleanReceiptNo = (value: string) => {
    return String(value || '').split(' - ')[0].trim();
  };

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


  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-domestic-raw-sugar/dropdowns/si', {
        params: { company_id: companyId, q: siSearch },
      })
      .then((res) => setSiOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setSiOptions([]));
  }, [companyId, siSearch]);

  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-domestic-raw-sugar/dropdowns/po', {
        params: { company_id: companyId, q: poSearch },
      })
      .then((res) => setPoOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setPoOptions([]));
  }, [companyId, poSearch]);




  useEffect(() => {
    if (!companyId) return;

    napi
      .get('/so-domestic-raw-sugar/dropdowns/customers', {
        params: { company_id: companyId, q: buyerSearch },
      })
      .then((res) => setBuyerOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setBuyerOptions([]));
  }, [companyId, buyerSearch]);

  useEffect(() => {
    if (!companyId || !selectedPoNo) {
      setBlOptions([]);
      return;
    }

    napi
      .get('/so-domestic-raw-sugar/dropdowns/bl', {
        params: {
          company_id: companyId,
          po_no: selectedPoNo,
        },
      })
      .then((res) => setBlOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setBlOptions([]));
  }, [companyId, selectedPoNo]);


  useEffect(() => {
    if (!companyId) {
      setRrOptions([]);
      return;
    }

    napi
      .get('/so-domestic-raw-sugar/dropdowns/rr', {
        params: {
          company_id: companyId,
          po_no: selectedPoNo || undefined,
          q: rrSearch,
        },
      })
      .then((res) => setRrOptions(Array.isArray(res.data) ? res.data : []))
      .catch(() => setRrOptions([]));
  }, [companyId, selectedPoNo, rrSearch]);


  const loadPoItems = async (poId: number | '', poNo: string) => {
    if (!companyId || (!poId && !poNo)) {
      setItemOptions([]);
      return;
    }

    try {
      const res = await napi.get('/so-domestic-raw-sugar/dropdowns/po-items', {
        params: {
          company_id: companyId,
          purchase_order_id: poId || undefined,
          po_no: poNo || undefined,
        },
      });

      const arr = Array.isArray(res.data) ? res.data : [];
      setItemOptions(arr);
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
      setSelectedBlNo('');
      setRrNo('');
      setRrSearch('');
      setRrOptions([]);      
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
    setSelectedBlNo('');
    setRrNo('');
    setRrSearch('');
    setRrOptions([]);
    setQuedanRows([]);
    setQuantity(0);

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
    setSelectedItemLabel(item.item_label || item.particulars || '');

    setSelectedMillId(item.mill_code || '');
    setSelectedMillName(item.mill || '');
    setQuantity(Number(item.quantity || 0));

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

    // customer_list currently has no TIN/address columns,
    // so these remain manually editable until columns are added.
  };

  const onSelectRR = async (receiptNo: string) => {
    const cleanNo = cleanReceiptNo(receiptNo);

    const rr = rrOptions.find(
      (r) => String(r.receipt_no).trim() === cleanNo
    );

    setRrNo(cleanNo);
    setQuedanRows([]);
    setQuantity(0);

    if (rr) {
      await loadQuedans(mainId || undefined, cleanNo, selectedPoNo);
    }
  };

  const computeQuedanQuantity = (rows: QuedanRow[]) => {
    return rows.reduce((sum, row) => {
      if (!row.selected_flag) return sum;

      if (row.override_flag) {
        const overrideQty = Number(row.override_quantity || 0);
        return sum + overrideQty;
      }

      return sum + Number(row.quantity || 0);
    }, 0);
  };

  const loadQuedans = async (
    targetMainId?: number,
    receiptNoOverride?: string,
    poNoOverride?: string
  ) => {
    const targetReceiptNo = cleanReceiptNo(receiptNoOverride || rrNo);

    if (!companyId || !targetReceiptNo) {
      setQuedanRows([]);
      return;
    }

    setLoadingQuedans(true);

    try {
      const res = await napi.get('/so-domestic-raw-sugar/quedans', {
        params: {
          company_id: companyId,
          receipt_no: targetReceiptNo,
          po_no: poNoOverride || selectedPoNo || undefined,
          so_domestic_raw_sugar_id: targetMainId || mainId || undefined,
        },
      });

      const rows = Array.isArray(res.data) ? res.data : [];
      setQuedanRows(rows);

      const total = computeQuedanQuantity(rows);
      setQuantity(total);
    } catch (e: any) {
      setQuedanRows([]);
      toast.error(e?.response?.data?.message || 'Failed to load quedans.');
    } finally {
      setLoadingQuedans(false);
    }
  };

  const saveQuedanSelections = async (rows: QuedanRow[]) => {
    if (!mainId || !companyId) {
      toast.info('Save the main entry first.');
      return;
    }

    try {
      const res = await napi.post('/so-domestic-raw-sugar/save-quedans', {
        so_domestic_raw_sugar_id: mainId,
        company_id: companyId,
        items: rows.map((row) => ({
          receipt_no: row.receipt_no,
          quedan_no: row.quedan_no,
          planter_tin: row.planter_tin,
          planter_name: row.planter_name,
          original_quantity: Number(row.quantity || 0),
          selected_flag: !!row.selected_flag,
          override_flag: !!row.override_flag,
          override_quantity: row.override_flag ? Number(row.override_quantity || 0) : null,
        })),
      });

      setQuantity(Number(res.data?.quantity || 0));
      toast.success('Quedan selection saved.');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save quedan selection.');
    }
  };

  const updateQuedanRows = async (nextRows: QuedanRow[]) => {
    const total = computeQuedanQuantity(nextRows);

    setQuedanRows(nextRows);
    setQuantity(total);

    if (mainId) {
      await saveQuedanSelections(nextRows);
    }
  };

  const toggleQuedanSelected = async (index: number, checked: boolean) => {
    const next = [...quedanRows];
    const row = { ...next[index] };

    row.selected_flag = checked;

    if (!checked) {
      row.override_flag = false;
      row.override_quantity = null;
      row.selected_quantity = 0;
    } else {
      row.selected_quantity = row.override_flag
        ? Number(row.override_quantity || 0)
        : Number(row.quantity || 0);
    }

    next[index] = row;
    await updateQuedanRows(next);
  };

  const toggleQuedanOverride = async (index: number, checked: boolean) => {
    const next = [...quedanRows];
    const row = { ...next[index] };

    if (!row.selected_flag && checked) {
      toast.info('Check the quedan first before overriding quantity.');
      return;
    }

    row.override_flag = checked;
    row.override_quantity = checked ? Number(row.quantity || 0) : null;
    row.selected_quantity = checked ? Number(row.override_quantity || 0) : Number(row.quantity || 0);

    next[index] = row;
    await updateQuedanRows(next);
  };

  const changeQuedanOverrideQty = async (index: number, rawValue: string) => {
    const next = [...quedanRows];
    const row = { ...next[index] };

    const originalQty = Number(row.quantity || 0);
    let value = Number(rawValue || 0);

    if (value < 0) value = 0;

    if (value > originalQty) {
      value = originalQty;
      toast.warning('Override quantity cannot exceed original quantity.');
    }

    row.override_quantity = value;
    row.selected_quantity = row.selected_flag ? value : 0;

    next[index] = row;
    await updateQuedanRows(next);
  };

  const loadSalesInvoice = async (id: string | number) => {
    if (!companyId || !id) return;

    try {
      const res = await napi.get(`/so-domestic-raw-sugar/${id}`, {
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

      setSelectedBlNo(String(main.bl_no || ''));

      setSelectedItemId(main.item_id ? Number(main.item_id) : '');
      setSelectedItemLabel(String(main.item_label || 'RAW SUGAR'));

      setSelectedMillId(String(main.mill_id || ''));
      setSelectedMillName(String(main.mill_name || ''));

      setSellingPrice(formatMoney(main.selling_price ?? 0));
      setQuantity(Number(main.quantity || 0));

      setBuyerName(String(main.buyer_name || ''));
      setBuyerAddress(String(main.buyer_address || ''));
      setTin(String(main.tin || ''));

      setWithholdingTaxFlag(!!main.withholding_tax_flag);
      setAlreadyProcessedToSalesJournal(!!main.processed_to_sales_journal);

      const loadedRrNo = String(main.rr_no || '');
      setRrNo(loadedRrNo);

      await loadPoItems(main.po_entry_id ? Number(main.po_entry_id) : '', String(main.po_no || ''));

      await loadQuedans(Number(main.id), loadedRrNo, String(main.po_no || ''));

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

        bl_no: selectedBlNo || null,

        mill_id: selectedMillId || null,
        mill_name: selectedMillName || null,

        selling_price: parseMoney(sellingPrice),
        quantity: Number(quantity || 0),

        buyer_name: buyerName || null,
        buyer_address: buyerAddress || null,
        tin: tin || null,

        vatable_flag: false,
        withholding_tax_flag: withholdingTaxFlag,
        withholding_tax_amount: computedWithholdingTax,
      };

      const res = await napi.post('/so-domestic-raw-sugar/save-main', payload);

      const savedId = Number(res.data.id);

      setMainId(savedId);
      setSiNo(String(res.data.si_no || ''));

      toast.success('Sales Invoice main saved.');

      await loadQuedans(savedId);
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

        bl_no: selectedBlNo || null,

        mill_id: selectedMillId || null,
        mill_name: selectedMillName || null,

        selling_price: parseMoney(sellingPrice),
        quantity: Number(quantity || 0),

        buyer_name: buyerName || null,
        buyer_address: buyerAddress || null,
        tin: tin || null,

        vatable_flag: false,
        withholding_tax_flag: withholdingTaxFlag,
        withholding_tax_amount: computedWithholdingTax,
      };

      await napi.post('/so-domestic-raw-sugar/update-main', payload);

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
      const res = await napi.get(`/so-domestic-raw-sugar/form-pdf/${pdfId}`, {
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
      await napi.post('/so-domestic-raw-sugar/post', {
        id: mainId,
        company_id: companyId,
      });

      setPostedFlag(true);
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
      const res = await napi.post('/so-domestic-raw-sugar/process', {
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
    const res = await napi.post('/so-domestic-raw-sugar/process-to-sales-journal', {
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

    setRrNo('');
    setSelectedBlNo('');
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

    setWithholdingTaxFlag(false);
    setQuedanRows([]);

    setProcessEntries([]);
    setProcessingToSalesJournal(false);
    setAlreadyProcessedToSalesJournal(false);

  };

  return (
    <div className="so-domestic-raw-sugar-page min-h-screen pb-40 space-y-4 p-6 overflow-visible">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-white shadow-md rounded-lg border border-blue-300">
        <div className="px-4 py-2 border-b bg-blue-50">
          <h2 className="text-lg font-bold text-blue-900">
            SALES INVOICE ENTRY - DOMESTIC RAW SUGAR
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
              <label className="font-semibold text-red-600 pt-2">RR #:</label>

              <DropdownWithHeadersDynamic
                label=""
                value={rrNo}
                onChange={(value) => onSelectRR(String(value))}
                items={rrOptions.map((r) => ({
                  code: r.receipt_no,
                  label: r.sugar_type,
                  description: r.vendor_name,
                  quantity: Number(r.quantity || 0).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                  }),
                  pbn_number: r.pbn_number,
                  receipt_date: r.receipt_date,
                }))}
                search={rrSearch}
                onSearchChange={setRrSearch}
                headers={['Receipt No', 'Sugar Type', 'Vendor Name', 'Qty', 'PBN No', 'RDate']}
                columnWidths={['160px', '90px', '340px', '100px', '130px', '120px']}
                customKey="so_domestic_rr"
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
                checked={withholdingTaxFlag}
                onChange={(e) => setWithholdingTaxFlag(e.target.checked)}
              />
              <span>W/Tax</span>
            </label>

            <label className="flex items-center gap-2 whitespace-nowrap">
              <input
                type="checkbox"
                checked={!withholdingTaxFlag}
                onChange={(e) => {
                if (e.target.checked) {
                    setWithholdingTaxFlag(false);
                }
                }}
              />
              <span>VAT Exempt Sales:</span>
            </label>

            <input
              value={withholdingTaxFlag ? formatMoney(computedWithholdingTax) : '0.00'}
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
          <h3 className="text-md font-bold text-blue-900">Quedan Selection</h3>
        </div>

        <div className="p-4">
          {!mainId ? (
            <div className="text-sm text-gray-500">
              Save the Sales Invoice main entry first to enable quedan selection.
            </div>
          ) : !rrNo ? (
            <div className="text-sm text-gray-500">
              Select RR # first to load related quedans.
            </div>
          ) : loadingQuedans ? (
            <div className="text-sm text-gray-500">Loading quedans...</div>
          ) : quedanRows.length === 0 ? (
            <div className="text-sm text-gray-500">
              No quedans found for the selected RR #.
            </div>
          ) : (
            <div className="overflow-auto border rounded">
              <table className="w-full text-sm">
                <thead className="bg-black text-white sticky top-0 z-10">
                  <tr>
                    <th className="px-2 py-2 border text-center w-[50px]">Use</th>
                    <th className="px-2 py-2 border text-left">Quedan #</th>
                    <th className="px-2 py-2 border text-right">Original Qty</th>
                    <th className="px-2 py-2 border text-left">Planter TIN</th>
                    <th className="px-2 py-2 border text-left">Planter Name</th>
                    <th className="px-2 py-2 border text-center">Override</th>
                    <th className="px-2 py-2 border text-right">Override Qty</th>
                    <th className="px-2 py-2 border text-right">Used Qty</th>
                  </tr>
                </thead>

                <tbody>
                  {quedanRows.map((row, index) => {
                    const originalQty = Number(row.quantity || 0);
                    const overrideQty = Number(row.override_quantity || 0);
                    const usedQty = row.selected_flag
                      ? row.override_flag
                        ? overrideQty
                        : originalQty
                      : 0;

                    return (
                      <tr key={`${row.receipt_no}-${row.quedan_no}`} className="hover:bg-yellow-50">
                        <td className="px-2 py-1 border text-center">
                          <input
                            type="checkbox"
                            checked={!!row.selected_flag}
                            onChange={(e) => toggleQuedanSelected(index, e.target.checked)}
                          />
                        </td>

                        <td className="px-2 py-1 border whitespace-nowrap">
                          {row.quedan_no}
                        </td>

                        <td className="px-2 py-1 border text-right">
                          {originalQty.toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                        </td>

                        <td className="px-2 py-1 border whitespace-nowrap">
                          {row.planter_tin}
                        </td>

                        <td className="px-2 py-1 border">
                          {row.planter_name}
                        </td>

                        <td className="px-2 py-1 border text-center">
                          <input
                            type="checkbox"
                            checked={!!row.override_flag}
                            disabled={!row.selected_flag}
                            onChange={(e) => toggleQuedanOverride(index, e.target.checked)}
                          />
                        </td>

                        <td className="px-2 py-1 border text-right">
                          <input
                            type="number"
                            min="0"
                            max={originalQty}
                            value={row.override_quantity ?? ''}
                            disabled={!row.selected_flag || !row.override_flag}
                            onChange={(e) => changeQuedanOverrideQty(index, e.target.value)}
                            className="w-28 border rounded px-2 py-1 text-right bg-yellow-100 disabled:bg-gray-100"
                          />
                        </td>

                        <td className="px-2 py-1 border text-right font-semibold">
                          {usedQty.toLocaleString('en-US', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                          })}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>

                <tfoot>
                  <tr className="bg-blue-50 font-bold">
                    <td colSpan={7} className="px-2 py-2 border text-right">
                      Total Selected Quantity
                    </td>
                    <td className="px-2 py-2 border text-right">
                      {Number(quantity || 0).toLocaleString('en-US', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
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
            }            className={`px-4 py-2 rounded text-white ${
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
        .so-domestic-raw-sugar-page {
          overflow: visible;
        }

        .so-domestic-raw-sugar-page [class*="absolute"] {
          z-index: 9999;
        }
      `}</style>
    </div>
  );
}