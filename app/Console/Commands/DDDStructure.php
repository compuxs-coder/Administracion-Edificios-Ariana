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
    protected $signature = 'make:ddd
                            {context : The bounded context name (e.g., Edificio, Cobranza)}
                            {--api : Create an API route file}
                            {--web : Create a web route file}';

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
        'Domain',
        'Infrastructure',
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

        if ($this->option('api')) {
            $this->createRouteFile($basePath, $context, 'api');
        }

        if ($this->option('web')) {
            $this->createRouteFile($basePath, $context, 'web');
        }

        $this->newLine();
        $this->info("Bounded context '{$context}' created successfully!");
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line('  1. Implement the first executable use case and its tests');
        $this->line('  2. Add the context to BoundedContextServiceProvider');
        $this->line('  3. Bind a repository only when a domain contract is required');

        return Command::SUCCESS;
    }

    /**
     * Create a requested route file for the bounded context.
     */
    private function createRouteFile(string $basePath, string $context, string $type): void
    {
        $path = $basePath . "/{$type}.php";

        File::put($path, $this->getRouteFileContent($context, $type));
        $this->line("  <fg=green>Created:</> {$path}");
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
