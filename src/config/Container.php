<?php

namespace App\config;

use ReflectionClass;

class Container
{
    private $singleton_directory = [];
    private $bind_directory = [];
    private $instances = [];

    //Register a singleton dependency container
    public function registerSingleton(string $path, ?object $return = null)
    {
        if (array_key_exists($path, $this->singleton_directory))
            return;

        $this->singleton_directory[$path] = true;

        if ($return !== null) {
            $this->instances[$path] = $return;
        }
    }

    //Register bind dependency container 
    public function registerBind(string $path)
    {
        if (array_key_exists($path, $this->bind_directory))
            return;

        $this->bind_directory[$path] = true;
    }

    //Creates a first instance if its a singleton, or makes a new instance each time
    public function get(string $className): ?object
    {
        if (key_exists($className, $this->instances))
            return $this->instances[$className];

        $builtClass = $this->build($className);
        if (isset($this->singleton_directory[$className])) {
            $this->instances[$className] = $builtClass;
        }

        return $builtClass;
    }

    private function build(string $className): object|bool
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (!$constructor)
            return new $className();

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $paramType = $param->getType()->getName();
            $params[] = $this->get($paramType);
        }

        return new $className(...$params);
    }
}
