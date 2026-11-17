# Print and pick on 2.0 staging: runbook

Owner runbook for turning on packing slip printing (PrintNode) and the PickingPal picker on the
DermaDirect 2.0 staging site (Kinsta, Bedrock). **Nothing here has been run.** Run it later, by
hand, one step at a time, reading the output of each command before the next.

All commands run over SSH on the 2.0 Kinsta site from the Bedrock root:

```sh
cd ~/public
wp option get siteurl   # confirm this is the 2.0 staging site, not production
```

If `siteurl` is not the 2.0 staging URL, stop.

## What the code does

- `web/app/mu-plugins/dd-migration-safety.php` blocks all outbound HTTP on every non-production
  site. `DD_SAFETY_ALLOW_HOSTS` lets listed hosts through over **https only**, exact host match,
  no wildcards. It is empty by default. Allowed calls are logged as `http-allowed` (query string
  stripped). Everything else stays blocked and logged.
- Packing slips auto-print when an order moves to **Ship** (`woocommerce_order_status_ship`).
  A successful print is stored in order meta `_dd_packing_slip_printed` and never repeated.
- On non-production only:
  - only review checkout orders (`_dd_review_order` = `1`) auto-print. Do **not** define
    `DD_PACKING_SLIP_PRINT_ALL_ORDERS` on 2.0 outside the approved testing mode below;
  - printing to the production warehouse printers **74857961, 71895499, 73994577** (with or
    without a `pn_` prefix) is refused unless `DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS` is
    `true` (see "Printing to the real warehouse printer (approved testing mode)" below).
    Neither flag is set by default, and the steps below assume neither is set.

## Warnings before you start

- **Never set `DD_SAFETY_HARNESS=off`.** Use `DD_SAFETY_ALLOW_HOSTS` only.
- **Never use production's PrintNode account, API key or printers on 2.0**, except in the
  approved testing mode described near the end of this runbook.
- **PickingPal: never press Deactivate on 2.0, and never deactivate the 2.0 site from the
  PickingPal account, while 2.0 still holds production's activation instance.** The instance was
  copied with the database, so deactivating it can switch off production's picker.
- **Never deactivate, delete or update the PickingPal WordPress plugin on 2.0** (`wp plugin
  deactivate`, `wp plugin delete`, `wp plugin update`, or the same actions on the Plugins
  screen) before the copied activation is cleared in step 3. Those actions can act on the stored
  instance, which until then is production's.
- Do not paste API keys as command arguments. Use the prompt pattern below so keys stay out of
  shell history and the process list.
- The Shiptheory webhook stays paused. It is out of scope for this runbook; do not re-enable it.

## Order of work

1. Back up the options you are about to change.
2. Turn auto-print off and clear production's PrintNode credentials.
3. PickingPal: clear production's copied activation and create a fresh 2.0 instance.
4. Set up the separate 2.0 PrintNode account and configure it (auto-print still off).
5. Set `DD_SAFETY_ALLOW_HOSTS` and verify with `wp dd safety`.
6. PickingPal: activate the spare seat in wp-admin.
7. Turn auto-print on and run the test plan.

Both clears (steps 2 and 3) **must** happen before the allow-list (step 5). Once
`api.printnode.com` is allowed, any production key still stored on 2.0 could reach production's
printers. Once `pickingpal.com` is allowed, any PickingPal call from 2.0 carries whatever
instance is stored, so production's instance must already be gone and replaced by a fresh one.
Until step 5 the harness blocks all outbound HTTP, so nothing in steps 1 to 4 can reach either
service.

## 1. Backup (outside the web root)

Backups contain production credentials. Keep them outside `~/public`, readable only by you, and
delete them at the end.

```sh
umask 077
mkdir -p ~/dd-print-pick-backup
cd ~/public
for o in flexible_printing_settings packing_slip_printnode_api_key packing_slip_printnode_printer_id \
         packaging_labels_printnode_api_key packaging_labels_printer_id packaging_labels_auto_print \
         wc_am_client_1513 wc_am_client_1513_instance wc_am_client_1513_activated \
         wc_am_1513_activate_success wc_am_client_1513_deactivate_checkbox; do
  wp option get "$o" --format=json > ~/dd-print-pick-backup/"$o".json 2>/dev/null \
    || echo "not present: $o"
done
ls -l ~/dd-print-pick-backup
```

## 2. Auto-print off, clear production PrintNode credentials

```sh
cd ~/public
wp option update packaging_labels_auto_print 0
```

Clear the PrintNode key and default printer inside `flexible_printing_settings` (the rest of
that option is kept):

```sh
wp option get flexible_printing_settings --format=json
wp option patch delete flexible_printing_settings print_node_api_key
wp option patch delete flexible_printing_settings default_printer
wp option get flexible_printing_settings --format=json   # confirm both keys are gone
```

If a key is reported as missing it was not set; carry on. If deleting a key breaks the plugin's
settings screen, set it to an empty string instead:

```sh
wp option patch update flexible_printing_settings print_node_api_key ''
wp option patch update flexible_printing_settings default_printer ''
```

Delete the older packing slip options if present:

```sh
wp option delete packing_slip_printnode_api_key
wp option delete packing_slip_printnode_printer_id
```

Check nothing else still holds a PrintNode key:

```sh
wp option list --search='*printnode*' --fields=option_name
wp option list --search='*print_node*' --fields=option_name
```

Anything listed that is not `packaging_labels_*` needs the same treatment before step 5.

## 3. PickingPal: clear production's copied activation, create a fresh instance

The options and transients below were copied from production and carry production's activation
instance. Clear them now, before the allow-list exists. Do not open the PickingPal screens in
wp-admin until step 6.

**Do not press Deactivate on 2.0. Do not deactivate anything from the PickingPal account.**

```sh
cd ~/public
wp option delete wc_am_client_1513
wp option delete wc_am_client_1513_instance
wp option delete wc_am_client_1513_activated
wp option delete wc_am_1513_activate_success
wp option delete wc_am_client_1513_deactivate_checkbox
wp transient delete wcpp_license_state_1513
wp transient delete wcpp_coms_fail
wp option list --search='wc_am_*1513*' --fields=option_name   # expect nothing
```

PickingPal only generates its instance in the plugin's activation hook, so the instance is now
empty and an activation would send an empty instance. Create a fresh instance and reset the
state the same way the plugin's `activation()` does:

```sh
wp eval 'update_option("wc_am_client_1513_instance", wp_generate_password(12, false)); update_option("wc_am_client_1513_deactivate_checkbox", "on"); update_option("wc_am_client_1513_activated", "Deactivated");'
```

Verify the new instance is set and differs from production's backed-up one, without printing
either value:

```sh
new=$(wp option get wc_am_client_1513_instance --format=json 2>/dev/null)
old=$(cat ~/dd-print-pick-backup/wc_am_client_1513_instance.json 2>/dev/null)
if [ -n "$new" ] && [ "$new" != '""' ] && [ "$new" != "$old" ]; then echo different; else echo 'SAME - STOP'; fi
unset new old

wp option get wc_am_client_1513_activated   # Deactivated
wp option list --search='wc_am_*1513*' --fields=option_name
# expect exactly: wc_am_client_1513_instance, wc_am_client_1513_deactivate_checkbox,
# wc_am_client_1513_activated
```

If it prints `SAME - STOP` (also printed if the instance is empty), stop. Do not continue to the
allow-list.

Deleting `wc_am_client_1513` also removed the stored PickingPal licence API key. For step 6, get
the licence key from the PickingPal account (**My Account** at pickingpal.com). Do not read it
out of the backup.

## 4. Separate PrintNode account for 2.0

1. Create a **new** PrintNode account for 2.0. Do not log in to or reuse production's account.
2. On a test PC, install the PrintNode client and sign it in to the new account.
3. Attach a test printer or a PDF printer (for example "Microsoft Print to PDF"). No warehouse
   printer may be added to this account.
4. In the PrintNode dashboard create an API key and note the test printer's id.

Configure 2.0 (auto-print stays off for now). The printer id **must not** be 74857961,
71895499 or 73994577; the site refuses those on staging anyway.

```sh
cd ~/public
read -rsp 'PrintNode API key (2.0 account): ' PN_KEY; echo
printf '%s' "$PN_KEY" | wp option update packaging_labels_printnode_api_key
unset PN_KEY

read -rp 'PrintNode test printer id: ' PN_PRINTER
case "$PN_PRINTER" in
  *74857961*|*71895499*|*73994577*) echo 'STOP: production warehouse printer'; unset PN_PRINTER ;;
  *) wp option update packaging_labels_printer_id "$PN_PRINTER"; unset PN_PRINTER ;;
esac

wp option get packaging_labels_printer_id
```

`wp option update` reads the value from STDIN when no value is given, so the key never appears
in shell history. Do not print the key back to check it.

## 5. Allow-list on Kinsta

Only start this once step 3 printed `different`.

Add this line to the site's `.env` in `~/public` (the deploy never overwrites `.env`), or set
the same variable in the Kinsta environment settings:

```sh
DD_SAFETY_ALLOW_HOSTS="api.printnode.com pickingpal.com www.pickingpal.com"
```

Hosts only: no `https://`, no paths, no ports, no `*`. Invalid entries are ignored. If you set it
in the Kinsta environment rather than `.env`, restart PHP from MyKinsta so it is picked up.

Verify:

```sh
cd ~/public
wp dd safety
```

Expect `Harness:  ACTIVE` and
`Allowed:  api.printnode.com pickingpal.com www.pickingpal.com (https only, exact host match)`.
The wp-admin notice shows the same list. If it says `none`, the variable was not picked up.

Do not set `DD_SAFETY_HARNESS=off` at any point.

## 6. PickingPal: activate the spare seat

In wp-admin go to **Settings > PickingPal License**, enter the licence API key taken from the
PickingPal account (**My Account** at pickingpal.com) and press **Activate**. The licence covers
2 sites; this uses the spare second seat with the fresh 2.0 instance created in step 3. Check:

```sh
cd ~/public
new=$(wp option get wc_am_client_1513_instance --format=json 2>/dev/null)
old=$(cat ~/dd-print-pick-backup/wc_am_client_1513_instance.json 2>/dev/null)
if [ -n "$new" ] && [ "$new" != '""' ] && [ "$new" != "$old" ]; then echo different; else echo 'SAME - STOP'; fi
unset new old                              # expect different: still the fresh 2.0 instance

wp option get wc_am_client_1513_activated  # Activated
wp dd safety                               # pickingpal.com entries logged as http-allowed
```

Confirm in the PickingPal account that two sites are now active and that production's
activation is unchanged.

## 7. Turn on auto-print and test

```sh
cd ~/public
wp option update packaging_labels_auto_print 1
```

Make sure the review window is active and the tester is flagged:

```sh
wp option get dd_review_sandbox --format=json
wp user meta get <tester-user-id> dd_review_tester   # expect 1
```

### Test plan

1. **Review order prints once.** As a flagged tester, place a review checkout order (Stripe test
   card). Confirm it is flagged:
   ```sh
   wp eval 'var_export(wc_get_order(<order-id>)->get_meta("_dd_review_order"));'   # '1'
   ```
   Move it to **Ship** in wp-admin (or
   `wp eval 'wc_get_order(<order-id>)->update_status("ship");'`).
   Confirm exactly one print job lands on the test printer, an order note says the slip was sent
   to PrintNode, and:
   ```sh
   wp eval 'var_export(wc_get_order(<order-id>)->get_meta("_dd_packing_slip_printed"));'
   wp dd safety   # an http-allowed entry for api.printnode.com/printjobs
   ```
2. **No double print.** Move the same order to another status, then to **Ship** again. Confirm
   no second job in PrintNode and an order note saying it was already printed.
3. **Imported real orders do not print.** Pick an imported order that is already **Completed**
   with nothing pending. Moving it changes a real imported record on 2.0 only: emails stay
   blocked by the harness and the Shiptheory webhook is paused. Note its current status, move it
   to **Ship**, confirm no print job and an order note saying it is not a review order, then put
   the status back to **Completed**.

If a print fails, the order note gives the reason (harness block, PrintNode HTTP code). Failed
prints are not marked printed, so moving the order to Ship again retries.

## Rollback

```sh
cd ~/public
wp option update packaging_labels_auto_print 0
```

Empty the allow-list in `~/public/.env` (or the Kinsta environment, then restart PHP):

```sh
DD_SAFETY_ALLOW_HOSTS=""
```

Verify `wp dd safety` shows `Allowed:  none (all outbound HTTP blocked)`.

Leave PickingPal activated on its own 2.0 instance. Do not restore production's PickingPal or
PrintNode values from the backup onto 2.0.

## Printing to the real warehouse printer (approved testing mode)

Only with explicit approval, and only after steps 1 to 7 above work on the test printer. This
makes 2.0 send packing slips to a real warehouse printer, for every order moved to **Ship**, not
only review orders. The default path above stays unchanged when these flags are not set.

1. **Tell the warehouse team first.** Agree a time window and which printer to use. Slips that
   come out of a warehouse printer from 2.0 are test slips and must not be picked or shipped.
2. Turn auto-print off while configuring:
   ```sh
   cd ~/public
   wp option update packaging_labels_auto_print 0
   ```
3. Add both flags to `~/public/.env` (or the Kinsta environment, then restart PHP):
   ```sh
   DD_PACKING_SLIP_PRINT_ALL_ORDERS=true
   DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS=true
   ```
   Only the exact value `true` turns a flag on. The flags are independent:
   `DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS` alone still prints review orders only.
4. Set the PrintNode API key for the account that holds the warehouse printer, via the prompt so
   it never appears on the command line or in shell history:
   ```sh
   read -rsp 'PrintNode API key: ' PN_KEY; echo
   printf '%s' "$PN_KEY" | wp option update packaging_labels_printnode_api_key
   unset PN_KEY
   ```
5. Set the printer id to the printer the warehouse names, and check it:
   ```sh
   read -rp 'Warehouse printer id: ' PN_PRINTER
   wp option update packaging_labels_printer_id "$PN_PRINTER"; unset PN_PRINTER
   wp option get packaging_labels_printer_id
   ```
   The red "Packing slips will not print" notice on the Packaging Labels settings screen should
   be gone. If it is still there, the flag was not picked up.
6. Turn auto-print on:
   ```sh
   wp option update packaging_labels_auto_print 1
   ```

Each order prints at most once: a successful print is stored in `_dd_packing_slip_printed` and
moving the same order to **Ship** again does not print it again. Only orders not yet printed
will print.

### Rollback

```sh
cd ~/public
wp option update packaging_labels_auto_print 0
```

Remove both lines from `~/public/.env` (or the Kinsta environment, then restart PHP):

```sh
DD_PACKING_SLIP_PRINT_ALL_ORDERS=true
DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS=true
```

Then put `packaging_labels_printer_id` and `packaging_labels_printnode_api_key` back to the 2.0
test account values from step 4, and tell the warehouse team testing is over.

## Clean up the backup

Once everything is confirmed, delete the backup, which contains production credentials:

```sh
shred -u ~/dd-print-pick-backup/*.json 2>/dev/null || rm -f ~/dd-print-pick-backup/*.json
rmdir ~/dd-print-pick-backup
```

## Go-live checklist for the warehouse test on 2.0

Run on 2.0 over SSH from `~/public` once the warehouse lead has sent (a) the warehouse PrintNode
printer id, (b) the PrintNode API key for the account that owns that printer, and (c) the
Shiptheory TEST account details. Production is never touched.

**Before starting (should already be true):**

- `wp dd safety | grep -E '^(Harness|WP_ENV|Allowed)'` shows Harness ACTIVE, WP_ENV staging, and
  Allowed includes `api.printnode.com` and `quark.madcapsule.com`. Do not change the harness.
  Print only those lines; the blocked table can contain URLs with tokens.
- `wp plugin list --status=active` includes woocommerce-pickingpal-pro, flexible-printing,
  print-invoices-packing-slip-labels-for-woocommerce (5.0.1), wt-woocommerce-invoice-addon and
  wt-woocommerce-packing-list-printnode.
- Shiptheory webhook 19 (points at the LIVE Shiptheory account) is paused and stays paused.
- WebToffee remote print (its own PrintNode add-on) stays off: automatic and manual both "No",
  no API key. Packing slips on 2.0 print only through the theme Packing Slip manager.

**A. Printer and PrintNode key** (needs: printer id, PrintNode key, approval to print to the
warehouse)

1. Tell the warehouse team the time window.
2. Follow [Printing to the real warehouse printer (approved testing mode)](#printing-to-the-real-warehouse-printer-approved-testing-mode)
   steps 2 to 6: both flags in `~/public/.env`, key via the hidden prompt, printer id,
   auto-print on.
3. Check the flags; this must print two `bool(true)`:
   ```sh
   wp eval 'var_dump(defined("DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS") && DD_PACKING_SLIP_ALLOW_PRODUCTION_PRINTERS, defined("DD_PACKING_SLIP_PRINT_ALL_ORDERS") && DD_PACKING_SLIP_PRINT_ALL_ORDERS);'
   ```
   The Packaging Labels settings screen must show no red "will not print" notice.
4. Never print the API key. Check it is set with
   `wp option get packaging_labels_printnode_api_key >/dev/null && echo set`.
5. Test with one order: move a paid test order to **Ship**, confirm exactly one slip printed and
   the order has `_dd_packing_slip_printed`.

**B. Shiptheory test account** (needs: the test account login; the warehouse lead owns that
account)

1. In the Shiptheory TEST account, add a WooCommerce channel for
   https://dermadirect20uk.kinsta.cloud. If it asks for WooCommerce REST API keys, create them on
   2.0 under WooCommerce > Settings > Advanced > REST API for an admin user, Read/Write,
   description "Shiptheory TEST". Paste them only into Shiptheory, never into chat, tickets or
   files.
2. Shiptheory then creates its own webhook on 2.0. List webhooks without printing URLs, then
   compare a short hash of each delivery URL (it can contain account identifiers):
   ```sh
   wp wc webhook list --user=<admin id> --fields=id,name,status
   wp wc webhook get <id> --user=<admin id> --field=delivery_url | sha256sum | cut -c1-12
   wp wc webhook get <id> --user=<admin id> --field=delivery_url | awk -F/ '{print $3}'
   ```
   The new webhook must be active and its hash must differ from webhook 19's. The last command
   prints only the host. If it is not `quark.madcapsule.com`, add that exact host to
   `DD_SAFETY_ALLOW_HOSTS` only with approval.
3. Leave webhook 19 paused. Do not delete it without approval.
4. Test with one order moved to **Ship**: it appears in the Shiptheory TEST account, not the live
   one.

**C. PickingPal**

- Already licensed on 2.0 (second seat). Never press Deactivate on 2.0; that frees or burns the
  seat. Test flow: Ship, Completed, Pending Pick, scan in PickingPal, Picked, Completed.

**Rollback (end of test)**

1. Auto-print off and both flags removed (see that section's [Rollback](#rollback-1)).
2. Pause the Shiptheory TEST webhook.
3. Revoke the "Shiptheory TEST" REST API key.
4. Tell the warehouse team testing is over.

## Out of scope

- The Shiptheory webhook stays paused.
- Production's PrintNode and PickingPal setup is not touched by anything here.
