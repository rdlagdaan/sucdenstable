import { useEffect, useRef, useState } from 'react';
import napi from '../../../utils/axiosnapi';
import { getCsrfToken } from '../../../utils/csrf';
import Cookies from 'js-cookie';
import { PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';

interface Company {
  id: number;
  company_name: string;
  logo: string | null;
}

const CompanySettings = () => {
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const [formData, setFormData] = useState<{ company_name: string; logo: string }>({
    company_name: '',
    logo: '',
  });
  const [companies, setCompanies] = useState<Company[]>([]);
  const [editId, setEditId] = useState<number | null>(null);
  const [formMode, setFormMode] = useState<'add' | 'edit'>('add');

  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [perPage, setPerPage] = useState(5);

  const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

  // debounce search
  useEffect(() => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    searchTimeout.current = setTimeout(() => setDebouncedSearchQuery(searchQuery), 300);
    return () => { if (searchTimeout.current) clearTimeout(searchTimeout.current); };
  }, [searchQuery]);

  // load page size + fetch
  useEffect(() => {
    const init = async () => {
      try {
        const setting = await napi.get('/settings/paginaterecs');
        const pageSize = setting.data.value || 5;
        setPerPage(pageSize);
        fetchCompanies(1, pageSize, debouncedSearchQuery);
      } catch {
        fetchCompanies(1, 5, debouncedSearchQuery);
      }
    };
    init();
  }, [debouncedSearchQuery]);

  const fetchCompanies = async (page = 1, perPageOverride = perPage, search = '') => {
    setIsLoading(true);
    try {
      // napi base is assumed to point at /api
      const res = await napi.get(
        `/company-settings?per_page=${perPageOverride}&page=${page}&search=${encodeURIComponent(search)}`
      );
      setCompanies(res.data.data);
      setTotalPages(res.data.last_page);
      setCurrentPage(res.data.current_page);
    } catch (e) {
      console.error('Failed to load companies', e);
    } finally {
      setIsLoading(false);
    }
  };

  const resetForm = () => {
    setFormData({ company_name: '', logo: '' });
    setEditId(null);
    setFormMode('add');
  };

  const handleCancelEdit = () => {
    resetForm();
    fetchCompanies(currentPage, perPage, searchQuery);
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await getCsrfToken();
    const headers = { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' };

    try {
      if (editId) {
        await napi.put(`/company-settings/${editId}`, formData, { headers, withCredentials: true });
        alert('Company updated');
      } else {
        await napi.post('/company-settings', formData, { headers, withCredentials: true });
        alert('Company added');
      }
      resetForm();
      fetchCompanies(currentPage, perPage, searchQuery);
    } catch (error: any) {
      alert(error?.response?.data?.message || 'Error saving company');
    }
  };

  const handleEdit = (c: Company) => {
    setEditId(c.id);
    setFormData({ company_name: c.company_name, logo: c.logo || '' });
    setFormMode('edit');
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Delete this company?')) return;
    await getCsrfToken();
    const headers = { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' };
    try {
      await napi.delete(`/company-settings/${id}`, { headers, withCredentials: true });
      alert('Company deleted');
      fetchCompanies(currentPage, perPage, searchQuery);
    } catch {
      alert('Delete failed');
    }
  };

  return (
    <div className="p-6 bg-gray-50 min-h-full">
      <h2 className="text-lg font-semibold mb-4">
        {formMode === 'edit' ? 'Update Company' : 'Add New Company'}
      </h2>

      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
        <input
          type="text"
          name="company_name"
          placeholder="Company name"
          value={formData.company_name}
          onChange={handleChange}
          className="border rounded px-3 py-2 text-gray-800"
          required
        />
        <input
          type="text"
          name="logo"
          placeholder="Logo filename (optional)"
          value={formData.logo}
          onChange={handleChange}
          className="border rounded px-3 py-2 text-gray-800"
        />
        <div className="flex gap-2">
          <button type="submit" className="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-500">
            {formMode === 'edit' ? 'Update' : 'Submit'}
          </button>
          {formMode === 'edit' && (
            <button
              type="button"
              onClick={handleCancelEdit}
              className="bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500"
            >
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
          placeholder="Search companies..."
          className="w-full border rounded px-3 py-2 text-gray-800"
        />
      </div>

      {/* Loading */}
      {isLoading && (
        <div className="flex justify-center my-4">
          <div className="w-6 h-6 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        </div>
      )}

      {/* Table */}
      {!isLoading && (
        <table className="w-full text-left border border-gray-200 bg-white rounded shadow">
          <thead className="bg-gray-100 text-gray-600">
            <tr>
              <th className="p-2">#</th>
              <th className="p-2">Company</th>
              <th className="p-2">Logo</th>
              <th className="p-2 w-24 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            {companies.map((c, i) => (
              <tr key={c.id} className="border-t border-gray-100">
                <td className="p-2">{(currentPage - 1) * perPage + i + 1}</td>
                <td className="p-2">{c.company_name}</td>
                <td className="p-2">{c.logo || ''}</td>
                <td className="p-2 flex justify-center gap-2">
                  <button
                    onClick={() => handleEdit(c)}
                    className="text-blue-500 hover:text-blue-700"
                    title="Edit"
                  >
                    <PencilSquareIcon className="h-5 w-5" />
                  </button>
                  <button
                    onClick={() => handleDelete(c.id)}
                    className="text-red-500 hover:text-red-700"
                    title="Delete"
                  >
                    <TrashIcon className="h-5 w-5" />
                  </button>
                </td>
              </tr>
            ))}
            {companies.length === 0 && (
              <tr><td className="p-3 text-gray-500" colSpan={4}>No companies found.</td></tr>
            )}
          </tbody>
        </table>
      )}

      {/* Pagination */}
      <div className="flex justify-between mt-4 text-sm text-gray-700">
        <button
          onClick={() => fetchCompanies(1)}
          disabled={currentPage === 1}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          First
        </button>
        <button
          onClick={() => fetchCompanies(currentPage - 1)}
          disabled={currentPage === 1}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Previous
        </button>
        <span className="self-center">Page {currentPage} of {totalPages}</span>
        <button
          onClick={() => fetchCompanies(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Next
        </button>
        <button
          onClick={() => fetchCompanies(totalPages)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Last
        </button>
      </div>
    </div>
  );
};

export default CompanySettings;
