import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function AdminDashboard({ telemetry: initialTelemetry, metrics, students, projects }) {
    const [telemetry, setTelemetry] = useState(initialTelemetry);
    const [isRefreshingTelemetry, setIsRefreshingTelemetry] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(true);

    // Active sub-navigation tab
    const [activeTab, setActiveTab] = useState('telemetry'); // 'telemetry' | 'moderation' | 'students' | 'metrics'

    // Moderation search & filters
    const [projectSearch, setProjectSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [languageFilter, setLanguageFilter] = useState('all');

    // Droptable Pagination & Expanded Rows
    const [currentPage, setCurrentPage] = useState(1);
    const pageSize = 8;
    const [expandedRows, setExpandedRows] = useState(new Set());

    const toggleRow = (id) => {
        setExpandedRows(prev => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };

    // Student directory search & pagination
    const [studentSearch, setStudentSearch] = useState('');
    const [studentStatusFilter, setStudentStatusFilter] = useState('all');
    const [studentPage, setStudentPage] = useState(1);
    const studentPageSize = 8;

    // Modals
    const [suspendModal, setSuspendModal] = useState({ isOpen: false, project: null, reason: '' });
    const [blockModal, setBlockModal] = useState({ isOpen: false, student: null, reason: '' });
    const [logModal, setLogModal] = useState({ isOpen: false, project: null, buildLog: '', containerLog: '', activeLogTab: 'build', loading: false });
    const [pruneResultModal, setPruneResultModal] = useState({ isOpen: false, message: '', reclaimed: '', loading: false });

    // Toast alert feedback
    const [toastMessage, setToastMessage] = useState(null);

    const showToast = (msg) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 4000);
    };

    // Telemetry live polling
    const fetchLiveTelemetry = async () => {
        setIsRefreshingTelemetry(true);
        try {
            const res = await fetch(route('admin.telemetry'));
            if (res.ok) {
                const data = await res.json();
                setTelemetry(data);
            }
        } catch (err) {
            console.error('Error fetching telemetry:', err);
        } finally {
            setIsRefreshingTelemetry(false);
        }
    };

    useEffect(() => {
        if (!autoRefresh) return;
        const interval = setInterval(fetchLiveTelemetry, 12000);
        return () => clearInterval(interval);
    }, [autoRefresh]);

    // Safe Docker Prune Action
    const handleSafeDockerPrune = async () => {
        if (!confirm('¿Deseas ejecutar la limpieza segura de Docker? Esto removerá capas intermedias temporales pero mantendrá la caché uleam_*_cache intacta.')) {
            return;
        }

        setPruneResultModal({ isOpen: true, message: '', reclaimed: '', loading: true });

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const res = await fetch(route('admin.docker.prune'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token || '',
                    'Accept': 'application/json',
                },
            });

            const data = await res.json();
            if (res.ok && data.success) {
                if (data.telemetry) setTelemetry(data.telemetry);
                setPruneResultModal({
                    isOpen: true,
                    message: data.message,
                    reclaimed: data.reclaimed,
                    loading: false,
                });
                showToast(`Limpieza completada: ${data.reclaimed} liberados.`);
            } else {
                setPruneResultModal({
                    isOpen: true,
                    message: data.error || 'Ocurrió un error al ejecutar prune.',
                    reclaimed: '0 B',
                    loading: false,
                });
            }
        } catch (error) {
            setPruneResultModal({
                isOpen: true,
                message: 'Fallo de conexión al ejecutar docker image prune: ' + error.message,
                reclaimed: '0 B',
                loading: false,
            });
        }
    };

    // Toggle Showcase Visibility
    const handleToggleShowcase = (project) => {
        router.patch(route('admin.projects.toggle-showcase', project.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                showToast(`Visibilidad de vitrina actualizada para ${project.name}`);
            },
        });
    };

    // Open Suspend Modal
    const openSuspendModal = (project) => {
        setSuspendModal({
            isOpen: true,
            project,
            reason: '',
        });
    };

    // Submit Suspension
    const submitSuspension = (e) => {
        e.preventDefault();
        if (!suspendModal.reason.trim()) return;

        router.post(route('admin.projects.suspend', suspendModal.project.id), {
            reason: suspendModal.reason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSuspendModal({ isOpen: false, project: null, reason: '' });
                showToast(`Proyecto '${suspendModal.project.name}' pausado por infracción.`);
                fetchLiveTelemetry();
            },
        });
    };

    // Unsuspend Project
    const handleUnsuspend = (project) => {
        router.post(route('admin.projects.unsuspend', project.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                showToast(`Suspensión del proyecto '${project.name}' levantada.`);
                fetchLiveTelemetry();
            },
        });
    };

    // Fetch and Open Project Logs
    const handleOpenLogs = async (project) => {
        setLogModal({
            isOpen: true,
            project,
            buildLog: 'Cargando logs de compilación...',
            containerLog: 'Cargando logs del contenedor...',
            activeLogTab: 'build',
            loading: true,
        });

        try {
            const res = await fetch(route('admin.projects.build-logs', project.id));
            if (res.ok) {
                const data = await res.json();
                setLogModal({
                    isOpen: true,
                    project,
                    buildLog: data.build_log || 'Sin registros de compilación.',
                    containerLog: data.container_log || 'Sin registros de contenedor.',
                    activeLogTab: 'build',
                    loading: false,
                });
            } else {
                setLogModal(prev => ({
                    ...prev,
                    buildLog: 'Error al obtener registros de compilación.',
                    containerLog: 'Error al obtener registros del contenedor.',
                    loading: false,
                }));
            }
        } catch (error) {
            setLogModal(prev => ({
                ...prev,
                buildLog: 'Error de red: ' + error.message,
                containerLog: 'Error de red: ' + error.message,
                loading: false,
            }));
        }
    };

    // Toggle Student Active Status
    const handleToggleStudentStatus = (student) => {
        if (student.is_active) {
            setBlockModal({
                isOpen: true,
                student,
                reason: '',
            });
        } else {
            router.patch(route('admin.students.toggle-status', student.id), {}, {
                preserveScroll: true,
                onSuccess: () => {
                    showToast(`Cuenta del estudiante ${student.name} reactivada.`);
                },
            });
        }
    };

    const submitStudentBlock = (e) => {
        e.preventDefault();
        router.patch(route('admin.students.toggle-status', blockModal.student.id), {
            reason: blockModal.reason,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setBlockModal({ isOpen: false, student: null, reason: '' });
                showToast(`Cuenta de ${blockModal.student.name} inhabilitada.`);
            },
        });
    };

    // Filter projects
    const filteredProjects = projects.filter(p => {
        const matchesSearch =
            p.name.toLowerCase().includes(projectSearch.toLowerCase()) ||
            p.subdomain.toLowerCase().includes(projectSearch.toLowerCase()) ||
            (p.user?.name || '').toLowerCase().includes(projectSearch.toLowerCase()) ||
            (p.user?.email || '').toLowerCase().includes(projectSearch.toLowerCase()) ||
            (p.category || '').toLowerCase().includes(projectSearch.toLowerCase());

        const matchesStatus =
            statusFilter === 'all' ? true :
            statusFilter === 'running' ? p.status === 'running' :
            statusFilter === 'sleeping' ? p.status === 'sleeping' :
            statusFilter === 'stopped' ? p.status === 'stopped' :
            statusFilter === 'suspended' ? p.is_suspended :
            statusFilter === 'hidden' ? !p.is_visible_in_showcase : true;

        const matchesLang = languageFilter === 'all' ? true : p.language === languageFilter;

        return matchesSearch && matchesStatus && matchesLang;
    });

    // Reset pagination on search
    useEffect(() => {
        setCurrentPage(1);
    }, [projectSearch, statusFilter, languageFilter]);

    const totalProjectPages = Math.ceil(filteredProjects.length / pageSize) || 1;
    const paginatedProjects = filteredProjects.slice((currentPage - 1) * pageSize, currentPage * pageSize);

    // Filter students
    const filteredStudents = students.filter(s => {
        const matchesSearch =
            s.name.toLowerCase().includes(studentSearch.toLowerCase()) ||
            s.email.toLowerCase().includes(studentSearch.toLowerCase());

        const matchesStatus =
            studentStatusFilter === 'all' ? true :
            studentStatusFilter === 'active' ? s.is_active :
            studentStatusFilter === 'blocked' ? !s.is_active : true;

        return matchesSearch && matchesStatus;
    });

    const totalStudentPages = Math.ceil(filteredStudents.length / studentPageSize) || 1;
    const paginatedStudents = filteredStudents.slice((studentPage - 1) * studentPageSize, studentPage * studentPageSize);

    const availableLanguages = Array.from(new Set(projects.map(p => p.language).filter(Boolean)));

    return (
        <AuthenticatedLayout>
            <Head title="Consola de Administración - Nexus Academic" />

            <div className="py-4 sm:py-5 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto space-y-4">
                {/* Toast Notification */}
                {toastMessage && (
                    <div className="fixed bottom-5 right-5 z-50">
                        <div className="px-4 py-2.5 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-200 text-xs font-bold flex items-center gap-2">
                            <span>●</span>
                            {toastMessage}
                        </div>
                    </div>
                )}

                {/* Sub-navigation tabs - Clean without any icons or emojis */}
                <div className="flex border-b border-slate-200 dark:border-slate-800 pb-0">
                    <div className="flex gap-2 sm:gap-6 overflow-x-auto scrollbar-none text-xs sm:text-sm">
                        <button
                            onClick={() => setActiveTab('telemetry')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${
                                activeTab === 'telemetry'
                                    ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                        >
                            1. Telemetría del Host
                        </button>

                        <button
                            onClick={() => setActiveTab('moderation')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${
                                activeTab === 'moderation'
                                    ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                        >
                            2. Control de Vitrina & Moderación
                        </button>

                        <button
                            onClick={() => setActiveTab('students')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${
                                activeTab === 'students'
                                    ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                        >
                            3. Gestión de Estudiantes
                        </button>

                        <button
                            onClick={() => setActiveTab('metrics')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${
                                activeTab === 'metrics'
                                    ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                    : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                            }`}
                        >
                            4. Métricas Institucionales
                        </button>
                    </div>
                </div>

                {/* TAB 1: TELEMETRÍA - PROPORCIONES NATURALES Y AMIGABLES */}
                {activeTab === 'telemetry' && (
                    <div className="space-y-4">
                        {/* Fila 1: 3 Tarjetas de Recursos del Host */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {/* DISCO DEL SERVIDOR */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md">
                                <div className="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">Disco del Servidor</h3>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">Almacenamiento Host VM</p>
                                    </div>
                                    <span className="px-2.5 py-0.5 rounded-lg text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-blue-700 dark:text-blue-300 border border-slate-200 dark:border-slate-700 font-mono">
                                        {telemetry.disk.used_percent}% Ocupado
                                    </span>
                                </div>

                                <div className="flex justify-between items-baseline mb-2">
                                    <span className="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">Espacio Libre</span>
                                    <span className="text-2xl sm:text-3xl font-black text-blue-600 dark:text-blue-400">{telemetry.disk.free_gb} <span className="text-sm font-normal text-slate-500 dark:text-slate-400">GB</span></span>
                                </div>
                                <div className="flex justify-between text-xs text-slate-500 dark:text-slate-400 font-mono mb-3">
                                    <span>Usado: <strong className="text-slate-700 dark:text-slate-200">{telemetry.disk.used_gb} GB</strong></span>
                                    <span>Total: <strong className="text-slate-700 dark:text-slate-200">{telemetry.disk.total_gb} GB</strong></span>
                                </div>

                                <div className="w-full bg-slate-100 dark:bg-slate-950 rounded-full h-2.5 overflow-hidden border border-slate-200 dark:border-slate-800 p-0.5 mb-3">
                                    <div
                                        className="h-full rounded-full bg-blue-500 transition-all duration-300"
                                        style={{ width: `${Math.min(100, telemetry.disk.used_percent)}%` }}
                                    ></div>
                                </div>

                                <div className="pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>Partición raíz: <strong className="text-slate-700 dark:text-slate-300 font-mono">/ (overlayfs)</strong></span>
                                    <span className="text-slate-700 dark:text-slate-300 font-medium">Capacidad Saludable</span>
                                </div>
                            </div>

                            {/* MEMORIA RAM */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md">
                                <div className="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">Memoria RAM</h3>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">Carga Virtual Machine</p>
                                    </div>
                                    <span className="px-2.5 py-0.5 rounded-lg text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-indigo-700 dark:text-indigo-300 border border-slate-200 dark:border-slate-700 font-mono">
                                        {telemetry.ram.used_percent}% RAM
                                    </span>
                                </div>

                                <div className="flex justify-between items-baseline mb-2">
                                    <span className="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">RAM Libre</span>
                                    <span className="text-2xl sm:text-3xl font-black text-indigo-600 dark:text-indigo-400">{(telemetry.ram.free_mb / 1024).toFixed(1)} <span className="text-sm font-normal text-slate-500 dark:text-slate-400">GB</span></span>
                                </div>
                                <div className="flex justify-between text-xs text-slate-500 dark:text-slate-400 font-mono mb-3">
                                    <span>En uso: <strong className="text-slate-700 dark:text-slate-200">{(telemetry.ram.used_mb / 1024).toFixed(1)} GB</strong></span>
                                    <span>Total: <strong className="text-slate-700 dark:text-slate-200">{(telemetry.ram.total_mb / 1024).toFixed(1)} GB</strong></span>
                                </div>

                                <div className="w-full bg-slate-100 dark:bg-slate-950 rounded-full h-2.5 overflow-hidden border border-slate-200 dark:border-slate-800 p-0.5 mb-3">
                                    <div
                                        className="h-full rounded-full bg-indigo-500 transition-all duration-300"
                                        style={{ width: `${Math.min(100, telemetry.ram.used_percent)}%` }}
                                    ></div>
                                </div>

                                <div className="pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>Disponible: <strong className="text-slate-700 dark:text-slate-300">{telemetry.ram.free_mb} MB</strong></span>
                                    <span className="text-slate-700 dark:text-slate-300 font-medium">Holgura Operativa</span>
                                </div>
                            </div>

                            {/* CPU HOST */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md">
                                <div className="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">CPU Host</h3>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">{telemetry.cpu.cores} Núcleos Asignados</p>
                                    </div>
                                    <span className="px-2.5 py-0.5 rounded-lg text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-cyan-800 dark:text-cyan-300 border border-slate-200 dark:border-slate-700 font-mono">
                                        Load: {telemetry.cpu.load_1m}
                                    </span>
                                </div>

                                <div className="flex justify-between items-baseline mb-2">
                                    <span className="text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">Carga Estimada</span>
                                    <span className="text-2xl sm:text-3xl font-black text-cyan-700 dark:text-cyan-400">{telemetry.cpu.used_percent}%</span>
                                </div>
                                <div className="flex justify-between text-xs text-slate-500 dark:text-slate-400 font-mono mb-3">
                                    <span>5m: <strong className="text-slate-700 dark:text-slate-200">{telemetry.cpu.load_5m}</strong></span>
                                    <span>15m: <strong className="text-slate-700 dark:text-slate-200">{telemetry.cpu.load_15m}</strong></span>
                                </div>

                                <div className="w-full bg-slate-100 dark:bg-slate-950 rounded-full h-2.5 overflow-hidden border border-slate-200 dark:border-slate-800 p-0.5 mb-3">
                                    <div
                                        className="h-full rounded-full bg-cyan-500 transition-all duration-300"
                                        style={{ width: `${Math.min(100, telemetry.cpu.used_percent)}%` }}
                                    ></div>
                                </div>

                                <div className="pt-2.5 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <span>Sync: <strong className="text-slate-700 dark:text-slate-300 font-mono">{telemetry.timestamp}</strong></span>
                                    <span className="text-slate-700 dark:text-slate-300 font-medium">Baja Latencia</span>
                                </div>
                            </div>
                        </div>

                        {/* Fila 2: Estado de Contenedores y Safe Docker Prune */}
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            {/* Panel de Contenedores en Vivo */}
                            <div className="lg:col-span-2 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md space-y-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">
                                            Estado de Contenedores en Vivo
                                        </h3>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                            Proyectos sincronizados con Docker Daemon en tiempo real
                                        </p>
                                    </div>
                                    <span className="px-3 py-1 rounded-lg text-xs bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 font-bold">
                                        Total Proyectos: {telemetry.containers.total}
                                    </span>
                                </div>

                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950/80 border border-slate-200/80 dark:border-slate-800">
                                        <div className="text-[11px] font-bold text-blue-700 dark:text-blue-300 uppercase tracking-wider">
                                            Encendidos
                                        </div>
                                        <div className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white my-1">
                                            {telemetry.containers.running}
                                        </div>
                                        <p className="text-[10px] text-slate-500 dark:text-slate-400">En ejecución</p>
                                    </div>

                                    <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950/80 border border-slate-200/80 dark:border-slate-800">
                                        <div className="text-[11px] font-bold text-indigo-700 dark:text-indigo-300 uppercase tracking-wider">
                                            En Reposo
                                        </div>
                                        <div className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white my-1">
                                            {telemetry.containers.sleeping}
                                        </div>
                                        <p className="text-[10px] text-slate-500 dark:text-slate-400">Auto-Sleep activo</p>
                                    </div>

                                    <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950/80 border border-slate-200/80 dark:border-slate-800">
                                        <div className="text-[11px] font-bold text-slate-600 dark:text-slate-400 uppercase tracking-wider">
                                            Detenidos
                                        </div>
                                        <div className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white my-1">
                                            {telemetry.containers.stopped}
                                        </div>
                                        <p className="text-[10px] text-slate-500 dark:text-slate-400">Apagados</p>
                                    </div>

                                    <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950/80 border border-slate-200/80 dark:border-slate-800">
                                        <div className="text-[11px] font-bold text-amber-700 dark:text-amber-300 uppercase tracking-wider">
                                            Suspendidos
                                        </div>
                                        <div className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white my-1">
                                            {telemetry.containers.suspended}
                                        </div>
                                        <p className="text-[10px] text-slate-500 dark:text-slate-400">Por moderación</p>
                                    </div>
                                </div>

                            </div>

                            {/* Optimización de Almacenamiento */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md flex flex-col justify-between space-y-3">
                                <div className="space-y-2.5">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">
                                                Optimización de Almacenamiento
                                            </h3>
                                            <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                                Liberación de espacio en desuso
                                            </p>
                                        </div>
                                        <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-slate-100 dark:bg-slate-800 text-blue-700 dark:text-blue-300 border border-slate-200 dark:border-slate-700">
                                            Auto al 80%
                                        </span>
                                    </div>

                                    <p className="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                        Limpia archivos temporales y versiones antiguas para recuperar espacio en disco, protegiendo siempre los proyectos y bases de datos de los estudiantes.
                                    </p>

                                    <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 text-xs text-slate-600 dark:text-slate-400 space-y-1.5">
                                        <div className="flex items-center justify-between text-[11px]">
                                            <span className="font-semibold text-slate-700 dark:text-slate-300">Modo de ejecución:</span>
                                            <span className="text-slate-500 dark:text-slate-400">Automático al 80% o manual</span>
                                        </div>
                                        {telemetry.auto_prune?.last_run_at && (
                                            <div className="flex items-center justify-between text-[11px] pt-1 border-t border-slate-200/60 dark:border-slate-800/80">
                                                <span className="font-semibold text-slate-700 dark:text-slate-300">Última liberación:</span>
                                                <span className="font-medium text-blue-600 dark:text-blue-400">
                                                    {telemetry.auto_prune.last_reclaimed} recuperados ({telemetry.auto_prune.last_trigger === 'manual' ? 'Manual' : 'Automática'})
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <button
                                    onClick={handleSafeDockerPrune}
                                    className="w-full py-2.5 rounded-xl font-bold text-xs bg-slate-900 hover:bg-slate-800 text-white dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-100 border border-slate-900 dark:border-slate-700 transition flex items-center justify-center gap-2 shadow-xs cursor-pointer"
                                >
                                    Liberar Espacio Ahora
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB 2: DROPTABLE DE CONTROL DE VITRINA & MODERACIÓN */}
                {activeTab === 'moderation' && (
                    <div className="space-y-3">
                        {/* Search and filter controls */}
                        <div className="p-2.5 sm:p-3 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 flex flex-col md:flex-row gap-2.5 justify-between items-center text-xs shadow-xs dark:shadow-md">
                            <div className="relative w-full md:w-80">
                                <input
                                    type="text"
                                    value={projectSearch}
                                    onChange={(e) => setProjectSearch(e.target.value)}
                                    placeholder="Buscar por proyecto, estudiante, subdominio..."
                                    className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 text-xs focus:ring-1 focus:ring-blue-500"
                                />
                                <svg className="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 absolute left-2.5 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>

                            <div className="flex flex-wrap gap-2 w-full md:w-auto items-center">
                                <select
                                    value={statusFilter}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 text-xs"
                                >
                                    <option value="all">Todos los estados</option>
                                    <option value="running">Encendidos</option>
                                    <option value="sleeping">En Reposo</option>
                                    <option value="stopped">Apagados</option>
                                    <option value="suspended">Suspendidos</option>
                                    <option value="hidden">Ocultos de Vitrina</option>
                                </select>

                                <select
                                    value={languageFilter}
                                    onChange={(e) => setLanguageFilter(e.target.value)}
                                    className="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 text-xs"
                                >
                                    <option value="all">Todos los lenguajes</option>
                                    {availableLanguages.map(lang => (
                                        <option key={lang} value={lang}>{lang.toUpperCase()}</option>
                                    ))}
                                </select>

                                <span className="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 font-semibold">
                                    {filteredProjects.length} proyectos
                                </span>
                            </div>
                        </div>

                        {/* Droptable: 8 proyectos por página con fila desplegable */}
                        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 overflow-hidden shadow-xs dark:shadow-md">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead className="bg-slate-50 dark:bg-slate-950 text-[11px] text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 uppercase tracking-wider">
                                        <tr>
                                            <th className="w-8 px-3 py-2.5"></th>
                                            <th className="px-3.5 py-2.5">Proyecto</th>
                                            <th className="px-3.5 py-2.5">Estudiante ULEAM</th>
                                            <th className="px-3.5 py-2.5">Lenguaje / Stack</th>
                                            <th className="px-3.5 py-2.5">Estado VM</th>
                                            <th className="px-3.5 py-2.5 text-center">Vitrina Pública</th>
                                            <th className="px-3.5 py-2.5 text-center">Infracción / Pausa</th>
                                            <th className="px-3.5 py-2.5 text-right">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                        {paginatedProjects.length === 0 ? (
                                            <tr>
                                                <td colSpan="8" className="px-6 py-8 text-center text-slate-400 dark:text-slate-500">
                                                    No se encontraron proyectos.
                                                </td>
                                            </tr>
                                        ) : (
                                            paginatedProjects.map(project => {
                                                const isExpanded = expandedRows.has(project.id);
                                                return (
                                                    <React.Fragment key={project.id}>
                                                        <tr className="hover:bg-slate-50/80 dark:hover:bg-slate-800/30 transition">
                                                            {/* Drop chevron */}
                                                            <td className="px-3 py-2.5 text-center">
                                                                <button
                                                                    onClick={() => toggleRow(project.id)}
                                                                    className="p-1 rounded text-slate-400 hover:text-slate-700 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition cursor-pointer"
                                                                    title="Desplegar detalles del proyecto"
                                                                >
                                                                    <svg className={`w-3.5 h-3.5 transition-transform duration-200 ${isExpanded ? 'rotate-90 text-blue-500 dark:text-blue-400' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                                    </svg>
                                                                </button>
                                                            </td>

                                                            <td className="px-3.5 py-2.5">
                                                                <div className="font-bold text-slate-900 dark:text-white text-xs">
                                                                    {project.name}
                                                                </div>
                                                                <div className="text-[10px] text-slate-500 dark:text-slate-400 font-mono">
                                                                    {project.subdomain}.nexus-academic.software
                                                                </div>
                                                            </td>

                                                            <td className="px-3.5 py-2.5">
                                                                <div className="font-medium text-slate-800 dark:text-slate-200 text-xs">
                                                                    {project.user ? project.user.name : 'Desconocido'}
                                                                </div>
                                                                <div className="text-[10px] text-slate-500 dark:text-slate-400 font-mono">
                                                                    {project.user ? project.user.email : 'N/A'}
                                                                </div>
                                                            </td>

                                                            {/* Lenguaje / Stack */}
                                                            <td className="px-3.5 py-2.5">
                                                                <div className="flex flex-col gap-0.5">
                                                                    <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 w-fit">
                                                                        {project.language || 'docker'}
                                                                    </span>
                                                                    {project.framework && (
                                                                        <span className="text-[10px] text-slate-500 dark:text-slate-400 font-medium">
                                                                            {project.framework}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            </td>

                                                            {/* Estado VM - sin emojis, sin verdes/rojos */}
                                                            <td className="px-3.5 py-2.5">
                                                                <span className={`px-2.5 py-1 rounded text-[10px] font-bold uppercase tracking-wider ${
                                                                    project.status === 'running' ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30' :
                                                                    project.status === 'sleeping' ? 'bg-indigo-50 dark:bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-500/30' :
                                                                    project.status === 'building' ? 'bg-amber-50 dark:bg-amber-500/15 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30' :
                                                                    'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700'
                                                                }`}>
                                                                    {project.status === 'running' ? 'Running' :
                                                                     project.status === 'sleeping' ? 'Sleeping' :
                                                                     project.status === 'building' ? 'Building' :
                                                                     'Stopped'}
                                                                </span>
                                                            </td>

                                                            {/* Vitrina Pública Switch */}
                                                            <td className="px-3.5 py-2.5 text-center">
                                                                <button
                                                                    onClick={() => handleToggleShowcase(project)}
                                                                    className={`px-3 py-1 rounded-lg text-xs font-semibold transition cursor-pointer ${
                                                                        project.is_visible_in_showcase
                                                                            ? 'bg-blue-50 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-700/50 hover:bg-blue-100 dark:hover:bg-blue-800/50'
                                                                            : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 dark:hover:bg-slate-700'
                                                                    }`}
                                                                >
                                                                    {project.is_visible_in_showcase ? 'Visible' : 'Oculto'}
                                                                </button>
                                                            </td>

                                                            {/* Infracción / Pausa */}
                                                            <td className="px-3.5 py-2.5 text-center">
                                                                {project.is_suspended ? (
                                                                    <div className="space-y-0.5">
                                                                        <span className="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 dark:bg-amber-500/15 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30">
                                                                            Pausado
                                                                        </span>
                                                                        <div>
                                                                            <button
                                                                                onClick={() => handleUnsuspend(project)}
                                                                                className="text-[10px] text-blue-600 dark:text-blue-400 hover:underline font-semibold cursor-pointer"
                                                                            >
                                                                                Reanudar
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                ) : (
                                                                    <button
                                                                        onClick={() => openSuspendModal(project)}
                                                                        className="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
                                                                    >
                                                                        Pausar por Falta
                                                                    </button>
                                                                )}
                                                            </td>

                                                            <td className="px-3.5 py-2.5 text-right">
                                                                <button
                                                                    onClick={() => handleOpenLogs(project)}
                                                                    className="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition inline-flex items-center gap-1 cursor-pointer"
                                                                >
                                                                    <svg className="w-3 h-3 text-blue-500 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                                    </svg>
                                                                    Build Log
                                                                </button>
                                                            </td>
                                                        </tr>

                                                        {/* Droptable expanded details drawer */}
                                                        {isExpanded && (
                                                            <tr className="bg-slate-50/70 dark:bg-slate-950/70 border-b border-slate-200 dark:border-slate-800/80">
                                                                <td colSpan="8" className="px-6 py-3.5">
                                                                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3 text-[11px] text-slate-600 dark:text-slate-300">
                                                                        <div className="space-y-0.5">
                                                                            <span className="text-slate-400 dark:text-slate-500 font-bold uppercase text-[10px]">Repositorio GitHub</span>
                                                                            <p className="font-mono text-slate-800 dark:text-slate-300 break-all">{project.github_repo_url || 'Carga manual'}</p>
                                                                            <p className="text-slate-400 dark:text-slate-500 text-[10px]">Rama: {project.branch || 'main'}</p>
                                                                        </div>

                                                                        <div className="space-y-0.5">
                                                                            <span className="text-slate-400 dark:text-slate-500 font-bold uppercase text-[10px]">Base de Datos</span>
                                                                            <p className="font-mono text-slate-800 dark:text-slate-300">{project.db_name ? `${project.db_driver || 'pg'}: ${project.db_name}` : 'Sin BD'}</p>
                                                                            {project.db_user && <p className="text-slate-400 dark:text-slate-500 text-[10px]">Usuario: {project.db_user}</p>}
                                                                        </div>

                                                                        <div className="space-y-0.5">
                                                                            <span className="text-slate-400 dark:text-slate-500 font-bold uppercase text-[10px]">Evaluaciones Vitrina</span>
                                                                            <p className="font-mono text-slate-800 dark:text-slate-300">{project.demo_runs_count || 0} ejecuciones de demo</p>
                                                                            <p className="text-slate-400 dark:text-slate-500 text-[10px]">Categoría: {project.category || 'General'}</p>
                                                                        </div>

                                                                        <div className="space-y-0.5">
                                                                            <span className="text-slate-400 dark:text-slate-500 font-bold uppercase text-[10px]">Estado Moderación</span>
                                                                            {project.is_suspended ? (
                                                                                <p className="text-amber-700 dark:text-amber-300 font-semibold">{project.suspension_reason || 'Infracción registrada'}</p>
                                                                            ) : (
                                                                                <p className="text-slate-500 dark:text-slate-400">Sin sanciones normativas activas</p>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        )}
                                                    </React.Fragment>
                                                );
                                            })
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination Controls */}
                            <div className="px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                <div>
                                    Mostrando {paginatedProjects.length > 0 ? (currentPage - 1) * pageSize + 1 : 0} a {Math.min(currentPage * pageSize, filteredProjects.length)} de {filteredProjects.length} proyectos
                                </div>

                                <div className="flex items-center gap-1">
                                    <button
                                        onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                        disabled={currentPage === 1}
                                        className="px-2.5 py-1 rounded bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer"
                                    >
                                        Anterior
                                    </button>

                                    {Array.from({ length: totalProjectPages }, (_, i) => i + 1).map(p => (
                                        <button
                                            key={p}
                                            onClick={() => setCurrentPage(p)}
                                            className={`px-2.5 py-1 rounded text-xs font-bold transition cursor-pointer ${
                                                currentPage === p
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700'
                                            }`}
                                        >
                                            {p}
                                        </button>
                                    ))}

                                    <button
                                        onClick={() => setCurrentPage(p => Math.min(totalProjectPages, p + 1))}
                                        disabled={currentPage === totalProjectPages}
                                        className="px-2.5 py-1 rounded bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer"
                                    >
                                        Siguiente
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB 3: GESTIÓN DE CUENTAS DE ESTUDIANTES */}
                {activeTab === 'students' && (
                    <div className="space-y-3">
                        <div className="p-2.5 sm:p-3 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 flex flex-col md:flex-row gap-2.5 justify-between items-center text-xs shadow-xs dark:shadow-md">
                            <div className="relative w-full md:w-80">
                                <input
                                    type="text"
                                    value={studentSearch}
                                    onChange={(e) => setStudentSearch(e.target.value)}
                                    placeholder="Buscar estudiante por nombre o correo @uleam.edu.ec..."
                                    className="w-full pl-8 pr-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 text-xs focus:ring-1 focus:ring-blue-500"
                                />
                                <svg className="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 absolute left-2.5 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>

                            <div className="flex gap-2 w-full md:w-auto items-center">
                                <select
                                    value={studentStatusFilter}
                                    onChange={(e) => setStudentStatusFilter(e.target.value)}
                                    className="px-2.5 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 text-xs"
                                >
                                    <option value="all">Todas las cuentas</option>
                                    <option value="active">Cuentas Activas</option>
                                    <option value="blocked">Cuentas Inactivas / Bloqueadas</option>
                                </select>

                                <span className="px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-400 font-semibold">
                                    {filteredStudents.length} estudiantes
                                </span>
                            </div>
                        </div>

                        {/* Students Directory Table */}
                        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 overflow-hidden shadow-xs dark:shadow-md">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs text-slate-700 dark:text-slate-300">
                                    <thead className="bg-slate-50 dark:bg-slate-950 text-[11px] text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800 uppercase tracking-wider">
                                        <tr>
                                            <th className="px-3.5 py-2.5">Estudiante</th>
                                            <th className="px-3.5 py-2.5">Correo Institucional</th>
                                            <th className="px-3.5 py-2.5">Fecha de Registro</th>
                                            <th className="px-3.5 py-2.5">Aplicaciones Activas</th>
                                            <th className="px-3.5 py-2.5">Estado Cuenta</th>
                                            <th className="px-3.5 py-2.5 text-right">Moderación</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                        {paginatedStudents.length === 0 ? (
                                            <tr>
                                                <td colSpan="6" className="px-6 py-8 text-center text-slate-400 dark:text-slate-500">
                                                    No se encontraron estudiantes.
                                                </td>
                                            </tr>
                                        ) : (
                                            paginatedStudents.map(student => (
                                                <tr key={student.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/30 transition">
                                                    <td className="px-3.5 py-2.5">
                                                        <div className="font-bold text-slate-900 dark:text-white text-xs">
                                                            {student.name}
                                                        </div>
                                                        <div className="text-[10px] text-slate-500 dark:text-slate-400 font-mono">
                                                            UUID: {student.id.substring(0, 8)}...
                                                        </div>
                                                    </td>

                                                    <td className="px-3.5 py-2.5">
                                                        <span className="font-mono text-slate-700 dark:text-slate-300 bg-slate-100 dark:bg-slate-950 px-2 py-0.5 rounded border border-slate-200 dark:border-slate-800 text-[11px]">
                                                            {student.email}
                                                        </span>
                                                    </td>

                                                    <td className="px-3.5 py-2.5 text-slate-500 dark:text-slate-400">
                                                        {new Date(student.created_at).toLocaleDateString('es-EC', {
                                                            year: 'numeric',
                                                            month: 'short',
                                                            day: 'numeric'
                                                        })}
                                                    </td>

                                                    {/* Conteo de aplicaciones activas */}
                                                    <td className="px-3.5 py-2.5">
                                                        <span className="px-2 py-0.5 rounded text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                                                            {student.active_projects_count} / 3 permitidas
                                                        </span>
                                                    </td>

                                                    {/* Estado de la cuenta - sin verde/rojo */}
                                                    <td className="px-3.5 py-2.5">
                                                        {student.is_active ? (
                                                            <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30">
                                                                Activa
                                                            </span>
                                                        ) : (
                                                            <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-50 dark:bg-amber-500/15 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-500/30">
                                                                Bloqueada
                                                            </span>
                                                        )}
                                                    </td>

                                                    <td className="px-3.5 py-2.5 text-right">
                                                        <button
                                                            onClick={() => handleToggleStudentStatus(student)}
                                                            className="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 transition cursor-pointer"
                                                        >
                                                            {student.is_active ? 'Inactivar / Bloquear' : 'Reactivar Cuenta'}
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination Controls */}
                            <div className="px-4 py-2.5 bg-slate-50 dark:bg-slate-950 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                <div>
                                    Mostrando {paginatedStudents.length > 0 ? (studentPage - 1) * studentPageSize + 1 : 0} a {Math.min(studentPage * studentPageSize, filteredStudents.length)} de {filteredStudents.length} estudiantes
                                </div>

                                <div className="flex items-center gap-1">
                                    <button
                                        onClick={() => setStudentPage(p => Math.max(1, p - 1))}
                                        disabled={studentPage === 1}
                                        className="px-2.5 py-1 rounded bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 disabled:cursor-not-allowed text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 cursor-pointer"
                                    >
                                        Anterior
                                    </button>

                                    {Array.from({ length: totalStudentPages }, (_, i) => i + 1).map(p => (
                                        <button
                                            key={p}
                                            onClick={() => setStudentPage(p)}
                                            className={`px-2.5 py-1 rounded text-xs font-bold transition cursor-pointer ${
                                                studentPage === p
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700'
                                            }`}
                                        >
                                            {p}
                                        </button>
                                    ))}

                                    <button
                                        onClick={() => setStudentPage(p => Math.min(totalStudentPages, p + 1))}
                                        disabled={studentPage === totalStudentPages}
                                        className="px-2.5 py-1 rounded bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 disabled:opacity-40 text-slate-200 cursor-pointer"
                                    >
                                        Siguiente
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* TAB 4: MÉTRICAS INSTITUCIONALES - PROPORCIONES NATURALES Y AMIGABLES */}
                {activeTab === 'metrics' && (
                    <div className="space-y-4">
                        {/* Fila 1: 4 Tarjetas de Métricas Principales */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs dark:shadow-md">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Proyectos Alojados
                                </div>
                                <div className="text-3xl font-black text-slate-900 dark:text-white my-1.5">
                                    {metrics.total_projects}
                                </div>
                                <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                    {metrics.main_projects} apps principales · {metrics.backend_services} APIs
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs dark:shadow-md">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Estudiantes Registrados
                                </div>
                                <div className="text-3xl font-black text-indigo-600 dark:text-indigo-400 my-1.5">
                                    {metrics.total_students}
                                </div>
                                <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                    {metrics.active_students} con despliegues activos
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs dark:shadow-md">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Demostraciones Evaluadas
                                </div>
                                <div className="text-3xl font-black text-blue-600 dark:text-blue-400 my-1.5">
                                    {metrics.total_demo_runs}
                                </div>
                                <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                    Evaluaciones públicas en vitrina
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs dark:shadow-md">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                    Contactos Reclutadores
                                </div>
                                <div className="text-3xl font-black text-cyan-600 dark:text-cyan-400 my-1.5">
                                    {metrics.total_contacts}
                                </div>
                                <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                    Interacciones registradas
                                </div>
                            </div>
                        </div>

                        {/* Fila 2: Desglose por Lenguajes y Categorías */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            {/* Desglose por Lenguajes */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md flex flex-col justify-between">
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">
                                            Desglose por Lenguajes de Programación
                                        </h3>
                                    </div>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mb-3.5">
                                        Distribución cuantitativa para informes a la carrera
                                    </p>

                                    <div className="space-y-2">
                                        {metrics.languages_breakdown.map(item => (
                                            <div key={item.language} className="space-y-1">
                                                <div className="flex justify-between text-xs">
                                                    <span className="font-bold uppercase text-slate-800 dark:text-slate-200 text-xs">
                                                        {item.language}
                                                    </span>
                                                    <span className="text-slate-500 dark:text-slate-400 font-mono text-xs">
                                                        {item.count} proyectos ({item.percentage}%)
                                                    </span>
                                                </div>
                                                <div className="w-full bg-slate-100 dark:bg-slate-950 rounded-full h-2 overflow-hidden border border-slate-200 dark:border-slate-800 p-0.5">
                                                    <div
                                                        className="h-full rounded-full bg-blue-500 transition-all duration-300"
                                                        style={{ width: `${item.percentage}%` }}
                                                    ></div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="pt-3 mt-4 border-t border-slate-100 dark:border-slate-800/80 text-xs text-slate-500 dark:text-slate-400 flex justify-between">
                                    <span>Total Tecnologías Evaluadas</span>
                                    <span className="font-semibold text-slate-700 dark:text-slate-300">{metrics.languages_breakdown.length} Stacks</span>
                                </div>
                            </div>

                            {/* Categorías de Proyecto en cuadrícula de 2 columnas */}
                            <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 p-5 shadow-xs dark:shadow-md flex flex-col justify-between">
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <h3 className="text-sm font-bold text-slate-900 dark:text-white tracking-wide">
                                            Categorías de Proyecto
                                        </h3>
                                    </div>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400 mb-3.5">
                                        Distribución temática universitaria
                                    </p>

                                    <div className="grid grid-cols-2 gap-2.5">
                                        {metrics.categories_breakdown && metrics.categories_breakdown.length > 0 ? (
                                            metrics.categories_breakdown.map(cat => (
                                                <div key={cat.category} className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 flex justify-between items-center text-xs">
                                                    <span className="font-medium text-slate-700 dark:text-slate-200 capitalize truncate pr-1 text-xs">
                                                        {cat.category}
                                                    </span>
                                                    <span className="px-2 py-0.5 rounded-lg bg-slate-200/80 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono font-bold text-[11px] border border-slate-300 dark:border-slate-700 shrink-0">
                                                        {cat.count}
                                                    </span>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="col-span-2 text-xs text-slate-400 dark:text-slate-500 text-center py-4">
                                                Sin categorías asignadas en proyectos actuales.
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="pt-3 mt-4 border-t border-slate-100 dark:border-slate-800/80 text-xs text-slate-500 dark:text-slate-400 flex justify-between">
                                    <span>Total Áreas Temáticas</span>
                                    <span className="font-semibold text-slate-700 dark:text-slate-300">{metrics.categories_breakdown?.length || 0} Categorías</span>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* MODAL: PAUSAR PROYECTO */}
            {suspendModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-2xl space-y-3">
                        <div className="flex items-center gap-2 text-slate-900 dark:text-slate-200 font-bold text-sm">
                            <svg className="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Pausar Proyecto por Infracción Normativa
                        </div>

                        <p className="text-xs text-slate-600 dark:text-slate-400">
                            Pausarás el proyecto <strong>{suspendModal.project?.name}</strong> del estudiante <strong>{suspendModal.project?.user?.name}</strong>. Si está encendido se detendrá y no se mostrará en vitrina.
                        </p>

                        <form onSubmit={submitSuspension} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Motivo de la infracción para el estudiante:
                                </label>
                                <textarea
                                    value={suspendModal.reason}
                                    onChange={(e) => setSuspendModal(prev => ({ ...prev, reason: e.target.value }))}
                                    rows="3"
                                    required
                                    placeholder="Indica la causa: plagio en código, pruebas incompletas, contenido no autorizado..."
                                    className="w-full rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white p-2.5 text-xs focus:ring-1 focus:ring-blue-500"
                                ></textarea>
                            </div>

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setSuspendModal({ isOpen: false, project: null, reason: '' })}
                                    className="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 cursor-pointer"
                                >
                                    Confirmar Pausa
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: BLOQUEAR ESTUDIANTE */}
            {blockModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-2xl space-y-3">
                        <div className="flex items-center gap-2 text-slate-900 dark:text-slate-200 font-bold text-sm">
                            <svg className="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Inactivar / Bloquear Cuenta de Estudiante
                        </div>

                        <p className="text-xs text-slate-600 dark:text-slate-400">
                            Bloquearás el acceso de <strong>{blockModal.student?.name}</strong> ({blockModal.student?.email}). No podrá iniciar sesión en la plataforma.
                        </p>

                        <form onSubmit={submitStudentBlock} className="space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1">
                                    Motivo del bloqueo (opcional):
                                </label>
                                <textarea
                                    value={blockModal.reason}
                                    onChange={(e) => setBlockModal(prev => ({ ...prev, reason: e.target.value }))}
                                    rows="3"
                                    placeholder="Indica la razón: baja de matrícula, sanción académica..."
                                    className="w-full rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white p-2.5 text-xs focus:ring-1 focus:ring-blue-500"
                                ></textarea>
                            </div>

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setBlockModal({ isOpen: false, student: null, reason: '' })}
                                    className="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="px-3 py-1.5 rounded-lg text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 cursor-pointer"
                                >
                                    Bloquear Usuario
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: LOGS DE COMPILACIÓN (BUILD_LOG) & DOCKER LOGS */}
            {logModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                    <div className="w-full max-w-4xl rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-2xl space-y-3 max-h-[90vh] flex flex-col">
                        <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2.5">
                            <div>
                                <h3 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span className="p-0.5 px-1.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono text-[10px] border border-slate-200 dark:border-slate-700">LOGS</span>
                                    {logModal.project?.name}
                                </h3>
                                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                    {logModal.project?.student ? `Estudiante: ${logModal.project.student}${logModal.project.student_email ? ` (${logModal.project.student_email})` : ''}` : 'Estudiante institucional'} · Subdominio: {logModal.project?.subdomain || 'N/A'}
                                </p>
                            </div>

                            <button
                                onClick={() => setLogModal(prev => ({ ...prev, isOpen: false }))}
                                className="p-1.5 rounded-lg text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white bg-slate-100 dark:bg-slate-800 cursor-pointer"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Tabs for build log vs container log */}
                        <div className="flex gap-2">
                            <button
                                onClick={() => setLogModal(prev => ({ ...prev, activeLogTab: 'build' }))}
                                className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${
                                    logModal.activeLogTab === 'build'
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                                }`}
                            >
                                Logs de Compilación (build_log)
                            </button>
                            <button
                                onClick={() => setLogModal(prev => ({ ...prev, activeLogTab: 'container' }))}
                                className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${
                                    logModal.activeLogTab === 'container'
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                                }`}
                            >
                                Salida Docker Daemon (Runtime)
                            </button>
                        </div>

                        {/* Terminal Box */}
                        <div className="flex-1 bg-slate-950 rounded-xl border border-slate-800 p-3 font-mono text-xs overflow-y-auto text-slate-200 whitespace-pre-wrap select-text max-h-[55vh] scrollbar-thin">
                            {logModal.loading ? (
                                <div className="text-slate-400">Cargando registros...</div>
                            ) : logModal.activeLogTab === 'build' ? (
                                logModal.buildLog
                            ) : (
                                logModal.containerLog
                            )}
                        </div>

                        <div className="flex justify-between items-center text-[11px] text-slate-500 dark:text-slate-400 pt-1.5 border-t border-slate-200 dark:border-slate-800">
                            <span>Soporte técnico y diagnóstico institucional</span>
                            <button
                                onClick={() => {
                                    const text = logModal.activeLogTab === 'build' ? logModal.buildLog : logModal.containerLog;
                                    navigator.clipboard.writeText(text);
                                    showToast('Logs copiados al portapapeles');
                                }}
                                className="px-3 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-bold text-xs border border-slate-200 dark:border-slate-700 cursor-pointer"
                            >
                                Copiar Logs
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: RESULTADO SAFE DOCKER PRUNE */}
            {pruneResultModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-2xl space-y-3">
                        <div className="flex items-center gap-2 text-slate-900 dark:text-slate-200 font-bold text-sm">
                            <svg className="w-4 h-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Mantenimiento de Imágenes Docker
                        </div>

                        {pruneResultModal.loading ? (
                            <div className="py-6 text-center space-y-2">
                                <div className="inline-block h-6 w-6 animate-spin rounded-full border-2 border-blue-500 border-t-transparent"></div>
                                <p className="text-xs text-slate-600 dark:text-slate-400">
                                    Ejecutando <code className="text-slate-900 dark:text-slate-200">docker image prune -f</code>...<br />
                                    Liberando capas residuales sin afectar librerías.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-2.5">
                                <p className="text-xs text-slate-700 dark:text-slate-300 leading-relaxed">
                                    {pruneResultModal.message}
                                </p>
                                {pruneResultModal.reclaimed && (
                                    <div className="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 flex justify-between items-center text-xs">
                                        <span className="text-slate-500 dark:text-slate-400">Espacio recuperado:</span>
                                        <span className="text-base font-black text-blue-600 dark:text-blue-300">{pruneResultModal.reclaimed}</span>
                                    </div>
                                )}
                                <div className="p-2 rounded-lg bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-[10px] text-slate-500 dark:text-slate-400">
                                    Cachés institucionales <code className="text-slate-800 dark:text-slate-200">uleam_*_cache</code> verificadas y protegidas.
                                </div>
                            </div>
                        )}

                        {!pruneResultModal.loading && (
                            <div className="flex justify-end pt-1">
                                <button
                                    onClick={() => setPruneResultModal(prev => ({ ...prev, isOpen: false }))}
                                    className="px-3.5 py-1.5 rounded-lg text-xs font-bold text-white bg-slate-900 hover:bg-slate-800 dark:bg-slate-800 dark:hover:bg-slate-700 cursor-pointer"
                                >
                                    Entendido
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
