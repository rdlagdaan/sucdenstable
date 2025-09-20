import  {useEffect, useMemo, useRef, useState} from 'react';

export type AttachedDropdownItem = Record<string, any>;

type Props = {
  value: string;
  displayValue?: string;
  readOnlyInput?: boolean;
  onChange: (val: string) => void;
  items: AttachedDropdownItem[];
  headers: string[];
  columns: string[];
  search?: string;
  onSearchChange?: (s: string) => void;
  inputClassName?: string;
  dropdownClassName?: string;
  columnWidths?: string[];
  placeholder?: string;
};

export default function AttachedDropdown({
  value,
  displayValue,
  readOnlyInput,
  onChange,
  items,
  headers,
  columns,
  search,
  onSearchChange,
  inputClassName,
  dropdownClassName,
  columnWidths,
  placeholder = '- -',
}: Props) {
  const [open, setOpen] = useState(false);
  const [localSearch, setLocalSearch] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);   // NEW

  const searchTerm = (search ?? localSearch).trim().toLowerCase();

  const filtered = useMemo(() => {
    if (!searchTerm) return items;
    return items.filter((row) =>
      columns.some((k) => String(row[k] ?? '').toLowerCase().includes(searchTerm)),
    );
  }, [items, columns, searchTerm]);

  // close on outside click / Esc
  useEffect(() => {
    const onDown = (e: MouseEvent | TouchEvent) => {
      const root = containerRef.current;
      if (!root) return;
      if (!root.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onDown, true);
    document.addEventListener('touchstart', onDown, true);
    document.addEventListener('keydown', onKey, true);
    return () => {
      document.removeEventListener('mousedown', onDown, true);
      document.removeEventListener('touchstart', onDown, true);
      document.removeEventListener('keydown', onKey, true);
    };
  }, []);

  // auto-focus the Search box whenever the dropdown opens
  useEffect(() => {
    if (open) {
      // wait for the input to mount
      setTimeout(() => searchRef.current?.focus(), 0);
    }
  }, [open]);

  const scheduleMaybeClose = () =>
    setTimeout(() => {
      const root = containerRef.current;
      if (root && !root.contains(document.activeElement)) setOpen(false);
    }, 0);

  return (
    <div className="relative w-full" ref={containerRef}>
      <input
        ref={inputRef}
        //value={value ?? ''}
        //onChange={(e) => onChange(e.target.value)}
        //onFocus={() => setOpen(true)}
        value={(displayValue ?? value) ?? ''}
        onChange={(e) => { if (!readOnlyInput) onChange(e.target.value); }}
        onFocus={() => setOpen(true)}
        onClick={() => setOpen(true)}                        // 👈 open even if already focused
        onKeyDown={(e) => {                                  // 👈 keyboard open
          if (e.key === 'ArrowDown' || e.key === 'Enter') {
            setOpen(true);
            // move cursor to Search when it opens
            setTimeout(() => searchRef.current?.focus(), 0);
            e.preventDefault();
          }
        }}
        readOnly={!!readOnlyInput}
        onBlur={scheduleMaybeClose}
        className={`w-full border rounded px-3 py-2 h-11 text-sm ${inputClassName || ''}`}
        placeholder={placeholder}
        autoComplete="off"
      />

      {open && (
        <div
          className={`absolute z-50 mt-1 min-w-full bg-white border rounded shadow ${dropdownClassName || ''}`}
          // Allow the search box to receive focus, but prevent the main input from blurring
          onMouseDown={(e) => {
            const el = e.target as HTMLElement;
            // if clicking inside the search input, DO NOT prevent default
            if (el.closest('input')) return;
            e.preventDefault();
          }}
        >
          {/* search */}
          <div className="p-2 border-b sticky top-0 bg-white">
            <input
              ref={searchRef}                           // NEW
              value={search ?? localSearch}
              onChange={(e) =>
                onSearchChange ? onSearchChange(e.target.value) : setLocalSearch(e.target.value)
              }
              onBlur={scheduleMaybeClose}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  const first = filtered[0];
                  if (first) {
                    onChange(String(first.code ?? first[columns[0]]));
                    setOpen(false);
                  }
                }
              }}
              className="w-full border px-2 py-1 rounded text-sm"
              placeholder="Search..."
            />
          </div>

          {/* header */}
          <div
            className="grid text-xs font-semibold text-gray-600 px-2 py-2 border-b"
            style={{
              gridTemplateColumns:
                columnWidths && columnWidths.length
                  ? columnWidths.join(' ')
                  : `repeat(${headers.length}, minmax(0, 1fr))`,
            }}
          >
            {headers.map((title) => (
              <div key={title} className="truncate pr-2">
                {title}
              </div>
            ))}
          </div>

          {/* rows */}
          <div className="max-h-72 overflow-auto">
            {filtered.length === 0 ? (
              <div className="px-3 py-3 text-sm text-gray-500">No results</div>
            ) : (
              filtered.map((row) => (
                <button
                  key={String(row.code ?? row[columns[0]])}
                  type="button"
                  className="grid w-full text-left px-2 py-1 hover:bg-gray-100 text-sm"
                  style={{
                    gridTemplateColumns:
                      columnWidths && columnWidths.length
                        ? columnWidths.join(' ')
                        : `repeat(${headers.length}, minmax(0, 1fr))`,
                  }}
                  /*onClick={() => {
                    onChange(String(row.code ?? row[columns[0]]));
                    setOpen(false);
                    inputRef.current?.focus();
                  }}*/

                  onMouseDown={(e) => {
                    e.preventDefault(); // keep main input from blurring; still handle selection
                    onChange(String(row.code ?? row[columns[0]]));
                    setOpen(false);     // ✅ closes immediately on single click
                  }}

                >
                  {columns.map((k, i) => (
                    <div key={`${row.code ?? row[columns[0]]}-${i}`} className="truncate pr-2">
                      {String(row[k] ?? '')}
                    </div>
                  ))}
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
