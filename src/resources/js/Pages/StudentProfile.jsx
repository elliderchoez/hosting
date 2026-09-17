import { useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';

export default function StudentProfile({ auth = {}, student }) {
    const profile = student?.profile || {};
    const projects = student?.projects || [];

    // Theme state
    const [theme, setTheme] = useState(localStorage.getItem('theme') || 'academic');
    useEffect(() => {
        localStorage.setItem('theme', theme);
        if (theme === 'dark' || theme === 'spatial') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('theme-academic');
        } else {
            document.documentElement.classList.add('theme-academic');
            document.documentElement.classList.remove('dark');
        }
    }, [theme]);

    // Demo Sandbox Modal states
    const [activeDemo, setActiveDemo] = useState(null);
    const [wakingUp, setWakingUp] = useState(false);
    const [demoError, setDemoError] = useState(null);
    const [demoLogs, setDemoLogs] = useState('');
    const [demoKey, setDemoKey] = useState(Date.now());
    const [iframeLoaded, setIframeLoaded] = useState(false);

    // Contact Modal states
    const [isContactOpen, setIsContactOpen] = useState(false);
    const [contactProject, setContactProject] = useState(null);
    const [isContactSent, setIsContactSent] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        company: '',
        message: '',
    });

    const handleOpenContact = (project = null) => {
        setContactProject(project);
        setIsContactSent(false);
        reset();
        if (project) {
            setData('message', `Hola ${student.name}, vi tu proyecto "${project.name}" en la Vitrina de Nexus Academic y me gustaría conversar contigo sobre oportunidades profesionales.`);
        } else {
            setData('message', `Hola ${student.name}, revisé tu perfil en Nexus Academic y me gustaría ponerme en contacto contigo.`);
        }
        setIsContactOpen(true);
    };

    const handleContactSubmit = (e) => {
        e.preventDefault();
        post(route('student.contact', student.id), {
            preserveScroll: true,
            onSuccess: () => {
                setIsContactSent(true);
                setTimeout(() => {
                    setIsContactOpen(false);
                    setIsContactSent(false);
                    reset();
                }, 2500);
            },
        });
    };

    const handleStartDemo = async (project) => {
        setWakingUp(true);
        setIframeLoaded(false);
        setActiveDemo(project);
        setDemoError(null);
        setDemoLogs('');
        setDemoKey(Date.now());
        try {
            const response = await fetch(`/showcase/projects/${project.id}/start`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });
            const resData = await response.json();
            if (!resData.success) {
                setDemoError(resData.error || 'Error al iniciar el contenedor de demostración.');
                setDemoLogs(resData.logs || 'No hay bitácoras disponibles del contenedor.');
            }
        } catch (err) {
            setDemoError('Error de conexión con el orquestador de contenedores.');
        } finally {
            setWakingUp(false);
        }
    };

    const handleCloseDemo = async (project) => {
        setActiveDemo(null);
        setDemoError(null);
        setDemoLogs('');
        if (project) {
            try {
                await fetch(`/showcase/projects/${project.id}/stop`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    }
                });
            } catch (e) { }
        }
    };

    const skills = Array.isArray(profile.skills) ? profile.skills : [];
    const education = Array.isArray(profile.education) ? profile.education : [];

    return (
        <>
            <Head title={`Perfil de ${student.name} - Nexus Academic`} />
            <div className="min-h-screen bg-[#f3f5f9] dark:bg-[#090d16] text-[#1e293b] dark:text-[#f1f5f9] font-sans antialiased selection:bg-blue-600 selection:text-white transition-colors duration-200">

                {/* =========================================================================
                    1. CABECERA LIMPIA Y NAVEGACIÓN
                    ========================================================================= */}
                <header className="sticky top-0 z-40 w-full bg-white dark:bg-[#0b0f19] border-b border-slate-200 dark:border-slate-800 shadow-xs transition-colors duration-200">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <Link href="/" className="flex items-center gap-2.5 shrink-0 group">
                                <ApplicationLogo className="h-8 w-auto object-contain transition group-hover:scale-105" />
                                <div className="flex items-center">
                                    <span className="brand-nexus text-xl font-black tracking-tight text-blue-700 dark:text-blue-500">nexus</span>
                                    <span className="brand-academic text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">academic</span>
                                </div>
                            </Link>

                            <div className="hidden sm:block h-5 w-[1px] bg-slate-200 dark:bg-slate-800" />

                            <Link
                                href="/"
                                className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 transition"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                <span>Volver a la Galería</span>
                            </Link>
                        </div>

                        <div className="flex items-center gap-3">
                            <button
                                onClick={() => setTheme(theme === 'dark' || theme === 'spatial' ? 'academic' : 'dark')}
                                className="p-2 rounded-full text-slate-500 hover:text-slate-800 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                title="Alternar tema"
                            >
                                {(theme === 'dark' || theme === 'spatial') ? (
                                    <svg className="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 9H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                    </svg>
                                ) : (
                                    <svg className="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                    </svg>
                                )}
                            </button>

                            {auth?.user ? (
                                <Link
                                    href={route('dashboard')}
                                    className="px-4 py-1.5 rounded-full text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition"
                                >
                                    Mi Panel
                                </Link>
                            ) : (
                                <Link
                                    href={route('login')}
                                    className="px-4 py-1.5 rounded-full text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition"
                                >
                                    Ingresar
                                </Link>
                            )}
                        </div>
                    </div>
                </header>

                {/* =========================================================================
                    2. CONTENEDOR PRINCIPAL DEL PERFIL
                    ========================================================================= */}
                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

                    {/* TARJETA HERO DEL ESTUDIANTE */}
                    <div className="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0f172a] border border-slate-200/90 dark:border-slate-800 shadow-sm p-6 sm:p-10">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-6 mb-6">
                            {/* Avatar con Inicial */}
                            <div className="flex items-center gap-5">
                                <div className="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center text-white text-3xl font-black shadow-md shrink-0">
                                    {student.name ? student.name.charAt(0).toUpperCase() : 'U'}
                                </div>
                                <div className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h1 className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
                                            {student.name}
                                        </h1>
                                            <span className="inline-flex items-center gap-1 text-[11px] font-extrabold px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                                <svg className="w-3 h-3 fill-current" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                                </svg>
                                                Estudiante ULEAM
                                            </span>
                                        </div>
                                        <p className="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-medium">
                                            Facultad de Ciencias de la Vida y Tecnologías
                                        </p>
                                    </div>
                                </div>

                                {/* Botones de Acción (Contactar + Descargar CV) */}
                                <div className="flex flex-wrap items-center gap-2.5 pt-2 sm:pt-0">
                                    <button
                                        onClick={() => handleOpenContact()}
                                        className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-sm transition cursor-pointer"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                        </svg>
                                        <span>Contactar al Estudiante</span>
                                    </button>

                                    <a
                                        href={route('student.cv', student.id)}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-300 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/60 shadow-2xs transition"
                                    >
                                        <svg className="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span>Ver Curriculum Vitae (PDF)</span>
                                    </a>

                                    {profile.github_username && (
                                        <a
                                            href={`https://github.com/${profile.github_username}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="p-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:text-black dark:hover:text-white transition"
                                            title="Perfil de GitHub"
                                        >
                                            <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                                <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z" />
                                            </svg>
                                        </a>
                                    )}

                                    {profile.linkedin_url && (
                                        <a
                                            href={profile.linkedin_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="p-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-blue-600 hover:text-blue-700 transition"
                                            title="Perfil de LinkedIn"
                                        >
                                            <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                                <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z" />
                                            </svg>
                                        </a>
                                    )}
                                </div>
                            </div>

                            {/* Resumen Profesional / Bio */}
                            {profile?.bio && profile.bio.trim() !== '' && (
                                <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                                    <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-1.5">
                                        Sobre el Estudiante
                                    </h2>
                                    <p className="text-xs sm:text-sm text-slate-700 dark:text-slate-300 leading-relaxed max-w-4xl whitespace-pre-line">
                                        {profile.bio}
                                    </p>
                                </div>
                            )}

                            {/* Habilidades Técnicas */}
                            {skills.length > 0 && (
                                <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                                    <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2">
                                        Competencias y Habilidades
                                    </h2>
                                    <div className="flex flex-wrap gap-2">
                                        {skills.map((skill, index) => (
                                            <span
                                                key={index}
                                                className="px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800/80 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700"
                                            >
                                                {skill}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Formación Académica */}
                            {education.length > 0 && (
                                <div className="mt-4 pt-4 border-t border-slate-100 dark:border-slate-800/80">
                                    <h2 className="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-2">
                                        Educación y Trayectoria
                                    </h2>
                                    <div className="space-y-2">
                                        {education.map((edu, idx) => (
                                            <div key={idx} className="text-xs text-slate-700 dark:text-slate-300">
                                                <strong className="font-bold text-slate-900 dark:text-white">{edu.degree || edu.institution || 'Carrera Universitaria'}</strong>
                                                {edu.institution && <span className="text-slate-500 dark:text-slate-400"> • {edu.institution}</span>}
                                                {edu.year && <span className="text-slate-400 dark:text-slate-500"> ({edu.year})</span>}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                    {/* =========================================================================
                        3. SECCIÓN: PROYECTOS Y APLICACIONES DEL ESTUDIANTE
                        ========================================================================= */}
                    <div>
                        <div className="flex items-center justify-between gap-4 mb-5">
                            <div>
                                <h2 className="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white tracking-tight">
                                    Proyectos y Demostraciones en Vivo
                                </h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    Software desplegado y ejecutable interactivamente en la plataforma
                                </p>
                            </div>
                        </div>

                        {projects.length === 0 ? (
                            <div className="p-12 text-center rounded-3xl bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 text-slate-400">
                                <svg className="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                </svg>
                                <p className="text-sm font-semibold">El estudiante aún no tiene proyectos activos en la vitrina pública.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {projects.map((project) => {
                                    const dbBadge = getDatabaseBadge(project.db_driver);

                                    return (
                                        <div
                                            key={project.id}
                                            className="flex flex-col bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 rounded-3xl overflow-hidden shadow-2xs hover:shadow-md hover:border-blue-300 dark:hover:border-blue-900/60 transition-all duration-300"
                                        >
                                            {/* Cabecera de la tarjeta con estado y lenguaje */}
                                            <div className="p-5 pb-3 flex items-center justify-between border-b border-slate-100 dark:border-slate-800/60">
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 uppercase">
                                                    {project.language || 'Software'}
                                                </span>

                                                <span className={`inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-0.5 rounded-full ${project.status === 'running'
                                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/80'
                                                    : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                                                    }`}>
                                                    <span className={`w-2 h-2 rounded-full ${project.status === 'running' ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'}`} />
                                                    <span>{project.status === 'running' ? 'En ejecución' : 'En reposo'}</span>
                                                </span>
                                            </div>

                                            {/* Contenido principal de la tarjeta */}
                                            <div className="p-5 flex-1 flex flex-col justify-between space-y-3">
                                                <div>
                                                    <h3 className="text-base font-bold text-slate-900 dark:text-white line-clamp-1 mb-1">
                                                        {project.name}
                                                    </h3>

                                                    <div className="flex flex-wrap items-center gap-1.5 mb-2">
                                                        <span className="text-[10px] font-bold text-slate-600 dark:text-slate-400 bg-slate-100 dark:bg-slate-800/80 px-2 py-0.5 rounded-md">
                                                            {project.category || 'Herramientas'}
                                                        </span>
                                                        {dbBadge}
                                                    </div>

                                                    <p className="text-xs text-slate-600 dark:text-slate-400 line-clamp-2 leading-relaxed">
                                                        {project.description || 'Proyecto desarrollado y desplegado en la plataforma con soporte para demostración en vivo.'}
                                                    </p>
                                                </div>

                                                <div className="pt-2 text-[11px] text-slate-400 dark:text-slate-500">
                                                    Subdominio: <span className="font-mono">{project.subdomain}.localhost</span>
                                                </div>
                                            </div>

                                            {/* Pie de la tarjeta */}
                                            <div className="p-3.5 border-t border-slate-100 dark:border-slate-800/80 bg-slate-50/50 dark:bg-slate-900/30 flex items-center justify-between gap-2">
                                                <button
                                                    onClick={() => handleOpenContact(project)}
                                                    className="text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 px-3 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                                                >
                                                    Contactar
                                                </button>

                                                <button
                                                    onClick={() => handleStartDemo(project)}
                                                    className="inline-flex items-center gap-1.5 px-4 py-1.5 rounded-xl text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition cursor-pointer"
                                                >
                                                    <svg className="w-3.5 h-3.5 fill-current" viewBox="0 0 24 24">
                                                        <path d="M8 5v14l11-7z" />
                                                    </svg>
                                                    <span>Ver Demo</span>
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </main>

                {/* =========================================================================
                    4. MODAL DE DEMO EN VIVO (VISOR INTERACTIVO)
                    ========================================================================= */}
                {activeDemo && (
                    <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-2 sm:p-4">
                        <div className="w-full h-full max-w-7xl max-h-[95vh] bg-white dark:bg-[#0f172a] rounded-2xl shadow-2xl flex flex-col overflow-hidden border border-slate-200 dark:border-slate-800">
                            {/* Cabecera del visor */}
                            <div className="px-6 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-900/60">
                                <div>
                                    <h3 className="font-bold text-sm text-slate-900 dark:text-white flex items-center gap-2">
                                        <span>{activeDemo.name}</span>
                                        <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                                            http://{activeDemo.subdomain}.localhost
                                        </span>
                                    </h3>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                        Estudiante: <strong>{student.name}</strong> • Entorno Sandbox
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    <a
                                        href={`http://${activeDemo.subdomain}.localhost`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white transition flex items-center gap-1.5 shadow-xs"
                                    >
                                        <span>Abrir en pestaña</span>
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>

                                    <button
                                        onClick={() => handleCloseDemo(activeDemo)}
                                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition cursor-pointer"
                                    >
                                        Cerrar
                                    </button>
                                </div>
                            </div>

                            {/* Contenido: Iframe interactivo + panel lateral */}
                            <div className="flex-1 bg-white relative flex flex-row min-h-0">
                                <div className="flex-1 min-w-0 h-full relative bg-white">
                                    {demoError ? (
                                        <div className="w-full h-full flex flex-col p-8 bg-slate-900 text-slate-200 font-mono overflow-y-auto">
                                            <p className="text-red-400 font-bold mb-4">{demoError}</p>
                                            <pre className="text-xs bg-slate-950 p-4 rounded-xl overflow-auto text-red-300">{demoLogs}</pre>
                                        </div>
                                    ) : (
                                        <>
                                            {(!iframeLoaded || wakingUp) && (
                                                <div className="absolute inset-0 bg-white/95 dark:bg-slate-950/95 flex flex-col items-center justify-center z-10">
                                                    <div className="w-10 h-10 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mb-4" />
                                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                                        {wakingUp ? 'Iniciando demostración en vivo...' : 'Conectando con la aplicación...'}
                                                    </p>
                                                    <p className="text-xs text-slate-500 mt-1">Nexus Academic PaaS</p>
                                                </div>
                                            )}
                                            <iframe
                                                key={demoKey}
                                                src={`http://${activeDemo.subdomain}.localhost`}
                                                className="w-full h-full border-0"
                                                title={activeDemo.name}
                                                onLoad={() => {
                                                    if (!wakingUp) setIframeLoaded(true);
                                                }}
                                            />
                                        </>
                                    )}
                                </div>

                                {/* Barra lateral de ayuda al evaluador */}
                                <div className="w-80 border-l border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/90 p-5 flex flex-col justify-between hidden md:flex">
                                    <div className="space-y-4">
                                        <h4 className="text-xs font-bold uppercase tracking-wider text-slate-400">Guía de Prueba</h4>
                                        <div className="p-3.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 space-y-2 text-xs">
                                            <p className="font-bold text-slate-900 dark:text-white">Credenciales Sugeridas:</p>
                                            <div className="flex justify-between">
                                                <span className="text-slate-500">Usuario:</span>
                                                <span className="font-mono font-bold">admin@nexus.test</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-slate-500">Clave:</span>
                                                <span className="font-mono font-bold">password123</span>
                                            </div>
                                        </div>

                                        <p className="text-[11px] text-slate-500 leading-relaxed">
                                            Puedes interactuar libremente con la aplicación. Al cerrar la demo, los datos se restablecen de forma automática y segura.
                                        </p>
                                    </div>

                                    <div className="text-center pt-3 border-t border-slate-200 dark:border-slate-800 text-[10px] text-slate-400 font-mono">
                                        Nexus Academic Platform
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* =========================================================================
                    5. MODAL DE CONTACTO AL ESTUDIANTE
                    ========================================================================= */}
                {isContactOpen && (
                    <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="w-full max-w-md bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
                                <div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                        Contactar a {student.name}
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                        {contactProject ? `Consulta sobre: ${contactProject.name}` : 'Vinculación profesional o académica'}
                                    </p>
                                </div>
                                <button
                                    onClick={() => setIsContactOpen(false)}
                                    className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition"
                                >
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {isContactSent ? (
                                <div className="p-8 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-center space-y-2">
                                    <p className="text-base font-bold">¡Mensaje enviado con éxito!</p>
                                    <p className="text-xs text-slate-600 dark:text-slate-300">
                                        El estudiante responderá a tu correo corporativo.
                                    </p>
                                </div>
                            ) : (
                                <form onSubmit={handleContactSubmit} className="space-y-3.5 text-xs">
                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 mb-1">Tu Nombre</label>
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 transition"
                                            placeholder="Ingresa tu nombre..."
                                        />
                                        {errors.name && <p className="text-red-500 mt-1">{errors.name}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 mb-1">Correo Corporativo</label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 transition"
                                            placeholder="reclutador@empresa.com"
                                        />
                                        {errors.email && <p className="text-red-500 mt-1">{errors.email}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 mb-1">Empresa / Organización</label>
                                        <input
                                            type="text"
                                            value={data.company}
                                            onChange={(e) => setData('company', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 transition"
                                            placeholder="Nombre de la empresa..."
                                        />
                                        {errors.company && <p className="text-red-500 mt-1">{errors.company}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 mb-1">Mensaje o Propuesta</label>
                                        <textarea
                                            value={data.message}
                                            onChange={(e) => setData('message', e.target.value)}
                                            required
                                            rows="3"
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 transition"
                                        />
                                        {errors.message && <p className="text-red-500 mt-1">{errors.message}</p>}
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full py-2.5 rounded-xl bg-[#1534e8] hover:bg-blue-700 text-white font-bold transition disabled:opacity-50 shadow-xs cursor-pointer"
                                    >
                                        {processing ? 'Enviando...' : 'Enviar Contacto'}
                                    </button>
                                </form>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function getDatabaseBadge(dbDriver) {
    const driver = (dbDriver || '').toLowerCase();
    if (driver === 'pgsql' || driver === 'postgres' || driver === 'postgresql') {
        return (
            <span
                title="PostgreSQL"
                className="inline-flex items-center gap-1 text-[10px] font-bold text-sky-800 dark:text-sky-300 bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800/80 rounded-full px-2 py-0.5"
            >
                <svg className="w-3 h-3 text-sky-600 fill-current shrink-0" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 4.02 2 6.5v11C2 19.98 6.48 22 12 22s10-2.02 10-4.5v-11C22 4.02 17.52 2 12 2zm0 2c4.41 0 8 1.34 8 2.5S16.41 9 12 9 4 7.66 4 6.5 7.59 4 12 4zm0 16c-4.41 0-8-1.34-8-2.5V15.7c1.94 1.13 4.8 1.8 8 1.8s6.06-.67 8-1.8v1.8c0 1.16-3.59 2.5-8 2.5zm0-4.5c-4.41 0-8-1.34-8-2.5v-1.8c1.94 1.13 4.8 1.8 8 1.8s6.06-.67 8-1.8V15c0 1.16-3.59 2.5-8 2.5z" />
                </svg>
                <span>PostgreSQL</span>
            </span>
        );
    }
    if (driver === 'mysql') {
        return (
            <span
                title="MySQL"
                className="inline-flex items-center gap-1 text-[10px] font-bold text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/80 rounded-full px-2 py-0.5"
            >
                <svg className="w-3 h-3 text-amber-600 fill-current shrink-0" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 4.02 2 6.5v11C2 19.98 6.48 22 12 22s10-2.02 10-4.5v-11C22 4.02 17.52 2 12 2zm0 2c4.41 0 8 1.34 8 2.5S16.41 9 12 9 4 7.66 4 6.5 7.59 4 12 4zm0 16c-4.41 0-8-1.34-8-2.5V15.7c1.94 1.13 4.8 1.8 8 1.8s6.06-.67 8-1.8v1.8c0 1.16-3.59 2.5-8 2.5zm0-4.5c-4.41 0-8-1.34-8-2.5v-1.8c1.94 1.13 4.8 1.8 8 1.8s6.06-.67 8-1.8V15c0 1.16-3.59 2.5-8 2.5z" />
                </svg>
                <span>MySQL</span>
            </span>
        );
    }
    if (driver === 'mongodb' || driver === 'mongo') {
        return (
            <span
                title="MongoDB"
                className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/80 rounded-full px-2 py-0.5"
            >
                <svg className="w-3 h-3 text-emerald-600 fill-current shrink-0" viewBox="0 0 24 24">
                    <path d="M12 2C8 7 6 11.5 6 15c0 3.31 2.69 6 6 6s6-2.69 6-6c0-3.5-2-8-6-13zm0 17.5c-2.48 0-4.5-2.02-4.5-4.5 0-2.22 1.4-5.44 4.5-9.62 3.1 4.18 4.5 7.4 4.5 9.62 0 2.48-2.02 4.5-4.5 4.5z" />
                </svg>
                <span>MongoDB</span>
            </span>
        );
    }
    return null;
}
