<?php
namespace Virmata\MarketplaceClient\Tools;

use Virmata\MarketplaceClient\Contracts\ClientInterface;
use Virmata\MarketplaceClient\Models\Marketplace;

class Client {

    protected ClientInterface $manager;

    protected Marketplace $model;

    public function __construct($model)
    {

        $model = is_object($model)
            ? $model : $model = app(config('marketplace.model'))->findOrFail($model);

        try {
            $mclass = 'Virmata\\MarketplaceClient\\Tools\\' . ucfirst($model->via);
            $manager = new $mclass($model->id);
        } catch (\Throwable $th) {
            throw new \Exception("Marketplace manager for '{$model->via}' not found.");
        }

        $this->model = $model;
        $this->manager = $manager;
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this->manager, $name)) {
            return call_user_func_array([$this->manager, $name], $arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist on " . get_class($this->manager));
    }

}
