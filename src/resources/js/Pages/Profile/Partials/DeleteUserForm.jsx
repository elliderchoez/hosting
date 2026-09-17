import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function DeleteUserForm({ className = '' }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef();

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);
        clearErrors();
        reset();
    };

    return (
        <section className={`space-y-4 ${className}`}>
            <header className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                        Eliminar Cuenta
                    </h2>

                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Una vez que tu cuenta sea eliminada, todos sus recursos y datos asociados (incluyendo proyectos y despliegues en contenedores) se borrarán de forma permanente.
                    </p>
                </div>


            </header>

            <div>
                <button
                    onClick={confirmUserDeletion}
                    className="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-600 hover:bg-red-700 text-white shadow-xs transition duration-200 cursor-pointer flex items-center gap-2"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    <span>Eliminar Cuenta</span>
                </button>
            </div>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 border border-slate-200 dark:border-slate-800 rounded-2xl">
                    <div className="flex items-start gap-3">
                        <div className="p-2.5 rounded-xl bg-rose-100 dark:bg-rose-950/50 text-rose-600 shrink-0">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                                ¿Confirmas la eliminación definitiva de tu cuenta?
                            </h2>

                            <p className="mt-2 text-xs leading-relaxed text-slate-500 dark:text-slate-400">
                                Esta acción es irreversible por diseño de seguridad. Se purgarán permanentemente todos tus proyectos, datos de perfil y contenedores aislados. Introduce tu contraseña para verificar tu identidad y autorizar la eliminación.
                            </p>
                        </div>
                    </div>

                    <div className="mt-6">
                        <InputLabel
                            htmlFor="password"
                            value="Contraseña de confirmación"
                            className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5"
                        />

                        <TextInput
                            id="password"
                            type="password"
                            name="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) =>
                                setData('password', e.target.value)
                            }
                            className="mt-1 block w-full bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 focus:border-rose-500 dark:focus:border-rose-500 focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3 font-mono"
                            isFocused
                            placeholder="Introduce tu contraseña para confirmar"
                        />

                        <InputError
                            message={errors.password}
                            className="mt-2"
                        />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={closeModal}
                            className="px-4 py-2.5 rounded-xl text-sm font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 dark:border-slate-700 dark:text-slate-200 transition shadow-2xs cursor-pointer"
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            disabled={processing || !data.password}
                            className="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-600 hover:bg-red-700 text-white transition disabled:opacity-50 shadow-xs cursor-pointer flex items-center gap-2"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            <span>{processing ? 'Eliminando...' : 'Eliminar Cuenta'}</span>
                        </button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
