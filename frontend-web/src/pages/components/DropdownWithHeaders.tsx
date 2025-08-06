import { Fragment, useMemo, useRef, useState, useLayoutEffect } from 'react';
import { Listbox, Transition } from '@headlessui/react';
import { createPortal } from 'react-dom';

export interface DropdownItem {
  code: string;
  label?: string;
  description?: string;
  [key: string]: any;
}




interface Props {
  label: string;
  value: string;
  onChange: (val: string) => void;
  items: DropdownItem[];
  search?: string;
  onSearchChange?: (val: string) => void;
  headers?: string[];
  dropdownPositionStyle?: React.CSSProperties;
  columnWidths?: string[];
  customKey?: string;
}


  const scalingFactors: Record<string, number> = {
    id: 0.2,
    pbn_number: 1.0,
    sugar_type: 0.5,
    //vend_code: 1.0,
    vendor_name: 1.5,
    crop_year: 0.8,
    pbn_date: 1.0,
  };

function toTitleCase(str: string) {
  return str
    .replace(/_/g, ' ')
    .toLowerCase()
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function computeDynamicWidths(
  items: DropdownItem[],
  headers: string[],
  min = 50,
  max = 500
): string[] {


  return headers.map((header, colIndex) => {
    const key = Object.keys(items[0] || {})[colIndex] || '';
    const factor = scalingFactors[key.toLowerCase()] ?? 1;

    const maxLen = Math.max(
      header.length,
      ...items.map(item => (item[key]?.toString().length || 0))
    );

    const estimated = Math.round(Math.min(Math.max(min, maxLen * 8 * factor), max));
    return `${estimated}px`;
  });
}





export default function DropdownWithHeaders({
  label,
  value,
  onChange,
  items,
  search = '',
  onSearchChange,
  headers = ['Code', 'Label', 'Description'],
  dropdownPositionStyle, // ✅ include this!
  columnWidths: inputColumnWidths,
  customKey = '',
}: Props) {
  const [position, setPosition] = useState({ top: 0, left: 0, width: 0 });
  const buttonRef = useRef<HTMLButtonElement>(null);

  const safeValue = value ?? '';

  const selectedItem: DropdownItem = useMemo(() => {
    return (
      items.find((item) => item.code === safeValue) || {
        code: '',
        label: '',
        description: '',
      }
    );
  }, [safeValue, items]);

  useLayoutEffect(() => {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (rect) {
      setPosition({
        top: rect.bottom + window.scrollY + 4,
        left: rect.left + window.scrollX,
        width: rect.width < 720 ? rect.width : 720, // max width cap
      });
    }
  }, [items.length, search]);

// ✅ Detect if this is the special "PBN No" dropdown
const isPbnDropdown = headers.includes('PBN #');

/*const getDynamicColumnWidths = (): string[] => {
  if (!isPbnDropdown || items.length === 0) return [];

  return headers.map((header) => {
    const key = header
      .toLowerCase()
      .replace(/\s+/g, '_')
      .replace(/[^a-z0-9_]/gi, '');

    const factor = scalingFactors[key] ?? 1;

    const maxLen = Math.max(
      header.length,
      ...items.map((item) =>
        item[key] ? item[key].toString().length : 0
      )
    );

    const estimated = Math.round(Math.min(Math.max(50, maxLen * 8 * factor), 300));
    return `${estimated}px`;
  });
};*/

//const dynamicWidths: string[] = getDynamicColumnWidths();
const dynamicWidths: string[] = useMemo(() => {
  if (!isPbnDropdown || items.length === 0) return [];

  return headers.map((header) => {
    const key = header
      .toLowerCase()
      .replace(/\s+/g, '_')
      .replace(/[^a-z0-9_]/gi, '');

    const factor = scalingFactors[key] ?? 1;

    const maxLen = Math.max(
      header.length,
      ...items.map((item) =>
        item[key] ? item[key].toString().length : 0
      )
    );

    const estimated = Math.round(Math.min(Math.max(50, maxLen * 8 * factor), 500));
    return `${estimated}px`;
  });
}, [items, headers]);


  const effectiveColumnWidths = useMemo(() => {
    if (label === '' && items.length > 0) {
      return computeDynamicWidths(items, headers);
    }
    return inputColumnWidths;
  }, [label, items, headers, inputColumnWidths]);



  return (
    <div className="w-full z-50">
      <label className="block mb-1 text-sm font-medium text-gray-700">{label}</label>
      <Listbox value={safeValue} onChange={onChange}>
        {({ open }) => (
          <div className="relative z-50">
            
            <Listbox.Button
              ref={buttonRef}
              className="block w-full min-w-0 rounded border p-2 text-left bg-green-100 text-sm truncate"
            >
             {selectedItem.code} - {selectedItem.label} - {selectedItem.description}
            </Listbox.Button>





            {open &&
              createPortal(
                <Transition
                  as={Fragment}
                  leave="transition ease-in duration-100"
                  leaveFrom="opacity-100"
                  leaveTo="opacity-0"
                >
                  <Listbox.Options
                    className={`absolute max-h-60 overflow-auto rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 z-[9999] text-sm`}
                    style={{
                      top: `${position.top}px`,
                      left: `${position.left}px`,
                      width: dropdownPositionStyle?.width || `${position.width}px`,
                      position: 'absolute',
                      //whiteSpace: 'nowrap',
    whiteSpace: customKey === 'pbn' ? 'normal' : 'nowrap', // ✅ only "pbn" uses dynamic wrap
    tableLayout: customKey === 'pbn' ? 'auto' : undefined, // ✅ table-style layout for PBN                      
                    }}
                  >



                    {onSearchChange && (
                      <div className="p-1 border-b bg-white sticky top-0 z-[9999]">
                        <input
                          type="text"
                          placeholder="Search..."
                          value={search}
                          onChange={(e) => onSearchChange(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === ' ' || e.key === 'Tab') {
                              e.stopPropagation(); // prevent Listbox from treating space/tab as navigation
                            }
                          }}
                          className="w-full border px-2 py-1 text-sm rounded"
                        />
                      </div>
                    )}

{/* 👇 INSERT HEADER ROW RIGHT HERE */}
  <div
    className="text-xs font-semibold px-2 py-1 border-b bg-gray-100"
    style={{ 
        display: 'grid', 
        //gridTemplateColumns: `repeat(${headers.length}, minmax(0, 1fr))` 
        //gridTemplateColumns: columnWidths
        gridTemplateColumns: isPbnDropdown
  ? dynamicWidths.join(' ')
  : effectiveColumnWidths
    ? effectiveColumnWidths.join(' ')
    : `repeat(${headers.length}, minmax(0, 1fr))`,    
        //minWidth: '100%',
    }}
  >
  {headers.map((h, i) => (
    <div key={i} className="truncate pr-2">{toTitleCase(h)}</div>
    
  ))}


  </div>

  {/* options below... */}

{items.map((item) => (
  
  
  


<Listbox.Option 
  key={item.code} 
  value={item.code} as={Fragment}>
  {({ active }) => (
    <li
      className={`grid px-2 py-1 cursor-pointer text-sm ${
        active ? 'bg-blue-500 text-white' : 'text-gray-900'
      }`}
      style={{ 
        gridTemplateColumns: isPbnDropdown 
          ? dynamicWidths.join(' ') 
          : effectiveColumnWidths 
            ? effectiveColumnWidths.join(' ') 
            : `repeat(${headers.length}, minmax(0, 1fr))` 
      }}
    >
      {headers.map((_, colIndex) => {
        const keys = Object.keys(item);
        const dataKey = keys[colIndex] ?? '';
        return (
          <div key={colIndex} className="truncate pr-2 whitespace-normal">
            {item[dataKey]}
          </div>
        );
      })}
    </li>
  )}
</Listbox.Option>




))}

                  </Listbox.Options>
                </Transition>,
                document.body
              )}
          </div>
        )}
      </Listbox>
    </div>
  );
}
