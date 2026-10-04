import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';

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

export default function Dashboard({ auth, profile, projects }) {
    const [activeLogsProject, setActiveLogsProject] = useState(null);
    const [logs, setLogs] = useState({ build_log: '', container_log: '', status: '' });
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [isAddProjectOpen, setIsAddProjectOpen] = useState(false);
    const [uploadType, setUploadType] = useState('github');
    const [localUploadMode, setLocalUploadMode] = useState('zip');
    const [projectToDelete, setProjectToDelete] = useState(null);
    const [instructionsProject, setInstructionsProject] = useState(null);
    const [linkBackendModalProject, setLinkBackendModalProject] = useState(null);
    const [attachParentProjectId, setAttachParentProjectId] = useState('');
    const [deletedProjectIds, setDeletedProjectIds] = useState([]);

    // Proyectos visibles filtrando optimistamente los que están en proceso de eliminación
    const visibleProjects = projects.filter(p => !deletedProjectIds.includes(p.id));

    // Separación de aplicaciones principales (visibles en vitrina) y servicios backend de soporte
    const mainProjects = visibleProjects.filter(p => !p.is_backend_service);
    const backendServices = visibleProjects.filter(p => p.is_backend_service);

    // Form for adding new project
    const projectForm = useForm({
        deployment_mode: 'fullstack',
        fullstack_source: 'monorepo',
        name: '',
        category: '',
        subdomain: '',
        github_repo_url: '',
        branch: 'main',
        backend_github_repo_url: '',
        backend_branch: 'main',
        frontend_dir: 'frontend',
        backend_dir: 'backend',
        folder_files: null,
        folder_paths: null,
        root_dir: '',
        env_vars: '',
        attach_to_project_id: ''
    });

    // Polling en segundo plano para mantener los estados de los proyectos sincronizados en tiempo real (cada 5 segundos)
    useEffect(() => {
        if (isAddProjectOpen || projectForm.processing) return;

        const interval = setInterval(() => {
            if (isAddProjectOpen || projectForm.processing) return;
            router.reload({
                only: ['projects'],
                preserveState: true,
                preserveScroll: true
            });
        }, 5000);
        return () => clearInterval(interval);
    }, [isAddProjectOpen, projectForm.processing]);

    // Auto-linking: subdominio del backend seleccionado para inyectar URLs en env_vars
    const [linkedBackend, setLinkedBackend] = useState('');
    const [isDeployHelpOpen, setIsDeployHelpOpen] = useState(false);
    const [selectedGuideOption, setSelectedGuideOption] = useState(null);

    // Proyectos del estudiante que son backends candidatos (PHP, Python, Java, .NET)
    const backendProjects = visibleProjects.filter(p =>
        ['php', 'python', 'java', 'dotnet'].includes(p.language)
    );

    // Cuando el usuario selecciona un backend, inyecta todas las variantes de URL conocidas
    const handleLinkBackend = (subdomain) => {
        setLinkedBackend(subdomain);
        if (!subdomain) {
            projectForm.setData('env_vars', '');
            return;
        }
        const apiUrl = `https://${subdomain}.nexus-academic.software/api`;
        const vars = [
            `VITE_API_URL=${apiUrl}`,
            `REACT_APP_API_URL=${apiUrl}`,
            `NEXT_PUBLIC_API_URL=${apiUrl}`,
            `API_URL=${apiUrl}`,
            `BACKEND_URL=https://${subdomain}.nexus-academic.software`
        ].join('\n');
        projectForm.setData('env_vars', vars);
    };

    const instructionsForm = useForm({
        demo_instructions: ''
    });

    const handleFolderChange = (e) => {
        const files = Array.from(e.target.files);
        const paths = files.map(file => file.webkitRelativePath);
        projectForm.setData({
            ...projectForm.data,
            folder_files: files,
            folder_paths: paths
        });
    };

    const openInstructionsModal = (project) => {
        setInstructionsProject(project);
        instructionsForm.setData('demo_instructions', project.demo_instructions || '');
    };

    const handleInstructionsSubmit = (e) => {
        e.preventDefault();
        instructionsForm.patch(route('projects.instructions', instructionsProject.id), {
            onSuccess: () => {
                setInstructionsProject(null);
            }
        });
    };

    // Auto-completado inteligente al pegar el repositorio de GitHub o subir archivo
    const handleRepoUrlChange = (url) => {
        let repoName = '';
        try {
            const cleanUrl = url.trim().replace(/\.git$/, '');
            const parts = cleanUrl.split('/');
            if (parts.length >= 2) {
                repoName = parts[parts.length - 1];
            }
        } catch (e) {
            repoName = '';
        }

        const updates = { github_repo_url: url };

        if (repoName) {
            const prettyName = repoName
                .replace(/[-_]+/g, ' ')
                .replace(/\b\w/g, l => l.toUpperCase());

            const cleanSlug = repoName.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/^_+|_+$/g, '');

            if (!projectForm.data.name || projectForm.data.name === 'Mi Portal Académico') {
                updates.name = prettyName;
            }

            if (projectForm.data.deployment_mode === 'fullstack') {
                updates.subdomain = `${cleanSlug}_frontend`;
                updates.backend_subdomain = `${cleanSlug}_backend`;
            } else {
                updates.subdomain = cleanSlug;
            }

            // Auto-detección inteligente de categoría a partir de palabras clave en el repositorio
            const lowerUrl = `${url} ${repoName}`.toLowerCase();
            if (!projectForm.data.category) {
                if (/invoice|crater|factur|finan|cobro|pago|billing|conta|contab/i.test(lowerUrl)) {
                    updates.category = 'Finanzas y Facturación';
                } else if (/shop|tienda|store|cart|e-?commerce|comercio|carrito/i.test(lowerUrl)) {
                    updates.category = 'Comercio Electrónico y Tiendas';
                } else if (/erp|crm|gestion|empresa|administra/i.test(lowerUrl)) {
                    updates.category = 'Gestión Empresarial (ERP / CRM)';
                } else if (/academic|educa|school|colegio|universi|bookstack|curso|aula/i.test(lowerUrl)) {
                    updates.category = 'Educación y Gestión Académica';
                } else if (/salud|medic|clinic|hospital|doctor/i.test(lowerUrl)) {
                    updates.category = 'Salud y Medicina';
                } else if (/chat|social|comunidad|forum|foro|red/i.test(lowerUrl)) {
                    updates.category = 'Redes Sociales y Comunidad';
                } else if (/ai|ia|datos|data|bot|nlp|machine-?learning/i.test(lowerUrl)) {
                    updates.category = 'Inteligencia Artificial y Datos';
                } else if (/hotel|turism|viaje|travel/i.test(lowerUrl)) {
                    updates.category = 'Turismo y Hotelería';
                } else if (/logist|transporte|delivery|envio|courier/i.test(lowerUrl)) {
                    updates.category = 'Logística y Transporte';
                } else if (/movie|music|video|streaming|multimedia|game|juego/i.test(lowerUrl)) {
                    updates.category = 'Entretenimiento y Multimedia';
                }
            }
        }

        projectForm.setData(prev => ({
            ...prev,
            ...updates
        }));
    };

    const handleZipChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            const rawName = file.name.replace(/\.zip$/i, '');
            const prettyName = rawName.replace(/[-_]+/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const cleanSlug = rawName.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/^_+|_+$/g, '');

            const updates = {
                folder_files: [file],
                folder_paths: [file.name]
            };

            if (!projectForm.data.name || projectForm.data.name === 'Mi Portal Académico') {
                updates.name = prettyName;
            }

            if (projectForm.data.deployment_mode === 'fullstack') {
                updates.subdomain = `${cleanSlug}_frontend`;
                updates.backend_subdomain = `${cleanSlug}_backend`;
            } else {
                updates.subdomain = cleanSlug;
            }

            projectForm.setData(prev => ({
                ...prev,
                ...updates
            }));
        }
    };


    // Actualizar el estado de los proyectos automáticamente si alguno se está compilando (building)
    useEffect(() => {
        const algunProyectoCompilando = projects.some(p => p.status === 'building');

        if (algunProyectoCompilando && !isAddProjectOpen && !projectForm.processing) {
            const interval = setInterval(() => {
                if (isAddProjectOpen || projectForm.processing) return;
                router.reload({
                    only: ['projects'],
                    preserveState: true,
                    preserveScroll: true
                });
            }, 3000); // Consultar cada 3 segundos

            return () => clearInterval(interval);
        }
    }, [projects, isAddProjectOpen, projectForm.processing]);

    // Fetch container/build logs
    const fetchLogs = (project) => {
        setLoadingLogs(true);
        setActiveLogsProject(project);
        axios.get(route('projects.logs', project.id))
            .then(res => {
                setLogs(res.data);
                setLoadingLogs(false);
            })
            .catch(err => {
                console.error("Error loading logs:", err);
                setLoadingLogs(false);
            });
    };

    // Si el proyecto recién enviado ya aparece registrado en la lista de proyectos, cerrar modal y abrir visor de logs
    useEffect(() => {
        if (isAddProjectOpen && projectForm.data.subdomain) {
            const target = projectForm.data.subdomain.toLowerCase().trim();
            const createdProj = projects.find(p => p.subdomain && p.subdomain.toLowerCase() === target);
            if (createdProj) {
                projectForm.reset();
                setIsAddProjectOpen(false);
                fetchLogs(createdProj);
            }
        }
    }, [projects, isAddProjectOpen]);

    // Recargar logs en tiempo real cada 3 segundos si el visor de logs está abierto
    useEffect(() => {
        if (!activeLogsProject) return;

        const interval = setInterval(() => {
            axios.get(route('projects.logs', activeLogsProject.id))
                .then(res => {
                    setLogs(res.data);
                })
                .catch(err => console.error("Error al actualizar logs:", err));
        }, 3000);

        return () => clearInterval(interval);
    }, [activeLogsProject]);

    // Handle project creation
    const handleProjectSubmit = (e) => {
        e.preventDefault();
        const targetSubdomain = projectForm.data.subdomain;
        projectForm.post(route('projects.store'), {
            onSuccess: (page) => {
                projectForm.reset();
                setIsAddProjectOpen(false);
                const newProj = page?.props?.projects?.find(p => p.subdomain === targetSubdomain);
                if (newProj) {
                    fetchLogs(newProj);
                }
            }
        });
    };

    // Start container
    const startContainer = (projectId) => {
        axios.post(route('projects.start', projectId))
            .then(() => {
                router.reload();
            })
            .catch(err => {
                const errorLog = err.response?.data?.log || err.response?.data?.error || "Error al iniciar el contenedor. Revisa los logs.";
                alert(errorLog);
            });
    };

    // Stop container
    const stopContainer = (projectId) => {
        axios.post(route('projects.stop', projectId))
            .then(() => {
                router.reload();
            })
            .catch(err => alert("Error al detener el contenedor."));
    };

    // Rebuild project
    const rebuildProject = (projectId) => {
        router.post(route('projects.rebuild', projectId));
    };

    // Delete project
    const deleteProject = (projectId) => {
        setProjectToDelete(projectId);
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-bold leading-tight text-slate-100">Mis Proyectos</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-12 bg-slate-950 min-h-screen text-slate-100">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">

                    {/* Information Banner for Students */}
                    <div className="bg-indigo-500/10 border border-indigo-500/20 p-5 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div className="space-y-1">
                            <h4 className="font-bold text-white flex items-center gap-1.5 text-sm">
                                <span>Información de tus Proyectos</span>
                            </h4>
                            <div className="text-xs text-slate-400 leading-relaxed space-y-1">
                                <p>• Puedes tener hasta 3 proyectos publicados al mismo tiempo en tu vitrina.</p>
                                <p>• Cuando un reclutador prueba tu proyecto, estará activo durante 10 minutos y luego se pausará solo para ahorrar recursos.</p>
                                <p>• Puedes subir proyectos en archivo ZIP de hasta 50MB.</p>
                            </div>
                        </div>
                        <div className="flex-shrink-0 bg-white dark:bg-slate-900 border border-blue-200 dark:border-slate-800 rounded-xl p-3 text-center shadow-xs">
                            <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">Cupo de Aplicaciones</span>
                            <span className="text-2xl font-black text-[#1534e8] dark:text-cyan-400 block mt-1">{mainProjects.length} / 3</span>
                        </div>
                    </div>

                    {/* Projects Section */}
                    <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur">
                        <div className="flex items-center justify-between mb-6">
                            <div>
                                <h3 className="text-xl font-bold text-white">Mis Aplicaciones ({mainProjects.length}/3)</h3>
                                <p className="text-sm text-slate-400">Administra tus aplicaciones completas, contenedores y microservicios vinculados.</p>
                            </div>
                            {mainProjects.length < 3 && (
                                <button
                                    onClick={() => {
                                        setAttachParentProjectId('');
                                        setUploadType('github');
                                        setSelectedGuideOption(null);
                                        projectForm.setData({
                                            deployment_mode: 'fullstack',
                                            fullstack_source: 'monorepo',
                                            name: '',
                                            subdomain: '',
                                            backend_subdomain: '',
                                            github_repo_url: '',
                                            branch: 'main',
                                            backend_github_repo_url: '',
                                            backend_branch: 'main',
                                            frontend_dir: 'frontend',
                                            backend_dir: 'backend',
                                            folder_files: null,
                                            folder_paths: null,
                                            root_dir: '',
                                            env_vars: '',
                                            attach_to_project_id: ''
                                        });
                                        setIsAddProjectOpen(true);
                                    }}
                                    className="px-4 py-2 rounded-xl text-sm font-bold bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 shadow-md shadow-cyan-500/10 transition duration-200 cursor-pointer"
                                >
                                    + Desplegar Proyecto
                                </button>
                            )}
                        </div>

                        {mainProjects.length === 0 ? (
                            <div className="text-center py-12 border border-dashed border-slate-800 rounded-xl bg-slate-900/10">
                                <p className="text-slate-400">Aún no has registrado ninguna aplicación.</p>
                                <p className="text-xs text-slate-500 mt-1">Sube tu primer proyecto desde GitHub o una carpeta local para mostrarlo en tu vitrina.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                {mainProjects.map((project) => {
                                    const linkedBackendObj = project.backend_project || visibleProjects.find(p => p.id === project.backend_project_id);

                                    return (
                                        <div
                                            key={project.id}
                                            className="my-application-card rounded-2xl border border-slate-900 bg-slate-950 p-5 flex flex-col justify-between shadow-lg space-y-4 transition duration-200"
                                        >
                                            <div>
                                                <div className="flex justify-between items-start mb-2">
                                                    <div>
                                                        <h4 className="text-lg font-bold text-slate-100 flex items-center gap-2">
                                                            <span>{project.name}</span>
                                                            {linkedBackendObj && (
                                                                <span className="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/30">
                                                                    Fullstack Suite
                                                                </span>
                                                            )}
                                                        </h4>
                                                    </div>
                                                    <span className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider ${
                                                        project.status === 'running' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-cyan-500/15 dark:text-cyan-400 dark:border-cyan-500/30' :
                                                        project.status === 'sleeping' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-500/15 dark:text-indigo-400 dark:border-indigo-500/30' :
                                                        project.status === 'building' ? 'bg-amber-50 text-amber-700 border border-amber-200 dark:bg-yellow-500/15 dark:text-yellow-400 dark:border-yellow-500/30 animate-pulse' :
                                                        'bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'
                                                    }`}>
                                                        {project.status === 'running' ? 'Activo' :
                                                            project.status === 'sleeping' ? 'Suspendido' :
                                                                project.status === 'building' ? 'Compilando' :
                                                                    'Apagado'}
                                                    </span>
                                                </div>

                                                <p className="text-xs text-slate-400 font-mono break-all mb-1">
                                                    https://{project.subdomain}.nexus-academic.software
                                                </p>
                                                <p className="text-xs text-slate-500 break-all mb-3">
                                                    Git: {project.github_repo_url} (Rama: {project.branch})
                                                </p>

                                                {/* Sección de Backend Vinculado */}
                                                {linkedBackendObj ? (
                                                    <div className="backend-link-box p-3 bg-slate-900/70 border border-slate-800 rounded-xl space-y-2 mt-3">
                                                        <div className="flex items-center justify-between">
                                                            <span className="text-[11px] font-bold text-indigo-400 flex items-center gap-1.5">
                                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                                </svg>
                                                                Backend API: <strong className="text-white">{linkedBackendObj.name}</strong>
                                                            </span>
                                                            <span className={`px-1.5 py-0.5 rounded text-[10px] font-semibold ${linkedBackendObj.status === 'running' ? 'text-emerald-400 bg-emerald-500/10' : 'text-slate-400 bg-slate-800'}`}>
                                                                {linkedBackendObj.status === 'running' ? '● API Online' : '● API Inactiva'}
                                                            </span>
                                                        </div>
                                                        <p className="text-[11px] text-slate-400 font-mono truncate">
                                                            API: https://{linkedBackendObj.subdomain}.nexus-academic.software/api
                                                        </p>
                                                        <div className="flex items-center justify-between pt-1 border-t border-slate-800 text-[11px]">
                                                            <div className="flex space-x-2">
                                                                <button
                                                                    onClick={() => fetchLogs(linkedBackendObj)}
                                                                    className="text-slate-400 hover:text-cyan-400 transition"
                                                                >
                                                                    Logs API
                                                                </button>
                                                                <button
                                                                    onClick={() => rebuildProject(linkedBackendObj.id)}
                                                                    className="text-slate-400 hover:text-indigo-400 transition"
                                                                >
                                                                    Rebuild API
                                                                </button>
                                                            </div>
                                                            <button
                                                                onClick={() => {
                                                                    router.patch(route('projects.link-backend', project.id), { backend_project_id: null }, { preserveScroll: true });
                                                                }}
                                                                className="text-red-400 hover:text-red-300 transition text-[10px]"
                                                            >
                                                                Desvincular
                                                            </button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="backend-link-box p-2.5 bg-slate-900/40 border border-dashed border-slate-800 rounded-xl flex items-center justify-between text-xs mt-3">
                                                        <span className="text-slate-400 text-[11px]">¿Requiere un Backend API separado?</span>
                                                        <button
                                                            onClick={() => setLinkBackendModalProject(project)}
                                                            className="px-2.5 py-1 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-[11px] font-bold transition"
                                                        >
                                                            + Vincular / Crear Backend
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Project Actions */}
                                            <div className="border-t border-slate-900 pt-3 flex flex-wrap gap-2 items-center justify-between">
                                                <div className="flex gap-2 flex-wrap">
                                                    {project.status === 'stopped' || project.status === 'sleeping' ? (
                                                        <button
                                                            onClick={() => startContainer(project.id)}
                                                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs transition"
                                                        >
                                                            Encender
                                                        </button>
                                                    ) : project.status === 'running' ? (
                                                        <button
                                                            onClick={() => stopContainer(project.id)}
                                                            className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 dark:text-slate-300 dark:border-transparent transition"
                                                        >
                                                            Apagar
                                                        </button>
                                                    ) : null}

                                                    <button
                                                        onClick={() => rebuildProject(project.id)}
                                                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 dark:bg-slate-900 dark:hover:bg-slate-800 dark:border-slate-800 dark:text-slate-300 transition shadow-2xs"
                                                        disabled={project.status === 'building'}
                                                    >
                                                        Actualizar (Build)
                                                    </button>

                                                    <button
                                                        onClick={() => fetchLogs(project)}
                                                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 dark:bg-slate-900 dark:hover:bg-slate-800 dark:border-slate-800 dark:text-slate-400 dark:hover:text-white transition shadow-2xs"
                                                    >
                                                        Ver Logs
                                                    </button>

                                                    <button
                                                        onClick={() => openInstructionsModal(project)}
                                                        className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 dark:bg-indigo-950/20 dark:hover:bg-indigo-950/45 dark:border-indigo-900/30 dark:text-indigo-400 transition shadow-2xs"
                                                    >
                                                        Instrucciones
                                                    </button>
                                                </div>

                                                <button
                                                    onClick={() => deleteProject(project.id)}
                                                    className="p-2 rounded-lg bg-red-50 hover:bg-red-100 border border-red-200 text-red-600 dark:bg-red-950/20 dark:hover:bg-red-950/50 dark:border-red-900/30 dark:text-red-400 transition text-xs shadow-2xs"
                                                    title="Eliminar Proyecto"
                                                >
                                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Modal para añadir un proyecto */}
            {isAddProjectOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    {/* Tarjeta Principal: Formulario de Despliegue */}
                    <div className="w-full max-w-xl bg-slate-900 rounded-2xl p-6 shadow-2xl max-h-[90vh] overflow-y-auto border-0">
                        <div className="flex justify-between items-start mb-5">
                            <div>
                                <h3 className="text-lg font-bold text-white tracking-wide">
                                    {attachParentProjectId ? (
                                        `Desplegar Backend para ${mainProjects.find(p => p.id === attachParentProjectId)?.name || 'Aplicación'}`
                                    ) : projectForm.data.deployment_mode === 'fullstack' ? (
                                        'Desplegar Suite Fullstack'
                                    ) : (
                                        'Desplegar Aplicación Simple'
                                    )}
                                </h3>
                                <p className="text-xs text-slate-400 mt-1">
                                    {attachParentProjectId
                                        ? 'Este servicio se vinculará como API backend y no consumirá cupo de vitrina.'
                                        : projectForm.data.deployment_mode === 'fullstack'
                                            ? 'Despliega Frontend y Backend en un solo paso con conexión automática.'
                                            : 'Publica un monolito o frontend individual.'}
                                </p>
                            </div>

                            <div>
                                <button
                                    onClick={() => {
                                        setIsAddProjectOpen(false);
                                        setAttachParentProjectId('');
                                        setIsDeployHelpOpen(false);
                                        setSelectedGuideOption(null);
                                    }}
                                    className="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition font-bold border-0"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>

                        {/* Selector de Modo de Despliegue (Fullstack vs Simple) */}
                        {!attachParentProjectId && (
                            <div className="grid grid-cols-2 gap-2 deploy-config-track mb-4">
                                <button
                                    type="button"
                                    onClick={() => projectForm.setData('deployment_mode', 'fullstack')}
                                    className={`py-2 px-3 rounded-lg text-xs font-bold transition border-0 ${projectForm.data.deployment_mode === 'fullstack'
                                        ? 'deploy-config-btn-active'
                                        : 'deploy-config-btn-inactive'
                                        }`}
                                >
                                    Suite Fullstack (Frontend + API)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => projectForm.setData('deployment_mode', 'simple')}
                                    className={`py-2 px-3 rounded-lg text-xs font-bold transition border-0 ${projectForm.data.deployment_mode === 'simple'
                                        ? 'deploy-config-btn-active'
                                        : 'deploy-config-btn-inactive'
                                        }`}
                                >
                                    Aplicación Simple / Monolito
                                </button>
                            </div>
                        )}

                        {/* Pestañas de Selección de tipo de subida */}
                        <div className="flex gap-2 deploy-config-track mb-4">
                            <button
                                type="button"
                                onClick={() => setUploadType('github')}
                                className={`flex-1 py-2 rounded-lg text-xs font-bold transition border-0 ${uploadType === 'github'
                                    ? 'deploy-config-btn-active'
                                    : 'deploy-config-btn-inactive'
                                    }`}
                            >
                                GitHub
                            </button>
                            <button
                                type="button"
                                onClick={() => setUploadType('folder')}
                                className={`flex-1 py-2 rounded-lg text-xs font-bold transition border-0 ${uploadType === 'folder'
                                    ? 'deploy-config-btn-active'
                                    : 'deploy-config-btn-inactive'
                                    }`}
                            >
                                Carpeta Local / ZIP
                            </button>
                        </div>

                        <form onSubmit={handleProjectSubmit} className="space-y-4">
                            {Object.keys(projectForm.errors).length > 0 && (
                                <div className="p-3.5 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800/60 rounded-xl text-xs space-y-1.5 shadow-2xs">
                                    {Object.keys(projectForm.errors).length === 1 && projectForm.errors.category ? (
                                        <p className="font-bold text-red-800 dark:text-red-200">
                                            Elige una categoría para el proyecto
                                        </p>
                                    ) : (
                                        <>
                                            <p className="font-bold text-red-800 dark:text-red-200">
                                                {projectForm.errors.category ? 'Elige una categoría para el proyecto' : 'Corrige los siguientes errores antes de continuar:'}
                                            </p>
                                            <ul className="list-disc list-inside space-y-0.5 text-[11px] text-red-700 dark:text-red-300 pl-1">
                                                {Object.entries(projectForm.errors).map(([field, msg]) => {
                                                    if (field === 'category') return null;
                                                    const labels = {
                                                        name: 'Nombre del Proyecto',
                                                        subdomain: 'Subdominio',
                                                        backend_subdomain: 'Subdominio Backend',
                                                        github_repo_url: 'URL de GitHub',
                                                        backend_github_repo_url: 'URL de GitHub Backend',
                                                        branch: 'Rama para Deploy',
                                                        folder_files: 'Archivos del Proyecto',
                                                        deployment_mode: 'Modo de Despliegue',
                                                    };
                                                    const fieldLabel = labels[field] || field.replace('_', ' ');
                                                    return (
                                                        <li key={field}>
                                                            <span className="font-bold">{fieldLabel}:</span> {msg}
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                        </>
                                    )}
                                </div>
                            )}
                            {/* ======================================================= */}
                            {/* PASO 1: FUENTE DE CÓDIGO (GitHub o Local)              */}
                            {/* ======================================================= */}
                            {projectForm.data.deployment_mode === 'fullstack' && !attachParentProjectId ? (
                                <div className="space-y-4">
                                    {uploadType === 'github' && (
                                        <div className="flex gap-1.5 deploy-config-track mb-1">
                                            <button
                                                type="button"
                                                onClick={() => projectForm.setData('fullstack_source', 'monorepo')}
                                                className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition border-0 ${projectForm.data.fullstack_source === 'monorepo'
                                                    ? 'deploy-config-btn-active'
                                                    : 'deploy-config-btn-inactive'
                                                    }`}
                                            >
                                                Mismo Repositorio (Monorepo)
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => projectForm.setData('fullstack_source', 'separate')}
                                                className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition border-0 ${projectForm.data.fullstack_source === 'separate'
                                                    ? 'deploy-config-btn-active'
                                                    : 'deploy-config-btn-inactive'
                                                    }`}
                                            >
                                                Repositorios Separados
                                            </button>
                                        </div>
                                    )}

                                    {uploadType === 'github' ? (
                                        projectForm.data.fullstack_source === 'monorepo' ? (
                                            <>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                                                        URL del Repositorio GitHub
                                                    </label>
                                                    <input
                                                        type="url"
                                                        value={projectForm.data.github_repo_url}
                                                        onChange={e => handleRepoUrlChange(e.target.value)}
                                                        required
                                                        className="w-full px-4 py-2.5 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 placeholder-slate-700 text-sm font-mono"
                                                        placeholder="https://github.com/usuario/proyecto-grupoC.git"
                                                    />
                                                    {projectForm.errors.github_repo_url && <p className="text-xs text-red-400 mt-1">{projectForm.errors.github_repo_url}</p>}
                                                </div>

                                                <div className="grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Subcarpeta Frontend</label>
                                                        <input
                                                            type="text"
                                                            value={projectForm.data.frontend_dir}
                                                            onChange={e => projectForm.setData('frontend_dir', e.target.value)}
                                                            className="w-full px-3 py-2 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 text-xs font-mono"
                                                            placeholder="frontend"
                                                        />
                                                    </div>
                                                    <div>
                                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Subcarpeta Backend</label>
                                                        <input
                                                            type="text"
                                                            value={projectForm.data.backend_dir}
                                                            onChange={e => projectForm.setData('backend_dir', e.target.value)}
                                                            className="w-full px-3 py-2 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 text-xs font-mono"
                                                            placeholder="backend"
                                                        />
                                                    </div>
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="space-y-2 p-3 bg-slate-950 rounded-xl border-0">
                                                    <h5 className="text-xs font-semibold text-slate-300 uppercase tracking-wider">1. Repositorio de Frontend (React / Vue)</h5>
                                                    <input
                                                        type="url"
                                                        value={projectForm.data.github_repo_url}
                                                        onChange={e => handleRepoUrlChange(e.target.value)}
                                                        required
                                                        className="w-full px-3 py-2 bg-slate-900 border-0 rounded-lg text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500"
                                                        placeholder="https://github.com/usuario/frontend-repo"
                                                    />
                                                </div>

                                                <div className="space-y-2 p-3 bg-slate-950 rounded-xl border-0">
                                                    <h5 className="text-xs font-semibold text-slate-300 uppercase tracking-wider">2. Repositorio de Backend (Laravel / Python)</h5>
                                                    <input
                                                        type="url"
                                                        value={projectForm.data.backend_github_repo_url}
                                                        onChange={e => projectForm.setData('backend_github_repo_url', e.target.value)}
                                                        required
                                                        className="w-full px-3 py-2 bg-slate-900 border-0 rounded-lg text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500"
                                                        placeholder="https://github.com/usuario/backend-repo"
                                                    />
                                                </div>
                                            </>
                                        )
                                    ) : (
                                        <div className="space-y-3">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Selecciona archivo ZIP del Proyecto Fullstack</label>
                                                <div className="bg-slate-950 rounded-xl p-5 text-center cursor-pointer transition relative border-0">
                                                    <input
                                                        type="file"
                                                        accept=".zip"
                                                        onChange={handleZipChange}
                                                        required
                                                        className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                    />
                                                    <p className="text-xs font-semibold text-slate-300">
                                                        {projectForm.data.folder_files ? projectForm.data.folder_files[0].name : 'Selecciona tu archivo .zip (máx. 50MB)'}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Subcarpeta Frontend</label>
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.frontend_dir}
                                                        onChange={e => projectForm.setData('frontend_dir', e.target.value)}
                                                        className="w-full px-3 py-2 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 text-xs font-mono"
                                                        placeholder="frontend"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Subcarpeta Backend</label>
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.backend_dir}
                                                        onChange={e => projectForm.setData('backend_dir', e.target.value)}
                                                        className="w-full px-3 py-2 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 text-xs font-mono"
                                                        placeholder="backend"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {uploadType === 'github' ? (
                                        <>
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">URL del Repositorio Público (GitHub)</label>
                                                <input
                                                    type="url"
                                                    value={projectForm.data.github_repo_url}
                                                    onChange={e => handleRepoUrlChange(e.target.value)}
                                                    required
                                                    className="w-full px-4 py-2.5 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 placeholder-slate-700 text-sm font-mono"
                                                    placeholder="https://github.com/usuario/nombre-repositorio"
                                                />
                                                {projectForm.errors.github_repo_url && <p className="text-xs text-red-400 mt-1">{projectForm.errors.github_repo_url}</p>}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Rama para Deploy</label>
                                                <input
                                                    type="text"
                                                    value={projectForm.data.branch}
                                                    onChange={e => projectForm.setData('branch', e.target.value)}
                                                    required
                                                    className="w-full px-4 py-2.5 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 placeholder-slate-700 text-sm"
                                                    placeholder="main"
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <div className="space-y-4">
                                            <div className="flex gap-1.5 deploy-config-track mb-1">
                                                <button
                                                    type="button"
                                                    onClick={() => { setLocalUploadMode('zip'); projectForm.setData({ ...projectForm.data, folder_files: null, folder_paths: null }); }}
                                                    className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition border-0 ${localUploadMode === 'zip'
                                                        ? 'deploy-config-btn-active'
                                                        : 'deploy-config-btn-inactive'
                                                        }`}
                                                >
                                                    Archivo ZIP (Recomendado)
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => { setLocalUploadMode('folder'); projectForm.setData({ ...projectForm.data, folder_files: null, folder_paths: null }); }}
                                                    className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition border-0 ${localUploadMode === 'folder'
                                                        ? 'deploy-config-btn-active'
                                                        : 'deploy-config-btn-inactive'
                                                        }`}
                                                >
                                                    Carpeta Local
                                                </button>
                                            </div>

                                            {localUploadMode === 'zip' ? (
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Selecciona archivo ZIP del Proyecto</label>
                                                    <div className="bg-slate-950 rounded-xl p-5 text-center cursor-pointer transition relative border-0">
                                                        <input
                                                            type="file"
                                                            accept=".zip"
                                                            onChange={handleZipChange}
                                                            required
                                                            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                        />
                                                        <p className="text-xs font-semibold text-slate-300">
                                                            {projectForm.data.folder_files ? projectForm.data.folder_files[0].name : 'Selecciona tu archivo .zip'}
                                                        </p>
                                                    </div>
                                                </div>
                                            ) : (
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Selecciona la Carpeta del Proyecto</label>
                                                    <div className="bg-slate-950 rounded-xl p-5 text-center cursor-pointer transition relative border-0">
                                                        <input
                                                            type="file"
                                                            webkitdirectory="true"
                                                            directory="true"
                                                            multiple
                                                            onChange={handleFolderChange}
                                                            required
                                                            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                        />
                                                        <p className="text-xs font-semibold text-slate-300">
                                                            {projectForm.data.folder_files ? `${projectForm.data.folder_files.length} archivos seleccionados` : 'Selecciona una carpeta local'}
                                                        </p>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* ======================================================= */}
                            {/* PASO 2: NOMBRE Y SUBDOMINIOS                            */}
                            {/* ======================================================= */}
                            <div className="space-y-3 pt-3">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                                        <span>Categoría del Proyecto</span>
                                        <span className="text-[10px] text-amber-400 font-semibold uppercase bg-amber-400/10 px-2 py-0.5 rounded">Obligatorio</span>
                                    </label>
                                    <select
                                        value={projectForm.data.category || ''}
                                        onChange={e => projectForm.setData('category', e.target.value)}
                                        required
                                        className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 text-xs sm:text-sm font-medium cursor-pointer"
                                    >
                                        <option value="">-- Selecciona una categoría de software --</option>
                                        {PROJECT_CATEGORIES.map(cat => (
                                            <option key={cat} value={cat} className="bg-slate-900 text-slate-200 py-1">
                                                {cat}
                                            </option>
                                        ))}
                                    </select>
                                    {projectForm.errors.category && (
                                        <p className="text-xs text-red-600 dark:text-red-400 mt-1 font-medium">
                                            Elige una categoría para el proyecto
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Nombre del Proyecto</label>
                                    <input
                                        type="text"
                                        value={projectForm.data.name}
                                        onChange={e => projectForm.setData('name', e.target.value)}
                                        required
                                        className="w-full px-4 py-2.5 bg-slate-950 border-0 rounded-xl text-slate-200 focus:ring-1 focus:ring-indigo-500 placeholder-slate-700 text-sm"
                                        placeholder="Mi Portal Académico"
                                    />
                                    {projectForm.errors.name && <p className="text-xs text-red-400 mt-1">{projectForm.errors.name}</p>}
                                </div>

                                {projectForm.data.deployment_mode === 'fullstack' && !attachParentProjectId ? (
                                    <div className="space-y-3">
                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Subdominio Frontend</label>
                                                <div className="flex bg-slate-950 rounded-xl overflow-hidden">
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.subdomain}
                                                        onChange={e => projectForm.setData('subdomain', e.target.value)}
                                                        required
                                                        className="flex-1 px-3 py-2 bg-slate-950 border-0 text-slate-200 focus:ring-0 placeholder-slate-700 text-xs font-mono"
                                                        placeholder="mi_app_frontend"
                                                    />
                                                    <span className="px-2.5 py-2 bg-slate-950 text-slate-500 text-[10px] flex items-center font-mono select-none">
                                                        .nexus...
                                                    </span>
                                                </div>
                                                {projectForm.errors.subdomain && <p className="text-xs text-red-400 mt-1">{projectForm.errors.subdomain}</p>}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Subdominio Backend (API)</label>
                                                <div className="flex bg-slate-950 rounded-xl overflow-hidden">
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.backend_subdomain || ''}
                                                        onChange={e => projectForm.setData('backend_subdomain', e.target.value)}
                                                        required
                                                        className="flex-1 px-3 py-2 bg-slate-950 border-0 text-slate-200 focus:ring-0 placeholder-slate-700 text-xs font-mono"
                                                        placeholder="mi_app_backend"
                                                    />
                                                    <span className="px-2.5 py-2 bg-slate-950 text-slate-500 text-[10px] flex items-center font-mono select-none">
                                                        .nexus...
                                                    </span>
                                                </div>
                                                {projectForm.errors.backend_subdomain && <p className="text-xs text-red-400 mt-1">{projectForm.errors.backend_subdomain}</p>}
                                            </div>
                                        </div>

                                        {/* Vista Previa de URLs en Vivo */}
                                        {projectForm.data.subdomain && (
                                            <div className="mt-2 p-2.5 bg-slate-950 rounded-xl text-[11px] font-mono space-y-1">
                                                <div className="text-slate-300 truncate">
                                                    Frontend: http://{projectForm.data.subdomain}.nexus-academic.software
                                                </div>
                                                <div className="text-slate-400 truncate">
                                                    API: http://{projectForm.data.backend_subdomain || (projectForm.data.subdomain + '_backend')}.nexus-academic.software/api
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                ) : (
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Subdominio</label>
                                        <div className={`flex bg-slate-950 rounded-xl overflow-hidden ${projectForm.errors.subdomain ? 'border border-red-500/60' : 'border-0'}`}>
                                            <input
                                                type="text"
                                                value={projectForm.data.subdomain}
                                                onChange={e => projectForm.setData('subdomain', e.target.value)}
                                                required
                                                className="flex-1 px-4 py-2.5 bg-slate-950 border-0 text-slate-200 focus:ring-0 placeholder-slate-700 text-sm font-mono"
                                                placeholder="mi-proyecto"
                                            />
                                            <span className="px-4 py-2.5 bg-slate-950 text-slate-500 text-sm flex items-center font-mono select-none">
                                                .nexus-academic.software
                                            </span>
                                        </div>
                                        {projectForm.errors.subdomain && (
                                            <p className="text-xs text-red-600 dark:text-red-400 mt-1 font-medium">
                                                {projectForm.errors.subdomain}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={projectForm.processing}
                                className="w-full py-3 mt-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold transition cursor-pointer text-sm shadow-md border-0"
                            >
                                {projectForm.processing
                                    ? 'Preparando Despliegue...'
                                    : projectForm.data.deployment_mode === 'fullstack'
                                        ? 'Desplegar Suite Fullstack'
                                        : 'Iniciar Despliegue'}
                            </button>
                        </form>
                    </div>

                    {/* Tarjeta Flotante Independiente: Guía de Despliegue (a la derecha de la pantalla, arriba del botón) */}
                    {isDeployHelpOpen && (
                        <div className="fixed bottom-20 right-6 z-[60] w-80 md:w-96 max-h-[75vh] bg-slate-900 rounded-2xl p-5 shadow-2xl overflow-y-auto flex flex-col justify-between animate-fadeIn border-0">
                            <div>
                                <div className="flex justify-between items-center pb-3 mb-4 border-b border-slate-200 dark:border-slate-800">
                                    <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
                                        Guía de Despliegue
                                    </h4>
                                    <button
                                        type="button"
                                        onClick={() => setIsDeployHelpOpen(false)}
                                        className="text-slate-400 hover:text-slate-700 dark:hover:text-white text-xs p-1 rounded-lg border-0 transition"
                                        title="Cerrar guía"
                                    >
                                        ✕
                                    </button>
                                </div>

                                <div className="space-y-2.5 text-xs">
                                    {/* Opción 1: Fullstack Monorepo GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 1) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(1);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    fullstack_source: 'monorepo',
                                                    frontend_dir: 'frontend',
                                                    backend_dir: 'backend'
                                                });
                                                setUploadType('github');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 1
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">1. Fullstack GitHub (Monorepo)</h5>
                                            {selectedGuideOption === 1 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Frontend y Backend en un solo repositorio con subcarpetas <code>frontend/</code> y <code>backend/</code>.
                                        </p>
                                    </button>

                                    {/* Opción 2: Fullstack Repositorios Separados GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 2) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(2);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    fullstack_source: 'separate'
                                                });
                                                setUploadType('github');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 2
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">2. Fullstack GitHub (2 Repositorios)</h5>
                                            {selectedGuideOption === 2 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Frontend y Backend en 2 repositorios de GitHub independientes.
                                        </p>
                                    </button>

                                    {/* Opción 3: Fullstack en Archivo ZIP */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 3) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(3);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    frontend_dir: 'frontend',
                                                    backend_dir: 'backend'
                                                });
                                                setUploadType('folder');
                                                setLocalUploadMode('zip');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 3
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">3. Fullstack en Archivo ZIP</h5>
                                            {selectedGuideOption === 3 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Sube un <code>.zip</code> local con las carpetas frontend y backend juntas.
                                        </p>
                                    </button>

                                    {/* Opción 4: Proyecto Simple GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 4) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(4);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'simple'
                                                });
                                                setUploadType('github');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 4
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">4. Proyecto Simple (GitHub)</h5>
                                            {selectedGuideOption === 4 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Para monolitos (Laravel con Blade, PHP puro, Python) o SPA individual desde GitHub.
                                        </p>
                                    </button>

                                    {/* Opción 5: Proyecto Simple en Archivo ZIP */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 5) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(5);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'simple'
                                                });
                                                setUploadType('folder');
                                                setLocalUploadMode('zip');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 5
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">5. Proyecto Simple en Archivo ZIP</h5>
                                            {selectedGuideOption === 5 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Sube un <code>.zip</code> local de hasta 50MB para una sola aplicación o monolito.
                                        </p>
                                    </button>
                                </div>
                            </div>

                            <div className="pt-4 mt-4 text-[11px] text-slate-500 text-center">
                                Nexus Academic
                            </div>
                        </div>
                    )}

                    {/* Botón Flotante en la esquina inferior derecha de la pantalla */}
                    <div className="fixed bottom-6 right-6 z-[60]">
                        <button
                            type="button"
                            onClick={() => setIsDeployHelpOpen(!isDeployHelpOpen)}
                            className="px-4 py-2.5 rounded-xl bg-slate-900/90 hover:bg-slate-850 text-slate-300 hover:text-white shadow-2xl backdrop-blur-md text-xs font-semibold flex items-center gap-2 transition cursor-pointer border-0"
                        >
                            <span>{isDeployHelpOpen ? 'Ocultar Guía' : 'Guía de Ayuda'}</span>
                        </button>
                    </div>
                </div>
            )}

            {/* Modal para Vincular / Crear Backend para un proyecto existente */}
            {linkBackendModalProject && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <h3 className="text-lg font-bold text-white">Vincular Backend API</h3>
                                <p className="text-xs text-slate-400 mt-1">Para la aplicación: <strong className="text-cyan-400">{linkBackendModalProject.name}</strong></p>
                            </div>
                            <button
                                onClick={() => setLinkBackendModalProject(null)}
                                className="p-1 px-2 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition font-bold"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="space-y-4">
                            {/* Opción 1: Desplegar nuevo backend */}
                            <div className="p-4 rounded-xl border border-indigo-500/30 bg-indigo-500/10">
                                <h4 className="text-sm font-bold text-white mb-1">Opción 1: Desplegar Nuevo Backend</h4>
                                <p className="text-xs text-slate-400 mb-3">Sube un nuevo repositorio o carpeta de Laravel/Python para conectarlo a esta app sin gastar cupo de vitrina.</p>
                                <button
                                    onClick={() => {
                                        const parentId = linkBackendModalProject.id;
                                        setLinkBackendModalProject(null);
                                        setAttachParentProjectId(parentId);
                                        projectForm.setData({
                                            name: '',
                                            subdomain: '',
                                            github_repo_url: '',
                                            branch: 'main',
                                            folder_files: null,
                                            folder_paths: null,
                                            root_dir: '',
                                            env_vars: '',
                                            attach_to_project_id: parentId
                                        });
                                        setIsAddProjectOpen(true);
                                    }}
                                    className="w-full py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs transition"
                                >
                                    + Subir y Desplegar Nuevo Backend
                                </button>
                            </div>

                            {/* Opción 2: Vincular backend ya desplegado */}
                            {backendProjects.length > 0 && (
                                <div className="p-4 rounded-xl border border-slate-800 bg-slate-950">
                                    <h4 className="text-sm font-bold text-white mb-1">Opción 2: Vincular API Existente</h4>
                                    <p className="text-xs text-slate-400 mb-3">Selecciona un proyecto backend que ya tengas activo en tu cuenta.</p>
                                    <select
                                        id="existingBackendSelect"
                                        className="w-full px-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-slate-200 text-xs mb-3"
                                    >
                                        {backendProjects.map(p => (
                                            <option key={p.id} value={p.id}>{p.name} ({p.subdomain}.nexus-academic.software)</option>
                                        ))}
                                    </select>
                                    <button
                                        onClick={() => {
                                            const selectEl = document.getElementById('existingBackendSelect');
                                            if (selectEl && selectEl.value) {
                                                router.patch(route('projects.link-backend', linkBackendModalProject.id), {
                                                    backend_project_id: selectEl.value
                                                }, {
                                                    onSuccess: () => setLinkBackendModalProject(null)
                                                });
                                            }
                                        }}
                                        className="w-full py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-xs transition"
                                    >
                                        Vincular Backend Seleccionado
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Demo Instructions Editing Modal */}
            {instructionsProject && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl">
                        <div className="flex justify-between items-start mb-4">
                            <div>
                                <h3 className="text-xl font-bold text-white">Instrucciones de la Demo</h3>
                                <p className="text-xs text-slate-400 mt-1">Proyecto: {instructionsProject.name}</p>
                            </div>
                            <button
                                onClick={() => setInstructionsProject(null)}
                                className="p-1 px-2 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition font-bold"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleInstructionsSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Guía para el Evaluador / Reclutador</label>
                                <textarea
                                    value={instructionsForm.data.demo_instructions}
                                    onChange={(e) => instructionsForm.setData('demo_instructions', e.target.value)}
                                    rows="6"
                                    maxLength="1000"
                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-600 text-sm transition"
                                    placeholder="Ej: Usuario de prueba: admin@example.com&#10;Contraseña: password&#10;&#10;Instrucciones: Inicia sesión, ingresa al panel y agrega un nuevo registro en el módulo de ventas."
                                />
                                <p className="text-[10px] text-slate-500 mt-1">Máximo 1000 caracteres. Esta información se mostrará en el panel de ayuda lateral cuando el reclutador abra tu demo.</p>
                                {instructionsForm.errors.demo_instructions && <p className="text-xs text-red-400 mt-1">{instructionsForm.errors.demo_instructions}</p>}
                            </div>

                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    onClick={() => setInstructionsProject(null)}
                                    className="flex-1 py-2.5 rounded-xl bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 font-bold transition text-sm shadow-2xs"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={instructionsForm.processing}
                                    className="flex-1 py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 font-bold transition text-sm"
                                >
                                    {instructionsForm.processing ? 'Guardando...' : 'Guardar Instrucciones'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {activeLogsProject && (
                <div
                    className="fixed inset-0 z-50 backdrop-blur-[2px] flex justify-end"
                    style={{ backgroundColor: 'rgba(2, 6, 23, 0.4)' }}
                >
                    <div className="w-full max-w-3xl bg-slate-900/85 border-l border-slate-800/80 h-screen flex flex-col p-6 shadow-2xl backdrop-blur-md">
                        <div className="flex justify-between items-start mb-6">
                            <div>
                                <h3 className="text-xl font-bold text-white">Visualizador de Logs</h3>
                                <p className="text-sm text-slate-400 mt-1">Logs para el proyecto: {activeLogsProject.name}</p>
                            </div>
                            <button
                                onClick={() => setActiveLogsProject(null)}
                                className="p-2 hover:bg-slate-800 rounded-full text-slate-400 hover:text-white transition"
                            >
                                ✕ Cerrar
                            </button>
                        </div>

                        {loadingLogs ? (
                            <div className="flex-1 flex flex-col items-center justify-center text-slate-500">
                                <svg className="animate-spin h-8 w-8 text-cyan-400 mb-2" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <p>Cargando registros del contenedor...</p>
                            </div>
                        ) : (
                            <div className="flex-1 flex flex-col min-h-0 space-y-5 overflow-hidden">
                                {/* Build logs (Collapsible Steps) */}
                                <div className="flex-[3] flex flex-col min-h-0">
                                    <h4 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Pasos de Compilación y Despliegue</h4>
                                    <div className="flex-1 overflow-y-auto pr-1">
                                        {parseLogs(logs.build_log).map((step, idx) => (
                                            <LogStep key={idx} step={step} />
                                        ))}
                                    </div>
                                </div>

                                {/* Container console logs */}
                                {logs.container_log && (
                                    <div className="flex-1 flex flex-col min-h-0 max-h-[220px]">
                                        <h4 className="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Consola del Contenedor (Stdout/Stderr)</h4>
                                        <pre className="flex-1 p-4 bg-slate-950 border border-slate-850 rounded-xl font-mono text-xs text-cyan-400 overflow-y-auto whitespace-pre-wrap">
                                            {logs.container_log}
                                        </pre>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Modal de confirmación para eliminar un proyecto */}
            {projectToDelete !== null && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl">
                        <div className="flex items-center space-x-3 text-red-400 mb-4">
                            <svg className="h-6 w-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <h3 className="text-lg font-bold text-white">¿Confirmar Eliminación?</h3>
                        </div>
                        <p className="text-sm text-slate-300 mb-6 leading-relaxed">
                            ¿Estás seguro de que deseas eliminar este proyecto por completo? Se detendrán los contenedores y se borrará el almacenamiento local.
                        </p>
                        <div className="flex justify-end space-x-3">
                            <button
                                onClick={() => setProjectToDelete(null)}
                                className="px-4 py-2 rounded-xl text-xs font-bold bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 transition duration-150 shadow-2xs"
                            >
                                Cancelar
                            </button>
                            <button
                                onClick={() => {
                                    const toDelete = projectToDelete;
                                    setProjectToDelete(null); // Cierra el modal inmediatamente
                                    setDeletedProjectIds(prev => [...prev, toDelete]); // Oculta la tarjeta al instante (0ms)
                                    router.delete(route('projects.destroy', toDelete));
                                }}
                                className="px-4 py-2 rounded-xl text-xs font-bold bg-red-600 hover:bg-red-500 text-white transition duration-150 shadow-lg shadow-red-600/10"
                            >
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

// === AUXILIARY LOGS VIEWER COMPONENTS (ACCORDION & AI ADVICE) ===

function parseLogs(logText) {
    if (!logText) return [];

    // Buscar encabezados de paso como "--- PASO 2: Detectando Lenguaje/Entorno ---"
    const stepRegex = /---\s*(PASO\s+[0-9.]+:\s*[^-]+)\s*---/g;
    const steps = [];
    let match;
    const matches = [];

    while ((match = stepRegex.exec(logText)) !== null) {
        matches.push({
            title: match[1],
            index: match.index,
            length: match[0].length
        });
    }

    if (matches.length === 0) {
        return [{ title: 'Bitácora del Despliegue', content: logText, defaultOpen: true }];
    }

    // El resultado general del build determina con precisión absoluta si existió un fallo real
    const isBuildSuccessful = logText.includes('=== COMPILACIÓN EXITOSA ===');
    const isBuildFailed = logText.includes('=== COMPILACIÓN FALLIDA ===');

    const headerText = logText.substring(0, matches[0].index).trim();
    if (headerText) {
        steps.push({ title: 'Preparando Entorno', content: headerText, hasError: false, defaultOpen: false });
    }

    for (let i = 0; i < matches.length; i++) {
        const currentMatch = matches[i];
        const nextMatch = matches[i + 1];

        const start = currentMatch.index + currentMatch.length;
        const end = nextMatch ? nextMatch.index : logText.length;

        const content = logText.substring(start, end).trim();

        // 1. Si el paso contiene un fallo explícito o advertencia de fallo, se marca con error
        // 2. Si la compilación falló en general, el paso que abortó la ejecución también se marca con error
        const contentLower = content.toLowerCase();
        let hasError = false;

        if (contentLower.includes('advertencia/fallo') || contentLower.includes('error al iniciar') || contentLower.includes('fallo al ejecutar') || contentLower.includes('fatal error')) {
            hasError = true;
        } else if (isBuildFailed) {
            hasError = (i === matches.length - 1);
        } else if (!isBuildSuccessful) {
            const lines = content.split('\n');
            hasError = lines.some(line => {
                const trimmed = line.trim().toLowerCase();
                return trimmed.includes('fatal error') || trimmed.includes('uncaught exception') || trimmed.includes('compilación fallida');
            });
        }

        steps.push({
            title: currentMatch.title,
            content: content,
            hasError: hasError,
            defaultOpen: hasError || (i === matches.length - 1)
        });
    }

    return steps;
}

function getQuickFixAdvice(content) {
    if (!content) return null;

    const text = content.toLowerCase();

    if (text.includes('falta el archivo de base de datos sql o archivos de migraciones')) {
        return {
            title: "Sugerencia del Asistente (Falta Base de Datos)",
            desc: "Tu proyecto requiere una base de datos, pero no se encontró ningún archivo .sql ni migraciones. Exporta tu base de datos desde phpMyAdmin o pgAdmin como un archivo .sql y súbelo a la carpeta de tu proyecto antes de desplegar."
        };
    }
    if (text.includes('could not find driver') || text.includes('pdoexception')) {
        return {
            title: "Sugerencia del Asistente (Falta Driver PDO)",
            desc: "El entorno PHP de tu proyecto no encuentra el controlador correspondiente de base de datos (mysqlnd / pgsql). Asegúrate de incluir el driver correspondiente en composer.json o de que la configuración de conexión sea la adecuada."
        };
    }
    if (text.includes('relation') && text.includes('does not exist')) {
        return {
            title: "Sugerencia del Asistente (Tabla Inexistente)",
            desc: "La base de datos se conectó con éxito, pero la consulta busca una tabla que no existe en el esquema. Asegúrate de incluir la estructura de la base de datos (CREATE TABLE) dentro de tu script .sql o archivo de migración."
        };
    }
    if (text.includes('libleptonica') || text.includes('cannot open shared object') || text.includes('librería nativa del sistema faltante')) {
        return {
            title: "Guía de Solución: Dependencia Nativa del Sistema Faltante",
            desc: "Tu aplicación intenta cargar librerías de C/C++ del sistema operativo (ej: OCR, procesamiento pesado de imágenes o PDFs con Leptonica/Tesseract). Los proyectos de este tipo están pensados para correr en contenedores personalizados con Dockerfile. Para proyectos web estándar, utiliza librerías puras del lenguaje que no requieran binarios externos del sistema."
        };
    }
    if (text.includes('error de conexión con la base de datos') || text.includes('connection refused') || text.includes('connectionbad')) {
        return {
            title: "Guía de Solución: Conexión con Base de Datos Rechazada",
            desc: "Tu servidor no pudo conectarse con la base de datos. Verifica que el servicio de base de datos asignado en la plataforma esté encendido y que las variables DB_HOST, DB_PORT, DB_USERNAME y DB_PASSWORD en tu proyecto coincidan con las de tu panel."
        };
    }
    if (text.includes('cannot find module') || text.includes('modulenotfounderror') || text.includes('dependencia o módulo no instalado')) {
        return {
            title: "Guía de Solución: Librería No Instalada",
            desc: "Tu código intenta importar un paquete que no se encuentra instalado. Declara la librería en package.json, requirements.txt, Gemfile o composer.json y sube los cambios a tu repositorio."
        };
    }
    if (text.includes('no se pudo iniciar el servidor')) {
        return {
            title: "Guía de Solución: El Servidor No Pudo Arrancar",
            desc: "El contenedor fue generado pero el proceso del servidor se cerró al instante. Revisa los puntos de '¿Qué pasó?' y '¿Cómo solucionarlo?' para ver la causa exacta y resolverla."
        };
    }

    return null;
}

function LogStep({ step }) {
    const [isOpen, setIsOpen] = useState(step.defaultOpen);
    const advice = getQuickFixAdvice(step.content);

    return (
        <div className={`border rounded-xl mb-3 overflow-hidden transition-all duration-200 ${step.hasError
            ? 'border-red-950 bg-red-950/10'
            : 'border-slate-800 bg-slate-900/40'
            }`}>
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="w-full px-4 py-3 flex items-center justify-between text-left font-semibold text-xs transition hover:bg-slate-800/40"
            >
                <div className="flex items-center space-x-2.5">
                    {step.hasError ? (
                        <span className="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse"></span>
                    ) : (
                        <span className="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
                    )}
                    <span className={step.hasError ? 'text-red-400 font-bold' : 'text-slate-200'}>
                        {step.title}
                    </span>
                </div>
                <span className="text-slate-500 text-[10px]">{isOpen ? '▲ Ocultar' : '▼ Mostrar'}</span>
            </button>

            {isOpen && (
                <div className="border-t border-slate-850 p-4 bg-slate-950">
                    <pre className="font-mono text-xs text-slate-300 whitespace-pre-wrap leading-relaxed">
                        {step.content || '(Sin mensajes de log)'}
                    </pre>
                </div>
            )}
        </div>
    );
}
