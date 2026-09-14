import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Show({ auth, profile, status, mustVerifyEmail }) {
    const [viewMode, setViewMode] = useState('view'); // 'view', 'edit_info', 'account'
    const [newSkill, setNewSkill] = useState('');
    const [skillsList, setSkillsList] = useState(profile.skills || []);
    const [flashStatus, setFlashStatus] = useState(status);

    useEffect(() => {
        if (status) {
            setFlashStatus(status);
            const timer = setTimeout(() => {
                setFlashStatus(null);
            }, 1000);
            return () => clearTimeout(timer);
        } else {
            setFlashStatus(null);
        }
    }, [status]);

    const profileForm = useForm({
        bio: profile.bio || '',
        phone: profile.phone || '',
        github_username: profile.github_username || '',
        linkedin_url: profile.linkedin_url || '',
        skills: profile.skills || [],
        education: profile.education || []
    });

    useEffect(() => {
        profileForm.setData('skills', skillsList);
    }, [skillsList]);

    const addSkill = () => {
        if (newSkill.trim() && !skillsList.includes(newSkill.trim())) {
            setSkillsList([...skillsList, newSkill.trim()]);
            setNewSkill('');
        }
    };

    const removeSkill = (skillToRemove) => {
        setSkillsList(skillsList.filter(s => s !== skillToRemove));
    };

    const handleProfileSubmit = (e) => {
        e.preventDefault();
        profileForm.post(route('profile.details'), {
            onSuccess: () => {
                setViewMode('view');
            }
        });
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-bold leading-tight text-slate-100">Mi Perfil Académico y Profesional</h2>}
        >
            <Head title="Mi Perfil" />

            <div className="py-12 bg-slate-950 min-h-screen text-slate-100">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">

                    {flashStatus && (
                        <div className="p-4 rounded-xl text-sm font-semibold flex items-center gap-3 transition-all duration-300 shadow-xs bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-300 dark:border-emerald-800/60">
                            <svg className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>{flashStatus}</span>
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        {/* Tarjeta de Información General e Imagen de Perfil */}
                        <div className="profile-card rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur flex flex-col justify-between h-fit space-y-6">
                            <div>
                                <div className="flex items-center space-x-4 mb-6">
                                    <div className="w-16 h-16 rounded-2xl bg-[#1534e8] dark:bg-gradient-to-tr dark:from-cyan-500 dark:to-indigo-600 flex items-center justify-center font-extrabold text-white text-2xl shadow-md">
                                        {auth.user.name[0]}
                                    </div>
                                    <div>
                                        <h3 className="text-xl font-bold text-slate-900 dark:text-white leading-tight">{auth.user.name}</h3>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">{auth.user.email}</p>
                                        <span className="inline-block mt-2 px-2.5 py-0.5 rounded-full bg-white text-blue-700 border border-blue-200 dark:bg-cyan-950/40 dark:border-cyan-800/30 dark:text-cyan-400 text-[10px] font-bold uppercase tracking-wider">
                                            Estudiante
                                        </span>
                                    </div>
                                </div>

                                <div className="space-y-4 border-t border-slate-200 dark:border-slate-850 pt-4 text-sm">
                                    {profile.phone && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-500 dark:text-slate-400 font-medium">Teléfono:</span>
                                            <span className="text-slate-800 dark:text-slate-200 font-mono">{profile.phone}</span>
                                        </div>
                                    )}
                                    {profile.github_username && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-500 dark:text-slate-400 font-medium">GitHub:</span>
                                            <a
                                                href={`https://github.com/${profile.github_username}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-blue-600 dark:text-cyan-400 hover:underline font-mono text-xs"
                                            >
                                                @{profile.github_username}
                                            </a>
                                        </div>
                                    )}
                                    {profile.linkedin_url && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-500 dark:text-slate-400 font-medium">LinkedIn:</span>
                                            <a
                                                href={profile.linkedin_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-blue-600 dark:text-cyan-400 hover:underline truncate max-w-[150px] text-xs"
                                            >
                                                Ver Perfil
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Fila de Acciones */}
                            <div className="flex flex-col gap-2.5 pt-4 border-t border-slate-200 dark:border-slate-850">
                                <button
                                    onClick={() => setViewMode(viewMode === 'edit_info' ? 'view' : 'edit_info')}
                                    className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-750 dark:border-slate-700/40 dark:text-slate-200 shadow-2xs transition cursor-pointer"
                                >
                                    Editar Información
                                </button>

                                <a
                                    href={route('profile.cv.generate')}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-750 dark:border-slate-700/40 dark:text-slate-200 shadow-2xs transition"
                                >
                                    Generar CV en PDF
                                </a>

                                <button
                                    onClick={() => setViewMode(viewMode === 'account' ? 'view' : 'account')}
                                    className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-750 dark:border-slate-700/40 dark:text-slate-200 shadow-2xs transition cursor-pointer"
                                >
                                    Configuración de Cuenta
                                </button>
                            </div>
                        </div>

                        {/* Contenido Principal: Ver Perfil, Editar Currículum o Configuración de Cuenta */}
                        <div className="profile-card lg:col-span-2 rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur min-h-[400px] flex flex-col justify-between">
                            {viewMode === 'view' && (
                                // Vista de Visualización del Perfil
                                <div className="space-y-6">
                                    <div>
                                        <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-2">Biografía Profesional</h3>
                                        <p className="text-slate-600 dark:text-slate-300 text-sm leading-relaxed whitespace-pre-line">
                                            {profile.bio || "No has definido tu biografía profesional todavía. Haz clic en 'Editar Información' para agregar detalles interesantes sobre ti."}
                                        </p>
                                    </div>

                                    <div className="border-t border-slate-200 dark:border-slate-850 pt-6">
                                        <h3 className="text-lg font-bold text-slate-900 dark:text-white mb-3">Habilidades y Tecnologías</h3>
                                        {skillsList.length > 0 ? (
                                            <div className="flex flex-wrap gap-2">
                                                {skillsList.map((skill, index) => (
                                                    <span
                                                        key={index}
                                                        className="px-3 py-1 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 dark:bg-cyan-950/30 dark:border-cyan-900/30 dark:text-cyan-400 text-xs font-semibold"
                                                    >
                                                        {skill}
                                                    </span>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-slate-500 text-sm italic">Ninguna habilidad agregada aún.</p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {viewMode === 'edit_info' && (
                                // Vista del Formulario de Edición de Currículum
                                <div>
                                    <div className="flex justify-between items-center mb-6 pb-4 border-b border-slate-200 dark:border-slate-800">
                                        <div>
                                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">Editar Información del Currículum</h3>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Actualiza tu biografía, enlaces y habilidades profesionales</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setViewMode('view')}
                                            className="px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-750 dark:border-slate-700 dark:text-slate-200 text-xs font-bold transition shadow-2xs flex items-center gap-1.5"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            Cancelar
                                        </button>
                                    </div>

                                    <form onSubmit={handleProfileSubmit} className="space-y-4">
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">Teléfono</label>
                                                <input
                                                    type="text"
                                                    value={profileForm.data.phone}
                                                    onChange={e => profileForm.setData('phone', e.target.value)}
                                                    className="w-full px-4 py-2.5 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 placeholder-slate-400 text-sm shadow-2xs"
                                                    placeholder="+593 99 999 9999"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">Usuario GitHub</label>
                                                <input
                                                    type="text"
                                                    value={profileForm.data.github_username}
                                                    onChange={e => profileForm.setData('github_username', e.target.value)}
                                                    className="w-full px-4 py-2.5 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 placeholder-slate-400 text-sm shadow-2xs"
                                                    placeholder="mi_usuario_github"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">URL LinkedIn</label>
                                            <input
                                                type="url"
                                                value={profileForm.data.linkedin_url}
                                                onChange={e => profileForm.setData('linkedin_url', e.target.value)}
                                                className="w-full px-4 py-2.5 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 placeholder-slate-400 text-sm shadow-2xs"
                                                placeholder="https://linkedin.com/in/tu-perfil"
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">Biografía Profesional</label>
                                            <textarea
                                                value={profileForm.data.bio}
                                                onChange={e => profileForm.setData('bio', e.target.value)}
                                                rows="5"
                                                className="w-full px-4 py-2.5 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 placeholder-slate-400 text-sm leading-relaxed shadow-2xs"
                                                placeholder="Escribe un resumen sobre tus intereses y habilidades profesionales..."
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider mb-2">Habilidades Técnicas</label>
                                            <div className="flex gap-2 mb-3">
                                                <input
                                                    type="text"
                                                    value={newSkill}
                                                    onChange={e => setNewSkill(e.target.value)}
                                                    onKeyDown={e => e.key === 'Enter' && (e.preventDefault(), addSkill())}
                                                    className="flex-1 px-4 py-2 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-sm shadow-2xs"
                                                    placeholder="Ingresa una habilidad (ej: React)..."
                                                />
                                                <button
                                                    type="button"
                                                    onClick={addSkill}
                                                    className="px-4 py-2 rounded-xl bg-[#1534e8] hover:bg-blue-700 text-white dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 text-sm font-bold shadow-xs transition"
                                                >
                                                    Agregar
                                                </button>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                {skillsList.map((skill, i) => (
                                                    <span
                                                        key={i}
                                                        className="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 dark:bg-cyan-950/20 dark:border-cyan-900/35 dark:text-cyan-400 text-xs font-medium"
                                                    >
                                                        {skill}
                                                        <button type="button" onClick={() => removeSkill(skill)} className="text-blue-500 hover:text-red-500 dark:text-cyan-600 dark:hover:text-cyan-400">✕</button>
                                                    </span>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="flex gap-3 pt-4 border-t border-slate-200 dark:border-slate-850">
                                            <button
                                                type="submit"
                                                disabled={profileForm.processing}
                                                className="px-5 py-2.5 rounded-xl text-sm font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition"
                                            >
                                                {profileForm.processing ? 'Guardando...' : 'Guardar Información'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setViewMode('view')}
                                                className="px-5 py-2.5 rounded-xl text-sm font-bold bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 dark:border-transparent transition shadow-2xs"
                                            >
                                                Cancelar
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            )}

                            {viewMode === 'account' && (
                                // Vista de Configuración de Cuenta Integrada
                                <div className="space-y-6">
                                    <div className="flex justify-between items-center pb-4 border-b border-slate-200 dark:border-slate-800">
                                        <div>
                                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">Configuración de la Cuenta</h3>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Administra tus credenciales de acceso, contraseña y seguridad</p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setViewMode('view')}
                                            className="px-3 py-1.5 rounded-xl bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-750 dark:border-slate-700 dark:text-slate-200 text-xs font-bold transition shadow-2xs flex items-center gap-1.5"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                            Cancelar
                                        </button>
                                    </div>

                                    {/* Bloque 1: Información de la Cuenta */}
                                    <div className="p-5 rounded-2xl bg-white dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800 shadow-2xs">
                                        <UpdateProfileInformationForm
                                            mustVerifyEmail={mustVerifyEmail}
                                            status={status}
                                            className="w-full"
                                        />
                                    </div>

                                    {/* Bloque 2: Actualizar Contraseña */}
                                    <div className="p-5 rounded-2xl bg-white dark:bg-slate-950/60 border border-slate-200 dark:border-slate-800 shadow-2xs">
                                        <UpdatePasswordForm className="w-full" />
                                    </div>

                                    {/* Bloque 3: Eliminar Cuenta */}
                                    <div className="p-5 rounded-2xl bg-white dark:bg-slate-950/60 border border-red-200/80 dark:border-red-900/40 shadow-2xs">
                                        <DeleteUserForm className="w-full" />
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
