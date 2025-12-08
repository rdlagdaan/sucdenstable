import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import napi from "../utils/axiosnapi";

/** ======================= Types ======================= */
type User = {
  id: number;
  username: string;
  email_address: string;
  first_name: string;
  last_name: string;
  middle_name?: string | null;
  designation?: string | null;
  active?: boolean;
};

type SubModule = {
  id: number;
  sub_module_name: string;
  sub_controller?: string | null;
  component_path?: string | null;
  sort_order: number;
};

type Module = {
  id: number;
  module_name: string;
  controller?: string | null;
  sort_order: number;
  sub_modules: SubModule[];
};

type SystemNode = {
  id: number;
  system_id?: string | null;
  system_name: string;
  sort_order: number;
  modules: Module[];
};

type UsersResp = { items: User[]; total: number; page: number; limit: number };
type TreeResp = { systems: SystemNode[] };
type Role = { id: number; role: string };

function fullName(u: User) {
  const mid = u.middle_name ? ` ${u.middle_name}` : "";
  return `${u.first_name}${mid} ${u.last_name}`.trim();
}

function getCompanyId(): number {
  const stored = localStorage.getItem("company_id");
  const fromUser = (() => {
    try {
      const u = JSON.parse(localStorage.getItem("user") || "null");
      return u?.company_id ?? undefined;
    } catch { return undefined; }
  })();
  const cid = Number(stored ?? fromUser ?? 0);
  return Number.isFinite(cid) && cid > 0 ? cid : 0;
}

/** ======================= Component ======================= */
export default function AssignUserModules() {
  // left: users
  const [q, setQ] = useState("");
  const [users, setUsers] = useState<User[]>([]);
  const [selectedUser, setSelectedUser] = useState<User | null>(null);

  // right: tree + assignments
  const [tree, setTree] = useState<SystemNode[]>([]);
  const [assigned, setAssigned] = useState<Set<number>>(new Set()); // current from server
  const [staged, setStaged] = useState<Set<number>>(new Set());     // UI working set

  // ui
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [showAssignedOnly, setShowAssignedOnly] = useState(false);
  const [filter, setFilter] = useState("");

  // accordion state
  const [openSystems, setOpenSystems] = useState<Set<number>>(new Set());
  const [openModules, setOpenModules] = useState<Set<number>>(new Set());

  // add user modal
  const [showAddUser, setShowAddUser] = useState(false);
  const [creating, setCreating] = useState(false);
  const [roles, setRoles] = useState<Role[]>([]);
  const [newUser, setNewUser] = useState({
    username: "", password: "", email_address: "",
    first_name: "", last_name: "",
    middle_name: "", designation: "", role_id: "",
    active: true
  });

  const companyId = getCompanyId();

  /** ========== Data loads ========== */
  // load users (simple: first page 50)
  useEffect(() => {
    let active = true;
    (async () => {
      const resp = await napi.get<UsersResp>("/aum/users", { params: { q, limit: 50, page: 1 } });
      if (!active) return;
      setUsers(resp.data.items);
    })().catch(() => {});
    return () => { active = false; };
  }, [q]);

  // load tree once
  useEffect(() => {
    let active = true;
    (async () => {
      const resp = await napi.get<TreeResp>("/aum/tree");
      if (!active) return;
      setTree(resp.data.systems);
      // open all systems by default for usability
      const sysSet = new Set<number>(resp.data.systems.map(s => s.id));
      setOpenSystems(sysSet);
      // open all modules by default
      const modSet = new Set<number>();
      resp.data.systems.forEach(s => s.modules.forEach(m => modSet.add(m.id)));
      setOpenModules(modSet);
    })().catch(() => {});
    return () => { active = false; };
  }, []);

  // load roles for dropdown
  useEffect(() => {
    let active = true;
    (async () => {
      const resp = await napi.get<{ items: Role[] }>('/aum/roles');
      if (!active) return;
      setRoles(resp.data.items);
    })().catch(() => {});
    return () => { active = false; };
  }, []);

  // selecting a user loads their assignments (COMPANY SCOPED)
  useEffect(() => {
    if (!selectedUser || !companyId) return;
    setLoading(true);
    (async () => {
      const resp = await napi.get<{ sub_module_ids: number[] }>(
        `/aum/users/${selectedUser.id}/assignments`,
        { params: { company_id: companyId } }
      );
      const set = new Set(resp.data.sub_module_ids);
      setAssigned(set);
      setStaged(new Set(set));
    })()
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [selectedUser, companyId]);

  /** ========== Helpers ========== */
  const allSubIds = useMemo(() => {
    const ids: number[] = [];
    for (const sys of tree) for (const mod of sys.modules) for (const sm of mod.sub_modules) ids.push(sm.id);
    return ids;
  }, [tree]);

  const assignedCount = staged.size;
  const totalSubCount = allSubIds.length;

  function isSubVisible(sm: SubModule) {
    if (filter && !`${sm.sub_module_name} ${sm.sub_controller ?? ""} ${sm.component_path ?? ""}`.toLowerCase().includes(filter.toLowerCase())) {
      return false;
    }
    if (showAssignedOnly && !staged.has(sm.id)) return false;
    return true;
  }

  // tri-state computations
  function subChecked(smId: number) {
    return staged.has(smId);
  }
  function moduleState(mod: Module): "all" | "some" | "none" {
    const subIds = mod.sub_modules
      .filter(s => isSubVisible(s))
      .map(s => s.id);
    if (subIds.length === 0) return "none";
    const hits = subIds.filter(id => staged.has(id)).length;
    if (hits === 0) return "none";
    if (hits === subIds.length) return "all";
    return "some";
  }
  function systemState(sys: SystemNode): "all" | "some" | "none" {
    const ids: number[] = [];
    for (const m of sys.modules) for (const s of m.sub_modules) if (isSubVisible(s)) ids.push(s.id);
    if (ids.length === 0) return "none";
    const hits = ids.filter(id => staged.has(id)).length;
    if (hits === 0) return "none";
    if (hits === ids.length) return "all";
    return "some";
  }

  // toggles
  function toggleSub(id: number, on: boolean) {
    setStaged(prev => {
      const next = new Set(prev);
      if (on) next.add(id); else next.delete(id);
      return next;
    });
  }
  function toggleModule(mod: Module, on: boolean) {
    const ids = mod.sub_modules.filter(isSubVisible).map(s => s.id);
    setStaged(prev => {
      const next = new Set(prev);
      for (const id of ids) on ? next.add(id) : next.delete(id);
      return next;
    });
  }
  function toggleSystem(sys: SystemNode, on: boolean) {
    const ids: number[] = [];
    for (const m of sys.modules) for (const s of m.sub_modules) if (isSubVisible(s)) ids.push(s.id);
    setStaged(prev => {
      const next = new Set(prev);
      for (const id of ids) on ? next.add(id) : next.delete(id);
      return next;
    });
  }

  // accordion controls
  const isSystemOpen = (sid: number) => openSystems.has(sid);
  const isModuleOpen = (mid: number) => openModules.has(mid);
  const toggleSystemOpen = (sid: number) => setOpenSystems(s => {
    const n = new Set(s); n.has(sid) ? n.delete(sid) : n.add(sid); return n;
  });
  const toggleModuleOpen = (mid: number) => setOpenModules(s => {
    const n = new Set(s); n.has(mid) ? n.delete(mid) : n.add(mid); return n;
  });

  // compute diff
  const diff = useMemo(() => {
    const add: number[] = [];
    const remove: number[] = [];
    for (const id of staged) if (!assigned.has(id)) add.push(id);
    for (const id of assigned) if (!staged.has(id)) remove.push(id);
    return { add, remove };
  }, [staged, assigned]);

  async function save() {
    if (!selectedUser) return;
    if (diff.add.length === 0 && diff.remove.length === 0) return;
    if (!companyId) return;
    setSaving(true);
    try {
      const resp = await napi.post(`/aum/users/${selectedUser.id}/assignments/diff`, {
        ...diff,
        company_id: companyId,
      });
      const ids: number[] = resp.data.sub_module_ids ?? [];
      const s = new Set(ids);
      setAssigned(s);
      setStaged(new Set(s));
    } finally {
      setSaving(false);
    }
  }

  function discard() {
    setStaged(new Set(assigned));
  }

  /** ========== Users: Add / Toggle Active ========== */

  // Banks-like submit handler: preventDefault, optional confirm, POST, reload list.
  const onCreateUserSubmit = async (e: FormEvent) => {
    e.preventDefault();

    if (
      !newUser.username.trim() ||
      !newUser.password ||
      !newUser.email_address.trim() ||
      !newUser.first_name.trim() ||
      !newUser.last_name.trim()
    ) {
      alert('Please fill in Username, Password, Email, First name, and Last name.');
      return;
    }

    const label = `${newUser.username} (${newUser.first_name} ${newUser.last_name})`;
    if (!window.confirm(`Create new user: ${label}?`)) return;

    setCreating(true);
    try {
      // Optional per-call CSRF priming (does not change axios config)
      await napi.get('/sanctum/csrf-cookie');

      const payload = {
        username: newUser.username.trim(),
        password: newUser.password,
        email_address: newUser.email_address.trim(),
        first_name: newUser.first_name.trim(),
        last_name: newUser.last_name.trim(),
        middle_name: newUser.middle_name.trim() || null,
        designation: newUser.designation.trim() || null,
        role_id: newUser.role_id ? Number(newUser.role_id) : null,
        active: !!newUser.active,
      };

      const resp = await napi.post<{ id: number; user: User }>('/aum/users', payload);

      // refresh list and select created user
      const list = await napi.get<UsersResp>('/aum/users', { params: { q, limit: 50, page: 1 } });
      setUsers(list.data.items);
      const created = list.data.items.find(u => u.id === resp.data.id) || null;
      setSelectedUser(created);

      setShowAddUser(false);
      setNewUser({
        username: "", password: "", email_address: "",
        first_name: "", last_name: "",
        middle_name: "", designation: "",
        role_id: "", active: true
      });
    } catch (err: any) {
      const status = err?.response?.status;
      const msg = err?.response?.data?.message ?? 'Create failed';
      alert(`Create failed. HTTP ${status ?? 'unknown'}\n${msg}`);
    } finally {
      setCreating(false);
    }
  };

  async function toggleActive(user: User) {
    const targetActive = !user.active;
    await napi.patch(`/aum/users/${user.id}/active`, { active: targetActive });
    // update local list quickly
    setUsers(prev => prev.map(u => u.id === user.id ? { ...u, active: targetActive } : u));
    if (selectedUser?.id === user.id) setSelectedUser({ ...user, active: targetActive });
  }

  /** ========== Render ========== */
  return (
    <div className="h-full w-full grid grid-cols-12 gap-4 p-4">
      {/* Left: Users */}
      <div className="col-span-4 bg-white/80 shadow rounded-2xl p-4">
        <div className="flex items-center justify-between mb-2">
          <div className="text-lg font-semibold">Users</div>
          <button
            className="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-sm"
            onClick={() => setShowAddUser(true)}
          >Add user</button>
        </div>
        <input
          className="w-full border rounded-lg px-3 py-2 mb-3"
          placeholder="Search username, name, email, designation…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <div className="h-[70vh] overflow-auto divide-y">
          {users.map(u => (
            <div key={u.id} className="flex items-start">
              <button
                className={`flex-1 text-left px-3 py-2 hover:bg-gray-50 ${selectedUser?.id===u.id ? 'bg-green-50 border-l-4 border-green-500' : ''} ${u.active === false ? 'opacity-60' : ''}`}
                onClick={() => setSelectedUser(u)}
              >
                <div className="font-medium">{u.username} — {fullName(u)}</div>
                <div className="text-xs text-gray-500">{u.email_address} • {u.designation ?? ''}</div>
              </button>
              <button
                className="px-2 py-2 text-xs text-blue-700 hover:underline"
                onClick={() => toggleActive(u)}
                title={u.active ? 'Deactivate' : 'Activate'}
              >
                {u.active ? 'Deactivate' : 'Activate'}
              </button>
            </div>
          ))}
          {users.length === 0 && <div className="text-sm text-gray-500 px-3 py-2">No users found.</div>}
        </div>
      </div>

      {/* Right: Tree */}
      <div className="col-span-8 bg-white/80 shadow rounded-2xl p-4 flex flex-col">
        <div className="flex items-center justify-between mb-3">
          <div className="text-lg font-semibold">Assign Modules</div>
          <div className="text-sm text-gray-600">
            Assigned: <span className="font-semibold">{assignedCount}</span> / {totalSubCount}
          </div>
        </div>

        <div className="flex items-center gap-2 mb-3">
          <input
            className="flex-1 border rounded-lg px-3 py-2"
            placeholder="Filter sub-modules (name, controller, path)…"
            value={filter}
            onChange={(e)=>setFilter(e.target.value)}
          />
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={showAssignedOnly} onChange={(e)=>setShowAssignedOnly(e.target.checked)} />
            Show assigned only
          </label>
        </div>

        {!selectedUser && (
          <div className="flex-1 grid place-items-center text-gray-500">
            Select a user to manage assignments.
          </div>
        )}

        {selectedUser && (
          <>
            {loading ? (
              <div className="flex-1 grid place-items-center text-gray-500">Loading assignments…</div>
            ) : (
              <div className="flex-1 overflow-auto space-y-3 pr-1">
                {tree.map(sys => {
                  const sState = systemState(sys);
                  const sysChecked = sState === "all";
                  const sysInd = sState === "some";
                  const sysOpen = isSystemOpen(sys.id);

                  return (
                    <div key={sys.id} className="border rounded-xl">
                      <div className="px-3 py-2 bg-gray-50 flex items-center gap-3">
                        <button
                          className="px-2 py-1 text-sm border rounded"
                          onClick={() => toggleSystemOpen(sys.id)}
                          aria-expanded={sysOpen}
                        >
                          {sysOpen ? '▾' : '▸'}
                        </button>
                        <input
                          type="checkbox"
                          checked={sysChecked}
                          ref={el => { if (el) el.indeterminate = sysInd; }}
                          onChange={(e)=>toggleSystem(sys, e.target.checked)}
                        />
                        <div className="font-medium">{sys.system_name}</div>
                      </div>

                      {sysOpen && (
                        <div className="p-3 space-y-2">
                          {sys.modules.map(mod => {
                            const mState = moduleState(mod);
                            const mChecked = mState === "all";
                            const mInd = mState === "some";
                            const visibleSubs = mod.sub_modules.filter(isSubVisible);
                            if (visibleSubs.length === 0) return null;
                            const mOpen = isModuleOpen(mod.id);

                            return (
                              <div key={mod.id} className="border rounded-lg">
                                <div className="px-3 py-2 bg-gray-50 flex items-center gap-3">
                                  <button
                                    className="px-2 py-1 text-sm border rounded"
                                    onClick={() => toggleModuleOpen(mod.id)}
                                    aria-expanded={mOpen}
                                  >
                                    {mOpen ? '▾' : '▸'}
                                  </button>
                                  <input
                                    type="checkbox"
                                    checked={mChecked}
                                    ref={el => { if (el) el.indeterminate = mInd; }}
                                    onChange={(e)=>toggleModule(mod, e.target.checked)}
                                  />
                                  <div className="font-medium">{mod.module_name}</div>
                                  {mod.controller && <div className="text-xs text-gray-500 ml-2">({mod.controller})</div>}
                                </div>

                                {mOpen && (
                                  <div className="p-3 grid grid-cols-1 md:grid-cols-2 gap-2">
                                    {visibleSubs.map(sm => {
                                      const on = subChecked(sm.id);
                                      return (
                                        <label key={sm.id} className={`flex items-start gap-2 p-2 rounded-lg border ${on ? 'bg-green-50 border-green-300' : 'bg-white'}`}>
                                          <input
                                            type="checkbox"
                                            checked={on}
                                            onChange={(e)=>toggleSub(sm.id, e.target.checked)}
                                          />
                                          <div>
                                            <div className="font-medium">{sm.sub_module_name}</div>
                                            <div className="text-xs text-gray-500">
                                              {sm.sub_controller || ''}{sm.sub_controller && sm.component_path ? ' • ' : ''}{sm.component_path || ''}
                                            </div>
                                          </div>
                                        </label>
                                      );
                                    })}
                                  </div>
                                )}
                              </div>
                            );
                          })}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}

            {/* Footer actions */}
            <div className="pt-3 mt-3 border-t flex items-center justify-between">
              <div className="text-sm text-gray-600">
                {diff.add.length === 0 && diff.remove.length === 0
                  ? 'No pending changes'
                  : `Pending: +${diff.add.length} / −${diff.remove.length}`}
              </div>
              <div className="flex gap-2">
                <button
                  className="px-3 py-2 rounded-lg border"
                  onClick={discard}
                  disabled={saving || (diff.add.length === 0 && diff.remove.length === 0)}
                >Discard</button>
                <button
                  className="px-4 py-2 rounded-lg bg-green-600 text-white disabled:opacity-60"
                  onClick={save}
                  disabled={saving || (diff.add.length === 0 && diff.remove.length === 0)}
                >{saving ? 'Saving…' : 'Save changes'}</button>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Add User Modal */}
      {showAddUser && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40">
          <div className="bg-white w-full max-w-xl max-h-[85vh] rounded-2xl shadow flex flex-col">
            {/* Header */}
            <div className="px-6 py-4 border-b flex items-center justify-between">
              <div className="text-lg font-semibold">Add user</div>
              <button type="button" className="text-gray-600" onClick={()=>setShowAddUser(false)}>✕</button>
            </div>

            {/* Form (Banks-style) */}
            <form onSubmit={onCreateUserSubmit} className="flex-1 flex flex-col min-h-0">
              {/* Body (scrollable) */}
              <div className="px-6 py-4 overflow-y-auto">
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-1">
                    <label className="text-sm">Username</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.username} onChange={e=>setNewUser(s=>({...s, username:e.target.value}))}/>
                  </div>
                  <div className="col-span-1">
                    <label className="text-sm">Password</label>
                    <input type="password" className="w-full border rounded px-3 py-2"
                      value={newUser.password} onChange={e=>setNewUser(s=>({...s, password:e.target.value}))}/>
                  </div>

                  <div className="col-span-2">
                    <label className="text-sm">Email</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.email_address} onChange={e=>setNewUser(s=>({...s, email_address:e.target.value}))}/>
                  </div>

                  <div className="col-span-1">
                    <label className="text-sm">First name</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.first_name} onChange={e=>setNewUser(s=>({...s, first_name:e.target.value}))}/>
                  </div>
                  <div className="col-span-1">
                    <label className="text-sm">Last name</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.last_name} onChange={e=>setNewUser(s=>({...s, last_name:e.target.value}))}/>
                  </div>

                  <div className="col-span-1">
                    <label className="text-sm">Middle name (optional)</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.middle_name} onChange={e=>setNewUser(s=>({...s, middle_name:e.target.value}))}/>
                  </div>
                  <div className="col-span-1">
                    <label className="text-sm">Designation (optional)</label>
                    <input className="w-full border rounded px-3 py-2"
                      value={newUser.designation} onChange={e=>setNewUser(s=>({...s, designation:e.target.value}))}/>
                  </div>

                  {/* Role combobox */}
                  <div className="col-span-1">
                    <label className="text-sm">Role (optional)</label>
                    <select
                      className="w-full border rounded px-3 py-2 bg-white"
                      value={newUser.role_id}
                      onChange={(e)=>setNewUser(s=>({...s, role_id:e.target.value}))}
                    >
                      <option value="">— Select role —</option>
                      {roles.map(r => (
                        <option key={r.id} value={r.id}>{r.role}</option>
                      ))}
                    </select>
                  </div>

                  {/* Active checkbox occupies the old Status slot (right column) */}
                  <div className="col-span-1 flex items-end">
                    <label className="inline-flex items-center gap-2 mb-1">
                      <input
                        type="checkbox"
                        checked={newUser.active}
                        onChange={e=>setNewUser(s=>({...s, active:e.target.checked}))}
                      />
                      <span className="text-sm">Active</span>
                    </label>
                  </div>
                </div>
              </div>

              {/* Footer */}
              <div className="px-6 py-4 border-t flex justify-end gap-2">
                <button type="button" className="px-3 py-2 rounded border" onClick={()=>setShowAddUser(false)}>Cancel</button>
                <button type="submit" className="px-4 py-2 rounded bg-blue-600 text-white disabled:opacity-60" disabled={creating}>
                  {creating ? "Creating…" : "Create user"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

    </div>
  );
}
