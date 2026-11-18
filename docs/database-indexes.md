# Database indexes

The must-use plugin `web/app/mu-plugins/dd-db-indexes.php` declares the custom database indexes the
site needs and manages them from WP-CLI, so 2.0, production and local copies all end up with the
same schema.

## Why

| Table | Index | Columns | Reason |
| --- | --- | --- | --- |
| `<prefix>postmeta` | `meta_key_value` | `meta_key(64), meta_value(64)` | Woo Discount Rules first-order lookup |

Woo Discount Rules Pro's "first order" condition runs a `WP_Query` on `shop_order` posts joined to
postmeta with `meta_key = '_customer_user'` (or `'_billing_email'`) and `meta_value = <value>`.
Orders are still stored in posts and postmeta (HPOS is off, with sync on). Core WordPress only
indexes `meta_key` on postmeta, so MySQL scans every `_customer_user` row: 10 to 30 seconds per
uncached product page, cart or REST request on a postmeta table of about 12.9 million rows.

Production already has this index (`SHOW INDEX` shows `meta_key_value: meta_key(64),
meta_value(64)`). The 2.0 database was rebuilt without it.

## Commands

```bash
wp dd db-indexes status            # table, index, state (present / missing / different / error)
wp dd db-indexes ensure --dry-run  # print the ALTER statements that would run, change nothing
wp dd db-indexes ensure            # create missing indexes, print the time each one took
```

`ensure` runs `ALTER TABLE <prefix>postmeta ADD INDEX meta_key_value (meta_key(64),
meta_value(64)), ALGORITHM=INPLACE, LOCK=NONE`. The table stays readable and writable while the
index builds; expect several minutes on a full-size postmeta table. Run it from a shell session
that will not be cut off (for example inside `screen` or `tmux`).

Safety rules built into the plugin:

- The ALTER never runs on a web request, only from WP-CLI.
- Only missing indexes are created. An index that exists under the same name with different
  columns, order, prefix lengths or uniqueness is reported as `different` and left alone; fix it by
  hand after checking what uses it.
- If the same columns are already indexed under another name, `ensure` reports it and does not
  build a duplicate.
- If the table is missing or `SHOW INDEX` fails, the command reports it and does nothing.
- After a run where every required index is confirmed present, the option `dd_db_indexes_version`
  (autoload no) records the schema version. `status` shows the code version next to the stored one.

In wp-admin, administrators see a warning notice when an index is not present. The check is cached
in a transient for 12 hours and never runs on the front end, AJAX or REST. `ensure` clears the cache.

## When to run it

Run `wp dd db-indexes status` and then `wp dd db-indexes ensure` **once per environment after
every database import or rebuild** (2.0 staging now, production after cutover, local copies after
loading a dump). On production `ensure` should report `present, nothing to do` and only record the
schema version.

## Adding an index

Add an entry to `required_indexes()` in the mu-plugin, bump `SCHEMA_VERSION`, extend
`scripts/tests/db_indexes_test.php`, then run `ensure` in each environment.
