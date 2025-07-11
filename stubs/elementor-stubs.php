<?php

// Função global fora da namespace

namespace Elementor;
function elementor_theme_do_location(string $location): void
{
}

class Plugin
{
    public static function instance(): self
    {
        return new self();
    }

    public function frontend(): Frontend
    {
        return new Frontend();
    }

    public function widgets_manager(): Widgets_Manager
    {
        return new Widgets_Manager();
    }

    public function controls_manager(): Controls_Manager
    {
        return new Controls_Manager();
    }
}

class Frontend
{
    public function enqueue_scripts(): void
    {
    }
    public function get_edit_url(): string
    {
        return '';
    }
}

class Widgets_Manager
{
    public function get_widget_types(): array
    {
        return [];
    }
}

class Controls_Manager
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const SELECT = 'select';
    public const SLIDER = 'slider';
    public const COLOR = 'color';
    public const SWITCHER = 'switcher';
    public const CHOOSE = 'choose';
    public const ICON = 'icon';
    public const HEADING = 'heading';
    public const URL = 'url';
    public const WYSIWYG = 'wysiwyg';
    public const DIMENSIONS = 'dimensions';
    public const NUMBER = 'number';
    public const HIDDEN = 'hidden';
    public const MEDIA = 'media';
    public const GALLERY = 'gallery';
    public const DATE_TIME = 'date_time';
    public const CODE = 'code';
    public const RAW_HTML = 'raw_html';

    public const TAB_STYLE = 'style';
    public const TAB_CONTENT = 'content';
    public const TAB_ADVANCED = 'advanced';

    public const RESPONSIVE_DESKTOP = 'desktop';
    public const RESPONSIVE_TABLET = 'tablet';
    public const RESPONSIVE_MOBILE = 'mobile';

    public const STATE_DEFAULT = '';
    public const STATE_HOVER = 'hover';
    public const STATE_ACTIVE = 'active';
}

class Group_Control_Typography
{
    public static function get_type(): string
    {
        return 'typography';
    }
}

class Group_Control_Background
{
    public static function get_type(): string
    {
        return 'background';
    }
}

class Group_Control_Box_Shadow
{
    public static function get_type(): string
    {
        return 'box_shadow';
    }
}

class Group_Control_Image_Size
{
    public static function get_type(): string
    {
        return 'image_size';
    }
}

class Group_Control_Border
{
    public static function get_type(): string
    {
        return 'border';
    }
}

class Group_Control_Text_Shadow
{
    public static function get_type(): string
    {
        return 'text_shadow';
    }
}

class Group_Control_Css_Filter
{
    public static function get_type(): string
    {
        return 'css_filter';
    }
}

class Repeater
{
    public function add_control(string $id, array $args): void
    {
    }
    public function add_group_control(string $type, array $args = []): void
    {
    }
}

class Icons_Manager
{
    public static function render_icon($icon, array $attributes = [], string $tag = 'i'): void
    {
    }
    public static function is_migration_allowed(): bool
    {
        return true;
    }
}
