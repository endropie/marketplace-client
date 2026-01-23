<?php

namespace Virmata\MarketplaceClient\Tools;

use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Virmata\MarketplaceClient\Contracts\ClientInterface;

use function Termwind\parse;

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
        return Http::baseUrl(config('marketplace.via.tokopedia.host'))
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

    public static function getShops($accessToken)
    {
        $apiVersion = static::$apiVersion;
        $pathURL = "/authorization/$apiVersion/shops";

        return static::http()
            ->contentType('application/json')
            ->withHeader("x-tts-access-token", $accessToken)
            ->withMiddleware(static::middleware())
            ->get($pathURL);
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
        if (now()->greaterThan($this->getPayload('refresh_at'))) {
            $this->refreshToken();
        }

        $accessToken = $this->getPayload('access_token');

        return  static::http()
            ->contentType('application/json')
            ->withHeader("x-tts-access-token", $accessToken)
            ->withMiddleware(static::middleware());
    }

    public function refreshToken()
    {
        $pathURL = '/api/v2/auth/access_token/get';

        $appid  = env('MARKETPLACE_TOKOPEDIA_APPID');
        $secret = env('MARKETPLACE_TOKOPEDIA_SECRET');
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
            abort(406, '[COMMERCE] Tokopedia refresh token failed access.');
        }

        if ($response->json('error')) {
            abort(406, '[COMMERCE] Tokopedia refresh token failed. (' . $response->json('message') . ')');
        }


        $this->marketConnect->update([
            'payload' => Tokopedia::createPayload([
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

    static public function getToken(string $code)
    {
        $host = config('marketplace.via.tokopedia.auth');
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

    public function getSyncronizeProducts($lastSync = null, $query = [], $data = [])
    {
        $data = [
            'status' => $attrs['status'] ?? 'ALL',
            'updated_after' => $lastSync,
        ];

        $query = [
            'shop_cipher' => $this->getPayload('cipher'),
            'page_size' => $attrs['limit'] ?? 50,
        ];

        $done = false;
        $rs = [];

        while ($done === false) {
            $response = $this->api()->post("/product/202502/products/search?". http_build_query($query), $data);

            $rs = array_merge($rs, $response->json('data.products'));

            if (true) $done = true;
        }

        return $rs;
    }
}
