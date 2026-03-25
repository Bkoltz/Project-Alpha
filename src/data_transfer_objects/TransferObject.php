<?php

namespace App\data_transfer_objects;
use ReflectionClass;
use ReflectionProperty;

// This is a base class used for transfer objects
class TransferObject {
    // Dynamically takes an array and maps them to public instance variables
    public static function fromArray(array $data): static
    {
        $dto = new static();
        $reflection = new ReflectionClass($dto);
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (isset($data[$name])) {
                $value = $data[$name];
                if ($prop->getType()?->getName() === 'array' && is_string($value)) {
                    $value = json_decode($value, true);
                }
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
            $name = $prop->getName();
            $value = $prop->getValue($this);
            if (is_array($value)) {
                $value = $value;
            }
            $array[$name] = $value;
        }
        return $array;
    }

    public function update(array $data) : static {
        $reflection = new ReflectionClass($this);
    
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (isset($data[$name])) {
                $value = $data[$name];
                if ($prop->getType()?->getName() === 'array' && is_string($value)) {
                    $value = json_decode($value, true);
                }
                $prop->setValue($this, $value);
            }
        }
        
        return $this;
    }
}