import { useEffect, useRef, useState } from 'react';
import napi from '../../../utils/axiosnapi';
import { getCsrfToken } from '../../../utils/csrf';
import Cookies from 'js-cookie';
import { PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';

interface Row {
  id: number;
  crop_year: string;
  begin_year: string;
  end_year: string;
  company_id?: number;
  created_at?: string;
  updated_at?: string;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
}

const CropYear = () => {
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [rows, setRows] = useState<Row[]>([]);
  const [formData, setFormData] = useState<{ crop_year: string; begin_year: string; end_year: string }>({
    crop_year: '',
    begin_year: '',
    end_year: '',
  });
  const [editId, setEditId] = useState<number | null>(null);
  const [formMode, setFormMode] = useState<'add' | 'edit'>('add');

  const [perPage, setPerPage] = useState(5);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const companyId = Number(localStorage.getItem('company_id') || 0);

  const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Debounce search
  useEffect(() => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    searchTimeout.current = setTimeout(() => setDebouncedSearch(searchQuery), 300);
    return () => { if (searchTimeout.current) clearTimeout(searchTimeout.current); };
  }, [searchQuery]);

  // Initial + debounced fetch
  useEffect(() => {
    const init = async () => {
      try {
        const pageSize = perPage;
        await fetchRows(1, pageSize, debouncedSearch);
      } catch {
        await fetchRows(1, 5, debouncedSearch);
      }
    };
    if (companyId > 0) init();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, companyId]);

  const parsePayload = (payload: any): Paginated<Row> => {
    if (Array.isArray(payload)) {
      return { data: payload as Row[], current_page: 1, last_page: 1 };
    }
    return {
      data: Array.isArray(payload?.data) ? (payload.data as Row[]) : [],
      current_page: Number(payload?.current_page ?? 1),
      last_page: Number(payload?.last_page ?? 1),
    };
  };

  const fetchRows = async (page = 1, pageSize = perPage, search = '') => {
    if (!companyId) return;
    setIsLoading(true);
    try {
      const res = await napi.get('/crop-years/admin', {
        params: { per_page: pageSize, page, search, company_id: companyId },
      });
      const p = parsePayload(res.data);
      setRows(p.data);
      setTotalPages(p.last_page);
      setCurrentPage(p.current_page);
      setPerPage(pageSize);
    } catch (e) {
      console.error('Failed to load crop years', e);
      setRows([]);
      setTotalPages(1);
      setCurrentPage(1);
    } finally {
      setIsLoading(false);
    }
  };

  const resetForm = () => {
    setFormData({ crop_year: '', begin_year: '', end_year: '' });
    setEditId(null);
    setFormMode('add');
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!companyId) return alert('Company is not selected.');
    await getCsrfToken();
    const headers = { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' };
    const payload = { ...formData, company_id: companyId };

    try {
      if (editId) {
        await napi.put(`/crop-years/admin/${editId}`, payload, { headers, withCredentials: true });
        alert('Crop year updated');
      } else {
        await napi.post('/crop-years/admin', payload, { headers, withCredentials: true });
        alert('Crop year added');
      }
      resetForm();
      await fetchRows(currentPage, perPage, debouncedSearch);
    } catch (err: any) {
      alert(err?.response?.data?.message || 'Save failed');
    }
  };

  const handleEdit = (row: Row) => {
    setEditId(row.id);
    setFormData({ crop_year: row.crop_year, begin_year: row.begin_year, end_year: row.end_year });
    setFormMode('edit');
  };

  const handleCancel = () => {
    resetForm();
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this crop year?')) return;
    await getCsrfToken();
    const headers = { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' };
    try {
      await napi.delete(`/crop-years/admin/${id}`, {
        headers,
        withCredentials: true,
        params: { company_id: companyId },
      });
      alert('Deleted');
      const nextPage = rows.length === 1 && currentPage > 1 ? currentPage - 1 : currentPage;
      await fetchRows(nextPage, perPage, debouncedSearch);
    } catch {
      alert('Delete failed');
    }
  };

  const safeRows = Array.isArray(rows) ? rows : [];

  return (
    <div className="p-6 bg-gray-50 min-h-full">
      <h2 className="text-lg font-semibold mb-4">{editId ? 'Update Crop Year' : 'Add Crop Year'}</h2>

      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-4 gap-2 mb-6">
        <input
          type="text"
          name="crop_year"
          placeholder="e.g., 2024-2025"
          value={formData.crop_year}
          onChange={(e) => setFormData({ ...formData, crop_year: e.target.value })}
          className="w-full border rounded px-3 py-2 text-gray-800"
          required
        />
        <input
          type="text"
          name="begin_year"
          placeholder="Begin (e.g., 2024)"
          value={formData.begin_year}
          onChange={(e) => setFormData({ ...formData, begin_year: e.target.value })}
          className="w-full border rounded px-3 py-2 text-gray-800"
          required
        />
        <input
          type="text"
          name="end_year"
          placeholder="End (e.g., 2025)"
          value={formData.end_year}
          onChange={(e) => setFormData({ ...formData, end_year: e.target.value })}
          className="w-full border rounded px-3 py-2 text-gray-800"
          required
        />
        <div className="flex gap-2">
          <button type="submit" className="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-500">
            {formMode === 'edit' ? 'Update' : 'Submit'}
          </button>
          {formMode === 'edit' && (
            <button type="button" onClick={handleCancel} className="bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500">
              Cancel
            </button>
          )}
        </div>
      </form>

      {/* Search */}
      <div className="mb-4">
        <input
          type="text"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          placeholder="Search crop years…"
          className="w-full border rounded px-3 py-2 text-gray-800"
        />
      </div>

      {isLoading && (
        <div className="flex justify-center my-4">
          <div className="w-6 h-6 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        </div>
      )}

      {!isLoading && (
        <table className="w-full text-left border border-gray-200 bg-white rounded shadow">
          <thead className="bg-gray-100 text-gray-600">
            <tr>
              <th className="p-2">#</th>
              <th className="p-2">Crop Year</th>
              <th className="p-2">Begin</th>
              <th className="p-2">End</th>
              <th className="p-2 w-24 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            {safeRows.map((r, i) => (
              <tr key={r.id} className="border-t border-gray-100">
                <td className="p-2">{(currentPage - 1) * perPage + i + 1}</td>
                <td className="p-2">{r.crop_year}</td>
                <td className="p-2">{r.begin_year}</td>
                <td className="p-2">{r.end_year}</td>
                <td className="p-2 flex justify-center gap-2">
                  <button
                    onClick={() => handleEdit(r)}
                    className="text-blue-500 hover:text-blue-700"
                    title="Edit"
                  >
                    <PencilSquareIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => handleDelete(r.id)}
                    className="text-red-500 hover:text-red-700"
                    title="Delete"
                  >
                    <TrashIcon className="h-5 w-5" />
                  </button>
                </td>
              </tr>
            ))}
            {safeRows.length === 0 && (
              <tr><td className="p-3 text-gray-500" colSpan={5}>No results.</td></tr>
            )}
          </tbody>
        </table>
      )}

      <div className="flex justify-between mt-4 text-sm text-gray-700">
        <button onClick={() => fetchRows(1)} disabled={currentPage === 1} className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50">First</button>
        <button onClick={() => fetchRows(currentPage - 1)} disabled={currentPage === 1} className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50">Previous</button>
        <span className="self-center">Page {currentPage} of {totalPages}</span>
        <button onClick={() => fetchRows(currentPage + 1)} disabled={currentPage === totalPages} className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50">Next</button>
        <button onClick={() => fetchRows(totalPages)} disabled={currentPage === totalPages} className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50">Last</button>
      </div>
    </div>
  );
};

export default CropYear;
