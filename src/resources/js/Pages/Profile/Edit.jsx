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
                    <h2 className="text-xl font-bold leading-tight text-slate-100">
                        Configuración de la Cuenta
                    </h2>
                    <Link
                        href={route('profile.professional')}
                        className="px-4 py-2 bg-slate-900 border border-slate-800 hover:border-slate-700 text-slate-300 hover:text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5"
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

            <div className="py-12 bg-slate-950 text-slate-100">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur shadow-md">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl text-slate-200"
                        />
                    </div>

                    <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur shadow-md">
                        <UpdatePasswordForm className="max-w-xl text-slate-200" />
                    </div>

                    <div className="rounded-2xl border border-slate-900 bg-slate-900/40 p-6 backdrop-blur shadow-md">
                        <DeleteUserForm className="max-w-xl text-slate-200" />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
