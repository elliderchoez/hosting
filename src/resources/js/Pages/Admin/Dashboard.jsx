import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

export default function AdminDashboard({ telemetry: initialTelemetry, metrics, students, projects, recruiters = [] }) {
    const [telemetry, setTelemetry] = useState(initialTelemetry);
    const [isRefreshingTelemetry, setIsRefreshingTelemetry] = useState(false);
    const [autoRefresh, setAutoRefresh] = useState(true);

    // Active sub-navigation tab - Persistente ante recarga de página (Enfoque Operativo Diario)
    const validTabs = ['partners', 'moderation', 'students', 'managed_partners', 'telemetry', 'metrics'];
    const [activeTab, setActiveTab] = useState(() => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            const tabParam = params.get('tab');
            if (tabParam && validTabs.includes(tabParam)) {
                return tabParam;
            }
            const stored = localStorage.getItem('admin_active_tab');
            if (stored && validTabs.includes(stored)) {
                return stored;
            }
        }
        return 'partners';
    });

    const handleTabChange = (tabKey) => {
        setActiveTab(tabKey);
        if (typeof window !== 'undefined') {
            localStorage.setItem('admin_active_tab', tabKey);
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabKey);
            window.history.replaceState({}, '', url.toString());
        }
    };

    useEffect(() => {
        if (typeof window !== 'undefined') {
            localStorage.setItem('admin_active_tab', activeTab);
            const url = new URL(window.location.href);
            if (url.searchParams.get('tab') !== activeTab) {
                url.searchParams.set('tab', activeTab);
                window.history.replaceState({}, '', url.toString());
            }

            const handlePopState = () => {
                const params = new URLSearchParams(window.location.search);
                const tabParam = params.get('tab');
                if (tabParam && validTabs.includes(tabParam)) {
                    setActiveTab(tabParam);
                }
            };
            window.addEventListener('popstate', handlePopState);
            return () => window.removeEventListener('popstate', handlePopState);
        }
    }, [activeTab]);

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
    const [rejectPartnerModal, setRejectPartnerModal] = useState({ isOpen: false, partner: null, reason: '', isSubmitting: false });
    const [approvePartnerModal, setApprovePartnerModal] = useState({ isOpen: false, partner: null, isSubmitting: false });
    const [approvingPartnerId, setApprovingPartnerId] = useState(null);

    // Partners & Recruiters moderation state
    const [recruiterSearch, setRecruiterSearch] = useState('');
    const [recruiterStatusFilter, setRecruiterStatusFilter] = useState('pending'); // Por defecto 'pending' para enfocar en solicitudes por revisar
    const [recruiterTypeFilter, setRecruiterTypeFilter] = useState('all'); // 'all' | 'company' | 'recruiter'
    const [recruiterPage, setRecruiterPage] = useState(1);
    const recruiterPageSize = 8;

    // Managed Partners (Tab 6: Gestión de Cuentas Aprobadas) state
    const [manageSearch, setManageSearch] = useState('');
    const [manageTypeFilter, setManageTypeFilter] = useState('all'); // 'all' | 'company' | 'recruiter'
    const [manageStatusFilter, setManageStatusFilter] = useState('all'); // 'all' | 'active' | 'inactive'
    const [managePage, setManagePage] = useState(1);
    const managePageSize = 8;
    const [togglePartnerModal, setTogglePartnerModal] = useState({ isOpen: false, partner: null, isSubmitting: false });

    // Copiar RUC/Cédula al portapapeles
    const [copiedTaxId, setCopiedTaxId] = useState(null);
    const handleCopyTaxId = (taxId) => {
        if (!taxId) return;
        navigator.clipboard.writeText(taxId);
        setCopiedTaxId(taxId);
        showToast(`Identificación "${taxId}" copiada al portapapeles.`);
        setTimeout(() => {
            setCopiedTaxId(null);
        }, 2000);
    };

    const pendingPartnersCount = recruiters.filter(r => r.status === 'pending').length;

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

    // Sondeo en tiempo real para recibir nuevas solicitudes de empresas/reclutadores sin tener que recargar
    useEffect(() => {
        const pollInterval = setInterval(() => {
            // Solo recargar si no hay modales abiertos para evitar interrumpir al administrador
            if (!rejectPartnerModal.isOpen && !approvePartnerModal.isOpen && !suspendModal.isOpen && !blockModal.isOpen) {
                router.reload({
                    only: ['recruiters'],
                    preserveScroll: true,
                    preserveState: true,
                });
            }
        }, 5000);

        return () => clearInterval(pollInterval);
    }, [rejectPartnerModal.isOpen, approvePartnerModal.isOpen, suspendModal.isOpen, blockModal.isOpen]);

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

    // Approve Partner / Recruiter - Modal Institucional
    const openApprovePartnerModal = (partner) => {
        setApprovePartnerModal({
            isOpen: true,
            partner,
            isSubmitting: false,
        });
    };

    const submitApprovePartner = () => {
        if (!approvePartnerModal.partner) return;
        const partner = approvePartnerModal.partner;
        const nombreEntidad = partner.account_type === 'company' ? partner.company : partner.name;

        setApprovePartnerModal(prev => ({ ...prev, isSubmitting: true }));
        router.post(route('admin.recruiters.approve', partner.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                showToast(`¡Cuenta de "${nombreEntidad}" aprobada y correo oficial enviado!`);
                setApprovePartnerModal({ isOpen: false, partner: null, isSubmitting: false });
            },
            onError: () => {
                setApprovePartnerModal(prev => ({ ...prev, isSubmitting: false }));
            },
        });
    };

    // Open Reject Partner Modal
    const openRejectPartnerModal = (partner) => {
        setRejectPartnerModal({
            isOpen: true,
            partner,
            reason: '',
            isSubmitting: false,
        });
    };

    // Submit Reject Partner
    const submitRejectPartner = (e) => {
        e.preventDefault();
        const trimmed = rejectPartnerModal.reason.trim();
        if (!trimmed || trimmed.length < 4) {
            alert('Por favor ingresa un motivo detallado del rechazo (mínimo 4 caracteres).');
            return;
        }

        setRejectPartnerModal(prev => ({ ...prev, isSubmitting: true }));
        router.post(route('admin.recruiters.reject', rejectPartnerModal.partner.id), {
            reason: trimmed,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                showToast(`Solicitud de ${rejectPartnerModal.partner?.name} rechazada y correo de notificación enviado.`);
                setRejectPartnerModal({ isOpen: false, partner: null, reason: '', isSubmitting: false });
            },
            onError: () => {
                setRejectPartnerModal(prev => ({ ...prev, isSubmitting: false }));
            },
        });
    };

    // Open Toggle Partner Status Modal (Activar / Desactivar cuenta)
    const handleTogglePartner = (partner) => {
        setTogglePartnerModal({
            isOpen: true,
            partner,
            isSubmitting: false,
        });
    };

    const confirmTogglePartner = () => {
        if (!togglePartnerModal.partner) return;
        const partner = togglePartnerModal.partner;
        const isCurrentlyActive = (partner.status === 'approved' && partner.verified);
        const entidad = partner.account_type === 'company' ? partner.company : partner.name;

        setTogglePartnerModal(prev => ({ ...prev, isSubmitting: true }));
        router.patch(route('admin.recruiters.toggle-status', partner.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setTogglePartnerModal({ isOpen: false, partner: null, isSubmitting: false });
                showToast(
                    isCurrentlyActive
                        ? `La cuenta de "${entidad}" ha sido desactivada temporalmente.`
                        : `La cuenta de "${entidad}" ha sido reactivada con éxito.`
                );
            },
            onError: () => {
                setTogglePartnerModal(prev => ({ ...prev, isSubmitting: false }));
                showToast('Error al modificar el estado de la cuenta.');
            }
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

    // Filter Recruiters & Companies
    const filteredRecruiters = recruiters.filter(r => {
        const matchesSearch =
            r.name.toLowerCase().includes(recruiterSearch.toLowerCase()) ||
            r.email.toLowerCase().includes(recruiterSearch.toLowerCase()) ||
            (r.company || '').toLowerCase().includes(recruiterSearch.toLowerCase()) ||
            (r.tax_id || '').toLowerCase().includes(recruiterSearch.toLowerCase()) ||
            (r.position || '').toLowerCase().includes(recruiterSearch.toLowerCase());

        const matchesStatus =
            recruiterStatusFilter === 'all' ? true :
                recruiterStatusFilter === 'pending' ? r.status === 'pending' :
                    recruiterStatusFilter === 'approved' ? r.status === 'approved' :
                        recruiterStatusFilter === 'rejected' ? r.status === 'rejected' : true;

        const matchesType =
            recruiterTypeFilter === 'all' ? true :
                recruiterTypeFilter === 'company' ? r.account_type === 'company' :
                    recruiterTypeFilter === 'recruiter' ? r.account_type === 'recruiter' : true;

        return matchesSearch && matchesStatus && matchesType;
    });

    const totalRecruiterPages = Math.ceil(filteredRecruiters.length / recruiterPageSize) || 1;
    const paginatedRecruiters = filteredRecruiters.slice((recruiterPage - 1) * recruiterPageSize, recruiterPage * recruiterPageSize);

    // Listado y Filtros de Empresas / Reclutadores Aprobados (Pestaña 6)
    const managedPartnersList = recruiters.filter(r => r.status === 'approved' || r.status === 'inactive');
    const filteredManagedPartners = managedPartnersList.filter(r => {
        const matchesSearch =
            r.name.toLowerCase().includes(manageSearch.toLowerCase()) ||
            r.email.toLowerCase().includes(manageSearch.toLowerCase()) ||
            (r.company || '').toLowerCase().includes(manageSearch.toLowerCase()) ||
            (r.tax_id || '').toLowerCase().includes(manageSearch.toLowerCase()) ||
            (r.position || '').toLowerCase().includes(manageSearch.toLowerCase());

        const isActive = (r.status === 'approved' && r.verified);
        const matchesStatus =
            manageStatusFilter === 'all' ? true :
                manageStatusFilter === 'active' ? isActive :
                    manageStatusFilter === 'inactive' ? !isActive : true;

        const matchesType =
            manageTypeFilter === 'all' ? true :
                manageTypeFilter === 'company' ? r.account_type === 'company' :
                    manageTypeFilter === 'recruiter' ? r.account_type === 'recruiter' : true;

        return matchesSearch && matchesStatus && matchesType;
    });

    const totalManagePages = Math.ceil(filteredManagedPartners.length / managePageSize) || 1;
    const paginatedManagedPartners = filteredManagedPartners.slice((managePage - 1) * managePageSize, managePage * managePageSize);

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
                            onClick={() => handleTabChange('partners')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer flex items-center gap-1.5 ${activeTab === 'partners'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            <span>1. Solicitudes de Empresas</span>
                            {pendingPartnersCount > 0 && (
                                <span className="px-1.5 py-0.5 rounded-full text-[10px] font-black bg-blue-600 text-white">
                                    {pendingPartnersCount}
                                </span>
                            )}
                        </button>

                        <button
                            onClick={() => handleTabChange('moderation')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${activeTab === 'moderation'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            2. Control de Vitrina & Moderación
                        </button>

                        <button
                            onClick={() => handleTabChange('students')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${activeTab === 'students'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            3. Gestión de Estudiantes
                        </button>

                        <button
                            onClick={() => handleTabChange('managed_partners')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer flex items-center gap-1.5 ${activeTab === 'managed_partners'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            <span>4. Gestión de Empresas/Reclutadores</span>
                        </button>

                        <button
                            onClick={() => handleTabChange('telemetry')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${activeTab === 'telemetry'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            5. Telemetría del Host
                        </button>

                        <button
                            onClick={() => handleTabChange('metrics')}
                            className={`pb-2.5 font-bold transition whitespace-nowrap border-b-2 cursor-pointer ${activeTab === 'metrics'
                                ? 'border-blue-600 text-blue-600 dark:border-blue-500 dark:text-blue-400'
                                : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-slate-200'
                                }`}
                        >
                            6. Métricas Institucionales
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
                                                                <span className={`px-2.5 py-1 rounded text-[10px] font-bold uppercase tracking-wider ${project.status === 'running' ? 'bg-blue-50 dark:bg-blue-500/15 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-500/30' :
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
                                                                    className={`px-3 py-1 rounded-lg text-xs font-semibold transition cursor-pointer ${project.is_visible_in_showcase
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
                                            className={`px-2.5 py-1 rounded text-xs font-bold transition cursor-pointer ${currentPage === p
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
                                            className={`px-2.5 py-1 rounded text-xs font-bold transition cursor-pointer ${studentPage === p
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

                {/* TAB 5: GESTIÓN Y VALIDACIÓN DE EMPRESAS Y RECLUTADORES */}
                {activeTab === 'partners' && (
                    <div className="space-y-4">
                        {/* Tarjetas resumen superior - Flujo de Solicitudes */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Pendientes de Revisión</div>
                                <div className="text-2xl sm:text-3xl font-black text-blue-600 dark:text-blue-400 mt-1">{pendingPartnersCount}</div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Solicitudes Aprobadas</div>
                                <div className="text-2xl sm:text-3xl font-black text-cyan-600 dark:text-cyan-400 mt-1">
                                    {recruiters.filter(r => r.status === 'approved' || r.status === 'inactive').length}
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Solicitudes Rechazadas</div>
                                <div className="text-2xl sm:text-3xl font-black text-slate-700 dark:text-slate-300 mt-1">
                                    {recruiters.filter(r => r.status === 'rejected').length}
                                </div>
                            </div>
                        </div>

                        {/* Filtros de búsqueda */}
                        <div className="p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs space-y-3">
                            <div className="flex flex-col sm:flex-row gap-3 justify-between items-center">
                                <div className="w-full sm:w-80">
                                    <input
                                        type="text"
                                        value={recruiterSearch}
                                        onChange={(e) => {
                                            setRecruiterSearch(e.target.value);
                                            setRecruiterPage(1);
                                        }}
                                        placeholder="Buscar por nombre, empresa, RUC, cargo..."
                                        className="w-full rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 focus:ring-1 focus:ring-blue-500"
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                                    {/* Filtro Tipo */}
                                    <select
                                        value={recruiterTypeFilter}
                                        onChange={(e) => {
                                            setRecruiterTypeFilter(e.target.value);
                                            setRecruiterPage(1);
                                        }}
                                        className="rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 cursor-pointer"
                                    >
                                        <option value="all">Tipo: Todos</option>
                                        <option value="company">Solo Empresas</option>
                                        <option value="recruiter">Solo Reclutadores</option>
                                    </select>

                                    {/* Filtro Estado */}
                                    <select
                                        value={recruiterStatusFilter}
                                        onChange={(e) => {
                                            setRecruiterStatusFilter(e.target.value);
                                            setRecruiterPage(1);
                                        }}
                                        className="rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 cursor-pointer"
                                    >
                                        <option value="pending">Solo Pendientes de Revisión</option>
                                        <option value="rejected">Solo Rechazadas</option>
                                        <option value="approved">Solo Aprobadas</option>
                                        <option value="all">Todas las Solicitudes</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Listado de Solicitudes */}
                        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 uppercase text-[10px] tracking-wider font-semibold">
                                        <tr>
                                            <th className="py-3 px-4">Tipo</th>
                                            <th className="py-3 px-4">Empresa / Reclutador</th>
                                            <th className="py-3 px-4">Identificación Fiscal</th>
                                            <th className="py-3 px-4">Evidencia Digital</th>
                                            <th className="py-3 px-4">Contacto Directo</th>
                                            <th className="py-3 px-4">Estado</th>
                                            <th className="py-3 px-4 text-right">Acciones de Verificación</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                        {paginatedRecruiters.length === 0 ? (
                                            <tr>
                                                <td colSpan="7" className="py-8 text-center text-slate-500 dark:text-slate-400">
                                                    No se encontraron registros de empresas o reclutadores que coincidan con los filtros.
                                                </td>
                                            </tr>
                                        ) : (
                                            paginatedRecruiters.map((item) => (
                                                <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        {item.account_type === 'company' ? (
                                                            <span className="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/70 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                                Empresa
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                                Reclutador
                                                            </span>
                                                        )}
                                                    </td>

                                                    <td className="py-3 px-4">
                                                        <div className="font-bold text-slate-900 dark:text-white">
                                                            {item.account_type === 'company' ? item.company : item.name}
                                                        </div>
                                                        <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                                            {item.account_type === 'company' ? (
                                                                <span>Contacto: <strong>{item.name}</strong> ({item.position || 'Representante'})</span>
                                                            ) : (
                                                                <span>{item.position || 'Recruiter'} · {item.company}</span>
                                                            )}
                                                        </div>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap font-mono text-[11px] text-slate-700 dark:text-slate-300">
                                                        <div className="flex items-center gap-1.5">
                                                            <span className="font-bold text-slate-900 dark:text-slate-100">{item.tax_id || 'Sin ID'}</span>
                                                            {item.tax_id && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => handleCopyTaxId(item.tax_id)}
                                                                    className="p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-400 hover:text-blue-500 transition cursor-pointer"
                                                                    title="Copiar RUC / Cédula"
                                                                >
                                                                    {copiedTaxId === item.tax_id ? (
                                                                        <svg className="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                                        </svg>
                                                                    ) : (
                                                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                                        </svg>
                                                                    )}
                                                                </button>
                                                            )}
                                                        </div>
                                                        <span className="text-[10px] text-slate-400">
                                                            {item.account_type === 'company' ? 'RUC Jurídico' : 'Cédula / RUC'}
                                                        </span>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        {item.account_type === 'company' && item.website_url ? (
                                                            <a
                                                                href={item.website_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline font-semibold"
                                                            >
                                                                <span>Ver Sitio Web</span>
                                                                <span>↗</span>
                                                            </a>
                                                        ) : item.account_type === 'recruiter' && item.linkedin_url ? (
                                                            <a
                                                                href={item.linkedin_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline font-semibold"
                                                            >
                                                                <span>Ver LinkedIn</span>
                                                                <span>↗</span>
                                                            </a>
                                                        ) : (
                                                            <span className="text-slate-400 italic">No especificado</span>
                                                        )}
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap text-[11px]">
                                                        <div className="text-slate-800 dark:text-slate-200">{item.email}</div>
                                                        <div className="text-slate-500 dark:text-slate-400">{item.phone || 'Sin teléfono'}</div>
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap">
                                                        {item.status === 'pending' && (
                                                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                                <span className="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                                                Pendiente
                                                            </span>
                                                        )}
                                                        {item.status === 'approved' && (
                                                            <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/70 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                                <span className="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                                                                Aprobado
                                                            </span>
                                                        )}
                                                        {item.status === 'rejected' && (
                                                            <div>
                                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300 border border-rose-200 dark:border-rose-800">
                                                                    Rechazado
                                                                </span>
                                                                {item.rejection_reason && (
                                                                    <div className="text-[10px] text-slate-500 dark:text-slate-400 mt-1 max-w-xs truncate" title={item.rejection_reason}>
                                                                        Motivo: {item.rejection_reason}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        )}
                                                    </td>

                                                    <td className="py-3 px-4 whitespace-nowrap text-right">
                                                        <div className="flex items-center justify-end gap-1.5">
                                                            {item.status !== 'approved' && (
                                                                <button
                                                                    onClick={() => openApprovePartnerModal(item)}
                                                                    className="px-3 py-1 rounded-lg text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition cursor-pointer shadow-xs flex items-center gap-1"
                                                                >
                                                                    <span>Aprobar</span>
                                                                </button>
                                                            )}

                                                            {item.status !== 'rejected' && (
                                                                <button
                                                                    onClick={() => openRejectPartnerModal(item)}
                                                                    className="px-2.5 py-1 rounded-lg text-xs font-bold text-rose-700 dark:text-rose-300 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/50 dark:hover:bg-rose-900/60 border border-rose-200 dark:border-rose-800 transition cursor-pointer"
                                                                >
                                                                    Rechazar
                                                                </button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* Paginación */}
                            {totalRecruiterPages > 1 && (
                                <div className="p-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <div>
                                        Página {recruiterPage} de {totalRecruiterPages} ({filteredRecruiters.length} registros)
                                    </div>
                                    <div className="flex gap-1">
                                        <button
                                            disabled={recruiterPage <= 1}
                                            onClick={() => setRecruiterPage(p => Math.max(1, p - 1))}
                                            className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
                                        >
                                            Anterior
                                        </button>
                                        <button
                                            disabled={recruiterPage >= totalRecruiterPages}
                                            onClick={() => setRecruiterPage(p => Math.min(totalRecruiterPages, p + 1))}
                                            className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
                                        >
                                            Siguiente
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* TAB 6: GESTIÓN DE EMPRESAS Y RECLUTADORES APROBADOS */}
                {activeTab === 'managed_partners' && (
                    <div className="space-y-4">
                        {/* Tarjetas resumen superior - Directorio Activo */}
                        <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 sm:gap-4">
                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Cuentas Aprobadas</div>
                                <div className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white mt-1">
                                    {managedPartnersList.length}
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Empresas Aliadas</div>
                                <div className="text-2xl sm:text-3xl font-black text-blue-600 dark:text-blue-400 mt-1">
                                    {managedPartnersList.filter(r => r.account_type === 'company').length}
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Reclutadores</div>
                                <div className="text-2xl sm:text-3xl font-black text-cyan-600 dark:text-cyan-400 mt-1">
                                    {managedPartnersList.filter(r => r.account_type === 'recruiter').length}
                                </div>
                            </div>

                            <div className="p-4 sm:p-5 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs">
                                <div className="text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Cuentas Desactivadas</div>
                                <div className="text-2xl sm:text-3xl font-black text-slate-500 dark:text-slate-400 mt-1">
                                    {managedPartnersList.filter(r => !r.verified || r.status === 'inactive').length}
                                </div>
                            </div>
                        </div>

                        {/* Filtros de búsqueda */}
                        <div className="p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs space-y-3">
                            <div className="flex flex-col sm:flex-row gap-3 justify-between items-center">
                                <div className="w-full sm:w-80">
                                    <input
                                        type="text"
                                        value={manageSearch}
                                        onChange={(e) => {
                                            setManageSearch(e.target.value);
                                            setManagePage(1);
                                        }}
                                        placeholder="Buscar por empresa, contacto, RUC, correo..."
                                        className="w-full rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 focus:ring-1 focus:ring-blue-500"
                                    />
                                </div>

                                <div className="flex flex-wrap gap-2 w-full sm:w-auto">
                                    {/* Filtro Tipo */}
                                    <select
                                        value={manageTypeFilter}
                                        onChange={(e) => {
                                            setManageTypeFilter(e.target.value);
                                            setManagePage(1);
                                        }}
                                        className="rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 cursor-pointer"
                                    >
                                        <option value="all">Tipo: Todos</option>
                                        <option value="company">Solo Empresas</option>
                                        <option value="recruiter">Solo Reclutadores</option>
                                    </select>

                                    {/* Filtro Estado */}
                                    <select
                                        value={manageStatusFilter}
                                        onChange={(e) => {
                                            setManageStatusFilter(e.target.value);
                                            setManagePage(1);
                                        }}
                                        className="rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-900 dark:text-white px-3 py-2 cursor-pointer"
                                    >
                                        <option value="all">Estado: Todos</option>
                                        <option value="active">Solo Activas (Acceso Permitido)</option>
                                        <option value="inactive">Solo Desactivadas (Acceso Bloqueado)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Listado de Empresas y Reclutadores Aprobados */}
                        <div className="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/70 shadow-xs overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-slate-50 dark:bg-slate-950 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 uppercase text-[10px] tracking-wider font-semibold">
                                        <tr>
                                            <th className="py-3 px-4">Tipo</th>
                                            <th className="py-3 px-4">Empresa / Reclutador</th>
                                            <th className="py-3 px-4">Identificación Fiscal</th>
                                            <th className="py-3 px-4">Evidencia Digital</th>
                                            <th className="py-3 px-4">Contacto Directo</th>
                                            <th className="py-3 px-4">Estado de Acceso</th>
                                            <th className="py-3 px-4 text-right">Acciones de Cuenta</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                        {paginatedManagedPartners.length === 0 ? (
                                            <tr>
                                                <td colSpan="7" className="py-8 text-center text-slate-500 dark:text-slate-400">
                                                    No se encontraron cuentas de empresas o reclutadores aprobados.
                                                </td>
                                            </tr>
                                        ) : (
                                            paginatedManagedPartners.map((item) => {
                                                const isActive = (item.status === 'approved' && item.verified);
                                                return (
                                                    <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                                                        <td className="py-3 px-4 whitespace-nowrap">
                                                            {item.account_type === 'company' ? (
                                                                <span className="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/70 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                                    Empresa
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center px-2.5 py-1 rounded-lg text-[11px] font-bold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                                    Reclutador
                                                                </span>
                                                            )}
                                                        </td>

                                                        <td className="py-3 px-4">
                                                            <div className="font-bold text-slate-900 dark:text-white">
                                                                {item.account_type === 'company' ? item.company : item.name}
                                                            </div>
                                                            <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                                                {item.account_type === 'company' ? (
                                                                    <span>Contacto: <strong>{item.name}</strong> ({item.position || 'Representante'})</span>
                                                                ) : (
                                                                    <span>{item.position || 'Recruiter'} · {item.company}</span>
                                                                )}
                                                            </div>
                                                        </td>

                                                        <td className="py-3 px-4 whitespace-nowrap font-mono text-[11px] text-slate-700 dark:text-slate-300">
                                                            <div className="flex items-center gap-1.5">
                                                                <span className="font-bold text-slate-900 dark:text-slate-100">{item.tax_id || 'Sin ID'}</span>
                                                                {item.tax_id && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleCopyTaxId(item.tax_id)}
                                                                        className="p-1 rounded hover:bg-slate-200 dark:hover:bg-slate-800 text-slate-400 hover:text-blue-500 transition cursor-pointer"
                                                                        title="Copiar RUC / Cédula"
                                                                    >
                                                                        {copiedTaxId === item.tax_id ? (
                                                                            <svg className="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                                            </svg>
                                                                        ) : (
                                                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                                            </svg>
                                                                        )}
                                                                    </button>
                                                                )}
                                                            </div>
                                                            <span className="text-[10px] text-slate-400">
                                                                {item.account_type === 'company' ? 'RUC Jurídico' : 'Cédula / RUC'}
                                                            </span>
                                                        </td>

                                                        <td className="py-3 px-4 whitespace-nowrap">
                                                            {item.account_type === 'company' && item.website_url ? (
                                                                <a
                                                                    href={item.website_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline font-semibold"
                                                                >
                                                                    <span>Ver Sitio Web</span>
                                                                    <span>↗</span>
                                                                </a>
                                                            ) : item.account_type === 'recruiter' && item.linkedin_url ? (
                                                                <a
                                                                    href={item.linkedin_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="inline-flex items-center gap-1 text-blue-600 dark:text-blue-400 hover:underline font-semibold"
                                                                >
                                                                    <span>Ver LinkedIn</span>
                                                                    <span>↗</span>
                                                                </a>
                                                            ) : (
                                                                <span className="text-slate-400 italic">No especificado</span>
                                                            )}
                                                        </td>

                                                        <td className="py-3 px-4 whitespace-nowrap text-[11px]">
                                                            <div className="text-slate-800 dark:text-slate-200">{item.email}</div>
                                                            <div className="text-slate-500 dark:text-slate-400">{item.phone || 'Sin teléfono'}</div>
                                                        </td>

                                                        <td className="py-3 px-4 whitespace-nowrap">
                                                            {isActive ? (
                                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 dark:bg-blue-950/70 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                                                    <span className="w-1.5 h-1.5 rounded-full bg-cyan-400"></span>
                                                                    Activa
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                                                    <span className="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                                    Desactivada
                                                                </span>
                                                            )}
                                                        </td>

                                                        <td className="py-3 px-4 whitespace-nowrap text-right">
                                                            <button
                                                                onClick={() => handleTogglePartner(item)}
                                                                className={`px-3 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${
                                                                    isActive
                                                                        ? 'text-slate-700 dark:text-slate-300 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700'
                                                                        : 'text-white bg-blue-600 hover:bg-blue-700 shadow-xs'
                                                                }`}
                                                            >
                                                                {isActive ? 'Desactivar Cuenta' : 'Activar Cuenta'}
                                                            </button>
                                                        </td>
                                                    </tr>
                                                );
                                            })
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* Paginación */}
                            {totalManagePages > 1 && (
                                <div className="p-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                                    <div>
                                        Página {managePage} de {totalManagePages} ({filteredManagedPartners.length} cuentas)
                                    </div>
                                    <div className="flex gap-1">
                                        <button
                                            disabled={managePage <= 1}
                                            onClick={() => setManagePage(p => Math.max(1, p - 1))}
                                            className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
                                        >
                                            Anterior
                                        </button>
                                        <button
                                            disabled={managePage >= totalManagePages}
                                            onClick={() => setManagePage(p => Math.min(totalManagePages, p + 1))}
                                            className="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
                                        >
                                            Siguiente
                                        </button>
                                    </div>
                                </div>
                            )}
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
                                title="Cerrar"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Tabs for build log vs container log */}
                        <div className="flex gap-2">
                            <button
                                onClick={() => setLogModal(prev => ({ ...prev, activeLogTab: 'build' }))}
                                className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${logModal.activeLogTab === 'build'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'
                                    }`}
                            >
                                Logs de Compilación (build_log)
                            </button>
                            <button
                                onClick={() => setLogModal(prev => ({ ...prev, activeLogTab: 'container' }))}
                                className={`px-2.5 py-1 rounded-lg text-xs font-bold transition cursor-pointer ${logModal.activeLogTab === 'container'
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

            {/* MODAL: APROBAR SOLICITUD DE EMPRESA O RECLUTADOR (PLATAFORMA INSTITUCIONAL) */}
            {approvePartnerModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                            <div className="flex items-center gap-2.5 text-slate-900 dark:text-slate-100 font-bold text-sm">
                                <span className="w-7 h-7 rounded-full bg-blue-100 dark:bg-blue-950/70 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </span>
                                <span>
                                    {approvePartnerModal.partner?.account_type === 'company'
                                        ? 'Aprobar Solicitud de Empresa'
                                        : 'Aprobar Solicitud de Reclutador'}
                                </span>
                            </div>
                            <button
                                type="button"
                                disabled={approvePartnerModal.isSubmitting}
                                onClick={() => setApprovePartnerModal({ isOpen: false, partner: null, isSubmitting: false })}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 rounded-lg transition disabled:opacity-50 cursor-pointer"
                                title="Cerrar"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="space-y-3">
                            <p className="text-sm text-slate-700 dark:text-slate-300">
                                {approvePartnerModal.partner?.account_type === 'company' ? (
                                    <>
                                        ¿Confirmas la aprobación y activación oficial de la empresa <strong className="text-slate-900 dark:text-white">"{approvePartnerModal.partner?.company}"</strong>?
                                    </>
                                ) : (
                                    <>
                                        ¿Confirmas la aprobación y activación oficial del reclutador <strong className="text-slate-900 dark:text-white">"{approvePartnerModal.partner?.name}"</strong>?
                                    </>
                                )}
                            </p>

                            <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs space-y-1.5">
                                {approvePartnerModal.partner?.account_type === 'company' ? (
                                    <>
                                        <div><strong className="text-slate-700 dark:text-slate-300">Empresa:</strong> <span className="font-semibold text-blue-600 dark:text-blue-400">{approvePartnerModal.partner?.company}</span></div>
                                        <div><strong className="text-slate-700 dark:text-slate-300">Representante:</strong> {approvePartnerModal.partner?.name} ({approvePartnerModal.partner?.position || 'Contacto'})</div>
                                        <div><strong className="text-slate-700 dark:text-slate-300">RUC:</strong> <span className="font-mono">{approvePartnerModal.partner?.tax_id}</span></div>
                                    </>
                                ) : (
                                    <>
                                        <div><strong className="text-slate-700 dark:text-slate-300">Reclutador:</strong> <span className="font-semibold text-blue-600 dark:text-blue-400">{approvePartnerModal.partner?.name}</span></div>
                                        <div><strong className="text-slate-700 dark:text-slate-300">Agencia / Cargo:</strong> {approvePartnerModal.partner?.company} · {approvePartnerModal.partner?.position}</div>
                                        <div><strong className="text-slate-700 dark:text-slate-300">Identificación:</strong> <span className="font-mono">{approvePartnerModal.partner?.tax_id}</span></div>
                                    </>
                                )}
                                <div><strong className="text-slate-700 dark:text-slate-300">Correo de Acceso:</strong> {approvePartnerModal.partner?.email}</div>
                                {approvePartnerModal.partner?.phone && (
                                    <div><strong className="text-slate-700 dark:text-slate-300">Teléfono:</strong> {approvePartnerModal.partner?.phone}</div>
                                )}
                            </div>

                            <div className="p-3 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 border border-blue-200/60 dark:border-blue-900/60 text-xs text-blue-800 dark:text-blue-300 flex items-start gap-2.5">
                                <svg className="w-4 h-4 shrink-0 text-blue-600 dark:text-blue-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    Se enviará automáticamente un correo a <span className="font-semibold underline">{approvePartnerModal.partner?.email}</span> indicando que su cuenta fue aprobada y que ya puede iniciar sesión con sus credenciales registradas.
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                            <button
                                type="button"
                                disabled={approvePartnerModal.isSubmitting}
                                onClick={() => setApprovePartnerModal({ isOpen: false, partner: null, isSubmitting: false })}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer disabled:opacity-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={approvePartnerModal.isSubmitting}
                                onClick={submitApprovePartner}
                                className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 transition cursor-pointer shadow-xs disabled:opacity-50 flex items-center gap-1.5"
                            >
                                {approvePartnerModal.isSubmitting ? (
                                    <>
                                        <span className="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full"></span>
                                        <span>Aprobando y enviando correo...</span>
                                    </>
                                ) : (
                                    <span>Confirmar Aprobación</span>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: RECHAZAR SOLICITUD DE EMPRESA O RECLUTADOR */}
            {rejectPartnerModal.isOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-2xl space-y-4">
                        <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                            <div className="flex items-center gap-2 text-slate-900 dark:text-slate-100 font-bold text-sm">
                                <span className="w-6 h-6 rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                                    <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </span>
                                Rechazar Solicitud de {rejectPartnerModal.partner?.account_type === 'company' ? 'Empresa' : 'Reclutador'}
                            </div>
                            <button
                                type="button"
                                disabled={rejectPartnerModal.isSubmitting}
                                onClick={() => setRejectPartnerModal({ isOpen: false, partner: null, reason: '', isSubmitting: false })}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 p-1 rounded-lg transition disabled:opacity-50 cursor-pointer"
                                title="Cerrar"
                            >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="bg-slate-50 dark:bg-slate-950 p-3.5 rounded-xl border border-slate-200 dark:border-slate-800 text-xs space-y-1.5">
                            <div className="text-slate-800 dark:text-slate-200">
                                <strong>Solicitante:</strong> {rejectPartnerModal.partner?.name} ({rejectPartnerModal.partner?.company})
                            </div>
                            <div className="text-slate-500 dark:text-slate-400">
                                <strong>Correo destino:</strong> {rejectPartnerModal.partner?.email}
                            </div>
                            <div className="text-rose-600 dark:text-rose-400 text-[11px] font-medium pt-1 flex items-start gap-1.5">
                                <svg className="w-3.5 h-3.5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Al confirmar, se enviará automáticamente un correo electrónico oficial notificando el motivo del rechazo al solicitante.</span>
                            </div>
                        </div>

                        <form onSubmit={submitRejectPartner} className="space-y-4">
                            <div>
                                <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">
                                    Motivo del Rechazo / Observaciones Institucionales *:
                                </label>
                                <textarea
                                    value={rejectPartnerModal.reason}
                                    onChange={(e) => setRejectPartnerModal(prev => ({ ...prev, reason: e.target.value }))}
                                    rows="4"
                                    required
                                    disabled={rejectPartnerModal.isSubmitting}
                                    placeholder="Detalla la razón del rechazo (ej. RUC no coincide en base SRI, correo corporativo no verificable, enlace web caído...)"
                                    className="w-full rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white p-3 text-xs focus:ring-1 focus:ring-rose-500 focus:outline-none"
                                ></textarea>
                                <div className="text-[10px] text-slate-400 text-right mt-1">
                                    {rejectPartnerModal.reason.length} caracteres (mínimo 4)
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                                <button
                                    type="button"
                                    disabled={rejectPartnerModal.isSubmitting}
                                    onClick={() => setRejectPartnerModal({ isOpen: false, partner: null, reason: '', isSubmitting: false })}
                                    className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer disabled:opacity-50"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={rejectPartnerModal.isSubmitting || rejectPartnerModal.reason.trim().length < 4}
                                    className="px-4 py-2 rounded-xl text-xs font-bold text-white bg-rose-600 hover:bg-rose-700 transition cursor-pointer shadow-xs disabled:opacity-50 flex items-center gap-1.5"
                                >
                                    {rejectPartnerModal.isSubmitting ? (
                                        <>
                                            <span className="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full"></span>
                                            <span>Enviando correo y rechazando...</span>
                                        </>
                                    ) : (
                                        <span>Confirmar Rechazo y Notificar</span>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: ACTIVAR / DESACTIVAR CUENTA DE EMPRESA O RECLUTADOR */}
            {togglePartnerModal.isOpen && togglePartnerModal.partner && (
                <div className="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-2xl space-y-4">
                        <div className="flex items-center gap-3">
                            <div className={`w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ${
                                (togglePartnerModal.partner.status === 'approved' && togglePartnerModal.partner.verified)
                                    ? 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800'
                                    : 'bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-800'
                            }`}>
                                <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                    {(togglePartnerModal.partner.status === 'approved' && togglePartnerModal.partner.verified)
                                        ? '¿Desactivar acceso de la cuenta?'
                                        : '¿Activar acceso de la cuenta?'}
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Control institucional de credenciales
                                </p>
                            </div>
                        </div>

                        <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs space-y-1.5">
                            <div className="text-slate-800 dark:text-slate-200">
                                <strong>Entidad:</strong> {togglePartnerModal.partner.company || togglePartnerModal.partner.name}
                            </div>
                            <div className="text-slate-600 dark:text-slate-400">
                                <strong>Contacto:</strong> {togglePartnerModal.partner.name} ({togglePartnerModal.partner.email})
                            </div>
                            <div className="text-slate-500 dark:text-slate-400 text-[11px] pt-1 border-t border-slate-200 dark:border-slate-800/80">
                                {(togglePartnerModal.partner.status === 'approved' && togglePartnerModal.partner.verified)
                                    ? 'Al desactivar, la empresa o reclutador no podrá iniciar sesión en Nexus Academic hasta que sea reactivada.'
                                    : 'Al activar, la empresa o reclutador podrá ingresar de inmediato con su correo y contraseña.'}
                            </div>
                        </div>

                        <div className="flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                            <button
                                type="button"
                                disabled={togglePartnerModal.isSubmitting}
                                onClick={() => setTogglePartnerModal({ isOpen: false, partner: null, isSubmitting: false })}
                                className="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 cursor-pointer disabled:opacity-50"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={togglePartnerModal.isSubmitting}
                                onClick={confirmTogglePartner}
                                className={`px-4 py-2 rounded-xl text-xs font-bold text-white transition cursor-pointer shadow-xs disabled:opacity-50 flex items-center gap-1.5 ${
                                    (togglePartnerModal.partner.status === 'approved' && togglePartnerModal.partner.verified)
                                        ? 'bg-rose-600 hover:bg-rose-700'
                                        : 'bg-blue-600 hover:bg-blue-700'
                                }`}
                            >
                                {togglePartnerModal.isSubmitting ? (
                                    <>
                                        <span className="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full"></span>
                                        <span>Procesando...</span>
                                    </>
                                ) : (
                                    <span>
                                        {(togglePartnerModal.partner.status === 'approved' && togglePartnerModal.partner.verified)
                                            ? 'Confirmar Desactivación'
                                            : 'Confirmar Activación'}
                                    </span>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
