<?php

namespace App\Widgets;

 use App\Widgets\ClinikoForm\Widget as ClinikoStripeForm;
 use App\Widgets\AppointmentTypeCard\Widget as AppointmentTypeCard;
 use App\Widgets\AppointmentTypePriceTag\Widget as AppointmentTypePriceTag;

class Widgets
{

 

    public static function register($widgets_manager)
    {
        $widgets_manager->register(new ClinikoStripeForm());
        $widgets_manager->register(new AppointmentTypeCard());
        $widgets_manager->register(new AppointmentTypePriceTag());
    }
}