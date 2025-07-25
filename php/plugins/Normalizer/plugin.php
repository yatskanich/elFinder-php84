<?php

/**
 * elFinder Plugin Normalizer
 * UTF-8 Normalizer of file-name and file-path etc.
 * nfc(NFC): Canonical Decomposition followed by Canonical Composition
 * nfkc(NFKC): Compatibility Decomposition followed by Canonical
 * This plugin require Class "Normalizer" (PHP 5 >= 5.3.0, PECL intl >= 1.0.0)
 * or PEAR package "I18N_UnicodeNormalizer"
 * ex. binding, configure on connector options
 *    $opts = array(
 *        'bind' => array(
 *            'upload.pre mkdir.pre mkfile.pre rename.pre archive.pre ls.pre' => array(
 *                'Plugin.Normalizer.cmdPreprocess'
 *            ),
 *            'upload.presave paste.copyfrom' => array(
 *                'Plugin.Normalizer.onUpLoadPreSave'
 *            )
 *        ),
 *        // global configure (optional)
 *        'plugin' => array(
 *            'Normalizer' => array(
 *                'enable'    => true,
 *                'nfc'       => true,
 *                'nfkc'      => true,
 *                'umlauts'   => false,
 *                'lowercase' => false,
 *                'convmap'   => array()
 *            )
 *        ),
 *        // each volume configure (optional)
 *        'roots' => array(
 *            array(
 *                'driver' => 'LocalFileSystem',
 *                'path'   => '/path/to/files/',
 *                'URL'    => 'http://localhost/to/files/'
 *                'plugin' => array(
 *                    'Normalizer' => array(
 *                        'enable'    => true,
 *                        'nfc'       => true,
 *                        'nfkc'      => true,
 *                        'umlauts'   => false,
 *                        'lowercase' => false,
 *                        'convmap'   => array()
 *                    )
 *                )
 *            )
 *        )
 *    );
 *
 * @package elfinder
 * @author  Naoki Sawada
 * @license New BSD
 */
class elFinderPluginNormalizer extends elFinderPlugin
{
    private $replaced = [];
    private $keyMap = [
        'ls' => 'intersect',
        'upload' => 'renames',
        'mkdir' => ['name', 'dirs']
    ];

    public function __construct($opts)
    {
        $defaults = [
            'enable' => true,  // For control by volume driver
            'nfc' => true,  // Canonical Decomposition followed by Canonical Composition
            'nfkc' => true,  // Compatibility Decomposition followed by Canonical
            'umlauts' => false, // Convert umlauts with their closest 7 bit ascii equivalent
            'lowercase' => false, // Make chars lowercase
            'convmap' => []// Convert map ('FROM' => 'TO') array
        ];

        $this->opts = array_merge($defaults, $opts);
    }

    public function cmdPreprocess($cmd, &$args, $elfinder, $volume)
    {
        $opts = $this->getCurrentOpts($volume);
        if (!$opts['enable']) {
            return false;
        }
        $this->replaced[$cmd] = [];
        $key = $this->keyMap[$cmd] ?? 'name';

        if (is_array($key)) {
            $keys = $key;
        } else {
            $keys = [$key];
        }
        foreach ($keys as $key) {
            if (isset($args[$key])) {
                if (is_array($args[$key])) {
                    foreach ($args[$key] as $i => $name) {
                        if ($cmd === 'mkdir' && $key === 'dirs') {
                            // $name need '/' as prefix see #2607
                            $name = '/' . ltrim((string)$name, '/');
                            $_names = explode('/', $name);
                            $_res = [];
                            foreach ($_names as $_name) {
                                $_res[] = $this->normalize($_name, $opts);
                            }
                            $this->replaced[$cmd][$name] = $args[$key][$i] = implode('/', $_res);
                        } else {
                            $this->replaced[$cmd][$name] = $args[$key][$i] = $this->normalize($name, $opts);
                        }
                    }
                } else if ($args[$key] !== '') {
                    $name = $args[$key];
                    $this->replaced[$cmd][$name] = $args[$key] = $this->normalize($name, $opts);
                }
            }
        }
        if ($cmd === 'ls' || $cmd === 'mkdir') {
            if (!empty($this->replaced[$cmd])) {
                // un-regist for legacy settings
                $elfinder->unbind($cmd, $this->cmdPostprocess(...));
                $elfinder->bind($cmd, $this->cmdPostprocess(...));
            }
        }
        return true;
    }

    public function cmdPostprocess($cmd, &$result, $args, $elfinder, $volume)
    {
        if ($cmd === 'ls') {
            if (!empty($result['list']) && !empty($this->replaced['ls'])) {
                foreach ($result['list'] as $hash => $name) {
                    if ($keys = array_keys($this->replaced['ls'], $name)) {
                        if (count($keys) === 1) {
                            $result['list'][$hash] = $keys[0];
                        } else {
                            $result['list'][$hash] = $keys;
                        }
                    }
                }
            }
        } else if ($cmd === 'mkdir') {
            if (!empty($result['hashes']) && !empty($this->replaced['mkdir'])) {
                foreach ($result['hashes'] as $name => $hash) {
                    if ($keys = array_keys($this->replaced['mkdir'], $name)) {
                        $result['hashes'][$keys[0]] = $hash;
                    }
                }
            }
        }
    }

    // NOTE: $thash is directory hash so it unneed to process at here
    public function onUpLoadPreSave(&$thash, &$name, $src, $elfinder, $volume)
    {
        $opts = $this->getCurrentOpts($volume);
        if (!$opts['enable']) {
            return false;
        }

        $name = $this->normalize($name, $opts);
        return true;
    }

    protected function normalize($str, $opts)
    {
        if ($opts['nfc'] || $opts['nfkc']) {
            if (class_exists('Normalizer', false)) {
                if ($opts['nfc'] && !Normalizer::isNormalized($str, Normalizer::FORM_C))
                    $str = Normalizer::normalize($str, Normalizer::FORM_C);
                if ($opts['nfkc'] && !Normalizer::isNormalized($str, Normalizer::FORM_KC))
                    $str = Normalizer::normalize($str, Normalizer::FORM_KC);
            } else {
                if (!class_exists('I18N_UnicodeNormalizer', false)) {
                    if (is_readable('I18N/UnicodeNormalizer.php')) {
                        include_once 'I18N/UnicodeNormalizer.php';
                    } else {
                        trigger_error('Plugin Normalizer\'s options "nfc" or "nfkc" require PHP class "Normalizer" or PEAR package "I18N_UnicodeNormalizer"', E_USER_WARNING);
                    }
                }
                if (class_exists('I18N_UnicodeNormalizer', false)) {
                    $normalizer = new I18N_UnicodeNormalizer();
                    if ($opts['nfc'])
                        $str = $normalizer->normalize($str, 'NFC');
                    if ($opts['nfkc'])
                        $str = $normalizer->normalize($str, 'NFKC');
                }
            }
        }
        if ($opts['umlauts']) {
            if (str_contains($str = htmlentities((string)$str, ENT_QUOTES, 'UTF-8'), '&')) {
                $str = html_entity_decode(
                    (string)preg_replace(
                        '~&([a-z]{1,2})(?:acute|caron|cedil|circ|grave|lig|orn|ring|slash|tilde|uml);~i',
                        '$1',
                        $str
                    ),
                    ENT_QUOTES,
                    'utf-8'
                );
            }
        }
        if ($opts['convmap'] && is_array($opts['convmap'])) {
            $str = strtr($str, $opts['convmap']);
        }
        if ($opts['lowercase']) {
            if (function_exists('mb_strtolower')) {
                $str = mb_strtolower((string)$str, 'UTF-8');
            } else {
                $str = strtolower((string)$str);
            }
        }
        return $str;
    }
}
