import { useEffect, useState, useRef } from 'react';
import napi from '../../../utils/axiosnapi';
import { getCsrfToken } from '../../../utils/csrf';
import Cookies from 'js-cookie';
import { PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';


interface Role {
  id: number;
  role: string;
  created_at: string;
  updated_at: string;
}

const Role = () => {
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');
  const [isLoading, setIsLoading] = useState(false);  
  const [formData, setFormData] = useState({ role: '' });
  const [roles, setRoles] = useState<Role[]>([]);
  const [editId, setEditId] = useState<number | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);


  const [perPage, setPerPage] = useState(5); // Default fallback
  const [formMode, setFormMode] = useState<'add' | 'edit'>('add');

  const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
/*useEffect(() => {
  const init = async () => {
    try {
      const setting = await napi.get('/api/settings/paginaterecs');
      setPerPage(setting.data.value || 5);
      fetchRoles(1, setting.data.value || 5);
    } catch (err) {
      fetchRoles(1, 5); // fallback if no setting found
    }
  };

  init();
}, []);*/

  // 1. Debounce search input
  useEffect(() => {
    if (searchTimeout.current) {
      clearTimeout(searchTimeout.current);
    }

    searchTimeout.current = setTimeout(() => {
      setDebouncedSearchQuery(searchQuery);
    }, 300);

    return () => {
      if (searchTimeout.current) {
        clearTimeout(searchTimeout.current);
      }
    };
  }, [searchQuery]);




  
  // 2. Fetch roles on debouncedSearchQuery change
  useEffect(() => {
    const init = async () => {
      try {
        const setting = await napi.get('/api/settings/paginaterecs');
        const pageSize = setting.data.value || 5;
        setPerPage(pageSize);
        fetchRoles(1, pageSize, debouncedSearchQuery);
      } catch (err) {
        fetchRoles(1, 5, debouncedSearchQuery);
      }
    };

    init();
  }, [debouncedSearchQuery]);




/*const fetchRoles = async (page = 1, perPageOverride = perPage, search = '') => {
  try {
    const res = await napi.get(`/api/roles?per_page=${perPageOverride}&page=${page}&search=${search}`);
    setRoles(res.data.data);
    setTotalPages(res.data.last_page);
    setCurrentPage(res.data.current_page);
  } catch (error) {
    console.error('Failed to load roles', error);
  }
};*/


const fetchRoles = async (page = 1, perPageOverride = perPage, search = '') => {
  setIsLoading(true);
  try {
    const res = await napi.get(`/api/roles?per_page=${perPageOverride}&page=${page}&search=${search}`);
    setRoles(res.data.data);
    setTotalPages(res.data.last_page);
    setCurrentPage(res.data.current_page);
  } catch (error) {
    console.error('Failed to load roles', error);
  } finally {
    setIsLoading(false);
  }
};




const handleCancelEdit = () => {
  setFormData({ role: '' });
  setEditId(null);
  setFormMode('add');
  fetchRoles(currentPage, perPage, searchQuery);
};

/*const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
  const query = e.target.value;
  setSearchQuery(query);
  fetchRoles(1, perPage, query); // always reset to page 1 when searching
};*/






  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
    fetchRoles(currentPage, perPage, searchQuery);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await getCsrfToken();

    try {
      if (editId) {
        await napi.put(`/api/roles/${editId}`, formData, {
          headers: { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' },
          withCredentials: true,
        });
        alert('Role updated');
        setEditId(null);
        setFormMode('add');
      } else {
        await napi.post('/api/roles', formData, {
          headers: { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' },
          withCredentials: true,
        });
        alert('Role added');
      }

      setFormData({ role: '' });
      //fetchRoles(currentPage);
      fetchRoles(currentPage, perPage, searchQuery);
    } catch (error: any) {
      alert(error?.response?.data?.message || 'Error saving role');
    }
  };

  const handleEdit = (role: Role) => {
    setEditId(role.id);
    setFormData({ role: role.role });
    setFormMode('edit'); // ✅ required
    fetchRoles(currentPage, perPage, searchQuery);
  };

  const handleDelete = async (id: number) => {
    if (!confirm('Are you sure you want to delete this role?')) return;
    await getCsrfToken();

    try {
      await napi.delete(`/api/roles/${id}`, {
        headers: { 'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '' },
        withCredentials: true,
      });
      alert('Role deleted');
      fetchRoles(currentPage);
    } catch (error) {
      alert('Delete failed');
    }
  };

  return (
    <div className="p-6 bg-gray-50 min-h-full">
      <h2 className="text-lg font-semibold mb-4">
        {editId ? 'Update Role' : 'Add New Role'}
      </h2>

    <form onSubmit={handleSubmit} className="flex gap-2 mb-6">
      <input
        type="text"
        name="role"
        placeholder="Enter role"
        value={formData.role}
        onChange={handleChange}
        className="w-full border rounded px-3 py-2 text-gray-800"
        required
      />
      <button type="submit" className="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-500">
        {formMode === 'edit' ? 'Update' : 'Submit'}
      </button>
      {formMode === 'edit' && (
        <button type="button" onClick={handleCancelEdit} className="bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500">
          Cancel
        </button>
      )}
    </form>

    {/* Search Box */}
    <div className="mb-4">
      <input
        type="text"
        value={searchQuery}
        onChange={(e) => setSearchQuery(e.target.value)}
        placeholder="Search roles..."
        className="w-full border rounded px-3 py-2 text-gray-800"
      />
    </div>

    {/* ✅ Place loading spinner right here */}
    {isLoading && (
      <div className="flex justify-center my-4">
        <div className="w-6 h-6 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
      </div>
    )}

    {/* ✅ The table shows once loading is false */}
    {!isLoading && (
      <table className="w-full text-left border border-gray-200 bg-white rounded shadow">
        <thead className="bg-gray-100 text-gray-600">
          <tr>
            <th className="p-2">#</th>
            <th className="p-2">Role</th>
            <th className="p-2 w-24 text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          {roles.map((r, i) => (
            <tr key={r.id} className="border-t border-gray-100">
              <td className="p-2">{(currentPage - 1) * perPage + i + 1}</td>
              <td className="p-2">{r.role}</td>
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
        </tbody>
      </table>
      )}

      {/* Pagination Controls */}
      <div className="flex justify-between mt-4 text-sm text-gray-700">
        <button
          onClick={() => fetchRoles(1)}
          disabled={currentPage === 1}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          First
        </button>
        <button
          onClick={() => fetchRoles(currentPage - 1)}
          disabled={currentPage === 1}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Previous
        </button>
        <span className="self-center">
          Page {currentPage} of {totalPages}
        </span>
        <button
          onClick={() => fetchRoles(currentPage + 1)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Next
        </button>
        <button
          onClick={() => fetchRoles(totalPages)}
          disabled={currentPage === totalPages}
          className="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50"
        >
          Last
        </button>
      </div>
    </div>
  );
};

export default Role;
