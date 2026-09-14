import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center w-full">
                    <h2 className="text-xl font-bold leading-tight text-slate-900 dark:text-slate-100">
                        Configuración de la Cuenta
                    </h2>
                    <Link
                        href={route('profile.professional')}
                        className="px-4 py-2 bg-white hover:bg-slate-50 border border-slate-300 text-slate-700 hover:text-slate-900 dark:bg-slate-900 dark:border-slate-800 dark:hover:border-slate-700 dark:text-slate-300 dark:hover:text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow-xs"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Regresar al Perfil
                    </Link>
                </div>
            }
        >
            <Head title="Configuración de Cuenta" />

            <div className="py-8 bg-[#f3f5f9] dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div className="profile-card rounded-2xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xs dark:shadow-md">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="w-full"
                        />
                    </div>

                    <div className="profile-card rounded-2xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xs dark:shadow-md">
                        <UpdatePasswordForm className="w-full" />
                    </div>

                    <div className="profile-card rounded-2xl border border-slate-200/80 dark:border-slate-800 bg-white dark:bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xs dark:shadow-md">
                        <DeleteUserForm className="w-full" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
