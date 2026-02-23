<?php

namespace Virmata\MarketplaceClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Virmata\MarketplaceClient\Tools\Client;

class Marketplace extends Model
{
    use HasUlids;

    protected $fillable = [
        'code',
        'name',
        'payload',
        'via',
    ];

    protected $casts = [
        'option' => 'json',
    ];

    public function getClientAttribute($query)
    {
        return new Client($this);
    }

    public function getMarketplaceOrder(array $parameter)
    {
        return $this->client->getOrder($parameter, function ($collection) {

            switch ($this->via) {
                case 'shopee':
                    $arrayData = collect($collection)->map(fn($e) => self::mapResponseShopeeOrder($e))->toArray();
                    break;
                case 'tokopedia':
                    $arrayData = collect($collection)->map(fn($e) => self::mapResponseTokopediaOrder($e))->toArray();
                    break;
                default:
                    throw new \Exception("Marketplace via [{$this->via}] not supported.");
            }
            return [
                'data' => $arrayData,
                'original' => $collection->toArray(),
            ];

        });
    }

    public function getMarketplaceOrderDetail(array $parameter)
    {
        return $this->client->getOrderDetail($parameter, function ($data) {

            switch ($this->via) {
                case 'shopee': $arrayData = self::mapResponseShopeeOrder($data); break;
                case 'tokopedia': $arrayData = self::mapResponseTokopediaOrder($data); break;
                default:
                    throw new \Exception("Marketplace via [{$this->via}] not supported.");
            }
            return [
                'data' => $arrayData,
                'original' => $data,
            ];

        });
    }

    public static function mapResponseShopeeOrder($order)
    {
        return [
            'via' => 'shopee',
            'sn' => $order['order_sn'],
            'date' => isset($order['create_time']) ? date('Y-m-d', $order['create_time']) : null,
            'status' => $order['order_status'],
            'items' => collect($order['item_list'])->map(function($item) {
                return [
                    'sku' => $item['item_sku'],
                    'name' => $item['item_name'],
                    'quantity' => $item['model_quantity_purchased'],
                    'price' => $item['model_discounted_price'],
                ];
            })->toArray(),
        ];
    }

    public static function mapResponseTokopediaOrder($order)
    {
        return [
            'via' => 'tokopedia',
            'sn' => $order['id'],
            'date' => boolval($order['create_time']) ? date('Y-m-d', $order['create_time']) : null,
            'status' => $order['status'],
            'items' => collect($order['line_items'])->map(function($item) {
                return [
                    'sku' => $item['seller_sku'],
                    'name' => $item['product_name'] . ($item['seller_sku'] ? " - " . $item['sku_name'] : ''),
                    'quantity' => $item['quantity'],
                    'price' => doubleval($item['sale_price']),
                ];
            })->toArray(),
        ];
    }
}
