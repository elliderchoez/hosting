import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState, useMemo, useRef, useEffect } from 'react';

export default function MessagesIndex({ messages = [], unreadCount = 0 }) {
    const [selectedMessageId, setSelectedMessageId] = useState(messages[0]?.id || null);
    const [searchQuery, setSearchQuery] = useState('');
    const [filter, setFilter] = useState('all'); // 'all', 'unread'
    const [deleteModalOpen, setDeleteModalOpen] = useState(false);
    const [messageToDelete, setMessageToDelete] = useState(null);
    const [copiedEmail, setCopiedEmail] = useState(false);
    const [showReplyMenu, setShowReplyMenu] = useState(false);
    const replyMenuRef = useRef(null);

    // Cerrar menú al hacer clic fuera
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (replyMenuRef.current && !replyMenuRef.current.contains(event.target)) {
                setShowReplyMenu(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Mensaje seleccionado actualmente
    const selectedMessage = useMemo(() => {
        return messages.find((m) => m.id === selectedMessageId) || null;
    }, [messages, selectedMessageId]);

    // Filtrar mensajes según búsqueda y pestaña
    const filteredMessages = useMemo(() => {
        return messages.filter((msg) => {
            const matchesSearch =
                (msg.sender_name || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                (msg.sender_company || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                (msg.sender_email || '').toLowerCase().includes(searchQuery.toLowerCase()) ||
                (msg.message || '').toLowerCase().includes(searchQuery.toLowerCase());

            if (!matchesSearch) return false;

            if (filter === 'unread') return !msg.read_at;
            return true;
        });
    }, [messages, searchQuery, filter]);

    // Seleccionar mensaje y marcar como leído
    const handleSelectMessage = (msg) => {
        setSelectedMessageId(msg.id);
        setCopiedEmail(false);

        // Marcar como leído si no lo está
        if (!msg.read_at) {
            router.patch(route('messages.read', msg.id), {}, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    // Copiar correo al portapapeles
    const handleCopyEmail = (email) => {
        if (!email) return;
        navigator.clipboard.writeText(email);
        setCopiedEmail(true);
        setTimeout(() => setCopiedEmail(false), 2000);
    };

    // Confirmar eliminación
    const confirmDelete = (msg, e) => {
        e?.stopPropagation();
        setMessageToDelete(msg);
        setDeleteModalOpen(true);
    };

    const handleDelete = () => {
        if (!messageToDelete) return;
        router.delete(route('messages.destroy', messageToDelete.id), {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteModalOpen(false);
                setMessageToDelete(null);
                if (selectedMessageId === messageToDelete.id) {
                    const remaining = messages.filter((m) => m.id !== messageToDelete.id);
                    setSelectedMessageId(remaining[0]?.id || null);
                }
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-bold leading-tight text-slate-100 flex items-center space-x-2">
                            <span>Bandeja de Contactos y Propuestas</span>
                            {unreadCount > 0 && (
                                <span className="text-xs bg-gradient-to-r from-cyan-500 to-indigo-500 text-slate-950 font-extrabold px-2 py-0.5 rounded-full shadow-sm">
                                    {unreadCount} sin leer
                                </span>
                            )}
                        </h2>
                        <p className="text-xs text-slate-400 mt-1">
                            Consulta las propuestas de empleo y mensajes de contacto recibidos desde la vitrina de proyectos.
                        </p>
                    </div>
                </div>
            }
        >
            <Head title="Mensajes de Reclutadores" />

            <div className="py-6 sm:py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                {messages.length === 0 ? (
                    <div className="text-center py-20 bg-slate-900/40 backdrop-blur-md border border-slate-800 rounded-3xl p-8 shadow-xl">
                        <div className="w-16 h-16 mx-auto mb-4 rounded-2xl bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400">
                            <svg className="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                            </svg>
                        </div>
                        <h3 className="text-lg font-bold text-white mb-2">No tienes mensajes de contacto aún</h3>
                        <p className="text-sm text-slate-400 max-w-md mx-auto">
                            Cuando un reclutador o empresa visite tus proyectos en la vitrina pública y haga clic en "Contactar", sus propuestas aparecerán organizadas aquí.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                        {/* Panel Izquierdo: Lista de Mensajes */}
                        <div className="lg:col-span-5 messages-container bg-slate-900/50 backdrop-blur-md border border-slate-800/80 rounded-2xl p-4 shadow-xl flex flex-col h-[650px]">
                            {/* Barra de Búsqueda y Filtros */}
                            <div className="space-y-3 mb-4">
                                <div className="relative">
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        placeholder="Buscar por reclutador, empresa o texto..."
                                        className="w-full pl-9 pr-4 py-2 messages-search-input bg-slate-950/80 border border-slate-800 rounded-xl text-xs text-slate-200 placeholder-slate-500 focus:border-cyan-500 focus:ring-0 transition"
                                    />
                                    <svg className="w-4 h-4 text-slate-500 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                    </svg>
                                </div>

                                <div className="flex space-x-1 p-1 messages-tabs-wrapper bg-slate-950/60 rounded-xl border border-slate-800/60 text-xs">
                                    <button
                                        onClick={() => setFilter('all')}
                                        className={`flex-1 py-1.5 rounded-lg font-semibold transition ${filter === 'all'
                                            ? 'messages-tab-active bg-cyan-500/10 text-cyan-400 border border-cyan-500/30'
                                            : 'messages-tab-inactive text-slate-400 hover:text-slate-200'
                                            }`}
                                    >
                                        Todos ({messages.length})
                                    </button>
                                    <button
                                        onClick={() => setFilter('unread')}
                                        className={`flex-1 py-1.5 rounded-lg font-semibold transition ${filter === 'unread'
                                            ? 'messages-tab-active bg-cyan-500/10 text-cyan-400 border border-cyan-500/30'
                                            : 'messages-tab-inactive text-slate-400 hover:text-slate-200'
                                            }`}
                                    >
                                        Sin leer ({messages.filter((m) => !m.read_at).length})
                                    </button>
                                </div>
                            </div>

                            {/* Lista scrolleable */}
                            <div className="flex-1 overflow-y-auto space-y-2 pr-1 custom-scrollbar">
                                {filteredMessages.length === 0 ? (
                                    <div className="text-center py-12 text-slate-500 text-xs">
                                        No se encontraron mensajes con los criterios seleccionados.
                                    </div>
                                ) : (
                                    filteredMessages.map((msg) => {
                                        const isSelected = msg.id === selectedMessageId;
                                        const isUnread = !msg.read_at;
                                        const dateStr = new Date(msg.created_at).toLocaleDateString('es-ES', {
                                            day: '2-digit',
                                            month: 'short',
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        });

                                        return (
                                            <div
                                                key={msg.id}
                                                onClick={() => handleSelectMessage(msg)}
                                                className={`p-3.5 rounded-xl cursor-pointer border transition duration-150 relative group ${isSelected
                                                    ? 'message-item-selected bg-slate-800/80 border-cyan-500/50 shadow-md'
                                                    : isUnread
                                                        ? 'message-item-unread bg-slate-900/90 border-cyan-500/30 hover:bg-slate-850 hover:border-slate-700'
                                                        : 'message-item-read bg-slate-950/40 border-slate-850 hover:bg-slate-900/60 hover:border-slate-800'
                                                    }`}
                                            >
                                                {isUnread && (
                                                    <span className="absolute top-3.5 right-3.5 w-2 h-2 rounded-full bg-cyan-400 ring-4 ring-cyan-500/20" />
                                                )}

                                                <div className="flex items-center space-x-3 mb-1.5">
                                                    <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-cyan-500/20 to-indigo-500/20 border border-cyan-500/30 flex items-center justify-center text-xs font-bold text-cyan-400 uppercase shrink-0">
                                                        {(msg.sender_name || 'R').charAt(0)}
                                                    </div>
                                                    <div className="flex-1 min-w-0 pr-4">
                                                        <p className="text-xs font-bold text-slate-100 truncate">
                                                            {msg.sender_name || 'Reclutador Anónimo'}
                                                        </p>
                                                        <p className="text-[11px] text-slate-400 truncate">
                                                            {msg.sender_company || 'Empresa no especificada'}
                                                        </p>
                                                    </div>
                                                </div>

                                                <p className="text-xs text-slate-300 line-clamp-2 mb-2">
                                                    {msg.message}
                                                </p>

                                                <div className="flex items-center justify-between text-[10px] text-slate-500 pt-1 border-t border-slate-800/50">
                                                    <span>{dateStr}</span>
                                                    <button
                                                        onClick={(e) => confirmDelete(msg, e)}
                                                        className="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-red-500 p-0.5 rounded transition cursor-pointer"
                                                        title="Eliminar mensaje"
                                                    >
                                                        <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        );
                                    })
                                )}
                            </div>
                        </div>

                        {/* Panel Derecho: Detalle del Mensaje */}
                        <div className="lg:col-span-7 messages-container bg-slate-900/50 backdrop-blur-md border border-slate-800/80 rounded-2xl p-6 shadow-xl min-h-[650px] flex flex-col justify-between">
                            {selectedMessage ? (
                                <div className="space-y-6">
                                    {/* Cabecera del Mensaje */}
                                    <div className="flex flex-col sm:flex-row sm:items-center justify-between pb-5 border-b border-slate-800/80 gap-3">
                                        <div className="flex items-center space-x-3.5">
                                            <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-cyan-500 to-indigo-600 flex items-center justify-center text-slate-950 text-lg font-extrabold uppercase shadow-lg shadow-cyan-500/10 shrink-0">
                                                {(selectedMessage.sender_name || 'R').charAt(0)}
                                            </div>
                                            <div>
                                                <h3 className="text-base font-bold text-slate-100">
                                                    {selectedMessage.sender_name || 'Reclutador'}
                                                </h3>
                                                <div className="flex flex-wrap items-center gap-x-2 text-xs text-slate-400 mt-0.5">
                                                    <span className="font-semibold text-cyan-400">{selectedMessage.sender_company || 'Empresa'}</span>
                                                    <span>•</span>
                                                    <span className="text-slate-300">{selectedMessage.sender_email}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-center space-x-2">
                                            {/* Dropdown de Respuesta Profesional */}
                                            <div className="relative" ref={replyMenuRef}>
                                                <button
                                                    onClick={() => setShowReplyMenu(!showReplyMenu)}
                                                    className="px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-cyan-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 text-xs font-bold shadow-md shadow-cyan-500/10 flex items-center space-x-2 transition cursor-pointer"
                                                >
                                                    <span>Responder</span>
                                                    <svg className={`w-3 h-3 text-slate-950 transition-transform duration-200 ${showReplyMenu ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                                    </svg>
                                                </button>

                                                {/* Menú Flotante */}
                                                {showReplyMenu && (
                                                    <div className="absolute right-0 mt-2 w-56 rounded-2xl message-dropdown-menu bg-slate-900/95 backdrop-blur-xl border border-slate-700/80 shadow-2xl p-1.5 z-50 animate-in fade-in zoom-in-95 duration-150">
                                                        <div className="px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-800/80 mb-1">
                                                            Elegir método de respuesta
                                                        </div>

                                                        {/* Opción Gmail */}
                                                        <a
                                                            href={`https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(selectedMessage.sender_email)}&su=${encodeURIComponent('Respuesta a contacto profesional - ULEAM Academic')}&body=${encodeURIComponent(`Hola ${selectedMessage.sender_name || ''},\n\nGracias por tu mensaje sobre mi proyecto en ULEAM Academic.\n\n`)}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            onClick={() => setShowReplyMenu(false)}
                                                            className="flex items-center space-x-3 px-3 py-2 rounded-xl text-xs font-semibold text-slate-200 hover:bg-slate-800/80 hover:text-white transition group cursor-pointer"
                                                        >
                                                            <div className="w-6 h-6 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center text-red-400 group-hover:scale-110 transition">
                                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                                                                    <path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.272H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.91 1.528-1.145C21.69 2.28 24 3.434 24 5.457z" />
                                                                </svg>
                                                            </div>
                                                            <div className="flex flex-col">
                                                                <span className="font-bold">Gmail</span>
                                                                <span className="text-[10px] text-slate-400">Abrir en navegador</span>
                                                            </div>
                                                        </a>

                                                        {/* Opción Outlook */}
                                                        <a
                                                            href={`https://outlook.live.com/mail/0/deeplink/compose?to=${encodeURIComponent(selectedMessage.sender_email)}&subject=${encodeURIComponent('Respuesta a contacto profesional - ULEAM Academic')}&body=${encodeURIComponent(`Hola ${selectedMessage.sender_name || ''},\n\nGracias por contactarme respecto a mi proyecto en ULEAM Academic.\n\n`)}`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            onClick={() => setShowReplyMenu(false)}
                                                            className="flex items-center space-x-3 px-3 py-2 rounded-xl text-xs font-semibold text-slate-200 hover:bg-slate-800/80 hover:text-white transition group cursor-pointer"
                                                        >
                                                            <div className="w-6 h-6 rounded-lg bg-cyan-500/10 border border-cyan-500/20 flex items-center justify-center text-cyan-400 group-hover:scale-110 transition">
                                                                <svg className="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                                                                    <path d="M24 7.25v9.5c0 1.24-1.01 2.25-2.25 2.25H9.75V5H21.75C22.99 5 24 6.01 24 7.25zM8.25 5v14H2.25C1.01 19 0 17.99 0 16.75v-9.5C0 6.01 1.01 5 2.25 5h6z" />
                                                                </svg>
                                                            </div>
                                                            <div className="flex flex-col">
                                                                <span className="font-bold">Outlook</span>
                                                                <span className="text-[10px] text-slate-400">Abrir en navegador</span>
                                                            </div>
                                                        </a>

                                                        <div className="my-1 border-t border-slate-800/80" />

                                                        {/* Opción Copiar Correo */}
                                                        <button
                                                            onClick={() => {
                                                                handleCopyEmail(selectedMessage.sender_email);
                                                                setShowReplyMenu(false);
                                                            }}
                                                            className="w-full flex items-center space-x-3 px-3 py-2 rounded-xl text-xs font-semibold text-slate-200 hover:bg-slate-800/80 hover:text-white transition group text-left cursor-pointer"
                                                        >
                                                            <div className="w-6 h-6 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 group-hover:scale-110 transition">
                                                                <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                                </svg>
                                                            </div>
                                                            <div className="flex flex-col">
                                                                <span className="font-bold">{copiedEmail ? '¡Copiado!' : 'Copiar Correo'}</span>
                                                                <span className="text-[10px] text-slate-400">{selectedMessage.sender_email}</span>
                                                            </div>
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Botón Eliminar Mensaje */}
                                            <button
                                                onClick={() => confirmDelete(selectedMessage)}
                                                className="message-action-btn p-1.5 px-2.5 rounded-xl bg-slate-800/80 hover:bg-red-950/40 text-slate-400 hover:text-red-400 border border-slate-700/80 hover:border-red-800/50 text-xs font-bold transition flex items-center space-x-1 cursor-pointer"
                                                title="Eliminar mensaje"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>

                                    {/* Cuerpo del Mensaje del Reclutador */}
                                    <div className="message-body-card bg-slate-950/60 border border-slate-800/70 rounded-2xl p-5 shadow-inner">
                                        <div className="flex items-center justify-between text-xs text-slate-400 mb-3">
                                            <span className="font-semibold text-slate-300 uppercase tracking-wider text-[11px]">
                                                Propuesta de Contacto
                                            </span>
                                            <span>
                                                {new Date(selectedMessage.created_at).toLocaleString('es-ES', {
                                                    dateStyle: 'medium',
                                                    timeStyle: 'short',
                                                })}
                                            </span>
                                        </div>
                                        <p className="text-slate-200 text-sm leading-relaxed whitespace-pre-wrap">
                                            {selectedMessage.message}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center py-32 text-slate-500">
                                    <svg className="w-12 h-12 mx-auto mb-3 text-slate-600 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.5">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                    <p className="text-sm font-semibold text-slate-400">Selecciona un mensaje de la lista</p>
                                    <p className="text-xs text-slate-500 mt-1">Haz clic en cualquier mensaje para ver su contenido.</p>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Modal de Confirmación para Eliminar Mensaje */}
            {deleteModalOpen && (
                <div className="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
                    <div className="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-2xl">
                        <div className="flex items-center space-x-3 text-red-400 mb-4">
                            <div className="p-2 bg-red-950/50 border border-red-800/50 rounded-xl">
                                <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </div>
                            <h3 className="text-lg font-bold text-white">¿Eliminar este mensaje?</h3>
                        </div>

                        <p className="text-sm text-slate-300 mb-6">
                            Esta acción eliminará el mensaje recibido de <strong className="text-white">{messageToDelete?.sender_name || 'este reclutador'}</strong> de forma permanente.
                        </p>

                        <div className="flex justify-end space-x-3">
                            <button
                                onClick={() => setDeleteModalOpen(false)}
                                className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition"
                            >
                                Cancelar
                            </button>
                            <button
                                onClick={handleDelete}
                                className="px-4 py-2 bg-red-600 hover:bg-red-500 text-white rounded-xl text-xs font-bold shadow-lg shadow-red-600/20 transition"
                            >
                                Sí, Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
