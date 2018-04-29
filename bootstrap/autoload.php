<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/helpers.php';

use Symfony\Component\ClassLoader\MapClassLoader;
use Symfony\Component\ClassLoader\ApcClassLoader;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Zend\Config\Config;

define('base_path', dirname(__DIR__));
define('public_path', base_path.'/public');

//Determine the bootstrap/cache directory whether already exists or create it
$fs = new Filesystem();

if (!$fs->exists(__DIR__ .'/../bootstrap/cache')) {
    try {
        $fs->mkdir(__DIR__ .'/../bootstrap/cache');
    } catch (IOExceptionInterface $e) {
        echo "An error occurred while creating your directory at ". $e->getPath();
    }
}
//Generate namespace all files in app/Core/
ClassMapGenerator::dump([
    __DIR__ . '/../app/Core/Classes/',
    __DIR__ . '/../app/Core/Support/',
], __DIR__ . '/../bootstrap/cache/class_map.php');

$mapping = include base_path . '/bootstrap/cache/class_map.php';
$loader = new MapClassLoader($mapping);
$cachedLoader = new ApcClassLoader(sha1(__FILE__), $loader);
$cachedLoader->register();
$loader->register();

// Determine server environment and loading there config file
$env = $_SERVER['APP_ENV'];
$config = new Config(include base_path . '/config/app_'.$env.'.php');
// Start Database

use App\Core\Classes\Database;
$database = new Database($config->database->sqlsrv);

// Start Session
ob_start();
session_start();
