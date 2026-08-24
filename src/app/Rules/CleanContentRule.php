<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CleanContentRule implements ValidationRule
{
    protected int $minWords;
    protected string $fieldName;

    /**
     * Comprehensive list of Spanish profanities, insults, offensive terms, and slurs.
     */
    protected static array $badWords = [
        // Insultos comunes en español
        'mierda', 'puta', 'puto', 'putas', 'putos', 'hdp', 'hp', 'hijo de puta', 'hija de puta',
        'verga', 'vergas', 'vergazo', 'pendejo', 'pendeja', 'pendejos', 'pendejas', 'pendejada',
        'marica', 'maricon', 'maricón', 'maricones', 'cabron', 'cabrón', 'cabrones', 'cabrona',
        'malparido', 'malparida', 'careverga', 'chucha', 'conchetumadre', 'concha tu madre',
        'chupapolla', 'chupapollas', 'gilipollas', 'coño', 'carajo', 'imbecil', 'imbécil', 'imbeciles',
        'estupido', 'estúpido', 'estupida', 'estúpida', 'idiota', 'idiotas', 'tarado', 'tarada',
        'zorra', 'maldito', 'maldita', 'gonorrea', 'culiao', 'culiado', 'culicagado', 'mamaguevo',
        'mamaguevazo', 'mamawebo', 'guevon', 'guevón', 'webon', 'huevon', 'huevón', 'carechimba',
        'bastardo', 'bastarda', 'perra', 'perro de mierda', 'asno', 'subnormal', 'retrasado',
        
        // Slurs / discriminación
        'nazi', 'violador', 'violadores', 'pedofilo', 'pedófilo', 'prostituta',
        
        // Términos en inglés frecuentes
        'fuck', 'fucking', 'shit', 'bitch', 'asshole', 'dick', 'cunt', 'bastard', 'motherfucker'
    ];

    public function __construct(int $minWords = 1, string $fieldName = 'mensaje')
    {
        $this->minWords = $minWords;
        $this->fieldName = $fieldName;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail("El {$this->fieldName} debe ser un texto válido.");
            return;
        }

        // Sanitizar y limpiar texto
        $cleanText = strip_tags(trim($value));
        
        if (empty($cleanText)) {
            $fail("El {$this->fieldName} es obligatorio.");
            return;
        }

        // Validar conteo de palabras mínimo si está configurado
        if ($this->minWords > 1) {
            $words = preg_split('/\s+/u', $cleanText, -1, PREG_SPLIT_NO_EMPTY);
            if (count($words) < $this->minWords) {
                $fail("El {$this->fieldName} debe contener al menos {$this->minWords} palabras.");
                return;
            }
        }

        // 2. Filtro Anti-Insultos / Malas Palabras
        $normalizedText = mb_strtolower($cleanText, 'UTF-8');
        // Normalizar acentos para detección estricta
        $unaccentedText = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $normalizedText
        );

        foreach (self::$badWords as $badWord) {
            $unaccentedBad = str_replace(
                ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
                ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
                $badWord
            );

            // Coincidencia por palabra completa o patrón de límites de palabra
            $pattern = '/\b' . preg_quote($unaccentedBad, '/') . '\b/i';
            if (preg_match($pattern, $unaccentedText)) {
                $fail("El {$this->fieldName} contiene palabras ofensivas o inapropiadas. Por favor mantén una comunicación profesional y respetuosa.");
                return;
            }
        }
    }
}
