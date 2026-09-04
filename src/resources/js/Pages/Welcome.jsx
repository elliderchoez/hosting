import { useState, useMemo, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import DemoLoadingAnimation from '@/Components/DemoLoadingAnimation';

export default function Welcome({ auth, projects }) {
    const [search, setSearch] = useState('');
    const [selectedLanguage, setSelectedLanguage] = useState('all');
    const [activeDemo, setActiveDemo] = useState(null);
    const [contactStudent, setContactStudent] = useState(null);
    const [wakingUp, setWakingUp] = useState(false);
    const [demoError, setDemoError] = useState(null);
    const [demoLogs, setDemoLogs] = useState('');

    const [theme, setTheme] = useState(localStorage.getItem('theme') || 'spatial');

    useEffect(() => {
        localStorage.setItem('theme', theme);
        if (theme === 'academic') {
            document.documentElement.classList.add('theme-academic');
        } else {
            document.documentElement.classList.remove('theme-academic');
        }
    }, [theme]);

    const handleStartDemo = async (project) => {
        setWakingUp(true);
        setActiveDemo(project);
        setDemoError(null);
        setDemoLogs('');
        try {
            const response = await fetch(`/showcase/projects/${project.id}/start`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });
            const data = await response.json();
            if (!data.success) {
                setDemoError(data.error || 'Error al iniciar el contenedor de demostración.');
                setDemoLogs(data.logs || 'No hay bitácoras disponibles del contenedor.');
            }
        } catch (error) {
            console.error('Error starting demo:', error);
            setDemoError('Error de red al intentar encender el contenedor del estudiante.');
            setDemoLogs('Por favor, comprueba si el backend y Docker están funcionando correctamente.');
        } finally {
            setWakingUp(false);
        }
    };

    const handleCloseDemo = async (project) => {
        setActiveDemo(null);
        setDemoError(null);
        setDemoLogs('');
        try {
            await fetch(`/showcase/projects/${project.id}/stop`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                }
            });
        } catch (error) {
            console.error('Error stopping demo container:', error);
        }
    };

    // Form for recruiter contact
    const { data, setData, post, processing, reset, errors, recentlySuccessful } = useForm({
        name: '',
        email: '',
        company: '',
        message: ''
    });

    // Filter projects based on search and selected language
    const filteredProjects = useMemo(() => {
        return projects.filter(project => {
            const matchesSearch = project.name.toLowerCase().includes(search.toLowerCase()) ||
                project.user.name.toLowerCase().includes(search.toLowerCase()) ||
                (project.user.profile?.skills && project.user.profile.skills.some(skill => skill.toLowerCase().includes(search.toLowerCase())));

            const matchesLanguage = selectedLanguage === 'all' || project.language === selectedLanguage;

            return matchesSearch && matchesLanguage;
        });
    }, [projects, search, selectedLanguage]);

    const [isContactSent, setIsContactSent] = useState(false);

    const handleContactSubmit = (e) => {
        e.preventDefault();
        post(route('student.contact', contactStudent.user_id), {
            preserveScroll: true,
            onSuccess: () => {
                setIsContactSent(true);
                setTimeout(() => {
                    setContactStudent(null);
                    setIsContactSent(false);
                    reset();
                }, 1600);
            }
        });
    };

    return (
        <>
            <Head title="Showcase de Proyectos" />
            <div className="min-h-screen bg-slate-950 text-slate-100 selection:bg-cyan-500 selection:text-slate-900 font-sans">
                {/* Header/Navbar */}
                <header className="sticky top-0 z-40 w-full border-b border-slate-900 bg-slate-950/80 backdrop-blur-md">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                        <div className="flex items-center space-x-3">
                            <ApplicationLogo className="w-10 h-10 object-contain rounded-xl" />
                            <span className="text-xl font-bold bg-gradient-to-r from-cyan-400 to-indigo-500 bg-clip-text text-transparent">
                                ULEAM Academic
                            </span>
                        </div>

                        <nav className="flex items-center space-x-4">
                            {!auth.user && (
                                <button
                                    onClick={() => setTheme(theme === 'spatial' ? 'academic' : 'spatial')}
                                    className="p-1.5 rounded-lg transition duration-150 bg-slate-900/60 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800/40 flex items-center justify-center"
                                    title="Cambiar tema"
                                >
                                    {theme === 'spatial' ? (
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 9H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                        </svg>
                                    ) : (
                                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                        </svg>
                                    )}
                                </button>
                            )}

                            {auth.user ? (
                                <div className="flex items-center space-x-3">
                                    <Link
                                        href="/"
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 text-white transition duration-150"
                                    >
                                        Home
                                    </Link>
                                    <Link
                                        href={route('dashboard')}
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-400 hover:text-slate-200 hover:bg-slate-900/40 transition duration-150"
                                    >
                                        Mis Proyectos
                                    </Link>
                                    <Link
                                        href={route('profile.professional')}
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold text-slate-400 hover:text-slate-200 hover:bg-slate-900/40 transition duration-150"
                                    >
                                        Perfil
                                    </Link>
                                    <Link
                                        href={route('messages.index')}
                                        className="relative px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center space-x-1.5 text-slate-400 hover:text-slate-200 hover:bg-slate-900/40"
                                    >

                                        <span>Mensajes</span>
                                        {auth?.unreadMessagesCount > 0 && (
                                            <span className="flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-gradient-to-r from-cyan-500 to-indigo-500 px-1 text-[10px] font-extrabold text-slate-950 shadow-sm animate-pulse">
                                                {auth.unreadMessagesCount}
                                            </span>
                                        )}
                                    </Link>
                                    <button
                                        onClick={() => setTheme(theme === 'spatial' ? 'academic' : 'spatial')}
                                        className="p-1.5 rounded-lg transition duration-150 bg-slate-900/60 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800/40 flex items-center justify-center"
                                        title="Cambiar tema"
                                    >
                                        {theme === 'spatial' ? (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 9H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                            </svg>
                                        ) : (
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                                            </svg>
                                        )}
                                    </button>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold text-red-400 hover:text-red-300 hover:bg-red-950/20 transition duration-150"
                                    >
                                        Cerrar Sesión
                                    </Link>
                                </div>
                            ) : (
                                <>
                                    <Link
                                        href={route('login')}
                                        className="text-sm font-semibold text-slate-400 hover:text-slate-200 transition"
                                    >
                                        Iniciar Sesión
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="px-4 py-2 rounded-xl text-sm font-semibold bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 font-bold shadow-lg shadow-cyan-500/10 transition duration-200"
                                    >
                                        Registrarme
                                    </Link>
                                </>
                            )}
                        </nav>
                    </div>
                </header>

                {/* Hero Section */}
                <section className="relative overflow-hidden pt-20 pb-16 border-b border-slate-900">
                    {/* Background glows */}
                    <div className="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] rounded-full bg-cyan-500/5 blur-[120px] pointer-events-none" />
                    <div className="absolute top-1/3 left-1/3 w-[300px] h-[300px] rounded-full bg-indigo-500/5 blur-[100px] pointer-events-none" />

                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative z-10">


                        <h1 className="text-4xl sm:text-6xl font-extrabold tracking-tight text-white mb-6">
                            Proyectos y Aplicaciones <br />
                            <span className="bg-gradient-to-r from-cyan-400 via-sky-400 to-indigo-400 bg-clip-text text-transparent">
                                de Estudiantes ULEAM
                            </span>
                        </h1>

                        <p className="max-w-2xl mx-auto text-base sm:text-lg text-slate-400 mb-10">
                            Explora y prueba en vivo las aplicaciones web creadas por los estudiantes. Puedes interactuar con ellas directamente en tu navegador.
                        </p>

                        {/* Search and Filters */}
                        <div className="max-w-3xl mx-auto p-2.5 rounded-2xl border border-slate-900 bg-slate-900/40 backdrop-blur-md flex flex-col md:flex-row gap-3 shadow-xl">
                            <div className="flex-1 relative">
                                <span className="absolute left-4 top-3.5 text-slate-500">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </span>
                                <input
                                    type="text"
                                    placeholder="Buscar por nombre, estudiante o tecnología (ej: React)..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-full pl-11 pr-4 py-3 bg-slate-950 border border-slate-900 focus:border-cyan-500 focus:ring-0 rounded-xl text-slate-200 placeholder-slate-500 transition duration-200"
                                />
                            </div>

                            <div className="flex gap-2">
                                <select
                                    value={selectedLanguage}
                                    onChange={(e) => setSelectedLanguage(e.target.value)}
                                    className="px-4 py-3 bg-slate-950 border border-slate-900 focus:border-cyan-500 focus:ring-0 rounded-xl text-slate-300 font-semibold transition"
                                >
                                    <option value="all">Todos los Lenguajes</option>
                                    <option value="nodejs">Node.js (React/Express)</option>
                                    <option value="php">PHP (Laravel/Vanilla)</option>
                                    <option value="python">Python (Flask/Django)</option>
                                </select>
                            </div>
                        </div>

                        {/* Information Banner for Recruiters */}
                        <div className="max-w-3xl mx-auto mt-8 bg-indigo-500/10 border border-indigo-500/20 p-4 rounded-xl text-left flex items-start space-x-3 text-xs leading-relaxed text-slate-400">
                            <span className="text-base"></span>
                            <div>
                                <p className="font-bold text-slate-200 mb-1">¿Cómo probar los proyectos?</p>
                                <ul className="list-disc list-inside space-y-1">
                                    <li>Haz clic en <strong>"Ejecutar Demo"</strong> para abrir la aplicación y probarla en tiempo real.</li>
                                    <li>Puedes interactuar con total libertad y llenar formularios de prueba.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </section>

                {/* Projects Catalog Grid */}
                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                    <div className="flex items-center justify-between mb-8">
                        <h2 className="text-2xl font-bold text-white flex items-center gap-2">
                            Proyectos Recientes ({filteredProjects.length})
                        </h2>
                    </div>

                    {filteredProjects.length === 0 ? (
                        <div className="text-center py-16 border border-dashed border-slate-900 rounded-2xl bg-slate-900/10">
                            <h3 className="text-lg font-semibold text-slate-300">No se encontraron proyectos</h3>
                            <p className="text-slate-500 mt-2">Intenta ajustar tu búsqueda o el filtro de lenguajes.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {filteredProjects.map((project) => (
                                <div
                                    key={project.id}
                                    className="group relative rounded-2xl bg-slate-900/60 hover:bg-slate-900/90 p-6 shadow-xl shadow-black/30 backdrop-blur-sm transition-all duration-300 hover:-translate-y-1 border-0 flex flex-col justify-between"
                                >
                                    {/* Project Language Badge */}
                                    <div className="flex justify-start items-center mb-4">
                                        <span className={`px-2.5 py-0.5 rounded-md text-[11px] font-mono font-semibold uppercase tracking-wider border-0 ${project.language === 'nodejs'
                                            ? 'bg-emerald-500/10 text-emerald-300'
                                            : project.language === 'php'
                                                ? 'bg-violet-500/10 text-violet-300'
                                                : 'bg-amber-500/10 text-amber-300'
                                            }`}>
                                            {project.language || 'Sin compilar'}
                                        </span>
                                    </div>

                                    <div>
                                        <h3 className="text-lg font-bold text-white group-hover:text-cyan-300 transition-colors mb-2">
                                            {project.name}
                                        </h3>
                                        <p className="text-xs text-slate-400 line-clamp-2 mb-4 leading-relaxed">
                                            <span className="text-slate-500">Repositorio: </span>
                                            {(!project.github_repo_url ||
                                                project.github_repo_url === 'Subido localmente' ||
                                                project.github_repo_url.toLowerCase().includes('local') ||
                                                !project.github_repo_url.startsWith('http')) ? (
                                                <span className="text-slate-400 font-medium">Subido localmente</span>
                                            ) : (
                                                <a
                                                    href={project.github_repo_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="hover:underline text-indigo-400 hover:text-indigo-300 font-mono text-[11px]"
                                                >
                                                    {project.github_repo_url.replace('https://github.com/', '')}
                                                </a>
                                            )}
                                        </p>
                                    </div>

                                    {/* Student Info */}
                                    <div className="pt-4 mt-4 flex items-center justify-between border-0">
                                        <div className="flex items-center space-x-3">
                                            <div className="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-600 to-cyan-500 text-white flex items-center justify-center font-bold text-xs shadow-sm uppercase border-0">
                                                {project.user.name[0]}
                                            </div>
                                            <div>
                                                <p className="text-[10px] text-slate-500 uppercase tracking-wider font-semibold">Desarrollado por</p>
                                                <p className="text-xs font-semibold text-slate-200">{project.user.name}</p>
                                            </div>
                                        </div>

                                        <div className="flex gap-2">
                                            {/* Contact Button */}
                                            {(!auth.user || auth.user.id !== project.user_id) && (
                                                <button
                                                    onClick={() => setContactStudent(project)}
                                                    className="p-2 px-3 rounded-xl bg-slate-950/80 hover:bg-slate-800 text-slate-300 hover:text-white transition text-xs font-semibold flex items-center gap-1.5 cursor-pointer border-0"
                                                    title="Contactar Estudiante"
                                                >
                                                    <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L22 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                    </svg>
                                                    <span>Contacto</span>
                                                </button>
                                            )}

                                            {/* Run Demo Button */}
                                            {(project.status === 'running' || project.status === 'sleeping') && (
                                                <button
                                                    onClick={() => handleStartDemo(project)}
                                                    className="px-3.5 py-2 rounded-xl bg-gradient-to-r from-cyan-500 via-indigo-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-white font-bold text-xs shadow-md shadow-cyan-500/10 hover:shadow-indigo-500/20 transition-all duration-200 cursor-pointer border-0"
                                                >
                                                    Ejecutar demo
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </main>

                {/* Footer */}
                <footer className="border-t border-slate-900 bg-slate-950 py-12">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm text-slate-500">
                        <p>© 2026 ULEAM Academic</p>
                    </div>
                </footer>

                {/* Live Demo Iframe Modal */}
                {activeDemo && (
                    <div className="fixed inset-0 z-50 bg-slate-950/90 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="w-full max-w-6xl h-[85vh] bg-slate-900 border border-slate-800 rounded-3xl overflow-hidden flex flex-col shadow-2xl">
                            {/* Modal Header */}
                            <div className="px-6 py-4 bg-slate-950 border-b border-slate-800 flex items-center justify-between">
                                <div className="flex items-center space-x-3">
                                    <div>
                                        <h3 className="font-bold text-white text-lg">{activeDemo.name}</h3>
                                        <p className="text-xs text-slate-400 flex items-center gap-1.5">
                                            <span>Estudiante: {activeDemo.user.name}</span>
                                            <span>•</span>
                                            <a
                                                href={`http://${activeDemo.subdomain}.localhost`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-cyan-400 font-mono underline hover:text-cyan-300 flex items-center gap-1"
                                                title="Abrir en pestaña nueva"
                                            >
                                                <span>http://{activeDemo.subdomain}.localhost</span>
                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </a>
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <a
                                        href={`http://${activeDemo.subdomain}.localhost`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-cyan-500/15 border border-cyan-500/30 text-cyan-300 hover:bg-cyan-500/25 transition duration-150 flex items-center gap-1.5"
                                    >
                                        <span>Abrir en nueva pestaña</span>
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>
                                    <button
                                        onClick={() => handleCloseDemo(activeDemo)}
                                        className="px-4 py-2 hover:bg-slate-800 rounded-xl text-slate-400 hover:text-white transition text-sm font-semibold"
                                    >
                                        Cerrar
                                    </button>
                                </div>
                            </div>

                            {/* Sandbox Warning Banner */}
                            <div className="px-6 py-2 bg-indigo-500/10 border-b border-indigo-500/20 text-xs text-indigo-400 flex items-center gap-2">
                                <span>Ejecución Aislada bajo gVisor. Se prohíbe el ingreso de información sensible o credenciales reales.</span>
                            </div>

                            {/* Demo IFrame / Loading Spinner / Error Terminal */}
                            <div className="flex-1 bg-slate-950 relative flex flex-row min-h-0">
                                {/* Left side: Iframe or spinner or error */}
                                <div className="flex-1 min-w-0 h-full relative">
                                    {wakingUp ? (
                                        <DemoLoadingAnimation
                                            projectName={activeDemo?.name}
                                            subdomain={activeDemo?.subdomain}
                                        />
                                    ) : demoError ? (
                                        <div className="w-full h-full flex flex-col p-8 bg-slate-950 text-slate-300 font-mono overflow-y-auto">
                                            <div className="flex items-center space-x-2 text-red-500 font-bold text-lg mb-4 border-b border-red-950/40 pb-2">
                                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                                </svg>
                                                <span>{demoError}</span>
                                            </div>
                                            <div className="flex-1 bg-slate-900 border border-slate-800 rounded-xl p-6 overflow-auto shadow-inner text-xs leading-relaxed text-red-400">
                                                <div className="text-slate-500 font-bold mb-2">=== BITÁCORA DEL CONTENEDOR (DOCKER LOGS) ===</div>
                                                <pre className="whitespace-pre-wrap">{demoLogs}</pre>
                                            </div>
                                            <div className="mt-4 text-center">
                                                <button
                                                    onClick={() => handleCloseDemo(activeDemo)}
                                                    className="px-5 py-2.5 bg-red-600 hover:bg-red-500 text-white rounded-xl font-bold text-xs shadow-md transition"
                                                >
                                                    Cerrar Ventana de Error
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <iframe
                                            src={`http://${activeDemo.subdomain}.localhost`}
                                            className="w-full h-full border-none bg-white"
                                            title="Student Live Project Demo"
                                        />
                                    )}
                                </div>

                                {/* Right side: Sidebar instructions (only when loaded successfully) */}
                                {!wakingUp && !demoError && (
                                    <div className="w-72 border-l border-slate-800 bg-slate-900/40 p-5 flex flex-col justify-between shrink-0 hidden md:flex">
                                        <div className="space-y-5">
                                            <div className="flex items-center space-x-2 text-indigo-400">
                                                <svg className="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                                </svg>
                                                <h4 className="font-bold text-xs tracking-wider uppercase text-indigo-300">Guía del Evaluador</h4>
                                            </div>

                                            {activeDemo.demo_instructions ? (
                                                <div className="space-y-4">
                                                    <div className="p-3 bg-slate-950 border border-slate-850 rounded-xl text-xs space-y-2.5">
                                                        <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wide block border-b border-slate-900 pb-1">Instrucciones del Alumno</span>
                                                        <p className="text-slate-300 whitespace-pre-wrap leading-relaxed text-[11px]">
                                                            {activeDemo.demo_instructions}
                                                        </p>
                                                    </div>

                                                    <div className="text-[10px] text-slate-500 leading-relaxed bg-slate-950 p-2.5 rounded-lg border border-slate-850">
                                                        Los datos agregados se eliminarán automáticamente al hacer clic en <strong>Cerrar</strong>.
                                                    </div>
                                                </div>
                                            ) : (() => {
                                                const creds = getDemoCredentials(activeDemo);
                                                return (
                                                    <div className="space-y-4">
                                                        <div className="p-3 bg-slate-950 border border-slate-850 rounded-xl">
                                                            <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wide">Acceso de Pruebas</span>
                                                            <div className="mt-1.5 space-y-1 text-xs">
                                                                <div className="flex justify-between">
                                                                    <span className="text-slate-400">Usuario:</span>
                                                                    <span className="font-mono text-cyan-400 select-all font-bold">{creds.user}</span>
                                                                </div>
                                                                <div className="flex justify-between">
                                                                    <span className="text-slate-400">Clave:</span>
                                                                    <span className="font-mono text-cyan-400 select-all font-bold">{creds.pass}</span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div className="text-xs space-y-2 leading-relaxed">
                                                            <p className="font-semibold text-slate-200">Recomendación:</p>
                                                            <p className="text-[11px] text-slate-400">{creds.note}</p>
                                                        </div>

                                                        {/* Botón de apertura en nueva pestaña / Pantalla completa */}
                                                        <a
                                                            href={`http://${activeDemo.subdomain}.localhost`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="w-full py-2.5 px-3 rounded-xl text-xs font-bold text-center flex items-center justify-center gap-2 bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 shadow-md shadow-cyan-500/20 transition duration-200 cursor-pointer"
                                                        >
                                                            <span>Abrir en Pantalla Completa</span>
                                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                            </svg>
                                                        </a>

                                                        <div className="text-[10px] text-slate-400 leading-relaxed bg-slate-950 p-2.5 rounded-lg border border-slate-800 space-y-1">
                                                            <p className="text-indigo-300 font-semibold flex items-center gap-1">
                                                                <span></span> <span>Tip de Autenticación</span>
                                                            </p>
                                                            <p>Para iniciar sesión y navegar con total compatibilidad de cookies, recomendamos usar el botón <strong>Abrir en Pantalla Completa</strong>.</p>
                                                        </div>

                                                        <div className="text-[10px] text-slate-500 leading-relaxed bg-slate-950/60 p-2 rounded-lg border border-slate-900">
                                                            Los datos agregados se eliminarán automáticamente al hacer clic en <strong>Cerrar</strong>.
                                                        </div>
                                                    </div>
                                                );
                                            })()}
                                        </div>

                                        <div className="pt-3 border-t border-slate-850/50 text-center">
                                            <span className="text-[10px] text-slate-600 font-mono">ULEAM Academic PaaS v1.2</span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Recruiter Contact Modal */}
                {contactStudent && (
                    <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                        <div className="w-full max-w-lg bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl">
                            <div className="flex justify-between items-start mb-6">
                                <div>
                                    <h3 className="text-xl font-bold text-white">Contactar con {contactStudent.user.name}</h3>
                                    <p className="text-sm text-slate-400 mt-1">Envía una propuesta de empleo o consulta sobre su proyecto.</p>
                                </div>
                                <button
                                    onClick={() => setContactStudent(null)}
                                    className="p-1 px-2 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            {isContactSent ? (
                                <div className="p-8 rounded-2xl bg-gradient-to-b from-green-500/10 to-slate-900 border border-green-500/30 text-green-400 text-center space-y-3 animate-fadeIn">
                                    <div className="w-12 h-12 mx-auto rounded-full bg-green-500/20 border border-green-500/40 flex items-center justify-center text-green-400 shadow-lg shadow-green-500/10">
                                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                    <p className="text-base font-bold text-white">¡Mensaje enviado con éxito!</p>
                                    <p className="text-xs text-slate-400 max-w-xs mx-auto">El estudiante ha recibido tu mensaje y podrá responderte a tu correo.</p>
                                </div>
                            ) : (
                                <form onSubmit={handleContactSubmit} className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Tu Nombre</label>
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            required
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-600 transition"
                                            placeholder="Ingresa tu nombre..."
                                        />
                                        {errors.name && <p className="text-xs text-red-400 mt-1">{errors.name}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Correo Corporativo</label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            required
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-600 transition"
                                            placeholder="ejemplo@empresa.com"
                                        />
                                        {errors.email && <p className="text-xs text-red-400 mt-1">{errors.email}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Empresa</label>
                                        <input
                                            type="text"
                                            value={data.company}
                                            onChange={(e) => setData('company', e.target.value)}
                                            required
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-600 transition"
                                            placeholder="Nombre de la empresa..."
                                        />
                                        {errors.company && <p className="text-xs text-red-400 mt-1">{errors.company}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Mensaje</label>
                                        <textarea
                                            value={data.message}
                                            onChange={(e) => setData('message', e.target.value)}
                                            required
                                            rows="4"
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-600 transition"
                                            placeholder="Hola, nos llamó la atención tu proyecto y nos gustaría agendar una entrevista técnica..."
                                        />
                                        {errors.message && <p className="text-xs text-red-400 mt-1">{errors.message}</p>}
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-500 to-indigo-500 hover:from-cyan-400 hover:to-indigo-400 text-slate-950 font-bold shadow-lg shadow-cyan-500/10 transition disabled:opacity-50"
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

// === AUXILIARY SHOWCASE HELPER FUNCTIONS ===

function getDemoCredentials(project) {
    const name = project.name.toLowerCase();
    if (name.includes('tienda') || name.includes('sequelize')) {
        return {
            user: 'admin@example.com',
            pass: 'password',
            note: 'Inicia sesión en la demo con estas credenciales para acceder al panel de administrador y gestionar registros.'
        };
    }
    if (name.includes('mongo')) {
        return {
            user: '(Ingreso libre)',
            pass: '(Sin autenticación)',
            note: 'Este proyecto usa base de datos MongoDB (NoSQL). No requiere inicio de sesión; usa el formulario directamente para insertar usuarios en la base de datos.'
        };
    }
    return {
        user: 'admin@example.com',
        pass: 'password',
        note: 'Usa estas credenciales predeterminadas para iniciar sesión y evaluar las funciones del sistema.'
    };
}
