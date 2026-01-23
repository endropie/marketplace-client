<?php

namespace Virmata\MarketplaceClient;

use Illuminate\Support\Facades\Facade as BaseFacade;
use Virmata\MarketplaceClient\Tools\Client;

class Facade extends BaseFacade {

    protected static function getFacadeAccesor() {
        return 'marketplace';
    }


    public static function on(string $id)
    {
        $model = app(config('marketplace.model'))->findOrFail($id);

        /** @var \Virmata\MarketplaceClient\Contracts\ClientInterface $manager */
        $manager = 'Virmata\\MarketplaceClient\\Tools\\' . ucfirst($model->via);

        return new Client($model);
    }


}
