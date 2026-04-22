import { useEffect, useMemo, useState } from 'react';
import napi from '../../../../utils/axiosnapi';

type CropYearRow = {
  id: number;
  crop_year: string;
  begin_year: string;
  end_year: string;
};

export default function ScheduleOfInventory() {
  const [companyId, setCompanyId] = useState<string>('');
  const [cropYears, setCropYears] = useState<CropYearRow[]>([]);
  const [cropYearId, setCropYearId] = useState<string>('');
  const [asOfDate, setAsOfDate] = useState<string>('');
  const [loadingCropYears, setLoadingCropYears] = useState<boolean>(false);
  const [submitting, setSubmitting] = useState<boolean>(false);
  const [errors, setErrors] = useState<{ crop_year_id?: string; as_of_date?: string }>({});

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

    const loadCropYears = async () => {
      setLoadingCropYears(true);
      try {
        const res = await napi.get('/schedule-of-inventory/crop-years', {
          params: { company_id: companyId },
        });

        if (!cancelled) {
          setCropYears(Array.isArray(res.data) ? res.data : []);
        }
      } catch (error) {
        console.error('Failed to load crop years:', error);
        if (!cancelled) {
          setCropYears([]);
        }
      } finally {
        if (!cancelled) {
          setLoadingCropYears(false);
        }
      }
    };

    loadCropYears();

    return () => {
      cancelled = true;
    };
  }, [companyId]);

  const cropYearOptions = useMemo(() => {
    return cropYears.map((row) => ({
      value: String(row.id),
      label: `${row.crop_year}`,
    }));
  }, [cropYears]);

  const validate = () => {
    const nextErrors: { crop_year_id?: string; as_of_date?: string } = {};

    if (!cropYearId) {
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
        `/api/schedule-of-inventory/generate` +
        `?company_id=${encodeURIComponent(companyId)}` +
        `&crop_year_id=${encodeURIComponent(cropYearId)}` +
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
          <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-center">
            <div className="md:col-span-4">
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Crop Year:
              </label>
              <select
                value={cropYearId}
                onChange={(e) => {
                  setCropYearId(e.target.value);
                  setErrors((prev) => ({ ...prev, crop_year_id: undefined }));
                }}
                className={`w-full rounded border px-3 py-2 bg-white ${
                  errors.crop_year_id ? 'border-red-400' : 'border-gray-300'
                }`}
                disabled={loadingCropYears}
              >
                <option value="">{loadingCropYears ? 'Loading...' : 'Select Crop Year'}</option>
                {cropYearOptions.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
              {errors.crop_year_id && (
                <div className="mt-1 text-sm text-red-600">{errors.crop_year_id}</div>
              )}
            </div>

            <div className="md:col-span-4">
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

            <div className="md:col-span-4 flex md:justify-end items-end">
              <button
                type="button"
                onClick={handleGenerate}
                disabled={submitting}
                className="inline-flex items-center justify-center rounded border border-gray-400 bg-gray-100 px-6 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 disabled:opacity-60"
              >
                {submitting ? 'Generating...' : 'Generate'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}