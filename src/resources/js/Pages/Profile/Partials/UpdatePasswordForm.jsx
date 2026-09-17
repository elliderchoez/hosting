import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import { useRef, useState, useMemo } from 'react';

const XSS_PATTERN = /(<script\b[^>]*>|<\/?(iframe|object|embed|applet|meta|link|style)\b|javascript\s*:|data\s*:\s*text\/html|on(load|error|click|mouseover|submit|focus|keydown)\s*=|(\bunion\s+select\b|\bdrop\s+table\b))/i;

export default function UpdatePasswordForm({ className = '' }) {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    // Validaciones de Ciberseguridad para Contraseña
    const hasMinLength = (data.password?.length || 0) >= 8;
    const hasUppercase = /[A-Z]/.test(data.password || '');
    const hasLowercase = /[a-z]/.test(data.password || '');
    const hasNumber = /[0-9]/.test(data.password || '');
    const hasSpecial = /[!@#$%^&*()_+\-=\[\]{}|;:,.<>?~`]/.test(data.password || '');

    const strengthScore = useMemo(() => {
        let score = 0;
        if (hasMinLength) score++;
        if (hasUppercase) score++;
        if (hasLowercase) score++;
        if (hasNumber) score++;
        if (hasSpecial) score++;
        return score;
    }, [hasMinLength, hasUppercase, hasLowercase, hasNumber, hasSpecial]);

    const isPasswordStrong = strengthScore >= 4 && hasMinLength;
    const passwordsMatch = Boolean(data.password && data.password_confirmation && data.password === data.password_confirmation);
    const hasXss = XSS_PATTERN.test(data.current_password || '') || XSS_PATTERN.test(data.password || '') || XSS_PATTERN.test(data.password_confirmation || '');

    const isBlocked = !data.current_password || !isPasswordStrong || !passwordsMatch || hasXss;

    const updatePassword = (e) => {
        e.preventDefault();
        if (isBlocked) return;

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                        Actualizar Contraseña
                    </h2>

                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Asegúrate de que tu cuenta esté utilizando una contraseña segura y compleja para mantener su confidencialidad.
                    </p>
                </div>


            </header>

            <form onSubmit={updatePassword} className="mt-5 space-y-4">
                {/* Contraseña Actual */}
                <div>
                    <InputLabel
                        htmlFor="current_password"
                        value="Contraseña Actual"
                        className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5"
                    />

                    <div className="relative mt-1">
                        <TextInput
                            id="current_password"
                            ref={currentPasswordInput}
                            value={data.current_password}
                            onChange={(e) =>
                                setData('current_password', e.target.value)
                            }
                            type={showCurrentPassword ? "text" : "password"}
                            className="block w-full pr-10 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs font-mono"
                            autoComplete="current-password"
                            placeholder="Introduce tu contraseña actual"
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                            className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none"
                            title={showCurrentPassword ? "Ocultar" : "Mostrar"}
                        >
                            {showCurrentPassword ? (
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            ) : (
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            )}
                        </button>
                    </div>

                    <InputError
                        message={errors.current_password}
                        className="mt-2"
                    />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    {/* Nueva Contraseña */}
                    <div>
                        <InputLabel htmlFor="password" value="Nueva Contraseña" className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5" />

                        <div className="relative mt-1">
                            <TextInput
                                id="password"
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                type={showPassword ? "text" : "password"}
                                className={`block w-full pr-10 bg-white dark:bg-slate-950 border focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs font-mono transition ${data.password && !isPasswordStrong
                                    ? 'border-rose-500 focus:border-rose-500'
                                    : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                    }`}
                                autoComplete="new-password"
                                placeholder="Mínimo 8 caracteres"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none"
                                title={showPassword ? "Ocultar" : "Mostrar"}
                            >
                                {showPassword ? (
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                ) : (
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                )}
                            </button>
                        </div>

                        {/* Indicador de Fortaleza de Contraseña */}
                        {data.password && (
                            <div className="mt-2 space-y-1">
                                <div className="flex items-center justify-between text-[11px] font-semibold">
                                    <span className="text-slate-500 dark:text-slate-400">Seguridad:</span>
                                    <span className={strengthScore <= 2 ? 'text-red-600 font-bold' : strengthScore <= 4 ? 'text-amber-500 font-bold' : 'text-emerald-600 font-bold'}>
                                        {strengthScore <= 2 ? 'Débil' : strengthScore <= 4 ? 'Moderada' : 'Fuerte'}
                                    </span>
                                </div>
                                <div className="w-full h-1.5 bg-slate-200 dark:bg-slate-800 rounded-full overflow-hidden">
                                    <div
                                        className={`h-full transition-all duration-300 ${strengthScore <= 2
                                            ? 'w-1/3 bg-red-500'
                                            : strengthScore <= 4
                                                ? 'w-2/3 bg-amber-500'
                                                : 'w-full bg-emerald-500'
                                            }`}
                                    />
                                </div>
                            </div>
                        )}

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    {/* Confirmar Nueva Contraseña */}
                    <div>
                        <InputLabel
                            htmlFor="password_confirmation"
                            value="Confirmar Nueva Contraseña"
                            className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5"
                        />

                        <div className="relative mt-1">
                            <TextInput
                                id="password_confirmation"
                                value={data.password_confirmation}
                                onChange={(e) =>
                                    setData('password_confirmation', e.target.value)
                                }
                                type={showConfirmPassword ? "text" : "password"}
                                className={`block w-full pr-10 bg-white dark:bg-slate-950 border focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 shadow-2xs font-mono transition ${data.password_confirmation && !passwordsMatch
                                    ? 'border-rose-500 focus:border-rose-500'
                                    : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                    }`}
                                autoComplete="new-password"
                                placeholder="Repite tu nueva contraseña"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none"
                                title={showConfirmPassword ? "Ocultar" : "Mostrar"}
                            >
                                {showConfirmPassword ? (
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                ) : (
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                )}
                            </button>
                        </div>

                        {/* Indicador de coincidencia */}
                        {data.password_confirmation && (
                            <div className="mt-2">
                                {passwordsMatch ? (
                                    <span style={{ color: '#16a34a' }} className="text-emerald-600 text-xs font-semibold flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <span>Las contraseñas coinciden.</span>
                                    </span>
                                ) : (
                                    <span style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>Las contraseñas no coinciden.</span>
                                    </span>
                                )}
                            </div>
                        )}

                        <InputError
                            message={errors.password_confirmation}
                            className="mt-2"
                        />
                    </div>
                </div>

                {/* Requisitos de Ciberseguridad */}
                <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800 text-xs space-y-1.5">
                    <p className="font-bold text-slate-700 dark:text-slate-300 text-[11px] uppercase tracking-wider mb-1 flex items-center gap-1.5">
                        <svg className="w-3.5 h-3.5 text-blue-600 dark:text-cyan-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        </svg>
                        <span>Criterios:</span>
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        <div className={`flex items-center gap-1.5 text-[11px] font-medium ${hasMinLength ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                            <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={hasMinLength ? "M5 13l4 4L19 7" : "M6 18L18 6M6 6l12 12"} />
                            </svg>
                            <span>Mínimo 8 caracteres</span>
                        </div>
                        <div className={`flex items-center gap-1.5 text-[11px] font-medium ${(hasUppercase && hasLowercase) ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                            <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={(hasUppercase && hasLowercase) ? "M5 13l4 4L19 7" : "M6 18L18 6M6 6l12 12"} />
                            </svg>
                            <span>Mayúsculas y minúsculas</span>
                        </div>
                        <div className={`flex items-center gap-1.5 text-[11px] font-medium ${hasNumber ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                            <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={hasNumber ? "M5 13l4 4L19 7" : "M6 18L18 6M6 6l12 12"} />
                            </svg>
                            <span>Al menos un número (0-9)</span>
                        </div>
                        <div className={`flex items-center gap-1.5 text-[11px] font-medium ${hasSpecial ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'}`}>
                            <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={hasSpecial ? "M5 13l4 4L19 7" : "M6 18L18 6M6 6l12 12"} />
                            </svg>
                            <span>Un carácter especial (!@#$...)</span>
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-4 pt-2">
                    <button
                        type="submit"
                        disabled={processing || isBlocked}
                        className={`px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-xs transition duration-200 flex items-center gap-2 ${isBlocked
                            ? 'bg-slate-400 dark:bg-slate-700 cursor-not-allowed opacity-60'
                            : 'bg-[#1534e8] hover:bg-blue-700 cursor-pointer'
                            }`}
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span>{processing ? 'Actualizando...' : 'Actualizar Contraseña'}</span>
                    </button>

                    {isBlocked && data.password && (
                        <span style={{ color: '#dc2626' }} className="text-xs text-red-600 font-bold flex items-center gap-1.5">
                            <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span>Cumple los requisitos para guardar los cambios.</span>
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
                            <span>Contraseña actualizada correctamente.</span>
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
