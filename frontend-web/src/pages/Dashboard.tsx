// src/pages/Dashboard.tsx
import * as React from 'react';
import { useEffect, useRef, useState, lazy, Suspense } from 'react';
import napi from '../utils/axiosnapi';
import { useNavigate } from 'react-router-dom';
import {
  Bars3Icon,
  ChevronDownIcon,
  ChevronRightIcon,
  ClipboardDocumentIcon,
  ChevronUpDownIcon,
} from '@heroicons/react/24/outline';
import type { JSX } from 'react';

const Profile = lazy(() => import('./components/settings/Profile'));
const Role = lazy(() => import('./components/settings/Role'));
const Pbn_entry_form = lazy(() => import('./components/quedan_tracking/Pbn_entry_form'));
const Receiving_entry_form = lazy(() => import('./components/quedan_tracking/Receiving_entry_form'));
const Sales_journal_form = lazy(() => import('./components/accounting/Sales_journal_form'));
const Cash_receipts_form = lazy(() => import('./components/accounting/Cash_receipts_form'));
const Purchase_journal_form = lazy(() => import('./components/accounting/Purchase_journal_form'));
const Cash_disbursement_form = lazy(() => import('./components/accounting/Cash_disbursement_form'));
const General_accounting_form = lazy(() => import('./components/accounting/General_accounting_form'));
const AssignUserModules = lazy(() => import('./AssignUserModules'));
const CompanySettings = lazy(() => import('./components/settings/CompanySettings'));
const CropYear = lazy(() => import('./components/settings/CropYear'));
const SugarType = lazy(() => import('./components/settings/SugarType'));


// Reports (Accounting)
const AccountsPayableJournal   = lazy(() => import('./components/accounting/reports/AccountsPayableJournal'));
const AccountsReceivableJournal= lazy(() => import('./components/accounting/reports/AccountsReceivableJournal'));
const CashDisbursementBook     = lazy(() => import('./components/accounting/reports/CashDisbursementBook'));
const CashReceiptBook          = lazy(() => import('./components/accounting/reports/CashReceiptBook'));
const CheckRegister            = lazy(() => import('./components/accounting/reports/CheckRegister'));
const GeneralJournalBook       = lazy(() => import('./components/accounting/reports/GeneralJournalBook'));
const GeneralLedger            = lazy(() => import('./components/accounting/reports/GeneralLedger'));
const ReceiptRegister          = lazy(() => import('./components/accounting/reports/ReceiptRegister'));
const TrialBalance             = lazy(() => import('./components/accounting/reports/TrialBalance'));



const FloatingWindow = lazy(() => import('./components/ui/FloatingWindow'));
const BanksWindow = lazy(() => import('./components/references/BanksWindow'));
const CustomersWindow = lazy(() => import('./components/references/CustomersWindow'));
const VendorsWindow = lazy(() => import('./components/references/VendorsWindow'));
const PlantersWindow = lazy(() => import('./components/references/PlantersWindow'));
const AccountsWindow = lazy(() => import('./components/references/AccountsWindow'));
const MillsWindow = lazy(() => import('./components/references/MillsWindow'));


const componentMap: Record<string, React.LazyExoticComponent<() => JSX.Element>> = {
  profile: Profile,
  roles: Role,
  pbn_entry_forms: Pbn_entry_form,
  receiving_entry_forms: Receiving_entry_form,
  sales_journal_forms: Sales_journal_form,
  cash_receipts_forms: Cash_receipts_form,
  purchase_journal_forms: Purchase_journal_form,
  cash_disbursement_forms: Cash_disbursement_form,
  general_accounting_forms: General_accounting_form,
  assign_user_modules: AssignUserModules,
  companies: CompanySettings,
  crop_years: CropYear,
  sugar_types: SugarType,

  // NEW — Accounting Reports
  accounts_payable_journal: AccountsPayableJournal,
  accounts_receivable_journal: AccountsReceivableJournal,
  cash_disbursement_book: CashDisbursementBook,
  cash_receipt_book: CashReceiptBook,
  check_register: CheckRegister,
  general_journal_book: GeneralJournalBook,
  general_ledger: GeneralLedger,
  receipt_register: ReceiptRegister,
  trial_balance: TrialBalance,

};

interface SubModule { sub_module_id: number; sub_module_name: string; component_path: string | null; }
interface Module { module_id: number; module_name: string; sub_modules: SubModule[]; }
interface System { system_id: number; system_name: string; modules: Module[]; }

type RefKey = 'accounts' | 'banks' | 'customers' | 'mills' | 'planters' | 'vendors';

// ---- Theme (Sucden=blue, Amerop=black) --------------------------------------
type ThemeKey = 'sucden' | 'amerop';
const THEME = {
  sucden: {
    header: 'bg-blue-700',
    headerBtn: 'bg-blue-600 hover:bg-blue-500',
    sidebar: 'bg-[#007BFF]',
    sidebarText: 'text-white',
    hoverSwap: 'hover:bg-white hover:text-[#007BFF]',
    inputBorder: 'border-gray-300',
    contentBg: 'bg-gray-50',
  },
  amerop: {
    header: 'bg-black',
    headerBtn: 'bg-neutral-800 hover:bg-neutral-700',
    sidebar: 'bg-black',
    sidebarText: 'text-white',
    hoverSwap: 'hover:bg-white hover:text-black',
    inputBorder: 'border-gray-600',
    contentBg: 'bg-neutral-50',
  },
} as const;
// -----------------------------------------------------------------------------

export default function Dashboard() {
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [systems, setSystems] = useState<System[]>([]);
  const [openSystems, setOpenSystems] = useState<number[]>([]);
  const [openModules, setOpenModules] = useState<number[]>([]);
  const [selectedContent, setSelectedContent] = useState<string>('Select a sub-module to view.');
  const [selectedSubModuleName, setSelectedSubModuleName] = useState<string>('Module Name');

  const [username, setUsername] = useState('');
  const [companyId, setCompanyId] = useState<string>('');
  const [companyName, setCompanyName] = useState<string>('');
  const [companyLogo, setCompanyLogo] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');

  // permission toggles for References (wire real values later)
  const [refPerms, setRefPerms] = useState<Record<RefKey, boolean>>({
    accounts: true, banks: true, customers: true, mills: true, planters: true, vendors: true,
  });

  // user menu UX
  const [menuOpen, setMenuOpen] = useState(false);
  const [refsOpen, setRefsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement | null>(null);

  // floating windows state
  const [windows, setWindows] = useState<Array<{ id: string; type: RefKey; title: string; minimized?: boolean }>>([]);

  const navigate = useNavigate();

  // ---- Robust logout (always clears + redirects even if API fails) ----------
  const hardSignOut = () => {
    try {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      // best-effort cookie clears (path=/ to hit SPA + API)
      document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
      document.cookie = 'sucden_session=; Max-Age=0; path=/';
    } catch {}
    // Hard redirect avoids any stale in-app state
    window.location.replace('/');
  };

  const handleLogout = async () => {
    try {
      await napi.post('/logout'); // 200/204/419/401 are all fine; we'll still sign out
    } catch (err) {
      // swallow; we still log out locally
      console.error('Logout request error (continuing to clear):', err);
    } finally {
      hardSignOut();
    }
  };
  // ---------------------------------------------------------------------------

  useEffect(() => {
    napi.get('/references/visibility')
      .then(res => { if (res?.data) setRefPerms(prev => ({ ...prev, ...res.data })); })
      .catch(() => {/* keep defaults if endpoint not ready */});
  }, []);

  // click outside to close menu
  useEffect(() => {
    function onDocClick(e: MouseEvent) {
      if (!menuRef.current) return;
      if (!menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
        setRefsOpen(false);
      }
    }
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  useEffect(() => {
    const stored = localStorage.getItem('user');
    if (stored) {
      const u = JSON.parse(stored);
      setUsername(u.first_name ?? u.username ?? '');
      //setCompanyId(String(u.company_id ?? ''));
      const storedCompanyId = localStorage.getItem('company_id');
      setCompanyId(String(storedCompanyId ?? u.company_id ?? ''));


      if (u?.permissions?.reference) {
        setRefPerms((prev) => ({ ...prev, ...u.permissions.reference }));
      }
    }
  }, []);

  useEffect(() => {
    if (!companyId) return;
    (async () => {
      try {
        const res = await napi.get(`/companies/${companyId}`);
        setCompanyName(res.data.name ?? '');
        setCompanyLogo(res.data.logo ?? '');
      } catch {
        console.error('Failed to load company info');
      }
    })();
  }, [companyId]);

useEffect(() => {
  const token = localStorage.getItem('token');
  if (!token) {
    navigate('/login');
    return;
  }
  // Wait until companyId is known and positive
  const cid = Number(companyId);
  if (!cid || cid <= 0) {
    // not ready yet — don’t call the API
    return;
  }

  // Optional: clear previous modules while switching company
  setSystems([]);

  napi
    .get<System[]>('/user/modules', { params: { company_id: cid } })
    .then((res) => setSystems(res.data ?? []))
    .catch((err) => {
      console.error('Failed to fetch systems/modules', err);
      if (err?.response?.status === 401) {
        localStorage.removeItem('token');
        navigate('/login');
      }
    });
}, [navigate, companyId]); // ← add companyId as a dependency


  const getFilteredModules = (modules: Module[]) =>
    modules
      .map((m) => {
        const q = searchQuery.toLowerCase();
        const moduleMatches = m.module_name.toLowerCase().includes(q);
        const filteredSub = m.sub_modules.filter((s) => s.sub_module_name.toLowerCase().includes(q));
        if (moduleMatches || filteredSub.length > 0) {
          return { ...m, sub_modules: moduleMatches ? m.sub_modules : filteredSub };
        }
        return null;
      })
      .filter((m): m is Module => m !== null);

  const toggleSystem = (id: number) =>
    setOpenSystems((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]));

  const toggleModule = (id: number) =>
    setOpenModules((prev) => (prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]));

  // window helpers
  const openRefWindow = (type: RefKey) => {
    const titleMap: Record<RefKey, string> = {
      accounts: 'Accounts', banks: 'Banks', customers: 'Customers', mills: 'Mills', planters: 'Planters', vendors: 'Vendors',
    };
    const id = `${type}-${Date.now()}`;
    setWindows((w) => [...w, { id, type, title: titleMap[type] }]);
    setMenuOpen(false);
    setRefsOpen(false);
  };
  const closeWindow = (id: string) => setWindows((w) => w.filter((x) => x.id !== id));
  const setMinimized = (id: string, minimized: boolean) =>
    setWindows((w) => w.map((x) => (x.id === id ? { ...x, minimized } : x)));

  // pick theme by company (default to Sucden if unknown)
  const themeKey: ThemeKey = companyId === '2' ? 'amerop' : 'sucden';
  const t = THEME[themeKey];

  return (
    <div className="flex w-full h-screen overflow-hidden"> {/* root: contain overflow */}
      {/* LEFT SIDEBAR */}
      <div
        className={`transition-all duration-300 ${sidebarOpen ? 'w-72' : 'w-0'} border-r shadow-md h-full flex flex-col min-h-0`}
      >
        <div className={`p-4 border-b font-bold text-lg flex justify-between items-center ${t.sidebar} ${t.sidebarText}`}>
          <span className="truncate">
            <img src={`/${companyLogo}.jpg`} alt={`${companyLogo}`} className="h-10 inline-block mr-2" />
            {companyName || 'Systems'}
          </span>
          <button onClick={() => setSidebarOpen(false)} className={`${t.headerBtn} ${t.sidebarText} p-2 rounded-full`}>
            <Bars3Icon className="h-7 w-7" />
          </button>
        </div>

        <div className={`px-3 py-2 ${t.sidebar}`}>
          <input
            type="text"
            placeholder="Search modules or submodules..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className={`w-full border ${t.inputBorder} rounded px-3 py-1 text-sm text-black`}
          />
        </div>

        <nav className={`p-4 ${t.sidebar} ${t.sidebarText} flex-1 overflow-y-auto min-h-0`}> {/* scrollable list */}
          {systems.map((system) => (
            <div key={system.system_id} className="mb-2">
              <button
                onClick={() => toggleSystem(system.system_id)}
                className={`flex items-center w-full text-left font-bold ${t.sidebar} ${t.sidebarText} ${t.hoverSwap} px-2 py-1 rounded`}
              >
                {openSystems.includes(system.system_id) ? (
                  <ChevronDownIcon className="h-4 w-4 mr-2" />
                ) : (
                  <ChevronRightIcon className="h-4 w-4 mr-2" />
                )}
                {system.system_name}
              </button>

              {openSystems.includes(system.system_id) && (
                <div className="ml-4 mt-1">
                  {getFilteredModules(system.modules).map((module) => (
                    <div key={module.module_id} className="mb-1">
                      <button
                        onClick={() => toggleModule(module.module_id)}
                        className={`flex items-center w-full text-left text-sm ${t.sidebarText} font-medium ${t.sidebar} ${t.hoverSwap} px-2 py-1 rounded`}
                      >
                        {openModules.includes(module.module_id) ? (
                          <ChevronDownIcon className="h-3 w-3 mr-2" />
                        ) : (
                          <ChevronRightIcon className="h-3 w-3 mr-2" />
                        )}
                        {module.module_name}
                      </button>

                      {openModules.includes(module.module_id) && (
                        <ul className="ml-6 text-sm mt-1 space-y-1">
                          {module.sub_modules.map((sub) => (
                            <li key={sub.sub_module_id}>
                              <button
                                onClick={() => {
                                  setSelectedContent(sub.component_path || `You selected: ${sub.sub_module_name}`);
                                  setSelectedSubModuleName(sub.sub_module_name);
                                }}
                                className={`w-full text-left ${t.sidebar} ${t.sidebarText} ${t.hoverSwap} px-3 py-1 rounded italic`}
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

      {/* RIGHT CONTENT */}
      <div className="flex-1 flex flex-col min-h-0">
        <div className={`${t.header} text-white px-6 py-3 flex items-center justify-between shadow w-full`}>
          <h1 className="text-lg font-semibold truncate">{selectedSubModuleName}</h1>

          {/* User menu */}
          <div className="relative" ref={menuRef}>
            <button
              onClick={() => setMenuOpen((v) => !v)}
              className={`flex items-center gap-2 rounded-full px-3 py-2 ${t.headerBtn} transition-colors`}
            >
              <span className="text-sm">Welcome, {username}</span>
              <ChevronUpDownIcon className="h-5 w-5" />
              <img src="https://via.placeholder.com/32" alt="User" className="w-8 h-8 rounded-full border-2 border-white" />
            </button>

            {menuOpen && (
              <div className="absolute right-0 mt-2 w-56 bg-white text-blue-700 rounded-lg shadow-lg z-50 overflow-hidden">
                <button
                  onClick={() => {
                    setSelectedContent('profile');
                    setSelectedSubModuleName('Profile');
                    setMenuOpen(false);
                  }}
                  className="block w-full text-left px-4 py-2 hover:bg-blue-50 text-sm"
                >
                  Profile
                </button>

                {/* REFERENCES SUBMENU */}
                <div className="border-t border-blue-100"></div>
                <div>
                  <button
                    onClick={() => setRefsOpen((v) => !v)}
                    className="flex w-full items-center justify-between px-4 py-2 hover:bg-blue-50 text-sm"
                  >
                    <span>References</span>
                    {refsOpen ? <ChevronDownIcon className="h-4 w-4" /> : <ChevronRightIcon className="h-4 w-4" />}
                  </button>
                  {refsOpen && (
                    <div className="py-1">
                      {refPerms.accounts && (
                        <button onClick={() => openRefWindow('accounts')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Accounts
                        </button>
                      )}
                      {refPerms.banks && (
                        <button onClick={() => openRefWindow('banks')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Banks
                        </button>
                      )}
                      {refPerms.customers && (
                        <button onClick={() => openRefWindow('customers')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Customers
                        </button>
                      )}
                      {refPerms.mills && (
                        <button onClick={() => openRefWindow('mills')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Mills
                        </button>
                      )}
                      {refPerms.planters && (
                        <button onClick={() => openRefWindow('planters')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Planters
                        </button>
                      )}
                      {refPerms.vendors && (
                        <button onClick={() => openRefWindow('vendors')} className="block w-full text-left px-6 py-2 hover:bg-blue-50 text-sm">
                          Vendors
                        </button>
                      )}
                    </div>
                  )}
                </div>

                <div className="border-t border-blue-100"></div>
                <button onClick={handleLogout} className="block w-full text-left px-4 py-2 hover:bg-blue-50 text-sm">
                  Logout
                </button>
              </div>
            )}
          </div>
        </div>

        {/* Content area must be scrollable */}
        <div className={`flex-1 p-6 ${t.contentBg} relative overflow-auto min-h-0`}>
          {!sidebarOpen && (
            <button onClick={() => setSidebarOpen(true)} className="mb-4 text-gray-700">
              <Bars3Icon className="h-5 w-5" />
            </button>
          )}

          <Suspense fallback={<div className="p-4 text-gray-500">Loading module...</div>}>
            {selectedContent && componentMap[selectedContent] ? (
              <div className="w-full h-full">
                {React.createElement(componentMap[selectedContent])}
              </div>
            ) : (
              <div className="w-full h-full">
                {typeof selectedContent === 'string' ? selectedContent : 'Select a sub-module to view.'}
              </div>
            )}
          </Suspense>

          {/* FLOATING WINDOWS */}
          <Suspense fallback={null}>
            {windows.map((w) => (
              <FloatingWindow
                key={w.id}
                title={w.title}
                defaultWidth={760}
                defaultHeight={520}
                minimized={!!w.minimized}
                onMinimize={(min) => setMinimized(w.id, min)}
                onClose={() => closeWindow(w.id)}
              >
              {w.type === 'banks' && <BanksWindow />}
              {w.type === 'customers' && <CustomersWindow />}
              {w.type === 'vendors' && <VendorsWindow />}
              {w.type === 'planters' && <PlantersWindow />}
              {w.type === 'accounts' && <AccountsWindow />}
              {w.type === 'mills' && <MillsWindow />}

              {w.type !== 'banks' && w.type !== 'customers' && w.type !== 'vendors'  && w.type !== 'planters'  && w.type !== 'accounts'  && w.type !== 'mills'  && (
                <div className="p-4 text-sm text-gray-600">
                  {w.title} module will be implemented next.
                </div>
              )}


              </FloatingWindow>
            ))}
          </Suspense>
        </div>
      </div>
    </div>
  );
}
