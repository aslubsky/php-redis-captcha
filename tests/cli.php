<?php

require '../src/Captcha/Generator.php';

use Captcha\Generator;

$g = new Generator([
    'key' => 'test'
]);
echo $g->generate();
