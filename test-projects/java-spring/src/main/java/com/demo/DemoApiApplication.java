package com.demo;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.Map;

@SpringBootApplication
@RestController
public class DemoApiApplication {

    public static void main(String[] args) {
        SpringApplication.run(DemoApiApplication.class, args);
    }

    @GetMapping("/")
    public Map<String, String> index() {
        return Map.of(
            "status", "ok",
            "message", "API Java Spring Boot corriendo en ULEAM Academic",
            "version", "1.0.0"
        );
    }

    @GetMapping("/api/saludo")
    public Map<String, String> saludo() {
        return Map.of(
            "mensaje", "¡Hola desde Spring Boot!",
            "plataforma", "ULEAM Academic PaaS"
        );
    }
}
