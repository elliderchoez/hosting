import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
        });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                    Información de la Cuenta
                </h2>

                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Actualiza la información básica de tu cuenta, como tu nombre y dirección de correo electrónico.
                </p>
            </header>

            <form onSubmit={submit} className="mt-5 space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <InputLabel htmlFor="name" value="Nombre Completo" className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5" />

                        <TextInput
                            id="name"
                            className="mt-1 block w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            isFocused
                            autoComplete="name"
                        />

                        <InputError className="mt-2" message={errors.name} />
                    </div>

                    <div>
                        <InputLabel htmlFor="email" value="Correo Electrónico" className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5" />

                        <TextInput
                            id="email"
                            type="email"
                            className="mt-1 block w-full bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoComplete="username"
                        />

                        <InputError className="mt-2" message={errors.email} />
                    </div>
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            Tu dirección de correo electrónico no está verificada.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-[#1534e8] dark:text-cyan-400 underline hover:text-blue-700 dark:hover:text-cyan-300 focus:outline-none ml-1"
                            >
                                Haz clic aquí para reenviar el correo de verificación.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className="mt-2 text-sm font-medium text-emerald-600 dark:text-green-400">
                                Se ha enviado un nuevo enlace de verificación a tu dirección de correo electrónico.
                            </div>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4 pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="px-5 py-2.5 rounded-xl text-sm font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition duration-200 disabled:opacity-50"
                    >
                        {processing ? 'Guardando...' : 'Guardar Información'}
                    </button>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm font-medium text-emerald-600 dark:text-green-400">
                            Guardado correctamente.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
