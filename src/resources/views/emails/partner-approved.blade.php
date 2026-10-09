<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuenta Aprobada — Nexus Academic</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f1f5f9;
            color: #1e293b;
            -webkit-text-size-adjust: 100%;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            border: 1px solid #e2e8f0;
        }
        .header {
            background: #0b132b;
            padding: 32px 24px;
            text-align: center;
        }
        .logo-title {
            color: #ffffff;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin: 0;
        }
        .logo-sub {
            color: #38bdf8;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            font-weight: 700;
            margin-top: 4px;
        }
        .content {
            padding: 36px 32px;
        }
        .badge {
            display: inline-block;
            background-color: #ecfdf5;
            color: #059669;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #a7f3d0;
            margin-bottom: 16px;
        }
        h1 {
            color: #0f172a;
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 16px 0;
            line-height: 1.3;
        }
        p {
            font-size: 14px;
            line-height: 1.6;
            color: #475569;
            margin: 0 0 16px 0;
        }
        .credentials-card {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
        }
        .credentials-title {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
        }
        .credential-item {
            font-size: 14px;
            margin: 8px 0;
            color: #334155;
        }
        .credential-item strong {
            color: #0f172a;
        }
        .btn-container {
            text-align: center;
            margin: 30px 0;
        }
        .btn {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            padding: 14px 32px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.2px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }
        .features-list {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 20px 0;
        }
        .features-list ul {
            margin: 8px 0 0 0;
            padding-left: 20px;
            color: #0369a1;
            font-size: 13px;
            line-height: 1.6;
        }
        .footer {
            background-color: #f8fafc;
            padding: 24px 32px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }
        .footer p {
            margin: 4px 0;
            font-size: 11px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Encabezado Institucional -->
        <div class="header">
            <div class="logo-title">nexus<span style="color: #38bdf8;">academic</span></div>
        </div>

        <!-- Cuerpo del Correo -->
        <div class="content">
            <div class="badge">SOLICITUD APROBADA</div>
            
            <h1>¡Bienvenido a Nexus Academic!</h1>
            
            <p>Estimado/a <strong>{{ $partner->name }}</strong>{{ $partner->account_type === 'company' ? ' (' . $partner->company . ')' : '' }},</p>
            
            <p>
                Nos complace informarte que la administración de <strong>NexusAcademic</strong> ha verificado y aprobado satisfactoriamente tu registro de cuenta como <strong>{{ $partner->account_type === 'company' ? 'Empresa Aliada' : 'Reclutador Especializado' }}</strong>.
            </p>

            <p>
                A partir de este momento, tienes acceso total para explorar, interactuar y ejecutar en tiempo real las soluciones tecnológicas y proyectos de titulación destacados de nuestros estudiantes e ingenieros.
            </p>

            <!-- Credenciales de Acceso -->
            <div class="credentials-card">
                <div class="credentials-title">Ingresa con tu correo y contraseña</div>
                <div class="credential-item">
                    <strong>Perfil de Acceso:</strong> {{ $partner->account_type === 'company' ? 'Empresa / Organización' : 'Reclutador / Headhunter' }}
                </div>
                <div class="credential-item">
                    <strong>Correo Electrónico:</strong> <span style="font-family: monospace; color: #2563eb;">{{ $partner->email }}</span>
                </div>
            </div>

            <!-- Botón de Inicio de Sesión -->
            <div class="btn-container">
                <a href="{{ $loginUrl }}" class="btn" target="_blank">
                    Iniciar Sesión en Nexus Academic
                </a>
            </div>

            <!-- Beneficios y Capacidades -->
            <div class="features-list">
                <strong style="color: #0369a1; font-size: 13px;">Lo que puedes hacer ahora en la plataforma:</strong>
                <ul>
                    <li>Ejecutar demostraciones interactivas y contenedores en tiempo real de cada proyecto.</li>
                    <li>Establecer contacto directo con los creadores y líderes de desarrollo.</li>
                </ul>
            </div>

            <div style="margin-top: 24px; padding: 14px 16px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #64748b; line-height: 1.5;">
                <p style="margin: 0 0 6px 0; color: #e11d48; font-weight: 600;">
                    Por favor no respondas a este mensaje.
                </p>
                <p style="margin: 0; color: #475569;">
                    Si necesitas asistencia técnica o tienes alguna consulta, puedes escribirnos al correo oficial de Nexus Academic: 
                    <a href="mailto:{{ config('mail.support_address', 'soporte@nexusacademic.com') }}" style="color: #0284c7; font-weight: 700; text-decoration: underline;">{{ config('mail.support_address', 'soporte@nexusacademic.com') }}</a>
                </p>
            </div>
        </div>

        <!-- Pie de Página Institucional -->
        <div class="footer">
            <p><strong>Nexus Academic</strong></p>
            <p>Plataforma Institucional de Demostración y Ejecución de Proyectos de Titulación</p>
            <p>Manta, Manabí, Ecuador · © {{ date('Y') }} Todos los derechos reservados.</p>
        </div>
    </div>
</body>
</html>
