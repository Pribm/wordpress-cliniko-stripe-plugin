<?php

namespace App\Admin\Modules\Components;

if (!defined('ABSPATH')) {
    exit;
}

/** A compact definition-list component for read/review shortcode views. */
final class DataReview
{
    /**
     * @param array{
     *   class?:string,
     *   title?:string,
     *   title_id?:string,
     *   introduction?:string,
     *   list_class?:string,
     *   show_empty?:bool,
     *   rows?:array<int,array{label:string,value:string,html?:bool}>
     * } $props
     */
    public static function render(array $props): string
    {
        $class = trim((string) ($props['class'] ?? ''));
        $title = trim((string) ($props['title'] ?? ''));
        $titleId = sanitize_html_class((string) ($props['title_id'] ?? ''));
        $introduction = trim((string) ($props['introduction'] ?? ''));
        $listClass = trim((string) ($props['list_class'] ?? ''));
        $showEmpty = !empty($props['show_empty']);
        $rows = is_array($props['rows'] ?? null) ? $props['rows'] : [];
        $classes = trim($class . ' cliniko-component cliniko-component-data-review');

        ob_start();
        ?>
        <section class="<?php echo esc_attr($classes); ?>"<?php echo $titleId !== '' ? ' aria-labelledby="' . esc_attr($titleId) . '"' : ''; ?>>
            <?php if ($title !== '') : ?><h3<?php echo $titleId !== '' ? ' id="' . esc_attr($titleId) . '"' : ''; ?>><?php echo esc_html($title); ?></h3><?php endif; ?>
            <?php if ($introduction !== '') : ?><p><?php echo esc_html($introduction); ?></p><?php endif; ?>
            <?php if ($rows !== []) : ?>
                <dl<?php echo $listClass !== '' ? ' class="' . esc_attr($listClass) . '"' : ''; ?>>
                    <?php foreach ($rows as $row) :
                        $label = trim($row['label']);
                        $value = $row['value'];
                        if ($label === '' || (!$showEmpty && trim(wp_strip_all_tags($value)) === '')) {
                            continue;
                        }
                        ?>
                        <div><dt><?php echo esc_html($label); ?></dt><dd><?php echo !empty($row['html']) ? wp_kses_post($value) : esc_html($value); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
