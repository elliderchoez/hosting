var builder = WebApplication.CreateBuilder(args);

// Escucha en el puerto 80 (requerido por la plataforma)
builder.WebHost.UseUrls("http://0.0.0.0:80");

var app = builder.Build();

app.MapGet("/", () => new {
    status = "ok",
    message = "API .NET 8 ASP.NET Core corriendo en ULEAM Academic",
    version = "1.0.0"
});

app.MapGet("/api/saludo", () => new {
    mensaje = "¡Hola desde ASP.NET Core!",
    plataforma = "ULEAM Academic PaaS"
});

app.Run();
