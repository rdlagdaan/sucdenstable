
import { useEffect, useState, useRef, useMemo } from 'react';

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

  const sugarTypes = useDropdownOptions('/api/sugar-types');
  const cropYears = useDropdownOptions('/api/crop-years');
  const vendors = useDropdownOptions('/api/vendors');  
  const [pendingDetails, setPendingDetails] = useState<PbnDetailRow[]>([]);


  // NEW ref — used to safely detect when mainEntryId is updated
  const mainEntryIdRef = useRef<number | null>(null);




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


  const fetchPbnEntries = async () => {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    const res = await napi.get('/api/pbn/dropdown-list', {
      
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
    //setPbnOptions(res.data);
  
  };


useEffect(() => {
  fetchPbnEntries();
}, [includePosted]);


  useEffect(() => {
    napi.get('/api/mills').then(res => setMills(res.data));
  }, []);

  useEffect(() => {
    const selected = vendors.items.find(v => v.code === selectedVendor);
    setVendorName(selected?.description || '');
  }, [selectedVendor, vendors.items]);


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

    setTableData([
      {
        mill: '',
        quantity: 0,
        unit_cost: 0,
        commission: 0,
        cost: 0,
        total_commission: 0,
        total_cost: 0,
      },
    ]);

    
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

    await fetchPbnEntries();

    const { id } = res.data;
    setMainEntryId(id);
    setSelectedPbnNo(id.toString());

    // ✅ 👇 Add the initial blank row here
    setTableData([
      {
        mill: '',
        quantity: 0,
        unit_cost: 0,
        commission: 0,
        cost: 0,
        total_commission: 0,
        total_cost: 0,
      },
    ]);

    // ✅ 👇 Only now, enable the Handsontable
    setHandsontableEnabled(true);

    toast.success('Main PBN Entry saved. You can now input details.');
  } catch (err) {
    toast.error('Failed to save PBN Entry.');
    console.error(err);
  }
};


/*const handleAutoSaveDetail = async (rowData: any, rowIndex: number) => {
  console.log('here');
  console.log(mainEntryId);
  console.log(pbnNumber);
  console.log(isRowComplete(rowData));


  if (!mainEntryId || !pbnNumber || !isRowComplete(rowData)) return;
 console.log('prepare');
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

      console.log('to be added');
      console.log(payload);
const response = await napi.post('/api/pbn/save-detail', payload);

const updatedRow = {
  ...rowData,
  id: response.data.detail_id,
  persisted: true,
};

// Prepare new data list
const updatedData = [...tableData];
updatedData[rowIndex] = updatedRow;

// 👇 Only add a blank row if this is the last row
if (rowIndex === updatedData.length - 1) {
  //updatedData.push({ mill: '', quantity: 0, unit_cost: 0, commission: 0 });
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
      console.log('to be updated');
      console.log(payload);
      await napi.post('/api/pbn/update-detail', payload);
    }
  } catch (err) {
      console.log('to be error');
      
    console.error('Auto-save failed', err);
  }
};

*/



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
      const response = await napi.post('/api/pbn/save-detail', payload);
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
      await napi.post('/api/pbn/update-detail', payload);
    }
  } catch (err) {
    console.error('Auto-save failed', err);
  }
};




const handlePbnSelect = async (selectedId: string) => {
  try {
    const storedUser = localStorage.getItem('user');
    const user = storedUser ? JSON.parse(storedUser) : null;

    const res = await napi.get(`/api/id/${selectedId}`, {
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



        {/*<div className="grid grid-cols-1 gap-4">
    <input
      type="checkbox"
      checked={includePosted}
      onChange={() => setIncludePosted(!includePosted)}
      className="form-checkbox h-4 w-4 text-blue-600"
    />

    <DropdownWithHeaders
      label="PBN No"
      value={selectedPbnNo}
      onChange={setSelectedPbnNo}
      items={pbnOptions}
      search={pbnSearch}
      onSearchChange={setPbnSearch}
      headers={['ID', 'PBN #', 'Sugar Type', 'Vend Code', 'Vendor Name', 'Crop Year', 'PBN Date']}
      dropdownPositionStyle={{ minWidth: '700px' }}
    />      
        
        
        </div>  */}
        
        
        
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
      //handleAutoSaveDetail(row, rowIndex);
      
      setTimeout(() => {
        //handleAutoSaveDetail(row, rowIndex);
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

  setTableData(newData);
}}




  contextMenu={{
    items: {
      'remove_row': {
        name: '🗑️ Remove row',
        callback: async function (_key, selection) {
          const rowIndex = selection[0].start.row;
          const rowData = tableData[rowIndex];
console.log(rowData);
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
            await napi.post('/api/pbn/delete-detail', {
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
          height="300"
          rowHeaders={true}
          licenseKey="non-commercial-and-evaluation"
        />
        
      )}
</div>
      <div className="flex gap-4 mt-4">
        <button onClick={handleNew} className="bg-blue-500 text-white px-4 py-2 rounded">New</button>
        <button onClick={handleSave} disabled={isExistingRecord} className={`px-4 py-2 rounded ${isExistingRecord ? 'bg-gray-400 cursor-not-allowed' : 'bg-green-600 text-white'}`}
>Save</button>
      </div>
    </div>
  );
}
