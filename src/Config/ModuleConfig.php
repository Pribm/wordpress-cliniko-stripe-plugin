<?php
namespace App\Config;
if (!defined('ABSPATH')) exit;


use App\Service\ClinikoService;
use App\Model\AppointmentType;

class ModuleConfig
{
    public static function getModules(): array
    {
        $cliniko = new ClinikoService();
        $types = $cliniko->getAppointmentTypes(); 

        $modules = [];

        /** @var AppointmentType $type */
        foreach ($types as $type) {
            $id = $type->id;
            $modules[$id] = [
                'name' => $type->name,
                'price' => $type->price ?? 0,
                'duration' => $type->durationInMinutes,
                'description' => $type->description,
                'appointment_type_id' => $id,
                'practitioner_id' => null, // pode ser configurado dinamicamente
                'required_fields' => [],   // personalizar conforme necessário
            ];
        }

        return $modules;
    }
}
