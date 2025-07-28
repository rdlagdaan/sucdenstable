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
}

export default function DropdownWithHeaders({
  label,
  value,
  onChange,
  items,
  search = '',
  onSearchChange,
  headers = ['Code', 'Label', 'Description'],
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
        width: rect.width,
      });
    }
  }, [items.length, search]);

  const columnCount = headers.length;
  //const gridColsClass = `grid-cols-${columnCount}`;
  const columnWidth = 160; // Adjust width per column here
  const dropdownWidth = columnCount * columnWidth;

  return (
    <div className="w-full z-50">
      <label className="block mb-1 text-sm font-medium text-gray-700">{label}</label>
      <Listbox value={safeValue} onChange={onChange}>
        {({ open }) => (
          <div className="relative z-50">
            <Listbox.Button
              ref={buttonRef}
              className="w-full rounded border p-2 text-left bg-white text-sm truncate"
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
                      width: `${dropdownWidth}px`,
                      position: 'absolute',
                      whiteSpace: 'nowrap',
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
    style={{ display: 'grid', gridTemplateColumns: `repeat(${headers.length}, minmax(0, 1fr))` }}
  >
    {headers.map((h) => (
      <div key={h} className="truncate pr-2">{h}</div>
    ))}
  </div>

  {/* options below... */}

{items.map((item) => (
  <Listbox.Option key={item.code} value={item.code} as={Fragment}>
    {({ active }) => (
      <li
        className={`grid px-2 py-1 cursor-pointer text-sm ${
          active ? 'bg-blue-500 text-white' : 'text-gray-900'
        }`}
        style={{ gridTemplateColumns: `repeat(${headers.length}, minmax(0, 1fr))` }}
      >
{headers.map((_, index) => {
  const keys = Object.keys(item); // dynamically handles item keys
  const key = keys[index] ?? '';
  return (
    <div key={index} className="truncate pr-2">
      {item[key]}
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
