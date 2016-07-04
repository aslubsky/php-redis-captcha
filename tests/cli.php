<?php

require '../src/Captcha/Generator.php';

use Captcha\Generator;

$g = new Generator();
echo $g->generate();