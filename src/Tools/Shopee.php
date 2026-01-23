<?php

namespace Virmata\MarketplaceClient\Tools;

use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Virmata\MarketplaceClient\Contracts\ClientInterface;
use Virmata\MarketplaceClient\Models\Marketplace;

class Shopee implements ClientInterface
{
    protected Marketplace $marketplace;

    function __construct(string|int $id)
    {
        $this->marketplace = app(config('marketplace.model'))->findOrFail($id);
    }

    public  function getPayload(...$keys): array|string|null
    {
        $payload = json_decode(decrypt($this->marketplace->payload), true);
        if(empty($keys)) return $payload;
        if(count($keys) == 1) {
            return $payload[$keys[0]] ?? null;
        }
        return array_filter($payload, function($key) use ($keys) {
            return in_array($key, $keys);
        }, ARRAY_FILTER_USE_KEY);
    }

    public static function http()
    {
        return Http::baseUrl(config('marketplace.via.shopee.host'))
            ->acceptJson()
            ->withResponseMiddleware(function (ResponseInterface $response) {

                $json = json_decode($response->getBody()->getContents());
                if ($json->error) {
                    $message = $json->message ?? 'Shopee API request failed.';
                    abort(406, '[API-SHOPEE: '. $json->error .'] ' . $message . ' (status: '. $response->getStatusCode() .')');
                }

                if ($response->getStatusCode() !== 200) {
                    abort(406, '[API-SHOPEE: status code '. $response->getStatusCode() .'] Shopee API request failed.');
                }

                return $response;
            });
    }

    public function api(): \Illuminate\Http\Client\PendingRequest
    {
        if (now()->greaterThan($this->getPayload('refresh_at'))) {
            $this->refreshToken();
        }

        $middleware = Middleware::mapRequest(function (RequestInterface $request) {
            $appid = env('MARKETPLACE_SHOPEE_APPID');
            $secret = env('MARKETPLACE_SHOPEE_SECRET');

            $accessToken = $this->getPayload('access_token');
            $shopID = $this->getPayload('shop_id');

            $uri = $request->getUri();
            $path = $uri->getPath();
            $timestamp = time();

            $string = sprintf("%s%s%s%s%s", $appid, $path, $timestamp, $accessToken, $shopID);
            $sign = hash_hmac('sha256', $string, $secret);

            $query = http_build_query([
                'partner_id' => $appid,
                'sign' => $sign,
                'timestamp' => $timestamp,
                'shop_id' => $shopID,
                'access_token' => $accessToken,
            ]);

            $request = $request->withUri(
                $uri->withQuery("$query&". $uri->getQuery())
            );

            return $request;
        });

        return  static::http()
            ->acceptJson()
            ->withMiddleware($middleware);
    }

    public function refreshToken()
    {
        // dump('Refreshing Shopee Token...', $this->getPayload());
        $pathURL = '/api/v2/auth/access_token/get';

        $appid  = env('MARKETPLACE_SHOPEE_APPID');
        $secret = env('MARKETPLACE_SHOPEE_SECRET');
        $timestamp = time();
        $refreshToken = $this->getPayload('refresh_token');
        $shopID = $this->getPayload('shop_id');

        $string = sprintf("%s%s%s", $appid, $pathURL, $timestamp, $refreshToken, $shopID);
        $sign = hash_hmac('sha256', $string, $secret);

        $response = static::http()->post($pathURL . "?partner_id=$appid&timestamp=$timestamp&sign=$sign", [
            'partner_id' => intval($appid),
            'shop_id' => intval($shopID),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            abort(406, '[COMMERCE] Shopee refresh token failed access.');
        }

        if ($response->json('error')) {
            abort(406, '[COMMERCE] Shopee refresh token failed. (' . $response->json('message') . ')');
        }


        $this->marketplace->update([
            'payload' => Shopee::createPayload([
                'shop_id' => $shopID,
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token'),
                'refresh_at' => now()->addSeconds(intval($response->json('expire_in')) - 300),
            ])
        ]);
    }

    public static function createPayload(array $data = [])
    {
        return encrypt(json_encode($data));
    }

    static public function getToken(string $code, string $shopID)
    {
        $host = config('marketplace.via.shopee.host');
        $pathURL = '/api/v2/auth/token/get';

        $appID  = env('MARKETPLACE_SHOPEE_APPID');
        $secret = env('MARKETPLACE_SHOPEE_SECRET');
        $timestamp = time();

        $string = sprintf("%s%s%s", $appID, $pathURL, $timestamp, $code, $shopID);
        $sign = hash_hmac('sha256', $string, $secret);

        $response = static::http()->post($pathURL . "?partner_id=$appID&timestamp=$timestamp&sign=$sign", [
            'code' => $code,
            'partner_id' => intval($appID),
            'shop_id' => intval($shopID),
        ]);

        return $response;
    }

    static public function getInfo(string $accessToken, string $shopID)
    {
        $host = config('marketplace.via.shopee.host');
        $path = '/api/v2/shop/get_shop_info';
        $appid = env('MARKETPLACE_SHOPEE_APPID');
        $secret = env('MARKETPLACE_SHOPEE_SECRET');
        $timestamp = time();

        $string = sprintf("%s%s%s%s%s", $appid, $path, $timestamp, $accessToken, $shopID);
        $sign = hash_hmac('sha256', $string, $secret);

        $response = static::http()->get($host . $path, [
            'partner_id' => $appid,
            'sign' => $sign,
            'timestamp' => $timestamp,
            'shop_id' => $shopID,
            'access_token' => $accessToken,
        ]);

        return $response->json();
    }

    public function getUpdatingProduct(callable $callback): array | false
    {
        $lastTime = $this->marketplace->option['sync_product_last_time'] ?? null;
        $newTime = time();

        $done = false;
        $ids = collect();
        $collection = collect();


        ## Fetch product list
        $offset = 0;
        while ($done == false) {
            $response = $this->api()->get('/api/v2/product/get_item_list', [
                'offset' => $offset,
                'page_size' => 50,
                'item_status' => 'NORMAL',
                'update_time_from' => $lastTime,
                'update_time_to' => $newTime,
            ]);

            $itemIDs = $ids->merge(collect($response->json('response.item'))->pluck('item_id'));


            if ($itemIDs->count() == 0) break;
            $resitem = $this->api()->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => $itemIDs->join(','),
                'need_tax_info' => true,
                'need_complaint_policy' => true,
            ]);


            collect($resitem->json('response.item_list'))->each(function($e) use (&$collection) {
                if ($e['has_model'] == true) {
                    $resvarian = $this->api()->get('/api/v2/product/get_model_list', [
                        'item_id' => $e['item_id'],
                    ]);
                    $e['model'] = $resvarian->json('response.model');
                }
                $collection->push($e);
            });



            if($resitem->json('response.has_next_page') === true) $offset = $resitem->json('response.next_offset');
            else $done = true;
        }

        $return = $callback($collection, $this->marketplace);
        if ($return !== false && $collection->count() > 0) {
            $this->marketplace->setAttribute('option->sync_product_last_time', $newTime);
            // $this->marketplace->save();
        }


        return $collection->toArray();
    }

    public function getOrder(array $parameter = [], callable $callback)
    {
        $status = $parameter['status'] ?? null;
        $lastTime = $this->marketplace->option['sync_order_last_creating_time'] ?? now()->addDays(-15)->timestamp;
        $newTime = time();
        $response = $this->onFetchOrder([
            'response_optional_fields' => 'order_status',
            'order_status' => $status,
            'time_range_field' => 'create_time',
            'time_from' => $lastTime,
            'time_to' => $newTime,
        ]);

        $array = $callback($response);

        return (array) $array;
    }

    public function getOrderShipParameter(): array
    {
        // $lastTime = $this->marketplace->option['sync_order_last_creating_time'] ?? now()->addDays(-15)->timestamp;
        // $newTime = time();
        $response =$this->marketplace->client->api()->get('/api/v2/order/get_order_list', [
            'response_optional_fields' => 'order_status',
            'order_status' => 'READY_TO_SHIP',
            // 'time_range_field' => 'create_time',
            // 'time_from' => $lastTime,
            // 'time_to' => $newTime,
        ]);

        return $response->json();
    }

    protected function onFetchOrder($mergeParams = []): \Illuminate\Support\Collection
    {

        $collection = collect();
        $done = false;
        $offset = 0;

        ## Fetch order list
        while ($done == false) {
            $response = $this->api()->get('/api/v2/order/get_order_list', array_merge([
                'offset' => $offset,
                'page_size' => 50,
                'response_optional_fields' => 'order_status',
                'time_range_field' => 'update_time',
                'time_from' => now()->addDays(-1)->timestamp,
                'time_to' => time(),

            ], $mergeParams, ['offset' => $offset]));

            // dump('Order List Response', $response->json(), $lastTime, $newTime);

            $sn = collect($response->json('response.order_list'))->pluck('order_sn');

            if ($sn->count() <= 0) break;

            $fields = [
                // 'buyer_user_id',
                // 'buyer_username',
                // 'buyer_cpf_id',

                // 'buyer_cancel_reason',
                // 'cancel_by',
                // 'cancel_reason',
                // 'cancel_time',

                // 'estimated_shipping_fee',
                // 'actual_shipping_fee',
                // 'shipping_carrier',
                // 'order_chargeable_weight_gram',

                // 'dropshipper',
                // ' dropshipper_phone',

                'recipient_address',
                'goods_to_declare',
                'note',
                'note_update_time',
                'item_list',

                // 'pay_time',
                'split_up',
                'actual_shipping_fee_confirmed',
                'fulfillment_flag',
                'pickup_done_time',
                'item_package_list',
                'payment_method',
                'total_amount',
                'invoice_data',
                'return_request_due_date',
                'edt',
                'payment_info',
            ];

            $responseDetail = $this->api()->get('/api/v2/order/get_order_detail', [
                'order_sn_list' => $sn->join(','),
                'request_order_status_pending' => true,
                'response_optional_fields' => collect($fields)->join(','),
            ]);

            collect($responseDetail->json('response.order_list'))->each(function($e) use (&$collection) {
                $collection->push($e);
            });

            if($response->json('response.has_next_page') === true) $offset = $response->json('response.next_offset');
            else $done = true;
        }

        return $collection;
    }

    public function request($module, array $attrs = [])
    {
        switch ($module) {

            case 'product.list':
                $query = array_merge([
                    'offset' => $attrs['offset'] ?? 0,
                    'page_size' => $attrs['limit'] ?? 50,
                    'item_status' => $attrs['status'] ?? 'NORMAL',
                ]);
                return $this->api()->get('/api/v2/product/get_item_list', $query);

            case 'product.detail':
                return $this->api()->get('/api/v2/product/get_item_base_info', [
                    'item_id_list' => $attrs['id'],
                    'need_tax_info' => true,
                    // 'need_complaint_policy' => true,
                ]);

            case 'product.variant.list':
                $query = array_merge([
                    'item_id' => $attrs['id'],
                    // 'offset' => $attrs['offset'] ?? 0,
                    // 'page_size' => $attrs['limit'] ?? 50,
                    // 'item_status' => $attrs['status'] ?? 'NORMAL',
                ]);
                return $this->api()->get('/api/v2/product/get_model_list', $query);

            case 'order.list':
                $query = array_merge([
                    'offset' => $attrs['offset'] ?? 0,
                    'page_size' => $attrs['limit'] ?? 50,

                    "time_range_field" => "create_time",
                    "time_from" => $attrs['time_from'] ?? now()->addDays(-15)->timestamp,
                    "time_to" => $attrs['time_to'] ?? now()->timestamp, // max 15 days
                    // "page_size" => "20",
                    // "order_status" => null,
                    // "response_optional_fields" => "order_status",
                    // "request_order_status_pending" => "true",
                    // "logistics_channel_id" => "91007",
                ]);

                return $this->api()->get('/api/v2/order/get_order_list', $query);

            case 'order.detail':
                return $this->api()->get('/api/v2/order/get_order_detail', [
                    'order_sn' => $attrs['sn'],
                    // 'request_order_status_pending' => true,
                    'response_optional_fields' => 'total_amount',
                ]);


            default:
                throw new \BadMethodCallException("Method {$module} does not exist on " . get_class($this));
        }
    }
}
