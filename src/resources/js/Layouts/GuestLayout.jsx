import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';

export default function GuestLayout({ children }) {
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
        <div className="relative flex min-h-screen flex-col items-center bg-[#f3f5f9] dark:bg-[#090d16] pt-6 sm:justify-center sm:pt-0 text-[#1e293b] dark:text-[#f1f5f9] transition-colors duration-200">
            {/* Botón flotante para alternar tema */}
            <div className="absolute top-4 right-4 z-20">
                <button
                    onClick={() => setTheme(theme === 'dark' || theme === 'spatial' ? 'academic' : 'dark')}
                    className="p-2 rounded-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-xs hover:scale-105 transition"
                    title="Alternar tema"
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
            </div>

            <div>
                <Link href="/" className="flex flex-col items-center group gap-2">
                    <ApplicationLogo className="h-14 w-auto object-contain transition group-hover:scale-105" />
                    <div className="flex items-center">
                        <span className="brand-nexus text-2xl font-black tracking-tight text-blue-700 dark:text-blue-500">nexus</span>
                        <span className="brand-academic text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">academic</span>
                    </div>
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-white dark:bg-[#0f172a] border border-slate-200 dark:border-slate-800 px-8 py-7 shadow-xl sm:max-w-md sm:rounded-2xl transition-colors duration-200">
                {children}
            </div>
        </div>
    );
}
