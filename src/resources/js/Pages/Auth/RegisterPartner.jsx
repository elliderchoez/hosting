import { useState } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function RegisterPartner() {
    const [accountType, setAccountType] = useState('company'); // 'company' | 'recruiter'
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        account_type: 'company',
        name: '',
        email: '',
        company: '',
        position: '',
        tax_id: '',
        website_url: '',
        linkedin_url: '',
        phone: '',
        password: '',
        password_confirmation: '',
    });

    const handleSwitchType = (type) => {
        setAccountType(type);
        setData((prev) => ({
            ...prev,
            account_type: type,
        }));
    };

    // Validations helper
    const hasMinLength = data.password.length >= 8;
    const hasUppercase = /[A-Z]/.test(data.password);
    const hasLowercase = /[a-z]/.test(data.password);
    const hasNumber = /[0-9]/.test(data.password);
    const hasSpecial = /[@$!%*?&._-]/.test(data.password);

    const isPasswordValid = hasMinLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;
    const passwordsMatch = data.password === data.password_confirmation && data.password_confirmation.length > 0;

    const isTaxIdValid = /^[0-9]{10,13}$/.test(data.tax_id);
    const isNameValid = data.name.trim().length >= 3 && data.name.trim().length <= 25 && !/[0-9]/.test(data.name);
    const isPositionValid = data.position.trim().length >= 2 && data.position.trim().length <= 25 && !/[0-9]/.test(data.position);
    const isPhoneValid = /^[0-9+ \-]{7,15}$/.test(data.phone.trim());

    const isCommonValid =
        isNameValid &&
        data.email.trim() !== '' &&
        isPhoneValid &&
        isTaxIdValid &&
        isPasswordValid &&
        passwordsMatch;

    const isCompanyValid =
        isCommonValid &&
        data.company.trim() !== '' &&
        data.company.trim().length <= 25 &&
        isPositionValid &&
        data.website_url.trim() !== '';

    const isRecruiterValid =
        isCommonValid &&
        data.company.trim() !== '' &&
        data.company.trim().length <= 25 &&
        isPositionValid &&
        data.linkedin_url.trim() !== '';

    const isFormValid = accountType === 'company' ? isCompanyValid : isRecruiterValid;

    const submit = (e) => {
        e.preventDefault();

        if (data.tax_id.length < 10 || data.tax_id.length > 13) {
            alert('El número de identificación (RUC/Cédula) debe tener entre 10 y 13 dígitos.');
            return;
        }

        if (data.company.trim().length > 25) {
            alert('El nombre de la empresa no debe superar los 25 caracteres.');
            return;
        }

        if (data.name.trim().length > 25) {
            alert('El nombre no debe superar los 25 caracteres.');
            return;
        }

        if (data.position.trim().length > 25) {
            alert('El cargo no debe superar los 25 caracteres.');
            return;
        }

        if (/[0-9]/.test(data.name)) {
            alert('El nombre no debe contener números, únicamente letras.');
            return;
        }

        if (/[0-9]/.test(data.position)) {
            alert('El cargo no debe contener números, únicamente letras.');
            return;
        }

        if (!isPasswordValid) {
            alert('Por favor asegúrate de cumplir con todos los requerimientos de la contraseña.');
            return;
        }

        if (!passwordsMatch) {
            alert('Las contraseñas no coinciden.');
            return;
        }

        post(route('register.partner.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout maxWidth="sm:max-w-xl md:max-w-2xl">
            <Head title="Registro de Empresas y Reclutadores" />
            <div className="mb-6 text-center">
                <h2 className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-slate-100 tracking-tight">
                    Registro de Reclutadores/Empresas
                </h2>
            </div>

            {/* Aviso de verificación institucional - Mismo color que el fondo del formulario y sin emojis */}
            <div className="mb-6 p-4 rounded-xl bg-slate-50/80 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-300 leading-relaxed shadow-xs">
                <div className="flex gap-3 items-start">
                    <svg className="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    <div>
                        <strong className="text-slate-800 dark:text-slate-200">Verificación de Seguridad:</strong> Por políticas institucionales de la ULEAM, todas las solicitudes de empresas y reclutadores son revisadas por la administración antes de su activación.
                    </div>
                </div>
            </div>

            {/* Selector de Tipo de Cuenta - Sin emojis, con iconos SVG limpios */}
            <div className="mb-6">
                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-2">
                    Selecciona tu perfil de acceso:
                </label>
                <div className="grid grid-cols-2 gap-2 p-1.5 bg-slate-100 dark:bg-slate-950/80 rounded-xl border border-slate-200 dark:border-slate-800">
                    <button
                        type="button"
                        onClick={() => handleSwitchType('company')}
                        className={`py-2.5 px-3 rounded-lg text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer ${accountType === 'company'
                            ? 'bg-white dark:bg-blue-600 text-blue-600 dark:text-white shadow-xs'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                    >
                        <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <span>Empresa</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => handleSwitchType('recruiter')}
                        className={`py-2.5 px-3 rounded-lg text-xs font-bold transition flex items-center justify-center gap-2 cursor-pointer ${accountType === 'recruiter'
                            ? 'bg-white dark:bg-blue-600 text-blue-600 dark:text-white shadow-xs'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                    >
                        <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        <span>Reclutador</span>
                    </button>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                {/* CAMPOS ESPECÍFICOS DE EMPRESA */}
                {accountType === 'company' && (
                    <>
                        <div>
                            <InputLabel htmlFor="company" value="Nombre Comercial o Razón Social *" />
                            <TextInput
                                id="company"
                                name="company"
                                maxLength={25}
                                value={data.company}
                                className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                onChange={(e) => setData('company', e.target.value.slice(0, 25))}
                                required
                                placeholder="Ej: Tech Solutions Corp"
                            />
                            <InputError message={errors.company} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="tax_id" value="RUC de la Empresa *" />
                                <TextInput
                                    id="tax_id"
                                    name="tax_id"
                                    inputMode="numeric"
                                    maxLength={13}
                                    value={data.tax_id}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm font-mono"
                                    onChange={(e) => setData('tax_id', e.target.value.replace(/\D/g, '').slice(0, 13))}
                                    required
                                    placeholder="Ej: 1391748291001"
                                />
                                <div className="text-[10px] text-slate-400 mt-1 text-right">
                                    {data.tax_id.length}/13
                                </div>
                                <InputError message={errors.tax_id} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="website_url" value="Sitio Web Oficial *" />
                                <TextInput
                                    id="website_url"
                                    type="url"
                                    name="website_url"
                                    value={data.website_url}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('website_url', e.target.value)}
                                    required
                                    placeholder="https://empresa.com"
                                />
                                <InputError message={errors.website_url} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="name" value="Representante / Contacto *" />
                                <TextInput
                                    id="name"
                                    name="name"
                                    maxLength={25}
                                    value={data.name}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('name', e.target.value.replace(/[0-9]/g, '').slice(0, 25))}
                                    required
                                    placeholder="Ej: Ing. Carlos Mendoza"
                                />
                                <InputError message={errors.name} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="position" value="Cargo en la Empresa *" />
                                <TextInput
                                    id="position"
                                    name="position"
                                    maxLength={25}
                                    value={data.position}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('position', e.target.value.replace(/[0-9]/g, '').slice(0, 25))}
                                    required
                                    placeholder="Ej: Gerente de TI"
                                />
                                <InputError message={errors.position} className="mt-1" />
                            </div>
                        </div>
                    </>
                )}

                {/* CAMPOS ESPECÍFICOS DE RECLUTADOR / HEADHUNTER */}
                {accountType === 'recruiter' && (
                    <>
                        <div>
                            <InputLabel htmlFor="name" value="Nombre Completo del Reclutador *" />
                            <TextInput
                                id="name"
                                name="name"
                                maxLength={25}
                                value={data.name}
                                className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                onChange={(e) => setData('name', e.target.value.replace(/[0-9]/g, '').slice(0, 25))}
                                required
                                placeholder="Ej: Andrea Gómez Palacios"
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="tax_id" value="Cédula de Identidad o RUC *" />
                                <TextInput
                                    id="tax_id"
                                    name="tax_id"
                                    inputMode="numeric"
                                    maxLength={13}
                                    value={data.tax_id}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm font-mono"
                                    onChange={(e) => setData('tax_id', e.target.value.replace(/\D/g, '').slice(0, 13))}
                                    required
                                    placeholder="Ej: 1314567890"
                                />
                                <div className="text-[10px] text-slate-400 mt-1 text-right">
                                    {data.tax_id.length}/13
                                </div>
                                <InputError message={errors.tax_id} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="position" value="Cargo / Rol de Selección *" />
                                <TextInput
                                    id="position"
                                    name="position"
                                    maxLength={25}
                                    value={data.position}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('position', e.target.value.replace(/[0-9]/g, '').slice(0, 25))}
                                    required
                                    placeholder="Ej: Tech Recruiter"
                                />
                                <InputError message={errors.position} className="mt-1" />
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel htmlFor="company" value="Agencia, Consultora o 'Independiente' *" />
                                <TextInput
                                    id="company"
                                    name="company"
                                    maxLength={25}
                                    value={data.company}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('company', e.target.value.slice(0, 25))}
                                    required
                                    placeholder="Ej: Talent Hunt"
                                />
                                <InputError message={errors.company} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="linkedin_url" value="Perfil de LinkedIn Profesional *" />
                                <TextInput
                                    id="linkedin_url"
                                    type="url"
                                    name="linkedin_url"
                                    value={data.linkedin_url}
                                    className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                                    onChange={(e) => setData('linkedin_url', e.target.value)}
                                    required
                                    placeholder="https://www.linkedin.com/in/tu-perfil"
                                />
                                <InputError message={errors.linkedin_url} className="mt-1" />
                            </div>
                        </div>
                    </>
                )}

                {/* CAMPOS COMUNES DE CONTACTO Y ACCESO */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                    <div>
                        <InputLabel htmlFor="email" value={accountType === 'company' ? 'Correo Corporativo *' : 'Correo Profesional *'} />
                        <TextInput
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            placeholder={accountType === 'company' ? 'contacto@empresa.com' : 'reclutador@profesional.com'}
                        />
                        <InputError message={errors.email} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="phone" value="Teléfono / WhatsApp *" />
                        <TextInput
                            id="phone"
                            name="phone"
                            inputMode="tel"
                            maxLength={15}
                            value={data.phone}
                            className="mt-1 block w-full bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                            onChange={(e) => setData('phone', e.target.value.replace(/[^\d+ ]/g, '').slice(0, 15))}
                            required
                            placeholder="Ej: 0989630531"
                        />
                        <InputError message={errors.phone} className="mt-1" />
                    </div>
                </div>

                {/* CONTRASEÑA CON OJITO SVG */}
                <div>
                    <InputLabel htmlFor="password" value="Contraseña*" />
                    <div className="relative mt-1">
                        <TextInput
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="block w-full pr-10 bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                            placeholder="Crea una contraseña segura"
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword(!showPassword)}
                            className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none cursor-pointer"
                            title={showPassword ? 'Ocultar contraseña' : 'Ver contraseña'}
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

                    {/* Requisitos visuales sin emojis */}
                    <div className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-2 gap-y-1">
                            <span className={hasMinLength ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''}>
                                {hasMinLength ? '✓' : '•'} Mínimo 8 caracteres
                            </span>
                            <span className={hasUppercase ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''}>
                                {hasUppercase ? '✓' : '•'} Una mayúscula (A-Z)
                            </span>
                            <span className={hasLowercase ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''}>
                                {hasLowercase ? '✓' : '•'} Una minúscula (a-z)
                            </span>
                            <span className={hasNumber ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''}>
                                {hasNumber ? '✓' : '•'} Al menos un número
                            </span>
                            <span className={hasSpecial ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : ''}>
                                {hasSpecial ? '✓' : '•'} Un carácter especial
                            </span>
                        </div>
                    </div>
                    <InputError message={errors.password} className="mt-1" />
                </div>

                {/* CONFIRMAR CONTRASEÑA CON OJITO SVG */}
                <div>
                    <InputLabel htmlFor="password_confirmation" value="Confirmar Contraseña *" />
                    <div className="relative mt-1">
                        <TextInput
                            id="password_confirmation"
                            type={showConfirmPassword ? 'text' : 'password'}
                            name="password_confirmation"
                            value={data.password_confirmation}
                            className="block w-full pr-10 bg-white dark:bg-slate-950 border-slate-300 dark:border-slate-800 text-slate-900 dark:text-slate-200 text-sm"
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            required
                            placeholder="Repite la contraseña"
                        />
                        <button
                            type="button"
                            onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                            className="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none cursor-pointer"
                            title={showConfirmPassword ? 'Ocultar contraseña' : 'Ver contraseña'}
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
                        <div className="mt-1.5 text-xs">
                            {passwordsMatch ? (
                                <span className="text-emerald-600 dark:text-emerald-400 font-medium">✓ Las contraseñas coinciden</span>
                            ) : (
                                <span className="text-rose-600 dark:text-rose-400 font-medium">✕ Las contraseñas no coinciden</span>
                            )}
                        </div>
                    )}
                    <InputError message={errors.password_confirmation} className="mt-1" />
                </div>

                {/* BOTÓN PRINCIPAL CENTRADO */}
                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing || !isFormValid}
                        className={`w-full py-3 px-4 rounded-xl text-sm font-bold transition flex items-center justify-center cursor-pointer ${isFormValid && !processing
                            ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-md shadow-blue-600/20'
                            : 'bg-slate-200 dark:bg-slate-800 text-slate-400 cursor-not-allowed'
                            }`}
                    >
                        <span>{processing ? 'Enviando Solicitud...' : 'Enviar Solicitud de Verificación'}</span>
                    </button>
                </div>

                {/* ENLACES DE PIE DE PÁGINA LIMPIOS Y BIEN DISTRIBUIDOS */}
                <div className="pt-4 mt-2 border-t border-slate-200 dark:border-slate-800/80 space-y-2 text-center text-xs text-slate-500 dark:text-slate-400">
                    <div>
                        ¿Ya tienes una cuenta aprobada?{' '}
                        <Link
                            href={route('login')}
                            className="font-bold text-blue-600 dark:text-blue-400 hover:underline"
                        >
                            Iniciar sesión
                        </Link>
                    </div>

                    <div className="text-[11px] text-slate-400 dark:text-slate-500">
                        ¿Eres estudiante de la universidad?{' '}
                        <Link
                            href={route('register')}
                            className="font-semibold text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 underline"
                        >
                            Registro de Estudiantes ULEAM
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
