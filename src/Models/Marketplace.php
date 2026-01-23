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
                case 'shopee': $arrayData = self::mapResponseShopeeOrder($collection); break;
                case 'tokopedia': $arrayData = self::mapResponseTokopediaOrder($collection); break;
                default:
                    throw new \Exception("Marketplace via [{$this->via}] not supported.");
            }
            return [
                'data' => $arrayData,
                'original' => $collection->toArray(),
            ];

        });
    }

    public static function mapResponseShopeeOrder(Collection $collection)
    {
        return collect($collection)->map(function($order) {
            return [
                'sn' => $order['order_sn'],
                'status' => $order['order_status'],
                'total_amount' => $order['total_amount'],
                'created_at' => isset($order['create_time']) ? date('Y-m-d H:i:s', $order['create_time']) : null,
                'updated_at' => isset($order['update_time']) ? date('Y-m-d H:i:s', $order['update_time']) : null,
                'items' => collect($order['item_list'])->map(function($item) {
                    return [
                        'sku' => $item['item_sku'],
                        'name' => $item['item_name'],
                        'quantity' => $item['model_quantity_purchased'],
                        'price' => $item['model_discounted_price'],
                    ];
                })->toArray(),
            ];

        })->toArray();
    }

    public static function mapResponseTokopediaOrder($collection)
    {
        return $collection->toArray();
    }
}
