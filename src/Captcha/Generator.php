<?php

namespace Captcha;

// Check for GD library
if (!function_exists('gd_info')) {
    throw new \Exception('Required GD library is missing');
}

if (!function_exists('hex2rgb')) {
    function hex2rgb($hex_str, $return_string = false, $separator = ',')
    {
        $hex_str = preg_replace("/[^0-9A-Fa-f]/", '', $hex_str); // Gets a proper hex string
        $rgb_array = array();
        if (strlen($hex_str) == 6) {
            $color_val = hexdec($hex_str);
            $rgb_array['r'] = 0xFF & ($color_val >> 0x10);
            $rgb_array['g'] = 0xFF & ($color_val >> 0x8);
            $rgb_array['b'] = 0xFF & $color_val;
        } elseif (strlen($hex_str) == 3) {
            $rgb_array['r'] = hexdec(str_repeat(substr($hex_str, 0, 1), 2));
            $rgb_array['g'] = hexdec(str_repeat(substr($hex_str, 1, 1), 2));
            $rgb_array['b'] = hexdec(str_repeat(substr($hex_str, 2, 1), 2));
        } else {
            return false;
        }
        return $return_string ? implode($separator, $rgb_array) : $rgb_array;
    }
}

define('CAPTCHA_BG_PATH', __DIR__ . '/../../backgrounds/');
define('CAPTCHA_FONTS_PATH', __DIR__ . '/../../fonts/');


class Generator
{
    public static $defaultConfig = [
        'code' => '',
        'min_length' => 5,
        'max_length' => 5,
        'backgrounds' => [
            CAPTCHA_BG_PATH . '45-degree-fabric.png',
            CAPTCHA_BG_PATH . 'cloth-alike.png',
            CAPTCHA_BG_PATH . 'grey-sandbag.png',
            CAPTCHA_BG_PATH . 'kinda-jean.png',
            CAPTCHA_BG_PATH . 'polyester-lite.png',
            CAPTCHA_BG_PATH . 'stitched-wool.png',
            CAPTCHA_BG_PATH . 'white-carbon.png',
            CAPTCHA_BG_PATH . 'white-wave.png'
        ],
        'fonts' => [
            CAPTCHA_FONTS_PATH . 'times_new_yorker.ttf'
        ],
        'characters' => 'ABCDEFGHJKLMNPRSTUVWXYZabcdefghjkmnprstuvwxyz23456789',
        'min_font_size' => 28,
        'max_font_size' => 28,
        'color' => '#666',
        'angle_min' => 0,
        'angle_max' => 10,
        'shadow' => true,
        'shadow_color' => '#fff',
        'shadow_offset_x' => -1,
        'shadow_offset_y' => 1
    ];

    private $captchaConfig = [];

    private $redisConnect = null;
    private $redisPrefix = 'captcha';

    private function _prepareConfig()
    {
        // Restrict certain values
        if (!isset($this->captchaConfig['key'])) {
            throw new \Exception('Missing required `key` option');
        }
        if ($this->captchaConfig['min_length'] < 1) {
            $this->captchaConfig['min_length'] = 1;
        }
        if ($this->captchaConfig['angle_min'] < 0) {
            $this->captchaConfig['angle_min'] = 0;
        }
        if ($this->captchaConfig['angle_max'] > 10) {
            $this->captchaConfig['angle_max'] = 10;
        }
        if ($this->captchaConfig['angle_max'] < $this->captchaConfig['angle_min']) {
            $this->captchaConfig['angle_max'] = $this->captchaConfig['angle_min'];
        }
        if ($this->captchaConfig['min_font_size'] < 10) {
            $this->captchaConfig['min_font_size'] = 10;
        }
        if ($this->captchaConfig['max_font_size'] < $this->captchaConfig['min_font_size']) {
            $this->captchaConfig['max_font_size'] = $this->captchaConfig['min_font_size'];
        }
        if (!isset($this->captchaConfig['redis'])) {
            $this->captchaConfig['redis'] = [
                'server' => '127.0.0.1',
                'port' => 6379
            ];
        }

        // Generate CAPTCHA code if not set by user
        if (empty($this->captchaConfig['code'])) {
            $this->captchaConfig['code'] = '';
            $length = mt_rand($this->captchaConfig['min_length'], $this->captchaConfig['max_length']);
            while (strlen($this->captchaConfig['code']) < $length) {
                $this->captchaConfig['code'] .= substr($this->captchaConfig['characters'], mt_rand() % (strlen($this->captchaConfig['characters'])), 1);
            }
        }
    }

    public function check()
    {
        $key = $this->redisConnect->get($this->redisPrefix . '-' . $this->captchaConfig['key']);
        if ($key) {
            return $key == $this->captchaConfig['code'];
        }
        return false;
    }

    public function generate()
    {
        $this->redisConnect->set($this->redisPrefix . '-' . $this->captchaConfig['key'], $this->captchaConfig['code']);

        // Pick random background, get info, and start captcha
        $background = $this->captchaConfig['backgrounds'][mt_rand(0, count($this->captchaConfig['backgrounds']) - 1)];
        list($bgWidth, $bgHeight, $bgType, $bgAttr) = getimagesize($background);
        $captcha = imagecreatefrompng($background);
        $color = hex2rgb($this->captchaConfig['color']);
        $color = imagecolorallocate($captcha, $color['r'], $color['g'], $color['b']);
        // Determine text angle
        $angle = mt_rand($this->captchaConfig['angle_min'], $this->captchaConfig['angle_max']) * (mt_rand(0, 1) == 1 ? -1 : 1);
        // Select font randomly
        $font = $this->captchaConfig['fonts'][mt_rand(0, count($this->captchaConfig['fonts']) - 1)];
        // Verify font file exists
        if (!file_exists($font)) {
            throw new \Exception('Font file not found: ' . $font);
        }
        //Set the font size.
        $fontSize = mt_rand($this->captchaConfig['min_font_size'], $this->captchaConfig['max_font_size']);
        $textBoxSize = imagettfbbox($fontSize, $angle, $font, $this->captchaConfig['code']);
        // Determine text position
        $boxWidth = abs($textBoxSize[6] - $textBoxSize[2]);
        $boxHeight = abs($textBoxSize[5] - $textBoxSize[1]);
        $textPosXMin = 0;
        $textPosXMax = ($bgWidth) - ($boxWidth);
        $textPosX = mt_rand($textPosXMin, $textPosXMax);
        $textPosYMin = $boxHeight;
        $textPosYMax = ($bgHeight) - ($boxHeight / 2);
        if ($textPosYMin > $textPosYMax) {
            $tempTextPosY = $textPosYMin;
            $textPosYMin = $textPosYMax;
            $textPosYMax = $tempTextPosY;
        }
        $textPosY = mt_rand($textPosYMin, $textPosYMax);
        // Draw shadow
        if ($this->captchaConfig['shadow']) {
            $shadow_color = hex2rgb($this->captchaConfig['shadow_color']);
            $shadow_color = imagecolorallocate($captcha, $shadow_color['r'], $shadow_color['g'], $shadow_color['b']);
            imagettftext($captcha, $fontSize, $angle, $textPosX + $this->captchaConfig['shadow_offset_x'], $textPosY + $this->captchaConfig['shadow_offset_y'], $shadow_color, $font, $this->captchaConfig['code']);
        }
        // Draw text
        imagettftext($captcha, $fontSize, $angle, $textPosX, $textPosY, $color, $font, $this->captchaConfig['code']);

        ob_start();
        imagepng($captcha);
        $imageData = ob_get_contents();
        ob_end_clean();

        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    public function __construct($config = [])
    {
        $this->captchaConfig = self::$defaultConfig;
        foreach ($config as $key => $value) {
            $this->captchaConfig[$key] = $value;
        }
        $this->_prepareConfig();

        $this->redisConnect = new \Predis\Client([
            'scheme' => 'tcp',
            'host' => $this->captchaConfig['redis']['server'],
            'port' => $this->captchaConfig['redis']['port']
        ]);
    }
}
