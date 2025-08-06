import { useState } from 'react';
import { usePbnEntries } from '../../hooks/usePbnEntries';
import DropdownWithHeaders from './DropdownWithHeaders';

const PbnEntrySelector = ({ onSelect }: { onSelect: (pbnNumber: string) => void }) => {
  const [includePosted, setIncludePosted] = useState(false);
  const { items, search, setSearch } = usePbnEntries(includePosted);
  const [selectedPbn, setSelectedPbn] = useState('');

  const handleChange = (val: string) => {
    setSelectedPbn(val);
    onSelect(val);
  };

  return (
    <div className="flex items-end gap-4">
      {/* Checkbox */}
      <div className="flex items-center space-x-2">
        <input
          type="checkbox"
          checked={includePosted}
          onChange={() => setIncludePosted(!includePosted)}
          className="form-checkbox h-4 w-4 text-blue-600"
        />
      </div>

      {/* Label and Dropdown */}
      <div className="flex flex-col w-full max-w-[720px]">
<DropdownWithHeaders
  label="PBN No"
  value={selectedPbn}
  onChange={handleChange}
  items={items}
  search={search}
  onSearchChange={setSearch}
  headers={['pbn_number', 'sugar_type' , 'vendor_name', 'crop_year', 'pbn_date']}
  dropdownPositionStyle={{ minWidth: '700px' }} // ✅ responsive dropdown width

/>

      </div>
    </div>
  );
};

export default PbnEntrySelector;
