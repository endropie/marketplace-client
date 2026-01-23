<?php

namespace Virmata\MarketplaceClient\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Order extends Model
{
    use HasUlids;

    protected $casts = [
        'option' => 'json',
    ];

    // public static function getMarketplaceOrder($marketplace, $parameter)
    // {
    //     return $marketplace->client->getOrder($parameter, function ($collection) use ($marketplace) {

    //         switch ($marketplace->via) {
    //             case 'shopee': $collection = self::mapResponseShopeeOrder($collection); break;
    //             case 'tokopedia': $collection = self::mapResponseTokopediaOrder($collection); break;
    //             default:
    //                 throw new \Exception("Marketplace via [{$marketplace->via}] not supported.");
    //         }
    //         return [
    //             'data' => $collection,
    //         ];

    //     });
    // }

    // public static function mapResponseShopeeOrder(Collection $collection)
    // {
    //     return collect($collection)->map(function($order) {
    //         return [
    //             'sn' => $order['order_sn'],
    //             'status' => $order['order_status'],
    //             'total_amount' => $order['total_amount'],
    //             'created_at' => isset($order['create_time']) ? date('Y-m-d H:i:s', $order['create_time']) : null,
    //             'updated_at' => isset($order['update_time']) ? date('Y-m-d H:i:s', $order['update_time']) : null,

    //             'orginal' => $order,
    //         ];

    //     })->toArray();
    // }

    // public static function mapResponseTokopediaOrder($collection)
    // {
    //     return $collection;
    // }
}
