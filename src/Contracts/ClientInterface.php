<?php

namespace Virmata\MarketplaceClient\Contracts;

use Illuminate\Http\Client\PendingRequest;

interface ClientInterface {

    public function __construct(string|int $id);

    public function api(): PendingRequest;
}
