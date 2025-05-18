<?php

namespace App\Console\Commands\Environment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class HorizonWorkerServiceCommand extends Command
{
    protected $description = 'Create the service for the queue worker.';

    protected $signature = 'p:environment:horizon-service
        {--service-name= : Name of the Horizon worker service.}
        {--user= : The user that PHP runs under.}
        {--group= : The group that PHP runs under.}
        {--overwrite : Force overwrite if the service file already exists.}';

    public function handle(): void
    {
        $serviceName = $this->option('service-name') ?? $this->ask('Horizon worker service name', 'horizon');
        $path = '/etc/systemd/system/' . $serviceName  . '.service';

        $fileExists = @file_exists($path);
        if ($fileExists && !$this->option('overwrite') && !$this->confirm('The service file already exists. Do you want to overwrite it?')) {
            $this->line('Creation of queue worker service file aborted because service file already exists.');

            return;
        }

        $user = $this->option('user') ?? $this->ask('Webserver User', 'www-data');
        $group = $this->option('group') ?? $this->ask('Webserver Group', 'www-data');

        $redisUsed = config('queue.default') === 'redis' || config('session.driver') === 'redis' || config('cache.default') === 'redis';
        $afterRedis = $redisUsed ? '
After=valkey-server.service' : '';

        $basePath = base_path();

        $success = File::put($path, "# Pelican Horizon File
# ----------------------------------

[Unit]
Description=Pelican Horizon Service$afterRedis

[Service]
User=$user
Group=$group
Restart=always
ExecStart=/usr/bin/php $basePath/artisan horizon
StartLimitInterval=180
StartLimitBurst=30

[Install]
WantedBy=multi-user.target
        ");

        if (!$success) {
            $this->error('Error creating service file');

            return;
        }

        if ($fileExists) {
            $result = Process::run("systemctl restart $serviceName.service");
            if ($result->failed()) {
                $this->error('Error restarting service: ' . $result->errorOutput());

                return;
            }

            $this->line('Horizon worker service file updated successfully.');
        } else {
            $result = Process::run("systemctl enable --now $serviceName.service");
            if ($result->failed()) {
                $this->error('Error enabling service: ' . $result->errorOutput());

                return;
            }

            $this->line('Horizon worker service file created successfully.');
        }
    }
}
