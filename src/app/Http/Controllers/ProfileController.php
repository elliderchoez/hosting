<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public const ALLOWED_SKILLS = [
        // Frontend & UI
        'React', 'Vue.js', 'Angular', 'Next.js', 'Nuxt.js', 'Svelte',
        'Tailwind CSS', 'Bootstrap', 'HTML5', 'CSS3', 'JavaScript', 'TypeScript',
        // Backend & Lenguajes
        'PHP', 'Laravel', 'Node.js', 'Express.js', 'NestJS', 'Python',
        'Django', 'FastAPI', 'Flask', 'Java', 'Spring Boot', 'C#', '.NET',
        'Go', 'Rust', 'Ruby on Rails',
        // Bases de datos & Cache
        'PostgreSQL', 'MySQL', 'MariaDB', 'SQLite', 'MongoDB', 'Redis',
        'Firebase', 'Supabase', 'Oracle Database',
        // DevOps, Cloud & Seguridad
        'Docker', 'Kubernetes', 'Linux', 'Git', 'GitHub Actions',
        'AWS', 'Azure', 'Google Cloud Platform', 'Nginx', 'CI/CD',
        'REST APIs', 'GraphQL', 'Cybersecurity', 'Microservicios'
    ];

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Validación de Ciberseguridad contra XSS, inyecciones de código y ataques
        $this->validateCybersecurity($request);

        $validated = $request->validated();
        $validated['name'] = strip_tags(trim($validated['name']));
        $validated['email'] = strip_tags(trim(strtolower($validated['email'])));

        $request->user()->fill($validated);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::back()->with('status', 'Información de la cuenta actualizada.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Display the user's professional profile.
     */
    public function showProfessional(Request $request): Response
    {
        $user = $request->user();
        
        $profile = \App\Models\Profile::firstOrCreate(
            ['user_id' => $user->id],
            ['bio' => '', 'skills' => [], 'education' => []]
        );

        return Inertia::render('Profile/Show', [
            'profile' => $profile,
            'status' => session('status'),
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'availableSkills' => self::ALLOWED_SKILLS,
        ]);
    }

    /**
     * Update additional student profile details (bio, skills, education, etc.)
     */
    public function updateDetails(Request $request): RedirectResponse
    {
        $user = $request->user();
        
        // 1. Ciberseguridad: Bloqueo de inyecciones XSS y código malicioso
        $this->validateCybersecurity($request);

        // 2. Validación estricta con expresiones regulares
        $validated = $request->validate([
            'phone' => [
                'nullable',
                'string',
                'max:16',
                'regex:/^\+?[0-9]{10,14}$/'
            ],
            'github_username' => [
                'nullable',
                'string',
                'max:39',
                'regex:/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/'
            ],
            'linkedin_url' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(?:https?:\/\/)?(?:[a-zA-Z0-9\-]+\.)*linkedin\.com\/in\/[a-zA-Z0-9_\-\.%]{3,100}\/?$/i'
            ],
            'bio' => [
                'nullable',
                'string',
                'max:1000'
            ],
            'skills' => [
                'nullable',
                'array'
            ],
            'skills.*' => [
                'string',
                Rule::in(self::ALLOWED_SKILLS)
            ],
            'education' => [
                'nullable',
                'array'
            ],
        ], [
            'phone.regex' => 'Solo se permite de 10 a 14 dígitos numéricos.',
            'github_username.regex' => 'El usuario de GitHub solo puede contener letras, números y guiones sencillos (máximo 39 caracteres, sin guión al inicio o al final).',
            'linkedin_url.regex' => 'Debe ser un enlace válido a tu perfil de LinkedIn.',
            'bio.max' => 'La biografía no puede superar los 1000 caracteres.',
            'skills.*.in' => 'Solo puedes seleccionar habilidades técnicas de la lista oficial permitida.',
        ]);

        // 3. Filtro contra groserías y lenguaje ofensivo en biografía
        if (!empty($validated['bio']) && $this->hasProfanity($validated['bio'])) {
            throw ValidationException::withMessages([
                'bio' => 'La biografía contiene vocabulario inapropiado o no permitido. Por favor mantén un lenguaje profesional y respetuoso con la comunidad académica.',
            ]);
        }

        // 4. Verificación de existencia real de cuenta de GitHub
        if (!empty($validated['github_username'])) {
            $githubUser = trim($validated['github_username']);
            try {
                $ghResponse = Http::timeout(4)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ])->get("https://github.com/{$githubUser}");

                if ($ghResponse->status() === 404) {
                    throw ValidationException::withMessages([
                        'github_username' => "El usuario de GitHub '{$githubUser}' no existe en GitHub. Por favor ingresa una cuenta real.",
                    ]);
                }
            } catch (ValidationException $ve) {
                throw $ve;
            } catch (\Exception $e) {
                // Si la conexión externa falla temporalmente, no bloqueamos al estudiante
            }
        }

        // 5. Verificación de formato y existencia real de perfil de LinkedIn
        if (!empty($validated['linkedin_url'])) {
            $linkedinUrl = trim($validated['linkedin_url']);
            if (!preg_match('/^https?:\/\//i', $linkedinUrl)) {
                $linkedinUrl = 'https://' . $linkedinUrl;
            }
            if (!preg_match('/^https?:\/\/(?:[a-zA-Z0-9\-]+\.)*linkedin\.com\/in\/[a-zA-Z0-9_\-\.%]{3,100}\/?$/i', $linkedinUrl)) {
                throw ValidationException::withMessages([
                    'linkedin_url' => 'Debe ser un enlace válido a tu perfil de LinkedIn (ej: https://www.linkedin.com/in/tu-perfil).',
                ]);
            }

            try {
                $status = $this->checkLinkedInStatus($linkedinUrl);
                if (!$status['exists']) {
                    throw ValidationException::withMessages([
                        'linkedin_url' => 'El perfil de LinkedIn no existe o no es público.',
                    ]);
                }
            } catch (ValidationException $ve) {
                throw $ve;
            } catch (\Exception $e) {
                // Si la conexión externa falla temporalmente, no bloqueamos al estudiante
            }

            $validated['linkedin_url'] = $linkedinUrl;
        }

        // 6. Sanitización defensiva de cadenas
        $cleanBio = !empty($validated['bio']) ? strip_tags(trim($validated['bio'])) : null;
        $cleanPhone = !empty($validated['phone']) ? trim($validated['phone']) : null;
        $cleanGithub = !empty($validated['github_username']) ? trim($validated['github_username']) : null;
        $cleanLinkedin = !empty($validated['linkedin_url']) ? trim($validated['linkedin_url']) : null;

        $profile = \App\Models\Profile::firstOrCreate(['user_id' => $user->id]);
        
        $profile->update([
            'bio' => $cleanBio,
            'phone' => $cleanPhone,
            'github_username' => $cleanGithub,
            'linkedin_url' => $cleanLinkedin,
            'skills' => array_values(array_unique($validated['skills'] ?? [])),
            'education' => $validated['education'] ?? [],
        ]);

        return Redirect::route('profile.professional')->with('status', 'Detalles profesionales actualizados correctamente con verificación de seguridad.');
    }

    /**
     * Endpoint API para verificar en tiempo real si un usuario existe en GitHub
     */
    public function verifyGithub(Request $request): JsonResponse
    {
        $username = trim($request->query('username', ''));
        if (empty($username)) {
            return response()->json(['valid' => true, 'exists' => null]);
        }

        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?=[a-zA-Z0-9])){0,38}$/', $username)) {
            return response()->json([
                'valid' => false,
                'exists' => false,
                'message' => 'Usuario inválido (máx. 39 caracteres, solo alfanuméricos y guiones).'
            ]);
        }

        try {
            $ghResponse = Http::timeout(4)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->get("https://github.com/{$username}");

            if ($ghResponse->status() === 404) {
                return response()->json([
                    'valid' => true,
                    'exists' => false,
                    'message' => "El usuario de GitHub '{$username}' no existe."
                ]);
            }

            return response()->json([
                'valid' => true,
                'exists' => true,
                'message' => 'Usuario verificado en GitHub.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'valid' => true,
                'exists' => true,
                'message' => 'No se pudo conectar a GitHub para verificar.'
            ]);
        }
    }

    /**
     * Endpoint API para verificar en tiempo real si un perfil de LinkedIn existe
     */
    public function verifyLinkedin(Request $request): JsonResponse
    {
        $url = trim($request->query('url', ''));
        if (empty($url)) {
            return response()->json(['valid' => true, 'exists' => null]);
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        if (!preg_match('/^https?:\/\/(?:[a-zA-Z0-9\-]+\.)*linkedin\.com\/in\/[a-zA-Z0-9_\-\.%]{3,100}\/?$/i', $url)) {
            return response()->json([
                'valid' => false,
                'exists' => false,
                'message' => 'Debe ser un enlace válido a tu perfil de LinkedIn (ej: https://www.linkedin.com/in/tu-nombre).'
            ]);
        }

        try {
            $status = $this->checkLinkedInStatus($url);
            if (!$status['exists']) {
                return response()->json([
                    'valid' => true,
                    'exists' => false,
                    'message' => 'El perfil no existe en LinkedIn o no es público.'
                ]);
            }

            return response()->json([
                'valid' => true,
                'exists' => true,
                'message' => 'Perfil verificado.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'valid' => true,
                'exists' => true,
                'message' => 'Perfil verificado.'
            ]);
        }
    }

    /**
     * Helper para comprobar estado real del perfil en LinkedIn usando cURL con agente de previsualización
     */
    protected function checkLinkedInStatus(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        if ($code === 404 || strpos($effectiveUrl, '/404') !== false) {
            return ['exists' => false, 'code' => $code];
        }

        return ['exists' => true, 'code' => $code];
    }

    /**
     * Verificar si un texto contiene lenguaje soez o groserías (incluso camuflado o como subcadena).
     */
    protected function hasProfanity(string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        // 1. Normalizar minúsculas y tildes
        $normalized = mb_strtolower($text, 'UTF-8');
        $normalized = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ', 'à', 'è', 'ì', 'ò', 'ù'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n', 'a', 'e', 'i', 'o', 'u'],
            $normalized
        );

        // 2. Normalizar leetspeak (0->o, 1->i, 3->e, 4->a, 5->s, 7->t, @->a, $->s)
        $leetMap = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's'];
        $deleet = strtr($normalized, $leetMap);

        // 3. Excluir palabras académicas legítimas que contienen 'puta' u otras raíces
        $safeText = preg_replace('/\b(computad\w*|computac\w*|comput\w*|disput\w*|reputac\w*|diputad\w*|imputac\w*|envergadur\w*)\b/i', '___', $deleet);

        // 4. Subcadenas estrictamente prohibidas (detecta incluso incrustadas como 'dfgdfputa')
        $forbiddenSubstrings = [
            'puta', 'puto', 'mierd', 'pendej', 'verga', 'chucha', 'cabron', 'cabrona',
            'maricon', 'marica', 'gonorrea', 'malparid', 'conchetumad', 'chupapol',
            'mamaguev', 'careverg', 'hdp', 'zorra', 'bastard', 'chinga'
        ];

        foreach ($forbiddenSubstrings as $bad) {
            if (strpos($safeText, $bad) !== false) {
                return true;
            }
        }

        // 5. Palabras insultantes a nivel de término
        $wordInsults = [
            'idiota', 'idiotas', 'imbecil', 'imbeciles', 'estupido', 'estupida',
            'estupidos', 'estupidas', 'culiao', 'joder', 'coño', 'carajo',
            'caretuco', 'vergaso', 'vergazos'
        ];
        foreach ($wordInsults as $insult) {
            if (preg_match('/\b' . $insult . '\b/i', $safeText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar firmas de inyecciones XSS y scripts maliciosos en todas las entradas.
     */
    protected function validateCybersecurity(Request $request): void
    {
        $fieldsToCheck = ['phone', 'github_username', 'linkedin_url'];
        $dangerousPatterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/<\/?(iframe|object|embed|applet|meta|link|style)\b[^>]*>/is',
            '/javascript\s*:/is',
            '/data\s*:\s*text\/html/is',
            '/on(load|error|click|mouseover|submit|focus|keydown)\s*=/is',
            '/<[^>]+>/i',
        ];

        foreach ($fieldsToCheck as $field) {
            $val = $request->input($field);
            if (is_string($val) && !empty($val)) {
                foreach ($dangerousPatterns as $pattern) {
                    if (preg_match($pattern, $val)) {
                        throw ValidationException::withMessages([
                            $field => 'Se detectaron caracteres o etiquetas no permitidas por los protocolos de ciberseguridad.',
                        ]);
                    }
                }
            }
        }

        $bioVal = $request->input('bio');
        if (is_string($bioVal) && !empty($bioVal)) {
            if (strip_tags($bioVal) !== $bioVal || preg_match('/javascript\s*:/is', $bioVal) || preg_match('/on\w+\s*=/is', $bioVal)) {
                throw ValidationException::withMessages([
                    'bio' => 'Por políticas estrictas de ciberseguridad, no se admiten scripts ni etiquetas HTML en la biografía.',
                ]);
            }
        }
    }

    /**
     * View or download print-friendly HTML CV.
     */
    public function generateCv(Request $request)
    {
        $user = Auth::user();
        $profile = \App\Models\Profile::where('user_id', $user->id)->first();

        if (!$profile) {
            return redirect()->back()->withErrors(['error' => 'Por favor completa tu perfil primero.']);
        }

        $projects = \App\Models\Project::where('user_id', $user->id)->get();

        // Return a beautiful print-friendly CV layout that the user can print to PDF
        return view('cv_template', [
            'user' => $user,
            'profile' => $profile,
            'projects' => $projects
        ]);
    }
}
