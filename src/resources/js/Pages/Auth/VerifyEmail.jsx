import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verificación de Correo" />

            <div className="mb-6 text-center">
                <h2 className="text-2xl font-bold text-slate-100">Verifica tu correo</h2>
                <p className="text-xs text-slate-400 mt-2">
                    ¡Gracias por registrarte! Antes de comenzar, por favor verifica tu cuenta haciendo clic en el enlace de correo que te enviamos. Si no lo recibiste, podemos enviarte otro.
                </p>
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-green-500 bg-green-500/10 border border-green-500/20 p-3 rounded-xl">
                    Se ha enviado un nuevo enlace de verificación al correo electrónico registrado.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div className="flex items-center justify-between mt-6">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2.5 rounded-xl text-sm font-bold bg-gradient-to-r from-cyan-500 to-indigo-500 hover:from-cyan-400 hover:to-indigo-400 text-slate-950 hover:text-slate-950 font-bold border-none transition duration-200 shadow-lg shadow-cyan-500/10 cursor-pointer disabled:opacity-50"
                    >
                        {processing ? 'Reenviando...' : 'Reenviar enlace de verificación'}
                    </button>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="text-xs text-slate-400 underline hover:text-slate-200 focus:outline-none"
                    >
                        Cerrar Sesión
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
