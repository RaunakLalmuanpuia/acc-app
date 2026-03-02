import React, { useState, useEffect, useRef } from 'react';
import { Head, usePage, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    Send, Sparkles, Paperclip, X, AlertCircle, Loader2, Copy, Check,
    FileText, BarChart3, Users, Receipt, ShieldCheck, Zap, Package, Building, Download
} from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

export default function Chat() {
    const { auth, errors } = usePage().props;

    const scrollRef = useRef(null);
    const fileInputRef = useRef(null);
    const textareaRef = useRef(null);

    const [localMessages, setLocalMessages] = useState([
        {
            id: 'welcome',
            role: 'assistant',
            content: "Hello! I'm your AI accounting assistant. How can I help you today?"
        }
    ]);

    const [copiedId, setCopiedId] = useState(null);

    const { data, setData, post, processing, reset, clearErrors } = useForm({
        message: '',
        conversation_id: null,
        attachments: []
    });

    useEffect(() => {
        if (auth.chatResponse && auth.chatResponse.reply) {
            setLocalMessages(prev => [...prev, {
                id: Date.now(),
                role: 'assistant',
                content: auth.chatResponse.reply
            }]);

            if (!data.conversation_id && auth.chatResponse.conversation_id) {
                setData('conversation_id', auth.chatResponse.conversation_id);
            }
        }
    }, [auth.chatResponse]);

    useEffect(() => {
        scrollRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [localMessages, processing]);

    const submit = (e) => {
        e?.preventDefault();
        if ((!data.message.trim() && data.attachments.length === 0) || processing) return;

        setLocalMessages(prev => [...prev, {
            id: Date.now() + 1,
            role: 'user',
            content: data.message.trim(),
            attachmentCount: data.attachments.length
        }]);

        post(route('accounting.chat.send'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reset('message', 'attachments');
                clearErrors();
                if(fileInputRef.current) fileInputRef.current.value = '';
                if (textareaRef.current) {
                    textareaRef.current.style.height = 'auto';
                }
            },
        });
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
            alert("You can only attach up to 5 files at a time.");
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

    const suggestedPrompts = [
        "Fetch me an invoice",
        "Issue an invoice",
        "Give me 6 month report"
    ];

    const botFeatures = [
        { icon: <Building size={18} />, title: "Business Details", desc: "Create and View your company profile, and manage Narration Head and Sub Head." },
        { icon: <Users size={18} />, title: "Client Management", desc: "Look up client details, create new clients, update clients and delete clients." },
        { icon: <Package size={18} />, title: "Inventory Management", desc: "Look up inventory items, add new products, update stock levels, and delete items." },
        { icon: <FileText size={18} />, title: "Invoice Management", desc: "Draft new invoices, preview PDFs, fetch existing records, and manage your billing." },
    ];
    return (
        <AuthenticatedLayout>
            <Head title="Chat Assistant" />

            <div className="bg-gray-50/50 min-h-[calc(100vh-64px)]">
                <div className="max-w-7xl mx-auto p-2 sm:p-6 lg:p-8">

                    {/* Main Container: Full height calc to fit screen */}
                    <div className="flex flex-col lg:flex-row gap-4 h-[calc(100vh-100px)] lg:h-[80vh]">

                        {/* LEFT COLUMN: Chat Area - Forced to grow */}
                        <div className="flex-[3] bg-white overflow-hidden shadow-sm rounded-2xl flex flex-col min-h-0 border border-gray-100 order-1 lg:order-1">
                            <header className="p-3 sm:p-4 border-b border-gray-100 flex items-center justify-between bg-white/80 backdrop-blur-md z-10 sticky top-0">
                                <div className="flex items-center gap-3">
                                    <div className="relative">
                                        <div className="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-br from-[#5d51e8] to-[#8e84f3] rounded-full flex items-center justify-center text-white font-bold text-xs sm:text-sm shadow-sm">
                                            AI
                                        </div>
                                        <span className="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white rounded-full"></span>
                                    </div>
                                    <div>
                                        <h1 className="font-bold text-gray-900 text-sm sm:text-[15px]">Accounting Assistant</h1>
                                        <div className="flex items-center gap-1">
                                            <span className="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                            <span className="text-[10px] sm:text-xs text-gray-500 font-medium">Online</span>
                                        </div>
                                    </div>
                                </div>
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

                                {localMessages.map((msg) => (
                                    <div key={msg.id} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                        <div className={`relative max-w-[92%] sm:max-w-[85%] px-4 py-2.5 sm:px-5 sm:py-3.5 rounded-2xl text-[14px] sm:text-[15px] leading-relaxed shadow-sm group ${
                                            msg.role === 'user'
                                                ? 'bg-[#5d51e8] text-white rounded-br-sm'
                                                : 'bg-white border border-gray-100 text-gray-800 rounded-bl-sm'
                                        }`}>

                                            {msg.role === 'assistant' && (
                                                <button
                                                    onClick={() => handleCopy(msg.content, msg.id)}
                                                    className="absolute top-2 right-2 p-1.5 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-md lg:opacity-0 lg:group-hover:opacity-100 transition-opacity"
                                                >
                                                    {copiedId === msg.id ? <Check size={14} className="text-green-500" /> : <Copy size={14} />}
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
                                                            const textContent = Array.isArray(children) ? children.join('') : String(children);
                                                            const isPdf = href?.toLowerCase().includes('.pdf') || textContent.toLowerCase().includes('pdf');
                                                            if (isPdf) {
                                                                return (
                                                                    <a href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 px-3 py-1.5 my-2 bg-indigo-50 text-indigo-700 rounded-xl border border-indigo-100 text-xs sm:text-sm font-semibold no-underline" {...props}>
                                                                        <Download size={14} /> <span>{children}</span>
                                                                    </a>
                                                                );
                                                            }
                                                            return <a href={href} target="_blank" rel="noopener noreferrer" className="text-[#5d51e8] hover:underline font-medium" {...props}>{children}</a>;
                                                        }
                                                    }}
                                                >
                                                    {msg.content}
                                                </ReactMarkdown>
                                            </div>
                                        </div>
                                    </div>
                                ))}

                                {processing && (
                                    <div className="flex justify-start">
                                        <div className="bg-white border border-gray-100 text-gray-500 px-4 py-2.5 sm:px-5 sm:py-3.5 rounded-2xl rounded-bl-sm flex items-center gap-2 shadow-sm">
                                            <Loader2 size={16} className="animate-spin text-[#5d51e8]" />
                                            <span className="text-xs sm:text-sm">Thinking...</span>
                                        </div>
                                    </div>
                                )}
                                <div ref={scrollRef} />
                            </main>

                            {/* Input Footer */}
                            <footer className="p-3 sm:p-4 bg-white border-t border-gray-100">
                                {data.attachments.length > 0 && (
                                    <div className="flex flex-wrap gap-2 mb-3">
                                        {data.attachments.map((file, idx) => (
                                            <div key={idx} className="flex items-center gap-2 bg-indigo-50 text-indigo-700 px-2 py-1 rounded-lg text-[10px] font-medium border border-indigo-100">
                                                <span className="truncate max-w-[100px]">{file.name}</span>
                                                <button type="button" onClick={() => removeAttachment(idx)} className="hover:text-red-500"><X size={12} /></button>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {!data.attachments.length && localMessages.length <= 1 && (
                                    <div className="flex gap-2 overflow-x-auto no-scrollbar pb-3">
                                        {suggestedPrompts.map((text) => (
                                            <button key={text} onClick={() => { setData('message', text); setTimeout(() => submit(), 50); }}
                                                    className="px-3 py-1.5 bg-white border border-gray-200 rounded-full text-xs font-medium text-gray-600 hover:bg-gray-50 whitespace-nowrap">
                                                {text}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <form onSubmit={submit} className="relative flex items-end bg-gray-100 rounded-2xl sm:rounded-3xl border border-transparent focus-within:bg-white focus-within:ring-2 focus-within:ring-[#5d51e8]/20 transition-all">
                                    <div className="absolute left-3 bottom-3 text-[#5d51e8]/60 pointer-events-none">
                                        <Sparkles size={18} />
                                    </div>
                                    <textarea
                                        ref={textareaRef}
                                        rows={1}
                                        placeholder="Message AI..."
                                        value={data.message}
                                        onChange={handleInput}
                                        onKeyDown={handleKeyDown}
                                        disabled={processing}
                                        className="w-full pl-10 pr-16 sm:pr-24 py-3 bg-transparent border-none focus:ring-0 resize-none text-sm sm:text-base text-gray-800 m-0"
                                        style={{ minHeight: '44px', maxHeight: '120px' }}
                                    />
                                    <div className="absolute right-1.5 bottom-1.5 flex items-center gap-0.5 sm:gap-1">
                                        <button type="button" onClick={() => fileInputRef.current?.click()} className="p-2 text-gray-400 hover:bg-gray-200 rounded-lg"><Paperclip size={18} /></button>
                                        <button type="submit" disabled={processing || (!data.message.trim() && data.attachments.length === 0)} className="p-2 bg-[#5d51e8] text-white rounded-lg disabled:opacity-50"><Send size={18} /></button>
                                    </div>
                                </form>
                                <p className="text-gray-400 text-[9px] mt-2 text-center w-full">AI can make mistakes. Check important info.</p>
                            </footer>
                        </div>

                        {/* RIGHT COLUMN: Smaller sidebar on mobile */}
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
            <input type="file" multiple ref={fileInputRef} onChange={handleFileChange} className="hidden" accept=".pdf,.csv,.xlsx,.xls,.docx,.doc,.txt,.png,.jpg,.jpeg,.webp" />
        </AuthenticatedLayout>
    );
}
