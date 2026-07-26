const express = require('express');
const app = express();
const port = process.env.PORT || 3000;

app.get('/', (req, res) => {
  const current_time = new Date().toISOString();
  const uptime = process.uptime();
  const memoryUsage = process.memoryUsage();
  const formattedMemory = {
    rss: (memoryUsage.rss / 1024 / 1024).toFixed(2) + ' MB',
    heapTotal: (memoryUsage.heapTotal / 1024 / 1024).toFixed(2) + ' MB',
    heapUsed: (memoryUsage.heapUsed / 1024 / 1024).toFixed(2) + ' MB',
  };

  res.send(`
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Express Demo - ULEAM Academic</title>
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
            <span class="text-indigo-400 text-2xl">⚡</span>
            <span class="font-bold text-lg bg-gradient-to-r from-cyan-400 to-indigo-400 bg-clip-text text-transparent">Node.js Express Demo</span>
          </div>
          <div class="text-xs text-green-400 bg-slate-900 px-3 py-1.5 rounded-full border border-slate-800 flex items-center gap-1.5">
            <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
            Online (Puerto ${port})
          </div>
        </div>
      </nav>

      <!-- Main Content -->
      <main class="max-w-4xl mx-auto px-4 py-16 flex-1 flex flex-col justify-center w-full">
        <div class="bg-slate-900/40 border border-slate-900 rounded-3xl p-8 md:p-12 shadow-2xl backdrop-blur-md relative overflow-hidden">
          <div class="absolute -top-24 -left-24 w-48 h-48 bg-cyan-500/10 blur-[80px] rounded-full"></div>
          <div class="absolute -bottom-24 -right-24 w-48 h-48 bg-indigo-500/10 blur-[80px] rounded-full"></div>

          <div class="relative z-10 space-y-6">
            <div class="inline-block px-3 py-1 bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-xs font-semibold rounded-full">
              Aplicación de Node.js Activa
            </div>
            
            <h1 class="text-4xl md:text-5xl font-bold tracking-tight text-white leading-tight">
              Monitoreo del Servidor <span class="bg-gradient-to-r from-cyan-400 to-indigo-400 bg-clip-text text-transparent">Express</span>
            </h1>
            
            <p class="text-slate-400 text-base">
              Este es un servidor dinámico de Express desplegado y compilado automáticamente por el PaaS Académico. Abajo se muestran métricas en tiempo real obtenidas desde el proceso de Node.js:
            </p>

            <!-- Server Metrics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
              <div class="bg-slate-950 p-4 rounded-2xl border border-slate-900">
                <span class="text-slate-500 text-xs uppercase tracking-wider block">Tiempo Activo (Uptime)</span>
                <span class="text-cyan-400 font-mono font-semibold text-base">${uptime.toFixed(1)} segundos</span>
              </div>
              <div class="bg-slate-950 p-4 rounded-2xl border border-slate-900">
                <span class="text-slate-500 text-xs uppercase tracking-wider block">Memoria Asignada (RSS)</span>
                <span class="text-indigo-400 font-mono font-semibold text-base">${formattedMemory.rss}</span>
              </div>
              <div class="bg-slate-950 p-4 rounded-2xl border border-slate-900">
                <span class="text-slate-500 text-xs uppercase tracking-wider block">Versión de Node.js</span>
                <span class="text-green-400 font-mono font-semibold text-base">${process.version}</span>
              </div>
            </div>

            <!-- Heap Details -->
            <div class="bg-slate-950/50 p-5 rounded-2xl border border-slate-900 space-y-2">
              <h3 className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Detalles de Memoria Heap</h3>
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span class="text-slate-500 text-xs block">Heap Total</span>
                  <span class="text-slate-300 font-mono font-medium">${formattedMemory.heapTotal}</span>
                </div>
                <div>
                  <span class="text-slate-500 text-xs block">Heap Usado</span>
                  <span class="text-slate-300 font-mono font-medium">${formattedMemory.heapUsed}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>

      <!-- Footer -->
      <footer class="border-t border-slate-900 bg-slate-950 py-8 text-center text-xs text-slate-500">
        <p>© ${new Date().getFullYear()} Node.js Metrics. Desplegado en la plataforma ULEAM Academic.</p>
      </footer>
    </body>
    </html>
  `);
});

app.listen(port, () => {
  console.log(`Servidor Express corriendo en http://localhost:\${port}`);
});
