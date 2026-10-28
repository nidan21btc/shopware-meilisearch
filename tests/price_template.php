<?php declare(strict_types=1);
// Render the real Twig template with synthetic calculated prices, without a shop or network.
$root=getenv('PROJECT_ROOT')?:'';
if(!is_file($root.'/vendor/autoload.php'))throw new RuntimeException('Set PROJECT_ROOT to a Shopware installation.');
require $root.'/vendor/autoload.php';
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/src/Resources/views/storefront/search'));
$twig->addFilter(new Twig\TwigFilter('currency',static fn(float $value):string=>sprintf('EUR %.2f',$value)));
$twig->addFilter(new Twig\TwigFilter('sw_sanitize',static fn(string $value):string=>$value));
$twig->addFilter(new Twig\TwigFilter('trans',static function(string $key,array $parameters=[]):string{
 $snippets=['listing.listingTextFrom'=>'From','listing.beforeListPrice'=>'','listing.afterListPrice'=>'','detail.listPricePercentage'=>'Saving %price%%','general.listPricePreviously'=>'Previously %price%'];
 return strtr($snippets[$key]??$key,$parameters);
}));
$price=['unitPrice'=>8.0,'listPrice'=>['price'=>10.0,'percentage'=>20.0],'regulationPrice'=>['price'=>9.0],'referencePrice'=>null];
$product=['calculatedPrice'=>$price,'calculatedPrices'=>['count'=>0],'variantListingConfig'=>['displayParent'=>false],'parentId'=>null];
$html=$twig->render('price.html.twig',['product'=>$product]);
if(!str_contains($html,'EUR 8.00')||!str_contains($html,'<s>EUR 10.00</s>')||!str_contains($html,'Saving 20%')||!str_contains($html,'Previously EUR 9.00'))throw new RuntimeException('Discounted price information is incomplete.');
$product['calculatedPrices']=['count'=>2,'last'=>$price];
$html=$twig->render('price.html.twig',['product'=>$product]);
if(!str_contains($html,'From EUR 8.00')||str_contains($html,'<s>')||str_contains($html,'Saving 20%')||!str_contains($html,'Previously EUR 9.00'))throw new RuntimeException('Tier-price comparison is misleading.');
$product['calculatedPrices']=['count'=>0];$product['calculatedPrice']['regulationPrice']=null;
$html=$twig->render('price.html.twig',['product'=>$product]);
if(str_contains($html,'Previously'))throw new RuntimeException('A missing previous price was invented.');
echo "Discount, previous-price and tier-price template checks passed.\n";
