import { useState, useMemo } from 'react';
import { useForm, router } from '@inertiajs/react';
import {
    Check, Wand2, TrendingUp, TrendingDown, AlertTriangle, Edit3,
    Link2, Link2Off, FileText, ChevronDown, ChevronUp, Sparkles, X,
} from 'lucide-react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';

const cx = (...classes) => classes.filter(Boolean).join(' ');

const formatCurrency = (amount) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(amount);

const getInitData = (heads, transaction) => {
    if (transaction.narration_sub_head_id) {
        for (const h of heads) {
            const s = (h.active_sub_heads || h.sub_heads || []).find(
                (sub) => sub.id === transaction.narration_sub_head_id
            );
            if (s) return { head: h, subHead: s };
        }
    }
    if (transaction.narration_head_id) {
        const h = heads.find((h) => h.id === transaction.narration_head_id);
        if (h) return { head: h, subHead: null };
    }
    return { head: null, subHead: null };
};

// ── Match confidence badge ────────────────────────────────────────────────────
function MatchBadge({ score }) {
    if (score >= 70) return <span className="text-[10px] font-bold text-emerald-700 bg-emerald-100 px-1.5 py-0.5 rounded-full">Strong</span>;
    if (score >= 40) return <span className="text-[10px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded-full">Possible</span>;
    return <span className="text-[10px] font-bold text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded-full">Weak</span>;
}

// ── Inline reconciliation picker (lives inside the form) ─────────────────────
function InlineReconciliationPicker({ transaction, selectedInvoiceId, onSelect }) {
    const suggestions       = transaction.invoice_suggestions || [];
    const isReconciled      = transaction.is_reconciled;
    const reconciledInvoice = transaction.reconciled_invoice;

    const [showAll, setShowAll]     = useState(false);
    const [manualRef, setManualRef] = useState('');

    // Already reconciled and user hasn't changed anything → show current link
    if (isReconciled && reconciledInvoice && selectedInvoiceId === transaction.reconciled_invoice_id) {
        return (
            <div className="rounded-xl border border-teal-100 bg-teal-50 px-3 py-2.5 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 min-w-0">
                    <Link2 size={14} className="text-teal-600 flex-shrink-0" />
                    <div className="min-w-0">
                        <p className="text-xs font-bold text-teal-800 truncate">
                            Reconciled · {reconciledInvoice.invoice_number}
                        </p>
                        <p className="text-xs text-teal-600 truncate">
                            {reconciledInvoice.client_name} · {formatCurrency(reconciledInvoice.total_amount)}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => onSelect(null)}
                    className="text-xs text-teal-600 hover:text-red-600 flex items-center gap-1 font-medium transition-colors flex-shrink-0"
                >
                    <Link2Off size={12} /> Unlink
                </button>
            </div>
        );
    }

    // User has picked a new invoice from the list
    if (selectedInvoiceId !== null && selectedInvoiceId !== transaction.reconciled_invoice_id) {
        const picked = suggestions.find(s => s.id === selectedInvoiceId);
        const label  = picked ? picked.invoice_number : (typeof selectedInvoiceId === 'string' ? selectedInvoiceId : `Invoice #${selectedInvoiceId}`);

        return (
            <div className="rounded-xl border-2 border-indigo-500 bg-indigo-50 px-3 py-2.5 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2 min-w-0">
                    <Link2 size={14} className="text-indigo-600 flex-shrink-0" />
                    <div className="min-w-0">
                        <p className="text-xs font-bold text-indigo-800 truncate">{label}</p>
                        {picked && (
                            <p className="text-xs text-indigo-600 truncate">
                                {picked.client_name} · {formatCurrency(picked.amount_due)} due
                            </p>
                        )}
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => onSelect(null)}
                    className="text-indigo-400 hover:text-indigo-700 transition-colors flex-shrink-0"
                    title="Clear selection"
                >
                    <X size={15} />
                </button>
            </div>
        );
    }

    // No suggestions available at all
    if (suggestions.length === 0) return null;

    const visible = showAll ? suggestions : suggestions.slice(0, 1);

    return (
        <div className="rounded-xl border border-indigo-100 bg-indigo-50/40 overflow-hidden">
            {/* Header row */}
            <div className="flex items-center justify-between px-3 py-2 border-b border-indigo-100">
                <div className="flex items-center gap-1.5 text-xs font-semibold text-indigo-700">
                    <Sparkles size={13} />
                    {suggestions.length === 1 ? 'Matched Invoice' : `${suggestions.length} Possible Invoices`}
                </div>
                {suggestions.length > 1 && (
                    <button
                        type="button"
                        onClick={() => setShowAll(v => !v)}
                        className="flex items-center gap-1 text-xs font-medium text-indigo-500 hover:text-indigo-700 transition-colors"
                    >
                        {showAll
                            ? <><ChevronUp size={13} /> Less</>
                            : <><ChevronDown size={13} /> +{suggestions.length - 1} more</>}
                    </button>
                )}
            </div>

            {/* Suggestion rows */}
            {visible.map((s) => (
                <div
                    key={s.id}
                    className="flex items-center justify-between gap-3 px-3 py-2.5 border-b border-indigo-100/60 last:border-b-0"
                >
                    <div className="min-w-0">
                        <div className="flex items-center gap-1.5 flex-wrap">
                            <span className="text-xs font-bold text-gray-800">{s.invoice_number}</span>
                            <MatchBadge score={s.match_score} />
                        </div>
                        <p className="text-xs text-gray-500 mt-0.5 truncate">
                            {s.client_name} · {formatCurrency(s.amount_due)} due · {s.invoice_date}
                        </p>
                        {s.match_reasons.length > 0 && (
                            <p className="text-[10px] text-indigo-500 mt-0.5 truncate">
                                {s.match_reasons[0]}
                                {s.match_reasons.length > 1 ? ` +${s.match_reasons.length - 1} more` : ''}
                            </p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={() => onSelect(s.id)}
                        className="flex-shrink-0 text-xs bg-white border-2 border-indigo-200 hover:border-indigo-500 hover:bg-indigo-600 hover:text-white text-indigo-700 font-semibold px-2.5 py-1 rounded-lg transition-all flex items-center gap-1"
                    >
                        <Link2 size={12} /> Select
                    </button>
                </div>
            ))}

            {/* Manual invoice number entry */}
            <div className="px-3 py-2.5 border-t border-indigo-100 flex items-center gap-2">
                <FileText size={13} className="text-indigo-400 flex-shrink-0" />
                <input
                    type="text"
                    placeholder="Or type invoice # manually…"
                    value={manualRef}
                    onChange={(e) => setManualRef(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key !== 'Enter') return;
                        e.preventDefault();
                        if (!manualRef.trim()) return;
                        const found = suggestions.find(
                            s => s.invoice_number.toLowerCase() === manualRef.toLowerCase().trim()
                        );
                        onSelect(found ? found.id : manualRef.trim());
                        setManualRef('');
                    }}
                    className="flex-1 bg-white border border-indigo-200 rounded-lg px-2.5 py-1 text-xs outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                />
                <button
                    type="button"
                    disabled={!manualRef.trim()}
                    onClick={() => {
                        if (!manualRef.trim()) return;
                        const found = suggestions.find(
                            s => s.invoice_number.toLowerCase() === manualRef.toLowerCase().trim()
                        );
                        onSelect(found ? found.id : manualRef.trim());
                        setManualRef('');
                    }}
                    className="text-xs bg-indigo-600 hover:bg-indigo-700 disabled:opacity-40 text-white font-semibold px-2.5 py-1 rounded-lg transition-colors flex-shrink-0"
                >
                    Select
                </button>
            </div>
        </div>
    );
}

// ── Collapsed read-only reconciliation strip ──────────────────────────────────
function ReconciliationStrip({ transaction }) {
    const { is_reconciled, reconciled_invoice, invoice_suggestions = [] } = transaction;

    if (is_reconciled && reconciled_invoice) {
        return (
            <div className="mt-3 rounded-xl border border-teal-100 bg-teal-50 px-3 py-2 flex items-center gap-2">
                <Link2 size={13} className="text-teal-600 flex-shrink-0" />
                <p className="text-xs font-semibold text-teal-800 truncate">
                    Reconciled · {reconciled_invoice.invoice_number}
                    <span className="font-normal text-teal-600"> · {reconciled_invoice.client_name}</span>
                </p>
            </div>
        );
    }

    if (invoice_suggestions.length > 0) {
        return (
            <div className="mt-3 rounded-xl border border-indigo-100 bg-indigo-50/50 px-3 py-2 flex items-center gap-2">
                <Sparkles size={13} className="text-indigo-500 flex-shrink-0" />
                <p className="text-xs text-indigo-700 font-medium">
                    {invoice_suggestions.length} possible invoice match{invoice_suggestions.length > 1 ? 'es' : ''} — open edit to link
                </p>
            </div>
        );
    }

    return null;
}

// ── Main Card ─────────────────────────────────────────────────────────────────
export default function PendingTransactionCard({ transaction, heads = [] }) {
    const isCredit      = transaction.type === 'credit';
    const isReviewed    = transaction.review_status !== 'pending';
    const relevantHeads = heads.filter(h => h.type === transaction.type || h.type === 'both');
    const hasInvoiceSection = (transaction.invoice_suggestions?.length > 0) || transaction.is_reconciled;

    const init = useMemo(() => getInitData(relevantHeads, transaction), [relevantHeads, transaction]);

    const [isExpanded, setIsExpanded]           = useState(false);
    const [selectedHead, setSelectedHead]       = useState(init?.head ?? null);
    const [selectedSub, setSelectedSub]         = useState(init?.subHead ?? null);
    const [saveRule, setSaveRule]               = useState(false);
    const [selectedInvoiceId, setSelectedInvoiceId] = useState(
        transaction.is_reconciled ? transaction.reconciled_invoice_id : null
    );

    const { data, setData, post, processing, errors, reset } = useForm({
        narration_head_id:     init?.head?.id ?? '',
        narration_sub_head_id: init?.subHead?.id ?? '',
        party_name:            transaction.party_name ?? '',
        narration_note:        transaction.narration_note ?? '',
        save_as_rule:          false,
        invoice_id:            transaction.is_reconciled ? transaction.reconciled_invoice_id : null,
        invoice_number:        null,
        unreconcile:           false,
    });

    const handlePickHead = (head) => {
        setSelectedHead(head);
        setSelectedSub(null);
        setData(prev => ({ ...prev, narration_head_id: head.id, narration_sub_head_id: '' }));
    };

    const handlePickSub = (sub) => {
        if (selectedSub?.id === sub.id) {
            setSelectedSub(null);
            setData('narration_sub_head_id', '');
        } else {
            setSelectedSub(sub);
            setData('narration_sub_head_id', sub.id);
        }
    };

    const handleInvoiceSelect = (value) => {
        setSelectedInvoiceId(value);

        if (value === null) {
            setData(prev => ({
                ...prev,
                invoice_id:     null,
                invoice_number: null,
                unreconcile:    transaction.is_reconciled, // flag to server: remove existing link
            }));
        } else if (typeof value === 'number') {
            setData(prev => ({ ...prev, invoice_id: value, invoice_number: null, unreconcile: false }));
        } else {
            // String → manually typed invoice number, resolve on server
            setData(prev => ({ ...prev, invoice_id: null, invoice_number: value, unreconcile: false }));
        }
    };

    const handleQuickApprove = () => {
        if (!data.narration_head_id) { setIsExpanded(true); return; }
        submitForm();
    };

    const submitForm = (e) => {
        if (e) e.preventDefault();
        post(route('banking.transactions.review', { transaction: transaction.id, action: 'correct' }), {
            preserveScroll: true,
            onSuccess: () => setIsExpanded(false),
        });
    };

    const handleCancel = () => {
        setIsExpanded(false);
        setSelectedHead(init?.head ?? null);
        setSelectedSub(init?.subHead ?? null);
        setSaveRule(false);
        setSelectedInvoiceId(transaction.is_reconciled ? transaction.reconciled_invoice_id : null);
        reset();
    };

    const activeSubHeads = selectedHead?.active_sub_heads || selectedHead?.sub_heads || [];

    // Step number offset: if invoice section is shown, Vendor = step 4 else step 3
    const vendorStepLabel = hasInvoiceSection ? '4. Vendor Name' : '3. Vendor Name';
    const noteStepLabel   = hasInvoiceSection ? '5. Additional Note' : '4. Additional Note';

    return (
        <div className={cx(
            'rounded-2xl shadow-sm border overflow-hidden transition-all relative',
            isReviewed ? 'bg-gray-50 border-gray-200' : 'bg-white border-gray-100'
        )}>
            {transaction.is_uncertain && !isReviewed && (
                <div className="absolute top-2 right-4 flex items-center gap-1 text-amber-500 bg-amber-50 px-2 py-1 rounded text-xs font-bold ring-1 ring-amber-200">
                    <AlertTriangle size={12} /> Low Confidence
                </div>
            )}

            <div className={cx(
                'text-white text-[10px] font-bold px-4 py-1.5 text-center tracking-widest uppercase',
                isReviewed ? 'bg-emerald-500' : 'bg-indigo-600'
            )}>
                {isReviewed ? 'Reviewed' : 'Pending'}
            </div>

            <div className="p-5 sm:p-6">
                <div className="flex gap-4">
                    <div className={cx(
                        'flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center',
                        isCredit ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'
                    )}>
                        {isCredit ? <TrendingUp size={24} /> : <TrendingDown size={24} />}
                    </div>

                    <div className="flex-1 w-full min-w-0">
                        <h3 className={cx('text-lg font-semibold mb-1', isReviewed ? 'text-gray-600' : 'text-gray-800')}>
                            {formatCurrency(transaction.amount)} {isCredit ? 'received' : 'debit for'}{' '}
                            {data.party_name ? `'${data.party_name}'` : ''}
                        </h3>
                        <p className="text-sm text-gray-500 font-mono mb-4">{transaction.raw_narration}</p>

                        {/* ── Collapsed ──────────────────────────────────── */}
                        {!isExpanded ? (
                            <>
                                {!isReviewed && (transaction.reasoning || init?.head) && (
                                    <div className="bg-[#F8FAFC] border border-indigo-50 rounded-xl p-3 mb-3 flex gap-3 text-sm text-gray-500 italic">
                                        <Wand2 size={16} className="text-indigo-400 flex-shrink-0 mt-0.5" />
                                        <div>
                                            {init?.head && (
                                                <span className="block not-italic font-semibold text-indigo-700 mb-0.5">
                                                    Suggested: {init.head.name}{init.subHead ? ` → ${init.subHead.name}` : ''}
                                                </span>
                                            )}
                                            <p>Narration: {transaction.narration_note}</p><br />
                                            <p>{transaction.reasoning || 'AI mapped this based on similar patterns.'}</p>
                                        </div>
                                    </div>
                                )}

                                {isReviewed && init?.head && (
                                    <div className="bg-emerald-50 border border-emerald-100 rounded-xl p-3 mb-3 flex gap-3 text-sm">
                                        <Check size={18} className="text-emerald-500 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span className="block font-semibold text-emerald-800 mb-0.5">
                                                Categorized as: {init.head.name}{init.subHead ? ` → ${init.subHead.name}` : ''}
                                            </span>
                                            {transaction.narration_note && (
                                                <p className="text-emerald-600/80 italic text-xs">Note: {transaction.narration_note}</p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {/* Read-only strip — hint to open edit for linking */}
                                <ReconciliationStrip transaction={transaction} />

                                <div className="flex gap-3 mt-4">
                                    {!isReviewed ? (
                                        <>
                                            <button
                                                onClick={handleQuickApprove}
                                                disabled={processing}
                                                className="flex-1 bg-[#10B981] hover:bg-[#059669] disabled:opacity-50 text-white font-semibold py-2.5 px-4 rounded-xl flex items-center justify-center gap-2 transition-colors"
                                            >
                                                <Check size={20} /> Yes, Confirm
                                            </button>
                                            <button
                                                onClick={() => setIsExpanded(true)}
                                                className="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 px-4 rounded-xl transition-colors"
                                            >
                                                {init?.head ? 'Enter Narration' : 'Categorize'}
                                            </button>
                                        </>
                                    ) : (
                                        <button
                                            onClick={() => setIsExpanded(true)}
                                            className="w-full bg-white border-2 border-gray-200 hover:border-gray-300 text-gray-600 font-semibold py-2 px-4 rounded-xl flex items-center justify-center gap-2 transition-colors"
                                        >
                                            <Edit3 size={18} /> Edit
                                        </button>
                                    )}
                                </div>
                            </>
                        ) : (
                            /* ── Expanded form ─────────────────────────────── */
                            <form onSubmit={submitForm} className="mt-4 animate-in fade-in duration-200 border-t border-gray-200 pt-4 space-y-6">

                                {/* 1. Head */}
                                <div>
                                    <InputLabel value="1. Select Head *" className="mb-2 text-gray-500 uppercase text-[10px] tracking-widest font-bold" />
                                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                        {relevantHeads.map(h => (
                                            <button
                                                key={h.id}
                                                type="button"
                                                onClick={() => handlePickHead(h)}
                                                className={cx(
                                                    'flex items-center justify-center rounded-xl border-2 py-2 px-2 text-xs font-bold transition-all',
                                                    selectedHead?.id === h.id
                                                        ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm'
                                                        : 'border-gray-100 bg-white text-gray-500 hover:border-gray-300'
                                                )}
                                            >
                                                {h.name}
                                            </button>
                                        ))}
                                    </div>
                                    <InputError message={errors.narration_head_id} className="mt-2" />
                                </div>

                                {/* 2. Sub-Head */}
                                {selectedHead && activeSubHeads.length > 0 && (
                                    <div className="animate-in fade-in slide-in-from-top-2 duration-300">
                                        <InputLabel value="2. Sub-Head (Optional)" className="mb-2 text-gray-500 uppercase text-[10px] tracking-widest font-bold" />
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            {activeSubHeads.map(s => (
                                                <button
                                                    key={s.id}
                                                    type="button"
                                                    onClick={() => handlePickSub(s)}
                                                    className={cx(
                                                        'flex items-center justify-between rounded-xl border-2 px-4 py-2.5 text-left text-sm font-medium transition-all',
                                                        selectedSub?.id === s.id
                                                            ? 'border-gray-900 bg-gray-900 text-white shadow-md'
                                                            : 'border-gray-100 bg-white text-gray-600 hover:border-gray-300'
                                                    )}
                                                >
                                                    {s.name}
                                                    {selectedSub?.id === s.id && <span className="text-indigo-400">●</span>}
                                                </button>
                                            ))}
                                        </div>
                                        <InputError message={errors.narration_sub_head_id} className="mt-2" />
                                    </div>
                                )}

                                {/* 3. Invoice link — only when suggestions exist or already reconciled */}
                                {hasInvoiceSection && (
                                    <div>
                                        <InputLabel
                                            value="3. Link to Invoice (Optional)"
                                            className="mb-2 text-gray-500 uppercase text-[10px] tracking-widest font-bold"
                                        />
                                        <InlineReconciliationPicker
                                            transaction={transaction}
                                            selectedInvoiceId={selectedInvoiceId}
                                            onSelect={handleInvoiceSelect}
                                        />
                                        <InputError message={errors.invoice_id ?? errors.invoice_number} className="mt-2" />
                                    </div>
                                )}

                                {/* 4/3. Vendor + Note */}
                                <div className="grid grid-cols-1 gap-4">
                                    <div>
                                        <InputLabel
                                            htmlFor={`party_${transaction.id}`}
                                            value={`${vendorStepLabel}${selectedSub?.requires_party ? ' *' : ' (Optional)'}`}
                                            className="mb-1"
                                        />
                                        <TextInput
                                            id={`party_${transaction.id}`}
                                            value={data.party_name}
                                            onChange={e => setData('party_name', e.target.value)}
                                            className="w-full bg-gray-50 border-gray-200 text-sm"
                                            placeholder="Vendor/Person name"
                                            required={selectedSub?.requires_party}
                                        />
                                        <InputError message={errors.party_name} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel
                                            htmlFor={`note_${transaction.id}`}
                                            value={`${noteStepLabel} (Optional)`}
                                            className="mb-1"
                                        />
                                        <TextInput
                                            id={`note_${transaction.id}`}
                                            value={data.narration_note}
                                            onChange={e => setData('narration_note', e.target.value)}
                                            className="w-full bg-gray-50 border-gray-200 text-sm"
                                            placeholder="Add specific details..."
                                        />
                                        <InputError message={errors.narration_note} className="mt-1" />
                                    </div>
                                </div>

                                {/* Auto-Rule */}
                                <div className={cx(
                                    'rounded-xl border-2 p-3.5 transition-colors',
                                    saveRule ? 'border-indigo-100 bg-indigo-50/30' : 'border-gray-50 bg-gray-50/50'
                                )}>
                                    <label className="flex cursor-pointer items-center gap-3">
                                        <input
                                            type="checkbox"
                                            checked={saveRule}
                                            onChange={e => { setSaveRule(e.target.checked); setData('save_as_rule', e.target.checked); }}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div className="flex flex-col">
                                            <span className="text-sm font-bold text-gray-800">Auto-categorize in future?</span>
                                            <span className="text-[10px] text-gray-500 leading-tight">Remember this choice for similar narrations.</span>
                                        </div>
                                    </label>
                                </div>

                                {/* Actions */}
                                <div className="flex justify-end gap-3 pt-2">
                                    <button
                                        type="button"
                                        onClick={handleCancel}
                                        className="px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 rounded-xl transition-colors"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={!selectedHead || processing}
                                        className="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl shadow-sm transition-colors"
                                    >
                                        {processing ? 'Saving...' : (isReviewed ? 'Update Details' : 'Confirm Details')}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
