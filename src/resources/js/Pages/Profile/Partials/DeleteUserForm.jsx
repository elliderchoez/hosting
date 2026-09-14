import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
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
            onError: () => passwordInput.current.focus(),
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
            <header>
                <h2 className="text-base font-bold text-slate-900 dark:text-slate-100">
                    Eliminar Cuenta
                </h2>

                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Una vez que tu cuenta sea eliminada, todos sus recursos y datos asociados (incluyendo proyectos y despliegues en contenedores) se borrarán de forma permanente.
                </p>
            </header>

            <div>
                <button 
                    onClick={confirmUserDeletion}
                    className="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-600 hover:bg-red-700 text-white shadow-xs transition duration-200"
                >
                    Eliminar Cuenta
                </button>
            </div>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className="p-6 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 border border-slate-200 dark:border-slate-800 rounded-2xl">
                    <h2 className="text-lg font-bold text-slate-900 dark:text-slate-100">
                        ¿Estás seguro de que deseas eliminar tu cuenta?
                    </h2>

                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                        Esta acción es irreversible. Se eliminarán permanentemente todos tus proyectos, contenedores gVisor y la hoja de vida generada. Por favor, introduce tu contraseña para confirmar la eliminación definitiva de la cuenta.
                    </p>

                    <div className="mt-6">
                        <InputLabel
                            htmlFor="password"
                            value="Contraseña"
                            className="sr-only"
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
                            className="mt-1 block w-3/4 bg-slate-50 dark:bg-slate-950 border border-slate-300 dark:border-slate-800 focus:border-[#1534e8] dark:focus:border-cyan-500 focus:ring-0 text-slate-900 dark:text-slate-100 text-sm rounded-xl py-2.5 px-3"
                            isFocused
                            placeholder="Contraseña de la cuenta"
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
                            className="px-4 py-2.5 rounded-xl text-sm font-semibold bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 dark:border-slate-700 dark:text-slate-200 transition shadow-2xs"
                        >
                            Cancelar
                        </button>

                        <button 
                            type="submit"
                            disabled={processing}
                            className="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-600 hover:bg-red-700 text-white transition disabled:opacity-50 shadow-xs"
                        >
                            {processing ? 'Eliminando...' : 'Eliminar Cuenta'}
                        </button>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
