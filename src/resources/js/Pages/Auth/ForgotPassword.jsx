import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, Link } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title="Recuperar Contraseña" />

            <div className="mb-6 text-center">
                <h2 className="text-2xl font-bold text-slate-100">¿Olvidaste tu contraseña?</h2>
                <p className="text-xs text-slate-400 mt-2">
                    Ingresa tu correo institucional y te enviaremos un enlace para restablecer tu contraseña y elegir una nueva de forma segura.
                </p>
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-500 bg-green-500/10 border border-green-500/20 p-3 rounded-xl">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="block w-full bg-slate-950 border-slate-900 focus:border-cyan-500 focus:ring-0 text-slate-200"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="ejemplo@uleam.edu.ec"
                        required
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-6 flex items-center justify-between">
                    <Link
                        href={route('login')}
                        className="text-xs text-slate-400 underline hover:text-slate-200 focus:outline-none"
                    >
                        Volver al inicio de sesión
                    </Link>

                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2.5 rounded-xl text-sm font-bold bg-gradient-to-r from-cyan-500 to-indigo-500 hover:from-cyan-400 hover:to-indigo-400 text-slate-950 hover:text-slate-950 font-bold border-none transition duration-200 shadow-lg shadow-cyan-500/10 cursor-pointer disabled:opacity-50"
                    >
                        Enviar enlace
                    </button>
                </div>
            </form>
        </GuestLayout>
    );
}
