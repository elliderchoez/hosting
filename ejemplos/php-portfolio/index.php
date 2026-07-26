<?php
// PHP Landing Page de Prueba - ULEAM Academic
$student_name = "Estudiante ULEAM";
$career = "Ingeniería en Tecnologías de la Información / Software";
$current_time = date('Y-m-d H:i:s');
$skills = ["PHP", "Laravel", "Docker", "JavaScript", "HTML5", "CSS3", "TailwindCSS", "Git"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portafolio Académico - <?php echo $student_name; ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between">
    <!-- Navbar -->
    <nav class="border-b border-slate-900 bg-slate-900/40 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <span class="text-cyan-400 text-2xl">⚡</span>
                <span class="font-bold text-lg bg-gradient-to-r from-cyan-400 to-indigo-400 bg-clip-text text-transparent">ULEAM PaaS Demo</span>
            </div>
            <div class="text-xs text-slate-400 bg-slate-900 px-3 py-1.5 rounded-full border border-slate-800">
                Servidor PHP Activo
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 py-16 flex-1 flex flex-col justify-center">
        <!-- Hero Card -->
        <div class="bg-slate-900/40 border border-slate-900 rounded-3xl p-8 md:p-12 shadow-2xl backdrop-blur-md relative overflow-hidden">
            <!-- Decorative glows -->
            <div class="absolute -top-24 -left-24 w-48 h-48 bg-cyan-500/10 blur-[80px] rounded-full"></div>
            <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-indigo-500/10 blur-[80px] rounded-full"></div>

            <div class="relative z-10 space-y-6">
                <div class="inline-block px-3 py-1 bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xs font-semibold rounded-full">
                    ¡Despliegue Exitoso!
                </div>
                
                <h1 class="text-4xl md:text-5xl font-bold tracking-tight text-white leading-tight">
                    Hola, soy <span class="bg-gradient-to-r from-cyan-400 to-indigo-400 bg-clip-text text-transparent"><?php echo $student_name; ?></span>
                </h1>
                
                <p class="text-slate-400 text-lg">
                    Estudiante de la carrera de <span class="text-white font-semibold"><?php echo $career; ?></span> en la ULEAM. Este es un proyecto PHP de demostración ejecutándose de forma segura en un contenedor gVisor aislado.
                </p>

                <!-- Technical Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 py-4">
                    <div class="bg-slate-950 p-4 rounded-2xl border border-slate-900">
                        <span class="text-slate-500 text-xs uppercase tracking-wider block">Hora del Servidor</span>
                        <span class="text-cyan-400 font-mono font-semibold text-sm"><?php echo $current_time; ?></span>
                    </div>
                    <div class="bg-slate-950 p-4 rounded-2xl border border-slate-900">
                        <span class="text-slate-500 text-xs uppercase tracking-wider block">Entorno de Ejecución</span>
                        <span class="text-green-400 font-semibold text-sm">PHP <?php echo phpversion(); ?> en Alpine Linux</span>
                    </div>
                </div>

                <!-- Skills Badge List -->
                <div>
                    <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Mis Habilidades</h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach($skills as $skill): ?>
                            <span class="px-3 py-1 bg-slate-950 border border-slate-850 rounded-xl text-xs text-slate-300 font-medium">
                                <?php echo $skill; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="border-t border-slate-900 bg-slate-950 py-8 text-center text-xs text-slate-500">
        <p>© <?php echo date('Y'); ?> Portafolio Académico. Desplegado en la plataforma ULEAM Academic.</p>
    </footer>
</body>
</html>
