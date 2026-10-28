<?php
// Standalone bootstrap: the tested classes (Model/Document, Model/Api/QueryBuilder, Model/Queue/Publisher) do not need
// a Magento installation. Run `composer install` in this folder, or point to any autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'WarpSearch\\Search\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
}
