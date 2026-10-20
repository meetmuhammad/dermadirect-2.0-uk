<?php
/**
 * Reference rewriting — pure functions, no database. Every rewrite is scoped: a number is only
 * replaced where a rule says that position holds an ID. Any mapped number found elsewhere is
 * returned as "unresolved" and the value is left untouched, so callers can stop instead of guessing.
 */
declare(strict_types=1);

namespace DDMig\Refs;

const ACF_REF_TYPES = ['image', 'file', 'gallery', 'post_object', 'relationship', 'page_link'];

/** Standalone decimal tokens in $map: not glued to letters/digits/underscore, not decimals. */
function tokens(string $s, array $map): array
{
    preg_match_all('/(?<![0-9A-Za-z_.])[0-9]+(?![0-9A-Za-z_]|\.[0-9])/', $s, $m, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m[0] as [$tok, $off]) {
        if ((string) (int) $tok === $tok && isset($map[(int) $tok])) {
            $out[] = [(int) $tok, $off];
        }
    }
    return $out;
}

function rewrite_int(string $v, array $map): string
{
    $t = trim($v);
    return (ctype_digit($t) && isset($map[(int) $t])) ? (string) $map[(int) $t] : $v;
}

function rewrite_csv(string $v, array $map): string
{
    return implode(',', array_map(fn ($p) => rewrite_int($p, $map), explode(',', $v)));
}

/** Maps an int or numeric string leaf, preserving its PHP type. */
function map_scalar(mixed $v, array $map, ?bool &$hit = null): mixed
{
    $hit = false;
    if (is_int($v) && isset($map[$v])) {
        $hit = true;
        return $map[$v];
    }
    if (is_string($v) && ctype_digit($v) && (string) (int) $v === $v && isset($map[(int) $v])) {
        $hit = true;
        return (string) $map[(int) $v];
    }
    return $v;
}

function path_matches(array $path, string $pattern): bool
{
    $p = explode('/', $pattern);
    if (count($p) !== count($path)) {
        return false;
    }
    foreach ($p as $i => $seg) {
        if ($seg !== '*' && $seg !== (string) $path[$i]) {
            return false;
        }
    }
    return true;
}

/**
 * $paths: patterns like 'custom_css_post_id', '* /media_file_id', 'items/* /id' (no spaces),
 * '@values' (every leaf value is an ID) and '@keys' (every array key at any depth is an ID).
 */
function rewrite_serialized(string $v, array $map, array $paths): array
{
    $data = @unserialize($v, ['allowed_classes' => false]);
    if ($data === false && $v !== 'b:0;') {
        return ['value' => $v, 'unresolved' => tokens($v, $map) ? ['@unserializable'] : []];
    }
    if (serialize($data) !== $v) {
        return ['value' => $v, 'unresolved' => tokens($v, $map) ? ['@not-roundtrippable'] : []];
    }
    $unresolved = [];
    $allValues = in_array('@values', $paths, true);
    $allKeys = in_array('@keys', $paths, true);
    $walk = function ($node, array $path) use (&$walk, $map, $paths, $allValues, $allKeys, &$unresolved) {
        if (!is_array($node)) {
            $new = map_scalar($node, $map, $hit);
            if (!$hit) {
                if (is_string($node) && tokens($node, $map)) {
                    $unresolved[] = implode('/', $path);
                }
                return $node;
            }
            foreach ($paths as $p) {
                if ($p[0] !== '@' && path_matches($path, $p)) {
                    return $new;
                }
            }
            if ($allValues) {
                return $new;
            }
            $unresolved[] = implode('/', $path);
            return $node;
        }
        $out = [];
        foreach ($node as $k => $child) {
            $nk = $k;
            if (is_int($k) && isset($map[$k])) {
                if ($allKeys) {
                    $nk = $map[$k];
                } elseif (!$allValues) {
                    $unresolved[] = '@key:' . $k;
                }
            }
            $out[$nk] = $walk($child, array_merge($path, [$k]));
        }
        return $out;
    };
    $new = $walk($data, []);
    return ['value' => $unresolved ? $v : serialize($new), 'unresolved' => $unresolved];
}

/** Regex rules for IDs embedded in text. Each has exactly one capture group: the ID. */
function pattern_rules(): array
{
    return [
        'query_id' => '/[?&](?:amp;|#038;)?(?:p|page_id|attachment_id|post)=([0-9]+)(?![0-9])/',
        // a GUID whose whole path is the post ID (WordPress writes these for some menu items): https://host/123/
        'path_id' => '#^https?://[^/?\#]+/([0-9]+)/?$#',
        'revision_name' => '/^([0-9]+)-(?:revision|autosave)-v[0-9]+$/',
        'whole' => '/^([0-9]+)$/',
        'wp_image' => '/\bwp-image-([0-9]+)(?![0-9])/',
        'data_id' => '/\bdata-(?:id|media-id|attachment-id)="([0-9]+)"/',
        'gallery_ids' => '/\[gallery[^\]]*\bids="([0-9,\s]+)"/',
    ];
}

/** Rewrites only IDs captured by the named patterns; reports every other mapped token. */
function rewrite_patterns(string $v, array $map, array $names, int $baseOffset = 0, string $label = ''): array
{
    $rules = pattern_rules();
    $claimed = [];
    $edits = [];
    foreach ($names as $n) {
        preg_match_all($rules[$n], $v, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $mm) {
            [$txt, $off] = $mm[1];
            preg_match_all('/[0-9]+/', $txt, $ids, PREG_OFFSET_CAPTURE);
            foreach ($ids[0] as [$id, $o]) {
                $claimed[$off + $o] = true;
                if (isset($map[(int) $id]) && (string) (int) $id === $id) {
                    $edits[$off + $o] = [strlen($id), (string) $map[(int) $id]];
                }
            }
        }
    }
    $unresolved = [];
    foreach (tokens($v, $map) as [$id, $off]) {
        if (!isset($claimed[$off])) {
            $unresolved[] = $label . $id . '@' . ($off + $baseOffset);
        }
    }
    if ($unresolved) {
        return ['value' => $v, 'unresolved' => $unresolved];
    }
    krsort($edits);
    foreach ($edits as $off => [$len, $rep]) {
        $v = substr_replace($v, $rep, $off, $len);
    }
    return ['value' => $v, 'unresolved' => []];
}

/** WordPress serialize_block_attributes() encoding. */
function encode_block_attrs(mixed $attrs): string
{
    $s = json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $s = preg_replace('/--/', '\\u002d\\u002d', $s);
    $s = preg_replace('/</', '\\u003c', $s);
    $s = preg_replace('/>/', '\\u003e', $s);
    $s = preg_replace('/&/', '\\u0026', $s);
    return preg_replace('/\\\\"/', '\\u0022', $s);
}

/** Block attribute keys that hold attachment/post IDs, by block name ('*' = any block). */
function block_ref_keys(string $block): array
{
    $any = ['mediaId', 'attachmentId', 'imageId', 'featuredImageId'];
    $by = [
        'core/image' => ['id'], 'core/cover' => ['id'], 'core/video' => ['id'], 'core/audio' => ['id'],
        'core/file' => ['id'], 'core/media-text' => ['mediaId'], 'core/gallery' => ['ids'],
        'core/block' => ['ref'], 'core/navigation' => ['ref'], 'core/post-featured-image' => [],
    ];
    return array_merge($any, $by[$block] ?? []);
}

/**
 * Rewrites IDs in block markup: typed attribute paths (core/GenerateBlocks keys, ACF block data by
 * field type) plus HTML patterns in inner content. Attribute JSON is only re-encoded when the
 * original encodes back byte-identically; otherwise any hit is reported.
 */
function rewrite_blocks(string $html, array $map, array $acfFields): array
{
    $unresolved = [];
    $re = '/<!--\s+wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{.*?\})\s+(\/)?-->/s';
    preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    $edits = [];
    $commentSpans = [];
    foreach ($m as $mm) {
        $name = str_contains($mm[1][0], '/') ? $mm[1][0] : 'core/' . $mm[1][0];
        [$json, $joff] = $mm[2];
        $commentSpans[] = [$mm[0][1], $mm[0][1] + strlen($mm[0][0])];
        if (!tokens($json, $map)) {
            continue;
        }
        $attrs = json_decode($json);
        if (!is_object($attrs) || encode_block_attrs($attrs) !== $json) {
            $unresolved[] = "$name:@not-roundtrippable";
            continue;
        }
        $refKeys = block_ref_keys($name);
        // ACF block data: a key is a reference iff its "_key" sibling names a field of a ref type.
        $acfRef = [];
        if (str_starts_with($name, 'acf/') && isset($attrs->data) && is_object($attrs->data)) {
            foreach (get_object_vars($attrs->data) as $k => $v) {
                $fk = $attrs->data->{'_' . $k} ?? null;
                $acfRef[$k] = is_string($fk) && in_array($acfFields[$fk]['type'] ?? '', ACF_REF_TYPES, true);
            }
        }
        $walk = function ($node, array $path) use (&$walk, $map, $refKeys, $acfRef, $name, &$unresolved) {
            if (is_object($node)) {
                foreach (get_object_vars($node) as $k => $child) {
                    $node->$k = $walk($child, array_merge($path, [$k]));
                }
                return $node;
            }
            if (is_array($node)) {
                foreach ($node as $i => $child) {
                    $node[$i] = $walk($child, array_merge($path, [$i]));
                }
                return $node;
            }
            $new = map_scalar($node, $map, $hit);
            if (!$hit) {
                if (is_string($node) && tokens($node, $map)) {
                    $unresolved[] = "$name:" . implode('.', $path);
                }
                return $node;
            }
            $top = (string) $path[0];
            $isRef = false;
            if (str_starts_with($name, 'acf/') && $top === 'data' && isset($path[1])) {
                $isRef = $acfRef[$path[1]] ?? false;
            } elseif (in_array($top, $refKeys, true) && (count($path) === 1 || ($top === 'ids' && count($path) === 2))) {
                $isRef = true;
            }
            if ($isRef) {
                return $new;
            }
            $unresolved[] = "$name:" . implode('.', $path);
            return $node;
        };
        $before = count($unresolved);
        $newAttrs = $walk($attrs, []);
        if (count($unresolved) === $before) {
            $edits[$joff] = [strlen($json), encode_block_attrs($newAttrs)];
        }
    }
    // Inner HTML (everything outside block comment delimiters): only known HTML ID patterns.
    $out = '';
    $pos = 0;
    $segments = [];
    foreach ($commentSpans as [$s, $e]) {
        $segments[] = [$pos, $s];
        $pos = $e;
    }
    $segments[] = [$pos, strlen($html)];
    foreach ($segments as [$s, $e]) {
        $seg = substr($html, $s, $e - $s);
        if ($seg === '' || !tokens($seg, $map)) {
            continue;
        }
        $r = rewrite_patterns($seg, $map, ['wp_image', 'data_id', 'query_id', 'gallery_ids'], $s, 'html:');
        array_push($unresolved, ...$r['unresolved']);
        if ($r['value'] !== $seg) {
            $edits[$s] = [strlen($seg), $r['value']];
        }
    }
    if ($unresolved) {
        return ['value' => $html, 'unresolved' => $unresolved];
    }
    krsort($edits);
    foreach ($edits as $off => [$len, $rep]) {
        $html = substr_replace($html, $rep, $off, $len);
    }
    return ['value' => $html, 'unresolved' => []];
}

/**
 * Independent verification: true iff $after differs from $before only by replacing mapped IDs with
 * their targets. Serialized values are compared structurally; everything else token by token.
 */
function equivalent_under_map(string $before, string $after, array $map): bool
{
    if ($before === $after) {
        return true;
    }
    $b = @unserialize($before, ['allowed_classes' => false]);
    $a = @unserialize($after, ['allowed_classes' => false]);
    if ($b !== false && $a !== false) {
        return struct_equiv($b, $a, $map);
    }
    $split = fn ($s) => preg_split('/([0-9]+)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $tb = $split($before);
    $ta = $split($after);
    if (count($tb) !== count($ta)) {
        return false;
    }
    foreach ($tb as $i => $x) {
        if ($x === $ta[$i]) {
            continue;
        }
        if (!(ctype_digit($x) && isset($map[(int) $x]) && (string) $map[(int) $x] === $ta[$i])) {
            return false;
        }
    }
    return true;
}

function struct_equiv(mixed $b, mixed $a, array $map): bool
{
    if (is_array($b) !== is_array($a)) {
        return false;
    }
    if (!is_array($b)) {
        if ($b === $a) {
            return true;
        }
        $m = map_scalar($b, $map, $hit);
        return $hit && $m === $a;
    }
    if (count($b) !== count($a)) {
        return false;
    }
    $ak = array_keys($a);
    $i = 0;
    foreach ($b as $k => $v) {
        $expectKey = (is_int($k) && isset($map[$k]) && $ak[$i] === $map[$k]) ? $map[$k] : $k;
        if ($ak[$i] !== $expectKey || !struct_equiv($v, $a[$expectKey], $map)) {
            return false;
        }
        $i++;
    }
    return true;
}

/** Expands [from, to] pairs with their JSON-escaped forms ("/" -> "\/"), longest first. */
function url_pairs(array $pairs): array
{
    $out = [];
    foreach ($pairs as [$from, $to]) {
        $out[$from] = $to;
        $out[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
    }
    uksort($out, fn ($a, $b) => strlen($b) <=> strlen($a));
    return $out;
}

/**
 * Rewrites legacy URLs/paths inside a copied value. Serialized values are unserialized, every
 * string (recursively, including nested serialized strings) is rewritten, then reserialized so
 * lengths stay valid. Returns null if a value needs rewriting but is not safely roundtrippable.
 */
function rewrite_urls(string $v, array $pairs): ?string
{
    $needs = false;
    foreach ($pairs as $from => $_) {
        if (str_contains($v, $from)) {
            $needs = true;
            break;
        }
    }
    if (!$needs) {
        return $v;
    }
    if (preg_match('/^(a|s|O|i|d|b):[0-9:{"]/', $v)) {
        $data = @unserialize($v, ['allowed_classes' => false]);
        if ($data === false || serialize($data) !== $v) {
            return null;
        }
        $bad = false;
        $walk = function ($n) use (&$walk, $pairs, &$bad) {
            if (is_array($n)) {
                return array_map($walk, $n);
            }
            if (is_object($n)) {
                // __PHP_Incomplete_Class keeps its original class on serialize(); rewrite its props
                foreach (get_object_vars($n) as $k => $x) {
                    $n->$k = $walk($x);
                }
                return $n;
            }
            if (is_string($n)) {
                $r = rewrite_urls($n, $pairs);
                if ($r === null) {
                    $bad = true;
                    return $n;
                }
                return $r;
            }
            return $n;
        };
        $new = serialize($walk($data));
        return $bad ? null : $new;
    }
    return strtr($v, $pairs);
}
