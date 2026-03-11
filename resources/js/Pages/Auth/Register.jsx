import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, Link, useForm } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Register" />

            <div className="min-h-screen flex items-center justify-center bg-gradient-to-b from-white to-gray-50 px-4">
                <div className="w-full max-w-md">

                    {/* Logo + Branding */}
                    <div className="text-center mb-8">
                        <div className="mx-auto w-14 h-14 bg-gradient-to-br from-[#5d51e8] to-[#8e84f3] rounded-2xl flex items-center justify-center text-white shadow-md">
                            <Sparkles size={24} />
                        </div>
                        <h1 className="mt-4 text-2xl font-bold text-gray-900">
                            Create Your Account
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Join AI Accounting and simplify your workflow
                        </p>
                    </div>

                    {/* Card */}
                    <div className="bg-white p-8 rounded-2xl shadow-sm border border-gray-100">

                        <form onSubmit={submit} className="space-y-5">

                            {/* Name */}
                            <div>
                                <InputLabel htmlFor="name" value="Full Name" />
                                <TextInput
                                    id="name"
                                    name="name"
                                    value={data.name}
                                    className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                    autoComplete="name"
                                    isFocused={true}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            {/* Email */}
                            <div>
                                <InputLabel htmlFor="email" value="Email Address" />
                                <TextInput
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={data.email}
                                    className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                    autoComplete="username"
                                    onChange={(e) => setData('email', e.target.value)}
                                    required
                                />
                                <InputError message={errors.email} className="mt-2" />
                            </div>

                            {/* Password */}
                            <div>
                                <InputLabel htmlFor="password" value="Password" />
                                <TextInput
                                    id="password"
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                    autoComplete="new-password"
                                    onChange={(e) => setData('password', e.target.value)}
                                    required
                                />
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            {/* Confirm Password */}
                            <div>
                                <InputLabel
                                    htmlFor="password_confirmation"
                                    value="Confirm Password"
                                />
                                <TextInput
                                    id="password_confirmation"
                                    type="password"
                                    name="password_confirmation"
                                    value={data.password_confirmation}
                                    className="mt-2 block w-full bg-gray-50 border-gray-200 rounded-xl focus:border-[#5d51e8] focus:ring-[#5d51e8]"
                                    autoComplete="new-password"
                                    onChange={(e) =>
                                        setData('password_confirmation', e.target.value)
                                    }
                                    required
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                    className="mt-2"
                                />
                            </div>

                            {/* Button */}
                            <PrimaryButton
                                disabled={processing}
                                className="w-full justify-center bg-[#5d51e8] hover:bg-[#4a3fc4] rounded-xl py-3 text-sm font-semibold transition"
                            >
                                {processing ? 'Creating account...' : 'Create Account'}
                            </PrimaryButton>

                            {/* Login link */}
                            <div className="text-center text-sm text-gray-500 pt-2">
                                Already have an account?{' '}
                                <Link
                                    href={route('login')}
                                    className="text-[#5d51e8] font-semibold hover:underline"
                                >
                                    Sign in
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
        </>
    );
}
