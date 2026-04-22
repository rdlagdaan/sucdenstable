import { useEffect, useMemo, useRef, useState } from 'react';
import napi from '../../../../utils/axiosnapi';

type VesselRow = {
  id: number;
  mill_id: string;
  mill_name: string;
};

type CropYearRow = {
  id: number;
  crop_year: string;
  begin_year: string;
  end_year: string;
};

type SearchableDropdownProps<T> = {
  label: string;
  placeholder: string;
  value: string;
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
      <label className="block text-sm font-medium text-gray-700 mb-1">
        {label}
      </label>

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
                  <td
                    colSpan={columns.length}
                    className="px-3 py-3 text-center text-gray-500"
                  >
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

export default function ExportDetailed() {
  const [companyId, setCompanyId] = useState<string>('');

  const [vessels, setVessels] = useState<VesselRow[]>([]);
  const [cropYears, setCropYears] = useState<CropYearRow[]>([]);

  const [vesselSearch, setVesselSearch] = useState<string>('');
  const [cropYearSearch, setCropYearSearch] = useState<string>('');

  const [selectedVesselId, setSelectedVesselId] = useState<string>('');
  const [selectedVesselText, setSelectedVesselText] = useState<string>('');

  const [selectedCropYearId, setSelectedCropYearId] = useState<string>('');
  const [selectedCropYearText, setSelectedCropYearText] = useState<string>('');

  const [asOfDate, setAsOfDate] = useState<string>('');

  const [loadingVessels, setLoadingVessels] = useState<boolean>(false);
  const [loadingCropYears, setLoadingCropYears] = useState<boolean>(false);
  const [submitting, setSubmitting] = useState<boolean>(false);

  const [errors, setErrors] = useState<{
    vessel_id?: string;
    crop_year_id?: string;
    as_of_date?: string;
  }>({});

  useEffect(() => {
    const storedCompanyId = localStorage.getItem('company_id');
    if (storedCompanyId) {
      setCompanyId(String(storedCompanyId));
      return;
    }

    const storedUser = localStorage.getItem('user');
    if (storedUser) {
      try {
        const parsed = JSON.parse(storedUser);
        setCompanyId(String(parsed?.company_id ?? ''));
      } catch {
        setCompanyId('');
      }
    }
  }, []);

  useEffect(() => {
    if (!companyId) return;

    let cancelled = false;

    const loadVessels = async () => {
      setLoadingVessels(true);
      try {
        const res = await napi.get('/export-detailed/vessels', {
          params: { company_id: companyId },
        });

        if (!cancelled) {
          setVessels(Array.isArray(res.data) ? res.data : []);
        }
      } catch (error) {
        console.error('Failed to load vessels:', error);
        if (!cancelled) setVessels([]);
      } finally {
        if (!cancelled) setLoadingVessels(false);
      }
    };

    const loadCropYears = async () => {
      setLoadingCropYears(true);
      try {
        const res = await napi.get('/export-detailed/crop-years', {
          params: { company_id: companyId },
        });

        if (!cancelled) {
          setCropYears(Array.isArray(res.data) ? res.data : []);
        }
      } catch (error) {
        console.error('Failed to load crop years:', error);
        if (!cancelled) setCropYears([]);
      } finally {
        if (!cancelled) setLoadingCropYears(false);
      }
    };

    loadVessels();
    loadCropYears();

    return () => {
      cancelled = true;
    };
  }, [companyId]);

  const filteredVessels = useMemo(() => {
    const q = vesselSearch.trim().toLowerCase();
    if (!q) return vessels;

    return vessels.filter((row) => {
      return (
        String(row.mill_id ?? '').toLowerCase().includes(q) ||
        String(row.mill_name ?? '').toLowerCase().includes(q)
      );
    });
  }, [vessels, vesselSearch]);

  const filteredCropYears = useMemo(() => {
    const q = cropYearSearch.trim().toLowerCase();
    if (!q) return cropYears;

    return cropYears.filter((row) => {
      return (
        String(row.crop_year ?? '').toLowerCase().includes(q) ||
        String(row.begin_year ?? '').toLowerCase().includes(q) ||
        String(row.end_year ?? '').toLowerCase().includes(q)
      );
    });
  }, [cropYears, cropYearSearch]);

  const validate = () => {
    const nextErrors: {
      vessel_id?: string;
      crop_year_id?: string;
      as_of_date?: string;
    } = {};

    if (!selectedVesselId) {
      nextErrors.vessel_id = 'Vessel is required.';
    }

    if (!selectedCropYearId) {
      nextErrors.crop_year_id = 'Crop Year is required.';
    }

    if (!asOfDate) {
      nextErrors.as_of_date = 'As Of Date is required.';
    }

    setErrors(nextErrors);
    return Object.keys(nextErrors).length === 0;
  };

  const handleGenerate = async () => {
    if (!validate()) return;

    try {
      setSubmitting(true);

      const url =
        `/api/export-detailed/generate` +
        `?company_id=${encodeURIComponent(companyId)}` +
        `&vessel_id=${encodeURIComponent(selectedVesselId)}` +
        `&crop_year_id=${encodeURIComponent(selectedCropYearId)}` +
        `&as_of_date=${encodeURIComponent(asOfDate)}` +
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
              <SearchableDropdown<VesselRow>
                label="Vessel:"
                placeholder={loadingVessels ? 'Loading vessels...' : 'Search Vessel'}
                value={selectedVesselId}
                displayValue={selectedVesselText}
                items={filteredVessels}
                columns={[
                  { key: 'mill_id', label: 'Vessel ID' },
                  { key: 'mill_name', label: 'Vessel Name' },
                ]}
                error={errors.vessel_id}
                disabled={loadingVessels}
                onSearch={(value) => {
                  setVesselSearch(value);
                  setSelectedVesselId('');
                  setSelectedVesselText(value);
                  setErrors((prev) => ({ ...prev, vessel_id: undefined }));
                }}
                onSelect={(item) => {
                  setSelectedVesselId(String(item.id));
                  setSelectedVesselText(String(item.mill_id ?? ''));
                  setVesselSearch(String(item.mill_id ?? ''));
                  setErrors((prev) => ({ ...prev, vessel_id: undefined }));
                }}
                getRowKey={(item) => item.id}
              />
            </div>

            <div className="md:col-span-4">
              <SearchableDropdown<CropYearRow>
                label="Crop Year:"
                placeholder={loadingCropYears ? 'Loading crop years...' : 'Search Crop Year'}
                value={selectedCropYearId}
                displayValue={selectedCropYearText}
                items={filteredCropYears}
                columns={[
                  { key: 'crop_year', label: 'Crop Year' },
                  { key: 'begin_year', label: 'Begin Year' },
                  { key: 'end_year', label: 'End Year' },
                ]}
                error={errors.crop_year_id}
                disabled={loadingCropYears}
                onSearch={(value) => {
                  setCropYearSearch(value);
                  setSelectedCropYearId('');
                  setSelectedCropYearText(value);
                  setErrors((prev) => ({ ...prev, crop_year_id: undefined }));
                }}
                onSelect={(item) => {
                  setSelectedCropYearId(String(item.id));
                  setSelectedCropYearText(String(item.crop_year ?? ''));
                  setCropYearSearch(String(item.crop_year ?? ''));
                  setErrors((prev) => ({ ...prev, crop_year_id: undefined }));
                }}
                getRowKey={(item) => item.id}
              />
            </div>

            <div className="md:col-span-3">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                As Of Date:
              </label>
              <input
                type="date"
                value={asOfDate}
                onChange={(e) => {
                  setAsOfDate(e.target.value);
                  setErrors((prev) => ({ ...prev, as_of_date: undefined }));
                }}
                className={`w-full rounded border px-3 py-2 ${
                  errors.as_of_date ? 'border-red-400' : 'border-gray-300'
                }`}
              />
              {errors.as_of_date && (
                <div className="mt-1 text-sm text-red-600">{errors.as_of_date}</div>
              )}
            </div>

            <div className="md:col-span-1 flex justify-start md:justify-end">
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
