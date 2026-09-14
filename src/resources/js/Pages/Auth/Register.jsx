import { useState } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    // Validations helper
    const hasMinLength = data.password.length >= 8;
    const hasUppercase = /[A-Z]/.test(data.password);
    const hasLowercase = /[a-z]/.test(data.password);
    const hasNumber = /[0-9]/.test(data.password);
    const hasSpecial = /[@$!%*?&._-]/.test(data.password);
    
    const isPasswordValid = hasMinLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;
    const passwordsMatch = data.password === data.password_confirmation && data.password_confirmation.length > 0;
    
    const isFormValid = data.name.trim() !== '' && data.email.trim() !== '' && isPasswordValid && passwordsMatch;

    const submit = (e) => {
        e.preventDefault();

        if (!isPasswordValid) {
            alert('Por favor asegúrate de cumplir con todos los requerimientos de la contraseña.');
            return;
        }

        if (!passwordsMatch) {
            alert('Las contraseñas no coinciden.');
            return;
        }

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Registro de Estudiantes" />

            <div className="mb-6 text-center">
                <h2 className="text-2xl font-bold text-slate-100">Crear una cuenta</h2>
                <p className="text-xs text-slate-400 mt-1">Registra tu usuario para Nexus Academic</p>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="name" value="Nombre Completo" />

                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1 block w-full bg-slate-950 border-slate-900 focus:border-cyan-500 focus:ring-0 text-slate-200"
                        autoComplete="name"
                        isFocused={true}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        placeholder="Ej: Juan Pérez Choez"
                    />

                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Correo Institucional" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full bg-slate-950 border-slate-900 focus:border-cyan-500 focus:ring-0 text-slate-200"
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        placeholder="ejemplo@uleam.edu.ec"
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="Contraseña" />

                    <div className="relative mt-1">
                        <TextInput
                            id="password"
                            type={showPassword ? "text" : "password"}
                            name="password"
                            value={data.password}
                            className="block w-full pr-10 bg-slate-950 border-slate-900 focus:border-cyan-500 focus:ring-0 text-slate-200"
                            autoComplete="new-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            placeholder="Mínimo 8 caracteres"
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword(!showPassword)}
                            className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-200 focus:outline-none"
                        >
                            {showPassword ? (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            )}
                        </button>
                    </div>
                    
                    {/* Password requirements hint */}
                    <div className="mt-2 text-[11px] bg-slate-900/40 p-2.5 rounded-lg border border-slate-900 space-y-1">
                        <p className="text-slate-400 font-semibold">Criterios de seguridad para la contraseña:</p>
                        <ul className="grid grid-cols-1 md:grid-cols-2 gap-1 pl-1">
                            <li className={`flex items-center gap-1.5 transition-colors ${hasMinLength ? "text-green-400" : "text-slate-500"}`}>
                                <span>{hasMinLength ? "✓" : "•"}</span>
                                <span>Mínimo 8 caracteres</span>
                            </li>
                            <li className={`flex items-center gap-1.5 transition-colors ${hasUppercase ? "text-green-400" : "text-slate-500"}`}>
                                <span>{hasUppercase ? "✓" : "•"}</span>
                                <span>Una letra mayúscula (A-Z)</span>
                            </li>
                            <li className={`flex items-center gap-1.5 transition-colors ${hasLowercase ? "text-green-400" : "text-slate-500"}`}>
                                <span>{hasLowercase ? "✓" : "•"}</span>
                                <span>Una letra minúscula (a-z)</span>
                            </li>
                            <li className={`flex items-center gap-1.5 transition-colors ${hasNumber ? "text-green-400" : "text-slate-500"}`}>
                                <span>{hasNumber ? "✓" : "•"}</span>
                                <span>Al menos un número (0-9)</span>
                            </li>
                            <li className={`flex items-center gap-1.5 transition-colors ${hasSpecial ? "text-green-400" : "text-slate-500"}`}>
                                <span>{hasSpecial ? "✓" : "•"}</span>
                                <span>Un carácter especial</span>
                            </li>
                        </ul>
                    </div>

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor="password_confirmation"
                        value="Confirmar Contraseña"
                    />

                    <div className="relative mt-1">
                        <TextInput
                            id="password_confirmation"
                            type={showConfirmPassword ? "text" : "password"}
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="block w-full pr-10 bg-slate-950 border-slate-900 focus:border-cyan-500 focus:ring-0 text-slate-200"
                            autoComplete="new-password"
                            onChange={(e) =>
                                setData('password_confirmation', e.target.value)
                            }
                            required
                            placeholder="Repite tu contraseña"
                        />
                        <button
                            type="button"
                            onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                            className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-200 focus:outline-none"
                        >
                            {showConfirmPassword ? (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            ) : (
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            )}
                        </button>
                    </div>

                    {data.password_confirmation.length > 0 && (
                        <div className="mt-1 text-[11px]">
                            {passwordsMatch ? (
                                <span className="text-green-400 flex items-center gap-1">✓ Las contraseñas coinciden</span>
                            ) : (
                                <span className="text-red-400 flex items-center gap-1">✕ Las contraseñas no coinciden</span>
                            )}
                        </div>
                    )}

                    <InputError
                        message={errors.password_confirmation}
                        className="mt-2"
                    />
                </div>

                <div className="mt-6 flex items-center justify-between">
                    <Link
                        href={route('login')}
                        className="text-xs text-slate-400 underline hover:text-slate-200 focus:outline-none"
                    >
                        ¿Ya tienes una cuenta registrada?
                    </Link>

                    <button 
                        type="submit"
                        disabled={processing || !isFormValid}
                        className={`px-5 py-2.5 rounded-xl text-sm font-bold border-none transition duration-200 ${
                            isFormValid && !processing
                                ? "bg-gradient-to-r from-cyan-500 to-indigo-500 hover:from-cyan-400 hover:to-indigo-400 text-slate-950 cursor-pointer shadow-lg shadow-cyan-500/10"
                                : "bg-slate-800 text-slate-500 cursor-not-allowed"
                        }`}
                    >
                        Registrarse
                    </button>
                </div>
            </form>
        </GuestLayout>
    );
}
