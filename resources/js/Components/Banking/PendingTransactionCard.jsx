import { useState, useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import { Check, Wand2, TrendingUp, TrendingDown, AlertTriangle, Edit3 } from 'lucide-react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';

// --- Helper Functions ---
const cx = (...classes) => classes.filter(Boolean).join(' ');

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 0
    }).format(amount);
};

const getInitData = (heads, transaction) => {
    if (transaction.narration_sub_head_id) {
        for (const h of heads) {
            const subHeads = h.active_sub_heads || h.sub_heads || [];
            const s = subHeads.find((sub) => sub.id === transaction.narration_sub_head_id);
            if (s) return { head: h, subHead: s };
        }
    }
    if (transaction.narration_head_id) {
        const h = heads.find(h => h.id === transaction.narration_head_id);
        if (h) return { head: h, subHead: null };
    }
    return { head: null, subHead: null };
};

export default function PendingTransactionCard({ transaction, heads = [] }) {
    const isCredit = transaction.type === 'credit';
    const relevantHeads = heads.filter(h => h.type === transaction.type || h.type === 'both');

    // Check if the transaction is already reviewed/completed
    const isReviewed = transaction.review_status !== 'pending';

    const init = useMemo(() => getInitData(relevantHeads, transaction), [relevantHeads, transaction]);

    const [isExpanded, setIsExpanded] = useState(false);
    const [selectedHead, setSelectedHead] = useState(init?.head ?? null);
    const [selectedSub, setSelectedSub] = useState(init?.subHead ?? null);
    const [saveRule, setSaveRule] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        narration_head_id:     init?.head?.id ?? '',
        narration_sub_head_id: init?.subHead?.id ?? '',
        party_name:            transaction.party_name ?? '',
        narration_note:        transaction.narration_note ?? '',
        save_as_rule:          false,
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

    const handleQuickApprove = () => {
        if (!data.narration_head_id) {
            setIsExpanded(true);
            return;
        }
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
        reset();
    };

    const activeSubHeads = selectedHead?.active_sub_heads || selectedHead?.sub_heads || [];

    return (
        <div className={cx(
            "rounded-2xl shadow-sm border overflow-hidden transition-all relative",
            isReviewed ? "bg-gray-50 border-gray-200" : "bg-white border-gray-100"
        )}>
            {transaction.is_uncertain && !isReviewed && (
                <div className="absolute top-2 right-4 flex items-center gap-1 text-amber-500 bg-amber-50 px-2 py-1 rounded text-xs font-bold ring-1 ring-amber-200">
                    <AlertTriangle size={12} /> Low Confidence
                </div>
            )}

            {/* Dynamic Badge */}
            <div className={cx(
                "text-white text-[10px] font-bold px-4 py-1.5 text-center tracking-widest uppercase",
                isReviewed ? "bg-emerald-500" : "bg-indigo-600"
            )}>
                {isReviewed ? 'Reviewed' : 'Pending'}
            </div>

            <div className="p-5 sm:p-6">
                <div className="flex gap-4">
                    <div className={cx(
                        "flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center",
                        isCredit ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'
                    )}>
                        {isCredit ? <TrendingUp size={24} /> : <TrendingDown size={24} />}
                    </div>

                    <div className="flex-1 w-full">
                        <h3 className={cx("text-lg font-semibold mb-1", isReviewed ? "text-gray-600" : "text-gray-800")}>
                            {formatCurrency(transaction.amount)} {isCredit ? 'received' : 'debit for'} {data.party_name ? `'${data.party_name}'` : ''}
                        </h3>
                        <p className="text-sm text-gray-500 font-mono mb-4">{transaction.raw_narration}</p>

                        {/* --- COLLAPSED VIEW --- */}
                        {!isExpanded ? (
                            <>
                                {/* Status specific highlight box */}
                                {!isReviewed && (transaction.reasoning || init?.head) && (
                                    <div className="bg-[#F8FAFC] border border-indigo-50 rounded-xl p-3 mb-5 flex gap-3 text-sm text-gray-500 italic">
                                        <Wand2 size={16} className="text-indigo-400 flex-shrink-0 mt-0.5" />
                                        <div>
                                            {init?.head && (
                                                <span className="block not-italic font-semibold text-indigo-700 mb-0.5">
                                                    Suggested: {init.head.name} {init.subHead ? `→ ${init.subHead.name}` : ''}
                                                </span>
                                            )}
                                            <p>Narration: {transaction.narration_note}</p><br/>
                                            <p>{transaction.reasoning || 'AI mapped this based on similar patterns.'}</p>
                                        </div>
                                    </div>
                                )}

                                {isReviewed && init?.head && (
                                    <div className="bg-emerald-50 border border-emerald-100 rounded-xl p-3 mb-5 flex gap-3 text-sm">
                                        <Check size={18} className="text-emerald-500 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <span className="block font-semibold text-emerald-800 mb-0.5">
                                                Categorized as: {init.head.name} {init.subHead ? `→ ${init.subHead.name}` : ''}
                                            </span>
                                            {transaction.narration_note && <p className="text-emerald-600/80 italic text-xs">Note: {transaction.narration_note}</p>}
                                        </div>
                                    </div>
                                )}

                                {/* Action Buttons */}
                                <div className="flex gap-3">
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
                            /* --- EXPANDED (EDIT) VIEW --- */
                            <form onSubmit={submitForm} className="mt-4 animate-in fade-in duration-200 border-t border-gray-200 pt-4 space-y-6">

                                {/* 1. Category Selection */}
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

                                {/* 2. Sub-Category Grid */}
                                {selectedHead && activeSubHeads.length > 0 && (
                                    <div className="animate-in fade-in slide-in-from-top-2 duration-300">
                                        <InputLabel value={`2. Sub-Head (Optional)`} className="mb-2 text-gray-500 uppercase text-[10px] tracking-widest font-bold" />
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

                                {/* 3. Meta Fields */}
                                <div className="grid grid-cols-1 gap-4">
                                    <div>
                                        <InputLabel
                                            htmlFor={`party_${transaction.id}`}
                                            value={selectedSub?.requires_party ? "Party Name *" : "Vendor Name (Optional)"}
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
                                            value="Additional Note (Optional)"
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

                                {/* 4. Auto-Rule Toggle */}
                                <div className={cx(
                                    'rounded-xl border-2 p-3.5 transition-colors',
                                    saveRule ? 'border-indigo-100 bg-indigo-50/30' : 'border-gray-50 bg-gray-50/50'
                                )}>
                                    <label className="flex cursor-pointer items-center gap-3">
                                        <input
                                            type="checkbox"
                                            checked={saveRule}
                                            onChange={e => {
                                                setSaveRule(e.target.checked);
                                                setData('save_as_rule', e.target.checked);
                                            }}
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
