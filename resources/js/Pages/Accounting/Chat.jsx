import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Send, Sparkles, Paperclip, X, AlertCircle, Loader2, Copy, Check,
    FileText, Users, Package, Building, Download, AlertTriangle,
    CheckCircle2, XCircle
} from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

// ─── HITL Confirmation Card ────────────────────────────────────────────────
function HitlConfirmCard({ warningText, pendingId, onConfirm, onCancel, isConfirming }) {
    const fileInputRef = useRef(null);
    const [attachments, setAttachments] = useState([]);

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files);
        setAttachments(prev => [...prev, ...files].slice(0, 5));
    };

    const removeAttachment = (idx) => {
        setAttachments(prev => prev.filter((_, i) => i !== idx));
    };

    return (
        <div className="flex justify-start w-full">
            <div className="max-w-[92%] sm:max-w-[85%] rounded-2xl rounded-bl-sm overflow-hidden shadow-sm border border-amber-200 bg-white">

                <div className="flex items-center gap-2 px-4 py-2.5 bg-amber-50 border-b border-amber-200">
                    <AlertTriangle size={16} className="text-amber-600 flex-shrink-0" />
                    <span className="text-xs font-bold text-amber-700 uppercase tracking-wide">
                        Action Requires Confirmation
                    </span>
                </div>

                <div className="px-4 py-3 text-sm text-gray-700 markdown-body">
                    <ReactMarkdown remarkPlugins={[remarkGfm]}
                                   components={{
                                       p: ({node, ...props}) => <p className="mb-2 last:mb-0" {...props} />,
                                       strong: ({node, ...props}) => <strong className="font-semibold text-gray-900" {...props} />,
                                   }}
                    >
                        {warningText}
                    </ReactMarkdown>
                </div>

                {attachments.length > 0 && (
                    <div className="px-4 pb-2 flex flex-wrap gap-2">
                        {attachments.map((file, idx) => (
                            <div key={idx} className="flex items-center gap-2 bg-indigo-50 text-indigo-700 px-2 py-1 rounded-lg text-[10px] font-medium border border-indigo-100">
                                <span className="truncate max-w-[100px]">{file.name}</span>
                                <button type="button" onClick={() => removeAttachment(idx)} className="hover:text-red-500">
                                    <X size={10} />
                                </button>
                            </div>
                        ))}
                    </div>
                )}

                <div className="px-4 py-3 bg-gray-50/70 border-t border-gray-100 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={isConfirming}
                        className="flex items-center gap-1.5 px-3 py-1.5 text-xs text-gray-500 border border-gray-200 rounded-lg hover:bg-white transition-colors disabled:opacity-50"
                    >
                        <Paperclip size={12} />
                        Re-attach files
                    </button>
                    <input
                        type="file" multiple ref={fileInputRef}
                        onChange={handleFileChange}
                        className="hidden"
                        accept=".pdf,.csv,.xlsx,.xls,.docx,.doc,.txt,.png,.jpg,.jpeg,.webp"
                    />

                    <div className="flex items-center gap-2 ml-auto">
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={isConfirming}
                            className="flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 transition-colors disabled:opacity-50"
                        >
                            <XCircle size={14} />
                            Cancel
                        </button>

                        <button
                            type="button"
                            onClick={() => onConfirm(pendingId, attachments)}
                            disabled={isConfirming}
                            className="flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors disabled:opacity-60"
                        >
                            {isConfirming
                                ? <><Loader2 size={13} className="animate-spin" /> Confirming…</>
                                : <><CheckCircle2 size={14} /> Yes, proceed</>
                            }
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Main Chat Component ───────────────────────────────────────────────────
export default function Chat() {
    const { auth, errors } = usePage().props;

    const scrollRef    = useRef(null);
    const fileInputRef = useRef(null);
    const textareaRef  = useRef(null);

    const [localMessages, setLocalMessages] = useState([
        {
            id:      'welcome',
            role:    'assistant',
            content: "Hello! I'm your AI accounting assistant. How can I help you today?",
        }
    ]);

    const [hitlState, setHitlState] = useState({
        active:    false,
        pendingId: null,
    });

    // Tracks the router.post() in-flight state for the confirm call.
    //
    // WHY NOT useForm here:
    //   useForm's setData() schedules an async React state update. Calling
    //   setConfirmData('pending_id', id) then postConfirm() immediately after
    //   submits the OLD state — pending_id arrives as "" on the backend,
    //   consumePendingAction() finds nothing, and returns "This confirmation
    //   has expired". The delete never runs.
    //
    //   router.post() from @inertiajs/react accepts the payload as a plain
    //   object in the second argument — no React state involved, no race.
    const [confirming, setConfirming] = useState(false);

    const [copiedId, setCopiedId] = useState(null);

    const { data, setData, post, processing, reset, clearErrors } = useForm({
        message:         '',
        conversation_id: null,
        attachments:     [],
    });

    // ── React to every chatResponse from the server ────────────────────────
    useEffect(() => {
        const response = auth?.chatResponse;
        if (!response?.reply) return;

        if (response.hitl_pending) {
            setLocalMessages(prev => [...prev, {
                id:        Date.now(),
                role:      'assistant',
                content:   response.reply,
                isHitl:    true,
                pendingId: response.pending_id,
            }]);
            setHitlState({ active: true, pendingId: response.pending_id });
        } else {
            // Normal reply OR successful confirm — clear HITL gate
            setHitlState({ active: false, pendingId: null });
            setConfirming(false);
            setLocalMessages(prev => [...prev, {
                id:      Date.now(),
                role:    'assistant',
                content: response.reply,
            }]);
        }

        if (!data.conversation_id && response.conversation_id) {
            setData('conversation_id', response.conversation_id);
        }
    }, [auth.chatResponse]);

    useEffect(() => {
        scrollRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [localMessages, processing, confirming]);

    // ── Submit main message ────────────────────────────────────────────────
    const submit = (e) => {
        e?.preventDefault();
        if ((!data.message.trim() && data.attachments.length === 0) || processing) return;

        setLocalMessages(prev => [...prev, {
            id:              Date.now() + 1,
            role:            'user',
            content:         data.message.trim(),
            attachmentCount: data.attachments.length,
        }]);

        post(route('accounting.chat.send'), {
            preserveScroll: true,
            preserveState:  true,
            onSuccess: () => {
                reset('message', 'attachments');
                clearErrors();
                if (fileInputRef.current) fileInputRef.current.value = '';
                if (textareaRef.current) textareaRef.current.style.height = 'auto';
            },
        });
    };

    // ── Confirm a HITL-pending action ──────────────────────────────────────
    //
    // Uses router.post() — NOT useForm — so pending_id is passed inline as a
    // plain JS value. There is no async state update between "set the value"
    // and "send the request", so the backend always receives the correct UUID.
    const handleHitlConfirm = (pendingId, attachments) => {
        setConfirming(true);

        setLocalMessages(prev => [...prev, {
            id:      Date.now(),
            role:    'user',
            content: '✅ Confirmed — proceeding with the action.',
        }]);

        router.post(
            route('accounting.chat.confirm'),
            { pending_id: pendingId, attachments },
            {
                preserveScroll: true,
                preserveState:  true,
                onError: () => {
                    setConfirming(false);
                    setHitlState({ active: false, pendingId: null });
                    setLocalMessages(prev => [...prev, {
                        id:      Date.now(),
                        role:    'assistant',
                        content: 'The confirmation could not be processed. Please try again.',
                    }]);
                },
            }
        );
    };

    // ── Cancel HITL checkpoint — client-only, no backend call needed ───────
    const handleHitlCancel = () => {
        setHitlState({ active: false, pendingId: null });
        setLocalMessages(prev => [...prev, {
            id:      Date.now(),
            role:    'assistant',
            content: 'Action cancelled. How else can I help you?',
        }]);
    };

    const handleInput = (e) => {
        setData('message', e.target.value);
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 200)}px`;
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit(e);
        }
    };

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files);
        if (data.attachments.length + files.length > 5) {
            alert('You can only attach up to 5 files at a time.');
            return;
        }
        setData('attachments', [...data.attachments, ...files]);
    };

    const removeAttachment = (index) => {
        const newAttachments = [...data.attachments];
        newAttachments.splice(index, 1);
        setData('attachments', newAttachments);
    };

    const handleCopy = (text, id) => {
        navigator.clipboard.writeText(text);
        setCopiedId(id);
        setTimeout(() => setCopiedId(null), 2000);
    };

    const inputBlocked = processing || confirming || hitlState.active;

    const suggestedPrompts = [
        'Fetch me an invoice',
        'Issue an invoice',
        'Give me 6 month report',
    ];

    const botFeatures = [
        { icon: <Building size={18} />, title: 'Business Details',     desc: 'Create and View your company profile, and manage Narration Head and Sub Head.' },
        { icon: <Users    size={18} />, title: 'Client Management',    desc: 'Look up client details, create new clients, update clients and delete clients.' },
        { icon: <Package  size={18} />, title: 'Inventory Management', desc: 'Look up inventory items, add new products, update stock levels, and delete items.' },
        { icon: <FileText size={18} />, title: 'Invoice Management',   desc: 'Draft new invoices, preview PDFs, fetch existing records, and manage your billing.' },
    ];

    return (
        <AuthenticatedLayout>
            <Head title="Chat Assistant" />

            <div className="bg-gray-50/50 min-h-[calc(100vh-64px)]">
                <div className="max-w-7xl mx-auto p-2 sm:p-6 lg:p-8">
                    <div className="flex flex-col lg:flex-row gap-4 h-[calc(100vh-100px)] lg:h-[80vh]">

                        {/* ── LEFT COLUMN: Chat ── */}
                        <div className="flex-[3] bg-white overflow-hidden shadow-sm rounded-2xl flex flex-col min-h-0 border border-gray-100 order-1 lg:order-1">

                            <header className="p-3 sm:p-4 border-b border-gray-100 flex items-center justify-between bg-white/80 backdrop-blur-md z-10 sticky top-0">
                                <div className="flex items-center gap-3">
                                    <div className="relative">
                                        <div className="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-br from-[#5d51e8] to-[#8e84f3] rounded-full flex items-center justify-center text-white font-bold text-xs sm:text-sm shadow-sm">
                                            AI
                                        </div>
                                        <span className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full" />
                                    </div>
                                    <div>
                                        <h1 className="font-bold text-gray-900 text-sm sm:text-[15px]">Accounting Assistant</h1>
                                        <div className="flex items-center gap-1">
                                            <span className="w-1.5 h-1.5 bg-green-500 rounded-full" />
                                            <span className="text-[10px] sm:text-xs text-gray-500 font-medium">Online</span>
                                        </div>
                                    </div>
                                </div>

                                {hitlState.active && (
                                    <div className="flex items-center gap-1.5 px-3 py-1 bg-amber-50 border border-amber-200 rounded-full">
                                        <AlertTriangle size={12} className="text-amber-600" />
                                        <span className="text-[10px] font-semibold text-amber-700">Awaiting confirmation</span>
                                    </div>
                                )}
                            </header>

                            {errors.ai && (
                                <div className="bg-red-50 p-3 flex items-center gap-2 text-red-700 text-xs sm:text-sm border-b border-red-100">
                                    <AlertCircle size={16} />
                                    <span>{errors.ai}</span>
                                </div>
                            )}

                            <main className="flex-1 overflow-y-auto p-3 sm:p-6 space-y-4 sm:space-y-6 bg-gray-50/50">
                                <div className="flex justify-center">
                                    <span className="text-[9px] sm:text-[10px] font-bold text-gray-400 bg-gray-100 px-3 py-1 rounded-full uppercase tracking-widest">
                                        Today
                                    </span>
                                </div>

                                {localMessages.map((msg) => {
                                    // Active HITL message → render confirm card
                                    if (msg.isHitl && hitlState.active && msg.pendingId === hitlState.pendingId) {
                                        return (
                                            <HitlConfirmCard
                                                key={msg.id}
                                                warningText={msg.content}
                                                pendingId={msg.pendingId}
                                                onConfirm={handleHitlConfirm}
                                                onCancel={handleHitlCancel}
                                                isConfirming={confirming}
                                            />
                                        );
                                    }

                                    // Standard bubble
                                    return (
                                        <div key={msg.id} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                            <div className={`relative max-w-[92%] sm:max-w-[85%] px-4 py-2.5 sm:px-5 sm:py-3.5 rounded-2xl text-[14px] sm:text-[15px] leading-relaxed shadow-sm group ${
                                                msg.role === 'user'
                                                    ? 'bg-[#5d51e8] text-white rounded-br-sm'
                                                    : 'bg-white border border-gray-100 text-gray-800 rounded-bl-sm'
                                            }`}>
                                                {msg.role === 'assistant' && !msg.isHitl && (
                                                    <button
                                                        onClick={() => handleCopy(msg.content, msg.id)}
                                                        className="absolute top-2 right-2 p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-md lg:opacity-0 lg:group-hover:opacity-100 transition-opacity"
                                                    >
                                                        {copiedId === msg.id
                                                            ? <Check size={14} className="text-green-500" />
                                                            : <Copy size={14} />
                                                        }
                                                    </button>
                                                )}

                                                {msg.attachmentCount > 0 && (
                                                    <div className="flex items-center gap-1 text-[10px] sm:text-xs opacity-75 mb-2 pb-2 border-b border-white/20">
                                                        <Paperclip size={12} /> {msg.attachmentCount} file(s) attached
                                                    </div>
                                                )}

                                                <div className="markdown-body pr-4 sm:pr-6 break-words">
                                                    <ReactMarkdown
                                                        remarkPlugins={[remarkGfm]}
                                                        components={{
                                                            p: ({node, ...props}) => <p className="mb-2 last:mb-0" {...props} />,
                                                            table: ({node, ...props}) => (
                                                                <div className="overflow-x-auto my-3">
                                                                    <table className="min-w-full divide-y divide-gray-200 border border-gray-200 text-xs sm:text-sm" {...props} />
                                                                </div>
                                                            ),
                                                            a: ({node, href, children, ...props}) => {
                                                                const text  = Array.isArray(children) ? children.join('') : String(children);
                                                                const isPdf = href?.toLowerCase().includes('.pdf') || text.toLowerCase().includes('pdf');
                                                                if (isPdf) {
                                                                    return (
                                                                        <a href={href} target="_blank" rel="noopener noreferrer"
                                                                           className="inline-flex items-center gap-2 px-3 py-1.5 my-2 bg-indigo-50 text-indigo-700 rounded-xl border border-indigo-100 text-xs sm:text-sm font-semibold no-underline" {...props}>
                                                                            <Download size={14} /> <span>{children}</span>
                                                                        </a>
                                                                    );
                                                                }
                                                                return (
                                                                    <a href={href} target="_blank" rel="noopener noreferrer"
                                                                       className="text-[#5d51e8] hover:underline font-medium" {...props}>
                                                                        {children}
                                                                    </a>
                                                                );
                                                            },
                                                        }}
                                                    >
                                                        {msg.content}
                                                    </ReactMarkdown>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}

                                {(processing || confirming) && (
                                    <div className="flex justify-start">
                                        <div className="bg-white border border-gray-100 text-gray-500 px-4 py-2.5 sm:px-5 sm:py-3.5 rounded-2xl rounded-bl-sm flex items-center gap-2 shadow-sm">
                                            <Loader2 size={16} className="animate-spin text-[#5d51e8]" />
                                            <span className="text-xs sm:text-sm">
                                                {confirming ? 'Processing confirmed action…' : 'Thinking…'}
                                            </span>
                                        </div>
                                    </div>
                                )}

                                <div ref={scrollRef} />
                            </main>

                            {/* ── Input Footer ── */}
                            <footer className="p-3 sm:p-4 bg-white border-t border-gray-100">
                                {hitlState.active && (
                                    <div className="flex items-center gap-2 mb-3 px-3 py-2 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-700">
                                        <AlertTriangle size={13} className="flex-shrink-0" />
                                        <span>Please confirm or cancel the action above before sending a new message.</span>
                                    </div>
                                )}

                                {data.attachments.length > 0 && (
                                    <div className="flex flex-wrap gap-2 mb-3">
                                        {data.attachments.map((file, idx) => (
                                            <div key={idx} className="flex items-center gap-2 bg-indigo-50 text-indigo-700 px-2 py-1 rounded-lg text-[10px] font-medium border border-indigo-100">
                                                <span className="truncate max-w-[100px]">{file.name}</span>
                                                <button type="button" onClick={() => removeAttachment(idx)} className="hover:text-red-500">
                                                    <X size={12} />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {!data.attachments.length && localMessages.length <= 1 && (
                                    <div className="flex gap-2 overflow-x-auto no-scrollbar pb-3">
                                        {suggestedPrompts.map((text) => (
                                            <button key={text}
                                                    onClick={() => { setData('message', text); setTimeout(() => submit(), 50); }}
                                                    className="px-3 py-1.5 bg-white border border-gray-200 rounded-full text-xs font-medium text-gray-600 hover:bg-gray-50 whitespace-nowrap"
                                            >
                                                {text}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <form onSubmit={submit}
                                      className={`relative flex items-end rounded-2xl sm:rounded-3xl border transition-all ${
                                          inputBlocked
                                              ? 'bg-gray-100 opacity-60 pointer-events-none border-transparent'
                                              : 'bg-gray-100 border-transparent focus-within:bg-white focus-within:ring-2 focus-within:ring-[#5d51e8]/20'
                                      }`}
                                >
                                    <div className="absolute left-3 bottom-3 text-[#5d51e8]/60 pointer-events-none">
                                        <Sparkles size={18} />
                                    </div>
                                    <textarea
                                        ref={textareaRef}
                                        rows={1}
                                        placeholder={hitlState.active ? 'Confirm or cancel the action above first…' : 'Message AI…'}
                                        value={data.message}
                                        onChange={handleInput}
                                        onKeyDown={handleKeyDown}
                                        disabled={inputBlocked}
                                        className="w-full pl-10 pr-16 sm:pr-24 py-3 bg-transparent border-none focus:ring-0 resize-none text-sm sm:text-base text-gray-800 m-0"
                                        style={{ minHeight: '44px', maxHeight: '120px' }}
                                    />
                                    <div className="absolute right-1.5 bottom-1.5 flex items-center gap-0.5 sm:gap-1">
                                        <button type="button" onClick={() => fileInputRef.current?.click()} disabled={inputBlocked} className="p-2 text-gray-400 hover:bg-gray-200 rounded-lg">
                                            <Paperclip size={18} />
                                        </button>
                                        <button type="submit"
                                                disabled={inputBlocked || (!data.message.trim() && data.attachments.length === 0)}
                                                className="p-2 bg-[#5d51e8] text-white rounded-lg disabled:opacity-50"
                                        >
                                            <Send size={18} />
                                        </button>
                                    </div>
                                </form>
                                <p className="text-gray-400 text-[9px] mt-2 text-center w-full">AI can make mistakes. Check important info.</p>
                            </footer>
                        </div>

                        {/* ── RIGHT COLUMN: Sidebar ── */}
                        <aside className="w-full lg:w-[280px] flex-shrink-0 bg-white overflow-hidden shadow-sm rounded-xl sm:rounded-2xl border border-gray-100 flex flex-col order-2 lg:order-2 max-h-[160px] lg:max-h-full">
                            <div className="p-3 border-b border-gray-100 bg-gray-50/50">
                                <h2 className="font-bold text-gray-900 flex items-center gap-2 text-xs sm:text-sm uppercase tracking-wider">
                                    <Sparkles size={14} className="text-[#5d51e8]" />
                                    What I can do
                                </h2>
                            </div>
                            <div className="flex-1 overflow-y-auto p-2 lg:p-3 space-y-1 sm:space-y-2">
                                {botFeatures.map((feature, idx) => (
                                    <div key={idx} className="flex gap-3 p-2 rounded-lg lg:rounded-xl hover:bg-gray-50 border border-transparent lg:hover:border-gray-100">
                                        <div className="flex-shrink-0 w-6 h-6 sm:w-8 sm:h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                                            {React.cloneElement(feature.icon, { size: 14 })}
                                        </div>
                                        <div className="min-w-0">
                                            <h3 className="text-[11px] sm:text-xs font-semibold text-gray-800 truncate">{feature.title}</h3>
                                            <p className="text-[10px] text-gray-400 mt-0.5 leading-tight lg:leading-relaxed truncate lg:whitespace-normal">{feature.desc}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </aside>

                    </div>
                </div>
            </div>

            <input type="file" multiple ref={fileInputRef} onChange={handleFileChange} className="hidden"
                   accept=".pdf,.csv,.xlsx,.xls,.docx,.doc,.txt,.png,.jpg,.jpeg,.webp" />
        </AuthenticatedLayout>
    );
}
