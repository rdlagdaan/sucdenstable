import { useEffect, useMemo, useRef, useState } from "react";
import napi from "../../../../utils/axiosnapi"; // axios withCredentials + CSRF baked in



type AccountRow = {
  acct_code: string;
  acct_desc?: string;
  acct_number?: number;
  main_acct?: string | null;
  main_acct_code?: string | null;
  fs?: string | null;
  exclude?: number;
  active_flag?: number;
};

type JobState = {
  status: "queued" | "running" | "done" | "failed" | "missing";
  progress: number;
  message?: string;
  format?: "pdf" | "xls" | "xlsx";
  orientation?: "landscape" | "portrait";
  file_rel?: string | null;
  file_abs?: string | null;
  file_url?: string | null;
  file_disk?: string | null;
  download_name?: string | null;
  file_name?: string | null;
};

type StartPayload = {
  startAccount: string;
  endAccount: string;
  startDate: string;
  endDate: string;
  format?: "pdf" | "xls" | "xlsx";
  orientation?: "portrait" | "landscape";
  company_id?: number;
  fs?: "ALL" | "ACT" | "BS" | "IS";
};

const POLL_MS = 1500;

function saveBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function openBlob(blob: Blob) {
  const url = URL.createObjectURL(blob);
  window.open(url, "_blank", "noopener,noreferrer");
}

type Option = { value: string; label: string };

function SearchableSelect({
  label,
  value,
  options,
  disabled,
  placeholder = "Type to search…",
  onChange,
}: {
  label: string;
  value: string;
  options: Option[];
  disabled?: boolean;
  placeholder?: string;
  onChange: (newValue: string) => void;
}) {
  const wrapRef = useRef<HTMLDivElement | null>(null);
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  const selectedLabel = useMemo(() => {
    return options.find((o) => o.value === value)?.label ?? "";
  }, [options, value]);

  useEffect(() => {
    setQuery(selectedLabel);
  }, [selectedLabel]);

  useEffect(() => {
    const onDocMouseDown = (e: MouseEvent) => {
      if (!wrapRef.current) return;
      if (!wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDocMouseDown);
    return () => document.removeEventListener("mousedown", onDocMouseDown);
  }, []);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!open) return options;
    if (!q) return options;
    return options.filter((o) => o.label.toLowerCase().includes(q));
  }, [options, query, open]);

  const pick = (opt: Option) => {
    onChange(opt.value);
    setQuery(opt.label);
    setOpen(false);
  };

  return (
    <div className="flex flex-col" ref={wrapRef}>
      <label className="text-sm text-gray-600 mb-1">{label}</label>

      <div className="relative">
        <input
          type="text"
          className="border rounded px-2 py-2 w-full"
          disabled={disabled}
          value={query}
          placeholder={disabled ? "Loading…" : placeholder}
          onFocus={() => !disabled && setOpen(true)}
          onChange={(e) => {
            setQuery(e.target.value);
            if (!open) setOpen(true);
          }}
          onKeyDown={(e) => {
            if (e.key === "Escape") setOpen(false);
          }}
        />

        {open && !disabled && (
          <div className="absolute z-50 mt-1 w-full bg-white border rounded shadow-lg max-h-72 overflow-auto">
            {filtered.length === 0 ? (
              <div className="px-3 py-2 text-sm text-gray-500">No matches</div>
            ) : (
              filtered.map((o) => (
                <button
                  type="button"
                  key={o.value}
                  className={[
                    "w-full text-left px-3 py-2 text-sm hover:bg-gray-100",
                    o.value === value ? "bg-gray-50 font-semibold" : "",
                  ].join(" ")}
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => pick(o)}
                >
                  {o.label}
                </button>
              ))
            )}
          </div>
        )}
      </div>
    </div>
  );
}



export default function TrialBalance() {
  const [accounts, setAccounts] = useState<AccountRow[]>([]);
  const [loadingAccounts, setLoadingAccounts] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [fs, setFs] = useState<"ALL" | "ACT" | "BS" | "IS">("ALL");
  const [startAccount, setStartAccount] = useState<string>("");
  const [endAccount, setEndAccount] = useState<string>("");

  const [startDate, setStartDate] = useState<string>("2025-01-01");
  const [endDate, setEndDate] = useState<string>("2025-01-31");

  const [ticket, setTicket] = useState<string | null>(null);
  const [job, setJob] = useState<JobState | null>(null);
  const pollRef = useRef<number | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const accountOptions = useMemo(
    () =>
      accounts.map((r) => ({
        value: r.acct_code,
        label: `${r.acct_code}${r.acct_desc ? " - " + r.acct_desc : ""}`,
      })),
    [accounts]
  );

// --- company id from localStorage 'user' ---
const user = useMemo(() => {
  try {
    const s = localStorage.getItem("user");
    return s ? JSON.parse(s) : null;
  } catch {
    return null;
  }
}, []);

const companyId = useMemo<number>(() => {
  return (
    user?.company_id ??
    user?.companyId ??
    user?.company?.id ??
    0
  );
}, [user]);


  useEffect(() => {
    const load = async () => {
      setLoadingAccounts(true);
      setLoadError(null);
      try {
        const { data } = await napi.get("trial-balance/accounts", {
        params: { fs, company_id: companyId },
        });
        if (!Array.isArray(data)) {
          throw new Error("Accounts endpoint did not return an array.");
        }
        const rows: AccountRow[] = data.map((x: any): AccountRow => ({
          acct_code: x.acct_code ?? "",
          acct_desc: x.acct_desc ?? "",
          acct_number: x.acct_number ?? undefined,
          main_acct: x.main_acct ?? null,
          main_acct_code: x.main_acct_code ?? null,
          fs: x.fs ?? null,
          exclude: x.exclude ?? undefined,
          active_flag: x.active_flag ?? undefined,
        }));
        const filtered = rows.filter((r) => r.acct_code);
        setAccounts(filtered);
        if (filtered.length) {
          setStartAccount(filtered[0].acct_code);
          setEndAccount(filtered[filtered.length - 1].acct_code);
        } else {
          setStartAccount("");
          setEndAccount("");
        }
      } catch (err: any) {
        setLoadError(err?.message ?? "Failed to load accounts.");
      } finally {
        setLoadingAccounts(false);
      }
    };
    load();
    return () => {
      if (pollRef.current) {
        window.clearInterval(pollRef.current);
        pollRef.current = null;
      }
    };
  }, [fs, companyId]);

  const startJob = async (payload: StartPayload) => {
    setActionError(null);
    setModalOpen(true);
    setJob({ status: "queued", progress: 0, message: "Queued" });
    setTicket(null);

    try {
      const { data } = await napi.post("trial-balance/report", payload);
      if (!data?.ticket) throw new Error("No ticket returned from server.");
      const t = data.ticket as string;
      setTicket(t);

      pollRef.current = window.setInterval(async () => {
        try {
const { data: s } = await napi.get<JobState>(
  `trial-balance/report/${t}/status`,
  { params: { company_id: companyId } }
);
          setJob(s);
          if (s.status === "done" || s.status === "failed" || s.status === "missing") {
            if (pollRef.current) {
              window.clearInterval(pollRef.current);
              pollRef.current = null;
            }
          }
        } catch (e: any) {
          setActionError(e?.message ?? "Status polling failed.");
        }
      }, POLL_MS) as unknown as number;
    } catch (err: any) {
      setActionError(err?.message ?? "Failed to start job.");
      setModalOpen(false);
    }
  };

  const handleGenerate = (orientation: "landscape" | "portrait") => {
    if (!startAccount || !endAccount || !startDate || !endDate) {
      setActionError("Please select account range and dates.");
      return;
    }
    startJob({
      startAccount,
      endAccount,
      startDate,
      endDate,
      format: "pdf",
      orientation,
      fs,
      company_id: companyId,
    });
  };

  const handleExcel = (ext: "xls" | "xlsx" = "xls") => {
    if (!startAccount || !endAccount || !startDate || !endDate) {
      setActionError("Please select account range and dates.");
      return;
    }
    startJob({
      startAccount,
      endAccount,
      startDate,
      endDate,
      format: ext,
      orientation: "landscape",
      fs,
      company_id: companyId,
    });
  };

  const getDownloadName = () => {
    if (!job) return "trial-balance.pdf";
    const fallback =
      job.format === "xls" || job.format === "xlsx"
        ? `trial-balance.${job.format}`
        : "trial-balance.pdf";
    return job.download_name || job.file_name || fallback;
  };

  const viewFile = async () => {
    if (!ticket || !job) return;
    try {
      if (job.format === "pdf") {
const res = await napi.get(
  `trial-balance/report/${ticket}/view`,
  { responseType: "blob", params: { company_id: companyId } }
);
        openBlob(res.data);
      } else {
const res = await napi.get(
  `trial-balance/report/${ticket}/download`,
  { responseType: "blob", params: { company_id: companyId } }
);
        saveBlob(res.data, getDownloadName());
      }
    } catch (err: any) {
      setActionError(err?.message ?? "Unable to open file.");
    }
  };

  const downloadFile = async () => {
    if (!ticket || !job) return;
    try {
const res = await napi.get(
  `trial-balance/report/${ticket}/download`,
  { responseType: "blob", params: { company_id: companyId } }
);
      saveBlob(res.data, getDownloadName());
    } catch (err: any) {
      setActionError(err?.message ?? "Unable to download file.");
    }
  };

  return (
    <div className="p-4">
      <h2 className="text-xl font-semibold mb-3">TRIAL BALANCE</h2>

{/* Row 1: FS + Dates */}
<div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
  <div className="flex flex-col">
    <label className="text-sm text-gray-600 mb-1">FS Filter</label>
    <select
      className="border rounded px-2 py-2"
      value={fs}
      onChange={(e) => setFs(e.target.value as any)}
    >
      <option value="ALL">ALL</option>
      <option value="ACT">Active Accounts</option>
      <option value="BS">Balance Sheet</option>
      <option value="IS">Income Statement</option>
    </select>
  </div>

  <div className="flex flex-col">
    <label className="text-sm text-gray-600 mb-1">Start Date</label>
    <input
      type="date"
      className="border rounded px-2 py-2"
      value={startDate}
      onChange={(e) => setStartDate(e.target.value)}
    />
  </div>

  <div className="flex flex-col">
    <label className="text-sm text-gray-600 mb-1">End Date</label>
    <input
      type="date"
      className="border rounded px-2 py-2"
      value={endDate}
      onChange={(e) => setEndDate(e.target.value)}
    />
  </div>
</div>

{/* Row 2: Account range */}
<div className="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 items-end">
  <SearchableSelect
  label="Account (Start)"
  disabled={loadingAccounts || !accountOptions.length}
  value={startAccount}
  options={accountOptions}
  onChange={(v) => setStartAccount(v)}
/>

<SearchableSelect
  label="Account (End)"
  disabled={loadingAccounts || !accountOptions.length}
  value={endAccount}
  options={accountOptions}
  onChange={(v) => setEndAccount(v)}
/>

</div>




      {loadError && <div className="mt-3 text-sm text-red-600">{loadError}</div>}

      <div className="mt-4 flex flex-wrap gap-2">
        <button
          className="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleGenerate("landscape")}
        >
          Generate
        </button>

        <button
          className="px-3 py-2 rounded bg-gray-700 text-white hover:bg-gray-800 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleGenerate("portrait")}
        >
          Portrait
        </button>

        <button
          className="px-3 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleExcel("xls")}
        >
          EXCEL (.xls)
        </button>

        <button
          className="px-3 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleExcel("xlsx")}
        >
          EXCEL (.xlsx)
        </button>
      </div>

      {modalOpen && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-xl w-full max-w-lg p-5">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-semibold">Generating…</h3>
              <button
                className="text-gray-500 hover:text-gray-700"
                onClick={() => setModalOpen(false)}
                aria-label="Close"
              >
                ✕
              </button>
            </div>

            <div className="mt-3">
              <div className="text-sm text-gray-600">{job?.message ?? "Working…"}</div>
              <div className="mt-2 h-2 w-full bg-gray-200 rounded">
                <div
                  className="h-2 bg-blue-600 rounded"
                  style={{ width: `${Math.max(0, Math.min(100, job?.progress ?? 0))}%` }}
                />
              </div>
              <div className="mt-2 text-sm text-gray-500">
                {job ? `${job.progress ?? 0}%` : "0%"}
              </div>
            </div>

            {actionError && <div className="mt-3 text-sm text-red-600">{actionError}</div>}

            {job?.status === "done" && (
              <div className="mt-4 flex gap-2">
                {job.format === "pdf" && (
                  <button
                    className="px-3 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700"
                    onClick={viewFile}
                  >
                    View PDF
                  </button>
                )}
                <button
                  className="px-3 py-2 rounded bg-gray-700 text-white hover:bg-gray-800"
                  onClick={downloadFile}
                >
                  Download
                </button>
              </div>
            )}

            {job?.status === "failed" && (
              <div className="mt-4 text-sm text-red-600">
                Generation failed. {job.message ?? ""}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
