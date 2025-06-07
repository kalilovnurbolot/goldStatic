<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Http;
class ParseProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:parse-products';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse products kyrgyzGold';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = 'https://ru.kyrgyzaltyn.kg/gold_catalog/';
        $response = Http::get($url);

        if (!$response->ok()) {
            $this->error('Ошибка при получении данных.');
            return;
        }

        $crawler = new Crawler($response->body());

        // Извлекаем заголовки новостей (в зависимости от структуры сайта, селектор может измениться)
        $crawler->filter('.gold_item')->each(function (Crawler $node) {
            $titleNode = $node->filter('a.gold_item_title');
            $title = $titleNode->count() ? trim($titleNode->text()) : null;
            $link = $titleNode->count() ? $titleNode->attr('href') : null;

            $imageNode = $node->filter('a img');
            $image = $imageNode->count() ? $imageNode->attr('src') : null;

            $fullLink = $link && str_starts_with($link, 'http') ? $link : 'https://ru.kyrgyzaltyn.kg' . $link;
            $image = $image && !str_starts_with($image, 'http') ? 'https://ru.kyrgyzaltyn.kg' . $image : $image;



                // 📥 Загружаем страницу деталей
                $detailResponse = Http::get($fullLink);
                if (!$detailResponse->ok()) {
                    echo "Ошибка при загрузке деталей для: $title\n";
                    return;
                }

                $detailCrawler = new Crawler($detailResponse->body());

                $attributes = []; // Важно: объявить до цикла .each()

                $detailCrawler->filter('.actual_date2')->each(function (Crawler $node) use (&$attributes) {
                    $label = trim($node->text());
                    $value = $node->filter('span')->count() ? trim($node->filter('span')->text()) : null;

                    if (str_contains($label, 'Масса')) {
                        $attributes['weight'] = $value;
                    } elseif (str_contains($label, 'Ширина')) {
                        $attributes['height'] = $value;
                    } elseif (str_contains($label, 'Длина')) {
                        $attributes['width'] = $value;
                    } elseif (str_contains($label, 'Проба')) {
                        $attributes['sample'] = $value;
                    } elseif (str_contains($label, 'Металл')) {
                        $attributes['product_type'] = $value;
                    }
                });



                $node = $detailCrawler->filter('.news_view');
                $outerHtml = null;
                if ($node->count()) {
                    $domNode = $node->getNode(0);
                    $doc = new \DOMDocument();
                    $doc->appendChild($doc->importNode($domNode, true));
                    $outerHtml = $doc->saveHTML();
                }
            if ($title && !Product::where('title', $title)->exists()) {
                $product = new Product();
                $product->title =  $title;
                $product->image =  $image;
                $product->description =  $outerHtml;
                $product->weight = $attributes['weight'] ?? null;
                $product->width =  $attributes['width'] ?? null;
                $product->height = $attributes['height'] ?? null;
                $product->sample = $attributes['sample'] ?? null;
                $product->product_type = $attributes['product_type'] ?? null;
                $product->save();
            }
            else{
                $product = Product::where('title', $title)->first();
            }
                $Price = [];

                $detailCrawler->filter('.actual_price')->each(function (Crawler $node) use (&$Price) {
                    $label = trim($node->text());
                    $value = $node->filter('span')->count() ? trim($node->filter('span')->text()) : null;

                    if (str_contains($label, 'Цена обратного выкупа')) {
                        $Price['repurchase_price'] = $value;
                    } elseif (str_contains($label, 'Цена продажи')) {
                        $Price['selling_price'] = $value;
                    } elseif (str_contains($label, 'Цена действительна на дату')) {
                        $Price['to'] = $value;
                    }
                });

                $productPrice = new ProductPrice();
                $productPrice->product_id = $product->id;
                $productPrice->selling_price = $Price['selling_price'] ?? 0;
                $productPrice->repurchase_price = $Price['repurchase_price'] ?? 0;
                $productPrice->to =  $Price['to'] ?? null;
                $productPrice->save();

                echo "Сохранено: $title\n";

        });


        $this->info('Парсинг завершен.');

    }
}
