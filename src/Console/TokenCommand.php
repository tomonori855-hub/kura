<?php

namespace Kura\Console;

use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

class TokenCommand extends Command
{
    protected $signature = 'kura:token
        {--show   : Display the current token without generating a new one}
        {--force  : Overwrite the existing token without confirmation}';

    protected $description = 'Generate a secure Bearer token for the Kura warm endpoint';

    public function handle(): int
    {
        if ($this->option('show')) {
            /** @var string $current */
            $current = config('kura.warm.token', '');

            if ($current === '') {
                $this->warn('KURA_WARM_TOKEN is not set.');
            } else {
                $this->line($current);
            }

            return self::SUCCESS;
        }

        /** @var string $current */
        $current = config('kura.warm.token', '');

        if ($current !== '' && ! $this->option('force')) {
            if (! $this->confirm('KURA_WARM_TOKEN is already set. Overwrite?')) {
                $this->info('Aborted. Existing token kept.');

                return self::SUCCESS;
            }
        }

        $token = Str::random(64);

        if ($this->writeToEnv($token)) {
            $this->info('Token written to .env');
        } else {
            $this->warn('.env not found. Set this manually:');
        }

        $this->line('');
        $this->line("KURA_WARM_TOKEN={$token}");
        $this->line('');

        return self::SUCCESS;
    }

    private function writeToEnv(string $token): bool
    {
        /** @var Application $app */
        $app = $this->laravel;
        $envPath = $app->environmentFilePath();

        if (! file_exists($envPath)) {
            return false;
        }

        $contents = file_get_contents($envPath);

        if ($contents === false) {
            return false;
        }

        $key = 'KURA_WARM_TOKEN';

        if (str_contains($contents, $key.'=')) {
            // Replace existing value
            $replaced = preg_replace(
                '/^'.preg_quote($key, '/').'=.*$/m',
                $key.'='.$token,
                $contents,
            );

            if ($replaced === null) {
                return false;
            }

            $contents = $replaced;
        } else {
            // Append to end of file
            $contents = rtrim($contents)."\n{$key}={$token}\n";
        }

        if (file_put_contents($envPath, $contents) === false) {
            return false;
        }

        return true;
    }
}
