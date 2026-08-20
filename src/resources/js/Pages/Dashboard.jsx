import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';

export default function Dashboard({ auth, profile, projects }) {
    const [activeLogsProject, setActiveLogsProject] = useState(null);
    const [logs, setLogs] = useState({ build_log: '', container_log: '', status: '' });
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [isAddProjectOpen, setIsAddProjectOpen] = useState(false);
    const [uploadType, setUploadType] = useState('github');
    const [localUploadMode, setLocalUploadMode] = useState('zip');
    const [projectToDelete, setProjectToDelete] = useState(null);
    const [instructionsProject, setInstructionsProject] = useState(null);
    const [deletedProjectIds, setDeletedProjectIds] = useState([]);

    // Proyectos visibles filtrando optimistamente los que están en proceso de eliminación
    const visibleProjects = projects.filter(p => !deletedProjectIds.includes(p.id));

    // Polling en segundo plano para mantener los estados de los proyectos sincronizados en tiempo real (cada 5 segundos)
    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['projects'],
                preserveState: true,
                preserveScroll: true
            });
        }, 5000);
        return () => clearInterval(interval);
    }, []);


    // Form for adding new project
    const projectForm = useForm({
        name: '',
        subdomain: '',
        github_repo_url: '',
        branch: 'main',
        folder_files: null,
        folder_paths: null,
        root_dir: '',
        env_vars: ''
    });

    // Auto-linking: subdominio del backend seleccionado para inyectar URLs en env_vars
    const [linkedBackend, setLinkedBackend] = useState('');

    // Proyectos del estudiante que son backends (PHP, Python, Java, .NET)
    const backendProjects = visibleProjects.filter(p =>
        ['php', 'python', 'java', 'dotnet'].includes(p.language) && p.status === 'running'
    );

    // Cuando el usuario selecciona un backend, inyecta todas las variantes de URL conocidas
    const handleLinkBackend = (subdomain) => {
        setLinkedBackend(subdomain);
        if (!subdomain) {
            projectForm.setData('env_vars', '');
            return;
        }
        const apiUrl = `http://${subdomain}.uleam-academic.software/api`;
        const vars = [
            `VITE_API_URL=${apiUrl}`,
            `REACT_APP_API_URL=${apiUrl}`,
            `NEXT_PUBLIC_API_URL=${apiUrl}`,
            `API_URL=${apiUrl}`,
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

    const handleZipChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            projectForm.setData({
                ...projectForm.data,
                folder_files: [file],
                folder_paths: [file.name]
            });
        }
    };


    // Actualizar el estado de los proyectos automáticamente si alguno se está compilando (building)
    useEffect(() => {
        const algunProyectoCompilando = projects.some(p => p.status === 'building');

        if (algunProyectoCompilando) {
            const interval = setInterval(() => {
                router.reload({
                    only: ['projects'],
                    preserveState: true,
                    preserveScroll: true
                });
            }, 3000); // Consultar cada 3 segundos

            return () => clearInterval(interval);
        }
    }, [projects]);

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
        projectForm.post(route('projects.store'), {
            onSuccess: () => {
                projectForm.reset();
                setIsAddProjectOpen(false);
            }
        });
    };


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

    // Start container
    const startContainer = (projectId) => {
        axios.post(route('projects.start', projectId))
            .then(() => {
                router.reload();
            })
            .catch(err => alert("Error al iniciar el contenedor. Revisa los logs."));
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
                                <span>Guía rápida para la entrega de tus proyectos</span>
                            </h4>
                            <div className="text-xs text-slate-400 leading-relaxed space-y-1">
                                <p>• <strong>Selecciona tus 3 mejores proyectos:</strong> Puedes subir hasta un máximo de <strong>3 proyectos</strong> en total. ¡Elige tus mejores trabajos para mostrarlos en la vitrina pública!</p>
                                <p>• <strong>Tecnologías soportadas:</strong> Puedes subir aplicaciones construidas con Node.js, PHP (Laravel) o Python.</p>
                                <p>• <strong>Bases de datos automáticas:</strong> El sistema crea y conecta tus bases de datos (SQL o MongoDB) de forma automática sin configuraciones.</p>
                                <p>• <strong>Tamaño de subida:</strong> Asegúrate de que el código comprimido en archivo `.zip` o tu carpeta local no pese más de 50MB.</p>
                            </div>
                        </div>
                        <div className="flex-shrink-0 bg-slate-900 border border-slate-800 rounded-xl p-3 text-center">
                            <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wider block">Cupo de Proyectos</span>
                            <span className="text-2xl font-black text-cyan-400 block mt-1">{projects.length} / 3</span>
                        </div>
                    </div>

                    {/* Projects Section */}
                    <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur">
                        <div className="flex items-center justify-between mb-6">
                            <div>
                                <h3 className="text-xl font-bold text-white">Mis Proyectos ({projects.length}/3)</h3>
                                <p className="text-sm text-slate-400">Administra tus despliegues contenedores y visualiza logs de ejecución.</p>
                            </div>
                            {projects.length < 3 && (
                                <button
                                    onClick={() => setIsAddProjectOpen(true)}
                                    className="px-4 py-2 rounded-xl text-sm font-bold bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 shadow-md shadow-cyan-500/10 transition duration-200"
                                >
                                    + Desplegar Proyecto
                                </button>
                            )}
                        </div>

                        {visibleProjects.length === 0 ? (
                            <div className="text-center py-12 border border-dashed border-slate-800 rounded-xl bg-slate-900/10">
                                <p className="text-slate-400">Aún no has registrado ningún proyecto.</p>
                                <p className="text-xs text-slate-500 mt-1">Sube tu primer proyecto desde GitHub o una carpeta local para mostrarlo en tu vitrina.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                {visibleProjects.map((project) => (
                                    <div
                                        key={project.id}
                                        className="rounded-xl border border-slate-900 bg-slate-950 p-5 flex flex-col justify-between shadow-md"
                                    >
                                        <div>
                                            <div className="flex justify-between items-start mb-3">
                                                <h4 className="text-lg font-bold text-slate-200">{project.name}</h4>
                                                <span className={`px-2 py-0.5 rounded text-xs font-semibold uppercase tracking-wider ${project.status === 'running' ? 'bg-cyan-500/15 text-cyan-400' :
                                                    project.status === 'sleeping' ? 'bg-indigo-500/15 text-indigo-400' :
                                                        project.status === 'building' ? 'bg-yellow-500/15 text-yellow-400 animate-pulse' :
                                                            'bg-slate-800 text-slate-400'
                                                    }`}>
                                                    {project.status === 'running' ? 'Activo' :
                                                        project.status === 'sleeping' ? 'Suspendido' :
                                                            project.status === 'building' ? 'Compilando' :
                                                                'Apagado'}
                                                </span>
                                            </div>

                                            <p className="text-xs text-slate-500 font-mono break-all mb-2">
                                                Subdominio: {project.subdomain}.uleam-academic.software
                                            </p>
                                            <p className="text-xs text-slate-500 break-all mb-4">
                                                Git: {project.github_repo_url} (Rama: {project.branch})
                                            </p>
                                        </div>

                                        {/* Project Actions */}
                                        <div className="border-t border-slate-900 pt-4 flex flex-wrap gap-2 items-center justify-between">
                                            <div className="flex gap-2">
                                                {project.status === 'stopped' || project.status === 'sleeping' ? (
                                                    <button
                                                        onClick={() => startContainer(project.id)}
                                                        className="px-3 py-1.5 rounded-lg text-xs font-bold bg-green-600 hover:bg-green-500 text-white transition"
                                                    >
                                                        Encender
                                                    </button>
                                                ) : project.status === 'running' ? (
                                                    <button
                                                        onClick={() => stopContainer(project.id)}
                                                        className="px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-300 transition"
                                                    >
                                                        Apagar
                                                    </button>
                                                ) : null}

                                                <button
                                                    onClick={() => rebuildProject(project.id)}
                                                    className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-900 hover:bg-slate-850 border border-slate-800 text-slate-300 transition"
                                                    disabled={project.status === 'building'}
                                                >
                                                    Actualizar (Build)
                                                </button>

                                                <button
                                                    onClick={() => fetchLogs(project)}
                                                    className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-900 hover:bg-slate-850 border border-slate-800 text-slate-400 hover:text-white transition"
                                                >
                                                    Ver Logs
                                                </button>

                                                <button
                                                    onClick={() => openInstructionsModal(project)}
                                                    className="px-3 py-1.5 rounded-lg text-xs font-semibold bg-indigo-950/20 hover:bg-indigo-950/45 border border-indigo-900/30 text-indigo-400 hover:text-indigo-300 transition"
                                                >
                                                    Instrucciones
                                                </button>
                                            </div>

                                            <button
                                                onClick={() => deleteProject(project.id)}
                                                className="p-2 rounded-lg bg-red-950/20 hover:bg-red-950/50 border border-red-900/30 text-red-400 hover:text-red-300 transition text-xs"
                                                title="Eliminar Proyecto"
                                            >
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Modal para añadir un proyecto */}
            {isAddProjectOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-lg bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl max-h-[90vh] overflow-y-auto">
                        <div className="flex justify-between items-start mb-6">
                            <div>
                                <h3 className="text-xl font-bold text-white">Desplegar Nuevo Proyecto</h3>
                                <p className="text-sm text-slate-400 mt-1">Conecta tu código público de GitHub o sube una carpeta local para publicarlo al instante.</p>
                            </div>
                            <button
                                onClick={() => setIsAddProjectOpen(false)}
                                className="p-2 hover:bg-slate-800 rounded-lg text-slate-400 hover:text-white transition font-bold"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Pestañas de Selección de tipo de subida */}
                        <div className="flex gap-2 p-1 bg-slate-950 rounded-xl mb-6">
                            <button
                                type="button"
                                onClick={() => setUploadType('github')}
                                className={`flex-1 py-2 rounded-lg text-xs font-bold transition ${uploadType === 'github'
                                    ? 'bg-slate-800 text-white'
                                    : 'text-slate-400 hover:text-slate-200'
                                    }`}
                            >
                                GitHub
                            </button>
                            <button
                                type="button"
                                onClick={() => setUploadType('folder')}
                                className={`flex-1 py-2 rounded-lg text-xs font-bold transition ${uploadType === 'folder'
                                    ? 'bg-slate-800 text-white'
                                    : 'text-slate-400 hover:text-slate-200'
                                    }`}
                            >
                                Carpeta Local
                            </button>
                        </div>

                        <form onSubmit={handleProjectSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Nombre del Proyecto</label>
                                <input
                                    type="text"
                                    value={projectForm.data.name}
                                    onChange={e => projectForm.setData('name', e.target.value)}
                                    required
                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                    placeholder="Mi Portal Académico"
                                />
                                {projectForm.errors.name && <p className="text-xs text-red-400 mt-1">{projectForm.errors.name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Subdominio</label>
                                <div className="flex">
                                    <input
                                        type="text"
                                        value={projectForm.data.subdomain}
                                        onChange={e => projectForm.setData('subdomain', e.target.value)}
                                        required
                                        className="flex-1 px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-l-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm border-r-0"
                                        placeholder="mi-proyecto"
                                    />
                                    <span className="px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-r-xl text-slate-500 text-sm flex items-center font-mono">
                                        .uleam.edu.ec
                                    </span>
                                </div>
                                {projectForm.errors.subdomain && <p className="text-xs text-red-400 mt-1">{projectForm.errors.subdomain}</p>}
                            </div>

                            {uploadType === 'github' ? (
                                <>
                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">URL del Repositorio Público (GitHub)</label>
                                        <input
                                            type="url"
                                            value={projectForm.data.github_repo_url}
                                            onChange={e => projectForm.setData('github_repo_url', e.target.value)}
                                            required
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                            placeholder="https://github.com/usuario/nombre-repositorio"
                                        />
                                        {projectForm.errors.github_repo_url && <p className="text-xs text-red-400 mt-1">{projectForm.errors.github_repo_url}</p>}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Rama para Deploy</label>
                                        <input
                                            type="text"
                                            value={projectForm.data.branch}
                                            onChange={e => projectForm.setData('branch', e.target.value)}
                                            required
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                            placeholder="main"
                                        />
                                        {projectForm.errors.branch && <p className="text-xs text-red-400 mt-1">{projectForm.errors.branch}</p>}
                                    </div>
                                </>
                            ) : (
                                <div className="space-y-4">
                                    <div className="flex bg-slate-950 p-1.5 rounded-xl border border-slate-850">
                                        <button
                                            type="button"
                                            onClick={() => { setLocalUploadMode('zip'); projectForm.setData({ ...projectForm.data, folder_files: null, folder_paths: null }); }}
                                            className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition ${localUploadMode === 'zip' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30' : 'text-slate-400 hover:text-white'
                                                }`}
                                        >
                                            Archivo ZIP (Recomendado)
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => { setLocalUploadMode('folder'); projectForm.setData({ ...projectForm.data, folder_files: null, folder_paths: null }); }}
                                            className={`flex-1 py-1.5 text-center text-xs font-bold rounded-lg transition ${localUploadMode === 'folder' ? 'bg-cyan-500/20 text-cyan-400 border border-cyan-500/30' : 'text-slate-400 hover:text-white'
                                                }`}
                                        >
                                            Carpeta Local
                                        </button>
                                    </div>

                                    {localUploadMode === 'zip' ? (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Selecciona archivo ZIP del Proyecto</label>
                                            <div className="border-2 border-dashed border-slate-800 hover:border-cyan-500/50 rounded-xl p-6 text-center cursor-pointer transition relative">
                                                <input
                                                    type="file"
                                                    accept=".zip"
                                                    onChange={handleZipChange}
                                                    required
                                                    className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                />
                                                <svg className="mx-auto h-8 w-8 text-slate-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                                <p className="text-sm font-semibold text-slate-300">
                                                    {projectForm.data.folder_files ? projectForm.data.folder_files[0].name : 'Selecciona tu archivo .zip'}
                                                </p>
                                                <p className="text-xs text-slate-500 mt-1">Sube el zip para evitar advertencias de seguridad del navegador</p>
                                            </div>
                                        </div>
                                    ) : (
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Selecciona la Carpeta del Proyecto</label>
                                            <div className="border-2 border-dashed border-slate-800 hover:border-cyan-500/50 rounded-xl p-6 text-center cursor-pointer transition relative">
                                                <input
                                                    type="file"
                                                    webkitdirectory="true"
                                                    directory="true"
                                                    multiple
                                                    onChange={handleFolderChange}
                                                    required
                                                    className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                                />
                                                <svg className="mx-auto h-8 w-8 text-slate-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 13h6m-3-3v6m-9 1V4a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path>
                                                </svg>
                                                <p className="text-sm font-semibold text-slate-300">
                                                    {projectForm.data.folder_files ? `${projectForm.data.folder_files.length} archivos seleccionados` : 'Selecciona una carpeta local'}
                                                </p>
                                                <p className="text-xs text-slate-500 mt-1">Sube la carpeta del código (puede mostrar advertencia del navegador)</p>
                                            </div>
                                        </div>
                                    )}
                                    {projectForm.errors.folder_files && <p className="text-xs text-red-400 mt-1">{projectForm.errors.folder_files}</p>}
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Subdirectorio del Proyecto (Opcional)</label>
                                <input
                                    type="text"
                                    value={projectForm.data.root_dir}
                                    onChange={e => projectForm.setData('root_dir', e.target.value)}
                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-sm"
                                    placeholder="Ejemplo: client o server (dejar vacío para raíz)"
                                />
                                <p className="text-[10px] text-slate-500 mt-1">Si tu repositorio contiene frontend y backend en carpetas separadas, especifica cuál deseas desplegar en este proyecto.</p>
                                {projectForm.errors.root_dir && <p className="text-xs text-red-400 mt-1">{projectForm.errors.root_dir}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Variables de Entorno (Opcional)</label>

                                {/* Auto-linking Frontend ↔ Backend */}
                                {backendProjects.length > 0 && (
                                    <div className="mb-3">
                                        <label className="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5">
                                            Vincular con proyecto Backend
                                        </label>
                                        <select
                                            value={linkedBackend}
                                            onChange={e => handleLinkBackend(e.target.value)}
                                            className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 text-sm"
                                        >
                                            <option value="">-- Ninguno (ingresar manualmente) --</option>
                                            {backendProjects.map(p => (
                                                <option key={p.id} value={p.subdomain}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="text-[10px] text-slate-500 mt-1">Selecciona un backend para que las URLs de la API se configuren automáticamente.</p>
                                    </div>
                                )}

                                <textarea
                                    value={projectForm.data.env_vars}
                                    onChange={e => projectForm.setData('env_vars', e.target.value)}
                                    rows="4"
                                    className="w-full px-4 py-2.5 bg-slate-950 border border-slate-850 rounded-xl text-slate-200 focus:border-cyan-500 focus:ring-0 placeholder-slate-700 text-xs font-mono"
                                    placeholder="CLAVE=VALOR&#10;OTRA_VARIABLE=VALOR"
                                />
                                <div className="mt-2 p-3 bg-slate-950/60 border border-slate-850/50 rounded-lg space-y-1.5">
                                    <p className="text-[10px] font-semibold text-slate-400">Instrucciones de uso:</p>
                                    <ul className="text-[10px] text-slate-500 list-disc pl-4 space-y-1">
                                        <li>Define una variable por cada línea en formato <code className="text-cyan-400 font-mono">CLAVE=VALOR</code>.</li>
                                        <li>Para conectar un frontend de React/Vite a una API backend, escribe:
                                            <div className="bg-slate-950 p-1.5 rounded border border-slate-850 mt-1 font-mono text-[9px] text-slate-400 select-all leading-relaxed">
                                                VITE_API_URL=http://tu-subdominio-back.uleam-academic.software<br />
                                                VITE_WS_URL=ws://tu-subdominio-back.uleam-academic.software
                                            </div>
                                        </li>
                                        <li>Para claves del servidor (ej. Node/Express), escribe:
                                            <div className="bg-slate-950 p-1.5 rounded border border-slate-850 mt-1 font-mono text-[9px] text-slate-400 select-all leading-relaxed">
                                                JWT_SECRET=tu-clave-secreta
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                                {projectForm.errors.env_vars && <p className="text-xs text-red-400 mt-1">{projectForm.errors.env_vars}</p>}
                            </div>

                            <button
                                type="submit"
                                disabled={projectForm.processing}
                                className="w-full py-3 rounded-xl bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 font-bold transition shadow-lg shadow-cyan-500/10"
                            >
                                {projectForm.processing ? 'Preparando...' : 'Iniciar Despliegue'}
                            </button>
                        </form>
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
                                    className="flex-1 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold transition text-sm"
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
                                className="px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-300 transition duration-150"
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

    const headerText = logText.substring(0, matches[0].index).trim();
    if (headerText) {
        steps.push({ title: 'Preparando Entorno', content: headerText, defaultOpen: false });
    }

    for (let i = 0; i < matches.length; i++) {
        const currentMatch = matches[i];
        const nextMatch = matches[i + 1];

        const start = currentMatch.index + currentMatch.length;
        const end = nextMatch ? nextMatch.index : logText.length;

        const content = logText.substring(start, end).trim();
        const cleanContentForErrorCheck = content
            .toLowerCase()
            .replace(/error-handler/g, '')
            .replace(/error_reporting/g, '')
            .replace(/0 errors/g, '')
            .replace(/no errors/g, '');

        const hasError = cleanContentForErrorCheck.includes('error') ||
            cleanContentForErrorCheck.includes('fallida') ||
            cleanContentForErrorCheck.includes('failed') ||
            cleanContentForErrorCheck.includes('exception') ||
            cleanContentForErrorCheck.includes('excepción') ||
            cleanContentForErrorCheck.includes('fatal');

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
                    {advice && (
                        <div className="mb-3.5 p-3 bg-violet-950/30 border border-violet-900/50 rounded-xl text-xs text-violet-300 flex items-start space-x-2.5">
                            <svg className="w-5 h-5 text-violet-400 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                            </svg>
                            <div>
                                <div className="font-bold text-violet-200 mb-0.5">{advice.title}</div>
                                <div className="leading-relaxed">{advice.desc}</div>
                            </div>
                        </div>
                    )}
                    <pre className="font-mono text-xs text-slate-300 whitespace-pre-wrap leading-relaxed">
                        {step.content || '(Sin mensajes de log)'}
                    </pre>
                </div>
            )}
        </div>
    );
}
