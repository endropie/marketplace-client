<?php
return [

    "route_prefix" => 'marketplace',

    "model" => Virmata\MarketplaceClient\Models\Marketplace::class,

    "via" => [
        "shopee" => [
            "host" => "https://openplatform.sandbox.test-stable.shopee.sg",
            "authorize_url" => "https://open.sandbox.test-stable.shopee.com/auth",
        ],
        "tokopedia" => [
            "host" => "https://open-api.tiktokglobalshop.com",
            "auth" => "https://auth.tiktok-shops.com",
            "authorize_url" => "https://services.tiktokshop.com/open/authorize",
        ]
    ],
];
