<?php
// Read-only: `wp eval-file` on the rehearsal DB. Prints every ACF field (DB + PHP/ACF Composer
// registered, recursing into sub_fields/layouts) as {key: {name,type,parent}} JSON for the audit.
$out = [];
$walk = function (array $fields, string $parent) use (&$walk, &$out) {
    foreach ($fields as $f) {
        $out[$f['key']] = ['name' => $f['name'], 'type' => $f['type'], 'parent' => $parent, 'return_format' => $f['return_format'] ?? null];
        if (!empty($f['sub_fields'])) { $walk($f['sub_fields'], $f['key']); }
        foreach ($f['layouts'] ?? [] as $l) { $walk($l['sub_fields'] ?? [], $f['key']); }
    }
};
foreach (acf_get_field_groups() as $g) { $walk(acf_get_fields($g) ?: [], $g['key']); }
$blocks = function_exists('acf_get_block_types') ? array_keys(acf_get_block_types()) : [];
echo json_encode(['fields' => $out, 'blocks' => $blocks, 'groups' => count(acf_get_field_groups())], JSON_PRETTY_PRINT);
