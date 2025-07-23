import { useEffect, useState } from 'react';
import napi from '../utils/axiosnapi';
import {
  Bars3Icon,
  ChevronDownIcon,
  ChevronRightIcon,
  ClipboardDocumentIcon, 
} from '@heroicons/react/24/outline';
import { useNavigate } from 'react-router-dom'; // 👈 Required for redirection
import Role from './components/settings/Role'; // adjust the path as needed
import type { JSX } from 'react';

// 🔽 Place it here — after imports, before function
const componentMap: Record<string, JSX.Element> = {
  roles: <Role />,
  // You can add more components later
};

interface SubModule {
  sub_module_id: number;
  sub_module_name: string;
  component_path: string | null;
}

interface Module {
  module_id: number;
  module_name: string;
  sub_modules: SubModule[];
}

interface System {
  system_id: number;
  system_name: string;
  modules: Module[];
}

export default function Dashboard() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [systems, setSystems] = useState<System[]>([]);
  const [openSystems, setOpenSystems] = useState<number[]>([]);
  const [openModules, setOpenModules] = useState<number[]>([]);
  const [selectedContent, setSelectedContent] = useState<string>('Select a sub-module to view.');
  const navigate = useNavigate();
  
  const [username, setUsername] = useState('');
  const [companyId, setCompanyId] = useState('');
  const [companyName, setCompanyName] = useState('');
  const [companyLogo, setCompanyLogo] = useState('');
  const [selectedSubModuleName, setSelectedSubModuleName] = useState<string>('Module Name');  

  const [searchQuery, setSearchQuery] = useState('');


    const handleLogout = async () => {
    try {
        await napi.post('/api/logout');
        localStorage.removeItem('token'); // clear token
        localStorage.removeItem('user');
        navigate('/'); // redirect to login
    } catch (err) {
        console.error('Logout failed:', err);
        alert('Logout failed');
    }
    };

    useEffect(() => {
      const storedUser = localStorage.getItem('user');
      if (storedUser) {
        const user = JSON.parse(storedUser);
        setUsername(user.first_name);
        setCompanyId(user.company_id);
      }
    }, []);

    useEffect(() => {
      const fetchCompanyName = async () => {
        if (!companyId) return;
        try {
          const res = await napi.get(`/api/companies/${companyId}`);
          setCompanyName(res.data.name); // depends on your actual response
        } catch (error) {
          console.error('Failed to load company name');
        }
      };

      fetchCompanyName();
    }, [companyId]);


    useEffect(() => {
      const fetchCompanyLogo = async () => {
        if (!companyId) return;
        try {
          const res = await napi.get(`/api/companies/${companyId}`);
          setCompanyLogo(res.data.logo); // depends on your actual response
        } catch (error) {
          console.error('Failed to load company logo');
        }
      };

      fetchCompanyLogo();
    }, [companyId]);


    useEffect(() => {
    const token = localStorage.getItem('token');

    if (!token) {
        navigate('/login');
        return;
    }

    napi
        .get<System[]>('/api/user/modules')
        .then((res) => setSystems(res.data))
        .catch((err) => {
        console.error('Failed to fetch systems/modules', err);

        // Optional: Redirect to login if the token is invalid (e.g., 401 error)
        if (err.response?.status === 401) {
            localStorage.removeItem('token');
            navigate('/login');
        }
        });
    }, [navigate]);


const getFilteredModules = (modules: Module[]) => {
  return modules
    .map((module) => {
      const moduleMatches = module.module_name.toLowerCase().includes(searchQuery.toLowerCase());

      const filteredSubModules = module.sub_modules.filter((sub) =>
        sub.sub_module_name.toLowerCase().includes(searchQuery.toLowerCase())
      );

      if (moduleMatches || filteredSubModules.length > 0) {
        return {
          ...module,
          sub_modules: moduleMatches ? module.sub_modules : filteredSubModules,
        };
      }

      return null;
    })
    .filter((m): m is Module => m !== null);
};

  

  const toggleSystem = (id: number) => {
    setOpenSystems((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const toggleModule = (id: number) => {
    setOpenModules((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  return (
    <div className="flex h-screen">
      {/* Sidebar */}
      <div className={`transition-all duration-300 ${sidebarOpen ? 'w-72' : 'w-0'} overflow-hidden border-r shadow-md h-screen flex flex-col`}>
      
      {/*<div className={`transition-all bg-[#007BFF] duration-300 ${sidebarOpen ? 'w-72' : 'w-0'} overflow-hidden bg-white border-r shadow-md`}>*/}
        {/* Title Bar */}
        <div className="p-4 border-b font-bold text-lg flex justify-between items-center  bg-[#007BFF] text-white">
          <span><img src={`/${companyLogo}.jpg`} alt={`${companyLogo}`} className="h-10" />{companyName || 'Systems'}</span>
          <button onClick={() => setSidebarOpen(false)} className=" bg-[#007BFF] text-white p-2 rounded-full hover:bg-blue-600">
            <Bars3Icon className="h-7 w-7" />
          </button>
        </div>

<div className="px-3 py-2 bg-[#007BFF]">
  <input
    type="text"
    placeholder="Search modules or submodules..."
    value={searchQuery}
    onChange={(e) => setSearchQuery(e.target.value)}
    className="w-full border border-gray-300 rounded px-3 py-1 text-sm text-black"
  />
</div>

        {/* Hierarchy Navigation */}
        <nav className="p-4 overflow-y-auto bg-[#007BFF] text-white-700">
          {systems.map((system) => (
            <div key={system.system_id} className="mb-2 bg-[#007BFF] text-white-700">
              {/* Level 1: System */}
              <button
                onClick={() => toggleSystem(system.system_id)}
                className="flex items-center w-full text-left font-bold  bg-[#007BFF] text-white text-md hover:bg-white hover:text-[#007BFF] px-2 py-1 rounded"
              >
                {openSystems.includes(system.system_id) ? (
                  <ChevronDownIcon className="h-4 w-4 mr-2" />
                ) : (
                  <ChevronRightIcon className="h-4 w-4 mr-2" />
                )}
                {system.system_name}
              </button>

              {/* Level 2: Modules */}
              {openSystems.includes(system.system_id) && (
                <div className="ml-4 mt-1 bg-[#007BFF]">
                  {getFilteredModules(system.modules).map((module) => (
                    <div key={module.module_id} className="mb-1  bg-[#007BFF]">
                      <button
                        onClick={() => toggleModule(module.module_id)}
                        className="flex items-center w-full  bg-[#007BFF] text-left text-sm text-white font-medium hover:bg-white hover:text-[#007BFF] px-2 py-1 rounded"
                      >
                        {openModules.includes(module.module_id) ? (
                          <ChevronDownIcon className="h-3 w-3 mr-2" />
                        ) : (
                          <ChevronRightIcon className="h-3 w-3 mr-2" />
                        )}
                        {module.module_name}
                      </button>

                      {/* Level 3: Submodules */}
                      {openModules.includes(module.module_id) && (
                        <ul className="ml-6 text-sm text-gray-600 mt-1 space-y-1">
                          {module.sub_modules.map((sub) => (
                            <li key={sub.sub_module_id}>
                              
                              <button
  onClick={() => {
    setSelectedContent(sub.component_path || `You selected: ${sub.sub_module_name}`);
    setSelectedSubModuleName(sub.sub_module_name); // Set dynamic name here
  }}
                                className="w-full text-left bg-[#007BFF] text-white hover:bg-white hover:text-[#007BFF] px-3 py-1 rounded italic text-gray-700"
                              >
                                <ClipboardDocumentIcon className="h-3 w-3 inline mr-2" />
                                {sub.sub_module_name}
                              </button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  ))}
                </div>
              )}				



              
            </div>
          ))}
        </nav>
      </div>

{/* Main Content */}
<div className="flex-1 flex flex-col overflow-auto">
  {/* Right Panel Header */}
  <div className="bg-blue-700 text-white px-6 py-3 flex items-center justify-between shadow w-full">
    <h1 className="text-lg font-semibold">{selectedSubModuleName}</h1>
    <div className="relative group">
      {/* Avatar + Dropdown Toggle */}
      <div className="flex items-center space-x-2 cursor-pointer">
        <span className="text-sm">Welcome, {username}</span>
        <img
          src="https://via.placeholder.com/32"
          alt="User Avatar"
          className="w-8 h-8 rounded-full border-2 border-white"
        />
      </div>

      {/* Dropdown */}
      <div className="absolute right-0 mt-2 w-32 bg-white text-blue-700 rounded shadow-md opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-50">
        <button
          onClick={handleLogout}
          className="flex items-center gap-2 px-4 py-2 hover:bg-blue-100 w-full text-left text-sm"
        >
          <svg
            xmlns="http://www.w3.org/2000/svg"
            className="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth="2"
              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"
            />
          </svg>
          Logout
        </button>
      </div>
    </div>
  </div>

  {/* Content Body */}
  <div className="flex-1 p-6 bg-gray-50">
    {!sidebarOpen && (
      <button
        onClick={() => setSidebarOpen(true)}
        className="mb-4 text-gray-700"
      >
        <Bars3Icon className="h-5 w-5" />
      </button>
    )}
{selectedContent && componentMap[selectedContent] ? (
  <div className="bg-white p-6 rounded shadow text-gray-800">
    {componentMap[selectedContent]}
  </div>
) : (
  <div className="bg-white p-6 rounded shadow text-gray-800">
    {typeof selectedContent === 'string' ? selectedContent : 'Select a sub-module to view.'}
  </div>
)}
  </div>
</div>

    </div>
  );
}
