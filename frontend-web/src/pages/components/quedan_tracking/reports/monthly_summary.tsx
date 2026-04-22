import { useEffect, useMemo, useRef, useState } from 'react';
import napi from '../../../../utils/axiosnapi';

type YearRow = {
  id: number;
  year_value: string;
};

type MonthRow = {
  id: number;
  month_num: string;
  month_desc: string;
};

type SearchableDropdownProps<T> = {
  label: string;
  placeholder: string;
  displayValue: string;
  items: T[];
  columns: { key: keyof T; label: string }[];
  error?: string;
  disabled?: boolean;
  onSearch: (value: string) => void;
  onSelect: (item: T) => void;
  getRowKey: (item: T) => string | number;
};

function SearchableDropdown<T extends Record<string, any>>({
  label,
  placeholder,
  displayValue,
  items,
  columns,
  error,
  disabled,
  onSearch,
  onSelect,
  getRowKey,
}: SearchableDropdownProps<T>) {
  const [open, setOpen] = useState(false);
  const wrapperRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (!wrapperRef.current) return;
      if (!wrapperRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div className="relative w-full" ref={wrapperRef}>
      <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>

      <div className="relative">
        <input
          type="text"
          value={displayValue}
          placeholder={placeholder}
          disabled={disabled}
          onChange={(e) => {
            onSearch(e.target.value);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          className={`w-full rounded border px-3 py-2 pr-10 bg-white ${
            error ? 'border-red-400' : 'border-gray-300'
          } ${disabled ? 'bg-gray-100 cursor-not-allowed' : ''}`}
        />

        <button
          type="button"
          onClick={() => !disabled && setOpen((prev) => !prev)}
          className="absolute inset-y-0 right-0 px-3 text-gray-500"
          disabled={disabled}
        >
          ▼
        </button>
      </div>

      {error && <div className="mt-1 text-sm text-red-600">{error}</div>}

      {open && !disabled && (
        <div className="absolute z-50 mt-1 w-full border border-gray-300 bg-white shadow-lg max-h-72 overflow-auto">
          <table className="w-full text-sm border-collapse">
            <thead className="sticky top-0 bg-gray-100">
              <tr>
                {columns.map((col) => (
                  <th
                    key={String(col.key)}
                    className="border-b border-gray-300 px-3 py-2 text-left font-semibold text-gray-700"
                  >
                    {col.label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {items.length > 0 ? (
                items.map((item) => (
                  <tr
                    key={String(getRowKey(item))}
                    className="cursor-pointer hover:bg-blue-50"
                    onClick={() => {
                      onSelect(item);
                      setOpen(false);
                    }}
                  >
                    {columns.map((col) => (
                      <td
                        key={String(col.key)}
                        className="border-b border-gray-200 px-3 py-2"
                      >
                        {String(item[col.key] ?? '')}
                      </td>
                    ))}
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={columns.length} className="px-3 py-3 text-center text-gray-500">
                    No records found.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default function MonthlySummary() {
  const [years, setYears] = useState<YearRow[]>([]);
  const [months, setMonths] = useState<MonthRow[]>([]);

  const [yearSearch, setYearSearch] = useState('');
  const [monthSearch, setMonthSearch] = useState('');

  const [selectedYearId, setSelectedYearId] = useState('');
  const [selectedYearText, setSelectedYearText] = useState('');

  const [selectedMonthId, setSelectedMonthId] = useState('');
  const [selectedMonthText, setSelectedMonthText] = useState('');

  const [loadingYears, setLoadingYears] = useState(false);
  const [loadingMonths, setLoadingMonths] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [errors, setErrors] = useState<{
    year_id?: string;
    month_id?: string;
  }>({});

  useEffect(() => {
    let cancelled = false;

    const loadYears = async () => {
      setLoadingYears(true);
      try {
        const res = await napi.get('/monthly-summary/years');
        if (!cancelled) setYears(Array.isArray(res.data) ? res.data : []);
      } catch (error) {
        console.error('Failed to load years:', error);
        if (!cancelled) setYears([]);
      } finally {
        if (!cancelled) setLoadingYears(false);
      }
    };

    const loadMonths = async () => {
      setLoadingMonths(true);
      try {
        const res = await napi.get('/monthly-summary/months');
        if (!cancelled) setMonths(Array.isArray(res.data) ? res.data : []);
      } catch (error) {
        console.error('Failed to load months:', error);
        if (!cancelled) setMonths([]);
      } finally {
        if (!cancelled) setLoadingMonths(false);
      }
    };

    loadYears();
    loadMonths();

    return () => {
      cancelled = true;
    };
  }, []);

  const filteredYears = useMemo(() => {
    const q = yearSearch.trim().toLowerCase();
    if (!q) return years;
    return years.filter((row) => String(row.year_value ?? '').toLowerCase().includes(q));
  }, [years, yearSearch]);

  const filteredMonths = useMemo(() => {
    const q = monthSearch.trim().toLowerCase();
    if (!q) return months;
    return months.filter((row) =>
      String(row.month_num ?? '').toLowerCase().includes(q) ||
      String(row.month_desc ?? '').toLowerCase().includes(q)
    );
  }, [months, monthSearch]);

  const validate = () => {
    const nextErrors: { year_id?: string; month_id?: string } = {};

    if (!selectedYearId) nextErrors.year_id = 'Year is required.';
    if (!selectedMonthId) nextErrors.month_id = 'Month is required.';

    setErrors(nextErrors);
    return Object.keys(nextErrors).length === 0;
  };

  const handleGenerate = async () => {
    if (!validate()) return;

    try {
      setSubmitting(true);

      const url =
        `/api/monthly-summary/generate` +
        `?year_id=${encodeURIComponent(selectedYearId)}` +
        `&month_id=${encodeURIComponent(selectedMonthId)}` +
        `&_=${Date.now()}`;

      window.open(url, '_blank', 'noopener,noreferrer');
    } catch (error) {
      console.error('Generate failed:', error);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="w-full">
      <div className="border border-gray-300 bg-white shadow-sm">
        <div className="bg-slate-100 border-b border-gray-300 px-4 py-3">
          <h2 className="text-xl font-bold text-slate-700 uppercase tracking-wide">
            Inventory Reports List
          </h2>
        </div>

        <div className="px-4 py-6">
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
            <div className="md:col-span-4">
              <SearchableDropdown<YearRow>
                label="Year:"
                placeholder={loadingYears ? 'Loading year...' : 'Search Year'}
                displayValue={selectedYearText}
                items={filteredYears}
                columns={[
                  { key: 'year_value', label: 'Crop Year' },
                ]}
                error={errors.year_id}
                disabled={loadingYears}
                onSearch={(value) => {
                  setYearSearch(value);
                  setSelectedYearId('');
                  setSelectedYearText(value);
                  setErrors((prev) => ({ ...prev, year_id: undefined }));
                }}
                onSelect={(item) => {
                  setSelectedYearId(String(item.id));
                  setSelectedYearText(String(item.year_value ?? ''));
                  setYearSearch(String(item.year_value ?? ''));
                  setErrors((prev) => ({ ...prev, year_id: undefined }));
                }}
                getRowKey={(item) => item.id}
              />
            </div>

            <div className="md:col-span-6">
              <SearchableDropdown<MonthRow>
                label="Month:"
                placeholder={loadingMonths ? 'Loading month...' : 'Search Month'}
                displayValue={selectedMonthText}
                items={filteredMonths}
                columns={[
                  { key: 'month_num', label: 'Month No' },
                  { key: 'month_desc', label: 'Month Description' },
                ]}
                error={errors.month_id}
                disabled={loadingMonths}
                onSearch={(value) => {
                  setMonthSearch(value);
                  setSelectedMonthId('');
                  setSelectedMonthText(value);
                  setErrors((prev) => ({ ...prev, month_id: undefined }));
                }}
                onSelect={(item) => {
                  setSelectedMonthId(String(item.id));
                  setSelectedMonthText(String(item.month_desc ?? ''));
                  setMonthSearch(String(item.month_desc ?? ''));
                  setErrors((prev) => ({ ...prev, month_id: undefined }));
                }}
                getRowKey={(item) => item.id}
              />
            </div>

            <div className="md:col-span-2 flex justify-start md:justify-end">
              <button
                type="button"
                onClick={handleGenerate}
                disabled={submitting}
                className="inline-flex items-center justify-center rounded border border-gray-400 bg-gray-100 px-6 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 disabled:opacity-60"
              >
                {submitting ? '...' : 'Generate'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
