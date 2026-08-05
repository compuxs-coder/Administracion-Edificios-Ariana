<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class DDDStructure extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:ddd {context : The bounded context name (e.g., Pedido, Inventario)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates DDD folder structure for the given bounded context';

    /**
     * The directories to create for the DDD structure.
     *
     * @var array<string>
     */
    private array $directories = [
        'Application',
        'Application/Controllers',
        'Domain',
        'Domain/Entities',
        'Infrastructure',
        'Infrastructure/Mappers',
        'Infrastructure/Migrations',
        'Infrastructure/Models',
        'Infrastructure/Requests',
        'Infrastructure/Resources',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $context = Str::studly($this->argument('context'));
        $basePath = base_path('src/' . $context);

        if (File::exists($basePath)) {
            $this->error("The bounded context '{$context}' already exists at: {$basePath}");

            return Command::FAILURE;
        }

        $this->info("Creating DDD structure for bounded context: {$context}");
        $this->newLine();

        // Create all directories
        foreach ($this->directories as $directory) {
            $path = $basePath . '/' . $directory;
            File::makeDirectory($path, 0755, true, true);
            $this->line("  <fg=green>Created:</> {$path}");
        }

        $this->newLine();

        // Create route files
        $this->createRouteFiles($basePath, $context);

        $this->newLine();
        $this->info("Bounded context '{$context}' created successfully!");
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line("  1. Register your routes in the BoundedContextServiceProvider");
        $this->line("  2. Create your domain entities in src/{$context}/Domain/Entities/");
        $this->line("  3. Define repository contracts in src/{$context}/Domain/Contracts/");

        return Command::SUCCESS;
    }

    /**
     * Create the route files for the bounded context.
     */
    private function createRouteFiles(string $basePath, string $context): void
    {
        $apiContent = $this->getRouteFileContent($context, 'api');
        $webContent = $this->getRouteFileContent($context, 'web');

        File::put($basePath . '/api.php', $apiContent);
        $this->line("  <fg=green>Created:</> {$basePath}/api.php");

        File::put($basePath . '/web.php', $webContent);
        $this->line("  <fg=green>Created:</> {$basePath}/web.php");
    }

    /**
     * Get the content for a route file.
     */
    private function getRouteFileContent(string $context, string $type): string
    {
        $controllerExample = $type === 'api'
            ? "{$context}Controller"
            : "{$context}WebController";

        return <<<PHP
<?php

use Illuminate\Support\Facades\Route;
// use Src\\{$context}\\Application\\Controllers\\{$controllerExample};

// Example routes for {$context} bounded context
//
// API routes (api.php):
// Route::middleware('auth:sanctum')->group(function () {
//     Route::apiResource('{$this->getResourceName($context)}', {$controllerExample}::class);
// });
//
// Web routes (web.php):
// Route::middleware(['auth', 'verified'])->group(function () {
//     Route::resource('{$this->getResourceName($context)}', {$controllerExample}::class);
// });

PHP;
    }

    /**
     * Get the resource name from the context (pluralized, kebab-case).
     */
    private function getResourceName(string $context): string
    {
        return Str::plural(Str::kebab($context));
    }
}
