import React, { useState, useEffect } from 'react';

const PROGRAMMING_QUOTES = [
    {
        quote: "«En mi máquina sí funcionaba... por eso ahora inventamos contenedores».",
        author: "Filosofía DevOps & Docker"
    },
    {
        quote: "«No es un bug, es una característica no documentada».",
        author: "Programador Anónimo"
    },
    {
        quote: "«Hay 10 tipos de personas en el mundo: las que entienden binario y las que no».",
        author: "Clásico de la Informática"
    },
    {
        quote: "«Primero resuelve el problema. Luego, escribe el código».",
        author: "John Johnson"
    },
    {
        quote: "«Cualquier tonto puede escribir código que un ordenador entienda. Los buenos programadores escriben código que los humanos entienden».",
        author: "Martin Fowler"
    },
    {
        quote: "«99 bugs en el código, arreglas uno, compilas... 127 bugs en el código».",
        author: "Viernes 5:00 PM"
    },
    {
        quote: "«Medir el progreso del software por líneas de código es como medir el avance de un avión por su peso».",
        author: "Bill Gates"
    },
    {
        quote: "«Café: sustancia mágica que convierte cafeína en software funcional».",
        author: "Tradición Developer"
    },
    {
        quote: "«El código es como el humor: si tienes que explicarlo, es malo».",
        author: "Cory House"
    },
    {
        quote: "«La paciencia y un buen console.log resuelven el 99% de los misterios del universo».",
        author: "Proverbio de Debugging"
    },
    {
        quote: "«Un buen programador siempre mira a ambos lados antes de cruzar una calle de sentido único».",
        author: "Doug Linder"
    },
    {
        quote: "«La simplicidad es el requisito previo para la fiabilidad».",
        author: "Edsger W. Dijkstra"
    }
];

export default function DemoLoadingAnimation() {
    const [progress, setProgress] = useState(6);
    const [quoteIndex, setQuoteIndex] = useState(0);
    const [fade, setFade] = useState(true);

    // Simulación del progreso de chunks de Minecraft (0% a 95%)
    useEffect(() => {
        const timer = setInterval(() => {
            setProgress((prev) => {
                if (prev >= 95) return 95;
                const delta = Math.floor(Math.random() * 6) + 3;
                return Math.min(prev + delta, 95);
            });
        }, 600);

        return () => clearInterval(timer);
    }, []);

    // Rotación de frases cada 4 segundos
    useEffect(() => {
        const interval = setInterval(() => {
            setFade(false);
            setTimeout(() => {
                setQuoteIndex((prev) => (prev + 1) % PROGRAMMING_QUOTES.length);
                setFade(true);
            }, 300);
        }, 4000);

        return () => clearInterval(interval);
    }, []);

    const activeQuote = PROGRAMMING_QUOTES[quoteIndex];

    return (
        <div className="w-full h-full flex flex-col items-center justify-center p-6 text-center select-none relative overflow-hidden bg-slate-950">
            <div className="relative z-10 flex flex-col items-center max-w-lg w-full">

                {/* Texto del porcentaje estilo Minecraft */}
                <div 
                    className="text-xl md:text-2xl font-mono font-bold text-white mb-3 tracking-wider"
                    style={{
                        textShadow: '2px 2px 0px #3f3f3f, 3px 3px 0px #111827'
                    }}
                >
                    {progress}%
                </div>

                {/* Cuadrado de Generación de Chunks estilo Minecraft */}
                <div 
                    className="w-44 h-44 md:w-52 md:h-52 bg-black p-4 flex items-center justify-center shadow-2xl relative"
                    style={{
                        imageRendering: 'pixelated'
                    }}
                >
                    {/* Fondo Gris del mapa no generado (#8c8c8c) */}
                    <div className="w-full h-full bg-[#8c8c8c] relative flex items-center justify-center overflow-hidden">
                        {/* Esquinas recortadas estilo pixel Minecraft */}
                        <div className="absolute top-0 left-0 w-2 h-2 bg-black"></div>
                        <div className="absolute bottom-0 right-0 w-2 h-2 bg-black"></div>

                        {/* Región de Chunks Generados que crece desde el centro con el porcentaje */}
                        <div 
                            className="transition-all duration-300 ease-out flex items-center justify-center relative"
                            style={{
                                width: `${Math.max(12, Math.min(100, progress))}%`,
                                height: `${Math.max(12, Math.min(100, progress))}%`
                            }}
                        >
                            {/* Borde exterior Verde Lima de chunk (#6cb51b) */}
                            <div className="w-full h-full bg-[#6cb51b] p-[3px] flex items-center justify-center">
                                {/* Borde interior Azul marino (#282b78) */}
                                <div className="w-full h-full bg-[#282b78] p-[3px] flex items-center justify-center">
                                    {/* Centro Blanco puro (#ffffff) */}
                                    <div className="w-full h-full bg-white"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Frase popular y autor solitos */}
                <div className="w-full mt-8 min-h-[85px] flex flex-col items-center justify-center">
                    <div className={`transition-opacity duration-300 max-w-md ${fade ? 'opacity-100' : 'opacity-0'}`}>
                        <p className="text-sm md:text-base text-slate-300 font-normal italic leading-relaxed">
                            {activeQuote.quote}
                        </p>
                        <p className="text-xs font-mono text-cyan-400 font-medium mt-2.5">
                            — {activeQuote.author}
                        </p>
                    </div>
                </div>

            </div>
        </div>
    );
}
