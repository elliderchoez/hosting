import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

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

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <nav className="border-b border-slate-900 bg-slate-900/40 backdrop-blur-md">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center">
                                <Link href="/" className="flex items-center gap-2.5 group">
                                    <ApplicationLogo className="block h-8 w-auto object-contain transition group-hover:scale-105" />
                                    <div className="flex items-center">
                                        <span className="brand-nexus text-lg font-black tracking-tight text-blue-700 dark:text-blue-500">nexus</span>
                                        <span className="brand-academic text-lg font-extrabold tracking-tight text-slate-900 dark:text-slate-100">academic</span>
                                    </div>
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-8 sm:flex">
                                {user?.role === 'admin' ? (
                                    <NavLink
                                        href={route('admin.dashboard')}
                                        active={route().current('admin.*')}
                                    >
                                        Consola Administrador
                                    </NavLink>
                                ) : (
                                    <NavLink
                                        href={route('dashboard')}
                                        active={route().current('dashboard')}
                                    >
                                        Panel de Control
                                    </NavLink>
                                )}
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center space-x-2">
                            <Link
                                href="/"
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 ${
                                    route().current('welcome')
                                        ? 'bg-blue-50 text-[#1534e8] border border-blue-200/80 dark:bg-slate-800 dark:text-white dark:border-slate-700 shadow-2xs'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800/60'
                                }`}
                            >
                                Home
                            </Link>

                            {user?.role !== 'admin' && (
                                <>
                                    <Link
                                        href={route('dashboard')}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 ${
                                            route().current('dashboard')
                                                ? 'bg-blue-50 text-[#1534e8] border border-blue-200/80 dark:bg-slate-800 dark:text-white dark:border-slate-700 shadow-2xs'
                                                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800/60'
                                        }`}
                                    >
                                        Mis Proyectos
                                    </Link>

                                    <Link
                                        href={route('profile.professional')}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 ${
                                            route().current('profile.professional')
                                                ? 'bg-blue-50 text-[#1534e8] border border-blue-200/80 dark:bg-slate-800 dark:text-white dark:border-slate-700 shadow-2xs'
                                                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800/60'
                                        }`}
                                    >
                                        Perfil
                                    </Link>

                                    <Link
                                        href={route('messages.index')}
                                        className={`relative px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center space-x-1.5 ${
                                            route().current('messages.*')
                                                ? 'bg-blue-50 text-[#1534e8] border border-blue-200/80 dark:bg-slate-800 dark:text-white dark:border-slate-700 shadow-2xs'
                                                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800/60'
                                        }`}
                                    >
                                        <span>Mensajes</span>
                                        {usePage().props.auth?.unreadMessagesCount > 0 && (
                                            <span className="flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-[#1534e8] px-1 text-[10px] font-extrabold text-white shadow-xs">
                                                {usePage().props.auth.unreadMessagesCount}
                                            </span>
                                        )}
                                    </Link>
                                </>
                            )}

                            <button
                                onClick={() => setTheme(theme === 'dark' || theme === 'spatial' ? 'academic' : 'dark')}
                                className="p-1.5 rounded-lg transition duration-150 bg-slate-900/60 border border-slate-800 text-slate-300 hover:text-white hover:bg-slate-800/40 flex items-center justify-center"
                                title="Cambiar tema"
                            >
                                {(theme === 'dark' || theme === 'spatial') ? (
                                    <svg className="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 9H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m0-12.728l.707.707m12.728 12.728l.707-.707M12 8a4 4 0 100 8 4 4 0 000-8z" />
                                    </svg>
                                ) : (
                                    <svg className="w-4 h-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
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

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-slate-400 transition duration-150 ease-in-out hover:bg-slate-900 hover:text-slate-200 focus:bg-slate-900 focus:text-slate-200 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden bg-slate-900 border-b border-slate-850'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        {user?.role === 'admin' ? (
                            <ResponsiveNavLink
                                href={route('admin.dashboard')}
                                active={route().current('admin.*')}
                                className="text-slate-200 hover:text-white font-semibold"
                            >
                                Consola Administrador
                            </ResponsiveNavLink>
                        ) : (
                            <ResponsiveNavLink
                                href={route('dashboard')}
                                active={route().current('dashboard')}
                                className="text-slate-300 hover:text-white"
                            >
                                Panel de Control
                            </ResponsiveNavLink>
                        )}
                    </div>

                    <div className="border-t border-slate-800 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-slate-200">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-slate-400">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink 
                                href="/"
                                active={route().current('welcome')}
                                className="text-slate-300 hover:text-white"
                            >
                                Home
                            </ResponsiveNavLink>

                            {user?.role !== 'admin' && (
                                <>
                                    <ResponsiveNavLink 
                                        href={route('dashboard')}
                                        active={route().current('dashboard')}
                                        className="text-slate-300 hover:text-white"
                                    >
                                        Mis Proyectos
                                    </ResponsiveNavLink>
                                    <ResponsiveNavLink 
                                        href={route('profile.professional')}
                                        active={route().current('profile.professional')}
                                        className="text-slate-300 hover:text-white"
                                    >
                                        Perfil
                                    </ResponsiveNavLink>
                                    <ResponsiveNavLink 
                                        href={route('messages.index')}
                                        active={route().current('messages.*')}
                                        className="text-slate-300 hover:text-white flex items-center justify-between"
                                    >
                                        <span>Mensajes</span>
                                        {usePage().props.auth?.unreadMessagesCount > 0 && (
                                            <span className="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-bold text-white">
                                                {usePage().props.auth.unreadMessagesCount} nuevos
                                            </span>
                                        )}
                                    </ResponsiveNavLink>
                                </>
                            )}
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                                className="text-slate-300 hover:text-white"
                            >
                                Cerrar Sesión
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="bg-slate-900 border-b border-slate-850 shadow">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main className="bg-slate-950 min-h-[calc(100vh-4rem)]">{children}</main>
        </div>
    );
}
