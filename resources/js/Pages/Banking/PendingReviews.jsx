import React from 'react';
import { Head, useForm, usePage, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    MessageSquare,
    Upload,
    Mail,
    Loader2,
    Building2,
    CreditCard,
    ArrowRight,
    CheckCircle2,
    Clock,
    AlertCircle,
} from 'lucide-react';
import PendingTransactionCard from '@/Components/Banking/PendingTransactionCard';

// ─── Empty State: No Company ─────────────────────────────────────────────────
function NoCompanyState() {
    return (
        <div className="flex flex-col items-center justify-center py-20 px-6 text-center">
            <div className="w-16 h-16 bg-amber-50 border-2 border-amber-200 rounded-2xl flex items-center justify-center mb-5">
                <Building2 className="text-amber-500" size={28} />
            </div>
            <h2 className="text-xl font-bold text-gray-800 mb-2">No Business Profile Found</h2>
            <p className="text-gray-500 text-sm max-w-xs mb-6 leading-relaxed">
                You need to set up your business profile before you can manage banking transactions.
            </p>
            <Link
                href={route('accounting.chat')}
                className="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition-colors shadow-sm"
            >
                Set Up Business
                <ArrowRight size={15} />
            </Link>
        </div>
    );
}

// ─── Empty State: No Bank Account ─────────────────────────────────────────────
function NoBankAccountState() {
    return (
        <div className="flex flex-col items-center justify-center py-20 px-6 text-center">
            <div className="w-16 h-16 bg-blue-50 border-2 border-blue-200 rounded-2xl flex items-center justify-center mb-5">
                <CreditCard className="text-blue-500" size={28} />
            </div>
            <h2 className="text-xl font-bold text-gray-800 mb-2">No Bank Account Added</h2>
            <p className="text-gray-500 text-sm max-w-xs mb-6 leading-relaxed">
                Add a bank account to your company profile to start tracking and reviewing transactions.
            </p>
            <Link
                href={route('accounting.chat')}
                className="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl transition-colors shadow-sm"
            >
                Add Bank Account
                <ArrowRight size={15} />
            </Link>
        </div>
    );
}

// ─── Empty Transactions State ─────────────────────────────────────────────────
function NoTransactionsState() {
    return (
        <div className="flex flex-col items-center justify-center py-14 px-6 text-center">
            <div className="w-14 h-14 bg-emerald-50 border-2 border-emerald-200 rounded-2xl flex items-center justify-center mb-4">
                <CheckCircle2 className="text-emerald-500" size={24} />
            </div>
            <h3 className="text-base font-semibold text-gray-700 mb-1">All caught up!</h3>
            <p className="text-gray-400 text-sm">No transactions to review right now.</p>
        </div>
    );
}

// ─── Main Component ───────────────────────────────────────────────────────────
export default function PendingReviews({ auth, transactions, heads, bankAccounts = [], hasCompany = true }) {
    const { flash } = usePage().props;

    const hasNoBankAccounts = bankAccounts.length === 0;

    const allTransactions = transactions?.data || [];
    const pendingTransactions = allTransactions.filter(t => t.review_status === 'pending');
    const completedTransactions = allTransactions.filter(t => t.review_status !== 'pending');

    // --- SMS Ingest Form ---
    const smsForm = useForm({
        bank_account_id: bankAccounts.length > 0 ? bankAccounts[0].id : '',
        raw_sms: '',
    });

    // --- Email Ingest Form ---
    const emailForm = useForm({
        bank_account_id: bankAccounts.length > 0 ? bankAccounts[0].id : '',
        email_subject: '',
        email_body: '',
    });

    // --- Statement Upload Form ---
    const statementForm = useForm({
        bank_account_id: bankAccounts.length > 0 ? bankAccounts[0].id : '',
        statement: null,
    });

    const handleSmsSubmit = (e) => {
        e.preventDefault();
        smsForm.post(route('banking.transactions.sms.ingest'), {
            onSuccess: () => smsForm.reset('raw_sms'),
        });
    };

    const handleEmailSubmit = (e) => {
        e.preventDefault();
        emailForm.post(route('banking.transactions.email.ingest'), {
            onSuccess: () => emailForm.reset('email_subject', 'email_body'),
        });
    };

    const handleStatementSubmit = (e) => {
        e.preventDefault();
        statementForm.post(route('banking.transactions.statement.upload'), {
            forceFormData: true,
            onSuccess: () => statementForm.reset('statement'),
        });
    };

    const showNoCompany    = !hasCompany;
    const showNoBankAccount = hasCompany && hasNoBankAccounts;
    const showContent      = hasCompany && !hasNoBankAccounts;

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Pending Reviews" />

            <div className="max-w-2xl mx-auto py-8 px-4 sm:px-6 lg:px-8 space-y-6">

                {/* ── Page Header ── */}
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 tracking-tight">Narration</h1>
                    <p className="text-sm text-gray-400 mt-0.5">Review and categorise your transactions</p>
                </div>

                {/* ── Flash Messages ── */}
                {flash?.success && (
                    <div className="flex items-start gap-3 bg-emerald-50 text-emerald-700 p-4 rounded-xl border border-emerald-200 text-sm font-medium">
                        <CheckCircle2 size={16} className="mt-0.5 flex-shrink-0" />
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="flex items-start gap-3 bg-red-50 text-red-700 p-4 rounded-xl border border-red-200 text-sm font-medium">
                        <AlertCircle size={16} className="mt-0.5 flex-shrink-0" />
                        {flash.error}
                    </div>
                )}

                {showNoCompany && (
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                        <NoCompanyState />
                    </div>
                )}

                {showNoBankAccount && (
                    <div className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                        <NoBankAccountState />
                    </div>
                )}

                {showContent && (
                    <>
                        {/* ── Data Entry Section ── */}
                        <section className="grid grid-cols-1 sm:grid-cols-3 gap-4">

                            {/* Paste SMS Card */}
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                                <div className="flex items-center gap-2 mb-4 text-indigo-600 font-semibold">
                                    <MessageSquare size={17} />
                                    <h2 className="text-sm">Paste Bank SMS</h2>
                                </div>
                                <form onSubmit={handleSmsSubmit} className="space-y-3">
                                    <textarea
                                        placeholder="Paste raw SMS text here..."
                                        value={smsForm.data.raw_sms}
                                        onChange={(e) => smsForm.setData('raw_sms', e.target.value)}
                                        className="w-full bg-gray-50 border border-gray-200 text-gray-800 rounded-lg px-3 py-2 text-sm min-h-[90px] outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none"
                                    />
                                    {smsForm.errors.raw_sms && (
                                        <p className="text-red-500 text-xs">{smsForm.errors.raw_sms}</p>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={smsForm.processing || !smsForm.data.raw_sms.trim()}
                                        className="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-medium py-2 rounded-lg text-sm transition-colors flex justify-center items-center gap-2"
                                    >
                                        {smsForm.processing && <Loader2 size={15} className="animate-spin" />}
                                        Process SMS
                                    </button>
                                </form>
                            </div>

                            {/* ── Paste Email Card ── */}
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                                <div className="flex items-center gap-2 mb-4 text-violet-600 font-semibold">
                                    <Mail size={17} />
                                    <h2 className="text-sm">Paste Bank Email</h2>
                                </div>
                                <form onSubmit={handleEmailSubmit} className="space-y-3">
                                    <input
                                        type="text"
                                        placeholder="Subject (optional)"
                                        value={emailForm.data.email_subject}
                                        onChange={(e) => emailForm.setData('email_subject', e.target.value)}
                                        className="w-full bg-gray-50 border border-gray-200 text-gray-800 rounded-lg px-3 py-2 text-sm outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500"
                                    />
                                    <textarea
                                        placeholder="Paste email body here..."
                                        value={emailForm.data.email_body}
                                        onChange={(e) => emailForm.setData('email_body', e.target.value)}
                                        className="w-full bg-gray-50 border border-gray-200 text-gray-800 rounded-lg px-3 py-2 text-sm min-h-[90px] outline-none focus:border-violet-500 focus:ring-1 focus:ring-violet-500 resize-none"
                                    />
                                    {emailForm.errors.email_body && (
                                        <p className="text-red-500 text-xs">{emailForm.errors.email_body}</p>
                                    )}
                                    <button
                                        type="submit"
                                        disabled={emailForm.processing || !emailForm.data.email_body.trim()}
                                        className="w-full bg-violet-600 hover:bg-violet-700 disabled:opacity-50 text-white font-medium py-2 rounded-lg text-sm transition-colors flex justify-center items-center gap-2"
                                    >
                                        {emailForm.processing && <Loader2 size={15} className="animate-spin" />}
                                        Process Email
                                    </button>
                                </form>
                            </div>

                            {/* Upload Statement Card */}
                            <div className="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                                <div className="flex items-center gap-2 mb-4 text-indigo-600 font-semibold">
                                    <Upload size={17} />
                                    <h2 className="text-sm">Upload Statement</h2>
                                </div>
                                <form onSubmit={handleStatementSubmit} className="space-y-3">
                                    <div className="relative">
                                        <input
                                            type="file"
                                            accept=".pdf,.csv,.xls,.xlsx,.png,.jpg,.jpeg"
                                            onChange={(e) => statementForm.setData('statement', e.target.files[0])}
                                            className="w-full bg-gray-50 border border-gray-200 text-gray-800 rounded-lg px-3 py-1.5 text-sm
                                                file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0
                                                file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700
                                                hover:file:bg-indigo-100 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                        />
                                    </div>
                                    {statementForm.errors.statement && (
                                        <p className="text-red-500 text-xs">{statementForm.errors.statement}</p>
                                    )}
                                    <p className="text-xs text-gray-400">
                                        Supported: PDF, CSV, XLS, XLSX, PNG, JPG
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={statementForm.processing || !statementForm.data.statement}
                                        className="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-medium py-2 rounded-lg text-sm transition-colors flex justify-center items-center gap-2"
                                    >
                                        {statementForm.processing && <Loader2 size={15} className="animate-spin" />}
                                        Upload File
                                    </button>
                                </form>
                            </div>
                        </section>

                        {/* ── Pending Review Section ── */}
                        <section>
                            <div className="flex items-center gap-3 mb-5 mt-4">
                                <div className="w-1 h-5 bg-indigo-500 rounded-full" />
                                <h2 className="text-sm font-bold text-gray-700 uppercase tracking-widest flex items-center gap-2">
                                    Pending Review
                                    {pendingTransactions.length > 0 && (
                                        <span className="bg-indigo-100 text-indigo-700 text-xs py-0.5 px-2 rounded-full font-bold">
                                            {pendingTransactions.length}
                                        </span>
                                    )}
                                </h2>
                            </div>

                            {pendingTransactions.length === 0 ? (
                                <NoTransactionsState />
                            ) : (
                                <div className="space-y-4">
                                    {pendingTransactions.map((transaction) => (
                                        <PendingTransactionCard
                                            key={transaction.id}
                                            transaction={transaction}
                                            heads={heads}
                                        />
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* ── Recently Reviewed Section ── */}
                        {completedTransactions.length > 0 && (
                            <section>
                                <div className="flex items-center gap-3 mb-5 mt-8">
                                    <div className="w-1 h-5 bg-emerald-400 rounded-full" />
                                    <h2 className="text-sm font-bold text-gray-700 uppercase tracking-widest">
                                        Recently Reviewed
                                    </h2>
                                </div>
                                <div className="space-y-4">
                                    {completedTransactions.map((transaction) => (
                                        <PendingTransactionCard
                                            key={transaction.id}
                                            transaction={transaction}
                                            heads={heads}
                                        />
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
