
// PurchaseBookNote.tsx

import _Handsontable from 'handsontable';
import { HotTable, HotTableClass } from '@handsontable/react';
import 'handsontable/dist/handsontable.full.min.css';
import { NumericCellType  } from 'handsontable/cellTypes';

_Handsontable.cellTypes.registerCellType('numeric', NumericCellType); // ✅ FIX


import 'handsontable/dist/handsontable.full.min.css';
import { useEffect, useState, useRef } from 'react';
import napi from '../../../utils/axiosnapi';
import type { CellChange, ChangeSource } from 'handsontable/common';

import DropdownWithHeaders from '../../components/DropdownWithHeaders';

import { useDropdownOptions } from '../../../hooks/useDropdownOptions';



/*interface Vendor {
  vend_code: string;
  vend_name: string;
}

interface SugarType {
  id: number;
  sugar_type: string;
  description: string;
}

interface CropYear {
  crop_year: string;
}*/

export default function PurchaseBookNote() {
  
  useEffect(() => {
    document.documentElement.style.overflow = 'visible';
    return () => {
      document.documentElement.style.overflow = '';
    };
  }, []);
  
  
  //const [sugarTypes, setSugarTypes] = useState<SugarType[]>([]);
  //const [cropYears, setCropYears] = useState<CropYear[]>([]);
  //const [vendors, setVendors] = useState<Vendor[]>([]);
  const [pbnNumber, setPbnNumber] = useState('');
  const [pbnDate, setPbnDate] = useState('');
  const [sugarType, setSugarType] = useState('');
  const [cropYear, setCropYear] = useState('');
  const [vendCode, setVendCode] = useState('');
  const [vendorName, setVendorName] = useState('');
  const hotRef = useRef<HotTableClass>(null);

  const selectedCompanyId = 1; // Or dynamically set this
  const [showPosted, setShowPosted] = useState(false);
  const [pbnEntries, setPbnEntries] = useState<any[]>([]);
  const [selectedPbn, setSelectedPbn] = useState('');

  const [selectedSugarType, setSelectedSugarType] = useState<string>('');

//const [selectedSugarType, setSelectedSugarType] = useState('');
const [selectedVendor, setSelectedVendor] = useState('');
const [selectedCropYear, setSelectedCropYear] = useState('');




// Load dynamic options
//const sugarTypes = useDropdownOptions('/api/sugar-types');

const {
  items: sugarTypeItems,
  search: sugarTypeSearch,
  setSearch: setSugarTypeSearch,
} = useDropdownOptions('/api/sugar-types');

const vendors = useDropdownOptions('/api/vendors');
//const cropYears = useDropdownOptions('/api/crop-years');

const {
  items: cropYears,
  search: cropYearSearch,
  setSearch: setCropYearSearch,
} = useDropdownOptions('/api/crop-years');


  useEffect(() => {
    const fetchData = async () => {
      const [sugar, crop, vendor,pbnno] = await Promise.all([
        napi.get('/api/sugar-types'),
        napi.get('/api/crop-years'),
        napi.get('/api/vendors'),
        napi.get(`/api/settings/PBNNO?company_id=${selectedCompanyId}`),
      ]);
      //setSugarTypes(sugar.data);
      setSelectedSugarType(sugar.data);
      if (sugar.data.length > 0) setSelectedSugarType(sugar.data[0].code);
      //setCropYears(crop.data);
      setSelectedCropYear(crop.data);
      //setVendors(vendor.data);
      setSelectedVendor(vendor.data);

      setPbnNumber(pbnno.data.value);
    };
    fetchData();
  }, []);

//useEffect(() => {
//  const selected = vendors.items.find(v => v.code === vendCode); // Access items array
//  setVendorName(selected?.description || ''); // Safely set vendor name or use default
//}, [vendCode, vendors.items]); // Correct dependency


  useEffect(() => {
    const fetchPbnEntries = async () => {
      try {
        const res = await napi.get(`/api/pbn-entries?postedFlag=${showPosted ? 1 : 0}`);
        setPbnEntries(res.data);
      } catch (err) {
        console.error('Failed to fetch PBN entries', err);
      }
    };

    fetchPbnEntries();
  }, [showPosted]);

const handleVendorChange = (vendorCode: string) => {
  setSelectedVendor(vendorCode);

  const selected = vendors.items.find(v => v.code === vendorCode);
  setVendorName(selected?.description || '');
};




  const handleNew = () => {
    setSugarType('');
    setCropYear('');
    setVendCode('');
    setVendorName('');
    setPbnDate(new Date().toISOString().substring(0, 10));
    setPbnNumber('');
    hotRef.current?.hotInstance?.loadData([{}]);
  };

  const handleSave = async () => {
    const hotInstance = hotRef.current?.hotInstance;
    if (!hotInstance) return;

    const details = hotInstance.getData().map((row: any[]) => {
      const [_, mill, quantity, commission] = row;
      const qty = parseFloat(quantity) || 0;
      const com = parseFloat(commission) || 0;
      return {
        mill: mill || '',
        quantity: qty,
        commission: com,
        cost: qty * com,
        total_cost: qty * com,
      };
    });

    const payload = {
      pbn_number: pbnNumber,
      pbn_date: pbnDate,
      sugar_type: sugarType,
      crop_year: cropYear,
      vend_code: vendCode,
      vendor_name: vendorName,
      details,
    };

    try {
      await napi.post('/api/pbn-entry', payload);
      alert('Saved successfully');
    } catch (err) {
      console.error(err);
      alert('Save failed');
    }
  };

  return (
    <div className="w-full h-full px-6 space-y-6 overflow-visible relative">
      
      
      <div className="grid grid-cols-2 gap-4 mb-6">
        
        <div className="relative overflow-visible z-10">
        <DropdownWithHeaders
          label="Sugar Type"
          value={selectedSugarType}
          onChange={setSelectedSugarType}
          items={sugarTypeItems}
          search={sugarTypeSearch}
          onSearchChange={setSugarTypeSearch}
          headers={['Sugar Type', 'Description']}
        />
        </div>        


        
        <div className="flex items-center gap-2 mt-4">
          <input
            type="checkbox"
            id="chkPbnPosted"
            checked={showPosted}
            onChange={(e) => setShowPosted(e.target.checked)}
          />
          <label htmlFor="chkPbnPosted">PBN #:</label>

          <select
            value={selectedPbn}
            onChange={(e) => {
              const selected = pbnEntries.find(p => p.pbn_number === e.target.value);
              setSelectedPbn(e.target.value);

              if (selected) {
                setSugarType(selected.sugar_type);
                setCropYear(selected.crop_year);
                setVendCode(selected.vend_code);
                setVendorName(selected.vendor_name);
                setPbnDate(selected.pbn_date);
              }
            }}
            className="border p-2 w-[550px] bg-yellow-100"
          >
            <option value="">Select PBN Number</option>
            {pbnEntries.map((entry) => (
              <option key={entry.pbn_number} value={entry.pbn_number}>
                {entry.pbn_number} | {entry.sugar_type} | {entry.vend_code} | {entry.vendor_name} | {entry.crop_year} | {entry.pbn_date}
              </option>
            ))}
          </select>
        </div>
      
      </div>


      <div className="grid grid-cols-2 gap-4 mt-6">      
        <div>
          <label className="block">PBN Date</label>
          <input type="date" value={pbnDate} onChange={e => setPbnDate(e.target.value)} className="w-full border p-2" />
        </div>
        
        <DropdownWithHeaders
          label="Crop Year"
          value={selectedCropYear}
          onChange={setSelectedCropYear}
          items={cropYears}
          search={cropYearSearch}
          onSearchChange={setCropYearSearch}
          headers={['Crop Year', 'FYear', 'TYear']} // optional custom headers
        />
      </div>
      
      
      <div className="grid grid-cols-2 gap-4">
        
        <DropdownWithHeaders
          label="Vendor"
          value={selectedVendor}
          onChange={handleVendorChange} // <-- use handler
          items={vendors.items}
          search={vendors.search}
          onSearchChange={vendors.setSearch}
          headers={['Vendor Code', 'Vendor Name']}
        />

        <div>
          <label className="block">Vendor Name</label>
          <input value={vendorName} disabled className="w-full border p-2 bg-gray-100" />

        </div>
      </div>
      <div>



        <label className="block font-bold mb-2">Details</label>
        <HotTable
          ref={hotRef}
          data={[{}]}
          colHeaders={["#", "Mill", "Quantity", "Commission", "Cost", "Total Cost"]}
          columns={[
            { readOnly: true },
            { type: 'text' },
            { type: 'numeric' },
            { type: 'numeric' },
            { readOnly: true },
            { readOnly: true },
          ]}
          licenseKey="non-commercial-and-evaluation"
          stretchH="all"
          height="auto"
          width="100%"
          rowHeaders={true}
          afterChange={(changes: CellChange[] | null, source: ChangeSource) => {
          if (source === 'edit' && changes) {
              changes.forEach(([row]) => {
              const hot = hotRef.current?.hotInstance;
              if (!hot) return;
              const qty = parseFloat(hot.getDataAtCell(row, 2)) || 0;
              const com = parseFloat(hot.getDataAtCell(row, 3)) || 0;
              const cost = qty * com;
              hot.setDataAtCell(row, 4, cost);
              hot.setDataAtCell(row, 5, cost);
              });
          }
          }}

        />
      </div>

      <div className="flex gap-4">
        <button onClick={handleNew} className="bg-gray-300 px-4 py-2 rounded">New</button>
        <button onClick={handleSave} className="bg-green-500 text-white px-4 py-2 rounded">Save</button>
        <button className="bg-blue-500 text-white px-4 py-2 rounded">Print</button>
        <button className="bg-yellow-500 text-white px-4 py-2 rounded">Download</button>
      </div>
    </div>
  );
}
