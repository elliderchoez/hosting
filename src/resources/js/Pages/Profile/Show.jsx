import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Show({ auth, profile, status }) {
    const [isEditing, setIsEditing] = useState(false);
    const [newSkill, setNewSkill] = useState('');
    const [skillsList, setSkillsList] = useState(profile.skills || []);

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
                setIsEditing(false);
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
                    
                    {status && (
                        <div className="p-4 bg-emerald-950/30 border border-emerald-900/40 text-emerald-400 rounded-xl text-sm font-semibold">
                            {status}
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        {/* Tarjeta de Información General e Imagen de Perfil */}
                        <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur flex flex-col justify-between h-fit space-y-6">
                            <div>
                                <div className="flex items-center space-x-4 mb-6">
                                    <div className="w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500 to-indigo-600 flex items-center justify-center font-extrabold text-white text-2xl shadow-lg shadow-cyan-500/20">
                                        {auth.user.name[0]}
                                    </div>
                                    <div>
                                        <h3 className="text-xl font-bold text-white leading-tight">{auth.user.name}</h3>
                                        <p className="text-xs text-slate-400">{auth.user.email}</p>
                                        <span className="inline-block mt-2 px-2.5 py-0.5 rounded-full bg-cyan-950/40 border border-cyan-800/30 text-cyan-400 text-[10px] font-bold uppercase tracking-wider">
                                            Estudiante
                                        </span>
                                    </div>
                                </div>

                                <div className="space-y-4 border-t border-slate-850 pt-4 text-sm">
                                    {profile.phone && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-400 font-medium">Teléfono:</span>
                                            <span className="text-slate-200 font-mono">{profile.phone}</span>
                                        </div>
                                    )}
                                    {profile.github_username && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-400 font-medium">GitHub:</span>
                                            <a 
                                                href={`https://github.com/${profile.github_username}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-cyan-400 hover:underline font-mono text-xs"
                                            >
                                                @{profile.github_username}
                                            </a>
                                        </div>
                                    )}
                                    {profile.linkedin_url && (
                                        <div className="flex items-center justify-between">
                                            <span className="text-slate-400 font-medium">LinkedIn:</span>
                                            <a 
                                                href={profile.linkedin_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-cyan-400 hover:underline truncate max-w-[150px] text-xs"
                                            >
                                                Ver Perfil
                                            </a>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Fila de Acciones */}
                            <div className="flex flex-col gap-2 pt-4 border-t border-slate-850">
                                {!isEditing && (
                                    <button
                                        onClick={() => setIsEditing(true)}
                                        className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-cyan-500 hover:bg-cyan-400 text-slate-950 shadow-md shadow-cyan-500/10 transition"
                                    >
                                        Editar Información
                                    </button>
                                )}
                                <a
                                    href={route('profile.cv.generate')}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-slate-800 hover:bg-slate-750 border border-slate-700/40 text-slate-200 transition"
                                >
                                    Generar CV en PDF
                                </a>
                                <a
                                    href={route('profile.edit')}
                                    className="w-full py-2.5 rounded-xl text-center text-sm font-bold bg-slate-900 hover:bg-slate-850 border border-slate-800 text-slate-400 hover:text-white transition"
                                >
                                    Configuración de Cuenta
                                </a>
                            </div>
                        </div>

                        {/* Contenido Principal: Ver Perfil o Editar Perfil */}
                        <div className="lg:col-span-2 rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur min-h-[400px] flex flex-col justify-between">
                            {!isEditing ? (
                                // Vista de Visualización del Perfil
                                <div className="space-y-6">
                                    <div>
                                        <h3 className="text-lg font-bold text-white mb-2">Biografía Profesional</h3>
                                        <p className="text-slate-300 text-sm leading-relaxed whitespace-pre-line">
                                            {profile.bio || "No has definido tu biografía profesional todavía. Haz clic en 'Editar Información' para agregar detalles interesantes sobre ti."}
                                        </p>
                                    </div>

                                    <div className="border-t border-slate-850 pt-6">
                                        <h3 className="text-lg font-bold text-white mb-3">Habilidades y Tecnologías</h3>
                                        {skillsList.length > 0 ? (
                                            <div className="flex flex-wrap gap-2">
                                                {skillsList.map((skill, index) => (
                                                    <span 
                                                        key={index} 
                                                        className="px-3 py-1 rounded-xl bg-cyan-950/30 border border-cyan-900/30 text-cyan-400 text-xs font-semibold"
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
                            ) : (
                                // Vista del Formulario de Edición
                                <div>
                                    <div className="flex justify-between items-center mb-6">
                                        <h3 className="text-lg font-bold text-white">Editar Información del Currículum</h3>
                                        <button 
                                            type="button" 
                                            onClick={() => setIsEditing(false)}
                                            className="text-xs text-slate-400 hover:text-white transition"
                                        >
                                            Cancelar
                                        </button>
                                    </div>
                                    
                                    <form onSubmit={handleProfileSubmit} className="space-y-4">
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Teléfono</label>
                                                <input
                                                    type="text"
                                                    value={profileForm.data.phone}
                                                    onChange={e => profileForm.setData('phone', e.target.value)}
                                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                                    placeholder="+593 99 999 9999"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Usuario GitHub</label>
                                                <input
                                                    type="text"
                                                    value={profileForm.data.github_username}
                                                    onChange={e => profileForm.setData('github_username', e.target.value)}
                                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                                    placeholder="mi_usuario_github"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">URL LinkedIn</label>
                                            <input
                                                type="url"
                                                value={profileForm.data.linkedin_url}
                                                onChange={e => profileForm.setData('linkedin_url', e.target.value)}
                                                className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                                placeholder="https://linkedin.com/in/tu-perfil"
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Biografía Profesional</label>
                                            <textarea
                                                value={profileForm.data.bio}
                                                onChange={e => profileForm.setData('bio', e.target.value)}
                                                rows="5"
                                                className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm leading-relaxed"
                                                placeholder="Escribe un resumen sobre tus intereses y habilidades profesionales..."
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Habilidades Técnicas</label>
                                            <div className="flex gap-2 mb-3">
                                                <input
                                                    type="text"
                                                    value={newSkill}
                                                    onChange={e => setNewSkill(e.target.value)}
                                                    onKeyDown={e => e.key === 'Enter' && (e.preventDefault(), addSkill())}
                                                    className="flex-1 px-4 py-2 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 text-sm"
                                                    placeholder="Ingresa una habilidad (ej: React)..."
                                                />
                                                <button
                                                    type="button"
                                                    onClick={addSkill}
                                                    className="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-750 text-slate-200 text-sm font-semibold border border-slate-750"
                                                >
                                                    Agregar
                                                </button>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                {skillsList.map((skill, i) => (
                                                    <span 
                                                        key={i} 
                                                        className="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-cyan-950/20 border border-cyan-900/35 text-cyan-400 text-xs font-medium"
                                                    >
                                                        {skill}
                                                        <button type="button" onClick={() => removeSkill(skill)} className="text-cyan-600 hover:text-cyan-400">✕</button>
                                                    </span>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="flex gap-3 pt-4 border-t border-slate-850">
                                            <button
                                                type="submit"
                                                disabled={profileForm.processing}
                                                className="px-5 py-2.5 rounded-xl text-sm font-bold bg-cyan-500 hover:bg-cyan-400 text-slate-950 shadow-md shadow-cyan-500/10 transition"
                                            >
                                                {profileForm.processing ? 'Guardando...' : 'Guardar Información'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setIsEditing(false)}
                                                className="px-5 py-2.5 rounded-xl text-sm font-bold bg-slate-800 hover:bg-slate-750 text-slate-200 transition"
                                            >
                                                Cancelar
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
