import { useEffect, useMemo, useRef, useState, useLayoutEffect } from 'react';
import { HotTable, HotTableClass } from '@handsontable/react';
import Handsontable from 'handsontable';
import { NumericCellType } from 'handsontable/cellTypes';
import 'handsontable/dist/handsontable.full.min.css';

import Swal from 'sweetalert2';
import { toast, ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';

import napi from '../../../utils/axiosnapi';

import DropdownWithHeaders from '../../components/DropdownWithHeaders';
import type { DropdownItem } from '../../components/DropdownWithHeaders';

import {
  PrinterIcon, ChevronDownIcon, DocumentTextIcon,
  CheckCircleIcon, PlusIcon,
  ArrowDownTrayIcon, DocumentArrowDownIcon,
} from '@heroicons/react/24/outline';

Handsontable.cellTypes.registerCellType('numeric', NumericCellType);

// --- utils ---
function formatDateToYYYYMMDD(dateStr: string): string {
  if (!dateStr) return '';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return (dateStr || '').split('T')[0] || ''; // fallback
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

const onlyCode = (v?: string) => (v || '').split(';')[0];

type SalesDetailRow = {
  id?: number;
  acct_code: string;      // stores "code;desc"
  acct_desc?: string;
  debit?: number;
  credit?: number;
  persisted?: boolean;
};

type TxOption = {
  id: number | string;
  cs_no: string;
  cust_id: string;
  sales_date: string;
  sales_amount: number;
  si_no: string;
  explanation?: string;
  is_cancel?: string; // 'y' | 'n'
};

// approval status type
type ApprovalStatus = {
  id: number | null;
  status: string | null;
  approvedActive: boolean;
  expiresAt: string | null;
  editWindowMinutes: number | null;
  reason: string | null;
};

export default function SalesJournalForm() {
  const hotRef = useRef<HotTableClass>(null);

  // header form state
  const [csNo, setCsNo] = useState('');
  const [custId, setCustId] = useState('');
  const [custName, setCustName] = useState('');
  const [salesDate, setSalesDate] = useState('');
  const [explanation, setExplanation] = useState('');
  const [siNo, setSiNo] = useState('');
  const [mainId, setMainId] = useState<number | null>(null);
  const [_locked, setLocked] = useState(false);

  // cancelled state (like GA)
  const [isCancelled, setIsCancelled] = useState(false);

  // grid lock (separate from header lock)
  const [gridLocked, setGridLocked] = useState(true);

  // dropdown data
  const [customers, setCustomers] = useState<DropdownItem[]>([]);
  const [accounts, setAccounts] = useState<{ acct_code: string; acct_desc: string }[]>([]);
  const [custSearch, setCustSearch] = useState('');

  // search transaction dropdown
  const [searchId, setSearchId] = useState<string>('');
  const [txSearch, setTxSearch] = useState<string>('');
  const [txOptions, setTxOptions] = useState<TxOption[]>([]);

  // grid
  const [tableData, setTableData] = useState<SalesDetailRow[]>([
    { acct_code: '', acct_desc: '', debit: 0, credit: 0, persisted: false },
  ]);
  const [hotEnabled, setHotEnabled] = useState(false);

  // menus
  const [printOpen, setPrintOpen] = useState(false);
  const [downloadOpen, setDownloadOpen] = useState(false);
  const [pdfUrl, setPdfUrl] = useState<string | undefined>(undefined);
  const [showPdf, setShowPdf] = useState(false);

  // layout helpers
  const detailsWrapRef = useRef<HTMLDivElement>(null);
  const [maxGridHeight, setMaxGridHeight] = useState<number>(600);
  useLayoutEffect(() => {
    const update = () => {
      const rect = detailsWrapRef.current?.getBoundingClientRect();
      const top = rect?.top ?? 0;
      const available = window.innerHeight - top - 140;
      setMaxGridHeight(Math.max(320, Math.floor(available)));
    };
    update();
    window.addEventListener('resize', update);
    return () => window.removeEventListener('resize', update);
  }, []);
  const ROW_HEIGHT = 28,
    HEADER_HEIGHT = 32,
    DROPDOWN_ROOM = 240;
  const dynamicHeight = useMemo(() => {
    const rows = Math.max(tableData.length, 6);
    const desired = HEADER_HEIGHT + rows * ROW_HEIGHT + DROPDOWN_ROOM;
    return Math.min(desired, maxGridHeight);
  }, [tableData.length, maxGridHeight]);

  const user = useMemo(() => {
    const s = localStorage.getItem('user');
    return s ? JSON.parse(s) : null;
  }, []);

  // module code for approvals (must match backend)
  const MODULE_CODE = 'sales_journal';

  // approval state
  const [approval, setApproval] = useState<ApprovalStatus>({
    id: null,
    status: null,
    approvedActive: false,
    expiresAt: null,
    editWindowMinutes: null,
    reason: null,
  });

  // approvals for cancel / uncancel / delete
  const [cancelApproval, setCancelApproval] = useState<ApprovalStatus>({
    id: null,
    status: null,
    approvedActive: false,
    expiresAt: null,
    editWindowMinutes: null,
    reason: null,
  });

  const [uncancelApproval, setUncancelApproval] = useState<ApprovalStatus>({
    id: null,
    status: null,
    approvedActive: false,
    expiresAt: null,
    editWindowMinutes: null,
    reason: null,
  });

  const [deleteApproval, setDeleteApproval] = useState<ApprovalStatus>({
    id: null,
    status: null,
    approvedActive: false,
    expiresAt: null,
    editWindowMinutes: null,
    reason: null,
  });

  const isApprovalPending = approval.status === 'pending';
  const isApprovalRejected = approval.status === 'rejected';

  const approvalLabel = useMemo(() => {
    if (!approval.approvedActive || !approval.expiresAt) return '';
    const expires = new Date(approval.expiresAt);
    const ms = expires.getTime() - Date.now();
    const mins = Math.max(0, Math.round(ms / 60000));
    if (mins <= 0) return 'Edit window expiring now';
    return `Edit window: ${mins} min left`;
  }, [approval.approvedActive, approval.expiresAt]);

  // fetch approval status for this record (EDIT)
  const refreshApprovalStatus = async (recordId: number | null) => {
    if (!recordId || !user?.company_id) {
      setApproval({
        id: null,
        status: null,
        approvedActive: false,
        expiresAt: null,
        editWindowMinutes: null,
        reason: null,
      });
      setLocked(!!recordId);
      setGridLocked(!!recordId);
      return;
    }

    try {
      const { data } = await napi.get('/approvals/status', {
        params: {
          module: MODULE_CODE,
          record_id: recordId,
          company_id: user.company_id,
          action: 'edit',
        },
      });

      if (!data?.exists) {
        setApproval({
          id: null,
          status: null,
          approvedActive: false,
          expiresAt: null,
          editWindowMinutes: null,
          reason: null,
        });
        setLocked(true);
        setGridLocked(true);
        return;
      }

      setApproval({
        id: data.id ?? null,
        status: data.status ?? null,
        approvedActive: !!data.approved_active,
        expiresAt: data.expires_at ?? null,
        editWindowMinutes: data.edit_window_minutes ?? null,
        reason: data.reason ?? null,
      });

      const canEdit = !!data.approved_active;
      setLocked(!canEdit);
      setGridLocked(!canEdit);
    } catch {
      setApproval({
        id: null,
        status: null,
        approvedActive: false,
        expiresAt: null,
        editWindowMinutes: null,
        reason: null,
      });
      setLocked(true);
      setGridLocked(true);
    }
  };

  // helper: fetch approval for cancel / uncancel / delete
  const refreshApprovalForAction = async (
    recordId: number | null,
    action: 'cancel' | 'uncancel' | 'delete',
    setter: (a: ApprovalStatus) => void,
  ) => {
    if (!recordId || !user?.company_id) {
      setter({
        id: null,
        status: null,
        approvedActive: false,
        expiresAt: null,
        editWindowMinutes: null,
        reason: null,
      });
      return;
    }

    try {
      const { data } = await napi.get('/approvals/status', {
        params: {
          module: MODULE_CODE,
          record_id: recordId,
          company_id: user.company_id,
          action,
        },
      });

      if (!data?.exists) {
        setter({
          id: null,
          status: null,
          approvedActive: false,
          expiresAt: null,
          editWindowMinutes: null,
          reason: null,
        });
        return;
      }

      setter({
        id: data.id ?? null,
        status: data.status ?? null,
        approvedActive: !!data.approved_active,
        expiresAt: data.expires_at ?? null,
        editWindowMinutes: data.edit_window_minutes ?? null,
        reason: data.reason ?? null,
      });
    } catch {
      setter({
        id: null,
        status: null,
        approvedActive: false,
        expiresAt: null,
        editWindowMinutes: null,
        reason: null,
      });
    }
  };

  // fetch customers
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/customers', {
          params: { company_id: user?.company_id },
        });
        const mapped: DropdownItem[] = (data || []).map((c: any) => ({
          code: String(c.cust_id ?? c.code),
          description: c.cust_name ?? c.description ?? '',
        }));
        setCustomers(mapped);
      } catch {
        setCustomers([]);
      }
    })();
  }, [user?.company_id]);

  // fetch accounts
  useEffect(() => {
    (async () => {
      try {
        const { data } = await napi.get('/accounts', {
          params: { company_id: user?.company_id },
        });
        setAccounts(Array.isArray(data) ? data : []);
      } catch {
        setAccounts([]);
      }
    })();
  }, [user?.company_id]);

  // search transaction (cash_sales) list
  const fetchTransactions = async () => {
    try {
      const { data } = await napi.get<TxOption[]>('/sales/list', {
        params: { company_id: user?.company_id || '', q: txSearch || '' },
      });
      setTxOptions(Array.isArray(data) ? data : []);
    } catch {
      setTxOptions([]);
    }
  };
  useEffect(() => {
    fetchTransactions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [txSearch]);

  // map TxOption[] -> DropdownItem[]
  const txDropdownItems = useMemo<DropdownItem[]>(() => {
    return (txOptions || []).map((o) => ({
      code: String(o.id),
      cs_no: o.cs_no,
      cust_id: o.cust_id,
      sales_date: formatDateToYYYYMMDD(o.sales_date),
      sales_amount: o.sales_amount,
      si_no: o.si_no,
      label: o.cs_no,
      description: o.cust_id,
    }));
  }, [txOptions]);

  const emptyRow = (): SalesDetailRow => ({
    acct_code: '',
    acct_desc: '',
    debit: 0,
    credit: 0,
    persisted: false,
  });

  const resetForm = () => {
    setSearchId('');
    setTxSearch('');
    setCsNo('');
    setCustId('');
    setCustName('');
    setSalesDate('');
    setExplanation('');
    setSiNo('');
    setMainId(null);
    setLocked(false);
    setGridLocked(false);
    setHotEnabled(false);
    setIsCancelled(false);
    setTableData([emptyRow()]);

    // reset approval states
    setApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setCancelApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setUncancelApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setDeleteApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
  };

  // Save main
  const handleSaveMain = async () => {
    const ok = await Swal.fire({
      title: 'Confirm Save?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Save',
    });
    if (!ok.isConfirmed) return;

    // preflight: other unbalanced transactions
    try {
      const existsResp = await napi.get('/sales/unbalanced-exists', {
        params: { company_id: user?.company_id || '' },
      });

      if (existsResp.data?.exists) {
        const listResp = await napi.get('/sales/unbalanced', {
          params: { company_id: user?.company_id || '', limit: 20 },
        });
        const items = Array.isArray(listResp.data?.items)
          ? listResp.data.items
          : [];

        const htmlRows = items
          .map(
            (r: any) => `
          <tr>
            <td style="padding:6px 8px">${r.cs_no}</td>
            <td style="padding:6px 8px">${r.cust_id}</td>
            <td style="padding:6px 8px;text-align:right">${Number(
              r.sum_debit || 0,
            ).toLocaleString()}</td>
            <td style="padding:6px 8px;text-align:right">${Number(
              r.sum_credit || 0,
            ).toLocaleString()}</td>
          </tr>`,
          )
          .join('');

        const html = `
        <div style="text-align:left">
          <div style="margin-bottom:8px">
            There are unbalanced transactions. Please balance them first before creating a new one.
          </div>
          <table style="width:100%;border-collapse:collapse;font-size:12px">
            <thead>
              <tr>
                <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">CS #</th>
                <th style="text-align:left;border-bottom:1px solid #ddd;padding:6px 8px">Customer</th>
                <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Debit</th>
                <th style="text-align:right;border-bottom:1px solid #ddd;padding:6px 8px">Credit</th>
              </tr>
            </thead>
            <tbody>
              ${
                htmlRows ||
                '<tr><td colspan="4" style="padding:6px 8px;color:#6b7280">No detail available</td></tr>'
              }
            </tbody>
          </table>
        </div>`;

        await Swal.fire({
          icon: 'warning',
          title: 'Unbalanced transactions found',
          html,
          confirmButtonText: 'OK',
        });

        return;
      }
    } catch (err) {
      console.error('Unbalanced check failed', err);
    }

    try {
      const gen = await napi.get('/sales/generate-cs-number', {
        params: { company_id: user?.company_id },
      });
      const nextNo = gen.data?.cs_no ?? gen.data;
      setCsNo(nextNo);

      const res = await napi.post('/sales/save-main', {
        cs_no: nextNo,
        cust_id: custId,
        sales_date: salesDate,
        explanation,
        si_no: siNo,
        company_id: user?.company_id,
        user_id: user?.id,
      });

      setMainId(res.data.id);
      setHotEnabled(true);
      setTableData([emptyRow(), emptyRow()]);
      toast.success('Main saved. You can now input details.');
      fetchTransactions();

      // lock header after save; grid editable
      setLocked(true);
      setGridLocked(false);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save.');
    }
  };


const handleSaveMainNoApproval = async () => {
  const ok = await Swal.fire({
    title: 'Save main details?',
    icon: 'question',
    showCancelButton: true,
    confirmButtonText: 'Yes, Save',
  });
  if (!ok.isConfirmed) return;

  try {
    await napi.post('/sales/update-main-no-approval', {
      id: mainId,
      cust_id: custId,
      sales_date: salesDate,
      explanation,
      si_no: siNo,
      company_id: user?.company_id,
    });

    toast.success('Main details updated.');
  } catch (e: any) {
    toast.error(e?.response?.data?.message || 'Failed to save main.');
  }
};




  // Cancel / Uncancel with approvals
  const handleCancelTxn = async () => {
    if (!mainId || !user?.company_id) return;

    // CANCEL path
    if (!isCancelled) {
      if (!cancelApproval.approvedActive) {
        const { value: reason } = await Swal.fire({
          title: 'Request Cancel Approval',
          input: 'textarea',
          inputLabel: 'Reason for cancellation',
          inputPlaceholder:
            'Explain why this transaction needs to be cancelled...',
          inputAttributes: { 'aria-label': 'Reason for cancellation' },
          showCancelButton: true,
        });

        if (reason === undefined) return;

        try {
          await napi.post('/approvals/request-edit', {
            module: MODULE_CODE,
            record_id: mainId,
            company_id: user.company_id,
            action: 'cancel',
            reason: reason || '',
          });

          toast.success('Cancel approval request sent to supervisor.');
          await refreshApprovalForAction(mainId, 'cancel', setCancelApproval);
        } catch (e: any) {
          toast.error(
            e?.response?.data?.message ||
              'Failed to send cancel approval request.',
          );
        }

        return;
      }

      const confirmed = await Swal.fire({
        title: 'Cancel this transaction?',
        text: 'This will mark the transaction as CANCELLED.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
      });
      if (!confirmed.isConfirmed) return;

      try {
        await napi.post('/sales/cancel', {
          id: mainId,
          flag: '1', // backend maps this to is_cancel = 'c'
          company_id: user.company_id,
        });

        await napi.post('/approvals/release', {
          module: MODULE_CODE,
          record_id: mainId,
          company_id: user.company_id,
          action: 'cancel',
        });

        setIsCancelled(true);
        setLocked(true);
        setGridLocked(true);

        toast.success('Transaction has been cancelled.');
        fetchTransactions();

        await Promise.all([
          refreshApprovalStatus(mainId),
          refreshApprovalForAction(mainId, 'cancel', setCancelApproval),
          refreshApprovalForAction(mainId, 'uncancel', setUncancelApproval),
          refreshApprovalForAction(mainId, 'delete', setDeleteApproval),
        ]);
      } catch (e: any) {
        toast.error(
          e?.response?.data?.message || 'Failed to cancel transaction.',
        );
      }

      return;
    }

    // UNCANCEL path
    if (!uncancelApproval.approvedActive) {
      const { value: reason } = await Swal.fire({
        title: 'Request Uncancel Approval',
        input: 'textarea',
        inputLabel: 'Reason for uncancelling',
        inputPlaceholder:
          'Explain why this transaction needs to be uncancelled...',
        inputAttributes: { 'aria-label': 'Reason for uncancelling' },
        showCancelButton: true,
      });

      if (reason === undefined) return;

      try {
        await napi.post('/approvals/request-edit', {
          module: MODULE_CODE,
          record_id: mainId,
          company_id: user.company_id,
          action: 'uncancel',
          reason: reason || '',
        });

        toast.success('Uncancel approval request sent to supervisor.');
        await refreshApprovalForAction(mainId, 'uncancel', setUncancelApproval);
      } catch (e: any) {
        toast.error(
          e?.response?.data?.message ||
            'Failed to send uncancel approval request.',
        );
      }

      return;
    }

    const confirmed = await Swal.fire({
      title: 'Uncancel this transaction?',
      text: 'This will re-open the transaction (subject to edit approvals).',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, uncancel it',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.post('/sales/cancel', {
        id: mainId,
        flag: '0', // backend maps this to is_cancel = 'n'
        company_id: user.company_id,
      });

      await napi.post('/approvals/release', {
        module: MODULE_CODE,
        record_id: mainId,
        company_id: user.company_id,
        action: 'uncancel',
      });

      setIsCancelled(false);

      toast.success('Transaction has been uncancelled.');
      fetchTransactions();

      await Promise.all([
        refreshApprovalStatus(mainId),
        refreshApprovalForAction(mainId, 'cancel', setCancelApproval),
        refreshApprovalForAction(mainId, 'uncancel', setUncancelApproval),
        refreshApprovalForAction(mainId, 'delete', setDeleteApproval),
      ]);
    } catch (e: any) {
      toast.error(
        e?.response?.data?.message || 'Failed to uncancel transaction.',
      );
    }
  };

  // Delete with approval
  const handleDeleteTxn = async () => {
    if (!mainId || !user?.company_id) return;

    if (!deleteApproval.approvedActive) {
      const { value: reason } = await Swal.fire({
        title: 'Request Delete Approval',
        input: 'textarea',
        inputLabel: 'Reason for delete',
        inputPlaceholder:
          'Explain why this transaction should be deleted...',
        inputAttributes: { 'aria-label': 'Reason for delete' },
        showCancelButton: true,
      });

      if (reason === undefined) return;

      try {
        await napi.post('/approvals/request-edit', {
          module: MODULE_CODE,
          record_id: mainId,
          company_id: user.company_id,
          action: 'delete',
          reason: reason || '',
        });

        toast.success('Delete approval request sent to supervisor.');
        await refreshApprovalForAction(mainId, 'delete', setDeleteApproval);
      } catch (e: any) {
        toast.error(
          e?.response?.data?.message ||
            'Failed to send delete approval request.',
        );
      }

      return;
    }

    const confirmed = await Swal.fire({
      title: 'Delete this transaction?',
      text: 'This action is irreversible.',
      icon: 'error',
      showCancelButton: true,
      confirmButtonText: 'Delete',
    });
    if (!confirmed.isConfirmed) return;

    try {
      await napi.delete(`/sales/${mainId}`);

      await napi.post('/approvals/release', {
        module: MODULE_CODE,
        record_id: mainId,
        company_id: user.company_id,
        action: 'delete',
      });

      resetForm();
      toast.success('Transaction deleted.');
      fetchTransactions();
    } catch (e: any) {
      toast.error(
        e?.response?.data?.message || 'Failed to delete transaction.',
      );
    }
  };

  // Request edit approval
  const handleRequestEdit = async () => {
    if (!mainId || !user?.company_id) return;

    const { value: reason } = await Swal.fire({
      title: 'Request Edit Approval',
      input: 'textarea',
      inputLabel: 'Reason for edit',
      inputPlaceholder: 'Explain why you need to edit this entry...',
      inputAttributes: { 'aria-label': 'Reason for edit' },
      showCancelButton: true,
    });

    if (reason === undefined) return;

    try {
      await napi.post('/approvals/request-edit', {
        module: MODULE_CODE,
        record_id: mainId,
        company_id: user.company_id,
        action: 'edit',
        reason: reason || '',
      });

      toast.success('Edit approval request sent to supervisor.');
      await refreshApprovalStatus(mainId);
    } catch (e: any) {
      toast.error(
        e?.response?.data?.message || 'Failed to send approval request.',
      );
    }
  };

  // Save header when approval is active, then release it
  const handleSaveHeader = async () => {
    if (!mainId || !user?.company_id) return;

    try {
      await napi.post('/sales/update-main', {
        id: mainId,
        cust_id: custId,
        sales_date: salesDate,
        explanation,
        si_no: siNo,
      });

      if (approval.id) {
        await napi.post('/approvals/release', {
          module: MODULE_CODE,
          record_id: mainId,
          company_id: user.company_id,
          action: 'edit',
        });
      }

      toast.success('Sales journal header saved and edit session closed.');
      await refreshApprovalStatus(mainId);
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Failed to save header.');
    }
  };

  // row validator
  const isRowValid = (r: SalesDetailRow) =>
    !!onlyCode(r.acct_code) &&
    ((r.debit ?? 0) > 0) !== ((r.credit ?? 0) > 0);

  // autosave detail
  const handleAutoSave = async (row: SalesDetailRow, rowIndex: number) => {
    if (!mainId || isCancelled) return;
    if (!isRowValid(row)) return;

    const code = onlyCode(row.acct_code);
    try {
      if (!row.persisted) {
        const res = await napi.post('/sales/save-detail', {
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
          company_id: user?.company_id,
          user_id: user?.id,
        });
        const src =
          (hotRef.current?.hotInstance?.getSourceData() as SalesDetailRow[]) ||
          [];
        if (src[rowIndex]) {
          src[rowIndex].persisted = true;
          src[rowIndex].id = res.data.detail_id;
        }
        setTableData([
          ...src,
          ...(src.find((r) => !r.acct_code) ? [] : [emptyRow()]),
        ]);
      } else {
        await napi.post('/sales/update-detail', {
          id: row.id,
          transaction_id: mainId,
          acct_code: code,
          debit: row.debit || 0,
          credit: row.credit || 0,
        });
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Row save failed');
    }
  };

  // select transaction from search dropdown
  const handleSelectTransaction = async (selectedId: string) => {
    if (!selectedId) return;
    try {
      setSearchId(selectedId);
      const { data } = await napi.get(`/sales/${selectedId}`, {
        params: { company_id: user?.company_id },
      });

      const m = data.main ?? data;
      setMainId(m.id);
      setCsNo(m.cs_no);
      setCustId(String(m.cust_id || ''));
      const selected = customers.find(
        (c) => String(c.code) === String(m.cust_id),
      );
      setCustName(selected?.description || selected?.label || '');
      setSalesDate(formatDateToYYYYMMDD(m.sales_date));
      setExplanation(m.explanation ?? '');
      setSiNo(m.si_no ?? '');
      setIsCancelled(m.is_cancel === 'c' || m.is_cancel === 'y');

      const details = (data.details || []).map((d: any) => ({
        id: d.id,
        acct_code: `${d.acct_code};${
          d.acct_desc || findDesc(d.acct_code)
        }`,
        acct_desc: d.acct_desc,
        debit: Number(d.debit ?? 0),
        credit: Number(d.credit ?? 0),
        persisted: true,
      }));
      setTableData(
        details.length ? details.concat([emptyRow()]) : [emptyRow()],
      );
      setHotEnabled(true);
      setLocked(true);
      setGridLocked(true);

      await refreshApprovalStatus(m.id);
      await Promise.all([
        refreshApprovalForAction(m.id, 'cancel', setCancelApproval),
        refreshApprovalForAction(m.id, 'uncancel', setUncancelApproval),
        refreshApprovalForAction(m.id, 'delete', setDeleteApproval),
      ]);

      toast.success('Transaction loaded.');
    } catch {
      toast.error('Unable to load the selected transaction.');
    }
  };

  const acctSource = useMemo(
    () => accounts.map((a) => `${a.acct_code};${a.acct_desc}`),
    [accounts],
  );

  const findDesc = (code: string) => {
    const hit = accounts.find((a) => a.acct_code === code);
    return hit?.acct_desc || '';
  };

  // totals helpers
  const getTotals = (rows: SalesDetailRow[]) => {
    const sumD = rows.reduce(
      (t, r) => t + (Number(r.debit) || 0),
      0,
    );
    const sumC = rows.reduce(
      (t, r) => t + (Number(r.credit) || 0),
      0,
    );
    const balanced = Math.abs(sumD - sumC) < 0.005;
    return { sumD, sumC, balanced };
  };

  const fmtMoney = (n: number) =>
    (Number(n) || 0).toLocaleString(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  // 👇 NEW: totals used for the header badges (like GA)
  const totals = useMemo(() => getTotals(tableData), [tableData]);

  // New transaction
  const handleNew = async () => {
    const hasAnyDetail = tableData.some(
      (r) =>
        (r.acct_code && r.acct_code.trim() !== '') ||
        (Number(r.debit) || 0) > 0 ||
        (Number(r.credit) || 0) > 0,
    );

    if (hasAnyDetail) {
      const { sumD, sumC, balanced } = getTotals(tableData);

      if (!balanced) {
        await Swal.fire({
          title: 'Transaction not balanced',
          html: `Debit <b>${fmtMoney(
            sumD,
          )}</b> must equal Credit <b>${fmtMoney(
            sumC,
          )}</b> before starting a new one.`,
          icon: 'error',
        });
        return;
      }

      const ok = await Swal.fire({
        title: 'Start a new transaction?',
        text: 'This will clear the current form.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, New',
      });
      if (!ok.isConfirmed) return;
    } else {
      const ok = await Swal.fire({
        title: 'Start a new transaction?',
        text: 'This will clear the current form.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, New',
      });
      if (!ok.isConfirmed) return;
    }

    setSearchId('');
    setTxSearch('');
    setMainId(null);
    setCsNo('');
    setCustId('');
    setCustName('');
    setSalesDate('');
    setExplanation('');
    setSiNo('');
    setLocked(false);
    setGridLocked(false);
    setIsCancelled(false);
    setTableData([emptyRow(), emptyRow(), emptyRow(), emptyRow()]);
    setHotEnabled(false);

    // clear approvals
    setApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setCancelApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setUncancelApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });
    setDeleteApproval({
      id: null,
      status: null,
      approvedActive: false,
      expiresAt: null,
      editWindowMinutes: null,
      reason: null,
    });

    toast.success('Form is ready for a new entry.');
  };

  // auto-show list when entering the Account Code cell
  const ACCT_COL_INDEX = 0;
  const afterBeginEditingOpenAll = (_row: number, col: number) => {
    if (gridLocked || isCancelled || col !== ACCT_COL_INDEX) return;
    const hot = hotRef.current?.hotInstance;
    const ed: any = hot?.getActiveEditor();
    if (ed?.cellProperties?.type === 'autocomplete') {
      ed.TEXTAREA.value = '';
      ed.query = '';
      ed.open();
      ed.refreshDropdown?.();
    }
  };

  // Download & Print
  const handleOpenPdf = () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    if (isCancelled) return toast.info('Cancelled transactions cannot be printed.');

    const url = `/api/sales/form-pdf/${mainId}?company_id=${encodeURIComponent(
      user?.company_id || '',
    )}&t=${Date.now()}`;

    setPdfUrl(url);
    setShowPdf(true);
  };

  const handleDownloadExcel = async () => {
    if (!mainId) return toast.info('Select or save a transaction first.');
    if (isCancelled) return toast.info('Cancelled transactions cannot be downloaded.');

    const res = await napi.get(`/sales/form-excel/${mainId}`, {
      responseType: 'blob',
      params: { company_id: user?.company_id || '' },
    });
    const name =
      res.headers['content-disposition']?.match(
        /filename="?([^"]+)"?/,
      )?.[1] || `SalesVoucher_${csNo || mainId}.xlsx`;
    const blob = new Blob([res.data], {
      type:
        res.headers['content-type'] ||
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="min-h-screen pb-40 space-y-4 p-6">
      <ToastContainer position="top-right" autoClose={3000} />

      <div className="bg-yellow-50 shadow-md rounded-lg p-6 space-y-4 border border-yellow-400">
        <h2 className="text-xl font-bold text-green-800 mb-2">
          SALES JOURNAL
        </h2>

        {/* Header form */}
        <div className="grid grid-cols-3 gap-4">
          {/* Search Transaction */}
          <div className="col-span-3">
            <DropdownWithHeaders
              label="Search Transaction"
              value={searchId}
              onChange={(v) => handleSelectTransaction(v)}
              items={txDropdownItems}
              search={txSearch}
              onSearchChange={setTxSearch}
              headers={['Id', 'Sales No', 'Customer', 'Date', 'Amount', 'S.I. #']}
              columnWidths={['60px', '100px', '160px', '100px', '110px', '100px']}
              dropdownPositionStyle={{ width: '750px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          {/* Customer + Date */}
          <div className="col-span-2">
            <DropdownWithHeaders
              label="Customer"
              value={custId}
              onChange={(v) => {
                setCustId(v);
                const sel = customers.find(
                  (c) => String(c.code) === String(v),
                );
                setCustName(sel?.description || '');
              }}
              items={customers}
              search={custSearch}
              onSearchChange={setCustSearch}
              headers={['Code', 'Description']}
              columnWidths={['140px', '520px']}
              dropdownPositionStyle={{ width: '700px' }}
              inputClassName="p-2 text-sm bg-white"
            />
          </div>

          <div>
            <label className="block mb-1">Date</label>
            <input
              type="date"
              value={salesDate}
              disabled={isCancelled}
              onChange={(e) => setSalesDate(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {/* Explanation + S.I. # */}
          <div className="col-span-2">
            <label className="block mb-1">Explanation</label>
            <input
              value={explanation}
              disabled={isCancelled}
              onChange={(e) => setExplanation(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          <div>
            <label className="block mb-1">S.I. #</label>
            <input
              value={siNo}
              disabled={isCancelled}
              onChange={(e) => setSiNo(e.target.value)}
              className="w-full border p-2 bg-green-100 text-green-900"
            />
          </div>

          {custName && (
            <div className="col-span-3 text-sm font-semibold text-gray-700">
              Customer: {custName}
            </div>
          )}

          {/* 👇 NEW: CS No + totals + Balanced + CANCELLED (like GA) */}
          <div className="col-span-3 flex items-center gap-6 text-sm mt-1">
            <div className="font-semibold">
              CS No:{' '}
              <span className="text-gray-800">{csNo || '—'}</span>
            </div>
            <div className="font-semibold">
              Total Debit:{' '}
              <span className="text-blue-700">
                {fmtMoney(totals.sumD)}
              </span>
            </div>
            <div className="font-semibold">
              Total Credit:{' '}
              <span className="text-blue-700">
                {fmtMoney(totals.sumC)}
              </span>
            </div>
            <div
              className={`px-2 py-0.5 rounded text-white ${
                totals.balanced ? 'bg-emerald-600' : 'bg-red-500'
              }`}
            >
              {totals.balanced ? 'Balanced' : 'Unbalanced'}
            </div>
            {isCancelled && (
              <div className="px-2 py-0.5 rounded bg-amber-600 text-white">
                CANCELLED
              </div>
            )}
          </div>
        </div>

        {/* Actions – approval-aware like GA */}
        <div className="mt-3 space-y-1">
          <div className="flex gap-2 items-center">
            {!mainId ? (
              <button
                onClick={handleSaveMain}
                className="inline-flex items-center gap-2 px-4 py-2 rounded bg-green-600 text-white hover:bg-green-700"
              >
                <CheckCircleIcon className="h-5 w-5" />
                Save
              </button>
            ) : (
              <>
                {!isCancelled && (
                  <>
                    {approval.approvedActive ? (
                      <button
                        type="button"
                        onClick={handleSaveHeader}
                        className="inline-flex items-center gap-2 px-4 py-2 rounded bg-emerald-600 text-white hover:bg-emerald-700"
                      >
                        <CheckCircleIcon className="h-5 w-5" />
                        Save changes
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={handleRequestEdit}
                        disabled={isApprovalPending}
                        className={
                          'inline-flex items-center gap-2 px-4 py-2 rounded text-white ' +
                          (isApprovalPending
                            ? 'bg-purple-400 cursor-not-allowed opacity-70'
                            : 'bg-purple-600 hover:bg-purple-700')
                        }
                      >
                        <CheckCircleIcon className="h-5 w-5" />
                        {isApprovalPending
                          ? 'Edit Request Pending'
                          : 'Request to Edit'}
                      </button>
                    )}
                  </>
                )}

                <button
                  onClick={handleCancelTxn}
                  className={
                    'inline-flex items-center gap-2 px-4 py-2 rounded text-white ' +
                    (isCancelled
                      ? 'bg-gray-600 hover:bg-gray-700'
                      : 'bg-amber-500 hover:bg-amber-600')
                  }
                >
                  {isCancelled ? 'Uncancel' : 'Cancel'}
                </button>

                <button
                  onClick={handleDeleteTxn}
                  className="inline-flex items-center gap-2 px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700"
                >
                  Delete
                </button>

                {mainId && !isCancelled && (
                  <button
                    onClick={handleSaveMainNoApproval}
                    className="inline-flex items-center gap-2 px-4 py-2 rounded bg-gray-600 text-white hover:bg-gray-700"
                  >
                    <CheckCircleIcon className="h-5 w-5" />
                    Save Main
                  </button>
                )}


              </>
            )}
          </div>

          {mainId && !isCancelled && (
            <>
              {approval.approvedActive && approvalLabel && (
                <div className="text-xs text-emerald-700">
                  Edit approved —{' '}
                  <span className="font-semibold">{approvalLabel}</span>
                </div>
              )}

              {isApprovalPending && !approval.approvedActive && (
                <div className="text-xs text-amber-700">
                  Edit approval is{' '}
                  <span className="font-semibold">pending</span>
                  {approval.reason
                    ? ` — Reason: ${approval.reason}`
                    : ''}
                </div>
              )}

              {isApprovalRejected && !approval.approvedActive && (
                <div className="text-xs text-red-700">
                  Last edit request was{' '}
                  <span className="font-semibold">rejected</span>
                  {approval.reason
                    ? ` — Reason: ${approval.reason}`
                    : ''}
                </div>
              )}
            </>
          )}

          {mainId && isCancelled && (
            <div className="text-xs text-red-700 font-semibold mt-1">
              This transaction is{' '}
              <span className="font-bold">CANCELLED</span>. It cannot
              be edited, printed, or downloaded.
            </div>
          )}
        </div>
      </div>

      {/* DETAILS */}
      <div ref={detailsWrapRef} className="relative z-0">
        <h2 className="text-lg font-semibold text-gray-800 mb-2 mt-4">
          Details
        </h2>
        {hotEnabled && (
          <HotTable
            className="hot-enhanced"
            ref={hotRef}
            data={tableData}
            colHeaders={[
              'Account Code',
              'Account Description',
              'Debit',
              'Credit',
            ]}
            columns={[
              {
                data: 'acct_code',
                type: 'autocomplete',
                source: (q: string, cb: (s: string[]) => void) => {
                  const list = acctSource;
                  if (!q) return cb(list);
                  const term = String(q).toLowerCase();
                  cb(
                    list.filter((t) =>
                      t.toLowerCase().includes(term),
                    ),
                  );
                },
                filter: true,
                strict: true,
                allowInvalid: false,
                visibleRows: 12,
                readOnly: gridLocked || isCancelled,
                renderer: (
                  inst,
                  td,
                  row,
                  col,
                  prop,
                  value,
                  cellProps,
                ) => {
                  const display = onlyCode(String(value ?? ''));
                  Handsontable.renderers.TextRenderer(
                    inst,
                    td,
                    row,
                    col,
                    prop,
                    display,
                    cellProps,
                  );
                },
              },
              { data: 'acct_desc', readOnly: true },
              {
                data: 'debit',
                type: 'numeric',
                numericFormat: { pattern: '0,0.00' },
                readOnly: gridLocked || isCancelled,
              },
              {
                data: 'credit',
                type: 'numeric',
                numericFormat: { pattern: '0,0.00' },
                readOnly: gridLocked || isCancelled,
              },
            ]}
            afterBeginEditing={afterBeginEditingOpenAll}
            afterChange={(changes, source) => {
              const hot = hotRef.current?.hotInstance;
              if (!changes || !hot || isCancelled) return;

              if (source === 'edit') {
                changes.forEach(
                  ([rowIndex, prop, _oldVal, newVal]) => {
                    if (prop === 'acct_code') {
                      const full = String(newVal || '');
                      const code = onlyCode(full);
                      hot.setDataAtRowProp(
                        rowIndex,
                        'acct_desc',
                        findDesc(code),
                      );

                      const rowObj = {
                        ...(hot.getSourceDataAtRow(
                          rowIndex,
                        ) as any),
                      } as SalesDetailRow;
                      const payload: SalesDetailRow = {
                        id: rowObj.id,
                        acct_code: full,
                        acct_desc: findDesc(code),
                        debit: Number(rowObj.debit || 0),
                        credit: Number(rowObj.credit || 0),
                        persisted: !!rowObj.persisted,
                      };
                      setTimeout(
                        () => handleAutoSave(payload, rowIndex),
                        0,
                      );
                    }

                    if (prop === 'debit' || prop === 'credit') {
                      const rowObj = {
                        ...(hot.getSourceDataAtRow(
                          rowIndex,
                        ) as any),
                      } as SalesDetailRow;
                      const payload: SalesDetailRow = {
                        ...rowObj,
                        acct_code: rowObj.acct_code,
                        debit: Number(
                          prop === 'debit'
                            ? newVal
                            : rowObj.debit || 0,
                        ),
                        credit: Number(
                          prop === 'credit'
                            ? newVal
                            : rowObj.credit || 0,
                        ),
                      };
                      setTimeout(
                        () => handleAutoSave(payload, rowIndex),
                        0,
                      );
                    }
                  },
                );

                requestAnimationFrame(() => {
                  const src =
                    (hot.getSourceData() as SalesDetailRow[]) ||
                    [];
                  if (!src.find((r) => !r.acct_code))
                    src.push(emptyRow());
                  setTableData([...src]);
                });
              }
            }}
            contextMenu={{
              items: {
                remove_row: {
                  name: '🗑️ Remove row',
                  callback: async (_key, selection) => {
                    if (gridLocked || isCancelled) return;
                    const hot = hotRef.current?.hotInstance;
                    const rowIndex = selection[0].start.row;
                    const src =
                      (hot?.getSourceData() as SalesDetailRow[]) ||
                      [];
                    const row = src[rowIndex];
                    if (!row?.id) {
                      src.splice(rowIndex, 1);
                      setTableData([...src]);
                      return;
                    }
                    const ok = await Swal.fire({
                      title: 'Delete this line?',
                      icon: 'warning',
                      showCancelButton: true,
                    });
                    if (!ok.isConfirmed) return;
                    await napi.post('/sales/delete-detail', {
                      id: row.id,
                      transaction_id: mainId,
                    });
                    src.splice(rowIndex, 1);
                    setTableData([...src]);
                    toast.success('Row deleted');
                  },
                },
              },
            }}
            manualColumnResize
            stretchH="all"
            height={dynamicHeight}
            rowHeaders
            licenseKey="non-commercial-and-evaluation"
          />
        )}
      </div>

      {/* Download / Print / New */}
      <div className="flex gap-3 mt-4 items-center">
        {/* DOWNLOAD */}
        <div
          className="relative inline-block"
          onMouseEnter={() => setDownloadOpen(true)}
          onMouseLeave={() => setDownloadOpen(false)}
        >
          <button
            type="button"
            disabled={!mainId || isCancelled}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              mainId && !isCancelled
                ? 'bg-white text-emerald-700 border-emerald-300 hover:bg-emerald-50'
                : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
            }`}
          >
            <ArrowDownTrayIcon
              className={`h-5 w-5 ${
                mainId && !isCancelled
                  ? 'text-emerald-600'
                  : 'text-gray-400'
              }`}
            />
            <span>Download</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {downloadOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-60 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleDownloadExcel}
                  disabled={!mainId || isCancelled}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    mainId && !isCancelled
                      ? 'text-gray-800 hover:bg-emerald-50'
                      : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentArrowDownIcon
                    className={`h-5 w-5 ${
                      mainId ? 'text-emerald-600' : 'text-gray-400'
                    }`}
                  />
                  <span className="truncate">
                    Sales Voucher – Excel
                  </span>
                  <span className="ml-auto text-[10px] font-semibold">
                    XLSX
                  </span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* PRINT */}
        <div
          className="relative inline-block"
          onMouseEnter={() => setPrintOpen(true)}
          onMouseLeave={() => setPrintOpen(false)}
        >
          <button
            type="button"
            disabled={!mainId || isCancelled}
            className={`inline-flex items-center gap-2 rounded border px-3 py-2 ${
              mainId && !isCancelled
                ? 'bg-white text-gray-700 hover:bg-gray-50'
                : 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
            }`}
          >
            <PrinterIcon className="h-5 w-5" />
            <span>Print</span>
            <ChevronDownIcon className="h-4 w-4 opacity-70" />
          </button>

          {printOpen && (
            <div className="absolute left-0 top-full z-50">
              <div className="mt-1 w-64 rounded-md border bg-white shadow-lg py-1">
                <button
                  type="button"
                  onClick={handleOpenPdf}
                  disabled={!mainId || isCancelled}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    mainId && !isCancelled
                      ? 'text-gray-800 hover:bg-gray-100'
                      : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">
                    Sales Voucher – PDF
                  </span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">
                    PDF
                  </span>
                </button>
                <button
                  type="button"
                  disabled={!mainId || isCancelled}
                  onClick={() => {
                    if (!mainId)
                      return toast.info('Select or save a transaction.');
                    if (isCancelled)
                      return toast.info(
                        'Cancelled transactions cannot be printed.',
                      );

                    window.open(
                      `/api/sales/check-pdf/${mainId}?company_id=${encodeURIComponent(
                        user?.company_id || '',
                      )}&t=${Date.now()}`,
                      '_blank',
                    );
                  }}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-sm ${
                    mainId && !isCancelled
                      ? 'text-gray-800 hover:bg-gray-100'
                      : 'text-gray-400 cursor-not-allowed'
                  }`}
                >
                  <DocumentTextIcon className="h-5 w-5 text-red-600" />
                  <span className="truncate">Print Check – PDF</span>
                  <span className="ml-auto text-[10px] font-semibold text-red-600">
                    PDF
                  </span>
                </button>
              </div>
            </div>
          )}
        </div>

        {/* NEW */}
        <button
          type="button"
          onClick={handleNew}
          className="inline-flex items-center gap-2 rounded border px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 focus:outline-none"
          title="Start a new transaction"
        >
          <PlusIcon className="h-5 w-5" />
          <span>New</span>
        </button>
      </div>

      {/* PDF modal */}
      {showPdf && (
        <div className="fixed inset-0 z-[10000] bg-black/50 flex items-center justify-center">
          <div className="bg-white rounded-lg shadow-xl w-[90vw] h-[85vh] relative">
            <button
              onClick={() => setShowPdf(false)}
              className="absolute top-2 right-2 rounded-full px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200"
              aria-label="Close"
            >
              ✕
            </button>
            <div className="h-full w-full pt-8">
              <iframe
                title="Sales Voucher PDF"
                src={pdfUrl}
                className="w-full h-full"
                style={{ border: 'none' }}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
