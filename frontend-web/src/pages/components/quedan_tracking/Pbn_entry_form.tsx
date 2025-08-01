// Unified working version of `pbn_entry_form.tsx` with:
// ✅ Properly working searchable Mill dropdown
// ✅ Auto-calculation of cost, commission, total cost
// ✅ Auto-add of new row on complete input
// ✅ Fully restored afterChange, cell typing, and Handsontable integration

import { useEffect, useState, useRef } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';
import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import DropdownWithHeaders from '../../components/DropdownWithHeaders';
import { useDropdownOptions } from '../../../hooks/useDropdownOptions';
import napi from '../../../utils/axiosnapi';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

export default function PurchaseBookNote() {
  const hotRef = useRef<HotTableClass>(null);
  const [pbnNumber, setPbnNumber] = useState('');
  const [pbnDate, setPbnDate] = useState('');
  const [selectedSugarType, setSelectedSugarType] = useState('');
  const [selectedCropYear, setSelectedCropYear] = useState('');
  const [selectedVendor, setSelectedVendor] = useState('');
  const [vendorName, setVendorName] = useState('');
  const [posted, setPosted] = useState(false);
  const [handsontableEnabled, setHandsontableEnabled] = useState(false);
  const [mainEntryId, setMainEntryId] = useState<number | null>(null);
  const [tableData, setTableData] = useState<any[]>([{}]);
  const [mills, setMills] = useState<{ mill_id: string; mill_name: string }[]>([]);

  const sugarTypes = useDropdownOptions('/api/sugar-types');
  const cropYears = useDropdownOptions('/api/crop-years');
  const vendors = useDropdownOptions('/api/vendors');

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


  useEffect(() => {
    napi.get('/api/mills').then(res => setMills(res.data));
  }, []);

  useEffect(() => {
    const selected = vendors.items.find(v => v.code === selectedVendor);
    setVendorName(selected?.description || '');
  }, [selectedVendor, vendors.items]);

  const handleNew = () => {
    setSelectedSugarType('');
    setSelectedCropYear('');
    setSelectedVendor('');
    setVendorName('');
    setPbnDate('');
    setPosted(false);
    setHandsontableEnabled(false);
    setMainEntryId(null);
    setTableData([{}]);
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

      const { data } = await napi.get('/api/pbn/generate-pbn-number', {
        params: {
          company_id: companyId,
          sugar_type: selectedSugarType,
        },
      });

      const generatedPbnNumber = data.pbn_number;
      setPbnNumber(generatedPbnNumber);

      const res = await napi.post('/api/pbn/save-main', {
        sugar_type: selectedSugarType,
        crop_year: selectedCropYear,
        pbn_date: pbnDate,
        vend_code: selectedVendor,
        vendor_name: vendorName,
        pbn_number: generatedPbnNumber,
        posted_flag: posted,
        company_id: companyId,
      });

      const { id } = res.data;
      setMainEntryId(id);
      setHandsontableEnabled(true);
      toast.success('Main PBN Entry saved. You can now input details.');
    } catch (err) {
      toast.error('Failed to save PBN Entry.');
      console.error(err);
    }
  };

const handleAutoSaveDetail = async (rowData: any, rowIndex: number) => {
  if (!mainEntryId || !pbnNumber || !isRowComplete(rowData)) return;

  const storedUser = localStorage.getItem('user');
  const user = storedUser ? JSON.parse(storedUser) : null;

  const payload = {
    ...rowData,
    mill_code: mills.find(m => m.mill_name === rowData.mill)?.mill_id || '',
    pbn_entry_id: mainEntryId,
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
      await napi.post('/api/pbn/save-detail', payload);
      rowData.persisted = true;
    } else {
      await napi.post('/api/pbn/update-detail', payload);
    }
  } catch (err) {
    console.error('Auto-save failed', err);
  }
};


  return (
    <div className="space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-white shadow-md rounded-lg p-6 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <DropdownWithHeaders label="Sugar Type" value={selectedSugarType} onChange={setSelectedSugarType} items={sugarTypes.items} search={sugarTypes.search} onSearchChange={sugarTypes.setSearch} headers={['Sugar Type', 'Description']} />
          <DropdownWithHeaders label="Crop Year" value={selectedCropYear} onChange={setSelectedCropYear} items={cropYears.items} search={cropYears.search} onSearchChange={cropYears.setSearch} headers={['Crop Year', 'FYear', 'TYear']} />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <DropdownWithHeaders label="Vendor" value={selectedVendor} onChange={setSelectedVendor} items={vendors.items} search={vendors.search} onSearchChange={vendors.setSearch} headers={['Vendor Code', 'Vendor Name']} />
          <div>
            <label className="block">Vendor Name</label>
            <input disabled className="w-full border p-2 bg-gray-100" value={vendorName} />
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label>PBN Date</label>
            <input type="date" value={pbnDate} onChange={(e) => setPbnDate(e.target.value)} className="w-full border p-2" />
          </div>
          <div className="flex items-center mt-6">
            <input type="checkbox" checked={posted} onChange={(e) => setPosted(e.target.checked)} className="mr-2" /> Posted
          </div>
        </div>
      </div>

<div className="relative overflow-visible z-0">
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
      source: mills.map(m => m.mill_name),
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
afterChange={(changes, source) => {
  if (!changes || source !== 'edit') return;
  const newData = [...tableData];
  changes.forEach(([rowIndex]) => {
    const row = newData[rowIndex];
    if (!row) return;

    const qty = parseFloat(row.quantity || 0);
    const uc = parseFloat(row.unit_cost || 0);
    const com = parseFloat(row.commission || 0);

    row.cost = Math.round(qty * uc * 100) / 100;
    row.total_commission = qty * com;
    row.total_cost = row.cost + row.total_commission;

    if (isRowComplete(row)) {
      handleAutoSaveDetail(row, rowIndex);
      if (!row.persisted && rowIndex === newData.length - 1) {
        newData.push({ mill: '', quantity: 0, unit_cost: 0, commission: 0 });
      }
    }
  });
  setTableData(newData);
}}




          stretchH="all"
          width="100%"
          height="300"
          rowHeaders={true}
          licenseKey="non-commercial-and-evaluation"
        />
        
      )}
</div>
      <div className="flex gap-4 mt-4">
        <button onClick={handleNew} className="bg-blue-500 text-white px-4 py-2 rounded">New</button>
        <button onClick={handleSave} className="bg-green-600 text-white px-4 py-2 rounded">Save</button>
      </div>
    </div>
  );
}
