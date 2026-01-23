<?php

namespace Virmata\MarketplaceClient\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    const SKU_NAME = 'sku';

    protected $casts = [
        'option' => 'json',
    ];

    public function syncProductMarketplace($marketplace)
    {
        return $marketplace->client->getUpdatingProduct(function ($collection, $marketplace) {

            $result = $collection->reduce(function ($return, $row)  use ($marketplace) {
                // Shopee specific logic
                if($marketplace->via == 'shopee') {

                    $savingFn = function ($data, &$collection): self {
                        $item = self::firstOrNew([
                            self::SKU_NAME => $data['sku'],
                        ], $data);

                        $item->save();

                        if ($item->wasRecentlyCreated) {
                            $item->saleprice = $data['saleprice'] ?? 0;
                            $item->stock = $data['stock'] ?? 0;
                        }

                        if (isset($data['option'])) {
                            foreach ($data['option'] as $key => $value) {
                                $item->setAttribute("option->$key", $value);
                            }
                        }

                        $item->save();
                        $collection->push($item);

                        return $item;
                    };

                    $price = data_get($row, 'price_info.0.current_price', 0);
                    $descripter = collect(data_get($row, 'description_info.extended_description.field_list', []))
                        ->first(fn($e) => $e['field_type'] == 'text');
                    $description = $descripter['text'] ?? null;

                    if ($row['has_model'] == true) {
                        if (empty($row['model'])) abort(500, "Model data is missing for item_id: {$row['item_id']}");

                        foreach ($row['model'] as $model) {
                            if (empty($model['model_sku'])) {
                                $return->undefined->push($row);
                                continue;
                            }
                            $item = $savingFn([
                                'sku' => $model['model_sku'],
                                'name' => $model['model_name'],
                                'type' => 'product',
                                'description' => $description,
                                'saleprice' => data_get($model, 'price_info.0.current_price', $price),
                                'stock' => data_get($model, 'stock_info_v2.summary_info.total_available_stock', 0),
                                'option' => [
                                    'parent_name' => $row['item_name'],
                                ],
                            ], $return->data);

                            if ($item->wasRecentlyCreated) {
                                $item->save();
                            }
                        }
                    } else {
                        if (empty($row['item_sku'])) {
                            $return->undefined->push($row);
                            return $return;
                        }
                        $item = $savingFn([
                            'sku' => $row['item_sku'],
                            'name' => $row['item_name'],
                            'type' => 'product',
                            'description' => $description,
                            'saleprice' => $price,
                            'stock' => data_get($row, 'stock_info_v2.summary_info.total_available_stock', 0),
                        ], $return->data);
                    }
                }

                return $return;
            }, (object) [
                'data' => collect(),
                'undefined' => collect(),
            ]);
            return $result;
        });

    }
}
