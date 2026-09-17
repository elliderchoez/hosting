import { useState, useEffect, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

// =========================================================================
// CATÁLOGO OFICIAL Y LISTA BLANCA DE HABILIDADES TÉCNICAS ULEAM
// =========================================================================
const SKILL_CATEGORIES = {
    'Frontend & UI': [
        'React', 'Vue.js', 'Angular', 'Next.js', 'Nuxt.js', 'Svelte',
        'Tailwind CSS', 'Bootstrap', 'HTML5', 'CSS3', 'JavaScript', 'TypeScript'
    ],
    'Backend & Lenguajes': [
        'PHP', 'Laravel', 'Node.js', 'Express.js', 'NestJS', 'Python',
        'Django', 'FastAPI', 'Flask', 'Java', 'Spring Boot', 'C#', '.NET',
        'Go', 'Rust', 'Ruby on Rails'
    ],
    'Bases de Datos & Almacenamiento': [
        'PostgreSQL', 'MySQL', 'MariaDB', 'SQLite', 'MongoDB', 'Redis',
        'Firebase', 'Supabase', 'Oracle Database'
    ],
    'DevOps, Cloud & Ciberseguridad': [
        'Docker', 'Kubernetes', 'Linux', 'Git', 'GitHub Actions',
        'AWS', 'Azure', 'Google Cloud Platform', 'Nginx', 'CI/CD',
        'REST APIs', 'GraphQL', 'Cybersecurity', 'Microservicios'
    ]
};

const ALL_APPROVED_SKILLS = Object.values(SKILL_CATEGORIES).flat();

// =========================================================================
// REGLAS DE CIBERSEGURIDAD Y FILTRO DE LENGUAJE OFENSIVO
// =========================================================================
function checkProfanity(rawText) {
    if (!rawText) return false;
    let text = rawText.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

    // Desactivar leetspeak (0->o, 1->i, 3->e, 4->a, 5->s, 7->t, @->a, $->s)
    const leetMap = { '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '@': 'a', '$': 's' };
    let deleet = '';
    for (let i = 0; i < text.length; i++) {
        deleet += leetMap[text[i]] || text[i];
    }

    // Excluir palabras legítimas que contienen 'puta' u otras raíces
    const safeText = deleet.replace(/\b(computad\w*|computac\w*|comput\w*|disput\w*|reputac\w*|diputad\w*|imputac\w*|envergadur\w*)\b/gi, '___');

    // Subcadenas prohibidas (bloquea incluso incrustadas como 'dfgdfputa')
    const forbiddenSubstrings = [
        'puta', 'puto', 'mierd', 'pendej', 'verga', 'chucha', 'cabron', 'cabrona',
        'maricon', 'marica', 'gonorrea', 'malparid', 'conchetumad', 'chupapol',
        'mamaguev', 'careverg', 'hdp', 'zorra', 'bastard', 'chinga'
    ];

    for (const bad of forbiddenSubstrings) {
        if (safeText.includes(bad)) {
            return true;
        }
    }

    // Palabras insultantes a nivel de término completo
    const wordInsults = [
        'idiota', 'idiotas', 'imbecil', 'imbeciles', 'estupido', 'estupida',
        'estupidos', 'estupidas', 'culiao', 'joder', 'coño', 'carajo',
        'caretuco', 'vergaso', 'vergazos'
    ];
    for (const insult of wordInsults) {
        if (new RegExp(`\\b${insult}\\b`, 'i').test(safeText)) {
            return true;
        }
    }

    return false;
}

const XSS_PATTERN = /(<script\b[^>]*>|<\/?(iframe|object|embed|applet|meta|link|style)\b|javascript\s*:|data\s*:\s*text\/html|on(load|error|click|mouseover|submit|focus|keydown)\s*=|(\bunion\s+select\b|\bdrop\s+table\b))/i;

export default function Show({ auth, profile, status, mustVerifyEmail, availableSkills = [] }) {
    const [viewMode, setViewMode] = useState('view'); // 'view', 'edit_info', 'account'
    const [selectedSkill, setSelectedSkill] = useState('');
    const [skillsList, setSkillsList] = useState(profile.skills || []);
    const [flashStatus, setFlashStatus] = useState(status);

    useEffect(() => {
        if (status) {
            setFlashStatus(status);
            const timer = setTimeout(() => {
                setFlashStatus(null);
            }, 3000);
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

    // =========================================================================
    // VALIDACIONES Y SANITIZACIÓN EN TIEMPO REAL (CIBERSEGURIDAD)
    // =========================================================================

    // Teléfono: Únicamente '+' al inicio y números [0-9], de 10 a 14 dígitos
    const handlePhoneChange = (e) => {
        const raw = e.target.value;
        let clean = '';
        for (let i = 0; i < raw.length; i++) {
            const char = raw[i];
            if (char === '+' && clean.length === 0) {
                clean += '+';
            } else if (/[0-9]/.test(char)) {
                clean += char;
            }
        }
        profileForm.setData('phone', clean);
    };

    const isPhoneValid = useMemo(() => {
        if (!profileForm.data.phone) return true;
        const digitsOnly = profileForm.data.phone.replace(/[^0-9]/g, '');
        return /^\+?[0-9]{10,14}$/.test(profileForm.data.phone) && digitsOnly.length >= 10 && digitsOnly.length <= 14;
    }, [profileForm.data.phone]);

    // Estado de verificación en tiempo real de GitHub
    const [githubStatus, setGithubStatus] = useState('idle'); // 'idle' | 'checking' | 'valid' | 'not_found' | 'invalid_format'
    const [githubMessage, setGithubMessage] = useState('');

    // GitHub: Solo caracteres alfanuméricos y guiones
    const handleGithubChange = (e) => {
        let val = e.target.value.replace(/^https?:\/\/(www\.)?github\.com\//i, '').replace(/^@/, '').trim();
        val = val.replace(/[^a-zA-Z0-9-]/g, '');
        profileForm.setData('github_username', val);
    };

    // Verificación de existencia real de cuenta de GitHub
    useEffect(() => {
        const username = profileForm.data.github_username?.trim();
        if (!username) {
            setGithubStatus('idle');
            setGithubMessage('');
            return;
        }

        if (!/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/.test(username)) {
            setGithubStatus('invalid_format');
            setGithubMessage('Usuario inválido (máx. 39 caracteres, solo letras, números y guiones).');
            return;
        }

        setGithubStatus('checking');
        setGithubMessage('Verificando usuario en GitHub...');

        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/profile/verify-github?username=${encodeURIComponent(username)}`);
                const data = await response.json();
                if (data.exists === false) {
                    setGithubStatus('not_found');
                    setGithubMessage(data.message || `El usuario de GitHub '${username}' no existe.`);
                } else {
                    setGithubStatus('valid');
                    setGithubMessage('Usuario verificado en GitHub.');
                }
            } catch (err) {
                setGithubStatus('idle');
                setGithubMessage('');
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [profileForm.data.github_username]);

    const isGithubValid = useMemo(() => {
        if (!profileForm.data.github_username) return true;
        if (!/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/.test(profileForm.data.github_username)) {
            return false;
        }
        return githubStatus !== 'not_found';
    }, [profileForm.data.github_username, githubStatus]);

    // Estado de verificación en tiempo real de LinkedIn
    const [linkedinStatus, setLinkedinStatus] = useState('idle'); // 'idle' | 'checking' | 'valid' | 'not_found' | 'invalid_format'
    const [linkedinMessage, setLinkedinMessage] = useState('');

    // LinkedIn URL: Estrictamente perfil válido
    const handleLinkedinChange = (e) => {
        profileForm.setData('linkedin_url', e.target.value.trim());
    };

    // Verificación de existencia real de perfil de LinkedIn
    useEffect(() => {
        let url = profileForm.data.linkedin_url?.trim();
        if (!url) {
            setLinkedinStatus('idle');
            setLinkedinMessage('');
            return;
        }

        if (!/^https?:\/\//i.test(url)) {
            url = 'https://' + url;
        }

        const linkedinRegex = /^https?:\/\/(?:[a-zA-Z0-9\-]+\.)*linkedin\.com\/in\/[a-zA-Z0-9_\-\.%]{3,100}\/?$/i;

        if (!linkedinRegex.test(url)) {
            setLinkedinStatus('invalid_format');
            setLinkedinMessage('Debe ser un enlace válido a tu perfil de LinkedIn (ej: https://www.linkedin.com/in/tu-perfil).');
            return;
        }

        setLinkedinStatus('checking');
        setLinkedinMessage('Verificando perfil en LinkedIn...');

        const timer = setTimeout(async () => {
            try {
                const response = await fetch(`/profile/verify-linkedin?url=${encodeURIComponent(url)}`);
                const data = await response.json();
                if (data.exists === false) {
                    setLinkedinStatus('not_found');
                    setLinkedinMessage(data.message || 'El perfil no existe en LinkedIn o no es público.');
                } else if (data.exists === true) {
                    setLinkedinStatus('valid');
                    setLinkedinMessage(data.message || 'Perfil verificado.');
                } else {
                    setLinkedinStatus('idle');
                    setLinkedinMessage('');
                }
            } catch (err) {
                setLinkedinStatus('idle');
                setLinkedinMessage('');
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [profileForm.data.linkedin_url]);

    const isLinkedinValid = useMemo(() => {
        if (!profileForm.data.linkedin_url) return true;
        let url = profileForm.data.linkedin_url.trim();
        if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
        const linkedinRegex = /^https?:\/\/(?:[a-zA-Z0-9\-]+\.)*linkedin\.com\/in\/[a-zA-Z0-9_\-\.%]{3,100}\/?$/i;
        if (!linkedinRegex.test(url)) {
            return false;
        }
        return linkedinStatus !== 'not_found' && linkedinStatus !== 'invalid_format';
    }, [profileForm.data.linkedin_url, linkedinStatus]);

    // Biografía: Detección de groserías (subcadena y deleet) y de XSS
    const handleBioChange = (e) => {
        profileForm.setData('bio', e.target.value);
    };

    const bioHasProfanity = useMemo(() => {
        return checkProfanity(profileForm.data.bio || '');
    }, [profileForm.data.bio]);

    const bioHasXss = useMemo(() => {
        return XSS_PATTERN.test(profileForm.data.bio || '');
    }, [profileForm.data.bio]);

    // Habilidades disponibles para agregar (excluye las ya seleccionadas)
    const availableSkillsToPick = useMemo(() => {
        const list = availableSkills.length > 0 ? availableSkills : ALL_APPROVED_SKILLS;
        return list.filter(s => !skillsList.includes(s));
    }, [availableSkills, skillsList]);

    const addSkill = (skillToAdd) => {
        const target = skillToAdd || selectedSkill;
        if (!target) return;
        if (!ALL_APPROVED_SKILLS.includes(target) && !availableSkills.includes(target)) {
            return;
        }
        if (!skillsList.includes(target)) {
            setSkillsList([...skillsList, target]);
            setSelectedSkill('');
        }
    };

    const removeSkill = (skillToRemove) => {
        setSkillsList(skillsList.filter(s => s !== skillToRemove));
    };

    // Bloqueo de seguridad si hay violaciones
    const isFormBlocked = useMemo(() => {
        return !isPhoneValid || !isGithubValid || !isLinkedinValid || bioHasProfanity || bioHasXss ||
            githubStatus === 'checking' || githubStatus === 'not_found' ||
            linkedinStatus === 'checking' || linkedinStatus === 'not_found';
    }, [isPhoneValid, isGithubValid, isLinkedinValid, bioHasProfanity, bioHasXss, githubStatus, linkedinStatus]);

    const handleProfileSubmit = (e) => {
        e.preventDefault();
        if (isFormBlocked) return;

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
                                // Vista del Formulario de Edición de Currículum con CIBERSEGURIDAD
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


                                    <form onSubmit={handleProfileSubmit} className="space-y-5">
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            {/* TELÉFONO: Solo '+' y números */}
                                            <div>
                                                <div className="flex justify-between items-center mb-1.5">
                                                    <label className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                                                        <span>Teléfono</span>
                                                    </label>
                                                </div>
                                                <input
                                                    type="tel"
                                                    value={profileForm.data.phone}
                                                    onChange={handlePhoneChange}
                                                    maxLength={16}
                                                    className={`w-full px-4 py-2.5 bg-white dark:bg-slate-950 border rounded-xl text-slate-900 dark:text-slate-200 focus:ring-0 text-sm shadow-2xs font-mono transition ${!isPhoneValid
                                                        ? 'border-rose-500 focus:border-rose-500'
                                                        : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                                        }`}
                                                    placeholder="+593998877665"
                                                />
                                                {!isPhoneValid && (
                                                    <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <span>Solo se permite de 10 a 14 dígitos.</span>
                                                    </p>
                                                )}
                                                {profileForm.errors.phone && (
                                                    <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <span>{profileForm.errors.phone}</span>
                                                    </p>
                                                )}
                                            </div>

                                            {/* USUARIO GITHUB: Validación de formato y verificación real */}
                                            <div>
                                                <div className="flex justify-between items-center mb-1.5">
                                                    <label className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                                        Usuario GitHub
                                                    </label>
                                                </div>
                                                <div className="relative">
                                                    <span className="absolute left-3.5 top-2.5 text-slate-400 font-mono text-sm select-none">@</span>
                                                    <input
                                                        type="text"
                                                        value={profileForm.data.github_username}
                                                        onChange={handleGithubChange}
                                                        maxLength={39}
                                                        className={`w-full pl-8 pr-10 py-2.5 bg-white dark:bg-slate-950 border rounded-xl text-slate-900 dark:text-slate-200 focus:ring-0 text-sm shadow-2xs font-mono transition ${!isGithubValid || githubStatus === 'not_found'
                                                            ? 'border-rose-500 focus:border-rose-500'
                                                            : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                                            }`}
                                                        placeholder="usuario_github"
                                                    />
                                                    {githubStatus === 'checking' && (
                                                        <span className="absolute right-3 top-3">
                                                            <svg className="w-4 h-4 animate-spin text-slate-400" fill="none" viewBox="0 0 24 24">
                                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                            </svg>
                                                        </span>
                                                    )}
                                                    {githubStatus === 'valid' && (
                                                        <span className="absolute right-3 top-3">
                                                            <svg className="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </span>
                                                    )}
                                                </div>
                                                {profileForm.data.github_username && !/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/.test(profileForm.data.github_username) && (
                                                    <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <span>Usuario inválido (máx. 39 caracteres, sin guiones consecutivos ni al inicio/final).</span>
                                                    </p>
                                                )}
                                                {githubStatus === 'not_found' && (
                                                    <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <span>{githubMessage || 'El usuario de GitHub no existe.'}</span>
                                                    </p>
                                                )}
                                                {githubStatus === 'checking' && (
                                                    <p className="text-slate-400 text-xs font-medium mt-1.5 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 animate-spin shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                        </svg>
                                                        <span>Verificando usuario en GitHub...</span>
                                                    </p>
                                                )}
                                                {githubStatus === 'valid' && (
                                                    <p style={{ color: '#16a34a' }} className="text-emerald-600 text-xs font-semibold mt-1.5 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                        <span>Cuenta verificada.</span>
                                                    </p>
                                                )}
                                                {profileForm.errors.github_username && (
                                                    <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1 flex items-center gap-1.5">
                                                        <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <span>{profileForm.errors.github_username}</span>
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        {/* URL LINKEDIN: Verificación y visto verde */}
                                        <div>
                                            <div className="flex justify-between items-center mb-1.5">
                                                <label className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                                    URL LinkedIn
                                                </label>
                                            </div>
                                            <div className="relative">
                                                <input
                                                    type="url"
                                                    value={profileForm.data.linkedin_url}
                                                    onChange={handleLinkedinChange}
                                                    maxLength={255}
                                                    className={`w-full pl-4 pr-10 py-2.5 bg-white dark:bg-slate-950 border rounded-xl text-slate-900 dark:text-slate-200 focus:ring-0 text-sm shadow-2xs transition ${!isLinkedinValid || linkedinStatus === 'not_found'
                                                        ? 'border-rose-500 focus:border-rose-500'
                                                        : linkedinStatus === 'valid'
                                                            ? 'border-emerald-500/70 focus:border-emerald-600 dark:focus:border-emerald-500'
                                                            : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                                        }`}
                                                    placeholder="https://www.linkedin.com/in/tu-perfil"
                                                />
                                                {linkedinStatus === 'checking' && (
                                                    <span className="absolute right-3 top-3">
                                                        <svg className="w-4 h-4 animate-spin text-slate-400" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                        </svg>
                                                    </span>
                                                )}
                                                {linkedinStatus === 'valid' && (
                                                    <span className="absolute right-3 top-2.5 text-emerald-600 dark:text-emerald-400" title="Perfil verificado">
                                                        <svg className="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </span>
                                                )}
                                            </div>

                                            {/* Mensajes de validación de LinkedIn */}
                                            {linkedinStatus === 'invalid_format' && (
                                                <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <span>{linkedinMessage || 'Debe ser un enlace válido a tu perfil de LinkedIn (ej: https://www.linkedin.com/in/tu-perfil).'}</span>
                                                </p>
                                            )}
                                            {linkedinStatus === 'not_found' && (
                                                <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1.5 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <span>{linkedinMessage || 'El perfil no existe en LinkedIn o no es público.'}</span>
                                                </p>
                                            )}
                                            {linkedinStatus === 'checking' && (
                                                <p className="text-slate-400 text-xs font-medium mt-1.5 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 animate-spin shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                                    </svg>
                                                    <span>Verificando perfil en LinkedIn...</span>
                                                </p>
                                            )}
                                            {linkedinStatus === 'valid' && (
                                                <p style={{ color: '#16a34a' }} className="text-emerald-600 text-xs font-semibold mt-1.5 flex items-center gap-1.5">
                                                    <svg className="w-4 h-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                    <span>Perfil verificado.</span>
                                                </p>
                                            )}
                                            {profileForm.errors.linkedin_url && (
                                                <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1 flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <span>{profileForm.errors.linkedin_url}</span>
                                                </p>
                                            )}
                                        </div>

                                        {/* BIOGRAFÍA PROFESIONAL: Filtro Anti-Groserías y Protección XSS */}
                                        <div>
                                            <div className="flex justify-between items-center mb-1.5">
                                                <label className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                                    Biografía Profesional
                                                </label>
                                                <div className="flex items-center gap-3">
                                                    <span className={`text-[11px] font-mono ${(profileForm.data.bio?.length || 0) > 950 ? 'text-amber-500 font-bold' : 'text-slate-400'
                                                        }`}>
                                                        {profileForm.data.bio?.length || 0} / 1000
                                                    </span>
                                                </div>
                                            </div>
                                            <textarea
                                                value={profileForm.data.bio}
                                                onChange={handleBioChange}
                                                maxLength={1000}
                                                rows="4"
                                                className={`w-full px-4 py-2.5 bg-white dark:bg-slate-950 border rounded-xl text-slate-900 dark:text-slate-200 focus:ring-0 text-sm leading-relaxed shadow-2xs transition ${bioHasProfanity || bioHasXss
                                                    ? 'border-rose-500 focus:border-rose-500 bg-rose-50/20'
                                                    : 'border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500'
                                                    }`}
                                                placeholder="Escribe una reseña académica y profesional sobre tus intereses, proyectos destacados y objetivos laborales..."
                                            />

                                            {/* Advertencia de Groserías / Lenguaje inapropiado */}
                                            {bioHasProfanity && (
                                                <div style={{ color: '#dc2626' }} className="mt-1.5 p-2 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 text-red-600 text-xs font-bold flex items-center gap-2">
                                                    <svg className="w-4 h-4 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <span>No se permiten palabras ofensivas o groserías en la biografía.</span>
                                                </div>
                                            )}

                                            {/* Advertencia de XSS / Scripts */}
                                            {bioHasXss && (
                                                <div style={{ color: '#dc2626' }} className="mt-1.5 p-2 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 text-red-600 text-xs font-bold flex items-center gap-2">
                                                    <svg className="w-4 h-4 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                    </svg>
                                                    <span>No se permiten scripts ni etiquetas HTML en la biografía.</span>
                                                </div>
                                            )}

                                            {profileForm.errors.bio && (
                                                <p style={{ color: '#dc2626' }} className="text-red-600 text-xs font-bold mt-1">
                                                    {profileForm.errors.bio}
                                                </p>
                                            )}
                                        </div>

                                        {/* HABILIDADES TÉCNICAS: SELECTOR RESTRINGIDO A LISTA OFICIAL */}
                                        <div>
                                            <div className="flex justify-between items-center mb-1.5">
                                                <label className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                                    Habilidades Técnicas
                                                </label>

                                            </div>
                                            <p className="text-xs text-slate-500 dark:text-slate-400 mb-2.5">
                                                Para garantizar la validez del currículum, selecciona tus habilidades técnicas exclusivamente de la lista de tecnologías reconocidas:
                                            </p>

                                            {/* Selector Dropdown con Categorías */}
                                            <div className="flex flex-col sm:flex-row gap-2 mb-3">
                                                <select
                                                    value={selectedSkill}
                                                    onChange={e => setSelectedSkill(e.target.value)}
                                                    className="flex-1 px-4 py-2.5 bg-white dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-900 dark:text-slate-200 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-sm shadow-2xs font-medium cursor-pointer"
                                                >
                                                    <option value="">-- Elige una habilidad técnica de la lista oficial --</option>
                                                    {Object.entries(SKILL_CATEGORIES).map(([category, skills]) => {
                                                        const remaining = skills.filter(s => !skillsList.includes(s));
                                                        if (remaining.length === 0) return null;
                                                        return (
                                                            <optgroup key={category} label={category} className="font-bold text-slate-800 dark:text-slate-200">
                                                                {remaining.map(skill => (
                                                                    <option key={skill} value={skill} className="font-normal text-slate-700 dark:text-slate-300">
                                                                        {skill}
                                                                    </option>
                                                                ))}
                                                            </optgroup>
                                                        );
                                                    })}
                                                </select>
                                                <button
                                                    type="button"
                                                    disabled={!selectedSkill}
                                                    onClick={() => addSkill()}
                                                    className="px-5 py-2.5 rounded-xl bg-[#1534e8] hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-bold shadow-xs transition flex items-center justify-center gap-1.5 shrink-0 cursor-pointer"
                                                >
                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                                                    </svg>
                                                    <span>Agregar Habilidad</span>
                                                </button>
                                            </div>

                                            {/* Sugerencias Rápidas de la Lista Oficial */}
                                            {availableSkillsToPick.length > 0 && (
                                                <div className="mb-3">
                                                    <span className="text-[11px] font-semibold text-slate-400 block mb-1.5">Sugerencias rápidas (haz clic para agregar):</span>
                                                    <div className="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto pr-1">
                                                        {availableSkillsToPick.slice(0, 14).map(skill => (
                                                            <button
                                                                key={skill}
                                                                type="button"
                                                                onClick={() => addSkill(skill)}
                                                                className="px-2.5 py-1 rounded-lg text-[11px] font-medium bg-slate-100 dark:bg-slate-900 hover:bg-blue-50 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 hover:text-[#1534e8] border border-slate-200 dark:border-slate-800 transition cursor-pointer flex items-center gap-1"
                                                            >
                                                                <span>+</span>
                                                                <span>{skill}</span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            {/* Habilidades seleccionadas por el estudiante */}
                                            <div className="p-3 rounded-xl bg-slate-50 dark:bg-slate-950/60 border border-slate-200 dark:border-slate-850 min-h-[52px]">
                                                {skillsList.length > 0 ? (
                                                    <div className="flex flex-wrap gap-2">
                                                        {skillsList.map((skill, i) => (
                                                            <span
                                                                key={i}
                                                                className="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 dark:bg-cyan-950/30 dark:border-cyan-900/40 dark:text-cyan-400 text-xs font-semibold shadow-2xs"
                                                            >
                                                                <span>{skill}</span>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeSkill(skill)}
                                                                    className="text-blue-500 hover:text-rose-600 dark:text-cyan-400 dark:hover:text-rose-400 transition"
                                                                    title="Eliminar habilidad"
                                                                >
                                                                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                                                    </svg>
                                                                </button>
                                                            </span>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <p className="text-xs text-slate-400 italic text-center py-1">
                                                        No has seleccionado ninguna habilidad técnica todavía. Elige de la lista superior.
                                                    </p>
                                                )}
                                            </div>
                                            {profileForm.errors.skills && (
                                                <p className="text-rose-600 dark:text-rose-400 text-[11px] font-semibold mt-1">
                                                    {profileForm.errors.skills}
                                                </p>
                                            )}
                                        </div>

                                        {/* Botones de Envío y Cancelar */}
                                        <div className="flex items-center gap-3 pt-4 border-t border-slate-200 dark:border-slate-850">
                                            <button
                                                type="submit"
                                                disabled={profileForm.processing || isFormBlocked}
                                                className={`px-5 py-2.5 rounded-xl text-sm font-bold text-white shadow-xs transition flex items-center gap-2 ${isFormBlocked
                                                    ? 'bg-slate-400 dark:bg-slate-700 cursor-not-allowed opacity-60'
                                                    : 'bg-[#1534e8] hover:bg-blue-700 cursor-pointer'
                                                    }`}
                                            >
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <span>{profileForm.processing ? 'Guardando con seguridad...' : 'Guardar Información'}</span>
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => setViewMode('view')}
                                                className="px-5 py-2.5 rounded-xl text-sm font-bold bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-200 dark:border-transparent transition shadow-2xs cursor-pointer"
                                            >
                                                Cancelar
                                            </button>

                                            {isFormBlocked && (
                                                <span style={{ color: '#dc2626' }} className="text-xs text-red-600 font-bold flex items-center gap-1.5">
                                                    <svg className="w-3.5 h-3.5 shrink-0 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <span>{githubStatus === 'checking' ? 'Verificando usuario en GitHub...' : 'Corrige los campos para guardar los cambios.'}</span>
                                                </span>
                                            )}
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
