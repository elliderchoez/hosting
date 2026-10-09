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

const SORT_OPTIONS = [
    { id: 'recent', name: 'Más recientes' },
    { id: 'popular', name: 'Más ejecutados' },
    { id: 'featured', name: 'Destacados primero' },
    { id: 'name_asc', name: 'Nombre (A - Z)' },
    { id: 'name_desc', name: 'Nombre (Z - A)' },
];

const LANGUAGE_OPTIONS = [
    { id: 'all', name: 'Todos los lenguajes' },
    { id: 'php', name: 'PHP' },
    { id: 'nodejs', name: 'JavaScript / TypeScript' },
    { id: 'python', name: 'Python' },
    { id: 'ruby', name: 'Ruby' },
    { id: 'java', name: 'Java' },
    { id: 'dotnet', name: 'C# (.NET)' },
    { id: 'go', name: 'Go (Golang)' },
];

const FRAMEWORK_OPTIONS = [
    { id: 'all', name: 'Todos los frameworks' },
    { id: 'laravel', name: 'Laravel' },
    { id: 'rails', name: 'Ruby on Rails' },
    { id: 'fullstack', name: 'Fullstack (React / Node)' },
    { id: 'react', name: 'React' },
    { id: 'vue', name: 'Vue.js' },
    { id: 'express', name: 'Express.js' },
    { id: 'django', name: 'Django' },
    { id: 'springboot', name: 'Spring Boot' },
    { id: 'aspnet', name: 'ASP.NET Core' },
    { id: 'none', name: 'Sin framework (Puro / Vanilla)' },
];

const DATABASE_OPTIONS = [
    { id: 'all', name: 'Todas las bases de datos' },
    { id: 'pgsql', name: 'PostgreSQL' },
    { id: 'mysql', name: 'MySQL' },
    { id: 'mongodb', name: 'MongoDB' },
    { id: 'none', name: 'Sin base de datos' },
];

export default function Welcome({ auth, projects = [] }) {
    const [search, setSearch] = useState('');
    const [selectedLanguage, setSelectedLanguage] = useState('all');
    const [selectedFramework, setSelectedFramework] = useState('all');
    const [selectedDatabase, setSelectedDatabase] = useState('all');
    const [selectedCategory, setSelectedCategory] = useState('all');
    const [sortBy, setSortBy] = useState('recent');
    const [currentPage, setCurrentPage] = useState(1);
    const [isSortOpen, setIsSortOpen] = useState(false);
    const [isLangOpen, setIsLangOpen] = useState(false);
    const [isFrameworkOpen, setIsFrameworkOpen] = useState(false);
    const [isDatabaseOpen, setIsDatabaseOpen] = useState(false);
    const [isCategorySidebarOpen, setIsCategorySidebarOpen] = useState(true);
    const [isHowItWorksOpen, setIsHowItWorksOpen] = useState(false);

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
    const searchInputRef = useRef(null);
    const [canScrollLeft, setCanScrollLeft] = useState(false);

    // Atajo de teclado Ctrl+K o Cmd+K para enfocar el buscador
    useEffect(() => {
        const handleKeyDown = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                searchInputRef.current?.focus();
            }
        };
        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, []);

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

    const checkCategoryScroll = () => {
        if (categoryScrollRef.current) {
            setCanScrollLeft(categoryScrollRef.current.scrollLeft > 20);
        }
    };

    const scrollCategoriesLeft = () => {
        if (categoryScrollRef.current) {
            categoryScrollRef.current.scrollBy({ left: -260, behavior: 'smooth' });
            setTimeout(checkCategoryScroll, 300);
        }
    };

    const scrollCategoriesRight = () => {
        if (categoryScrollRef.current) {
            categoryScrollRef.current.scrollBy({ left: 260, behavior: 'smooth' });
            setTimeout(checkCategoryScroll, 300);
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
        if (!project) return;

        // 1. Obtener la URL base del proyecto para desloguear y limpiar cookies en el navegador
        const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        const projectBaseUrl = isLocal 
            ? `${window.location.protocol}//${project.subdomain}.localhost`
            : `${window.location.protocol}//${project.subdomain}.nexus-academic.software`;

        // 2. Disparar limpieza universal de cookies y storage mediante iframe oculto hacia /uleam-clear-session
        try {
            const clearIframe = document.createElement('iframe');
            clearIframe.style.display = 'none';
            clearIframe.style.width = '0px';
            clearIframe.style.height = '0px';
            clearIframe.src = `${projectBaseUrl}/uleam-clear-session?t=${Date.now()}`;
            document.body.appendChild(clearIframe);

            // Fetch en paralelo con credentials para aplicar Clear-Site-Data
            fetch(`${projectBaseUrl}/uleam-clear-session?t=${Date.now()}`, {
                mode: 'no-cors',
                credentials: 'include'
            }).catch(() => {});

            // Intentar también llamar endpoints estándar de logout
            fetch(`${projectBaseUrl}/logout`, { mode: 'no-cors', credentials: 'include' }).catch(() => {});
            fetch(`${projectBaseUrl}/users/sign_out`, { mode: 'no-cors', credentials: 'include' }).catch(() => {});

            setTimeout(() => {
                if (clearIframe.parentNode) {
                    clearIframe.parentNode.removeChild(clearIframe);
                }
            }, 1200);
        } catch (e) {
            console.warn('Error clearing project session:', e);
        }

        // 3. Cerrar el modal inmediatamente en la interfaz
        setActiveDemo(null);
        setDemoError(null);
        setDemoLogs('');
        setIframeLoaded(false);

        // 4. Notificar al backend para que restablezca la base de datos a su estado inicial sin apagar el contenedor
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

    // Recuento dinámico de proyectos por lenguaje
    const languageCounts = useMemo(() => {
        const counts = {
            php: 0,
            nodejs: 0,
            python: 0,
            ruby: 0,
            java: 0,
            dotnet: 0,
            go: 0,
        };
        projects.forEach(p => {
            const lang = (p.language || '').toLowerCase();
            if (lang === 'php') {
                counts.php++;
            } else if (['nodejs', 'javascript', 'typescript', 'js', 'ts'].includes(lang)) {
                counts.nodejs++;
            } else if (lang === 'python') {
                counts.python++;
            } else if (lang === 'ruby') {
                counts.ruby++;
            } else if (lang === 'java') {
                counts.java++;
            } else if (['dotnet', 'csharp', 'c#'].includes(lang)) {
                counts.dotnet++;
            } else if (lang === 'go' || lang === 'golang') {
                counts.go++;
            }
        });
        return counts;
    }, [projects]);

    // Recuento dinámico de proyectos por framework
    const frameworkCounts = useMemo(() => {
        const counts = {
            laravel: 0,
            rails: 0,
            fullstack: 0,
            react: 0,
            vue: 0,
            express: 0,
            django: 0,
            springboot: 0,
            aspnet: 0,
            none: 0,
        };
        projects.forEach(p => {
            const fw = (p.framework || '').toLowerCase();
            const pName = (p.name || '').toLowerCase();
            const isJira = pName.includes('jira');
            const isFullstack = fw === 'react-node' || fw === 'fullstack' || fw.includes('fullstack') || isJira;

            if (fw === 'laravel' || fw.includes('laravel')) counts.laravel++;
            else if (fw === 'rails' || fw.includes('rails')) counts.rails++;
            else if (isFullstack) counts.fullstack++;
            else if (fw === 'react' || fw === 'nextjs' || pName.includes('calculator')) counts.react++;
            else if (fw === 'vue') counts.vue++;
            else if (fw === 'express') counts.express++;
            else if (fw === 'django') counts.django++;
            else if (fw === 'springboot') counts.springboot++;
            else if (fw === 'aspnet') counts.aspnet++;
            else counts.none++;
        });
        return counts;
    }, [projects]);

    // Recuento dinámico de proyectos por base de datos
    const databaseCounts = useMemo(() => {
        const counts = {
            pgsql: 0,
            mysql: 0,
            mongodb: 0,
            none: 0
        };
        projects.forEach(p => {
            const driver = (p.db_driver || '').toLowerCase();
            if (driver === 'pgsql' || driver === 'postgres' || driver === 'postgresql') {
                counts.pgsql++;
            } else if (driver === 'mysql') {
                counts.mysql++;
            } else if (driver === 'mongodb' || driver === 'mongo') {
                counts.mongodb++;
            } else {
                counts.none++;
            }
        });
        return counts;
    }, [projects]);

    // Filtrado y Ordenamiento de proyectos
    const filteredProjects = useMemo(() => {
        let result = projects.filter(project => {
            const query = search.toLowerCase().trim();
            const projectCat = project.category || 'Herramientas y Calculadoras';

            const matchesSearch = !query ||
                project.name.toLowerCase().includes(query) ||
                projectCat.toLowerCase().includes(query) ||
                project.user.name.toLowerCase().includes(query) ||
                (project.subdomain && project.subdomain.toLowerCase().includes(query)) ||
                (project.user.profile?.skills && project.user.profile.skills.some(s => s.toLowerCase().includes(query)));

            let matchesLanguage = true;
            if (selectedLanguage !== 'all') {
                const lang = (project.language || '').toLowerCase();
                if (selectedLanguage === 'nodejs') {
                    matchesLanguage = ['nodejs', 'javascript', 'typescript', 'js', 'ts'].includes(lang);
                } else if (selectedLanguage === 'dotnet') {
                    matchesLanguage = ['dotnet', 'csharp', 'c#'].includes(lang);
                } else if (selectedLanguage === 'go') {
                    matchesLanguage = lang === 'go' || lang === 'golang';
                } else {
                    matchesLanguage = lang === selectedLanguage.toLowerCase();
                }
            }

            let matchesFramework = true;
            if (selectedFramework !== 'all') {
                const fw = (project.framework || '').toLowerCase();
                const pName = (project.name || '').toLowerCase();
                const isJira = pName.includes('jira');
                const isFullstack = fw === 'react-node' || fw === 'fullstack' || fw.includes('fullstack') || isJira;

                if (selectedFramework === 'laravel') matchesFramework = fw === 'laravel' || fw.includes('laravel');
                else if (selectedFramework === 'rails') matchesFramework = fw === 'rails' || fw.includes('rails');
                else if (selectedFramework === 'fullstack') matchesFramework = isFullstack;
                else if (selectedFramework === 'react') matchesFramework = (fw === 'react' || fw === 'nextjs' || fw.includes('react') || pName.includes('calculator') || isFullstack);
                else if (selectedFramework === 'vue') matchesFramework = fw === 'vue' || fw.includes('vue');
                else if (selectedFramework === 'express') matchesFramework = fw === 'express';
                else if (selectedFramework === 'django') matchesFramework = fw === 'django';
                else if (selectedFramework === 'springboot') matchesFramework = fw === 'springboot';
                else if (selectedFramework === 'aspnet') matchesFramework = fw === 'aspnet';
                else if (selectedFramework === 'none') matchesFramework = !fw && !isFullstack;
            }

            let matchesDatabase = true;
            if (selectedDatabase !== 'all') {
                const driver = (project.db_driver || '').toLowerCase();
                if (selectedDatabase === 'pgsql') {
                    matchesDatabase = driver === 'pgsql' || driver === 'postgres' || driver === 'postgresql';
                } else if (selectedDatabase === 'mysql') {
                    matchesDatabase = driver === 'mysql';
                } else if (selectedDatabase === 'mongodb') {
                    matchesDatabase = driver === 'mongodb' || driver === 'mongo';
                } else if (selectedDatabase === 'none') {
                    matchesDatabase = !driver;
                }
            }

            const matchesCategory = selectedCategory === 'all' || projectCat === selectedCategory;

            return matchesSearch && matchesLanguage && matchesFramework && matchesDatabase && matchesCategory;
        });

        // Ordenamiento dinámico
        return result.sort((a, b) => {
            if (sortBy === 'popular') {
                return (b.status === 'running' ? 1 : 0) - (a.status === 'running' ? 1 : 0);
            }
            if (sortBy === 'featured') {
                const aFeatured = a.status === 'running' ? 2 : (a.status === 'sleeping' ? 1 : 0);
                const bFeatured = b.status === 'running' ? 2 : (b.status === 'sleeping' ? 1 : 0);
                return bFeatured - aFeatured;
            }
            if (sortBy === 'name_asc') {
                return a.name.localeCompare(b.name);
            }
            if (sortBy === 'name_desc') {
                return b.name.localeCompare(a.name);
            }
            // recent default: más recientes primero
            return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
        });
    }, [projects, search, selectedLanguage, selectedFramework, selectedDatabase, selectedCategory, sortBy]);

    // Paginación (6 por página para cuadrícula de 3 columnas perfecta)
    const PROJECTS_PER_PAGE = 6;

    useEffect(() => {
        setCurrentPage(1);
    }, [search, selectedLanguage, selectedFramework, selectedDatabase, selectedCategory, sortBy]);

    const totalPages = Math.ceil(filteredProjects.length / PROJECTS_PER_PAGE) || 1;

    const paginatedProjects = useMemo(() => {
        const startIndex = (currentPage - 1) * PROJECTS_PER_PAGE;
        return filteredProjects.slice(startIndex, startIndex + PROJECTS_PER_PAGE);
    }, [filteredProjects, currentPage]);

    const renderPagination = (isTop = true) => {
        if (filteredProjects.length === 0) return null;
        if (!isTop && totalPages <= 1) return null;
        return (
            <div className="bg-white dark:bg-[#0f172a] rounded-xl border border-slate-200 dark:border-slate-800 py-2 px-3.5 sm:px-4 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-2.5">
                <div className="text-[11px] sm:text-xs text-slate-500 dark:text-slate-400 font-medium">
                    Mostrando <strong className="text-slate-800 dark:text-slate-200 font-bold">{(currentPage - 1) * PROJECTS_PER_PAGE + 1}</strong> - <strong className="text-slate-800 dark:text-slate-200 font-bold">{Math.min(currentPage * PROJECTS_PER_PAGE, filteredProjects.length)}</strong> de <strong className="text-slate-800 dark:text-slate-200 font-bold">{filteredProjects.length}</strong> proyectos
                </div>

                <div className="flex items-center gap-1.5">
                    <button
                        onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
                        disabled={currentPage === 1}
                        className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-800 text-[11px] font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 cursor-pointer"
                    >
                        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 19l-7-7 7-7" />
                        </svg>
                        <span>Anterior</span>
                    </button>

                    <div className="flex items-center gap-1">
                        {Array.from({ length: Math.max(totalPages, 1) }, (_, i) => i + 1).map(pageNum => (
                            <button
                                key={pageNum}
                                onClick={() => setCurrentPage(pageNum)}
                                className={`w-7 h-7 rounded-lg text-xs font-bold transition flex items-center justify-center cursor-pointer ${currentPage === pageNum
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
                        disabled={currentPage >= totalPages}
                        className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-800 text-[11px] font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition flex items-center gap-1 cursor-pointer"
                    >
                        <span>Siguiente</span>
                        <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        );
    };

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
                                    {auth.user.role === 'admin' ? (
                                        <Link
                                            href={route('admin.dashboard')}
                                            className="px-3.5 py-1.5 rounded-full text-xs font-bold bg-purple-900/70 text-purple-200 border border-purple-700/60 hover:bg-purple-800/80 transition"
                                        >
                                            Consola Administrador
                                        </Link>
                                    ) : auth.user.is_partner ? (
                                        <div className="flex items-center gap-2">
                                            <span className="px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 flex items-center gap-1.5 shadow-xs">
                                                <span className="text-emerald-500 font-bold">✓</span>
                                                <span>{auth.user.role === 'company' ? 'Empresa Verificada' : 'Reclutador Tech'}: <strong className="font-bold">{auth.user.company || auth.user.name}</strong></span>
                                            </span>
                                        </div>
                                    ) : (
                                        <>
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
                                        </>
                                    )}
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="px-3 py-1.5 rounded-full text-xs font-bold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800/60 transition"
                                    >
                                        Salir
                                    </Link>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
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

                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 pb-5">

                    {/* =========================================================================
                        2. TÍTULO PRINCIPAL + PÍLDORA ¿CÓMO PROBAR LAS DEMOS? (ABAJO) + BUSCADOR
                        ========================================================================= */}
                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-6">
                        {/* Título de la galería + Píldora de Guía abajo del título */}
                        <div className="flex flex-col items-start gap-1.5">
                            <h1 className="text-2xl sm:text-3xl font-extrabold text-slate-900 dark:text-white tracking-tight font-serif">
                                Galería de Innovación Académica
                            </h1>

                            {/* Píldora "¿Cómo probar las demos?" debajo de Galería de Innovación Académica */}
                            <div>
                                <button
                                    onClick={() => setIsHowItWorksOpen(true)}
                                    className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-50 dark:bg-blue-950/40 text-[#1534e8] dark:text-blue-400 border border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-900/50 transition shadow-2xs cursor-pointer"
                                    title="Conoce cómo explorar y probar las aplicaciones"
                                >
                                    <svg className="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span>¿Cómo probar las demos?</span>
                                </button>
                            </div>
                        </div>

                        {/* Nuevo diseño de Buscador de última generación */}
                        <div className="w-full lg:w-[430px]">
                            <div className="group relative flex items-center bg-white dark:bg-[#0f172a] rounded-full border border-slate-200 dark:border-slate-800 shadow-2xs hover:border-slate-300 dark:hover:border-slate-700 focus-within:!border-[#1534e8] focus-within:!ring-4 focus-within:!ring-[#1534e8]/10 transition-all duration-300 p-1.5 pl-3.5">
                                {/* Icono de lupa con transición suave */}
                                <div className="flex items-center justify-center w-7 h-7 rounded-full bg-blue-50 dark:bg-blue-950/60 text-[#1534e8] dark:text-blue-400 shrink-0 mr-2.5 transition-transform group-focus-within:scale-110">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>

                                {/* Input sin bordes internos ni sombras de navegador */}
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar proyectos, tecnologías, creadores..."
                                    className="search-input-borderless w-full bg-transparent !border-0 !border-none !outline-none !ring-0 !shadow-none text-xs sm:text-sm text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 py-1.5 px-0"
                                />

                                {/* Acciones en el lateral derecho */}
                                <div className="flex items-center gap-1.5 shrink-0 pl-2">
                                    {search && (
                                        <button
                                            type="button"
                                            onClick={() => { setSearch(''); searchInputRef.current?.focus(); }}
                                            className="w-6 h-6 flex items-center justify-center rounded-full text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                                            title="Limpiar búsqueda"
                                        >
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    )}



                                    <button
                                        type="button"
                                        onClick={() => searchInputRef.current?.focus()}
                                        className="px-4 py-1.5 rounded-full bg-[#1534e8] hover:bg-blue-700 active:scale-95 text-white text-xs font-bold transition shadow-2xs hover:shadow-xs cursor-pointer flex items-center gap-1.5"
                                    >
                                        <span>Buscar</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* =========================================================================
                        3. BARRA HORIZONTAL DE CATEGORÍAS (SIN ICONOS + NAVEGACIÓN IZQUIERDA Y DERECHA)
                        ========================================================================= */}
                    <div className="relative flex items-center gap-2 mb-8">
                        {/* Botón de desplazamiento hacia la izquierda */}
                        {canScrollLeft && (
                            <button
                                onClick={scrollCategoriesLeft}
                                className="shrink-0 p-2 rounded-full bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 shadow-xs text-slate-600 dark:text-slate-300 hover:text-blue-600 hover:border-blue-400 transition cursor-pointer"
                                title="Ver categorías anteriores"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                        )}

                        <div
                            ref={categoryScrollRef}
                            onScroll={checkCategoryScroll}
                            className="flex items-center gap-2 overflow-x-auto no-scrollbar py-1 scroll-smooth pr-6"
                            style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}
                        >
                            {HORIZONTAL_CATEGORIES.map((catItem) => {
                                const isActive = selectedCategory === catItem.id;
                                return (
                                    <button
                                        key={catItem.id}
                                        onClick={() => setSelectedCategory(catItem.id)}
                                        className={`px-4 py-2 rounded-full text-xs font-bold transition-all shrink-0 cursor-pointer shadow-2xs ${isActive
                                            ? 'bg-[#1534e8] text-white shadow-xs scale-[1.02]'
                                            : 'bg-white dark:bg-[#0f172a] text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 hover:border-blue-400 hover:bg-slate-50 dark:hover:bg-slate-800/60'
                                            }`}
                                    >
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
                            BARRA LATERAL IZQUIERDA: FILTROS Y CATEGORÍAS (ESTILO UNIFICADO)
                            --------------------------------------------------------------------- */}
                        <aside className="lg:col-span-4 xl:col-span-3 space-y-4">
                            <div className="category-sidebar-card bg-white dark:bg-[#0f172a] rounded-3xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs transition space-y-5">
                                {/* Encabezado del panel de filtros con reseteo rápido */}
                                <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3 select-none">
                                    <h2 className="category-header-title text-sm font-extrabold text-slate-900 dark:text-white tracking-tight flex items-center gap-2">
                                        <svg className="w-4 h-4 text-[#1534e8]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                        </svg>
                                        <span>Filtros</span>
                                    </h2>
                                    {(selectedLanguage !== 'all' || selectedFramework !== 'all' || selectedDatabase !== 'all' || sortBy !== 'recent') && (
                                        <button
                                            onClick={() => { setSelectedLanguage('all'); setSelectedFramework('all'); setSelectedDatabase('all'); setSortBy('recent'); }}
                                            className="text-[11px] text-red-600 dark:text-red-400 hover:underline font-bold transition cursor-pointer"
                                        >
                                            Limpiar todo
                                        </button>
                                    )}
                                </div>

                                {/* 1. Más recientes */}
                                <div className="space-y-2">
                                    <div
                                        onClick={() => setIsSortOpen(!isSortOpen)}
                                        className="flex items-center justify-between cursor-pointer py-1 select-none"
                                    >
                                        <h3 className="category-header-title text-sm font-extrabold text-slate-900 dark:text-white tracking-tight">
                                            Más recientes
                                        </h3>
                                        <button className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                            <svg
                                                className={`w-4 h-4 transition-transform duration-200 ${isSortOpen ? 'rotate-180' : ''}`}
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </div>

                                    {isSortOpen && (
                                        <div className="space-y-1.5 pt-1">
                                            {SORT_OPTIONS.map((opt) => {
                                                const isSelected = sortBy === opt.id;
                                                return (
                                                    <label
                                                        key={opt.id}
                                                        onClick={() => setSortBy(opt.id)}
                                                        className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${isSelected
                                                            ? 'category-item-active font-bold'
                                                            : 'category-item-inactive'
                                                            }`}
                                                    >
                                                        <div className="flex items-center gap-2.5 truncate">
                                                            <input
                                                                type="radio"
                                                                name="sidebar_sort"
                                                                checked={isSelected}
                                                                onChange={() => setSortBy(opt.id)}
                                                                className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                            />
                                                            <span className="truncate">{opt.name}</span>
                                                        </div>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {/* 2. Todos los lenguajes */}
                                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">
                                    <div
                                        onClick={() => setIsLangOpen(!isLangOpen)}
                                        className="flex items-center justify-between cursor-pointer py-1 select-none"
                                    >
                                        <h3 className="category-header-title text-sm font-extrabold text-slate-900 dark:text-white tracking-tight">
                                            Todos los lenguajes
                                        </h3>
                                        <button className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                            <svg
                                                className={`w-4 h-4 transition-transform duration-200 ${isLangOpen ? 'rotate-180' : ''}`}
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </div>

                                    {isLangOpen && (
                                        <div className="space-y-1.5 pt-1">
                                            {LANGUAGE_OPTIONS.map((opt) => {
                                                const isSelected = selectedLanguage === opt.id;
                                                const count = opt.id === 'all' ? projects.length : (languageCounts[opt.id] || 0);
                                                return (
                                                    <label
                                                        key={opt.id}
                                                        onClick={() => setSelectedLanguage(opt.id)}
                                                        className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${isSelected
                                                            ? 'category-item-active font-bold'
                                                            : 'category-item-inactive'
                                                            }`}
                                                    >
                                                        <div className="flex items-center gap-2.5 truncate">
                                                            <input
                                                                type="radio"
                                                                name="sidebar_language"
                                                                checked={isSelected}
                                                                onChange={() => setSelectedLanguage(opt.id)}
                                                                className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                            />
                                                            <span className="truncate">{opt.name}</span>
                                                        </div>
                                                        {count > 0 && (
                                                            <span className={`text-[11px] px-2 py-0.5 rounded-full font-semibold ${isSelected ? 'category-badge-active' : 'category-badge-inactive'}`}>
                                                                {count}
                                                            </span>
                                                        )}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {/* 3. Todos los frameworks */}
                                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">
                                    <div
                                        onClick={() => setIsFrameworkOpen(!isFrameworkOpen)}
                                        className="flex items-center justify-between cursor-pointer py-1 select-none"
                                    >
                                        <h3 className="category-header-title text-sm font-extrabold text-slate-900 dark:text-white tracking-tight">
                                            Todos los frameworks
                                        </h3>
                                        <button className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                            <svg
                                                className={`w-4 h-4 transition-transform duration-200 ${isFrameworkOpen ? 'rotate-180' : ''}`}
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </div>

                                    {isFrameworkOpen && (
                                        <div className="space-y-1.5 pt-1">
                                            {FRAMEWORK_OPTIONS.map((opt) => {
                                                const isSelected = selectedFramework === opt.id;
                                                const count = opt.id === 'all' ? projects.length : (frameworkCounts[opt.id] || 0);
                                                return (
                                                    <label
                                                        key={opt.id}
                                                        onClick={() => setSelectedFramework(opt.id)}
                                                        className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${isSelected
                                                            ? 'category-item-active font-bold'
                                                            : 'category-item-inactive'
                                                            }`}
                                                    >
                                                        <div className="flex items-center gap-2.5 truncate">
                                                            <input
                                                                type="radio"
                                                                name="sidebar_framework"
                                                                checked={isSelected}
                                                                onChange={() => setSelectedFramework(opt.id)}
                                                                className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                            />
                                                            <span className="truncate">{opt.name}</span>
                                                        </div>
                                                        {count > 0 && (
                                                            <span className={`text-[11px] px-2 py-0.5 rounded-full font-semibold ${isSelected ? 'category-badge-active' : 'category-badge-inactive'}`}>
                                                                {count}
                                                            </span>
                                                        )}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>

                                {/* 4. Base de datos */}
                                <div className="pt-3 border-t border-slate-100 dark:border-slate-800 space-y-2">
                                    <div
                                        onClick={() => setIsDatabaseOpen(!isDatabaseOpen)}
                                        className="flex items-center justify-between cursor-pointer py-1 select-none"
                                    >
                                        <h3 className="category-header-title text-sm font-extrabold text-slate-900 dark:text-white tracking-tight">
                                            Base de datos
                                        </h3>
                                        <button className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                                            <svg
                                                className={`w-4 h-4 transition-transform duration-200 ${isDatabaseOpen ? 'rotate-180' : ''}`}
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </div>

                                    {isDatabaseOpen && (
                                        <div className="space-y-1.5 pt-1">
                                            {DATABASE_OPTIONS.map((opt) => {
                                                const isSelected = selectedDatabase === opt.id;
                                                const count = opt.id === 'all' ? projects.length : (databaseCounts[opt.id] || 0);
                                                return (
                                                    <label
                                                        key={opt.id}
                                                        onClick={() => setSelectedDatabase(opt.id)}
                                                        className={`flex items-center justify-between p-2 rounded-xl cursor-pointer text-xs transition ${isSelected
                                                            ? 'category-item-active font-bold'
                                                            : 'category-item-inactive'
                                                            }`}
                                                    >
                                                        <div className="flex items-center gap-2.5 truncate">
                                                            <input
                                                                type="radio"
                                                                name="sidebar_database"
                                                                checked={isSelected}
                                                                onChange={() => setSelectedDatabase(opt.id)}
                                                                className="text-[#1534e8] focus:ring-0 w-4 h-4 cursor-pointer accent-[#1534e8]"
                                                            />
                                                            <span className="truncate">{opt.name}</span>
                                                        </div>
                                                        {count > 0 && (
                                                            <span className={`text-[11px] px-2 py-0.5 rounded-full font-semibold ${isSelected ? 'category-badge-active' : 'category-badge-inactive'}`}>
                                                                {count}
                                                            </span>
                                                        )}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </aside>

                        {/* ---------------------------------------------------------------------
                            COLUMNA PRINCIPAL (DERECHA): TARJETAS DE PROYECTOS Y PAGINACIÓN
                            --------------------------------------------------------------------- */}
                        <div className="lg:col-span-8 xl:col-span-9 space-y-3.5 flex flex-col">
                            {/* Paginación superior */}
                            {renderPagination(true)}

                            {filteredProjects.length === 0 ? (
                                <div className="bg-white dark:bg-[#0f172a] rounded-3xl border border-slate-200 dark:border-slate-800 p-12 text-center shadow-xs flex-1 flex flex-col items-center justify-center min-h-[400px]">
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
                                        onClick={() => { setSearch(''); setSelectedLanguage('all'); setSelectedFramework('all'); setSelectedDatabase('all'); setSelectedCategory('all'); setSortBy('recent'); }}
                                        className="px-5 py-2 bg-[#1534e8] hover:bg-blue-700 text-white rounded-full text-xs font-bold transition cursor-pointer shadow-xs"
                                    >
                                        Restablecer filtros
                                    </button>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 auto-rows-max content-start min-h-[640px]">
                                    {paginatedProjects.map((project, idx) => {
                                        const tech = getTechInfo(project);
                                        const isFeatured = idx === 0 || project.status === 'running';
                                        const dbBadge = getDatabaseBadge(project.db_driver);

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

                                                {/* Contenido de la tarjeta */}
                                                <div className="px-3.5 pb-3.5 pt-1 flex-1 flex flex-col justify-between">
                                                    <div className="space-y-2 text-center">
                                                        {/* Título del proyecto */}
                                                        <h4 className="text-base font-black text-slate-900 dark:text-white tracking-tight leading-snug group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                                                            {project.name}
                                                        </h4>

                                                        {/* Badges de Categoría y Base de Datos */}
                                                        <div className="flex flex-wrap items-center justify-center gap-1.5">
                                                            {/* Categoría / Industria */}
                                                            <span className="inline-flex items-center text-[10px] font-bold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800/60 px-2.5 py-0.5 rounded-full shadow-2xs">
                                                                {project.category || 'General'}
                                                            </span>

                                                            {/* Base de Datos si existe */}
                                                            {dbBadge}
                                                        </div>

                                                        {/* Información del Estudiante Creador */}
                                                        <div className="pt-0.5">
                                                            <Link
                                                                href={route('student.profile', project.user_id || project.user?.id)}
                                                                className="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300 hover:text-blue-600 dark:hover:text-blue-400 font-semibold group/link transition"
                                                                title="Ver perfil del estudiante"
                                                            >
                                                                <span>Estudiante: <span className="underline decoration-slate-300 group-hover/link:decoration-blue-500 font-bold text-slate-800 dark:text-slate-100 capitalize">{project.user?.name || project.creator_name || 'Estudiante'}</span></span>
                                                                <svg className="w-3 h-3 text-slate-400 group-hover/link:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                </svg>
                                                            </Link>
                                                        </div>
                                                    </div>

                                                    {/* Footer de la tarjeta con acciones claras */}
                                                    <div className="mt-3.5 pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-1">
                                                        <div className="flex items-center gap-0.5">
                                                            {/* Botón Ver Perfil del Estudiante */}
                                                            <Link
                                                                href={route('student.profile', project.user_id || project.user?.id)}
                                                                className="flex items-center gap-1 px-1.5 py-1 rounded-lg text-[11px] font-semibold text-slate-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-slate-100 dark:hover:bg-slate-800/70 transition cursor-pointer"
                                                                title="Ver Perfil del Estudiante"
                                                            >
                                                                <svg className="w-3 h-3 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                                </svg>
                                                                <span>Perfil</span>
                                                            </Link>

                                                            {/* Botón Contactar Estudiante */}
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setContactStudent(project);
                                                                }}
                                                                className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 px-1.5 py-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800/70 transition cursor-pointer"
                                                                title="Contactar al estudiante por correo"
                                                            >
                                                                Contactar
                                                            </button>
                                                        </div>

                                                        {/* Botón Ver Demo en Vivo */}
                                                        <button
                                                            onClick={() => handleStartDemo(project)}
                                                            className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-extrabold text-white bg-[#1534e8] hover:bg-blue-700 shadow-xs hover:shadow-md transition-all cursor-pointer whitespace-nowrap shrink-0"
                                                            title="Ejecutar y probar la demo en vivo"
                                                        >
                                                            <svg className="w-2.5 h-2.5 fill-current shrink-0" viewBox="0 0 24 24">
                                                                <path d="M8 5v14l11-7z" />
                                                            </svg>
                                                            <span>Ver Demo en Vivo</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {/* Paginación inferior */}
                            {renderPagination(false)}
                        </div>
                    </div>
                </div>

                {/* =========================================================================
                    5. FOOTER INSTITUCIONAL
                    ========================================================================= */}
                <footer className="bg-white dark:bg-[#0b0f19] border-t border-slate-200 dark:border-slate-800 py-4 text-xs text-slate-500 dark:text-slate-400">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <span className="font-bold text-slate-800 dark:text-slate-200">&gt; nexus academic</span>
                            <span>• Vitrina de Talento de Software Universitario</span>
                        </div>
                        <p>© 2026 Nexus Academic Platform. Todos los derechos reservados.</p>
                    </div>
                </footer>

                {/* =========================================================================
                    6. MODAL: ¿CÓMO FUNCIONA LA VITRINA? (GUÍA PARA EVALUADORES EXTERNOS)
                    ========================================================================= */}
                {/* =========================================================================
                    6. MODAL: ¿CÓMO PROBAR LAS DEMOS? (GUÍA SENCILLA PARA EVALUADORES Y RECLUTADORES)
                    ========================================================================= */}
                {isHowItWorksOpen && (
                    <div className="fixed inset-0 z-50 bg-slate-950/75 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="w-full max-w-2xl bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-6">
                            <div className="flex items-start justify-between border-b border-slate-100 dark:border-slate-800 pb-4">
                                <div className="flex items-center gap-3">

                                    <div>
                                        <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                            ¿Cómo probar las aplicaciones interactivas?
                                        </h3>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">
                                            Guía rápida para evaluar proyectos de software en vivo sin necesidad de instalar nada
                                        </p>
                                    </div>
                                </div>
                                <button
                                    onClick={() => setIsHowItWorksOpen(false)}
                                    className="p-1 px-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            {/* Pasos explicativos sencillos y prácticos */}
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-800 space-y-2">
                                    <div className="w-8 h-8 rounded-full bg-white dark:bg-slate-900 border-2 border-[#1534e8] dark:border-blue-500 text-[#1534e8] dark:text-blue-400 flex items-center justify-center text-xs font-black shadow-xs">
                                        1
                                    </div>
                                    <h4 className="font-bold text-xs text-slate-900 dark:text-white">Elige un Proyecto</h4>
                                    <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                        Navega por las tarjetas, filtra por área de interés o usa el buscador para encontrar la aplicación que deseas evaluar.
                                    </p>
                                </div>

                                <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-800 space-y-2">
                                    <div className="w-8 h-8 rounded-full bg-white dark:bg-slate-900 border-2 border-[#1534e8] dark:border-blue-500 text-[#1534e8] dark:text-blue-400 flex items-center justify-center text-xs font-black shadow-xs">
                                        2
                                    </div>
                                    <h4 className="font-bold text-xs text-slate-900 dark:text-white">Inicia la Demostración</h4>
                                    <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                        Presiona el botón <strong>"Ver Demo"</strong>. La aplicación se encenderá en vivo en pocos segundos directamente en tu pantalla.
                                    </p>
                                </div>

                                <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-800 space-y-2">
                                    <div className="w-8 h-8 rounded-full bg-white dark:bg-slate-900 border-2 border-[#1534e8] dark:border-blue-500 text-[#1534e8] dark:text-blue-400 flex items-center justify-center text-xs font-black shadow-xs">
                                        3
                                    </div>
                                    <h4 className="font-bold text-xs text-slate-900 dark:text-white">Prueba sin Registrarte</h4>
                                    <p className="text-[11px] text-slate-600 dark:text-slate-400 leading-relaxed">
                                        En la barra lateral de la demo encontrarás credenciales de prueba predefinidas (usuario y clave) para explorar todas las funciones.
                                    </p>
                                </div>
                            </div>

                            {/* Banner amigable para reclutadores */}
                            <div className="p-3.5 rounded-2xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/80 flex items-start gap-3">

                                <div className="text-xs space-y-0.5">
                                    <p className="font-bold text-blue-900 dark:text-blue-200">Demostraciones 100% interactivas y seguras</p>
                                    <p className="text-blue-700 dark:text-blue-300 leading-relaxed text-[11px]">
                                        Puedes probar y manipular los sistemas con total tranquilidad. No necesitas instalar programas en tu equipo y cada demo incluye datos de prueba listos para interactuar.
                                    </p>
                                </div>
                            </div>

                            <div className="flex justify-end pt-2">
                                <button
                                    onClick={() => setIsHowItWorksOpen(false)}
                                    className="px-6 py-2 rounded-full bg-[#1534e8] hover:bg-blue-700 text-white font-bold text-xs shadow-xs transition cursor-pointer"
                                >
                                    Entendido, explorar proyectos
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* =========================================================================
                    7. MODAL DE DEMO EN VIVO (100% Funcional e Integrado en Sandbox gVisor)
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
                                        <h3 className="demo-modal-title font-bold text-sm flex items-center gap-2 text-slate-900 dark:text-white">
                                            <span>{activeDemo.name}</span>
                                            <span className="demo-modal-badge text-[10px] font-mono px-2 py-0.5 rounded-md font-semibold">
                                                http://{activeDemo.subdomain}.localhost
                                            </span>
                                        </h3>
                                        <p className="demo-modal-subtitle text-[11px]">
                                            Estudiante: <strong className="font-semibold">{activeDemo.user.name}</strong> • Entorno Sandbox gVisor
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
                                        className="demo-btn-secondary px-3 py-1.5 rounded-lg text-xs font-semibold transition flex items-center gap-1.5 shadow-2xs cursor-pointer"
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
                                        className="demo-btn-close px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer"
                                    >
                                        Cerrar Demo
                                    </button>
                                </div>
                            </div>

                            {/* Banner de Aislamiento de Seguridad */}
                            <div className="demo-modal-security-banner px-6 py-2 text-xs flex items-center gap-2 border-b">
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
                                    <div className="demo-evaluator-sidebar w-80 p-5 flex flex-col justify-between shrink-0 hidden md:flex overflow-y-auto">
                                        <div className="space-y-4">
                                            <div className="demo-evaluator-header flex items-center space-x-2">
                                                <svg className="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                                </svg>
                                                <h4 className="font-bold text-xs tracking-wider uppercase">Guía del Evaluador</h4>
                                            </div>

                                            {(() => {
                                                const creds = getDemoCredentials(activeDemo);
                                                return (
                                                    <div className="space-y-4">
                                                        {/* Acceso de Pruebas */}
                                                        <div className="demo-evaluator-credentials-card p-3.5 rounded-xl border">
                                                            <span className="demo-evaluator-credentials-title text-[10px] font-bold uppercase tracking-wider block mb-2">Acceso de Pruebas</span>
                                                            <div className="space-y-2 text-xs">
                                                                <div className="flex justify-between items-center">
                                                                    <span className="demo-evaluator-label">{creds.userLabel || 'Usuario:'}</span>
                                                                    <span className="demo-evaluator-value font-mono font-bold px-2.5 py-0.5 rounded border select-all" title="Clic para seleccionar">
                                                                        {creds.user}
                                                                    </span>
                                                                </div>
                                                                {creds.altUser && (
                                                                    <div className="flex justify-between items-center text-[11px] opacity-80">
                                                                        <span className="demo-evaluator-label">Alternativo:</span>
                                                                        <span className="demo-evaluator-value font-mono font-semibold px-2 py-0.5 rounded border select-all" title="Usuario alternativo">
                                                                            {creds.altUser}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                                <div className="flex justify-between items-center">
                                                                    <span className="demo-evaluator-label">{creds.passLabel || 'Clave:'}</span>
                                                                    <span className="demo-evaluator-value font-mono font-bold px-2.5 py-0.5 rounded border select-all" title="Clic para seleccionar">
                                                                        {creds.pass}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        {/* Recomendación de Uso */}
                                                        <div className="demo-evaluator-note-card text-xs space-y-1.5 leading-relaxed p-3.5 rounded-xl border">
                                                            <p className="demo-evaluator-note-title font-bold text-[11px]">Recomendación de Uso:</p>
                                                            <p className="demo-evaluator-note-text text-[11px] leading-relaxed">{creds.note}</p>
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

                                        <div className="pt-3 border-t demo-evaluator-footer text-center">
                                            <span className="text-[10px] font-mono">Nexus Academic PaaS v2.0</span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* =========================================================================
                    8. MODAL DE CONTACTO RECLUTADOR
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

function getDatabaseBadge(dbDriver) {
    const driver = (dbDriver || '').toLowerCase();
    if (driver === 'pgsql' || driver === 'postgres' || driver === 'postgresql') {
        return (
            <span
                title="Base de datos PostgreSQL aprovisionada"
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
                title="Base de datos MySQL aprovisionada"
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
                title="Base de datos NoSQL MongoDB aprovisionada"
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

// =========================================================================
// LOGOS SVG AUTÉNTICOS DE TECNOLOGÍAS
// =========================================================================
function JavaScriptLogo({ className = "w-9 h-9" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-800/60 flex items-center justify-center shadow-xs" title="JavaScript">
            <svg className={`${className} rounded-lg shadow-2xs`} viewBox="0 0 128 128">
                <rect width="128" height="128" rx="20" fill="#F7DF1E" />
                <path fill="#000000" d="M116.347 96.736c-.917-5.711-4.641-10.508-15.672-14.981-3.832-1.761-8.104-3.022-9.377-5.926-.452-1.69-.512-2.642-.226-3.665.821-3.32 4.784-4.355 7.925-3.403 2.023.678 3.938 2.237 5.093 4.724 5.402-3.498 5.391-3.475 9.163-5.879-1.381-2.141-2.118-3.129-3.022-4.045-3.249-3.629-7.676-5.498-14.756-5.355l-3.688.477c-3.534.893-6.902 2.748-8.877 5.235-5.926 6.724-4.236 18.492 2.975 23.335 7.104 5.332 17.54 6.545 18.873 11.531 1.297 6.104-4.486 8.08-10.234 7.378-4.236-.881-6.592-3.034-9.139-6.949-4.688 2.713-4.688 2.713-9.508 5.485 1.143 2.499 2.344 3.63 4.26 5.795 9.068 9.198 31.76 8.746 35.83-5.176.165-.478 1.261-3.666.38-8.581zM69.462 58.943H57.753l-.048 30.272c0 6.438.333 12.34-.714 14.149-1.713 3.558-6.152 3.117-8.175 2.427-2.059-1.012-3.106-2.451-4.319-4.485-.333-.584-.583-1.036-.667-1.071l-9.52 5.83c1.583 3.249 3.915 6.069 6.902 7.901 4.462 2.678 10.459 3.499 16.731 2.059 4.082-1.189 7.604-3.652 9.448-7.401 2.666-4.915 2.094-10.864 2.07-17.444.06-10.735.001-21.468.001-32.237z" />
            </svg>
        </div>
    );
}

function GoLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-[#00ADD8]/15 dark:bg-[#00ADD8]/25 border border-[#00ADD8]/40 flex items-center justify-center shadow-xs" title="Go (Golang)">
            <span className="font-sans font-black text-[#00ADD8] text-2xl tracking-tighter select-none">GO</span>
        </div>
    );
}

function NodeJsLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-[#5FA04E]/15 dark:bg-[#5FA04E]/25 border border-[#5FA04E]/35 flex items-center justify-center shadow-xs" title="Node.js">
            <svg className={className} viewBox="0 0 256 289" fill="none">
                <path d="M128 0L256 73.9V215.1L128 289L0 215.1V73.9L128 0Z" fill="#5FA04E" />
                <path d="M128 24.3L234.9 86V203L128 264.7L21.1 203V86L128 24.3Z" fill="#215732" />
                <path d="M112 184c-7-3-12-8-15-14l16-9c2 4 5 7 9 8 5 2 11 1 14-2s5-7 5-11v-4c-3 4-7 6-12 7s-10 0-14-3c-5-3-8-7-10-12s-4-11-4-17c0-7 1-12 4-17s7-9 12-12c4-3 9-4 15-4 5 0 9 1 13 3s7 5 9 9v-10h18v70c0 8-2 15-7 20s-10 8-17 9c-7 0-13-1-19-4zm15-40c4 0 7-1 9-3s4-5 4-9v-13c-1-4-2-7-5-9s-5-3-9-3c-3 0-6 1-8 3s-4 5-5 8c-1 4-1 8 0 12s2 7 4 10c2 2 6 4 10 4z" fill="#FFFFFF" />
            </svg>
        </div>
    );
}

function ExpressLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800/80 border border-slate-300 dark:border-slate-700 flex items-center justify-center shadow-xs" title="Express.js">
            <svg className={`${className} text-slate-800 dark:text-slate-100 fill-current`} viewBox="0 0 128 128">
                <path d="M126.67 98.44c-4.56 1.16-7.38.05-9.91-3.75-5.68-8.51-11.95-16.63-18-24.9-.78-1.07-1.59-2.12-2.6-3.45C89 76 81.85 85.2 75.14 94.77c-2.4 3.42-4.92 4.91-9.4 3.7l26.92-36.13L67.6 29.71c4.31-.84 7.29-.41 9.93 3.45 5.83 8.52 12.26 16.63 18.67 25.21 6.45-8.55 12.8-16.67 18.8-25.11 2.41-3.42 5-4.72 9.33-3.46-3.28 4.35-6.49 8.63-9.72 12.88-4.36 5.73-8.64 11.53-13.16 17.14-1.61 2-1.35 3.3.09 5.19C109.9 76 118.16 87.1 126.67 98.44zM1.33 61.74c.72-3.61 1.2-7.29 2.2-10.83 6-21.43 30.6-30.34 47.5-17.06C60.93 41.64 63.39 52.62 62.9 65H7.1c-.84 22.21 15.15 35.62 35.53 28.78 7.15-2.4 11.36-8 13.47-15 1.07-3.51 2.84-4.06 6.14-3.06-1.69 8.76-5.52 16.08-13.52 20.66-12 6.86-29.13 4.64-38.14-4.89C5.26 85.89 3 78.92 2 71.39c-.15-1.2-.46-2.38-.7-3.57q.03-3.04.03-6.08zm5.87-1.49h50.43c-.33-16.06-10.33-27.47-24-27.57-15-.12-25.78 11.02-26.43 27.57z" />
            </svg>
        </div>
    );
}

function ReactLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-cyan-50 dark:bg-cyan-950/40 border border-cyan-200 dark:border-cyan-800/60 flex items-center justify-center shadow-xs" title="React">
            <svg className={className} viewBox="-11.5 -10.23174 23 20.46348" fill="none">
                <circle cx="0" cy="0" r="2.05" fill="#00D8FF" />
                <g stroke="#00D8FF" strokeWidth="1" fill="none">
                    <ellipse rx="11" ry="4.2" />
                    <ellipse rx="11" ry="4.2" transform="rotate(60)" />
                    <ellipse rx="11" ry="4.2" transform="rotate(120)" />
                </g>
            </svg>
        </div>
    );
}

function VueLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 flex items-center justify-center shadow-xs" title="Vue.js">
            <svg className={className} viewBox="0 0 256 221">
                <path d="M204.8 0H256L128 220.8L0 0h97.92L128 51.2L157.44 0h47.36z" fill="#42B883" />
                <path d="M0 0l128 220.8L256 0h-51.2L128 132.48L50.56 0H0z" fill="#35495E" />
            </svg>
        </div>
    );
}

function NextJsLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 flex items-center justify-center shadow-xs" title="Next.js">
            <svg className={`${className} text-slate-900 dark:text-white fill-current`} viewBox="0 0 180 180">
                <mask height="180" id="mask0" maskUnits="userSpaceOnUse" width="180" x="0" y="0" style={{ maskType: 'alpha' }}>
                    <circle cx="90" cy="90" fill="black" r="90" />
                </mask>
                <g mask="url(#mask0)">
                    <circle cx="90" cy="90" data-circle="true" fill="currentColor" r="90" />
                    <path d="M149.508 157.52L69.142 54H54V125.97H66.1136V69.3836L139.999 164.845C143.333 162.614 146.509 160.165 149.508 157.52Z" fill="white" />
                    <rect fill="white" height="72" width="12" x="115" y="54" />
                </g>
            </svg>
        </div>
    );
}

function PythonLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-blue-50 dark:bg-slate-800/80 border border-blue-200 dark:border-slate-700 flex items-center justify-center shadow-xs" title="Python">
            <svg className={className} viewBox="0 0 110 110" fill="none">
                <path d="M53.5 3C29.6 3 31.2 13.4 31.2 13.4L31.3 24.1H54.2V27.5H23.3C13.9 27.5 5.5 33.1 5.5 45.4C5.5 57.8 12.8 61.9 19.3 62.7V51.7C19.3 39.3 27.7 39.2 27.7 39.2H47.4C54.4 39.2 59.9 33.7 59.9 26.6V7.1C59.9 7.1 61.3 3 53.5 3ZM40.8 10.3C43.4 10.3 45.5 12.4 45.5 15C45.5 17.6 43.4 19.7 40.8 19.7C38.2 19.7 36.1 17.6 36.1 15C36.1 12.4 38.2 10.3 40.8 10.3Z" fill="#3776AB" />
                <path d="M56.5 107C80.4 107 78.8 96.6 78.8 96.6L78.7 85.9H55.8V82.5H86.7C96.1 82.5 104.5 76.9 104.5 64.6C104.5 52.2 97.2 48.1 90.7 47.3V58.3C90.7 70.7 82.3 70.8 82.3 70.8H62.6C55.6 70.8 50.1 76.3 50.1 83.4V102.9C50.1 102.9 48.7 107 56.5 107ZM69.2 99.7C66.6 99.7 64.5 97.6 64.5 95C64.5 92.4 66.6 90.3 69.2 90.3C71.8 90.3 73.9 92.4 73.9 95C73.9 97.6 71.8 99.7 69.2 99.7Z" fill="#FFD438" />
            </svg>
        </div>
    );
}

function DjangoLogo() {
    return (
        <div className="w-16 h-12 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-300 dark:border-emerald-800/80 flex items-center justify-center shadow-xs px-2" title="Django">
            <span className="font-serif font-black text-emerald-800 dark:text-emerald-300 text-xs tracking-tight">django</span>
        </div>
    );
}

function FastApiLogo() {
    return (
        <div className="w-16 h-12 rounded-2xl bg-teal-50 dark:bg-teal-950/40 border border-teal-200 dark:border-teal-800/60 flex items-center justify-center shadow-xs gap-1 px-2" title="FastAPI">
            <svg className="w-4 h-4 text-[#059669] fill-current" viewBox="0 0 24 24">
                <path d="M12 0L2 12h7v12l13-14h-8l3-10z" />
            </svg>
            <span className="font-bold text-teal-800 dark:text-teal-300 text-xs tracking-tight">FastAPI</span>
        </div>
    );
}

function FlaskLogo() {
    return (
        <div className="w-16 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 flex items-center justify-center shadow-xs gap-1.5 px-2" title="Flask">
            <svg className="w-4 h-4 text-slate-800 dark:text-slate-100" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M9 3h6M10 3v5l-6 11a2 2 0 002 2h12a2 2 0 002-2L14 8V3" />
            </svg>
            <span className="font-bold text-slate-800 dark:text-slate-200 text-xs">Flask</span>
        </div>
    );
}

function PhpLogo({ className = "w-12 h-7" }) {
    return (
        <div className="w-18 h-12 rounded-2xl bg-[#777BB4]/15 dark:bg-[#777BB4]/25 border border-[#777BB4]/40 flex items-center justify-center shadow-xs px-2" title="PHP">
            <svg className={className} viewBox="0 0 128 68" fill="none">
                <ellipse cx="64" cy="34" rx="60" ry="30" fill="#777BB4" />
                <ellipse cx="64" cy="34" rx="55" ry="26" fill="#8892BF" />
                <text x="64" y="44" fontFamily="sans-serif" fontSize="28" fontWeight="900" fontStyle="italic" textAnchor="middle" fill="#FFFFFF">php</text>
            </svg>
        </div>
    );
}

function LaravelLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 p-2.5 flex items-center justify-center shadow-xs" title="Laravel">
            <svg className={className} viewBox="0 0 50 50" fill="none">
                <path d="M25 4L4 16V34L25 46L46 34V16L25 4Z" stroke="#FF2D20" strokeWidth="2.5" strokeLinejoin="round" fill="#FF2D20" fillOpacity="0.1" />
                <path d="M25 18L13 25V35L25 42L37 35V25L25 18Z" fill="#FF2D20" />
                <path d="M25 4L37 11L25 18L13 11L25 4Z" fill="#FF5346" />
                <path d="M46 16L37 22V32L46 26V16Z" fill="#E02418" />
            </svg>
        </div>
    );
}

function JavaLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-800/60 flex items-center justify-center shadow-xs" title="Java">
            <svg className={className} viewBox="0 0 24 24" fill="none">
                <path d="M4 19c4 1 12 1 16 0M3 21c5 1.5 13 1.5 18 0" stroke="#E76F00" strokeWidth="1.8" strokeLinecap="round" />
                <path d="M16 8v5a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V8h12z" fill="#E76F00" fillOpacity="0.15" stroke="#E76F00" strokeWidth="1.8" />
                <path d="M16 10h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2" stroke="#E76F00" strokeWidth="1.8" />
                <path d="M8 3c0 2-2 3-2 5M12 2c0 2-2 3-2 5" stroke="#5382A1" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
        </div>
    );
}

function SpringBootLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/60 flex items-center justify-center shadow-xs" title="Spring Boot">
            <svg className={className} viewBox="0 0 24 24" fill="none">
                <path d="M21 3C11.5 3.5 4 11 4 20.5c3.5 0 9-1.5 12.5-5 3.5-3.5 4.5-9 4.5-12.5z" fill="#6DB33F" fillOpacity="0.25" stroke="#6DB33F" strokeWidth="2" />
                <path d="M10 14c2-2 5.5-3.5 8-4M7 17c1.5-1.5 4-2.5 6-3" stroke="#6DB33F" strokeWidth="2" strokeLinecap="round" />
            </svg>
        </div>
    );
}

function DotNetLogo() {
    return (
        <div className="w-14 h-14 rounded-2xl bg-purple-50 dark:bg-purple-950/40 border border-purple-200 dark:border-purple-800/60 flex items-center justify-center shadow-xs" title=".NET">
            <svg className="w-8 h-8" viewBox="0 0 24 24" fill="none">
                <rect x="2" y="4" width="20" height="16" rx="4" fill="#512BD4" />
                <text x="12" y="15.5" fontFamily="sans-serif" fontSize="8.5" fontWeight="900" textAnchor="middle" fill="#FFFFFF">.NET</text>
            </svg>
        </div>
    );
}

function RubyLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-14 h-14 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 p-2.5 flex items-center justify-center shadow-xs" title="Ruby">
            <svg className={className} viewBox="0 0 24 24" fill="none">
                <path d="M6 3h12l4 6-10 12L2 9l4-6z" fill="#CC342D" fillOpacity="0.2" stroke="#CC342D" strokeWidth="1.8" strokeLinejoin="round" />
                <path d="M2 9h20M12 21L7.5 9M12 21l4.5-12M6 3l1.5 6M18 3l-1.5 6M9 3l3 6M15 3l-3 6" stroke="#CC342D" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        </div>
    );
}

function RailsLogo({ className = "w-8 h-8" }) {
    return (
        <div className="w-16 h-12 rounded-2xl bg-red-50 dark:bg-red-950/40 border border-red-300 dark:border-red-800/80 flex items-center justify-center shadow-xs px-2" title="Ruby on Rails">
            <span className="font-sans font-black text-red-700 dark:text-red-400 text-xs tracking-tight">RAILS</span>
        </div>
    );
}

function DockerLogo({ className = "w-9 h-9" }) {
    return (
        <div className="w-16 h-14 rounded-2xl bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800/60 flex items-center justify-center shadow-xs" title="Docker">
            <svg className={className} viewBox="0 0 24 24" fill="#2496ED">
                <path d="M13.983 11.078h2.119a.186.186 0 00.186-.185V9.006a.186.186 0 00-.186-.186h-2.119a.185.185 0 00-.185.185v1.888c0 .102.083.185.185.185m-2.954-5.43h2.118a.186.186 0 00.186-.186V3.574a.186.186 0 00-.186-.185h-2.118a.185.185 0 00-.185.185v1.888c0 .102.082.185.185.185zm0 2.716h2.118a.187.187 0 00.186-.186V6.29a.186.186 0 00-.186-.185h-2.118a.185.185 0 00-.185.185v1.887c0 .102.082.186.185.186zm-2.93 0h2.12a.186.186 0 00.184-.186V6.29a.185.185 0 00-.185-.185H8.1a.185.185 0 00-.185.185v1.887c0 .102.083.186.185.186zm-2.964 0h2.119a.186.186 0 00.185-.186V6.29a.185.185 0 00-.185-.185H5.136a.186.186 0 00-.186.185v1.887c0 .102.084.186.186.186zm5.893 2.715h2.119a.186.186 0 00.186-.185V9.006a.186.186 0 00-.186-.186h-2.119a.185.185 0 00-.185.185v1.888c0 .102.082.185.185.185zm-2.93 0h2.12a.185.185 0 00.184-.185V9.006a.185.185 0 00-.184-.186H8.1a.185.185 0 00-.185.185v1.888c0 .102.083.185.185.185zm-2.964 0h2.119a.185.185 0 00.185-.185V9.006a.185.185 0 00-.185-.186H5.136a.186.186 0 00-.185.185v1.888c0 .102.084.185.186.185zm-2.928 0h2.119a.185.185 0 00.185-.185V9.006a.185.185 0 00-.185-.186H2.208a.186.186 0 00-.186.185v1.888c0 .102.084.185.186.185zM23.79 9.873c-.35-.55-1.12-.76-1.74-.53-.4.15-.75.42-.99.78-.34-.23-.74-.36-1.16-.36-.26 0-.52.05-.76.14-.14-.07-.3-.11-.47-.11h-.97c-.03 0-.06.01-.08.02-.03-.47-.14-.94-.33-1.38l-.05-.12a.208.208 0 00-.19-.13H1.94a.2.2 0 00-.18.11c-.53 1.05-.8 2.21-.8 3.39 0 5.14 3.92 9.4 9.17 9.84.7.06 1.41.09 2.12.09 3.86 0 7.55-1.37 10.45-3.88.35-.31.6-.72.7-1.18.31-1.39.5-2.81.56-4.24.03-.79.03-1.58 0-2.37-.02-.05-.08-.09-.17-.06z" />
            </svg>
        </div>
    );
}

function getTechInfo(project) {
    const lang = typeof project === 'string' ? project.toLowerCase() : (project?.language || '').toLowerCase();
    const fw = typeof project === 'object' ? (project?.framework || '').toLowerCase() : '';
    const hasBackend = typeof project === 'object' && (!!project?.backend_project_id || !!project?.backend_project || !!project?.backendProject);

    // 0. Si es un proyecto enlazado Fullstack (Frontend + Backend independiente)
    if (hasBackend) {
        const backendLang = (project?.backend_project?.language || project?.backendProject?.language || '').toLowerCase();
        let backendVisual = <PhpLogo className="w-9 h-6" />;
        if (backendLang === 'python') backendVisual = <PythonLogo className="w-7 h-7" />;
        else if (backendLang === 'nodejs') backendVisual = <NodeJsLogo className="w-7 h-7" />;
        else if (backendLang === 'dotnet') backendVisual = <DotNetLogo className="w-7 h-7" />;
        else if (backendLang === 'java') backendVisual = <JavaLogo className="w-7 h-7" />;

        return {
            name: 'Aplicación Fullstack Integrada',
            pillName: 'Fullstack (Front + API)',
            dotColor: 'bg-blue-600',
            shortInfo: 'Fullstack API',
            cardVisual: (
                <div className="flex items-center justify-center gap-2.5">
                    <ReactLogo className="w-7 h-7" />
                    <span className="text-slate-400 font-black text-xs">+</span>
                    {backendVisual}
                </div>
            )
        };
    }

    // 1. PHP
    if (lang === 'php') {
        if (fw === 'laravel') {
            return {
                name: 'Laravel / PHP',
                pillName: 'Laravel / PHP',
                dotColor: 'bg-red-500',
                shortInfo: 'Laravel / PHP',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <LaravelLogo />
                        <PhpLogo />
                    </div>
                )
            };
        }
        if (fw === 'symfony') {
            return {
                name: 'Symfony / PHP',
                pillName: 'Symfony / PHP',
                dotColor: 'bg-slate-800',
                shortInfo: 'Symfony / PHP',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <div className="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 flex items-center justify-center shadow-xs">
                            <span className="font-black text-slate-900 dark:text-white text-sm">SF</span>
                        </div>
                        <PhpLogo />
                    </div>
                )
            };
        }
        if (fw === 'codeigniter') {
            return {
                name: 'CodeIgniter / PHP',
                pillName: 'CodeIgniter / PHP',
                dotColor: 'bg-orange-600',
                shortInfo: 'CodeIgniter / PHP',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <div className="w-14 h-14 rounded-2xl bg-orange-50 dark:bg-orange-950/40 border border-orange-200 dark:border-orange-900/60 flex items-center justify-center shadow-xs">
                            <span className="font-black text-orange-600 text-xs font-bold">CI</span>
                        </div>
                        <PhpLogo />
                    </div>
                )
            };
        }
        // PHP Puro / Vanilla PHP (Sin Framework)
        return {
            name: 'PHP Nativo',
            pillName: 'PHP',
            dotColor: 'bg-indigo-500',
            shortInfo: 'PHP Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <PhpLogo />
                </div>
            )
        };
    }

    // 2. Node.js / JavaScript / TypeScript
    if (['nodejs', 'javascript', 'typescript', 'js', 'ts'].includes(lang)) {
        if (fw === 'react') {
            return {
                name: 'React & JavaScript',
                pillName: 'React / JS',
                dotColor: 'bg-cyan-500',
                shortInfo: 'React & JS',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <ReactLogo />
                        <JavaScriptLogo />
                    </div>
                )
            };
        }
        if (fw === 'vue') {
            return {
                name: 'Vue.js & JavaScript',
                pillName: 'Vue / JS',
                dotColor: 'bg-emerald-500',
                shortInfo: 'Vue & JS',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <VueLogo />
                        <JavaScriptLogo />
                    </div>
                )
            };
        }
        if (fw === 'express') {
            return {
                name: 'Express.js & Node.js',
                pillName: 'Express / Node.js',
                dotColor: 'bg-slate-600',
                shortInfo: 'Express / Node',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <ExpressLogo />
                        <NodeJsLogo />
                    </div>
                )
            };
        }
        if (fw === 'nextjs') {
            return {
                name: 'Next.js & React',
                pillName: 'Next.js / React',
                dotColor: 'bg-slate-900',
                shortInfo: 'Next.js / React',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <NextJsLogo />
                        <ReactLogo />
                    </div>
                )
            };
        }
        // Node.js Puro / Vanilla JS
        return {
            name: 'JavaScript / Node.js',
            pillName: 'JavaScript',
            dotColor: 'bg-yellow-500',
            shortInfo: 'JavaScript Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <JavaScriptLogo />
                </div>
            )
        };
    }

    // 3. Python
    if (lang === 'python') {
        if (fw === 'django') {
            return {
                name: 'Django / Python',
                pillName: 'Django / Python',
                dotColor: 'bg-emerald-700',
                shortInfo: 'Django / Python',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <PythonLogo />
                        <DjangoLogo />
                    </div>
                )
            };
        }
        if (fw === 'fastapi') {
            return {
                name: 'FastAPI / Python',
                pillName: 'FastAPI / Python',
                dotColor: 'bg-teal-600',
                shortInfo: 'FastAPI / Python',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <PythonLogo />
                        <FastApiLogo />
                    </div>
                )
            };
        }
        if (fw === 'flask') {
            return {
                name: 'Flask / Python',
                pillName: 'Flask / Python',
                dotColor: 'bg-slate-700',
                shortInfo: 'Flask / Python',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <PythonLogo />
                        <FlaskLogo />
                    </div>
                )
            };
        }
        // Python Puro (Sin Framework)
        return {
            name: 'Python',
            pillName: 'Python',
            dotColor: 'bg-blue-500',
            shortInfo: 'Python Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <PythonLogo />
                </div>
            )
        };
    }

    // 4. Java
    if (lang === 'java') {
        if (fw === 'springboot') {
            return {
                name: 'Spring Boot / Java',
                pillName: 'Spring Boot / Java',
                dotColor: 'bg-emerald-600',
                shortInfo: 'Spring Boot & Java',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <JavaLogo />
                        <SpringBootLogo />
                    </div>
                )
            };
        }
        return {
            name: 'Java Nativo',
            pillName: 'Java',
            dotColor: 'bg-orange-500',
            shortInfo: 'Java Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <JavaLogo />
                </div>
            )
        };
    }

    // 5. .NET / C#
    if (['dotnet', 'csharp', 'c#'].includes(lang)) {
        return {
            name: fw === 'aspnet' ? 'ASP.NET Core / C#' : 'C# (.NET)',
            pillName: fw === 'aspnet' ? 'ASP.NET / C#' : 'C# (.NET)',
            dotColor: 'bg-purple-500',
            shortInfo: 'C# (.NET)',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <DotNetLogo />
                </div>
            )
        };
    }

    // 6. Go
    if (lang === 'go' || lang === 'golang') {
        return {
            name: 'Go (Golang)',
            pillName: 'Go',
            dotColor: 'bg-cyan-500',
            shortInfo: 'Go Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <GoLogo />
                </div>
            )
        };
    }

    // 7. Ruby / Ruby on Rails
    if (lang === 'ruby') {
        if (fw === 'rails' || fw.includes('rails')) {
            return {
                name: 'Ruby on Rails',
                pillName: 'Ruby on Rails',
                dotColor: 'bg-rose-600',
                shortInfo: 'Ruby on Rails',
                cardVisual: (
                    <div className="flex items-center justify-center gap-3">
                        <RubyLogo />
                        <RailsLogo />
                    </div>
                )
            };
        }
        return {
            name: 'Ruby Nativo',
            pillName: 'Ruby',
            dotColor: 'bg-rose-500',
            shortInfo: 'Ruby Nativo',
            cardVisual: (
                <div className="flex items-center justify-center">
                    <RubyLogo />
                </div>
            )
        };
    }

    // Default / Dockerfile
    return {
        name: 'Contenedor Docker Universal',
        pillName: 'Docker Container',
        dotColor: 'bg-sky-500',
        shortInfo: 'Docker gVisor',
        cardVisual: (
            <div className="flex items-center justify-center">
                <DockerLogo />
            </div>
        )
    };
}

function getDemoCredentials(project) {
    if (project && project.demo_instructions) {
        // Captura variaciones: Usuario / Identificador, Usuario / Email, Nombre de usuario, Identificador, Correo, Email, etc.
        const userMatch = project.demo_instructions.match(/(?:Usuario\s*(?:\/|\()\s*(?:Identificador|Email)\)?|Nombre\s+de\s+usuario|Identificador|Correo\s*electr[oó]nico|Correo|Email|Usuario|User|Login)\s*:\s*([^\r\n]+)/i);
        const passMatch = project.demo_instructions.match(/(?:Clave|Contrase[ñn]a|Password|Pass)\s*:\s*([^\r\n]+)/i);
        
        if (userMatch && passMatch) {
            const rawUser = userMatch[1].trim();
            const rawPass = passMatch[1].trim();

            let primaryUser = rawUser;
            let altUser = null;
            const altMatch = rawUser.match(/^([^\s(]+)(?:\s*\(\s*o\s+([^)]+)\))?/i);
            if (altMatch) {
                primaryUser = altMatch[1].trim();
                if (altMatch[2]) {
                    altUser = altMatch[2].trim();
                }
            }

            const isEmail = primaryUser.includes('@');
            const isRedmine = (project.name || '').toLowerCase().includes('redmine') || 
                              project.demo_instructions.toLowerCase().includes('redmine') ||
                              project.demo_instructions.toLowerCase().includes('identificador');

            let userLabel = 'Usuario:';
            if (isEmail) {
                userLabel = 'Correo electrónico:';
            } else if (isRedmine) {
                userLabel = 'Identificador:';
            } else {
                userLabel = 'Nombre de usuario:';
            }

            let note = 'Usa estas credenciales de prueba preconfiguradas para iniciar sesión y evaluar el sistema.';
            if (isRedmine) {
                note = `Ingresa el Identificador "${primaryUser}"${altUser ? ` (o "${altUser}")` : ''} en el campo Identificador (no correo) para iniciar sesión en Redmine.`;
            } else if (isEmail) {
                note = 'Inicia sesión utilizando este correo electrónico y contraseña para acceder a la aplicación.';
            } else {
                note = 'Inicia sesión utilizando este nombre de usuario (no correo) y contraseña.';
            }

            return {
                userLabel,
                user: primaryUser,
                altUser,
                rawUser,
                pass: rawPass,
                passLabel: 'Clave:',
                note
            };
        }
    }

    const name = (project?.name || '').toLowerCase();
    if (name.includes('redmine')) {
        return {
            userLabel: 'Identificador:',
            user: 'test',
            altUser: 'admin',
            pass: 'Password123!',
            passLabel: 'Clave:',
            note: 'Ingresa "test" o "admin" en el campo Identificador (no correo) y la clave para acceder a Redmine.'
        };
    }
    if (name.includes('django')) {
        return {
            userLabel: 'Nombre de usuario:',
            user: 'admin',
            pass: 'password',
            passLabel: 'Clave:',
            note: 'Inicia sesión en el panel de administración de Django con el usuario de superadministrador.'
        };
    }
    if (name.includes('grocy')) {
        return {
            userLabel: 'Nombre de usuario:',
            user: 'admin',
            pass: 'admin',
            passLabel: 'Clave:',
            note: 'Inicia sesión en Grocy con el usuario de administrador predeterminado.'
        };
    }
    if (name.includes('crater')) {
        return {
            userLabel: 'Correo electrónico:',
            user: 'admin@craterapp.com',
            pass: 'password',
            passLabel: 'Clave:',
            note: 'Inicia sesión en Crater Invoice con estas credenciales de administrador para explorar facturas, clientes y reportes.'
        };
    }
    if (name.includes('tienda') || name.includes('sequelize')) {
        return {
            userLabel: 'Correo electrónico:',
            user: 'admin@example.com',
            pass: 'password',
            passLabel: 'Clave:',
            note: 'Inicia sesión en la demo con estas credenciales para acceder al panel de administrador y gestionar registros.'
        };
    }
    if (name.includes('mongo')) {
        return {
            userLabel: 'Acceso:',
            user: '(Ingreso libre)',
            pass: '(Sin autenticación)',
            passLabel: 'Clave:',
            note: 'Este proyecto usa base de datos MongoDB (NoSQL). No requiere inicio de sesión; usa el formulario directamente para insertar usuarios en la base de datos.'
        };
    }
    if (name.includes('jekyll')) {
        return {
            userLabel: 'Acceso:',
            user: '(Navegación libre)',
            pass: '(Sin autenticación)',
            passLabel: 'Modo:',
            note: 'Este proyecto es un sitio web estático generado con Jekyll (Ruby). Puedes navegar por todas sus páginas, tutoriales y artículos sin iniciar sesión.'
        };
    }
    return {
        userLabel: 'Correo / Usuario:',
        user: 'admin@example.com',
        pass: 'password',
        passLabel: 'Clave:',
        note: 'Usa estas credenciales predeterminadas para iniciar sesión y evaluar las funciones del sistema.'
    };
}
