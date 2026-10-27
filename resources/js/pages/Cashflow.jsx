import { useCallback, useEffect, useMemo, useState } from "react";
import axios from "axios";
import ReactApexChart from "react-apexcharts";
import toast from "react-hot-toast";
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    Building2,
    CalendarDays,
    ChevronDown,
    CircleDollarSign,
    Coins,
    CreditCard,
    Landmark,
    LoaderCircle,
    Pencil,
    Plus,
    ReceiptText,
    RefreshCw,
    Scale,
    Trash2,
    TrendingUp,
    WalletCards,
    X,
} from "lucide-react";
import AppLayout from "../../views/components/layouts/AppLayout";

const accountTypes = {
    cash: [
        { value: "cash", label: "Kas tunai" },
        { value: "bank", label: "Rekening bank" },
        { value: "ewallet", label: "E-wallet" },
        { value: "marketplace", label: "Saldo marketplace" },
    ],
    asset: [
        { value: "investment", label: "Investasi" },
        { value: "property", label: "Properti" },
        { value: "other_asset", label: "Aset lainnya" },
    ],
};

const subtypeLabels = Object.values(accountTypes)
    .flat()
    .reduce((result, item) => ({ ...result, [item.value]: item.label }), {});

const emptyAccount = {
    name: "",
    category: "cash",
    subtype: "bank",
    balance: "",
    notes: "",
};

const emptyObligation = {
    kind: "debt",
    title: "",
    counterparty: "",
    amount: "",
    due_date: "",
    status: "open",
    notes: "",
};

function localDate(date = new Date()) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
}

function localDateTime(date = new Date()) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function dateDaysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);
    return localDate(date);
}

const emptyTransaction = {
    direction: "expense",
    financial_account_id: "",
    category: "Operasional",
    description: "",
    amount: "",
    occurred_at: localDateTime(),
    notes: "",
};

function formatRp(value) {
    return `Rp ${Math.round(Number(value || 0)).toLocaleString("id-ID")}`;
}

function formatDate(value, withTime = false) {
    if (!value) return "-";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat("id-ID", withTime
        ? { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }
        : { day: "2-digit", month: "short", year: "numeric" }
    ).format(date);
}

function SelectField({ className = "", children, ...props }) {
    return (
        <div className={`relative ${className}`}>
            <select
                {...props}
                className="h-10 w-full appearance-none rounded-md border border-slate-200 bg-white pl-3 pr-9 text-sm font-medium text-slate-700 outline-none transition focus:border-[#304674] focus:ring-2 focus:ring-[#304674]/10 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
            >
                {children}
            </select>
            <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        </div>
    );
}

function Modal({ title, subtitle, icon: Icon, onClose, children, footer }) {
    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        return () => { document.body.style.overflow = previous; };
    }, []);

    return (
        <div className="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/40 p-4 backdrop-blur-sm" onMouseDown={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                className="flex max-h-[calc(100dvh-32px)] w-full max-w-xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900"
                onMouseDown={(event) => event.stopPropagation()}
            >
                <header className="flex items-start gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-[#304674]/10 text-[#304674] dark:bg-blue-400/10 dark:text-blue-300">
                        <Icon className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <h2 className="text-base font-bold text-slate-900 dark:text-white">{title}</h2>
                        {subtitle && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" aria-label="Tutup">
                        <X className="h-4 w-4" />
                    </button>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto p-5">{children}</div>
                <footer className="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4 dark:border-slate-700 dark:bg-slate-950/40">
                    {footer}
                </footer>
            </div>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-slate-600 dark:text-slate-300">{label}</span>
            {children}
        </label>
    );
}

const inputClass = "h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#304674] focus:ring-2 focus:ring-[#304674]/10 dark:border-slate-700 dark:bg-slate-900 dark:text-white";
const textAreaClass = "min-h-20 w-full resize-y rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-[#304674] focus:ring-2 focus:ring-[#304674]/10 dark:border-slate-700 dark:bg-slate-900 dark:text-white";

function SummaryMetric({ label, value, description, icon: Icon, tone = "default", children }) {
    const emphasized = tone === "primary";
    return (
        <div className={`min-w-0 border-b border-slate-200 p-4 last:border-b-0 md:border-b-0 md:border-r md:last:border-r-0 dark:border-slate-700 ${emphasized ? "bg-[#304674] text-white" : "bg-white dark:bg-slate-900"}`}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className={`text-xs font-semibold ${emphasized ? "text-blue-100" : "text-slate-500 dark:text-slate-400"}`}>{label}</p>
                    <p className={`mt-2 truncate text-xl font-bold sm:text-2xl ${emphasized ? "text-white" : "text-slate-900 dark:text-white"}`} title={formatRp(value)}>{formatRp(value)}</p>
                    <p className={`mt-1 text-xs ${emphasized ? "text-blue-100" : "text-slate-400 dark:text-slate-500"}`}>{description}</p>
                    {children}
                </div>
                <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-md ${emphasized ? "bg-white/10 text-white" : "bg-slate-100 text-[#304674] dark:bg-slate-800 dark:text-blue-300"}`}>
                    <Icon className="h-4.5 w-4.5" />
                </div>
            </div>
        </div>
    );
}

function AccountRow({ account, onEdit, onDelete }) {
    const icons = {
        bank: Landmark,
        ewallet: WalletCards,
        marketplace: Building2,
        cash: Banknote,
        investment: TrendingUp,
        property: Building2,
        other_asset: Coins,
    };
    const Icon = icons[account.subtype] || CircleDollarSign;

    return (
        <div className="flex items-center gap-3 border-t border-slate-100 px-4 py-3 first:border-t-0 dark:border-slate-800">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                <Icon className="h-4 w-4" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{account.name}</p>
                <p className="text-xs text-slate-400">{subtypeLabels[account.subtype] || account.subtype}</p>
            </div>
            <p className="shrink-0 text-sm font-bold text-slate-900 dark:text-white">{formatRp(account.balance)}</p>
            <div className="flex shrink-0 gap-1">
                <button type="button" onClick={() => onEdit(account)} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-[#304674] dark:hover:bg-slate-800" title="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                <button type="button" onClick={() => onDelete(account)} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Hapus"><Trash2 className="h-3.5 w-3.5" /></button>
            </div>
        </div>
    );
}

function EmptyRows({ label }) {
    return <div className="px-4 py-7 text-center text-sm text-slate-400">{label}</div>;
}

export default function Cashflow() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [period, setPeriod] = useState("30_days");
    const [startDate, setStartDate] = useState(dateDaysAgo(29));
    const [endDate, setEndDate] = useState(localDate());
    const [modal, setModal] = useState(null);
    const [editing, setEditing] = useState(null);
    const [saving, setSaving] = useState(false);
    const [accountForm, setAccountForm] = useState(emptyAccount);
    const [obligationForm, setObligationForm] = useState(emptyObligation);
    const [transactionForm, setTransactionForm] = useState(emptyTransaction);
    const [deleteTarget, setDeleteTarget] = useState(null);

    const loadData = useCallback(async (silent = false) => {
        silent ? setRefreshing(true) : setLoading(true);
        try {
            const response = await axios.get("/api/cashflow", { params: { start_date: startDate, end_date: endDate } });
            setData(response.data.data);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal memuat data keuangan");
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, [startDate, endDate]);

    useEffect(() => { loadData(); }, [loadData]);

    const applyPeriod = (value) => {
        setPeriod(value);
        const today = localDate();
        setEndDate(today);
        if (value === "7_days") setStartDate(dateDaysAgo(6));
        if (value === "30_days") setStartDate(dateDaysAgo(29));
        if (value === "90_days") setStartDate(dateDaysAgo(89));
        if (value === "this_month") setStartDate(`${today.slice(0, 7)}-01`);
    };

    const cashAccounts = data?.accounts?.filter((account) => account.category === "cash") || [];
    const assetAccounts = data?.accounts?.filter((account) => account.category === "asset") || [];
    const manualDebt = data?.obligations?.filter((item) => item.kind === "debt" && item.status === "open") || [];
    const receivables = data?.obligations?.filter((item) => item.kind === "receivable" && item.status === "open") || [];

    const chartOptions = useMemo(() => ({
        chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: "Geist Variable, sans-serif" },
        colors: ["#0f9f6e", "#ef4444"],
        dataLabels: { enabled: false },
        stroke: { curve: "smooth", width: 2 },
        fill: { type: "gradient", gradient: { opacityFrom: 0.28, opacityTo: 0.03, stops: [0, 90, 100] } },
        grid: { borderColor: "#e2e8f0", strokeDashArray: 4, padding: { left: 8, right: 8 } },
        xaxis: {
            categories: data?.chart?.map((item) => formatDate(`${item.date}T00:00:00`)) || [],
            labels: { style: { colors: "#94a3b8", fontSize: "11px" }, rotate: -35 },
            axisBorder: { show: false }, axisTicks: { show: false },
        },
        yaxis: { labels: { style: { colors: "#94a3b8", fontSize: "11px" }, formatter: (value) => `${Math.round(value / 1000)}rb` } },
        tooltip: { y: { formatter: formatRp } },
        legend: { show: false },
    }), [data?.chart]);

    const chartSeries = useMemo(() => [
        { name: "Pemasukan", data: data?.chart?.map((item) => Number(item.income)) || [] },
        { name: "Pengeluaran", data: data?.chart?.map((item) => Number(item.expense)) || [] },
    ], [data?.chart]);

    const openAccount = (account = null, category = "cash") => {
        setEditing(account);
        setAccountForm(account ? {
            name: account.name,
            category: account.category,
            subtype: account.subtype,
            balance: account.balance,
            notes: account.notes || "",
        } : { ...emptyAccount, category, subtype: category === "cash" ? "bank" : "investment" });
        setModal("account");
    };

    const openObligation = (item = null, kind = "debt") => {
        setEditing(item);
        setObligationForm(item ? {
            kind: item.kind,
            title: item.title,
            counterparty: item.counterparty || "",
            amount: item.amount,
            due_date: item.due_date ? String(item.due_date).slice(0, 10) : "",
            status: item.status,
            notes: item.notes || "",
        } : { ...emptyObligation, kind });
        setModal("obligation");
    };

    const openTransaction = (transaction = null) => {
        setEditing(transaction);
        setTransactionForm(transaction ? {
            direction: transaction.direction,
            financial_account_id: transaction.financial_account_id || "",
            category: transaction.category || "",
            description: transaction.description,
            amount: transaction.amount,
            occurred_at: transaction.occurred_at ? localDateTime(new Date(transaction.occurred_at)) : localDateTime(),
            notes: transaction.notes || "",
        } : { ...emptyTransaction, occurred_at: localDateTime() });
        setModal("transaction");
    };

    const closeModal = () => {
        if (saving) return;
        resetModal();
    };

    const resetModal = () => {
        setModal(null);
        setEditing(null);
    };

    const saveAccount = async (event) => {
        event.preventDefault();
        setSaving(true);
        try {
            editing
                ? await axios.put(`/api/cashflow/accounts/${editing.id}`, accountForm)
                : await axios.post("/api/cashflow/accounts", accountForm);
            toast.success(editing ? "Akun berhasil diperbarui" : "Akun berhasil ditambahkan");
            resetModal();
            await loadData(true);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal menyimpan akun");
        } finally { setSaving(false); }
    };

    const saveObligation = async (event) => {
        event.preventDefault();
        setSaving(true);
        try {
            editing
                ? await axios.put(`/api/cashflow/obligations/${editing.id}`, obligationForm)
                : await axios.post("/api/cashflow/obligations", obligationForm);
            toast.success(editing ? "Data berhasil diperbarui" : "Data berhasil ditambahkan");
            resetModal();
            await loadData(true);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal menyimpan data");
        } finally { setSaving(false); }
    };

    const saveTransaction = async (event) => {
        event.preventDefault();
        setSaving(true);
        const payload = { ...transactionForm, financial_account_id: transactionForm.financial_account_id || null };
        try {
            editing
                ? await axios.put(`/api/cashflow/transactions/${editing.id}`, payload)
                : await axios.post("/api/cashflow/transactions", payload);
            toast.success(editing ? "Transaksi berhasil diperbarui" : "Transaksi berhasil dicatat");
            resetModal();
            await loadData(true);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal menyimpan transaksi");
        } finally { setSaving(false); }
    };

    const removeTarget = async () => {
        if (!deleteTarget) return;
        setSaving(true);
        try {
            await axios.delete(`/api/cashflow/${deleteTarget.type}/${deleteTarget.item.id}`);
            toast.success("Data berhasil dihapus");
            setDeleteTarget(null);
            await loadData(true);
        } catch (error) {
            toast.error(error.response?.data?.message || "Gagal menghapus data");
        } finally { setSaving(false); }
    };

    if (loading && !data) {
        return (
            <AppLayout>
                <div className="flex min-h-[70vh] items-center justify-center bg-slate-50 dark:bg-slate-950">
                    <div className="flex items-center gap-3 text-sm font-medium text-slate-500"><LoaderCircle className="h-5 w-5 animate-spin" /> Memuat keuangan...</div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <main className="min-h-full bg-slate-50 px-4 py-5 dark:bg-slate-950 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1600px] space-y-6">
                    <header className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Cashflow</h1>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Pantau posisi keuangan dan catat pergerakan dana usaha.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <button type="button" onClick={() => loadData(true)} disabled={refreshing} className="flex h-10 items-center gap-2 rounded-md border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-600 transition hover:border-slate-300 hover:text-slate-900 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                                <RefreshCw className={`h-4 w-4 ${refreshing ? "animate-spin" : ""}`} /> Perbarui
                            </button>
                            <button type="button" onClick={() => openAccount()} className="flex h-10 items-center gap-2 rounded-md border border-[#304674] bg-white px-3 text-sm font-semibold text-[#304674] transition hover:bg-[#304674]/5 dark:bg-slate-900 dark:text-blue-300">
                                <Plus className="h-4 w-4" /> Akun atau aset
                            </button>
                            <button type="button" onClick={() => openTransaction()} className="flex h-10 items-center gap-2 rounded-md bg-[#304674] px-4 text-sm font-semibold text-white transition hover:bg-[#26395f] active:translate-y-px">
                                <Plus className="h-4 w-4" /> Catat transaksi
                            </button>
                        </div>
                    </header>

                    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700 sm:px-5">
                            <div>
                                <h2 className="text-sm font-bold text-slate-900 dark:text-white">Ringkasan Keuangan</h2>
                                <p className="mt-0.5 text-xs text-slate-400">Posisi saldo dan kewajiban terkini</p>
                            </div>
                            <Scale className="h-4 w-4 text-slate-400" />
                        </div>
                        <div className="grid md:grid-cols-4">
                            <SummaryMetric label="Posisi kas" value={data?.summary?.cash_position} description={`${cashAccounts.length} akun aktif`} icon={WalletCards} />
                            <SummaryMetric label="Aset" value={data?.summary?.assets} description={`${assetAccounts.length} portofolio`} icon={TrendingUp} />
                            <SummaryMetric label="Total utang" value={data?.summary?.total_debt} description={`Piutang ${formatRp(data?.summary?.receivables)}`} icon={ReceiptText} />
                            <SummaryMetric label="Kekayaan bersih" value={data?.summary?.net_worth} description="Kas + aset + piutang - utang" icon={Scale} tone="primary" />
                        </div>
                    </section>

                    <section className="grid gap-4 xl:grid-cols-2">
                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <div className="flex items-center justify-between px-4 py-3">
                                <div>
                                    <h2 className="text-sm font-bold text-slate-900 dark:text-white">Posisi Kas</h2>
                                    <p className="text-xs text-slate-400">Bank, kas, e-wallet, dan marketplace</p>
                                </div>
                                <button type="button" onClick={() => openAccount(null, "cash")} className="flex h-8 items-center gap-1.5 rounded-md bg-slate-100 px-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"><Plus className="h-3.5 w-3.5" /> Tambah</button>
                            </div>
                            {cashAccounts.length ? cashAccounts.map((account) => <AccountRow key={account.id} account={account} onEdit={openAccount} onDelete={(item) => setDeleteTarget({ type: "accounts", item })} />) : <EmptyRows label="Belum ada akun kas yang dicatat." />}
                            <div className="flex justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-950/40"><span className="font-medium text-slate-500">Total posisi kas</span><strong className="text-slate-900 dark:text-white">{formatRp(data?.summary?.cash_position)}</strong></div>
                        </div>

                        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <div className="flex items-center justify-between px-4 py-3">
                                <div>
                                    <h2 className="text-sm font-bold text-slate-900 dark:text-white">Aset</h2>
                                    <p className="text-xs text-slate-400">Investasi, properti, dan aset usaha</p>
                                </div>
                                <button type="button" onClick={() => openAccount(null, "asset")} className="flex h-8 items-center gap-1.5 rounded-md bg-slate-100 px-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300"><Plus className="h-3.5 w-3.5" /> Tambah</button>
                            </div>
                            {assetAccounts.length ? assetAccounts.map((account) => <AccountRow key={account.id} account={account} onEdit={openAccount} onDelete={(item) => setDeleteTarget({ type: "accounts", item })} />) : <EmptyRows label="Belum ada aset atau investasi yang dicatat." />}
                            <div className="flex justify-between border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-950/40"><span className="font-medium text-slate-500">Total aset</span><strong className="text-slate-900 dark:text-white">{formatRp(data?.summary?.assets)}</strong></div>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-sm font-bold text-slate-900 dark:text-white">Utang dan Piutang</h2>
                                <p className="text-xs text-slate-400">Tagihan supplier terhubung otomatis dari Supplier Transactions</p>
                            </div>
                            <div className="flex gap-2">
                                <button type="button" onClick={() => openObligation(null, "receivable")} className="h-8 rounded-md border border-slate-200 px-2.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300">Tambah piutang</button>
                                <button type="button" onClick={() => openObligation(null, "debt")} className="h-8 rounded-md bg-slate-800 px-2.5 text-xs font-semibold text-white transition hover:bg-slate-700 dark:bg-slate-700">Tambah utang</button>
                            </div>
                        </div>
                        <div className="grid lg:grid-cols-2 lg:divide-x lg:divide-slate-200 dark:lg:divide-slate-700">
                            <div>
                                <div className="flex items-center justify-between bg-rose-50/60 px-4 py-3 dark:bg-rose-500/5">
                                    <span className="text-xs font-bold text-rose-700 dark:text-rose-300">UTANG</span>
                                    <strong className="text-sm text-rose-700 dark:text-rose-300">{formatRp(data?.summary?.total_debt)}</strong>
                                </div>
                                {data?.supplier_payables?.map((item) => (
                                    <div key={`supplier-${item.supplier_id}`} className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                                        <div className="min-w-0"><p className="truncate font-semibold text-slate-700 dark:text-slate-200">{item.supplier_name}</p><p className="text-xs text-slate-400">Tagihan supplier otomatis</p></div>
                                        <strong className="shrink-0 text-slate-800 dark:text-white">{formatRp(item.amount)}</strong>
                                    </div>
                                ))}
                                {manualDebt.map((item) => (
                                    <div key={`debt-${item.id}`} className="flex items-center gap-3 border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                                        <div className="min-w-0 flex-1"><p className="truncate font-semibold text-slate-700 dark:text-slate-200">{item.title}</p><p className="text-xs text-slate-400">{item.counterparty || "Utang manual"}{item.due_date ? ` · Jatuh tempo ${formatDate(item.due_date)}` : ""}</p></div>
                                        <strong className="shrink-0 text-slate-800 dark:text-white">{formatRp(item.amount)}</strong>
                                        <button type="button" onClick={() => openObligation(item)} className="text-slate-400 hover:text-[#304674]" title="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                                        <button type="button" onClick={() => setDeleteTarget({ type: "obligations", item })} className="text-slate-400 hover:text-rose-600" title="Hapus"><Trash2 className="h-3.5 w-3.5" /></button>
                                    </div>
                                ))}
                                {!data?.supplier_payables?.length && !manualDebt.length && <EmptyRows label="Tidak ada utang yang belum diselesaikan." />}
                            </div>
                            <div>
                                <div className="flex items-center justify-between bg-emerald-50/60 px-4 py-3 dark:bg-emerald-500/5">
                                    <span className="text-xs font-bold text-emerald-700 dark:text-emerald-300">PIUTANG</span>
                                    <strong className="text-sm text-emerald-700 dark:text-emerald-300">{formatRp(data?.summary?.receivables)}</strong>
                                </div>
                                {receivables.map((item) => (
                                    <div key={`receivable-${item.id}`} className="flex items-center gap-3 border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
                                        <div className="min-w-0 flex-1"><p className="truncate font-semibold text-slate-700 dark:text-slate-200">{item.title}</p><p className="text-xs text-slate-400">{item.counterparty || "Piutang manual"}{item.due_date ? ` · Jatuh tempo ${formatDate(item.due_date)}` : ""}</p></div>
                                        <strong className="shrink-0 text-slate-800 dark:text-white">{formatRp(item.amount)}</strong>
                                        <button type="button" onClick={() => openObligation(item)} className="text-slate-400 hover:text-[#304674]" title="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                                        <button type="button" onClick={() => setDeleteTarget({ type: "obligations", item })} className="text-slate-400 hover:text-rose-600" title="Hapus"><Trash2 className="h-3.5 w-3.5" /></button>
                                    </div>
                                ))}
                                {!receivables.length && <EmptyRows label="Belum ada piutang yang dicatat." />}
                            </div>
                        </div>
                    </section>

                    <section className="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        <div className="flex flex-col gap-3 border-b border-slate-200 px-4 py-4 dark:border-slate-700 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 className="text-sm font-bold text-slate-900 dark:text-white">Arus Kas</h2>
                                <p className="text-xs text-slate-400">Profit harian ditambahkan otomatis sebagai pemasukan read-only</p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <SelectField className="w-36" value={period} onChange={(event) => applyPeriod(event.target.value)}>
                                    <option value="7_days">7 hari</option><option value="30_days">30 hari</option><option value="90_days">90 hari</option><option value="this_month">Bulan ini</option><option value="custom">Pilih tanggal</option>
                                </SelectField>
                                {period === "custom" && <><input type="date" value={startDate} onChange={(event) => setStartDate(event.target.value)} className={`${inputClass} w-36`} /><span className="text-xs text-slate-400">sampai</span><input type="date" value={endDate} onChange={(event) => setEndDate(event.target.value)} className={`${inputClass} w-36`} /></>}
                            </div>
                        </div>
                        <div className="grid border-b border-slate-200 sm:grid-cols-3 sm:divide-x sm:divide-slate-200 dark:border-slate-700 dark:sm:divide-slate-700">
                            <div className="p-4"><p className="text-xs font-medium text-slate-400">Pemasukan</p><p className="mt-1 text-lg font-bold text-emerald-600">+ {formatRp(data?.cashflow_totals?.income)}</p></div>
                            <div className="p-4"><p className="text-xs font-medium text-slate-400">Pengeluaran</p><p className="mt-1 text-lg font-bold text-rose-600">- {formatRp(data?.cashflow_totals?.expense)}</p></div>
                            <div className="p-4"><p className="text-xs font-medium text-slate-400">Arus kas bersih</p><p className={`mt-1 text-lg font-bold ${Number(data?.cashflow_totals?.net) >= 0 ? "text-[#304674] dark:text-blue-300" : "text-rose-600"}`}>{formatRp(data?.cashflow_totals?.net)}</p></div>
                        </div>
                        {data?.chart?.length > 0 && <div className="border-b border-slate-200 px-2 py-3 dark:border-slate-700 sm:px-4"><ReactApexChart type="area" height={245} options={chartOptions} series={chartSeries} /></div>}
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left">
                                <thead className="bg-slate-50 text-[11px] font-bold uppercase text-slate-400 dark:bg-slate-950/40"><tr><th className="px-4 py-3">Tanggal</th><th className="px-4 py-3">Transaksi</th><th className="px-4 py-3">Akun</th><th className="px-4 py-3 text-right">Pemasukan</th><th className="px-4 py-3 text-right">Pengeluaran</th><th className="w-20 px-4 py-3"></th></tr></thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {data?.transactions?.map((transaction) => (
                                        <tr key={`${transaction.source}-${transaction.id}`} className="text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30">
                                            <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">{formatDate(transaction.occurred_at, true)}</td>
                                            <td className="px-4 py-3"><div className="flex items-center gap-2.5"><div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-md ${transaction.direction === "income" ? "bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10" : "bg-rose-50 text-rose-600 dark:bg-rose-500/10"}`}>{transaction.direction === "income" ? <ArrowDownLeft className="h-4 w-4" /> : <ArrowUpRight className="h-4 w-4" />}</div><div><p className="font-semibold text-slate-800 dark:text-slate-100">{transaction.description}</p><p className="text-xs text-slate-400">{transaction.category || "Tanpa kategori"}{transaction.is_read_only ? " · Otomatis" : ""}</p></div></div></td>
                                            <td className="px-4 py-3 text-xs font-medium text-slate-500">{transaction.account?.name || (transaction.is_read_only ? "Profit Tracker" : "Tidak dipilih")}</td>
                                            <td className="px-4 py-3 text-right font-semibold text-emerald-600">{transaction.direction === "income" ? formatRp(transaction.amount) : "-"}</td>
                                            <td className="px-4 py-3 text-right font-semibold text-rose-600">{transaction.direction === "expense" ? formatRp(transaction.amount) : "-"}</td>
                                            <td className="px-4 py-3"><div className="flex justify-end gap-1">{!transaction.is_read_only && <><button type="button" onClick={() => openTransaction(transaction)} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-[#304674] dark:hover:bg-slate-800" title="Edit"><Pencil className="h-3.5 w-3.5" /></button><button type="button" onClick={() => setDeleteTarget({ type: "transactions", item: transaction })} className="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Hapus"><Trash2 className="h-3.5 w-3.5" /></button></>}</div></td>
                                        </tr>
                                    ))}
                                    {!data?.transactions?.length && <tr><td colSpan="6" className="px-4 py-10 text-center text-sm text-slate-400">Belum ada transaksi pada periode ini.</td></tr>}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </main>

            {modal === "account" && <Modal title={editing ? "Edit akun atau aset" : "Tambah akun atau aset"} subtitle="Saldo dapat diperbarui kembali kapan saja." icon={Landmark} onClose={closeModal} footer={<><button type="button" onClick={closeModal} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Batal</button><button type="submit" form="account-form" disabled={saving} className="flex h-10 min-w-28 items-center justify-center rounded-md bg-[#304674] px-4 text-sm font-semibold text-white disabled:opacity-60">{saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : "Simpan"}</button></>}>
                <form id="account-form" onSubmit={saveAccount} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Kelompok"><SelectField value={accountForm.category} onChange={(event) => { const category = event.target.value; setAccountForm((current) => ({ ...current, category, subtype: category === "cash" ? "bank" : "investment" })); }}><option value="cash">Posisi kas</option><option value="asset">Aset</option></SelectField></Field>
                    <Field label="Jenis"><SelectField value={accountForm.subtype} onChange={(event) => setAccountForm((current) => ({ ...current, subtype: event.target.value }))}>{accountTypes[accountForm.category].map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</SelectField></Field>
                    <Field label="Nama akun atau portofolio"><input required value={accountForm.name} onChange={(event) => setAccountForm((current) => ({ ...current, name: event.target.value }))} className={inputClass} placeholder="Contoh: BCA Operasional" /></Field>
                    <Field label="Nilai saat ini"><input required min="0" step="1" type="number" value={accountForm.balance} onChange={(event) => setAccountForm((current) => ({ ...current, balance: event.target.value }))} className={inputClass} placeholder="0" /></Field>
                    <div className="sm:col-span-2"><Field label="Catatan"><textarea value={accountForm.notes} onChange={(event) => setAccountForm((current) => ({ ...current, notes: event.target.value }))} className={textAreaClass} placeholder="Opsional" /></Field></div>
                </form>
            </Modal>}

            {modal === "obligation" && <Modal title={editing ? "Edit utang atau piutang" : "Tambah utang atau piutang"} subtitle="Tagihan supplier otomatis tidak perlu dicatat ulang." icon={ReceiptText} onClose={closeModal} footer={<><button type="button" onClick={closeModal} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Batal</button><button type="submit" form="obligation-form" disabled={saving} className="flex h-10 min-w-28 items-center justify-center rounded-md bg-[#304674] px-4 text-sm font-semibold text-white disabled:opacity-60">{saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : "Simpan"}</button></>}>
                <form id="obligation-form" onSubmit={saveObligation} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Jenis"><SelectField value={obligationForm.kind} onChange={(event) => setObligationForm((current) => ({ ...current, kind: event.target.value }))}><option value="debt">Utang</option><option value="receivable">Piutang</option></SelectField></Field>
                    <Field label="Status"><SelectField value={obligationForm.status} onChange={(event) => setObligationForm((current) => ({ ...current, status: event.target.value }))}><option value="open">Belum selesai</option><option value="settled">Selesai</option></SelectField></Field>
                    <Field label="Nama"><input required value={obligationForm.title} onChange={(event) => setObligationForm((current) => ({ ...current, title: event.target.value }))} className={inputClass} placeholder="Contoh: Pinjaman modal" /></Field>
                    <Field label="Pihak terkait"><input value={obligationForm.counterparty} onChange={(event) => setObligationForm((current) => ({ ...current, counterparty: event.target.value }))} className={inputClass} placeholder="Opsional" /></Field>
                    <Field label="Nilai"><input required min="1" step="1" type="number" value={obligationForm.amount} onChange={(event) => setObligationForm((current) => ({ ...current, amount: event.target.value }))} className={inputClass} placeholder="0" /></Field>
                    <Field label="Jatuh tempo"><input type="date" value={obligationForm.due_date} onChange={(event) => setObligationForm((current) => ({ ...current, due_date: event.target.value }))} className={inputClass} /></Field>
                    <div className="sm:col-span-2"><Field label="Catatan"><textarea value={obligationForm.notes} onChange={(event) => setObligationForm((current) => ({ ...current, notes: event.target.value }))} className={textAreaClass} placeholder="Opsional" /></Field></div>
                </form>
            </Modal>}

            {modal === "transaction" && <Modal title={editing ? "Edit transaksi" : "Catat transaksi"} subtitle="Saldo akun yang dipilih akan disesuaikan otomatis." icon={CreditCard} onClose={closeModal} footer={<><button type="button" onClick={closeModal} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Batal</button><button type="submit" form="transaction-form" disabled={saving} className="flex h-10 min-w-28 items-center justify-center rounded-md bg-[#304674] px-4 text-sm font-semibold text-white disabled:opacity-60">{saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : "Simpan"}</button></>}>
                <form id="transaction-form" onSubmit={saveTransaction} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Jenis"><SelectField value={transactionForm.direction} onChange={(event) => setTransactionForm((current) => ({ ...current, direction: event.target.value }))}><option value="income">Pemasukan</option><option value="expense">Pengeluaran</option></SelectField></Field>
                    <Field label="Akun tujuan atau sumber"><SelectField value={transactionForm.financial_account_id} onChange={(event) => setTransactionForm((current) => ({ ...current, financial_account_id: event.target.value }))}><option value="">Tanpa akun</option>{data?.accounts?.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</SelectField></Field>
                    <Field label="Deskripsi"><input required value={transactionForm.description} onChange={(event) => setTransactionForm((current) => ({ ...current, description: event.target.value }))} className={inputClass} placeholder="Contoh: Pembelian kemasan" /></Field>
                    <Field label="Kategori"><input value={transactionForm.category} onChange={(event) => setTransactionForm((current) => ({ ...current, category: event.target.value }))} className={inputClass} placeholder="Operasional" /></Field>
                    <Field label="Nilai"><input required min="1" step="1" type="number" value={transactionForm.amount} onChange={(event) => setTransactionForm((current) => ({ ...current, amount: event.target.value }))} className={inputClass} placeholder="0" /></Field>
                    <Field label="Tanggal dan waktu"><input required type="datetime-local" value={transactionForm.occurred_at} onChange={(event) => setTransactionForm((current) => ({ ...current, occurred_at: event.target.value }))} className={inputClass} /></Field>
                    <div className="sm:col-span-2"><Field label="Catatan"><textarea value={transactionForm.notes} onChange={(event) => setTransactionForm((current) => ({ ...current, notes: event.target.value }))} className={textAreaClass} placeholder="Opsional" /></Field></div>
                </form>
            </Modal>}

            {deleteTarget && <Modal title="Hapus data?" subtitle="Tindakan ini tidak dapat dibatalkan." icon={Trash2} onClose={() => !saving && setDeleteTarget(null)} footer={<><button type="button" onClick={() => setDeleteTarget(null)} className="h-10 rounded-md border border-slate-200 px-4 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:text-slate-300">Batal</button><button type="button" onClick={removeTarget} disabled={saving} className="flex h-10 min-w-28 items-center justify-center rounded-md bg-rose-600 px-4 text-sm font-semibold text-white disabled:opacity-60">{saving ? <LoaderCircle className="h-4 w-4 animate-spin" /> : "Hapus"}</button></>}>
                <p className="text-sm leading-relaxed text-slate-600 dark:text-slate-300">Data <strong className="text-slate-900 dark:text-white">{deleteTarget.item.name || deleteTarget.item.title || deleteTarget.item.description}</strong> akan dihapus. Saldo akun akan dikembalikan bila yang dihapus adalah transaksi.</p>
            </Modal>}
        </AppLayout>
    );
}
