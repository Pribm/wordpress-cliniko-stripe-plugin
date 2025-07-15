<?php
if (!defined('ABSPATH'))
exit;

$details_background = esc_attr($settings['details_background']);
$details_border_color = esc_attr($settings['details_border_color']);
$details_border_radius = esc_attr($settings['details_border_radius']['size']);
$summary_heading_color = esc_attr($settings['summary_heading_color']);
$summary_text_color = esc_attr($settings['summary_text_color']);
$summary_price_color = esc_attr($settings['summary_price_color']);
$gap_between_columns = esc_attr($settings['gap_between_columns']['size']);
$button_label = esc_attr($settings['button_label']);
$input_border = esc_attr($settings['input_border']);
$border_radius = esc_attr($settings['border_radius']['size']) . 'px';
$font_family = esc_attr($settings['font_family']);
$color_text = esc_attr($settings['color_text']);
$color_background = esc_attr($settings['color_background']);
$color_primary = esc_attr($settings['color_primary']);
$button_text_color = esc_attr($settings['button_text_color']);
$button_padding = esc_attr($settings['button_padding']);
$button_font_size = esc_attr($settings['button_font_size']['size']);
$button_css = esc_attr($settings['button_css']);
$style_button = "margin-top: 16px; width: 100%; background: {$color_primary}; color: {$button_text_color}; padding: {$button_padding}; font-size: {$button_font_size}px; border: none; border-radius: {$border_radius}px; font-family: {$font_family}; {$button_css}";
?>