import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import {Head, Link, useForm} from '@inertiajs/react';
import {Sparkles} from 'lucide-react';

export default function Login({status, canResetPassword}) {
    const {data, setData, post, processing, errors, reset} = useForm({
        email: '', password: '', remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (<>
        <Head title="Login"/>

        <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-white to-gray-50 px-4">

            <div className="w-full max-w-md">

                {/* Logo + Branding */}
                <div className="text-center mb-8">
                    <div
                        className="mx-auto w-14 h-14 bg-gradient-to-br from-[#5d51e8] to-[#8e84f3] rounded-2xl flex items-center justify-center text-white shadow-md">
                        <Sparkles size={24}/>
                    </div>
                    <h1 className="mt-4 text-2xl font-bold text-gray-900">
                        Welcome Back
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Sign in to your AI Accounting Dashboard
                    </p>
                </div>

                {/* Card */}
                <div className="bg-white p-8 rounded-2xl shadow-sm border border-gray-100">

                    {status && (<div
                        className="mb-4 text-sm font-medium text-green-600 bg-green-50 border border-green-100 rounded-lg p-3">
                        {status}
                    </div>)}

                    <form onSubmit={submit} className="space-y-5">

                        {/* Email */}
                        <div>
                            <InputLabel htmlFor="email" value="Email Address"/>
                            <TextInput
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                autoComplete="username"
                                isFocused={true}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            <InputError message={errors.email} className="mt-2"/>
                        </div>

                        {/* Password */}
                        <div>
                            <InputLabel htmlFor="password" value="Password"/>
                            <TextInput
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                autoComplete="current-password"
                                onChange={(e) => setData('password', e.target.value)}
                            />
                            <InputError message={errors.password} className="mt-2"/>
                        </div>

                        {/* Remember + Forgot */}
                        <div className="flex items-center justify-between text-sm">
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    name="remember"
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                />
                                <span className="text-gray-600">Remember me</span>
                            </label>

                            {canResetPassword && (<Link
                                href={route('password.request')}
                                className="text-[#5d51e8] hover:underline font-medium"
                            >
                                Forgot password?
                            </Link>)}
                        </div>

                        {/* Button */}
                        <PrimaryButton
                            disabled={processing}
                            className="w-full justify-center bg-[#5d51e8] hover:bg-[#4a3fc4] rounded-xl py-3 text-sm font-semibold transition"
                        >
                            {processing ? 'Signing in...' : 'Sign In'}
                        </PrimaryButton>

                        {/* Register */}
                        <div className="text-center text-sm text-gray-500 pt-2">
                            Don’t have an account?{' '}
                            <Link
                                href="/register"
                                className="text-[#5d51e8] font-semibold hover:underline"
                            >
                                Create one
                            </Link>
                        </div>

                    </form>
                </div>

                {/* Footer */}
                <p className="text-center text-xs text-gray-400 mt-6">
                    © {new Date().getFullYear()} Accounting AI. All rights reserved.
                </p>

            </div>
        </div>
    </>);
}
