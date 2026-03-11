import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    Sparkles,
    MessageSquare,
    FileText,
    Users,
    Package,
    BarChart3,
    ShieldCheck,
    ArrowRight
} from 'lucide-react';

export default function Landing() {
    const { auth } = usePage().props;

    const isLoggedIn = !!auth?.user;
    return (
        <>
            <Head title="AI Accounting Platform" />

            <div className="min-h-screen bg-gradient-to-b from-white to-gray-50 text-gray-900">

                {/* NAVBAR */}
                <header className="max-w-7xl mx-auto px-6 py-6 flex justify-between items-center">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 bg-gradient-to-br from-[#5d51e8] to-[#8e84f3] rounded-xl flex items-center justify-center text-white font-bold">
                            AI
                        </div>
                        <span className="font-bold text-lg">Accounting AI</span>
                    </div>

                    <div className="flex gap-4">
                        {!isLoggedIn ? (
                            <>
                                <Link
                                    href="/login"
                                    className="border border-gray-200 px-6 py-3 rounded-2xl font-semibold hover:bg-gray-50 transition"
                                >
                                    Login
                                </Link>

                                <Link
                                    href="/register"
                                    className="bg-[#5d51e8] hover:bg-[#4a3fc4] text-white px-6 py-3 rounded-2xl font-semibold flex items-center gap-2 transition shadow-sm"
                                >
                                    Get Started
                                </Link>
                            </>
                        ) : (
                            <Link
                                href="/dashboard"
                                className="bg-[#5d51e8] hover:bg-[#4a3fc4] text-white px-6 py-3 rounded-2xl font-semibold flex items-center gap-2 transition shadow-sm"
                            >
                                Go to Dashboard
                            </Link>
                        )}
                    </div>
                </header>

                {/* HERO SECTION */}
                <section className="max-w-7xl mx-auto px-6 pt-20 pb-24 text-center">
                    <div className="inline-flex items-center gap-2 bg-indigo-50 text-indigo-700 px-4 py-1.5 rounded-full text-sm font-medium mb-6">
                        <Sparkles size={16} />
                        AI Powered Accounting Assistant
                    </div>

                    <h1 className="text-4xl md:text-6xl font-bold leading-tight max-w-4xl mx-auto">
                        Automate Your Accounting with
                        <span className="text-[#5d51e8]"> Intelligent AI</span>
                    </h1>

                    <p className="mt-6 text-lg text-gray-600 max-w-2xl mx-auto">
                        Manage invoices, categorize transactions, upload bank statements,
                        and interact with your financial data using a smart AI assistant.
                    </p>

                    <div className="mt-10 flex justify-center gap-4">
                        <Link
                            href="/register"
                            className="bg-[#5d51e8] hover:bg-[#4a3fc4] text-white px-6 py-3 rounded-2xl font-semibold flex items-center gap-2 transition shadow-sm"
                        >
                            Start Free <ArrowRight size={18} />
                        </Link>

                        <Link
                            href="/login"
                            className="border border-gray-200 px-6 py-3 rounded-2xl font-semibold hover:bg-gray-50 transition"
                        >
                            Login
                        </Link>
                    </div>
                </section>

                {/* FEATURES GRID */}
                <section className="max-w-7xl mx-auto px-6 pb-24">
                    <div className="text-center mb-16">
                        <h2 className="text-3xl font-bold mb-3">
                            Everything You Need in One Platform
                        </h2>
                        <p className="text-gray-600">
                            Designed for modern businesses and accountants.
                        </p>
                    </div>

                    <div className="grid md:grid-cols-3 gap-8">

                        {/* AI Chat */}
                        <FeatureCard
                            icon={<MessageSquare size={22} />}
                            title="AI Chat Assistant"
                            desc="Ask questions, fetch invoices, generate reports, and manage clients through natural conversation."
                        />

                        {/* Invoice Management */}
                        <FeatureCard
                            icon={<FileText size={22} />}
                            title="Invoice Management"
                            desc="Create, preview, and download professional invoices with automatic tax calculations."
                        />

                        {/* Client Management */}
                        <FeatureCard
                            icon={<Users size={22} />}
                            title="Client Management"
                            desc="Add, update, and manage clients effortlessly from one centralized dashboard."
                        />

                        {/* Inventory */}
                        <FeatureCard
                            icon={<Package size={22} />}
                            title="Inventory Tracking"
                            desc="Monitor stock levels, product pricing, GST rates, and manage SKUs easily."
                        />

                        {/* Reports */}
                        <FeatureCard
                            icon={<BarChart3 size={22} />}
                            title="Financial Reports"
                            desc="Generate 6-month reports, summaries, and insights instantly."
                        />

                        {/* Secure */}
                        <FeatureCard
                            icon={<ShieldCheck size={22} />}
                            title="Secure & Reliable"
                            desc="Built with enterprise-grade security and reliable data handling."
                        />
                    </div>
                </section>

                {/* CTA SECTION */}
                <section className="bg-[#5d51e8] text-white py-20 text-center">
                    <h2 className="text-3xl font-bold mb-4">
                        Ready to Simplify Your Accounting?
                    </h2>
                    <p className="opacity-90 mb-8">
                        Join businesses already using AI to streamline their finance workflows.
                    </p>
                    <Link
                        href="/register"
                        className="bg-white text-[#5d51e8] px-8 py-3 rounded-2xl font-bold hover:bg-gray-100 transition"
                    >
                        Get Started Now
                    </Link>
                </section>

                {/* FOOTER */}
                <footer className="py-8 text-center text-sm text-gray-500 border-t border-gray-100">
                    © {new Date().getFullYear()} Accounting AI. All rights reserved.
                </footer>

            </div>
        </>
    );
}

function FeatureCard({ icon, title, desc }) {
    return (
        <div className="bg-white p-8 rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition">
            <div className="w-10 h-10 bg-indigo-50 text-indigo-600 rounded-xl flex items-center justify-center mb-5">
                {icon}
            </div>
            <h3 className="text-lg font-semibold mb-2">{title}</h3>
            <p className="text-gray-600 text-sm leading-relaxed">{desc}</p>
        </div>
    );
}
