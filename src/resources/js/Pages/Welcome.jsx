import { useState, useMemo, useEffect, useRef } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import ApplicationLogo from '@/Components/ApplicationLogo';
import DemoLoadingAnimation from '@/Components/DemoLoadingAnimation';

export const PROJECT_CATEGORIES = [
    'Finanzas y Facturación',
    'Comercio Electrónico y Tiendas',
    'Herramientas y Calculadoras',
    'Educación y Gestión Académica',
    'Salud y Medicina',
    'Gestión Empresarial (ERP / CRM)',
    'Redes Sociales y Comunidad',
    'Inteligencia Artificial y Datos',
    'Turismo y Hotelería',
    'Logística y Transporte',
    'Entretenimiento y Multimedia',
    'Otros / General'
];

export const HORIZONTAL_CATEGORIES = [
    {
        id: 'all',
        name: 'Todas las Categorías',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
            </svg>
        )
    },
    {
        id: 'Comercio Electrónico y Tiendas',
        name: 'Comercio Electrónico',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
        )
    },
    {
        id: 'Educación y Gestión Académica',
        name: 'Educación',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222" />
            </svg>
        )
    },
    {
        id: 'Gestión Empresarial (ERP / CRM)',
        name: 'Gestión Empresarial',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
        )
    },
    {
        id: 'Redes Sociales y Comunidad',
        name: 'Redes Sociales',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
            </svg>
        )
    },
    {
        id: 'Salud y Medicina',
        name: 'Salud y Medicina',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
            </svg>
        )
    },
    {
        id: 'Finanzas y Facturación',
        name: 'Finanzas y Facturación',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
        )
    },
    {
        id: 'Herramientas y Calculadoras',
        name: 'Herramientas',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
        )
    },
    {
        id: 'Inteligencia Artificial y Datos',
        name: 'IA y Datos',
        icon: (
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
        )
    }
];

export default function Welcome({ auth, projects = [] }) {
    const [search, setSearch] = useState('');
    const [selectedLanguage, setSelectedLanguage] = useState('all');
    const [selectedCategory, setSelectedCategory] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);
    const [isCategorySidebarOpen, setIsCategorySidebarOpen] = useState(true);

    const [activeDemo, setActiveDemo] = useState(null);
    const [contactStudent, setContactStudent] = useState(null);
    const [wakingUp, setWakingUp] = useState(false);
    const [demoError, setDemoError] = useState(null);
    const [demoLogs, setDemoLogs] = useState('');
    const [demoKey, setDemoKey] = useState(Date.now());
    const [iframeLoaded, setIframeLoaded] = useState(false);

    // Tema de la plataforma
    const [theme, setTheme] = useState(localStorage.getItem('theme') || 'academic');
    const [isContactSent, setIsContactSent] = useState(false);

    const categoryScrollRef = useRef(null);

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

    const scrollCategoriesRight = () => {
        if (categoryScrollRef.current) {
            categoryScrollRef.current.scrollBy({ left: 240, behavior: 'smooth' });
        }
    };

    // Inicio / Despertado del Contenedor con Traefik
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
            const data = await response.json();
            if (!data.success) {
                setDemoError(data.error || 'Error al iniciar el contenedor de demostración.');
                setDemoLogs(data.logs || 'No hay bitácoras disponibles del contenedor.');
            } else {
                setDemoKey(Date.now());
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
        setIframeLoaded(false);
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

    // Formulario de Contacto Reclutador / Empresa
    const { data, setData, post, processing, reset, errors } = useForm({
        name: '',
        email: '',
        company: '',
        message: ''
    });

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

    // Recuento dinámico de proyectos por categoría
    const categoryCounts = useMemo(() => {
        const counts = {};
        projects.forEach(p => {
            const cat = p.category || 'Herramientas y Calculadoras';
            counts[cat] = (counts[cat] || 0) + 1;
        });
        return counts;
    }, [projects]);

    // Filtrado de proyectos por texto, lenguaje y categoría de software
    const filteredProjects = useMemo(() => {
        return projects.filter(project => {
            const query = search.toLowerCase().trim();
            const projectCat = project.category || 'Herramientas y Calculadoras';

            const matchesSearch = !query ||
                project.name.toLowerCase().includes(query) ||
                projectCat.toLowerCase().includes(query) ||
                project.user.name.toLowerCase().includes(query) ||
                (project.subdomain && project.subdomain.toLowerCase().includes(query)) ||
                (project.user.profile?.skills && project.user.profile.skills.some(s => s.toLowerCase().includes(query)));

            const matchesLanguage = selectedLanguage === 'all' || project.language === selectedLanguage;
            const matchesCategory = selectedCategory === 'all' || projectCat === selectedCategory;

            return matchesSearch && matchesLanguage && matchesCategory;
        });
    }, [projects, search, selectedLanguage, selectedCategory]);

    // Paginación (6 por página para cuadrícula de 3 columnas perfecta)
    const PROJECTS_PER_PAGE = 6;

    useEffect(() => {
        setCurrentPage(1);
    }, [search, selectedLanguage, selectedCategory]);

    const totalPages = Math.ceil(filteredProjects.length / PROJECTS_PER_PAGE) || 1;

    const paginatedProjects = useMemo(() => {
        const startIndex = (currentPage - 1) * PROJECTS_PER_PAGE;
        return filteredProjects.slice(startIndex, startIndex + PROJECTS_PER_PAGE);
    }, [filteredProjects, currentPage]);

    return (
        <>
            <Head title="Galería de Innovación Académica - Nexus Academic" />
            <div className="min-h-screen bg-[#f3f5f9] dark:bg-[#090d16] text-[#1e293b] dark:text-[#f1f5f9] font-sans antialiased selection:bg-blue-600 selection:text-white transition-colors duration-200">

                {/* =========================================================================
                    1. CABECERA LIMPIA Y CENTRADA CON LOGO PRINCIPAL
                    ========================================================================= */}
                <header className="sticky top-0 z-40 w-full bg-white dark:bg-[#0b0f19] border-b border-slate-200 dark:border-slate-800 shadow-xs transition-colors duration-200">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">

                        {/* Logo del sistema y nombre */}
                        <Link href="/" className="flex items-center gap-2.5 shrink-0 group">
                            <ApplicationLogo className="h-8 w-auto object-contain transition group-hover:scale-105" />
                            <div className="flex items-center">
                                <span className="brand-nexus text-xl font-black tracking-tight text-blue-700 dark:text-blue-500">nexus</span>
                                <span className="brand-academic text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">academic</span>
                            </div>
                        </Link>

                        {/* Botones de Cabecera: Alternador de tema + Auth */}
                        <div className="flex items-center gap-3">
                            {/* Alternador de tema */}
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

                            {auth.user ? (
                                <div className="flex items-center gap-2">
                                    <Link
                                        href={route('dashboard')}
                                        className="px-3 py-1.5 rounded-full text-xs font-bold text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                    >
                                        Mis Proyectos
                                    </Link>
                                    <Link
                                        href={route('profile.professional')}
                                        className="px-3 py-1.5 rounded-full text-xs font-bold text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition"
                                    >
                                        Perfil
                                    </Link>
                                    <Link
                                        href={route('messages.index')}
                                        className="relative px-3 py-1.5 rounded-full text-xs font-bold text-slate-700 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition flex items-center gap-1"
                                    >
                                        <span>Mensajes</span>
                                        {auth?.unreadMessagesCount > 0 && (
                                            <span className="flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-extrabold text-white">
                                                {auth.unreadMessagesCount}
                                            </span>
                                        )}
                                    </Link>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="px-3 py-1.5 rounded-full text-xs font-bold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 transition"
                                    >
                                        Salir
                                    </Link>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2.5">
                                    <Link
                                        href={route('register')}
                                        className="text-xs font-bold text-slate-700 dark:text-slate-200 hover:text-blue-600 dark:hover:text-blue-400 px-2 py-1 transition"
                                    >
                                        Crear cuenta
                                    </Link>
                                    <Link
                                        href={route('login')}
                                        className="text-xs font-bold text-slate-700 dark:text-slate-200 hover:text-blue-600 dark:hover:text-blue-400 px-2 py-1 transition"
                                    >
                                        Ingresar
                                    </Link>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-7 pb-16">

                    {/* =========================================================================
                        2. TÍTULO PRINCIPAL + BARRA DE BÚSQUEDA Y FILTRO DE LENGUAJE
                        ========================================================================= */}
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                        {/* Título de la galería */}
                        <div>
                            <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight font-serif">
                                Galería de Innovación Académica
                            </h1>
                        </div>

                        {/* Buscador y selector de lenguajes unificados */}
                        <div className="flex items-center gap-3 w-full md:w-auto">
                            <div className="flex items-center w-full md:w-96 bg-white dark:bg-[#0f172a] border border-slate-300 dark:border-slate-700 hover:border-blue-500 focus-within:border-blue-600 rounded-full py-1.5 px-3 shadow-2xs transition">
                                <svg className="w-4 h-4 text-slate-400 mr-2 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar proyectos, estudiantes, keywords..."
                                    className="w-full bg-transparent border-none text-xs sm:text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 focus:outline-none focus:ring-0 p-1"
                                />
                                {search && (
                                    <button
                                        onClick={() => setSearch('')}
                                        className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xs px-1"
                                    >
                                        ✕
                                    </button>
                                )}
                            </div>

                            {/* Dropdown de lenguajes */}
                            <div className="relative shrink-0">
                                <select
                                    value={selectedLanguage}
                                    onChange={(e) => setSelectedLanguage(e.target.value)}
                                    className="bg-white dark:bg-[#0f172a] border border-slate-300 dark:border-slate-700 text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-200 rounded-full py-2 pl-4 pr-9 shadow-2xs hover:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-600 cursor-pointer appearance-none transition"
                                >
                                    <option value="all">Todos los lenguajes</option>
                                    <option value="php">PHP / Laravel</option>
                                    <option value="nodejs">Node.js / React</option>
                                    <option value="python">Python / Django</option>
                                    <option value="java">Java / Spring Boot</option>
                                    <option value="dotnet">.NET / C#</option>
                                    <option value="dockerfile">Contenedor Docker</option>
                                </select>
                                <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-slate-500">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* =========================================================================
                        3. BARRA HORIZONTAL DE CATEGORÍAS (PILLS CON ICONOS)
                        ========================================================================= */}
                    <div className="relative flex items-center gap-2 mb-8">
                        <div
                            ref={categoryScrollRef}
                            className="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 scroll-smooth pr-10"
                            style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
                        >
                            {HORIZONTAL_CATEGORIES.map((catItem) => {
                                const isActive = selectedCategory === catItem.id;
                                return (
                                    <button
                                        key={catItem.id}
                                        onClick={() => setSelectedCategory(catItem.id)}
                                        className={`flex items-center gap-2 px-4 py-2 rounded-full text-xs font-bold transition-all shrink-0 cursor-pointer shadow-2xs ${isActive
                                            ? 'bg-[#1534e8] text-white shadow-xs scale-[1.02]'
                                            : 'bg-white dark:bg-[#0f172a] text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 hover:border-blue-400 hover:bg-slate-50 dark:hover:bg-slate-800/60'
                                            }`}
                                    >
                                        <span className={isActive ? 'text-white' : 'text-slate-600 dark:text-slate-300'}>
                                            {catItem.icon}
                                        </span>
                                        <span>{catItem.name}</span>
                                    </button>
                                );
                            })}
                        </div>

                        {/* Botón de desplazamiento hacia la derecha */}
                        <button
                            onClick={scrollCategoriesRight}
                            className="shrink-0 p-2 rounded-full bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 shadow-xs text-slate-600 dark:text-slate-300 hover:text-blue-600 hover:border-blue-400 transition cursor-pointer"
                            title="Ver más categorías"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>

                    {/* =========================================================================
                        4. CUERPO PRINCIPAL: CUADRÍCULA DE PROYECTOS + BARRA LATERAL DERECHA
                        ========================================================================= */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-7 items-start">

                        {/* ---------------------------------------------------------------------
                            COLUMNA PRINCIPAL (IZQUIERDA/CENTRO): TARJETAS DE PROYECTOS
                            --------------------------------------------------------------------- */}
                        <div className="lg:col-span-8 xl:col-span-9 space-y-6">
                            {filteredProjects.length === 0 ? (
                                <div className="bg-white dark:bg-[#0f172a] rounded-3xl border border-slate-200 dark:border-slate-800 p-12 text-center shadow-xs">
                                    <div className="w-14 h-14 mx-auto rounded-full bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-800 flex items-center justify-center text-blue-600 dark:text-blue-400 mb-3">
                                        <svg className="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-white mb-1">
                                        No se encontraron proyectos
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mb-4 max-w-sm mx-auto">
                                        No existen proyectos que coincidan con la búsqueda actual o la categoría seleccionada.
                                    </p>
                                    <button
                                        onClick={() => { setSearch(''); setSelectedLanguage('all'); setSelectedCategory('all'); }}
                                        className="px-5 py-2 bg-[#1534e8] hover:bg-blue-700 text-white rounded-full text-xs font-bold transition cursor-pointer shadow-xs"
                                    >
                                        Restablecer filtros
                                    </button>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
                                    {paginatedProjects.map((project, idx) => {
                                        const tech = getTechInfo(project.language);
                                        const isFeatured = idx === 0 || project.status === 'running';

                                        return (
                                            <div
                                                key={project.id}
                                                className="group bg-white dark:bg-[#0f172a] rounded-[2rem] border border-slate-200 dark:border-slate-800 shadow-xs hover:shadow-xl hover:border-blue-400/60 dark:hover:border-blue-500/50 transition-all duration-300 flex flex-col justify-between relative overflow-hidden"
                                            >
                                                {/* Badge superior de stack tecnológico centrado */}
                                                <div className="absolute top-2.5 left-1/2 -translate-x-1/2 z-10">
                                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-extrabold tracking-tight bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-800 dark:text-slate-100 shadow-xs">
                                                        <span className={`w-2 h-2 rounded-full ${tech.dotColor}`}></span>
                                                        <span>{tech.pillName}</span>
                                                    </span>
                                                </div>

                                                {/* Badge DESTACADO en esquina superior derecha */}
                                                {isFeatured && (
                                                    <div className="absolute top-3 right-3 z-10">
                                                        <span className="bg-amber-500 text-white text-[9px] font-black px-2 py-0.5 rounded-full uppercase tracking-wider shadow-2xs">
                                                            DESTACADO
                                                        </span>
                                                    </div>
                                                )}

                                                {/* Cabecera de la tarjeta con arco curvo decorativo y logos visuales */}
                                                <div className="relative pt-10 pb-4 px-4 flex items-center justify-center overflow-hidden">
                                                    {/* Arco de fondo estilizado */}
                                                    <div className="absolute inset-x-3 top-2 bottom-0 rounded-t-[2rem] bg-gradient-to-b from-amber-500/10 via-amber-400/5 to-transparent dark:from-blue-900/20 dark:via-slate-800/30 dark:to-transparent pointer-events-none border-t border-x border-amber-300/30 dark:border-slate-700/40"></div>

                                                    {/* Logos de tecnologías grandes y limpios */}
                                                    <div className="relative z-10 py-3 transition-transform group-hover:scale-105 duration-200">
                                                        {tech.cardVisual}
                                                    </div>
                                                </div>

                                                {/* Contenido Central: Título, Categoría, Estudiante y Resumen */}
                                                <div className="px-5 pb-3 text-center flex-1 flex flex-col justify-between">
                                                    <div>
                                                        <h3 className="font-extrabold text-slate-900 dark:text-white text-base group-hover:text-blue-600 dark:group-hover:text-blue-400 transition truncate mb-1">
                                                            {project.name}
                                                        </h3>

                                                        {/* Píldora de Categoría */}
                                                        <div className="mb-2">
                                                            <span className="inline-block text-[10px] font-bold text-emerald-800 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-full px-2.5 py-0.5">
                                                                {project.category || 'Herramientas y Calculadoras'}
                                                            </span>
                                                        </div>

                                                        {/* Autor Estudiante */}
                                                        <p className="text-xs text-slate-500 dark:text-slate-400 font-medium mb-1">
                                                            Estudiante: <strong className="text-slate-800 dark:text-slate-200 font-bold">{project.user.name}</strong>
                                                        </p>

                                                        {/* Resumen del stack */}
                                                        <p className="text-[11px] text-slate-500 dark:text-slate-400 font-medium truncate mb-2">
                                                            {project.category || 'General'} • {tech.shortInfo}
                                                        </p>

                                                        {/* Snippet de descripción */}
                                                        <p className="text-[11px] text-slate-600 dark:text-slate-400 line-clamp-2 leading-relaxed text-center px-1">
                                                            {project.description || 'Proyecto académico desplegado en la plataforma con entorno seguro gVisor.'}
                                                        </p>
                                                    </div>
                                                </div>

                                                {/* Pie de la tarjeta: Botones Contactar y Ver Demo en Vivo */}
                                                <div className="p-4 pt-3 border-t border-slate-100 dark:border-slate-800/80 bg-slate-50/50 dark:bg-slate-900/30 flex items-center justify-between gap-2">
                                                    {/* Botón Contactar */}
                                                    <button
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setContactStudent(project);
                                                        }}
                                                        className="text-xs font-bold text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 px-3 py-1.5 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                                                    >
                                                        Contactar
                                                    </button>

                                                    {/* Botón Ver Demo en Vivo */}
                                                    <button
                                                        onClick={() => handleStartDemo(project)}
                                                        className="px-4 py-1.5 rounded-full bg-[#1e293b] hover:bg-[#1534e8] dark:bg-blue-600 dark:hover:bg-blue-500 text-white font-bold text-xs shadow-xs transition flex items-center gap-1.5 cursor-pointer"
                                                    >
                                                        <svg className="w-3.5 h-3.5 fill-current" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clipRule="evenodd" />
                                                        </svg>
                                                        <span>Ver Demo en Vivo</span>
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Paginación */}
                            {totalPages > 1 && (
                                <div className="bg-white dark:bg-[#0f172a] rounded-2xl border border-slate-200 dark:border-slate-800 p-4 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3 mt-4">
                                    <div className="text-xs text-slate-500 dark:text-slate-400 font-medium">
                                        Mostrando <strong className="text-slate-800 dark:text-slate-200 font-bold">{(currentPage - 1) * PROJECTS_PER_PAGE + 1}</strong> - <strong className="text-slate-800 dark:text-slate-200 font-bold">{Math.min(currentPage * PROJECTS_PER_PAGE, filteredProjects.length)}</strong> de <strong className="text-slate-800 dark:text-slate-200 font-bold">{filteredProjects.length}</strong> proyectos
                                    </div>

                                    <div className="flex items-center gap-1.5">
                                        <button
                                            onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                                            disabled={currentPage === 1}
                                            className="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 cursor-pointer"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7" />
                                            </svg>
                                            <span>Anterior</span>
                                        </button>

                                        <div className="flex items-center gap-1">
                                            {Array.from({ length: totalPages }, (_, i) => i + 1).map(pageNum => (
                                                <button
                                                    key={pageNum}
                                                    onClick={() => setCurrentPage(pageNum)}
                                                    className={`w-8 h-8 rounded-lg text-xs font-bold transition flex items-center justify-center cursor-pointer ${currentPage === pageNum
                                                        ? 'bg-[#1534e8] text-white shadow-xs'
                                                        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'
                                                        }`}
                                                >
                                                    {pageNum}
                                                </button>
                                            ))}
                                        </div>

                                        <button
                                            onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
                                            disabled={currentPage === totalPages}
                                            className="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 cursor-pointer"
                                        >
                                            <span>Siguiente</span>
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* ---------------------------------------------------------------------
                            BARRA LATERAL DERECHA: FILTRAR POR CATEGORÍA
                            --------------------------------------------------------------------- */}
                        <aside className="lg:col-span-4 xl:col-span-3 space-y-4">
                            <div className="bg-white dark:bg-[#0f172a] rounded-3xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs transition">
                                <div
                                    onClick={() => setIsCategorySidebarOpen(!isCategorySidebarOpen)}
                                    className="flex items-center justify-between cursor-pointer border-b border-slate-100 dark:border-slate-800 pb-3 mb-3 select-none"
                                >
                                    <h2 className="text-sm font-extrabold text-slate-900 dark:text-white tracking-tight">
                                        Filtrar por Categoría
                                    </h2>
                                    <button className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                        <svg
                                            className={`w-4 h-4 transition-transform duration-200 ${isCategorySidebarOpen ? 'rotate-180' : ''}`}
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>

                                {isCategorySidebarOpen && (
                                    <div className="space-y-1.5 pt-1">
                                        {/* Opción Todas las industrias */}
                                        <label
                                            onClick={() => setSelectedCategory('all')}
                                            className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${selectedCategory === 'all'
                                                ? 'bg-blue-50 dark:bg-blue-950/40 text-[#1534e8] dark:text-blue-400 font-bold'
                                                : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                                                }`}
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <input
                                                    type="radio"
                                                    name="sidebar_category"
                                                    checked={selectedCategory === 'all'}
                                                    onChange={() => setSelectedCategory('all')}
                                                    className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                />
                                                <span>Todas las industrias</span>
                                            </div>
                                            <span className="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 font-semibold text-slate-600 dark:text-slate-400">
                                                {projects.length}
                                            </span>
                                        </label>

                                        {/* Lista de Categorías */}
                                        {PROJECT_CATEGORIES.map((cat) => {
                                            const count = categoryCounts[cat] || 0;
                                            const isSelected = selectedCategory === cat;
                                            return (
                                                <label
                                                    key={cat}
                                                    onClick={() => setSelectedCategory(cat)}
                                                    className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${isSelected
                                                        ? 'bg-blue-50 dark:bg-blue-950/40 text-[#1534e8] dark:text-blue-400 font-bold'
                                                        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800/50'
                                                        }`}
                                                >
                                                    <div className="flex items-center gap-2.5 truncate">
                                                        <input
                                                            type="radio"
                                                            name="sidebar_category"
                                                            checked={isSelected}
                                                            onChange={() => setSelectedCategory(cat)}
                                                            className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                        />
                                                        <span className="truncate">{cat}</span>
                                                    </div>
                                                    {count > 0 && (
                                                        <span className="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 font-semibold text-slate-600 dark:text-slate-400">
                                                            {count}
                                                        </span>
                                                    )}
                                                </label>
                                            );
                                        })}

                                        {selectedCategory !== 'all' && (
                                            <div className="pt-3 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                                                <button
                                                    onClick={() => setSelectedCategory('all')}
                                                    className="text-[11px] text-red-600 dark:text-red-400 hover:underline font-bold transition cursor-pointer"
                                                >
                                                    Limpiar filtro
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </aside>
                    </div>
                </div>

                {/* =========================================================================
                    5. FOOTER INSTITUCIONAL
                    ========================================================================= */}
                <footer className="bg-white dark:bg-[#0b0f19] border-t border-slate-200 dark:border-slate-800 py-8 text-xs text-slate-500 dark:text-slate-400">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <span className="font-bold text-slate-800 dark:text-slate-200">&gt; nexus academic</span>
                            <span>• Vitrina de Talento de Software Universitario</span>
                        </div>
                        <p>© 2026 Nexus Academic Platform. Todos los derechos reservados.</p>
                    </div>
                </footer>

                {/* =========================================================================
                    6. MODAL DE DEMO EN VIVO (100% Funcional e Integrado en Sandbox gVisor)
                    ========================================================================= */}
                {activeDemo && (
                    <div className="fixed inset-0 z-50 bg-slate-950/75 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="demo-modal-container w-full max-w-6xl h-[88vh] bg-white dark:bg-[#0b0f19] border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden flex flex-col shadow-2xl">

                            {/* Cabecera del Modal */}
                            <div className="demo-modal-header px-6 py-3.5 flex items-center justify-between border-b border-slate-200 dark:border-slate-800">
                                <div className="flex items-center space-x-3">
                                    <div className="w-8 h-8 rounded-lg bg-[#1534e8] text-white font-bold flex items-center justify-center text-xs shadow-xs">
                                        {activeDemo.name[0]}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-sm flex items-center gap-2 text-slate-900 dark:text-white">
                                            <span>{activeDemo.name}</span>
                                            <span className="text-[10px] bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-400 font-mono px-2 py-0.5 rounded-md font-semibold border border-blue-200 dark:border-blue-800">
                                                http://{activeDemo.subdomain}.localhost
                                            </span>
                                        </h3>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                            Estudiante: <strong className="font-semibold text-slate-700 dark:text-slate-200">{activeDemo.user.name}</strong> • Entorno Sandbox gVisor
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center space-x-2">
                                    <button
                                        onClick={() => {
                                            setIframeLoaded(false);
                                            setDemoKey(Date.now());
                                        }}
                                        title="Recargar vista"
                                        className="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition flex items-center gap-1.5 shadow-2xs cursor-pointer"
                                    >
                                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        <span className="hidden sm:inline">Recargar</span>
                                    </button>

                                    <a
                                        href={`http://${activeDemo.subdomain}.localhost`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="px-3 py-1.5 rounded-lg text-xs font-bold bg-[#1534e8] hover:bg-blue-700 text-white transition flex items-center gap-1.5 shadow-xs"
                                    >
                                        <span>Abrir en nueva pestaña</span>
                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                    </a>

                                    <button
                                        onClick={() => handleCloseDemo(activeDemo)}
                                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40 dark:hover:text-red-400 transition cursor-pointer"
                                    >
                                        Cerrar Demo
                                    </button>
                                </div>
                            </div>

                            {/* Banner de Aislamiento de Seguridad */}
                            <div className="bg-slate-50 dark:bg-slate-900/50 px-6 py-2 text-xs flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400">
                                <svg className="w-4 h-4 text-blue-600 dark:text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <span>Ejecución Segura en Sandbox gVisor.</span>
                            </div>

                            {/* Contenido: Iframe (Aislado) + Guía lateral */}
                            <div className="flex-1 bg-white relative flex flex-row min-h-0">
                                <div className="flex-1 min-w-0 h-full relative bg-white">
                                    {demoError ? (
                                        <div className="w-full h-full flex flex-col p-8 bg-slate-900 text-slate-200 font-mono overflow-y-auto">
                                            <div className="flex items-center space-x-2 text-red-400 font-bold text-base mb-4 border-b border-red-900/40 pb-2">
                                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                </svg>
                                                <span>{demoError}</span>
                                            </div>
                                            <div className="flex-1 bg-slate-950 border border-slate-800 rounded-xl p-6 overflow-auto shadow-inner text-xs leading-relaxed text-red-300">
                                                <div className="text-slate-400 font-bold mb-2">=== BITÁCORA DEL CONTENEDOR (DOCKER LOGS) ===</div>
                                                <pre className="whitespace-pre-wrap">{demoLogs}</pre>
                                            </div>
                                            <div className="mt-4 text-center">
                                                <button
                                                    onClick={() => handleCloseDemo(activeDemo)}
                                                    className="px-5 py-2.5 bg-red-600 hover:bg-red-500 text-white rounded-xl font-bold text-xs shadow-md transition"
                                                >
                                                    Cerrar Bitácora
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="w-full h-full relative bg-white">
                                            {(wakingUp || !iframeLoaded) && (
                                                <div className="absolute inset-0 z-10 bg-white">
                                                    <DemoLoadingAnimation
                                                        projectName={activeDemo?.name}
                                                        subdomain={activeDemo?.subdomain}
                                                    />
                                                </div>
                                            )}
                                            {!wakingUp && (
                                                <iframe
                                                    key={demoKey}
                                                    src={`http://${activeDemo.subdomain}.localhost`}
                                                    className={`demo-iframe w-full h-full border-0 transition-opacity duration-300 ${iframeLoaded ? 'opacity-100' : 'opacity-0'}`}
                                                    style={{
                                                        backgroundColor: '#ffffff',
                                                        colorScheme: 'light',
                                                    }}
                                                    title="Demostración en Vivo del Estudiante"
                                                    onLoad={() => setIframeLoaded(true)}
                                                />
                                            )}
                                        </div>
                                    )}
                                </div>

                                {/* Guía del Evaluador lateral */}
                                {!wakingUp && iframeLoaded && !demoError && (
                                    <div className="demo-evaluator-sidebar w-80 p-5 flex flex-col justify-between shrink-0 hidden md:flex overflow-y-auto border-l border-slate-200 dark:border-slate-800 bg-white dark:bg-[#0b0f19]">
                                        <div className="space-y-4">
                                            <div className="flex items-center space-x-2 text-blue-700 dark:text-blue-400">
                                                <svg className="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                                </svg>
                                                <h4 className="font-bold text-xs tracking-wider uppercase text-slate-800 dark:text-slate-100">Guía del Evaluador</h4>
                                            </div>

                                            {(() => {
                                                const creds = getDemoCredentials(activeDemo);
                                                return (
                                                    <div className="space-y-4">
                                                        {/* Acceso de Pruebas */}
                                                        <div className="p-3.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50">
                                                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block mb-2">Acceso de Pruebas</span>
                                                            <div className="space-y-2 text-xs">
                                                                <div className="flex justify-between items-center">
                                                                    <span className="text-slate-500">Usuario:</span>
                                                                    <span className="font-mono font-bold px-2.5 py-0.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 select-all">
                                                                        {creds.user}
                                                                    </span>
                                                                </div>
                                                                <div className="flex justify-between items-center">
                                                                    <span className="text-slate-500">Clave:</span>
                                                                    <span className="font-mono font-bold px-2.5 py-0.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-800 dark:text-slate-100 select-all">
                                                                        {creds.pass}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Recomendación de Uso */}
                                                        <div className="text-xs space-y-1.5 leading-relaxed p-3.5 rounded-xl border border-blue-100 dark:border-blue-900/40 bg-blue-50/50 dark:bg-blue-950/20">
                                                            <p className="font-bold text-[11px] text-blue-700 dark:text-blue-400">Recomendación de Uso:</p>
                                                            <p className="text-[11px] text-slate-600 dark:text-slate-300 leading-relaxed">{creds.note}</p>
                                                        </div>

                                                        {/* Botón Pantalla Completa */}
                                                        <a
                                                            href={`http://${activeDemo.subdomain}.localhost`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="w-full py-2.5 px-3 rounded-xl text-xs font-bold text-center flex items-center justify-center gap-2 bg-[#1534e8] hover:bg-blue-700 text-white shadow-xs transition"
                                                        >
                                                            <span>Pantalla Completa</span>
                                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                            </svg>
                                                        </a>
                                                    </div>
                                                );
                                            })()}
                                        </div>

                                        <div className="pt-3 border-t border-slate-200 dark:border-slate-800 text-center">
                                            <span className="text-[10px] font-mono text-slate-400">Nexus Academic PaaS v2.0</span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* =========================================================================
                    7. MODAL DE CONTACTO RECLUTADOR
                    ========================================================================= */}
                {contactStudent && (
                    <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="w-full max-w-lg bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 shadow-2xl">
                            <div className="flex justify-between items-start mb-5 border-b border-slate-100 dark:border-slate-800 pb-3">
                                <div>
                                    <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                        Contactar con {contactStudent.user.name}
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                        Envía una propuesta laboral, feedback técnico o invitación a entrevista.
                                    </p>
                                </div>
                                <button
                                    onClick={() => setContactStudent(null)}
                                    className="p-1 px-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            {isContactSent ? (
                                <div className="p-8 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-300 text-center space-y-3">
                                    <div className="w-12 h-12 mx-auto rounded-full bg-emerald-100 dark:bg-emerald-900/60 border border-emerald-300 dark:border-emerald-700 flex items-center justify-center text-emerald-700 dark:text-emerald-400">
                                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                    <p className="text-base font-bold text-slate-900 dark:text-white">¡Mensaje enviado con éxito!</p>
                                    <p className="text-xs text-slate-600 dark:text-slate-300 max-w-xs mx-auto">
                                        El estudiante ha recibido tu mensaje y se pondrá en contacto a tu correo corporativo.
                                    </p>
                                </div>
                            ) : (
                                <form onSubmit={handleContactSubmit} className="space-y-4 text-xs">
                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Tu Nombre</label>
                                        <input
                                            type="text"
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 transition"
                                            placeholder="Ingresa tu nombre completo..."
                                        />
                                        {errors.name && <p className="text-red-500 mt-1">{errors.name}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Correo Corporativo</label>
                                        <input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 transition"
                                            placeholder="ejemplo@empresa.com"
                                        />
                                        {errors.email && <p className="text-red-500 mt-1">{errors.email}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Empresa / Organización</label>
                                        <input
                                            type="text"
                                            value={data.company}
                                            onChange={(e) => setData('company', e.target.value)}
                                            required
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 transition"
                                            placeholder="Nombre de la empresa..."
                                        />
                                        {errors.company && <p className="text-red-500 mt-1">{errors.company}</p>}
                                    </div>

                                    <div>
                                        <label className="block font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">Mensaje o Propuesta</label>
                                        <textarea
                                            value={data.message}
                                            onChange={(e) => setData('message', e.target.value)}
                                            required
                                            rows="4"
                                            className="w-full px-3 py-2 bg-slate-50 dark:bg-slate-800/60 border border-slate-300 dark:border-slate-700 rounded-xl text-slate-800 dark:text-slate-100 focus:bg-white dark:focus:bg-slate-800 focus:border-blue-600 focus:ring-1 focus:ring-blue-600 transition"
                                            placeholder="Nos llamó la atención tu proyecto y nos gustaría agendar una entrevista técnica..."
                                        />
                                        {errors.message && <p className="text-red-500 mt-1">{errors.message}</p>}
                                    </div>

                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="w-full py-2.5 rounded-xl bg-[#1534e8] hover:bg-blue-700 text-white font-bold transition disabled:opacity-50 shadow-xs cursor-pointer"
                                    >
                                        {processing ? 'Enviando...' : 'Enviar Contacto a Estudiante'}
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

// =========================================================================
// AUXILIARES Y LOGOS VISUALES PARA CADA LENGUAJE/FRAMEWORK
// =========================================================================

function getTechInfo(language) {
    const lang = (language || '').toLowerCase();
    if (lang === 'php') {
        return {
            name: 'PHP 8.2 / Laravel',
            pillName: 'Laravel / PHP',
            dotColor: 'bg-red-500',
            shortInfo: 'PHP 8.2 / Laravel',
            cardVisual: (
                <div className="flex items-center justify-center gap-3">
                    {/* Logo Laravel */}
                    <div className="w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <svg className="w-full h-full text-[#ff2d20] fill-current" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                        </svg>
                    </div>
                    {/* Logo PHP */}
                    <div className="w-16 h-10 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-900/60 flex items-center justify-center shadow-xs">
                        <span className="font-black text-indigo-700 dark:text-indigo-400 text-sm italic tracking-tighter">php</span>
                    </div>
                </div>
            )
        };
    }
    if (lang === 'nodejs') {
        return {
            name: 'Node.js 20 & React',
            pillName: 'Node.js / React',
            dotColor: 'bg-emerald-500',
            shortInfo: 'Node 20 & React',
            cardVisual: (
                <div className="flex items-center justify-center gap-3">
                    {/* Logo Node.js */}
                    <div className="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <svg className="w-full h-full text-emerald-600 fill-current" viewBox="0 0 24 24">
                            <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 14.5h-2v-2h2v2zm0-4h-2V7h2v5.5z" />
                        </svg>
                    </div>
                    {/* Logo React */}
                    <div className="w-14 h-14 rounded-2xl bg-cyan-50 dark:bg-cyan-950/40 border border-cyan-200 dark:border-cyan-900/60 p-2 flex items-center justify-center shadow-xs">
                        <svg className="w-full h-full text-cyan-500 animate-spin-slow" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                            <ellipse cx="12" cy="12" rx="10" ry="4.5" />
                            <ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(60 12 12)" />
                            <ellipse cx="12" cy="12" rx="10" ry="4.5" transform="rotate(120 12 12)" />
                            <circle cx="12" cy="12" r="2" fill="currentColor" />
                        </svg>
                    </div>
                </div>
            )
        };
    }
    if (lang === 'python') {
        return {
            name: 'Python 3.11 / Django',
            pillName: 'Python / Django',
            dotColor: 'bg-blue-500',
            shortInfo: 'Python 3.11 / Django',
            cardVisual: (
                <div className="flex items-center justify-center gap-3">
                    <div className="w-14 h-14 rounded-2xl bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <svg className="w-full h-full text-blue-600 fill-current" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" />
                        </svg>
                    </div>
                    <div className="w-16 h-10 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 flex items-center justify-center shadow-xs">
                        <span className="font-black text-emerald-800 dark:text-emerald-400 text-xs uppercase tracking-tight">django</span>
                    </div>
                </div>
            )
        };
    }
    if (lang === 'java') {
        return {
            name: 'Java 17/21 / Spring Boot',
            pillName: 'Java / Spring Boot',
            dotColor: 'bg-orange-500',
            shortInfo: 'Java 17 & Spring Boot',
            cardVisual: (
                <div className="flex items-center justify-center gap-3">
                    <div className="w-14 h-14 rounded-2xl bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <span className="font-black text-orange-600 text-sm uppercase">JAVA</span>
                    </div>
                    <div className="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <span className="font-bold text-emerald-600 text-xs">Spring</span>
                    </div>
                </div>
            )
        };
    }
    if (lang === 'dotnet') {
        return {
            name: '.NET 8 / ASP.NET Core',
            pillName: '.NET / C#',
            dotColor: 'bg-purple-500',
            shortInfo: '.NET 8 & ASP.NET',
            cardVisual: (
                <div className="flex items-center justify-center gap-3">
                    <div className="w-14 h-14 rounded-2xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <span className="font-black text-purple-600 text-sm">.NET</span>
                    </div>
                    <div className="w-14 h-14 rounded-2xl bg-indigo-50 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-900/60 p-2.5 flex items-center justify-center shadow-xs">
                        <span className="font-bold text-indigo-600 text-sm">C#</span>
                    </div>
                </div>
            )
        };
    }
    return {
        name: 'Contenedor Docker Universal',
        pillName: 'Docker Container',
        dotColor: 'bg-sky-500',
        shortInfo: 'Docker gVisor',
        cardVisual: (
            <div className="flex items-center justify-center gap-2">
                <div className="w-16 h-14 rounded-2xl bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-900/60 p-2 flex items-center justify-center shadow-xs">
                    <svg className="w-full h-full text-sky-600 fill-current" viewBox="0 0 24 24">
                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" />
                    </svg>
                </div>
            </div>
        )
    };
}

function getDemoCredentials(project) {
    if (project.demo_instructions) {
        const userMatch = project.demo_instructions.match(/Usuario:\s*([^\r\n]+)/i);
        const passMatch = project.demo_instructions.match(/Clave:\s*([^\r\n]+)/i);
        if (userMatch && passMatch) {
            return {
                user: userMatch[1].trim(),
                pass: passMatch[1].trim(),
                note: 'Usa estas credenciales de prueba preconfiguradas para iniciar sesión y evaluar el sistema.'
            };
        }
    }
    const name = (project.name || '').toLowerCase();
    if (name.includes('crater')) {
        return {
            user: 'admin@craterapp.com',
            pass: 'password',
            note: 'Inicia sesión en Crater Invoice con estas credenciales de administrador para explorar facturas, clientes y reportes.'
        };
    }
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
