<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

/** Built-in detectors; the caller owns alias allocation and local persistence. */
final class IdentityMasker {
    public static function redact(string $text, array $categories, callable $alias): string {
        if (!empty($categories['emails'])) {
            $text = self::replace(
                '~(?<![\pL\pN.!#$%&\'*+/=?^_\x60{|}\x7e-])[\pL\pN!#$%&\'*+/=?^_\x60{|}\x7e-]+(?:\.[\pL\pN!#$%&\'*+/=?^_\x60{|}\x7e-]+)*@(?:[\pL\pN](?:[\pL\pN-]*[\pL\pN])?\.)+[\pL]{2,63}(?![\pL\pN_-])~u',
                static fn($m) => self::email($m[0], $alias), $text
            );
        }
        if (!empty($categories['personal_paths'])) {
            // Preserve the drive, Users/home prefix, separators and file names.
            $text = self::replace(
                '~(?<![\pL\pN_])([A-Za-z]:\\\\{1,2}Users\\\\{1,2})([^\\\\/\x00-\x1f:*?"<>|]{1,128})(?=\\\\|/|["\']|$)~iu',
                static function($m) use ($alias) {
                    if (in_array(strtolower($m[2]), ['public', 'default', 'default user', 'all users'], true)) {
                        return $m[0];
                    }
                    return $alias($m[0], 'personal_path', $m[1]);
                }, $text
            );
            $text = self::replace(
                '~(?<![\pL\pN_])(/(?:home|Users)/)([\pL\pN_.@-]{1,128})(?=/|["\'\s]|$)~u',
                static function($m) use ($alias) {
                    return $m[1] === '/Users/' && $m[2] === 'Shared'
                        ? $m[0] : $alias($m[0], 'personal_path', $m[1]);
                }, $text
            );
        }
        if (!empty($categories['accounts'])) {
            // Domain-qualified accounts, excluding path components and UNC paths.
            $text = self::replace(
                '~(?<![\pL\pN_.:/\\\\-])([\pL\pN_][\pL\pN_.-]{0,63})\\\\{1,2}([\pL\pN_$][\pL\pN_.@$-]{0,127})(?![\pL\pN_./\\\\-])~u',
                static fn($m) => $alias($m[0], 'account'), $text
            );
            $text = self::labelled($text, '(?:user(?:[_ -]?(?:name|id))?|login(?:[_ -]?name)?|account(?:[_ -]?(?:name|id))?|upn)',
                'account', $alias);
        }
        if (!empty($categories['mac_addresses'])) {
            $text = self::replace(
                '~(?<![A-Fa-f0-9:.-])(?:[A-Fa-f0-9]{2}(?::[A-Fa-f0-9]{2}){5}|[A-Fa-f0-9]{2}(?:-[A-Fa-f0-9]{2}){5}|[A-Fa-f0-9]{4}(?:\.[A-Fa-f0-9]{4}){2})(?![A-Fa-f0-9:.-])~',
                static fn($m) => $alias($m[0], 'mac'), $text
            );
        }
        if (!empty($categories['asset_identifiers'])) {
            // Includes Zabbix inventory keys and NetBox's serial/asset_tag keys.
            $text = self::labelled($text,
                '(?:serial(?:[_ -]?(?:number|no))?(?:[_ -]?[ab])?|asset[_ -]?(?:tag|id|number))', 'asset', $alias);
        }
        return $text;
    }

    private static function email(string $value, callable $alias): string {
        $at = strrpos($value, '@');
        $local = substr($value, 0, $at);
        $prefix = '';
        // RFC email local parts allow /?= characters. In monitoring URLs those
        // often belong to the URL's path/query instead; preserve that structure.
        if (strpos($local, '//') === 0) {
            $slash = strrpos($local, '/');
            $prefix = substr($local, 0, $slash + 1);
            $local = substr($local, $slash + 1);
        }
        if (($local[0] ?? '') === '?' || ($local[0] ?? '') === '&'
                || ($prefix !== '' && strpos($local, '?') !== false)) {
            $equal = strrpos($local, '=');
            if ($equal !== false) {
                $prefix .= substr($local, 0, $equal + 1);
                $local = substr($local, $equal + 1);
            }
        }
        elseif (preg_match('~^((?:e?mail|email_address|recipient|sendto|to|cc|bcc|username|login)=)(.+)$~i', $local, $parts)) {
            $prefix .= $parts[1];
            $local = $parts[2];
        }
        return $local === '' ? $value : $prefix.$alias($local.substr($value, $at), 'email');
    }

    private static function labelled(string $text, string $labels, string $type, callable $alias): string {
        $pattern = '~(?<![\pL\pN_])('.$labels.'(?:["\']|\*\*)?[ \t]*[:=][ \t]*)'
            .'(?:"((?:\\\\.|[^"\\\\\r\n])*)"|\'((?:\\\\.|[^\'\\\\\r\n])*)\'|([\pL\pN_@.\\\\/+$-]+))~iu';
        return self::replace($pattern, static function($m) use ($type, $alias) {
            $quote = '';
            if (isset($m[4]) && $m[4] !== '') {
                $value = $m[4];
            }
            elseif (isset($m[3]) && $m[3] !== '') {
                $value = $m[3];
                $quote = "'";
            }
            else {
                $value = $m[2] ?? '';
                $quote = '"';
            }
            if ($value === '' || preg_match('/^(?:null|none|unknown|n\/a|redacted)$/i', $value)) {
                return $m[0];
            }
            return $m[1].$quote.$alias($value, $type).$quote;
        }, $text);
    }

    private static function replace(string $pattern, callable $callback, string $text): string {
        $result = preg_replace_callback($pattern, $callback, $text);
        if ($result === null) {
            throw new \RuntimeException('Built-in identity masking could not process this text.');
        }
        return $result;
    }
}
