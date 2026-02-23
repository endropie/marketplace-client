<?php
return [

    "route_prefix" => 'marketplace',

    "model" => Virmata\MarketplaceClient\Models\Marketplace::class,

    "shopee" => [
        "host" => "https://partner.shopeemobile.com",
        "status" => [
            "READY" => "READY_TO_SHIP",
            "PROCESSED" => "PROCESSED",
            "PICKUP" => "SHIPPED",
            "COMPLETED" => "COMPLETED",
            "CANCELED" => "CANCELED",
        ],
    ],
    "tokopedia" => [
        "host" => "https://open-api.tiktokglobalshop.com",
        "status" => [
            "READY" => "AWAITING_SHIPMENT",
            "PROCESSED" => "AWAITING_COLLECTION",
            "PICKUP" => "IN_TRANSIT",
            "COMPLETED" => "COMPLETED",
            "CANCELED" => "CANCELED",
        ],
    ]
];
