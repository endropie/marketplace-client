<?php

use Illuminate\Support\Facades\Route;
use Virmata\MarketplaceClient\Middleware\ForceJsonResponse;
use Virmata\MarketplaceClient\Tools\Shopee;
use Virmata\MarketplaceClient\Tools\Tokopedia;

use function Symfony\Component\String\s;

Route::get('/', function () {
    return response()->json([
        'version' => '1.0.0',
        'status' => 'Marketplace Client is running.',
    ]);
});

## SHOPEE
Route::group(['prefix' => 'shopee'], function (\Illuminate\Routing\Router $router) {
    $router->get("/auth", function () use ($router) {

        $uri = str($router->current()->uri)->replace('/auth', '/auth-callback')->toString();
        $link = http_build_query([
            'response_type' => 'code',
            'auth_type' => 'seller',
            'partner_id' => env('MARKETPLACE_SHOPEE_APPID', ''),
            'redirect_uri' => env('APP_URL', 'http://localhost') . "/$uri",
        ]);

        $authURL = app()->environment('local', 'testing', 'staging')
            ? "https://open.sandbox.test-stable.shopee.com/auth"
            :  "https://open.shopee.com/auth";

        return redirect()->away("$authURL?$link");
    });

    $router->get("/auth-callback", function (\Illuminate\Http\Request $request) {
        $request->validate([
            'code' => 'required|string',
            'shop_id' => 'required|string',
        ]);

        $response = Shopee::getToken($request->get('code'), $request->get('shop_id'));
        $auth = $response->json();

        $shop = Shopee::getInfo($auth['access_token'], $request->get('shop_id'));

        $model = app(config('marketplace.model'))->firstOrNew([
            'code' => $request->get('shop_id'),
            'via' => 'shopee',
        ]);

        $model->name = $shop['shop_name'];

        $model->payload = Shopee::createPayload($dtoken = [
            'shop_id' => $request->get('shop_id'),
            'access_token' => $auth['access_token'],
            'refresh_token' => $auth['refresh_token'],
            'refresh_at' => now()->addSeconds(intval($auth['expire_in']) - 300),
        ]);

        $model->save();

        return view('marketplace-client::callback', [
            'id' => $model->id,
            'name' => $model->name,
            'via' => $model->via,
            'payload' => $model->payload,
        ]);
    });
});

## TOKOPEDIA
Route::group(['prefix' => 'tokopedia'], function (\Illuminate\Routing\Router $router) {
    $router->get("/auth", function () use ($router) {

        $link = http_build_query([
            'service_id' => env('MARKETPLACE_TOKOPEDIA_SERVICE', ''),
        ]);

        $authURL = "https://services.tiktokshop.com/open/authorize";
        return redirect()->away("$authURL?$link");
    });

    $router->get("/auth-callback", function (\Illuminate\Http\Request $request) {
        $request->validate([
            'code' => 'required|string',
        ]);

        $response = Tokopedia::getToken($request->get('code'));
        $auth = $response->json('data');

        $response = Tokopedia::getShops($auth['access_token']);
        $shops = $response->json('data.shops');

        $shop = collect($shops)->first();

        $model = app(config('marketplace.model'))->firstOrNew([
            'code' => $shop['id'],
            'via' => 'tokopedia',
        ]);

        $model->name = $shop['name'];
        $model->via = 'tokopedia';
        $model->payload = Tokopedia::createPayload([
            'cipher' => $shop['cipher'],
            'access_token' => $auth['access_token'],
            'refresh_token' => $auth['refresh_token'],
            'refresh_at' => now()->setTimestamp(intval($auth['access_token_expire_in']) - 300),
        ]);

        $model->save();

        return view('marketplace-client::callback', [
            'id' => $model->id,
            'name' => $model->name,
            'via' => $model->via,
            'payload' => $model->payload,
        ]);
    });
});

Route::group(['prefix' => 'store', 'middleware' => [ForceJsonResponse::class]], function (\Illuminate\Routing\Router $router) {

    ## ORDERS
    $router->post("{mid}/orders/shipping", function (\Illuminate\Http\Request $request) {
        $request->validate([
            'via' => 'required|string',
        ]);

        switch ($request->get('via')) {
            case 'shopee':
                $request->validate([
                    'sn' => 'required|string',
                    'address_id' => 'required',
                    'pickup_time_id' => 'required',
                ]);


                $sn = $request->get("sn");
                $addressId = $request->get("address_id");
                $pickupTimeId = $request->get("pickup_time_id");
                $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));

                $response = $marketplace->client->api()->post('/api/v2/logistics/ship_order', [
                    "order_sn" => $sn,
                    "pickup" => [
                        "address_id" => intval($addressId),
                        "pickup_time_id" => strval($pickupTimeId),
                    ],
                ]);

                return [
                    "success" => $response->successful(),
                    "message" => $response->json('message') ?? "Successfully set order [{$sn}] to shipping.",
                ];

            case 'tokopedia':
                $request->validate([
                    'package_id' => 'required',
                ]);

                $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));

                $parameters = $request->only(['handover_method', 'pickup_slot', 'self_shipment']);

                $response = $marketplace->client->api()->post("/fulfillment/202309/packages/{$request->get('package_id')}/ship", $parameters);

                return [
                    "success" => $response->successful(),
                    "message" => $response->json('message') ?? "Successfully set package [{$request->get('package_id')}] to shipped.",
                ];

                break;
            default:
                return response()->json([
                    'error' => 'Unsupported marketplace [via]',
                ], 400);
        }

    });

    $router->get("{mid}/orders/{sn}", function (\Illuminate\Http\Request $request) {
        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));
        return $marketplace->getMarketplaceOrderDetail($request->all());
    });

    $router->get("{mid}/orders", function (\Illuminate\Http\Request $request) {

        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));
        return $marketplace->getMarketplaceOrder($request->all());
    });

    ## PRODUCTS
    $router->get("{mid}/sync-products", function (\Illuminate\Http\Request $request) {
        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));
        return app(config('marketplace.product_model'))->syncProductMarketplace($marketplace);
    });

    $router->get("{mid}/products/{id}", function (\Illuminate\Http\Request $request) {

        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));

        return $marketplace->client->request('product.detail', [
            'id' => $request->route('id'),
        ]);

        return $response->json('response.item');
    });

    $router->get("{mid}/products", function (\Illuminate\Http\Request $request) {

        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));

        $response = $marketplace->client->request('product.list', [
            'limit' => 50,
            'offset' => 0,
        ]);

        return $response->json();
    });

    $router->get("{mid}/products/{id}/variants", function (\Illuminate\Http\Request $request) {

        $marketplace = app(config('marketplace.model'))->findOrFail($request->route('mid'));

        $response = $marketplace->client->request('product.variant.list', [
            'id' => $request->route('id'),
            // 'limit' => 50,
            // 'offset' => 0,
        ]);

        return $response->json();
    });
});




