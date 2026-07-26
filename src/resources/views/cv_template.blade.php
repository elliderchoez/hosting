<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Vitae - {{ $user->name }}</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css?family=Plus+Jakarta+Sans:400,500,600,700|Inter:400,500,600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f172a; /* Slate 900 */
            --accent: #0891b2; /* Cyan 600 */
            --accent-light: #ecfeff; /* Cyan 50 */
            --text-main: #334155; /* Slate 700 */
            --text-dark: #0f172a; /* Slate 900 */
            --text-light: #64748b; /* Slate 500 */
            --border: #cbd5e1; /* Slate 300 */
            --bg-light: #f8fafc; /* Slate 50 */
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            background-color: var(--bg-light);
            line-height: 1.6;
            padding: 40px 20px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .cv-card {
            max-width: 800px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            padding: 45px;
        }

        /* Top Header */
        .cv-header {
            border-bottom: 2px solid var(--accent);
            padding-bottom: 25px;
            margin-bottom: 30px;
        }

        .cv-header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 36px;
            font-weight: 700;
            letter-spacing: -1px;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .cv-header p.subtitle {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 20px;
        }

        /* Contact Details List */
        .contact-list {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
            color: var(--text-light);
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            color: var(--text-light);
        }

        .contact-item svg {
            width: 14px;
            height: 14px;
            color: var(--accent);
            flex-shrink: 0;
        }

        .contact-item a {
            color: var(--text-light);
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .contact-item a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        /* Section block */
        .section-block {
            margin-bottom: 35px;
        }

        .section-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            border-bottom: 1.5px solid var(--border);
            padding-bottom: 6px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title svg {
            width: 16px;
            height: 16px;
            color: var(--accent);
        }

        /* Skills Pill Layout */
        .skills-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .skill-pill {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            background-color: var(--accent-light);
            border: 1px solid rgba(8, 145, 178, 0.15);
            padding: 4px 12px;
            border-radius: 6px;
        }

        /* Bio */
        .bio-para {
            font-size: 14px;
            color: var(--text-main);
            line-height: 1.6;
            text-align: justify;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            background-color: var(--bg-light);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .info-item {
            font-size: 13px;
        }

        .info-item strong {
            color: var(--primary);
        }

        /* Timeline / Education */
        .timeline {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .timeline-item {
            border-left: 2.5px solid var(--accent);
            padding-left: 15px;
            margin-left: 5px;
            position: relative;
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 4px;
        }

        .timeline-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--primary);
        }

        .timeline-date {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            white-space: nowrap;
        }

        .timeline-sub {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 500;
        }

        /* Top bar actions */
        .btn-print-container {
            max-width: 800px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: flex-end;
        }

        .btn-print {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--accent);
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(8, 145, 178, 0.15);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print:hover {
            background-color: #0e7490;
            box-shadow: 0 6px 16px rgba(8, 145, 178, 0.25);
            transform: translateY(-1px);
        }

        /* Print Media Styles */
        @media print {
            @page {
                margin: 0; /* Elimina cabeceras (título/fecha) y pies de página (URL/páginas) del navegador */
            }
            body {
                background-color: #ffffff;
                padding: 2.5cm 2cm; /* Márgenes seguros internos dentro de la página impresa */
                color: #000000;
            }
            .cv-card {
                box-shadow: none;
                border: none;
                max-width: 100%;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Top Print Actions Bar (Hidden on print) -->
    <div class="btn-print-container no-print">
        <button class="btn-print" onclick="window.print()">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Descargar CV en PDF (Imprimir)
        </button>
    </div>

    <!-- CV Card Structure -->
    <div class="cv-card">
        <!-- Main Top Header -->
        <div class="cv-header">
            <h1>{{ $user->name }}</h1>
            
            <div class="contact-list">
                <div class="contact-item">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <span>{{ $user->email }}</span>
                </div>
                @if($profile->phone)
                    <div class="contact-item">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.94.725l.548 2.2a1 1 0 01-.321.988l-1.305.98a10.582 10.582 0 004.872 4.872l.98-1.305a1 1 0 01.988-.321l2.2.548a1 1 0 01.725.94V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                        </svg>
                        <span>{{ $profile->phone }}</span>
                    </div>
                @endif
                @if($profile->github_username)
                    <a href="https://github.com/{{ $profile->github_username }}" target="_blank" class="contact-item">
                        <svg fill="currentColor" viewBox="0 0 24 24" style="width: 13px; height: 13px;">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482C19.138 20.193 22 16.44 22 12.017 22 6.484 17.522 2 12 2z"/>
                        </svg>
                        <span>github.com/{{ $profile->github_username }}</span>
                    </a>
                @endif
                @if($profile->linkedin_url)
                    <a href="{{ $profile->linkedin_url }}" target="_blank" class="contact-item">
                        <svg fill="currentColor" viewBox="0 0 24 24" style="width: 13px; height: 13px;">
                            <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/>
                        </svg>
                        <span>linkedin.com / perfil</span>
                    </a>
                @endif
            </div>
        </div>

        <!-- Professional Bio -->
        @if($profile->bio)
            <div class="section-block">
                <div class="section-title">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    Perfil Profesional
                </div>
                <p class="bio-para">
                    {{ $profile->bio }}
                </p>
            </div>
        @endif

        <!-- Habilidades / Skills -->
        @if(!empty($profile->skills))
            <div class="section-block">
                <div class="section-title">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                    </svg>
                    Habilidades Técnicas
                </div>
                <div class="skills-list">
                    @foreach($profile->skills as $skill)
                        <span class="skill-pill">{{ $skill }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Education & Academic Training -->
        @if(!empty($profile->education))
            <div class="section-block">
                <div class="section-title">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-4-9 4 9 5zM12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z" />
                    </svg>
                    Educación y Formación
                </div>
                <div class="timeline">
                    @foreach($profile->education as $edu)
                        <div class="timeline-item">
                            <div class="timeline-header">
                                <span class="timeline-title">{{ $edu['degree'] ?? 'Carrera' }}</span>
                                <span class="timeline-date">{{ $edu['start_year'] ?? '' }} - {{ $edu['end_year'] ?? 'Presente' }}</span>
                            </div>
                            <div class="timeline-sub">
                                {{ $edu['institution'] ?? 'Universidad Laica Eloy Alfaro de Manabí' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Información Académica Adicional -->
        <div class="section-block">
            <div class="section-title">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Información Institucional
            </div>
            <div class="info-grid" style="grid-template-columns: 1fr;">
                <div class="info-item"><strong>Universidad:</strong> Universidad Laica Eloy Alfaro de Manabí</div>
            </div>
        </div>
    </div>
</body>
</html>
