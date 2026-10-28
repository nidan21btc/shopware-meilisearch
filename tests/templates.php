<?php declare(strict_types=1);

// Syntax check with Shopware's real token parsers. Does not boot the shop or
// claim to test its theme inheritance, translations or contextual rendering.
require (getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/autoload.php';

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Adapter\Twig\TemplateScopeDetector;
use Shopware\Core\Framework\Adapter\Twig\TokenParser\ExtendsTokenParser;
use Shopware\Core\Framework\Adapter\Twig\TokenParser\IncludeTokenParser;
use Shopware\Storefront\Framework\Twig\TokenParser\IconTokenParser;
use Shopware\Storefront\Framework\Twig\TokenParser\ThumbnailTokenParser;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

$views = dirname(__DIR__) . '/src/Resources/views';
$loader = new FilesystemLoader();
$loader->addPath($views, 'ShopwareMeilisearch');
$loader->addPath((getenv('SHOPWARE_ROOT') ?: dirname(__DIR__, 4)) . '/vendor/shopware/storefront/Resources/views', 'Storefront');
$twig = new Environment($loader);
$finder = new class extends TemplateFinder {
    public function __construct() {}
    public function find(string $template, $ignoreMissing = false, ?string $source = null): string { return $template; }
};
$twig->addTokenParser(new ExtendsTokenParser($finder, new TemplateScopeDetector(new RequestStack())));
$twig->addTokenParser(new IncludeTokenParser($finder));
$twig->addTokenParser(new IconTokenParser());
$twig->addTokenParser(new ThumbnailTokenParser());
foreach (['trans', 'sw_sanitize', 'sw_encode_url', 'currency'] as $filter) {
    $twig->addFilter(new TwigFilter($filter, static fn ($value) => $value));
}
foreach (['config', 'path', 'theme_config', 'asset', 'seoUrl'] as $function) {
    $twig->addFunction(new TwigFunction($function, static fn (...$args) => ''));
}
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views)) as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
        $name = '@ShopwareMeilisearch/' . substr($file->getPathname(), strlen($views) + 1);
        $twig->compileSource($loader->getSourceContext($name));
        ++$count;
    }
}
echo "$count Twig templates compiled with Shopware token parsers.\n";
