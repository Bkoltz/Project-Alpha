<?php

namespace App\data_transfer_objects;

use ReflectionClass;
use ReflectionProperty;

// This is a base class used for transfer objects
class TransferObject
{
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    // Dynamically takes an array and maps them to public instance variables
    public static function fromArray(?array $data): ?static
    {
        if ($data == null)
            return null;

        $dto = new static();
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();

            if (isset($data[$name])) {
                $value = $data[$name];
                $type = $prop->getType()?->getName();

                if ($type === 'array' && is_string($value)) {
                    $value = json_decode($value, true);
                    if (gettype($value) !== 'array') {
                        $value = [$value];
                    }
                }

                if ($type && class_exists($type) && is_subclass_of($type, TransferObject::class, true) && is_array($value)) 
                    $value = $type::fromArray($value);
                
                $value = match ($type) {
                    'int' => (int)$value,
                    'float' => (float)$value,
                    'string' => (string)$value,
                    'bool' => (bool)$value,
                    default => $value
                };

                $prop->setValue($dto, $value);
            }
        }
        return $dto;
    }

    // Dynamically turns public instance variables into an asso array
    public function toArray(): array
    {
        $array = [];
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($this))
                continue;

            $name = $prop->getName();
            $value = $prop->getValue($this);

            $type = $prop->getType()?->getName();
            if ($type === 'array' && is_string($value))
                $value = json_decode($value, true);

            if ($value instanceof TransferObject)
                $value = $value->toArray();

            $array[$name] = $value;
        }
        return $array;
    }

    public function toNumericArray(): array
    {
        $array = [];
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($this);
            $type = $prop->getType()?->getName();

            if ($type === 'array' && is_string($value)) {
                $value = json_decode($value, true);
            }

            if (is_a($type, TransferObject::class, true)) {
                $value = $value->toNumericArray();
            }

            $array[] = $value;
        }
        return $array;
    }

    //Updates a transfer object from and asso array
    public function update(array $data): static
    {
        $reflection = new ReflectionClass($this);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (isset($data[$name])) {
                $type = $prop->getType()?->getName();
                $value = $data[$name];

                if ($type === 'array' && is_string($value)) {
                    $value = json_decode($value, true);
                }

                if ($type && class_exists($type) && is_a($type, TransferObject::class, true) && is_array($value)) {
                    $value = $type::fromArray($value);
                }

                $prop->setValue($this, $value);
            }
        }

        return $this;
    }
}
