// DropdownWithHeaders.tsx
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
  label?: string;
  value: string;
  onChange: (val: string) => void;
  items: DropdownItem[];
  search?: string;
  onSearchChange?: (val: string) => void;
  headers?: string[];
  dropdownPositionStyle?: React.CSSProperties;
  columnWidths?: string[];
  customKey?: string;
  inputClassName?: string;
}

const scalingFactors: Record<string, number> = {
  id: 0.2,
  pbn_number: 1.0,
  sugar_type: 0.5,
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
  return headers.map((_, colIndex) => {
    const key = Object.keys(items[0] || {})[colIndex] || '';
    const factor = scalingFactors[key.toLowerCase()] ?? 1;
    const maxLen = Math.max(
      (headers[colIndex] || '').length,
      ...items.map((item) => (item[key]?.toString().length || 0))
    );
    const estimated = Math.round(Math.min(Math.max(min, maxLen * 8 * factor), max));
    return `${estimated}px`;
  });
}

export default function DropdownWithHeaders({
  label = '',
  value,
  onChange,
  items,
  search = '',
  onSearchChange,
  headers = ['Code', 'Label', 'Description'],
  dropdownPositionStyle,
  columnWidths: inputColumnWidths,
  customKey = '',
  inputClassName,
}: Props) {
  const [position, setPosition] = useState({ top: 0, left: 0, width: 0 });
  const buttonRef = useRef<HTMLButtonElement>(null);

  const safeValue = value ?? '';
  const selectedItem: DropdownItem = useMemo(() => {
    return (
      items.find((item) => String(item.code) === String(safeValue)) || {
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
        width: rect.width,
      });
    }
  }, [items.length, search]);

  const isPbnDropdown = headers.includes('PBN #');

  // 🔎 NEW: local filtering of items using the `search` prop
  const displayedItems = useMemo(() => {
    const term = (search || '').trim().toLowerCase();
    if (!term) return items;

    // match if ANY string/number field contains the term
    return items.filter((it) =>
      Object.values(it).some((val) => {
        if (typeof val === 'string' || typeof val === 'number') {
          return String(val).toLowerCase().includes(term);
        }
        return false;
      })
    );
  }, [items, search]);

  // Use displayed items (if any) to compute widths so the header stays tight
  const widthBase = displayedItems.length ? displayedItems : items;

  const dynamicWidths: string[] = useMemo(() => {
    if (!isPbnDropdown || widthBase.length === 0) return [];
    return headers.map((header) => {
      const key = header.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/gi, '');
      const factor = scalingFactors[key] ?? 1;
      const maxLen = Math.max(
        header.length,
        ...widthBase.map((item) => (item[key] ? item[key].toString().length : 0))
      );
      const estimated = Math.round(Math.min(Math.max(50, maxLen * 8 * factor), 500));
      return `${estimated}px`;
    });
  }, [widthBase, headers, isPbnDropdown]);

  const effectiveColumnWidths = useMemo(() => {
    if (label === '' && widthBase.length > 0) {
      return computeDynamicWidths(widthBase, headers);
    }
    return inputColumnWidths;
  }, [label, widthBase, headers, inputColumnWidths]);

  return (
    <div className="w-full z-50">
      {label.trim() ? (
        <label className="block mb-1 text-sm font-medium text-gray-700">{label}</label>
      ) : null}

      <Listbox value={safeValue} onChange={onChange}>
        {({ open }) => (
          <div className="relative z-50">
            <Listbox.Button
              ref={buttonRef}
              className={`block w-full min-w-0 rounded border text-left truncate ${
                inputClassName || 'p-2 text-sm bg-white'
              }`}
            >
              {selectedItem.code}
              {selectedItem.label ? ` - ${selectedItem.label}` : ''}
              {selectedItem.description ? ` - ${selectedItem.description}` : ''}
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
                    className="absolute max-h-72 overflow-auto rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 z-[9999] text-sm"
                    style={{
                      top: `${position.top}px`,
                      left: `${position.left}px`,
                      width: dropdownPositionStyle?.width || `${position.width}px`,
                      position: 'absolute',
                      whiteSpace: customKey === 'pbn' ? 'normal' : 'nowrap',
                      tableLayout: customKey === 'pbn' ? 'auto' : undefined,
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
                            if (e.key === ' ' || e.key === 'Tab') e.stopPropagation();
                          }}
                          className="w-full border px-2 py-2 rounded"
                        />
                      </div>
                    )}

                    {/* header row */}
                    <div
                      className="text-xs font-semibold px-2 py-1 border-b bg-gray-100"
                      style={{
                        display: 'grid',
                        gridTemplateColumns: isPbnDropdown
                          ? dynamicWidths.join(' ')
                          : effectiveColumnWidths
                          ? effectiveColumnWidths.join(' ')
                          : `repeat(${headers.length}, minmax(0, 1fr))`,
                      }}
                    >
                      {headers.map((h, i) => (
                        <div key={i} className="truncate pr-2">
                          {toTitleCase(h)}
                        </div>
                      ))}
                    </div>

                    {/* rows */}
                    <div className="max-h-60 overflow-auto">
                      {displayedItems.length === 0 ? (
                        <div className="px-3 py-2 text-gray-500 text-sm">No results</div>
                      ) : (
                        displayedItems.map((item) => (
                          <Listbox.Option key={item.code} value={item.code} as={Fragment}>
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
                                    : `repeat(${headers.length}, minmax(0, 1fr))`,
                                }}
                              >
                                {headers.map((_, colIndex) => {
                                  const keys = Object.keys(item);
                                  const dataKey = keys[colIndex] ?? '';
                                  return (
                                    <div
                                      key={colIndex}
                                      className="truncate pr-2 whitespace-normal"
                                    >
                                      {item[dataKey]}
                                    </div>
                                  );
                                })}
                              </li>
                            )}
                          </Listbox.Option>
                        ))
                      )}
                    </div>
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
