<?php

namespace Virmata\MarketplaceClient\Tools;

use GuzzleHttp\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Virmata\MarketplaceClient\Contracts\ClientInterface;


class Tokopedia implements ClientInterface
{
    protected $marketConnect;
    public static $apiVersion = '202309'; // Tokopedia API version

    function __construct(string|int $id)
    {
        $this->marketConnect = app(config('marketplace.model'))->findOrFail($id);
    }

    public  function getPayload(...$keys): array|string|null
    {
        $payload = json_decode(decrypt($this->marketConnect->payload), true);
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
        return Http::baseUrl(config('marketplace.tokopedia.host'))
            ->acceptJson()
            ->contentType('application/json')
            ->withResponseMiddleware(function (ResponseInterface $response) {

                $json = json_decode($response->getBody()->getContents());
                if ($json->code > 0) {
                    abort(406, '[API-TOKOPEDIA: '. $json->code .'] '. ($json->message ?? 'Tokopedia API request failed.'));
                }

                if ($response->getStatusCode() !== 200) {
                    abort(406, ('[API-TOKOPEDIA: '. ($json->code ?? 'Failed') .']') . $json->message ?? 'Tokopedia API request failed.');
                }

                return $response;
            });
    }

    public static function middleware ($options = []) {
        return Middleware::mapRequest(function (RequestInterface $request) use ($options) {
            $appKey = env('MARKETPLACE_TOKOPEDIA_APPID');
            $secret = env('MARKETPLACE_TOKOPEDIA_SECRET');

            $arrayQuery = []; parse_str($request->getUri()->getQuery(), $arrayQuery);
            $arrayQuery = array_merge($arrayQuery, [
                'app_key' => $appKey,
                'timestamp' => time(),
            ]);

            // 1. Extract all query param EXCEPT ' sign ', ' access_token ', reorder the params based on alphabetical order.
            $collect = collect($arrayQuery)->except(['sign', 'access_token', 'x-tts-access-token'])
                    ->keys()
                    ->sort();

            // 2. Concat all the param in the format of {key}{value}
            $signQuery = $collect
                    ->map(fn($e) => (string) ($e.$arrayQuery[$e]))
                    ->join("");

            // 3. Append the request path to the beginning
            $string = $request->getUri()->getPath() . $signQuery;

            // 4. If the request header content_type is not multipart/form-data, append body to the end
            if ($request->getMethod() !== 'GET' && strpos($request->getHeaderLine('content-type'), 'multipart/form-data') === false) {
                $string .= (string) $request->getBody();
            }

            // 5. Wrap string generated in step 3 with app_secret.
            $string = $secret . $string . $secret;

            // Encode the digest byte stream in hexadecimal and use sha256 to generate sign with salt(secret).
            $sign = hash_hmac('sha256', $string, $secret);

            $request = $request->withUri(
                $request->getUri()->withQuery(
                    http_build_query(
                        array_merge($arrayQuery, $options, [
                            'sign' => $sign,
                        ])
                    )
                )
            );

            return $request;
        });
    }

    public function api(): \Illuminate\Http\Client\PendingRequest
    {
        if (true || Carbon::now()->greaterThan($this->getPayload('refresh_at')['date'])) {
            $this->refreshToken();
        }

        $options = [];

        if ($this->getPayload('cipher')) {
            $options = array_merge($options, ['shop_cipher' => $this->getPayload('cipher')]);
        }

        $accessToken = $this->getPayload('access_token');
        return  static::http()
            ->contentType('application/json')
            ->withHeader("x-tts-access-token", $accessToken)
            ->withQueryParameters($options)
            ->withMiddleware(static::middleware());
    }

    public function refreshToken()
    {
        $pathURL = 'https://auth.tiktok-shops.com/api/v2/token/refresh';

        $appid  = env('MARKETPLACE_TOKOPEDIA_APPID');
        $secret = env('MARKETPLACE_TOKOPEDIA_SECRET');

        $response = static::http()->get($pathURL, [
            'app_key' => $appid,
            'app_secret' => $secret,
            'refresh_token' => $this->getPayload('refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            abort(406, '[COMMERCE] Tokopedia refresh token failed access.');
        }

        if ($response->json('error')) {
            abort(406, '[COMMERCE] Tokopedia refresh token failed. (' . $response->json('message') . ')');
        }

        $auth = $response->json('data');
        $this->marketConnect->update([
            'payload' => Tokopedia::createPayload([
                ...$this->getPayload(),
                'access_token' => $auth['access_token'],
                'refresh_token' => $auth['refresh_token'],
                'refresh_at' => Carbon::now()->setTimestamp(intval($auth['access_token_expire_in']) - 300)->format('Y-m-d H:i:s'),
            ])
        ]);
    }

    public static function createPayload(array $data = [])
    {
        return encrypt(json_encode($data));
    }

    public static function getShops($accessToken)
    {
        $pathURL = "/authorization/202309/shops";

        return static::http()
            ->contentType('application/json')
            ->withHeader("x-tts-access-token", $accessToken)
            ->withMiddleware(static::middleware())
            ->get($pathURL);
    }

    static public function getToken(string $code)
    {
        $host = 'https://auth.tiktok-shops.com'; // config('marketplace.tokopedia.host');
        $pathURL = '/api/v2/token/get';
        $appKey = env('MARKETPLACE_TOKOPEDIA_APPID');
        $secret = env('MARKETPLACE_TOKOPEDIA_SECRET');

        $response = static::http()->baseUrl($host)->get($pathURL, [
            'grant_type' => 'authorized_code',
            'auth_code' => $code,
            'app_key' => $appKey,
            'app_secret' => $secret,
        ]);

        return $response;
    }

    public function request($module, array $attrs = [])
    {
        switch ($module) {

            case 'product.list':

                $data = [
                    'status' => $attrs['status'] ?? 'ALL',
                ];

                $query = [
                    'shop_cipher' => $this->getPayload('cipher'),
                    'page_size' => $attrs['limit'] ?? 50,
                ];

                return $this->api()->post("/product/202502/products/search?". http_build_query($query), $data);

            case 'product.detail':
                return $this->api()->get("/product/202502/products/". $attrs["id"], [
                    'item_id_list' => $attrs['id'],
                    'need_tax_info' => true,
                    // 'need_complaint_policy' => true,
                ]);

            case 'order.list':
                $query = array_merge([
                    'offset' => $attrs['offset'] ?? 0,
                    'page_size' => $attrs['limit'] ?? 50,
                ]);

                return $this->api()->get('/api/v2/order/get_order_list', $query);

            case 'order.detail':
                return $this->api()->get('/api/v2/order/get_order_detail', [
                    'order_sn' => $attrs['id'],
                    'request_order_status_pending' => true,
                    'response_optional_fields' => 'total_amount',
                ]);


            default:
                throw new \BadMethodCallException("Method {$module} does not exist on " . get_class($this));
        }
    }

    public function getOrder(array $parameter = [], callable $callback)
    {
        $map = config("marketplace.tokopedia.status", []);
        $status = !isset($parameter['status']) ? null
            : ($map[strtoupper($parameter['status'])] ?? null);

        if (isset($parameter['dates'][0]) && isset($parameter['dates'][1])) {
            $lastTime = strtotime($parameter['dates'][0]);
            $newTime = strtotime($parameter['dates'][1] . ' +1 day');
        }
        elseif (isset($parameter['date'])) {
            $lastTime = strtotime($parameter['date']);
            $newTime = strtotime($parameter['date'] . ' +1 day');
        }
        else {
            $newTime = Carbon::createFromTime(0,0,0)->timestamp;
            $lastTime = Carbon::createFromTime(0,0,0)->addDays(-15)->timestamp;
        }

        $response = $this->onFetchOrder([
            'response_optional_fields' => 'order_status',
            'order_status' => $status,
            'time_range_field' => 'create_time',
            'create_time_ge' => $lastTime,
            'create_time_lt' => $newTime,
        ]);

        $array = $callback($response);

        return (array) $array;
    }

    protected function onFetchOrder($mergeParams = []): \Illuminate\Support\Collection
    {

        $collection = collect();
        $done = false;
        $orderPackage = [];

        ## Fetch order list
        while ($done == false) {
            $response = $this->api()
                ->withQueryParameters([
                    'page_size' => 50,
                ])
                ->post('/order/202309/orders/search', array_merge([
                    'create_time_ge' => Carbon::createFromTime(0,0,0)->addDays(-15)->timestamp,
                    'create_time_lt' => Carbon::createFromTime(0,0,0)->timestamp,

                ], $mergeParams));

            $orders = collect($response->json('data.orders'));

            if ($orders->count() <= 0) break;

            $orders->each(function($e) use (&$collection, &$orderPackage) {

                $items = collect($e['line_items'])->reduceWithKeys(function($all, $item) use (&$orderPackage, $e) {

                    $key = $item['sku_id'] ?: 'ID:'. $item['product_id'];

                    if (!isset($all[$key])) {
                         $all[$key] = array_merge($item, ['quantity' => 0]);
                    }

                    $all[$key]['quantity']++;

                    return $all;
                }, []);

                $collection->push(array_merge($e, [
                    'line_items' => array_values($items),
                    'line_itemx' => $e['line_items'],
                    'package' => $orderPackage
                ]));

            });

            if(!$response->json('data.next_page_token')) {
                $done = true;
            }
        }

        return $collection;
    }

    public function getOrderDetail(array $parameter = [], callable $callback)
    {
        $map = config("marketplace.tokopedia.status", []);
        $status = !isset($parameter['status']) ? null
            : ($map[strtoupper($parameter['status'])] ?? null);

        if (isset($parameter['dates'][0]) && isset($parameter['dates'][1])) {
            $lastTime = strtotime($parameter['dates'][0]);
            $newTime = strtotime($parameter['dates'][1] . ' +1 day');
        }
        elseif (isset($parameter['date'])) {
            $lastTime = strtotime($parameter['date']);
            $newTime = strtotime($parameter['date'] . ' +1 day');
        }
        else {
            $newTime = Carbon::createFromTime(0,0,0)->timestamp;
            $lastTime = Carbon::createFromTime(0,0,0)->addDays(-15)->timestamp;
        }

        $response = $this->onFetchOrder([
            'response_optional_fields' => 'order_status',
            'order_status' => $status,
            'time_range_field' => 'create_time',
            'create_time_ge' => $lastTime,
            'create_time_lt' => $newTime,
        ]);

        $array = $callback($response);

        return (array) $array;
    }
}
