import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { useEffect } from 'react';

export default function GuestLayout({ children }) {
    useEffect(() => {
        const storedTheme = localStorage.getItem('theme') || 'spatial';
        if (storedTheme === 'academic') {
            document.documentElement.classList.add('theme-academic');
        } else {
            document.documentElement.classList.remove('theme-academic');
        }
    }, []);

    return (
        <div className="flex min-h-screen flex-col items-center bg-slate-950 pt-6 sm:justify-center sm:pt-0 text-slate-100">
            <div>
                <Link href="/">
                    <ApplicationLogo className="h-20 w-20 fill-current text-cyan-400" />
                </Link>
            </div>

            <div className="mt-6 w-full overflow-hidden bg-slate-900/60 border border-slate-900/80 px-8 py-7 shadow-xl backdrop-blur-md sm:max-w-md sm:rounded-2xl">
                {children}
            </div>
        </div>
    );
}
