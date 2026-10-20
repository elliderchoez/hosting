import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import axios from 'axios';

export const parseEnvToPairs = (raw) => {
    if (!raw || typeof raw !== 'string') return [{ key: '', value: '' }];
    const lines = raw.split('\n');
    const pairs = [];
    for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        const eqIdx = trimmed.indexOf('=');
        if (eqIdx !== -1) {
            const key = trimmed.substring(0, eqIdx).trim();
            const value = trimmed.substring(eqIdx + 1).trim();
            pairs.push({ key, value });
        } else {
            pairs.push({ key: trimmed, value: '' });
        }
    }
    return pairs.length > 0 ? pairs : [{ key: '', value: '' }];
};

export const formatPairsToEnv = (pairs) => {
    return pairs
        .filter(p => p.key && p.key.trim() !== '')
        .map(p => `${p.key.trim()}=${p.value || ''}`)
        .join('\n');
};

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
        deployment_mode: 'simple',
        fullstack_source: 'monorepo',
        name: '',
        category: '',
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

    // Editor Visual de Variables de Entorno (.env)
    const [envPairs, setEnvPairs] = useState([{ key: '', value: '' }]);
    const [envEditorMode, setEnvEditorMode] = useState('visual'); // 'visual' | 'raw'
    const [isEnvSectionOpen, setIsEnvSectionOpen] = useState(false);

    // Validación previa (Pre-flight Check) de GitHub
    const [githubCheckStatus, setGithubCheckStatus] = useState('idle'); // 'idle' | 'checking' | 'valid' | 'invalid'
    const [githubCheckDetails, setGithubCheckDetails] = useState(null);

    // Proyectos del estudiante que son backends candidatos (PHP, Python, Java, .NET)
    const backendProjects = visibleProjects.filter(p =>
        ['php', 'python', 'java', 'dotnet'].includes(p.language)
    );

    // Cuando el usuario selecciona un backend, inyecta todas las variantes de URL conocidas
    const handleLinkBackend = (subdomain) => {
        setLinkedBackend(subdomain);
        if (!subdomain) {
            projectForm.setData('env_vars', '');
            setEnvPairs([{ key: '', value: '' }]);
            return;
        }
        const apiUrl = `https://${subdomain}.uleamcode.software/api`;
        const vars = [
            `VITE_API_URL=${apiUrl}`,
            `REACT_APP_API_URL=${apiUrl}`,
            `NEXT_PUBLIC_API_URL=${apiUrl}`,
            `API_URL=${apiUrl}`,
            `BACKEND_URL=https://${subdomain}.uleamcode.software`
        ].join('\n');
        projectForm.setData('env_vars', vars);
        setEnvPairs(parseEnvToPairs(vars));
        setIsEnvSectionOpen(true);
    };

    // Handlers para el Editor Visual de .env
    const handleEnvPairChange = (index, field, val) => {
        const updated = [...envPairs];
        updated[index] = { ...updated[index], [field]: val };
        setEnvPairs(updated);
        projectForm.setData('env_vars', formatPairsToEnv(updated));
    };

    const handleAddEnvPair = () => {
        setEnvPairs(prev => [...prev, { key: '', value: '' }]);
    };

    const handleRemoveEnvPair = (index) => {
        const updated = envPairs.filter((_, i) => i !== index);
        const finalPairs = updated.length > 0 ? updated : [{ key: '', value: '' }];
        setEnvPairs(finalPairs);
        projectForm.setData('env_vars', formatPairsToEnv(finalPairs));
    };

    const handleRawEnvChange = (val) => {
        projectForm.setData('env_vars', val);
        setEnvPairs(parseEnvToPairs(val));
    };

    const activeEnvCount = envPairs.filter(p => p.key && p.key.trim() !== '').length;

    // Pre-flight Check automático para URLs de GitHub
    useEffect(() => {
        const url = projectForm.data.github_repo_url?.trim();
        if (!url || uploadType !== 'github') {
            setGithubCheckStatus('idle');
            setGithubCheckDetails(null);
            return;
        }

        const isGithub = /^https?:\/\/github\.com\/[a-zA-Z0-9_\.-]+\/[a-zA-Z0-9_\.-]+/i.test(url);
        if (!isGithub) {
            setGithubCheckStatus('idle');
            setGithubCheckDetails(null);
            return;
        }

        const timer = setTimeout(() => {
            setGithubCheckStatus('checking');
            axios.post(route('projects.check-github'), { url })
                .then(res => {
                    if (res.data && res.data.valid) {
                        setGithubCheckStatus('valid');
                        setGithubCheckDetails(res.data);
                        if (res.data.default_branch && (!projectForm.data.branch || projectForm.data.branch === 'main')) {
                            projectForm.setData('branch', res.data.default_branch);
                        }
                    } else {
                        setGithubCheckStatus('invalid');
                        setGithubCheckDetails(res.data || { message: 'Repositorio inaccesible o privado.' });
                    }
                })
                .catch(err => {
                    const message = err.response?.data?.message || 'No se pudo verificar el repositorio en GitHub.';
                    setGithubCheckStatus('invalid');
                    setGithubCheckDetails({ message });
                });
        }, 500);

        return () => clearTimeout(timer);
    }, [projectForm.data.github_repo_url, uploadType]);

    // Componente visual de estado de verificación Pre-Flight de GitHub
    const renderGithubPreflightBadge = () => {
        if (githubCheckStatus === 'checking') {
            return (
                <div className="github-preflight-checking flex items-center gap-2 mt-2 px-3 py-1.5 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 dark:bg-indigo-500/10 dark:border-indigo-500/30 dark:text-indigo-300 text-xs animate-pulse">
                    <svg className="animate-spin h-3.5 w-3.5 text-indigo-600 dark:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span>Verificando acceso público al repositorio GitHub (Pre-flight)...</span>
                </div>
            );
        }
        if (githubCheckStatus === 'valid') {
            return (
                <div className="github-preflight-valid flex flex-wrap items-center justify-between gap-1 mt-2 px-3 py-2 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 dark:bg-emerald-500/10 dark:border-emerald-500/30 dark:text-emerald-300 text-xs">
                    <div className="flex items-center gap-2">
                        <svg className="w-3.5 h-3.5 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                        <span>
                            <strong className="text-emerald-950 dark:text-emerald-100">Repositorio verificado:</strong>{' '}
                            <span className="font-semibold text-emerald-800 dark:text-emerald-300">{githubCheckDetails?.name || 'Válido'}</span>
                            {githubCheckDetails?.language && <span className="ml-1 text-emerald-700 dark:text-emerald-400/80">({githubCheckDetails.language})</span>}
                        </span>
                    </div>
                    {githubCheckDetails?.default_branch && (
                        <span className="branch-badge text-[11px] font-mono bg-emerald-100 dark:bg-emerald-500/20 px-2 py-0.5 rounded text-emerald-800 dark:text-emerald-200 border border-emerald-300/60 dark:border-transparent font-medium">
                            Rama: {githubCheckDetails.default_branch}
                        </span>
                    )}
                </div>
            );
        }
        if (githubCheckStatus === 'invalid') {
            return (
                <div className="github-preflight-invalid flex items-start gap-2 mt-2 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 dark:bg-rose-500/10 dark:border-rose-500/30 dark:text-rose-300 text-xs">
                    <svg className="w-3.5 h-3.5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>{githubCheckDetails?.message || 'Repositorio no accesible o privado en GitHub.'}</span>
                </div>
            );
        }
        return null;
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

    // Formatear texto a slug válido para subdominio (solo letras, números y guiones bajos)
    const formatToSlug = (text) => {
        return (text || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9_-]/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');
    };

    // Actualizar nombre del proyecto y sincronizar en tiempo real el subdominio no editable
    const handleProjectNameChange = (newName) => {
        const slug = formatToSlug(newName);
        const isFullstack = projectForm.data.deployment_mode === 'fullstack';
        projectForm.setData(prev => ({
            ...prev,
            name: newName,
            subdomain: isFullstack ? (slug ? `${slug}_frontend` : '') : slug,
            backend_subdomain: isFullstack ? (slug ? `${slug}_backend` : '') : ''
        }));
    };

    // Alternar modo Fullstack (Frontend + Backend) con recálculo automático de subdominios
    const handleToggleFullstack = (checked) => {
        const isFullstack = checked;
        const slug = formatToSlug(projectForm.data.name);
        projectForm.setData(prev => ({
            ...prev,
            deployment_mode: isFullstack ? 'fullstack' : 'simple',
            fullstack_source: 'monorepo',
            subdomain: isFullstack ? (slug ? `${slug}_frontend` : '') : slug,
            backend_subdomain: isFullstack ? (slug ? `${slug}_backend` : '') : '',
            frontend_dir: isFullstack ? (prev.frontend_dir || 'frontend') : 'frontend',
            backend_dir: isFullstack ? (prev.backend_dir || 'backend') : 'backend'
        }));
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

            const slug = formatToSlug(repoName);
            const isFullstack = projectForm.data.deployment_mode === 'fullstack';

            updates.name = prettyName;
            updates.subdomain = isFullstack ? (slug ? `${slug}_frontend` : '') : slug;
            updates.backend_subdomain = isFullstack ? (slug ? `${slug}_backend` : '') : '';

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
        const file = e.target.files && e.target.files[0];
        if (file) {
            const rawName = file.name.replace(/\.zip$/i, '');
            const prettyName = rawName.replace(/[-_]+/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const slug = formatToSlug(rawName);
            const isFullstack = projectForm.data.deployment_mode === 'fullstack';

            const updates = {
                folder_files: [file],
                folder_paths: [file.name],
                name: prettyName,
                subdomain: isFullstack ? (slug ? `${slug}_frontend` : '') : slug,
                backend_subdomain: isFullstack ? (slug ? `${slug}_backend` : '') : ''
            };

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
                                        setLocalUploadMode('zip');
                                        setSelectedGuideOption(null);
                                        projectForm.setData({
                                            deployment_mode: 'simple',
                                            fullstack_source: 'monorepo',
                                            name: '',
                                            category: '',
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
                                        setGithubCheckStatus('idle');
                                        setGithubCheckDetails(null);
                                        setEnvPairs([{ key: '', value: '' }]);
                                        setIsEnvSectionOpen(false);
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
                                                            {!project.is_visible_in_showcase && (
                                                                <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/30 flex items-center gap-1">
                                                                    Oculto en Vitrina
                                                                </span>
                                                            )}
                                                        </h4>
                                                    </div>
                                                    {project.is_suspended ? (
                                                        <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider bg-red-500/15 text-red-400 border border-red-500/30 animate-pulse">
                                                            Pausado por Infracción
                                                        </span>
                                                    ) : (
                                                        <span className={`px-2.5 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider ${project.status === 'running' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-cyan-500/15 dark:text-cyan-400 dark:border-cyan-500/30' :
                                                            project.status === 'sleeping' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-500/15 dark:text-indigo-400 dark:border-indigo-500/30' :
                                                                project.status === 'building' ? 'bg-amber-50 text-amber-700 border border-amber-200 dark:bg-yellow-500/15 dark:text-yellow-400 dark:border-yellow-500/30 animate-pulse' :
                                                                    'bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'
                                                            }`}>
                                                            {project.status === 'running' ? 'Activo' :
                                                                project.status === 'sleeping' ? 'Suspendido' :
                                                                    project.status === 'building' ? 'Compilando' :
                                                                        'Apagado'}
                                                        </span>
                                                    )}
                                                </div>

                                                {project.is_suspended && (
                                                    <div className="p-3 bg-red-950/40 border border-red-800/60 rounded-xl mb-3 text-red-200 text-xs">
                                                        <div className="font-bold flex items-center gap-1.5 text-red-300 mb-1">
                                                            <svg className="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                            </svg>
                                                            Proyecto pausado por la administración académica
                                                        </div>
                                                        <p className="text-red-300/90">{project.suspension_reason || 'Infracción de normativas universitarias o contenido prohibido.'}</p>
                                                        <p className="text-[11px] text-red-400/80 mt-1">Por favor contacta a la coordinación de carrera para regularizar la situación.</p>
                                                    </div>
                                                )}

                                                <p className="text-xs text-slate-400 font-mono break-all mb-1">
                                                    https://{project.subdomain}.uleamcode.software
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
                                                            API: https://{linkedBackendObj.subdomain}.uleamcode.software/api
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
                                                <div className="flex gap-2 flex-wrap items-center">
                                                    {project.is_suspended ? (
                                                        <span className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-950/30 text-red-400 border border-red-800/40 flex items-center gap-1.5">
                                                            <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                            </svg>
                                                            Operaciones bloqueadas
                                                        </span>
                                                    ) : (
                                                        <>
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
                                                        </>
                                                    )}

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
                    {/* Tarjeta Principal: Formulario de Despliegue */}
                    <div className="w-full max-w-xl bg-white dark:bg-slate-900 rounded-2xl p-6 shadow-2xl h-[90vh] flex flex-col border border-slate-200 dark:border-slate-800">
                        <div className="flex justify-between items-start mb-4 shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white tracking-wide">
                                    {attachParentProjectId ? (
                                        `Desplegar Backend para ${mainProjects.find(p => p.id === attachParentProjectId)?.name || 'Aplicación'}`
                                    ) : projectForm.data.deployment_mode === 'fullstack' ? (
                                        'Desplegar Proyecto Fullstack'
                                    ) : (
                                        'Desplegar Proyecto'
                                    )}
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                    {attachParentProjectId
                                        ? 'Este servicio se vinculará como API backend y no consumirá cupo de vitrina.'
                                        : 'Indica el enlace o archivo de tu proyecto para desplegarlo en la plataforma.'}
                                </p>
                            </div>

                            <div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setIsAddProjectOpen(false);
                                        setAttachParentProjectId('');
                                        setIsDeployHelpOpen(false);
                                        setSelectedGuideOption(null);
                                        setGithubCheckStatus('idle');
                                        setGithubCheckDetails(null);
                                        setEnvPairs([{ key: '', value: '' }]);
                                        setIsEnvSectionOpen(false);
                                    }}
                                    className="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-white transition font-bold border-0"
                                    title="Cerrar"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>

                        {/* Selector Superior Segmentado: GitHub vs Archivo ZIP */}
                        <div className="grid grid-cols-2 gap-2 deploy-config-track mb-4 shrink-0">
                            <button
                                type="button"
                                onClick={() => {
                                    setUploadType('github');
                                    projectForm.setData(prev => ({
                                        ...prev,
                                        folder_files: null,
                                        folder_paths: null
                                    }));
                                }}
                                className={`py-2 px-3 rounded-lg text-xs font-bold transition flex items-center justify-center gap-2 border-0 ${uploadType === 'github'
                                    ? 'deploy-config-btn-active'
                                    : 'deploy-config-btn-inactive'
                                    }`}
                            >
                                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                    <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z" />
                                </svg>
                                <span>GitHub</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setUploadType('folder');
                                    setLocalUploadMode('zip');
                                }}
                                className={`py-2 px-3 rounded-lg text-xs font-bold transition flex items-center justify-center gap-2 border-0 ${uploadType === 'folder'
                                    ? 'deploy-config-btn-active'
                                    : 'deploy-config-btn-inactive'
                                    }`}
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                <span>Archivo ZIP</span>
                            </button>
                        </div>

                        <form onSubmit={handleProjectSubmit} className="flex-1 flex flex-col min-h-0">
                            <div className="flex-1 overflow-y-auto space-y-4 pr-1.5">
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
                            {/* BLOQUE 1: ORIGEN DEL CODIGO (GitHub o Archivo ZIP)     */}
                            {/* ======================================================= */}
                            {uploadType === 'github' ? (
                                <div className="space-y-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">
                                            URL del Repositorio GitHub
                                        </label>
                                        <input
                                            type="url"
                                            value={projectForm.data.github_repo_url}
                                            onChange={e => handleRepoUrlChange(e.target.value)}
                                            required
                                            className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 placeholder-slate-400 dark:placeholder-slate-600 text-xs sm:text-sm font-mono"
                                            placeholder="https://github.com/usuario/mi-proyecto"
                                        />
                                        {projectForm.errors.github_repo_url && (
                                            <p className="text-xs text-red-500 dark:text-red-400 mt-1">{projectForm.errors.github_repo_url}</p>
                                        )}
                                        {renderGithubPreflightBadge()}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">
                                            Rama para Deploy
                                        </label>
                                        <input
                                            type="text"
                                            value={projectForm.data.branch}
                                            onChange={e => projectForm.setData('branch', e.target.value)}
                                            required
                                            className="w-full px-3.5 py-2 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs font-mono"
                                            placeholder="main"
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-2">
                                        Archivo ZIP del Proyecto
                                    </label>
                                    <div className="relative border-2 border-dashed rounded-xl p-5 text-center transition border-slate-300 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/40 hover:bg-slate-50 dark:hover:bg-slate-950/70">
                                        <input
                                            type="file"
                                            accept=".zip"
                                            onChange={handleZipChange}
                                            required={!projectForm.data.folder_files}
                                            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                        />
                                        {projectForm.data.folder_files && projectForm.data.folder_files[0] ? (
                                            <div className="flex items-center justify-between pointer-events-none">
                                                <div className="flex items-center gap-3 text-left">
                                                    <div className="p-2.5 rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                    </div>
                                                    <div>
                                                        <p className="text-xs font-bold text-slate-800 dark:text-slate-100 truncate max-w-xs sm:max-w-sm">
                                                            {projectForm.data.folder_files[0].name}
                                                        </p>
                                                        <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                                            {(projectForm.data.folder_files[0].size / (1024 * 1024)).toFixed(2)} MB · Archivo listo para desplegar
                                                        </p>
                                                    </div>
                                                </div>
                                                <span className="text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                                    Cambiar
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="space-y-1.5 pointer-events-none">
                                                <div className="mx-auto w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-900 flex items-center justify-center text-slate-500 dark:text-slate-400">
                                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                                    </svg>
                                                </div>
                                                <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                                                    Haz clic o arrastra tu archivo .zip aquí
                                                </p>
                                                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                                    Formato .ZIP (máximo 50 MB)
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                    {projectForm.errors.folder_files && (
                                        <p className="text-xs text-red-500 dark:text-red-400 mt-1">{projectForm.errors.folder_files}</p>
                                    )}
                                </div>
                            )}

                            {/* ======================================================= */}
                            {/* BLOQUE 2: IDENTIFICACION DEL PROYECTO                   */}
                            {/* ======================================================= */}
                            <div className="space-y-3 pt-1">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                                        <span>Categoría del Proyecto</span>
                                    </label>
                                    <select
                                        value={projectForm.data.category || ''}
                                        onChange={e => projectForm.setData('category', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs sm:text-sm font-medium cursor-pointer"
                                    >
                                        <option value="">-- Selecciona una categoría de software --</option>
                                        {PROJECT_CATEGORIES.map(cat => (
                                            <option key={cat} value={cat} className="bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200">
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
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5">
                                        Nombre del Proyecto
                                    </label>
                                    <input
                                        type="text"
                                        value={projectForm.data.name}
                                        onChange={e => handleProjectNameChange(e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-800 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 placeholder-slate-400 dark:placeholder-slate-600 text-sm"
                                        placeholder="Ej: Mi Portal Académico"
                                    />
                                    {projectForm.errors.name && <p className="text-xs text-red-500 dark:text-red-400 mt-1">{projectForm.errors.name}</p>}
                                </div>

                                {/* Subdominio (Siempre visible y no editable) */}
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1.5 flex items-center justify-between">
                                        <span>Subdominio</span>
                                    </label>
                                    <div className={`flex rounded-xl overflow-hidden border ${projectForm.errors.subdomain ? 'border-red-500/60' : 'border-slate-300 dark:border-slate-800'} bg-slate-100 dark:bg-slate-950/70`}>
                                        <input
                                            type="text"
                                            value={projectForm.data.subdomain}
                                            readOnly
                                            tabIndex={-1}
                                            className="subdomain-clean-input flex-1 px-3.5 py-2.5 bg-transparent border-0 text-slate-700 dark:text-slate-300 focus:ring-0 text-sm font-mono cursor-not-allowed select-none"
                                            placeholder="nombre_del_proyecto"
                                        />
                                        <span className="px-3.5 py-2.5 bg-slate-200/70 dark:bg-slate-900 text-slate-600 dark:text-slate-400 text-xs sm:text-sm flex items-center font-mono select-none border-l border-slate-300/80 dark:border-slate-800">
                                            .uleamcode.software
                                        </span>
                                    </div>

                                    {projectForm.errors.subdomain && (
                                        <p className="text-xs text-red-600 dark:text-red-400 mt-1 font-medium">
                                            {projectForm.errors.subdomain}
                                        </p>
                                    )}
                                </div>

                            </div>

                            {/* ======================================================= */}
                            {/* BLOQUE 3: OPCION DE FULLSTACK (FRONTEND + BACKEND)       */}
                            {/* ======================================================= */}
                            {!attachParentProjectId && (
                                <div className="pt-1">
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => handleToggleFullstack(projectForm.data.deployment_mode !== 'fullstack')}
                                        className={`flex items-start gap-3 p-3.5 rounded-xl border transition cursor-pointer select-none ${projectForm.data.deployment_mode === 'fullstack'
                                            ? 'border-indigo-500 bg-indigo-50/40 dark:bg-indigo-950/20'
                                            : 'border-slate-200 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-950/50 hover:bg-slate-100/70 dark:hover:bg-slate-950'
                                            }`}
                                    >
                                        <div
                                            className={`w-5 h-5 mt-0.5 rounded-md flex items-center justify-center shrink-0 transition-colors border ${projectForm.data.deployment_mode === 'fullstack'
                                                ? 'bg-indigo-600 border-indigo-600'
                                                : 'bg-white dark:bg-slate-900 border-slate-300 dark:border-slate-700'
                                                }`}
                                        >
                                            {projectForm.data.deployment_mode === 'fullstack' && (
                                                <svg className="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                            )}
                                        </div>
                                        <div className="flex-1 text-xs">
                                            <span className="font-semibold text-slate-800 dark:text-slate-200 block text-xs sm:text-sm">
                                                Desplegar en 2 servicios separados (Web + API)
                                            </span>
                                            <span className="text-slate-500 dark:text-slate-400 text-[11px] block mt-0.5">
                                                Actívalo solo si tu proyecto está dividido en interfaz web y servidor API independiente.
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* Opciones simplificadas cuando el checkbox Fullstack está activo */}
                            {projectForm.data.deployment_mode === 'fullstack' && !attachParentProjectId && (
                                <div className="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-950/70 border border-slate-200 dark:border-slate-800 space-y-3">
                                    {/* Subcarpetas solo si es Monorepo o archivo ZIP */}
                                    {(uploadType === 'folder' || projectForm.data.fullstack_source === 'monorepo') && (
                                        <>
                                            <p className="text-xs text-slate-600 dark:text-slate-400">
                                                Carpetas donde se encuentran tu interfaz y tu servidor en este repositorio:
                                            </p>

                                            <div className="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">
                                                        Carpeta Frontend
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.frontend_dir}
                                                        onChange={e => projectForm.setData('frontend_dir', e.target.value)}
                                                        className="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                                        placeholder="frontend"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">
                                                        Carpeta Backend
                                                    </label>
                                                    <input
                                                        type="text"
                                                        value={projectForm.data.backend_dir}
                                                        onChange={e => projectForm.setData('backend_dir', e.target.value)}
                                                        className="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                                        placeholder="backend"
                                                    />
                                                </div>
                                            </div>
                                        </>
                                    )}

                                    {uploadType === 'github' && projectForm.data.fullstack_source === 'separate' && (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-1">
                                                URL Repositorio Backend (GitHub)
                                            </label>
                                            <input
                                                type="url"
                                                value={projectForm.data.backend_github_repo_url}
                                                onChange={e => projectForm.setData('backend_github_repo_url', e.target.value)}
                                                required
                                                className="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                                placeholder="https://github.com/usuario/backend-repo"
                                            />
                                        </div>
                                    )}

                                    {uploadType === 'github' && (
                                        <div className="text-right">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const nextSource = projectForm.data.fullstack_source === 'monorepo' ? 'separate' : 'monorepo';
                                                    projectForm.setData({
                                                        ...projectForm.data,
                                                        fullstack_source: nextSource,
                                                        frontend_dir: nextSource === 'separate' ? '' : (projectForm.data.frontend_dir || 'frontend'),
                                                        backend_dir: nextSource === 'separate' ? '' : (projectForm.data.backend_dir || 'backend')
                                                    });
                                                }}
                                                className="text-[11px] text-indigo-600 dark:text-indigo-400 hover:underline border-0 bg-transparent cursor-pointer font-medium"
                                            >
                                                {projectForm.data.fullstack_source === 'monorepo'
                                                    ? '¿Tienes el Backend en otro repositorio de GitHub?'
                                                    : 'Usar un solo repositorio para ambos'}
                                            </button>
                                        </div>
                                    )}

                                    <div className="text-[11px] text-slate-600 dark:text-slate-400 bg-white dark:bg-slate-900/60 p-2.5 rounded-lg border border-slate-200 dark:border-slate-800 space-y-0.5">
                                        <div>Frontend: <span className="font-mono font-semibold text-indigo-600 dark:text-indigo-400">{projectForm.data.subdomain}.uleamcode.software</span></div>
                                        <div>Backend API: <span className="font-mono font-semibold text-indigo-600 dark:text-indigo-400">{projectForm.data.backend_subdomain || (projectForm.data.subdomain + '_backend')}.uleamcode.software/api</span></div>
                                    </div>
                                </div>
                            )}

                            {/* ======================================================= */}
                            {/* BLOQUE 4: VARIABLES DE ENTORNO (.env)                   */}
                            {/* ======================================================= */}
                            <div className="pt-2 border-t border-slate-200 dark:border-slate-800/80">
                                <button
                                    type="button"
                                    onClick={() => setIsEnvSectionOpen(!isEnvSectionOpen)}
                                    className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-50 hover:bg-slate-100/80 dark:bg-slate-950/60 dark:hover:bg-slate-950 border border-slate-200 dark:border-slate-800/80 transition text-left group"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <span className="p-1.5 rounded-lg bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400 group-hover:bg-indigo-100 dark:group-hover:bg-indigo-500/20 transition">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                            </svg>
                                        </span>
                                        <div>
                                            <div className="text-xs font-bold text-slate-800 dark:text-slate-200 flex items-center gap-2">
                                                Variables de Entorno (.env)
                                                {activeEnvCount > 0 && (
                                                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300 border border-indigo-200 dark:border-transparent">
                                                        {activeEnvCount} {activeEnvCount === 1 ? 'variable' : 'variables'}
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-[11px] text-slate-500 dark:text-slate-400">
                                                Opcional · API Keys, tokens y variables de entorno personalizadas
                                            </div>
                                        </div>
                                    </div>
                                    <span className={`text-slate-400 text-xs transition-transform duration-200 ${isEnvSectionOpen ? 'rotate-180' : ''}`}>
                                        ▼
                                    </span>
                                </button>

                                {isEnvSectionOpen && (
                                    <div className="mt-2.5 p-3.5 bg-slate-50/90 dark:bg-slate-950/90 rounded-xl border border-slate-200 dark:border-slate-800/90 space-y-3 env-section-box">
                                        <div className="flex items-center justify-between pb-2 border-b border-slate-200 dark:border-slate-800/60">
                                            <span className="text-[11px] font-semibold text-slate-600 dark:text-slate-400">
                                                Formato de edición:
                                            </span>
                                            <div className="flex gap-1 bg-slate-200/70 dark:bg-slate-900 p-0.5 rounded-lg border border-slate-300/60 dark:border-slate-800">
                                                <button
                                                    type="button"
                                                    onClick={() => setEnvEditorMode('visual')}
                                                    className={`px-2.5 py-1 rounded-md text-[11px] font-semibold transition ${envEditorMode === 'visual'
                                                        ? 'bg-indigo-600 text-white shadow-xs'
                                                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200'
                                                        }`}
                                                >
                                                    Modo Visual (Clave - Valor)
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setEnvEditorMode('raw')}
                                                    className={`px-2.5 py-1 rounded-md text-[11px] font-semibold transition ${envEditorMode === 'raw'
                                                        ? 'bg-indigo-600 text-white shadow-xs'
                                                        : 'text-slate-600 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200'
                                                        }`}
                                                >
                                                    Texto Plano (.env)
                                                </button>
                                            </div>
                                        </div>

                                        {envEditorMode === 'visual' ? (
                                            <div className="space-y-2">
                                                {envPairs.map((pair, idx) => (
                                                    <div key={idx} className="flex items-center gap-2">
                                                        <input
                                                            type="text"
                                                            value={pair.key}
                                                            onChange={e => handleEnvPairChange(idx, 'key', e.target.value)}
                                                            placeholder="VARIABLE_KEY"
                                                            className="w-5/12 px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-lg text-slate-800 dark:text-slate-200 text-xs font-mono uppercase focus:ring-1 focus:ring-indigo-500 placeholder-slate-400 dark:placeholder-slate-600"
                                                        />
                                                        <span className="text-slate-400 dark:text-slate-500 font-mono text-xs font-bold">=</span>
                                                        <input
                                                            type="text"
                                                            value={pair.value}
                                                            onChange={e => handleEnvPairChange(idx, 'value', e.target.value)}
                                                            placeholder="valor_o_token"
                                                            className="flex-1 px-2.5 py-1.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-lg text-slate-800 dark:text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500 placeholder-slate-400 dark:placeholder-slate-600"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveEnvPair(idx)}
                                                            title="Eliminar variable"
                                                            className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:text-slate-500 dark:hover:text-rose-400 dark:hover:bg-rose-500/10 rounded-lg transition"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                ))}

                                                <div className="pt-1 flex items-center justify-between">
                                                    <button
                                                        type="button"
                                                        onClick={handleAddEnvPair}
                                                        className="px-3 py-1.5 rounded-lg bg-white hover:bg-slate-100 dark:bg-slate-900 dark:hover:bg-slate-800 text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-semibold border border-slate-300 dark:border-slate-800 flex items-center gap-1.5 transition shadow-2xs"
                                                    >
                                                        <span>+</span> Añadir Variable
                                                    </button>
                                                    {envPairs.length > 1 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setEnvPairs([{ key: '', value: '' }]);
                                                                projectForm.setData('env_vars', '');
                                                            }}
                                                            className="text-[11px] font-medium text-slate-500 hover:text-rose-600 dark:hover:text-rose-400 transition"
                                                        >
                                                            Limpiar todo
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                <textarea
                                                    rows={5}
                                                    value={projectForm.data.env_vars}
                                                    onChange={e => handleRawEnvChange(e.target.value)}
                                                    placeholder={`KEY=VALOR\nAPI_KEY=mi_super_secreto\nNEXT_PUBLIC_APP_TITLE=Mi App`}
                                                    className="w-full px-3 py-2 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-xl text-slate-800 dark:text-slate-200 text-xs font-mono focus:ring-1 focus:ring-indigo-500 placeholder-slate-400 dark:placeholder-slate-600 resize-y"
                                                />
                                                <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                                    Una variable por línea en formato <code>CLAVE=VALOR</code>. Las líneas vacías serán omitidas.
                                                </p>
                                            </div>
                                        )}

                                        <div className="flex items-start gap-2.5 p-2.5 rounded-lg bg-indigo-50/80 dark:bg-indigo-950/20 border border-indigo-200/60 dark:border-indigo-900/40 text-[11px] text-indigo-900 dark:text-indigo-300/80 leading-relaxed">
                                            <svg className="w-3.5 h-3.5 text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <div className="env-system-note">
                                                <strong className="text-indigo-950 dark:text-indigo-200 font-bold">Nota del Sistema:</strong> Las credenciales de base de datos (<code>DB_HOST</code>, <code>DB_PASSWORD</code>, etc.) se autogeneran e inyectan automáticamente. Agrega aquí tus API keys o configuraciones particulares.
                                            </div>
                                        </div>
                                    </div>
                                )}
                                </div>
                            </div>

                            <div className="pt-3 border-t border-slate-100 dark:border-slate-800/80 shrink-0">
                                <button
                                    type="submit"
                                    disabled={projectForm.processing || (uploadType === 'github' && githubCheckStatus === 'invalid')}
                                    className="w-full py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold transition cursor-pointer text-sm shadow-md border-0"
                                >
                                    {projectForm.processing
                                        ? 'Preparando Despliegue...'
                                        : projectForm.data.deployment_mode === 'fullstack'
                                            ? 'Desplegar Proyecto (Web + API)'
                                            : 'Iniciar Despliegue'}
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Tarjeta Flotante Independiente: Guía de Despliegue (a la derecha de la pantalla, arriba del botón) */}
                    {isDeployHelpOpen && (
                        <div className="fixed bottom-20 right-6 z-[60] w-80 md:w-96 max-h-[75vh] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-5 shadow-2xl overflow-y-auto flex flex-col justify-between animate-fadeIn">
                            <div>
                                <div className="flex justify-between items-center pb-3 mb-3 border-b border-slate-200 dark:border-slate-800">
                                    <div>
                                        <h4 className="text-sm font-bold text-slate-800 dark:text-slate-100 uppercase tracking-wider">
                                            Guía de Despliegue
                                        </h4>
                                        <p className="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                            Selecciona tu tipo de proyecto para autoconfigurar el formulario:
                                        </p>
                                    </div>
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
                                    {/* Opción 1: Una sola aplicación desde GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 1) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(1);
                                                const slug = formatToSlug(projectForm.data.name);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'simple',
                                                    fullstack_source: 'monorepo',
                                                    subdomain: slug,
                                                    backend_subdomain: '',
                                                    frontend_dir: '',
                                                    backend_dir: '',
                                                    backend_github_repo_url: ''
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
                                            <h5 className="guide-card-title text-xs">1. Una sola aplicación (GitHub)</h5>
                                            {selectedGuideOption === 1 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Tu proyecto completo está en un solo repositorio (por ejemplo: PHP, Laravel tradicional, Python, React o HTML/JS).
                                        </p>
                                    </button>

                                    {/* Opción 2: Una sola aplicación en archivo ZIP */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 2) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(2);
                                                const slug = formatToSlug(projectForm.data.name);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'simple',
                                                    fullstack_source: 'monorepo',
                                                    subdomain: slug,
                                                    backend_subdomain: '',
                                                    frontend_dir: '',
                                                    backend_dir: '',
                                                    backend_github_repo_url: ''
                                                });
                                                setUploadType('folder');
                                                setLocalUploadMode('zip');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 2
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">2. Una sola aplicación (Archivo ZIP)</h5>
                                            {selectedGuideOption === 2 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Sube tu proyecto comprimido en un archivo <code>.zip</code> (hasta 50 MB) directamente desde tu computadora.
                                        </p>
                                    </button>

                                    {/* Opción 3: Web y API en el mismo repositorio GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 3) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(3);
                                                const slug = formatToSlug(projectForm.data.name);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    fullstack_source: 'monorepo',
                                                    subdomain: slug ? `${slug}_frontend` : '',
                                                    backend_subdomain: slug ? `${slug}_backend` : '',
                                                    frontend_dir: 'frontend',
                                                    backend_dir: 'backend',
                                                    backend_github_repo_url: ''
                                                });
                                                setUploadType('github');
                                            }
                                        }}
                                        className={`deploy-guide-card ${selectedGuideOption === 3
                                            ? 'deploy-guide-card-active'
                                            : 'deploy-guide-card-inactive'
                                            }`}
                                    >
                                        <div className="flex justify-between items-center mb-1">
                                            <h5 className="guide-card-title text-xs">3. Web + API en el mismo repositorio (GitHub)</h5>
                                            {selectedGuideOption === 3 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Tienes la interfaz web en la carpeta <code>frontend/</code> y el servidor en la carpeta <code>backend/</code> dentro del mismo link de GitHub.
                                        </p>
                                    </button>

                                    {/* Opción 4: Web y API en 2 repositorios diferentes GitHub */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 4) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(4);
                                                const slug = formatToSlug(projectForm.data.name);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    fullstack_source: 'separate',
                                                    subdomain: slug ? `${slug}_frontend` : '',
                                                    backend_subdomain: slug ? `${slug}_backend` : '',
                                                    frontend_dir: '',
                                                    backend_dir: ''
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
                                            <h5 className="guide-card-title text-xs">4. Web + API en dos repositorios (GitHub)</h5>
                                            {selectedGuideOption === 4 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Tienes dos repositorios distintos en GitHub: uno para la página web y otro para el servidor API backend.
                                        </p>
                                    </button>

                                    {/* Opción 5: Web y API en un solo archivo ZIP */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            if (selectedGuideOption === 5) {
                                                setSelectedGuideOption(null);
                                            } else {
                                                setSelectedGuideOption(5);
                                                const slug = formatToSlug(projectForm.data.name);
                                                projectForm.setData({
                                                    ...projectForm.data,
                                                    deployment_mode: 'fullstack',
                                                    fullstack_source: 'monorepo',
                                                    subdomain: slug ? `${slug}_frontend` : '',
                                                    backend_subdomain: slug ? `${slug}_backend` : '',
                                                    frontend_dir: 'frontend',
                                                    backend_dir: 'backend',
                                                    backend_github_repo_url: ''
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
                                            <h5 className="guide-card-title text-xs">5. Web + API en un solo archivo ZIP</h5>
                                            {selectedGuideOption === 5 && (
                                                <span className="guide-card-badge text-[10px] font-bold px-2 py-0.5 rounded-full">Activo</span>
                                            )}
                                        </div>
                                        <p className="guide-card-desc text-[11px] leading-relaxed">
                                            Tu archivo <code>.zip</code> contiene adentro las subcarpetas <code>frontend/</code> y <code>backend/</code> para desplegar ambos servicios juntos.
                                        </p>
                                    </button>
                                </div>
                            </div>

                            <div className="pt-4 mt-4 text-[11px] text-slate-400 dark:text-slate-500 text-center border-t border-slate-100 dark:border-slate-800">
                                ULEAM Academic Code
                            </div>
                        </div>
                    )}

                    {/* Botón Flotante en la esquina inferior derecha de la pantalla */}
                    <div className="fixed bottom-6 right-6 z-[60]">
                        <button
                            type="button"
                            onClick={() => setIsDeployHelpOpen(!isDeployHelpOpen)}
                            className="px-4 py-2.5 rounded-xl bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-700 dark:text-slate-200 shadow-xl border border-slate-200 dark:border-slate-800 text-xs font-semibold flex items-center gap-2 transition cursor-pointer"
                        >
                            <span>{isDeployHelpOpen ? 'Ocultar Guía' : 'Guía de Despliegue'}</span>
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
                                            <option key={p.id} value={p.id}>{p.name} ({p.subdomain}.uleamcode.software)</option>
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
    if (text.includes('proyecto incompatible con la plataforma') || text.includes('bundle install') && text.includes('compilación fallida')) {
        return {
            title: "Aviso de Compatibilidad: Librería de Ruby No Soportada",
            desc: "Este proyecto de Ruby requiere librerías nativas del sistema operativo (extensiones en C/C++) que no son compatibles con el entorno estándar de la plataforma. Para desplegar en esta plataforma, elimina del Gemfile las gemas que requieran compilación nativa o usa alternativas estándar en Ruby puro."
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
