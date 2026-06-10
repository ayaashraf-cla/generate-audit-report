<?php

namespace Cla\GenerateAuditReport;

use Cla\GenerateAuditReport\AuditReportBuilder;
use Cla\GenerateAuditReport\Facades\GenerateAuditReport;
use Cla\GenerateAuditReport\Http\Livewire\AuditProgress;
use Cla\GenerateAuditReport\Observers\DocumentObserver;
use Cla\GenerateAuditReport\Services\Document\Analyzers\CsvFileAnalyzer;
use Cla\GenerateAuditReport\Services\Document\Analyzers\XlsxFileAnalyzer;
use Cla\GenerateAuditReport\Services\Document\DocumentProfiler;
use Cla\GenerateAuditReport\Services\Document\FileTypeDetector;
use Cla\GenerateAuditReport\Services\Document\Summarizers\StructuredFileSummarizer;
use Cla\GenerateAuditReport\Services\Document\Summarizers\UnstructuredFileSummarizer;
use AyaAshraf\LaravelRag\Models\Document;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class GenerateAuditReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/audit.php', 'audit');

        $this->app->bind(AuditReportBuilder::class, AuditReportBuilder::class);

        $this->app->booting(function () {
            $loader = \Illuminate\Foundation\AliasLoader::getInstance();
            $loader->alias('GenerateAuditReport', GenerateAuditReport::class);
        });

        $this->app->singleton(DocumentProfiler::class, function ($app) {
            return new DocumentProfiler(
                detector: $app->make(FileTypeDetector::class),
                analyzers: [
                    $app->make(CsvFileAnalyzer::class),
                    $app->make(XlsxFileAnalyzer::class),
                ],
                summarizers: [
                    $app->make(StructuredFileSummarizer::class),
                    $app->make(UnstructuredFileSummarizer::class),
                ],
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/audit.php' => config_path('audit.php'),
            ], 'audit-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'audit-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/generate-audit-report'),
            ], 'audit-views');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'generate-audit-report');

        if (class_exists(Document::class)) {
            Document::observe(DocumentObserver::class);
        }

        if (class_exists(Livewire::class)) {
            Livewire::component('generate-audit-report::audit-progress', AuditProgress::class);
        }
    }
}
