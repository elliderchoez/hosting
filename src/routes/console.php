<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('projects:auto-sleep')->everyMinute();
Schedule::command('projects:reset-databases')->dailyAt('02:00');

Artisan::command('projects:audit-tech', function () {
    $projects = \App\Models\Project::all();
    $detectFramework = app(\App\Actions\Docker\DetectFrameworkAction::class);
    
    foreach ($projects as $p) {
        $path = storage_path("app/projects/project-{$p->id}");
        $isJira = str_contains(strtolower($p->name), 'jira') || str_contains(strtolower($p->github_repo_url ?? ''), 'jira_clone');
        $isCalculator = str_contains(strtolower($p->name), 'calculator') || str_contains(strtolower($p->github_repo_url ?? ''), 'calculator');
        
        $oldFw = $p->framework;
        $oldLang = $p->language;
        
        if ($isJira) {
            $p->framework = 'react-node';
            $p->save();
            $this->info("[JIRA] #{$p->id} '{$p->name}' -> fw set to 'react-node' (was '$oldFw')");
        } elseif ($isCalculator) {
            $p->framework = 'react';
            $p->save();
            $this->info("[CALCULATOR] #{$p->id} '{$p->name}' -> fw set to 'react' (was '$oldFw')");
        } else {
            $this->line("[#{$p->id}] '{$p->name}' | lang: {$p->language} | fw: {$p->framework}");
        }
    }
})->purpose('Audit and fix project tech stacks');

