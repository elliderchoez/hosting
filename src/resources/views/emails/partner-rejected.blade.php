<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolución de Solicitud — Nexus Academic</title>
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
            background-color: #fff1f2;
            color: #be123c;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #fecdd3;
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
        .reason-box {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 18px 20px;
            margin: 24px 0;
        }
        .reason-title {
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            color: #991b1b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .reason-text {
            font-size: 14px;
            color: #7f1d1d;
            line-height: 1.5;
            margin: 0;
            white-space: pre-line;
        }
        .btn-container {
            text-align: center;
            margin: 30px 0;
        }
        .btn {
            display: inline-block;
            background-color: #0f172a;
            color: #ffffff !important;
            text-decoration: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.2px;
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
            <div class="badge">SOLICITUD NO APROBADA</div>
            
            <h1>Resolución sobre tu Solicitud de Registro</h1>
            
            <p>Estimado/a <strong>{{ $partner->name }}</strong>{{ $partner->account_type === 'company' ? ' (' . $partner->company . ')' : '' }},</p>
        

            <p>
                Te informamos que, tras la revisión y validación de datos realizada por nuestro equipo administrativo, tu solicitud de registro como <strong>{{ $partner->account_type === 'company' ? 'Empresa' : 'Reclutador' }}</strong> no pudo ser aprobada en esta ocasión.
            </p>

            <!-- Motivo de Rechazo -->
            <div class="reason-box">
                <div class="reason-title">Observaciones de la Administración:</div>
                <div class="reason-text">{{ $reason }}</div>
            </div>

            <p>
                Si consideras que hubo una omisión o si cuentas con la información corregida (RUC institucional, correo corporativo o credenciales actualizadas), puedes presentar una nueva solicitud a través del formulario oficial:
            </p>

            <!-- Botón para Reintentar -->
            <div class="btn-container">
                <a href="{{ $registerUrl }}" class="btn" target="_blank">
                    Presentar Nueva Solicitud
                </a>
            </div>

            <div style="margin-top: 24px; padding: 14px 16px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #64748b; line-height: 1.5;">
                <p style="margin: 0 0 6px 0; color: #e11d48; font-weight: 600;">
                    Por favor no respondas a este mensaje, ya que fue emitido automáticamente por el sistema.
                </p>
                <p style="margin: 0; color: #475569;">
                    Para cualquier aclaración o asistencia técnica, puedes escribirnos al correo oficial de Nexus Academic: 
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
