import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const XSS_PATTERN = /(<script\b[^>]*>|<\/?(iframe|object|embed|applet|meta|link|style)\b|javascript\s*:|data\s*:\s*text\/html|on(load|error|click|mouseover|submit|focus|keydown)\s*=|(\bunion\s+select\b|\bdrop\s+table\b))/i;

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

    // Validaciones de Ciberseguridad en Tiempo Real
    const nameHasXss = useMemo(() => XSS_PATTERN.test(data.name || ''), [data.name]);
    const isNameValid = useMemo(() => {
        if (!data.name) return false;
        if (nameHasXss) return false;
        const trimmed = data.name.trim();
        return trimmed.length >= 2 && trimmed.length <= 20 && /^[\p{L}0-9\s\-._]+$/u.test(trimmed);
    }, [data.name, nameHasXss]);

    const emailHasXss = useMemo(() => XSS_PATTERN.test(data.email || ''), [data.email]);
    const isEmailValid = useMemo(() => {
        if (!data.email) return false;
        if (emailHasXss) return false;
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email) && data.email.length <= 120;
    }, [data.email, emailHasXss]);

    const isFormBlocked = !isNameValid || !isEmailValid || nameHasXss || emailHasXss;

    const submit = (e) => {
        e.preventDefault();
        if (isFormBlocked) return;
        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                        Información de la Cuenta
                    </h2>

                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Actualiza la información básica de tu cuenta, como tu nombre y dirección de correo electrónico.
                    </p>
                </div>


            </header>

            <form onSubmit={submit} className="mt-5 space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Campo Nombre Completo */}
                    <div>
                        <div className="flex justify-between items-center mb-1.5">
                            <InputLabel htmlFor="name" value="Nombre Completo" className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300" />
                            <span className="text-[10px] text-slate-400 font-mono">
                                {data.name?.length || 0}/20
                            </span>
                        </div>

                        <TextInput
                            id="name"
                            className={`mt-1 block w-full bg-white dark:bg-slate-950 border focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs transition ${!isNameValid && data.name
                                ? 'border-rose-500 focus:border-rose-500'
                                : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                }`}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            maxLength={20}
                            required
                            isFocused
                            autoComplete="name"
                            placeholder="Tu nombre o nombre de usuario"
                        />

                        {nameHasXss && (
                            <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span>Alerta se detectó código malicioso o scripts no permitidos.</span>
                            </p>
                        )}

                        {!nameHasXss && !isNameValid && data.name && (
                            <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span>Solo se permiten letras, números y caracteres válidos (de 2 a 20 caracteres).</span>
                            </p>
                        )}

                        <InputError className="mt-2" message={errors.name} />
                    </div>

                    {/* Campo Correo Electrónico */}
                    <div>
                        <div className="flex justify-between items-center mb-1.5">
                            <InputLabel htmlFor="email" value="Correo Electrónico" className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300" />
                            <span className="text-[10px] text-slate-400 font-mono">
                                {data.email?.length || 0}/120
                            </span>
                        </div>

                        <TextInput
                            id="email"
                            type="email"
                            className={`mt-1 block w-full bg-white dark:bg-slate-950 border focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs transition ${!isEmailValid && data.email
                                ? 'border-rose-500 focus:border-rose-500'
                                : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                }`}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            maxLength={120}
                            required
                            autoComplete="username"
                            placeholder="usuario@ejemplo.com"
                        />

                        {emailHasXss && (
                            <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span>Alerta de Ciberseguridad: Caracteres no permitidos en el correo.</span>
                            </p>
                        )}

                        {!emailHasXss && !isEmailValid && data.email && (
                            <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <span>Introduce un correo electrónico con formato válido (ej: nombre@dominio.com).</span>
                            </p>
                        )}

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
                        disabled={processing || isFormBlocked}
                        className={`px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-xs transition duration-200 flex items-center gap-2 ${isFormBlocked
                            ? 'bg-slate-400 dark:bg-slate-700 cursor-not-allowed opacity-60'
                            : 'bg-[#1534e8] hover:bg-blue-700 cursor-pointer'
                            }`}
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{processing ? 'Guardando con seguridad...' : 'Guardar Información'}</span>
                    </button>

                    {isFormBlocked && (
                        <span style={{ color: '#dc2626' }} className="text-xs text-red-600 font-bold flex items-center gap-1.5">
                            <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>Corrige los campos marcados para guardar los cambios.</span>
                        </span>
                    )}

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm font-medium text-emerald-600 dark:text-green-400 flex items-center gap-1">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span>Guardado correctamente con verificación de seguridad.</span>
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
