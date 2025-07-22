<?php
foreach (glob(__DIR__ . '/*.php') as $file) {
    if (basename($file) !== 'index.php') {
        require_once $file;
    }
}
