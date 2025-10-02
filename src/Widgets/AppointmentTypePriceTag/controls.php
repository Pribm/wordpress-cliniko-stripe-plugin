<?php
use App\Model\AppointmentType;
use Elementor\Controls_Manager;

$this->start_controls_section(
  'section_content',
  [
    'label' => __('Content', 'plugin-name'),
  ]
);

$client = cliniko_client(true);
$types = AppointmentType::all($client, true);

$options = [];
foreach ($types as $type) {
    /** @var AppointmentType $type */
    $options[$type->getId()] = $type->getName();
}

// Select Appointment Type (dynamic from Cliniko)
$this->add_control(
  'selected_appointment_type',
  [
    'label' => __('Appointment Type', 'plugin-name'),
    'type' => Controls_Manager::SELECT,
    'options' => $options,
    'default' => '',
  ]
);

// Editable Label
$this->add_control(
  'price_label',
  [
    'label' => __('Price Label', 'plugin-name'),
    'type' => Controls_Manager::TEXT,
    'default' => __('Price:', 'plugin-name'),
    'placeholder' => __('Enter label', 'plugin-name'),
  ]
);

// Optional custom override
$this->add_control(
  'custom_price',
  [
    'label' => __('Custom Price in Cents (Optional)', 'plugin-name'),
    'type' => Controls_Manager::TEXT,
    'placeholder' => __('Leave empty to use Cliniko price', 'plugin-name'),
  ]
);

$this->end_controls_section();


// Style controls
$this->start_controls_section(
  'section_style',
  [
    'label' => __('Style', 'plugin-name'),
    'tab'   => Controls_Manager::TAB_STYLE,
  ]
);

// Label Typography & Color
$this->add_control(
  'label_color',
  [
    'label' => __('Label Color', 'plugin-name'),
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} .cliniko-price-label' => 'color: {{VALUE}};',
    ],
  ]
);

$this->add_group_control(
  \Elementor\Group_Control_Typography::get_type(),
  [
    'name'     => 'label_typography',
    'selector' => '{{WRAPPER}} .cliniko-price-label',
  ]
);

// Price Typography & Color
$this->add_control(
  'price_color',
  [
    'label' => __('Price Color', 'plugin-name'),
    'type'  => Controls_Manager::COLOR,
    'selectors' => [
      '{{WRAPPER}} .cliniko-price-value' => 'color: {{VALUE}};',
    ],
  ]
);

$this->add_group_control(
  \Elementor\Group_Control_Typography::get_type(),
  [
    'name'     => 'price_typography',
    'selector' => '{{WRAPPER}} .cliniko-price-value',
  ]
);

$this->end_controls_section();
