<?php
declare(strict_types=1);
namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Shopify\ApiVersion;
use Shopify\Auth\FileSessionStorage;
use Shopify\Auth\Session;
use Shopify\Context;
use Shopify\Rest\Admin2023_07\Product;
use Shopify\Rest\Admin2023_07\Variant;
use Shopify\Rest\Admin2023_07\Location;
use Shopify\Rest\Admin2023_07\InventoryLevel;
use Shopify\Rest\Admin2023_07\SmartCollection;
use Shopify\Rest\Admin2023_07\Image;

class ShopifyDataController extends AbstractController
{


    #[Route('/', name: 'post_data')]
    public function getData(Request $request): JsonResponse
    {
        if (!$request->get('totalPrice')) {
            die('empty');
        }

        if (!$request->get('type')) {
            die('empty');
        }

        $totalPrice = $request->get('totalPrice');
        $type = $request->get('type');
        $searchStatus = '';
        $session = $this->prepareConnection();

        list($productIdList, $returnId, $returnVariantId, $searchStatus) = $this->prepareProductList($session, $totalPrice, $searchStatus, $type);

        if (!$returnId) {
            return new JsonResponse($this->createNewVariant($productIdList, $session, $totalPrice, $type));
        }

        return new JsonResponse(['search_msg' => $searchStatus,'product_id' => $returnId, 'variant_id' => $returnVariantId]);
    }

    /**
     * @param Product $product
     * @param mixed $totalPrice
     * @return array
     */
    public function checkProduct(Product $product, mixed $totalPrice): array
    {
        $response = [];
        $counter = 0;
        $response['search_status'] = false;
        foreach ($product->variants as $variant) {
            $counter++;
            $response['product_id'] = $variant->product_id;
            if ($variant->price == $totalPrice) {
                $response['search_status'] = true;
                $response['variant_id'] = $variant->id;
                $response['variant_price'] = $variant->price;
            }
        }

        $response['variant_count'] = $counter;

        return $response;
    }

    /**
     * @param array $productIdList
     * @param Session $session
     * @param mixed $totalPrice
     * @param string $type
     * @return array
     * @throws \Shopify\Exception\RestResourceException
     */
    public function createNewVariant(array $productIdList, Session $session, mixed $totalPrice, string $type): array
    {
        foreach ($productIdList as $item) {
            if ((int)$item['variant_count'] > 99) {
                continue;
            }

            $variants = Variant::all(
                $session,
                ["product_id" => $item['product_id']],
                ['limit'=>150],
            );
            $variantNames = [];
            foreach($variants as $variant){
                $variantNames[] = $variant->option1;
            }

            for($i=1;$i<101;$i++){
                $suggestedNewName = 'custom bundle' . $i;
                if(!in_array($suggestedNewName,$variantNames)){
                    break;
                }
            }

            $variant = new Variant($session);
            $variant->product_id = $item['product_id'];
            $variant->option1 = $suggestedNewName;
            $variant->price = $totalPrice;
            $variant->inventory_quantity = 99999;
            $variant->save(
                true,
            );

            $this->updateInventoryQuantity($session, $variant);

            return [
                'search_msg' => 'Variant with search price created.',
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id
            ];
        }

        if (!$productIdList) {
            return ['search_msg' => 'No product of this type found.'];
        }

        $newProduct = $this->createNewParentProduct($totalPrice, $type);
        return [
            'search_msg' => 'New product and variant with search price created.',
            'product_id' => $newProduct['product_id'],
            'variant_id' => $newProduct['variant_id']
        ];
    }

    /**
     * @return Session
     * @throws \Shopify\Exception\MissingArgumentException
     */
    public function prepareConnection(): Session
    {
        Context::initialize(
            apiKey: $this->getParameter('shopify.api_key'),
            apiSecretKey: $this->getParameter('shopify.api_secret_key'),
            scopes: ['NA'],
            hostName: 'NA',
            sessionStorage: new FileSessionStorage(),
            apiVersion: ApiVersion::JULY_2023,
            isEmbeddedApp: false,
        );

        $session = new Session(
            id: 'NA',
            shop: $this->getParameter('shopify.shop'),
            isOnline: false,
            state: 'NA'
        );

        $session->setAccessToken($this->getParameter('shopify.api_token'));

        return $session;
    }

    /**
     * @param Session $session
     * @param mixed $totalPrice
     * @param string $searchStatus
     * @param string $type
     * @return array
     */
    public function prepareProductList(Session $session, mixed $totalPrice, string $searchStatus, string $type): array
    {
        $products = Product::all(
            $session,
            [],
            ["product_type" => $type]
        );

        $productIdList = [];
        $returnId = 0;
        $returnVariantId = 0;
        foreach ($products as $product) {
            if (!$product) {
                continue;
            }

            $checkProduct = $this->checkProduct($product, $totalPrice);
            $productIdList[] = ['product_id' => $checkProduct['product_id'], 'variant_count' => $checkProduct['variant_count']];

            if (!$checkProduct['search_status']) {
                continue;
            }

            $returnId = $checkProduct['product_id'];
            $returnVariantId = $checkProduct['variant_id'];
            $searchStatus = 'Variant with search price exist.';
        }

        return array($productIdList, $returnId, $returnVariantId, $searchStatus);
    }

    /**
     * @param Session $session
     * @param Variant $variant
     * @return void
     * @throws \Shopify\Exception\RestResourceException
     */
    public function updateInventoryQuantity(Session $session, Variant $variant): void
    {
        $location = Location::all(
            $session,
            [],
            []
        );

        $inventory_level = new InventoryLevel($session);
        $inventory_level->adjust(
            [],
            ["location_id" => $location[0]->id, "inventory_item_id" => $variant->inventory_item_id, "available_adjustment" => 99999]
        );
    }

    /**
     * @param mixed $totalPrice
     * @param string $type
     * @return array
     * @throws \Shopify\Exception\MissingArgumentException
     * @throws \Shopify\Exception\RestResourceException
     */
    public function createNewParentProduct(mixed $totalPrice, string $type): array
    {
        $session = $this->prepareConnection();

        $title = 'Macarons - without type';
        $description = '';
        $imageSrc = 'https://customorders-dev.cdngmc.dev/default.jpg';

        if ($type === 'box') {
            $title = 'Macarons - Eigener Mix';
            $description = '<p>Macarons online kaufen: Entdecke die süße Verführung!</p>
<p>Möchtest Du die köstlichen Aromen und die zarte Textur von Macarons genießen, ohne Dein Zuhause zu verlassen? Dann bist Du bei uns genau richtig. Hier kannst Du Macarons online kaufen und Dich in die Welt der französischen Gebäckkunst entführen lassen.</p>
<p>Unsere Auswahl an Macarons ist vielfältig und verlockend. Von klassischen Geschmacksrichtungen wie Erdbeere, Schokolade und Vanille bis hin zu exotischen Variationen wie Lavendel oder Passionsfrucht – wir bieten Dir eine breite Palette an Möglichkeiten, um Deinen Gaumen zu verwöhnen.</p>
<p>Warum solltest Du Macarons online kaufen?</p>
<ol>
<li>
<p>Bequemlichkeit: Einkaufen war noch nie so einfach. Du kannst unsere Macarons bequem von zu Hause aus bestellen und Dir die Anfahrt zum Geschäft sparen.</p>
</li>
<li>
<p>Frische Garantie: Unsere Macarons werden sorgfältig zubereitet und direkt zu Dir nach Hause geliefert, um sicherzustellen, dass Du stets frische und leckere Macarons genießen kannst.</p>
</li>
<li>
<p>Vielfältige Auswahl: Unser Sortiment umfasst eine breite Auswahl an Geschmacksrichtungen und Farben, sodass für jeden Geschmack etwas dabei ist.</p>
</li>
<li>
<p>Geschenkidee: Macarons sind auch eine wunderbare Geschenkidee. Überrasche Deine Liebsten mit einer köstlichen Box Macarons zu besonderen Anlässen.</p>
</li>
</ol>
<p>Stöbere in unserem <strong><span style="text-decoration: underline;"><a title="Macarons online bestellen" href="https://dusucre-macarons.de/pages/produkt-konfigurator">Online-Shop</a></span></strong> und lass Dich von der Vielfalt unserer Macarons verführen. <a title="Macarons online kaufen" href="https://dusucre-macarons.de/pages/produkt-konfigurator">Mit <strong><span style="text-decoration: underline;">nur wenigen Klicks</span></strong></a> kannst Du Macarons online kaufen und Dir eine süße Auszeit gönnen. Guten Appetit!</p>
<p>&nbsp;</p>';
            $imageSrc = 'https://customorders-dev.cdngmc.dev/macarons-eigener-mix.jpg';
        }

        if ($type === 'print') {
            $title = 'Macarons - Personalisierung';
            $description = '';
            $imageSrc = 'https://customorders-dev.cdngmc.dev/macarons-personalisierung.jpg';
        }

        $product = new Product($session);
        $product->title = $title;
        $product->body_html = $description;
        $product->product_type = $type;
        $product->status = "active";
        $product->save(
            true,
        );

        $this->updateProductImage($session, $product, $imageSrc);

        if ($this->getParameter('shopify.smart_collection_id')) {
            $this->addProductToCollection($session, $product);
        }

        $productIdList[] = ['product_id' => $product->id, 'variant_count' => 0];
        $newProduct = $this->createNewVariant($productIdList, $session, $totalPrice, $type);

        return ['product_id' => $newProduct['product_id'], 'variant_id' => $newProduct['variant_id']];
    }

    /**
     * @param Session $session
     * @param Product $product
     * @return void
     * @throws \Shopify\Exception\RestResourceException
     */
    public function addProductToCollection(Session $session, Product $product): void
    {
        $smart_collection = new SmartCollection($session);
        $smart_collection->id = $this->getParameter('shopify.smart_collection_id');
        $smart_collection->product_id = $product->id;
        $smart_collection->save(
            true,
        );
    }

    /**
     * @param Session $session
     * @param Product $product
     * @param string $imageSrc
     * @return void
     * @throws \Shopify\Exception\RestResourceException
     */
    public function updateProductImage(Session $session, Product $product, string $imageSrc): void
    {
        $image = new Image($session);
        $image->product_id = $product->id;
        $image->src = $imageSrc;
        $image->save(
            true
        );
    }
}