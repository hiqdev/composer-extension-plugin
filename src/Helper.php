<?php

namespace hiqdev\composerextensionplugin;

use Closure;
use ReflectionFunction;

/**
 * Helper class.
 *
 * @author Andrii Vasyliev <sol@hiqdev.com>
 */
class Helper
{
    /**
     * Merges two or more arrays into one recursively.
     * Based on Yii2 yii\helpers\BaseArrayHelper::merge.
     * @param array $a array to be merged to
     * @param array $b array to be merged from
     * @return array the merged array
     */
    public static function mergeConfig($a, $b)
    {
        $args = func_get_args();
        $res = array_shift($args);
        foreach ($args as $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $k => $v) {
                if (is_int($k)) {
                    if (isset($res[$k])) {
                        $res[] = $v;
                    } else {
                        $res[$k] = $v;
                    }
                } elseif (is_array($v) && isset($res[$k]) && is_array($res[$k])) {
                    $res[$k] = self::mergeConfig($res[$k], $v);
                } else {
                    $res[$k] = $v;
                }
            }
        }

        return $res;
    }

    /**
     * Dumps closure object to string.
     * Based on http://www.metashock.de/2013/05/dump-source-code-of-closure-in-php/
     * @param Closure $c 
     * @return string
     */
    public static function dumpClosure(Closure $c) {
        $res = 'function (';
        $fun = new ReflectionFunction($c);
        $args = [];
        foreach($fun->getParameters() as $arg) {
            $str = '';
            if($arg->isArray()) {
                $str .= 'array ';
            } else if($arg->getClass()) {
                $str .= $arg->getClass()->name . ' ';
            }
            if($arg->isPassedByReference()){
                $str .= '&';
            }
            $str .= '$' . $arg->name;
            if ($arg->isOptional()) {
                $str .= ' = ' . var_export($arg->getDefaultValue(), true);
            }
            $args[] = $str;
        }
        $res .= implode(', ', $args);
        $res .= ') {' . PHP_EOL;
        $lines = file($fun->getFileName());
        for ($i = $fun->getStartLine(); $i < $fun->getEndLine(); $i++) {
            $res .= $lines[$i];
        }

        return rtrim($res, "\n ,");
    }

    /**
     * Returns a parsable string representation of given value.
     * In contrast to var_dump outputs Closures as PHP code.
     * @param mixed $value
     * @return string
     */
    public static function exportVar($value)
    {
        $closures = self::collectClosures($value);
        $res = var_export($value, true);
        if (!empty($closures)) {
            $subs = [];
            foreach ($closures as $key => $closure) {
                $subs["'" . $key . "'"] = self::dumpClosure($closure);
            }
            $res = strtr($res, $subs);
        }

        return $res;
    }

    /**
     * Collects closures from given input.
     * Substitutes closures with a tag.
     * @param mixed $input will be changed
     * @return array array of found closures
     */
    private static function collectClosures(&$input) {
        static $closureNo = 1;
        $closures = [];
        if (is_array($input)) {
            foreach ($input as &$value) {
                if (is_array($value) || $value instanceof Closure) {
                    $closures = array_merge($closures, self::collectClosures($value));
                }
            }
        } elseif ($input instanceof Closure) {
            $closureNo++;
            $key = "--==<<[[((Closure#$closureNo))]]>>==--";
            $closures[$key] = $input;
            $input = $key;
        }

        return $closures;
    }
}
