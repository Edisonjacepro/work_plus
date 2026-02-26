<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    $projectDir = dirname(__DIR__);
    $dotenvPath = $projectDir.'/.env';
    if (!is_file($dotenvPath)) {
        $dotenvPath = $projectDir.'/.env.dist';
    }

    if (is_file($dotenvPath)) {
        (new Dotenv())->bootEnv($dotenvPath);
    }
}

if ((bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false)) {
    umask(0000);
}
