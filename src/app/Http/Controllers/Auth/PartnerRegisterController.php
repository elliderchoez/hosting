<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Recruiter;
use App\Models\User;
use App\Rules\CleanContentRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PartnerRegisterController extends Controller
{
    /**
     * Display the Partner (Company / Recruiter) registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/RegisterPartner');
    }

    /**
     * Handle incoming partner registration.
     */
    public function store(Request $request): RedirectResponse
    {
        $accountType = $request->input('account_type', 'company');

        if (!in_array($accountType, ['company', 'recruiter'])) {
            $accountType = 'company';
        }

        $commonRules = [
            'account_type' => ['required', 'in:company,recruiter'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:recruiters,email',
                'unique:users,email',
            ],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+ \-]{7,15}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()],
        ];

        $messages = [
            'email.unique' => 'Este correo ya tiene una cuenta registrada en la plataforma.',
            'tax_id.required' => 'El número de identificación (RUC/Cédula) es obligatorio.',
            'tax_id.regex' => 'El número de identificación (RUC/Cédula) debe contener entre 10 y 13 dígitos numéricos.',
            'name.regex' => 'El nombre no puede contener números, únicamente letras y títulos.',
            'position.regex' => 'El cargo no puede contener números, únicamente letras descriptivas.',
            'phone.regex' => 'El teléfono solo debe contener números (ej: 0989630531 o con prefijo opcional +).',
            'company.max' => 'El nombre comercial o empresa no debe superar los 25 caracteres.',
            'name.max' => 'El nombre no debe superar los 25 caracteres.',
            'position.max' => 'El cargo no debe superar los 25 caracteres.',
            'website_url.regex' => 'El sitio web debe comenzar con http:// o https://',
            'linkedin_url.regex' => 'El enlace a LinkedIn debe comenzar con http:// o https://',
        ];

        if ($accountType === 'company') {
            $request->validate(array_merge($commonRules, [
                'company' => ['required', 'string', 'max:25', new CleanContentRule(1, 'nombre de la empresa')],
                'tax_id' => ['required', 'string', 'regex:/^[0-9]{10,13}$/'],
                'website_url' => ['required', 'url', 'regex:/^https?:\/\/.+/i', 'max:255'],
                'name' => ['required', 'string', 'max:25', 'regex:/^[\p{L}\s\.\,\:\'\-\(\)]+$/u', new CleanContentRule(1, 'nombre del representante')],
                'position' => ['required', 'string', 'max:25', 'regex:/^[\p{L}\s\.\,\:\'\-\/\&\(\)]+$/u', new CleanContentRule(1, 'cargo institucional')],
            ]), $messages);
        } else {
            $request->validate(array_merge($commonRules, [
                'name' => ['required', 'string', 'max:25', 'regex:/^[\p{L}\s\.\,\:\'\-\(\)]+$/u', new CleanContentRule(1, 'nombre completo')],
                'tax_id' => ['required', 'string', 'regex:/^[0-9]{10,13}$/'],
                'linkedin_url' => ['required', 'url', 'regex:/^https?:\/\/.+/i', 'max:255'],
                'company' => ['required', 'string', 'max:25', new CleanContentRule(1, 'empresa o agencia')],
                'position' => ['required', 'string', 'max:25', 'regex:/^[\p{L}\s\.\,\:\'\-\/\&\(\)]+$/u', new CleanContentRule(1, 'cargo o especialidad')],
            ]), $messages);
        }

        $recruiter = Recruiter::create([
            'account_type' => $accountType,
            'name' => strip_tags(trim($request->name)),
            'email' => strip_tags(trim(strtolower($request->email))),
            'company' => strip_tags(trim($request->company)),
            'position' => strip_tags(trim($request->position)),
            'tax_id' => strip_tags(trim($request->tax_id)),
            'website_url' => $accountType === 'company' ? strip_tags(trim($request->website_url)) : null,
            'linkedin_url' => $accountType === 'recruiter' ? strip_tags(trim($request->linkedin_url)) : null,
            'phone' => strip_tags(trim($request->phone)),
            'password' => Hash::make($request->password),
            'verified' => false,
            'status' => 'pending',
            'rejection_reason' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $tipoTexto = $accountType === 'company' ? 'Empresa' : 'Reclutador';

        return redirect()->route('login')->with(
            'status',
            "¡Solicitud enviada con éxito! Tu cuenta de {$tipoTexto} ha sido registrada y está en proceso de revisión por la administración de Nexus Academic. Te enviaremos un correo electrónico una vez sea aprobada."
        );
    }
}
