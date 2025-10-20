import { useEffect, useMemo, useRef, useState } from "react";
import napi from "../../../../utils/axiosnapi"; // axios withCredentials + CSRF baked in

type AccountRow = {
  acct_code: string;
  acct_desc?: string;
  acct_number?: number;
  main_acct?: string | null;
  main_acct_code?: string | null;
};

type JobState = {
  status: "queued" | "running" | "done" | "failed" | "missing";
  progress: number;
  message?: string;
  format?: "pdf" | "xls";
  orientation?: "landscape" | "portrait";
  file_path?: string | null;
  file_name?: string | null;
};

type StartPayload = {
  startAccount: string;
  endAccount: string;
  startDate: string;
  endDate: string;
  format?: "pdf" | "xls";
  orientation?: "portrait" | "landscape";
  company_id?: number;
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

export default function GeneralLedger() {
  const [accounts, setAccounts] = useState<AccountRow[]>([]);
  const [loadingAccounts, setLoadingAccounts] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [startAccount, setStartAccount] = useState<string>("");
  const [endAccount, setEndAccount] = useState<string>("");

  const [startDate, setStartDate] = useState<string>("2025-01-01");
  const [endDate, setEndDate] = useState<string>("2025-01-16");

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

  const user = useMemo(() => {
    try {
      const s = localStorage.getItem("user");
      return s ? JSON.parse(s) : null;
    } catch {
      return null;
    }
  }, []);

  const companyId = useMemo<number>(() =>
    Number(user?.company_id ?? user?.companyId ?? user?.company?.id) || 0,
  [user]);


  useEffect(() => {
    const load = async () => {
      setLoadingAccounts(true);
      setLoadError(null);
      try {
        const { data } = await napi.get("general-ledger/accounts", {
          params: { company_id: companyId },
        });
        if (!Array.isArray(data)) {
          throw new Error(
            "Accounts endpoint did not return an array. Check route group and napi baseURL."
          );
        }
        const rows: AccountRow[] = data.map((x: any): AccountRow => ({
          acct_code: x.acct_code ?? x.acctCode ?? x.code ?? "",
          acct_desc: x.acct_desc ?? x.acctDesc ?? x.description ?? "",
          acct_number: x.acct_number ?? x.acctNumber ?? undefined,
          main_acct: x.main_acct ?? null,
          main_acct_code: x.main_acct_code ?? null,
        }));
        const filtered = rows.filter((r) => r.acct_code);
        setAccounts(filtered);
        if (filtered.length) {
          setStartAccount(filtered[0].acct_code);
          setEndAccount(filtered[filtered.length - 1].acct_code);
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
  }, [companyId]);

  const startJob = async (payload: StartPayload) => {
    setActionError(null);
    setModalOpen(true);
    setJob({ status: "queued", progress: 0, message: "Queued" });
    setTicket(null);

    try {
      const { data } = await napi.post("general-ledger/report", payload);
      if (!data?.ticket) throw new Error("No ticket returned from server.");
      const t = data.ticket as string;
      setTicket(t);

      // start polling
      pollRef.current = window.setInterval(async () => {
        try {
          const { data: s } = await napi.get<JobState>(`general-ledger/report/${t}/status`);
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
      company_id: companyId,
    });
  };

  const handleExcel = () => {
    if (!startAccount || !endAccount || !startDate || !endDate) {
      setActionError("Please select account range and dates.");
      return;
    }
    startJob({
      startAccount,
      endAccount,
      startDate,
      endDate,
      format: "xls",
      orientation: "landscape",
      company_id: companyId,
    });
  };

  const viewFile = async () => {
    if (!ticket || !job) return;
    try {
      if (job.format === "pdf") {
        const res = await napi.get(`general-ledger/report/${ticket}/view`, {
          responseType: "blob",
        });
        openBlob(res.data);
      } else {
        const res = await napi.get(`general-ledger/report/${ticket}/download`, {
          responseType: "blob",
        });
        saveBlob(res.data, job.file_name || "general-ledger.xls");
      }
    } catch (err: any) {
      setActionError(err?.message ?? "Unable to open file.");
    }
  };

  const downloadFile = async () => {
    if (!ticket || !job) return;
    try {
      const res = await napi.get(`general-ledger/report/${ticket}/download`, {
        responseType: "blob",
      });
      const name =
        job.file_name ||
        (job.format === "xls" ? "general-ledger.xls" : "general-ledger.pdf");
      saveBlob(res.data, name);
    } catch (err: any) {
      setActionError(err?.message ?? "Unable to download file.");
    }
  };

  return (
    <div className="p-4">
      <h2 className="text-xl font-semibold mb-3">GENERAL LEDGER</h2>

      <div className="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div className="flex flex-col">
          <label className="text-sm text-gray-600 mb-1">Account (Start)</label>
          <select
            className="border rounded px-2 py-2"
            disabled={loadingAccounts}
            value={startAccount}
            onChange={(e) => setStartAccount(e.target.value)}
          >
            {accountOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col">
          <label className="text-sm text-gray-600 mb-1">Account (End)</label>
          <select
            className="border rounded px-2 py-2"
            disabled={loadingAccounts}
            value={endAccount}
            onChange={(e) => setEndAccount(e.target.value)}
          >
            {accountOptions.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
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

      {loadError && (
        <div className="mt-3 text-sm text-red-600">{loadError}</div>
      )}

      <div className="mt-4 flex flex-wrap gap-2">
        <button
          className="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleGenerate("landscape")}
          title="Generate (Landscape PDF)"
        >
          Generate
        </button>

        <button
          className="px-3 py-2 rounded bg-gray-700 text-white hover:bg-gray-800 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={() => handleGenerate("portrait")}
          title="Generate (Portrait PDF)"
        >
          Portrait
        </button>

        <button
          className="px-3 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-60"
          disabled={loadingAccounts || !accounts.length}
          onClick={handleExcel}
          title="Generate Excel"
        >
          EXCEL
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
