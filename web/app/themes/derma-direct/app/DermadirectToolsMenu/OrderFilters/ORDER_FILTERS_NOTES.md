# Order Filters Implementation Notes

## Overview
Comprehensive order filtering system integrated into WooCommerce orders page, providing advanced filtering capabilities beyond the default WooCommerce filters. Features a modal popup interface with applied filter pills for easy management.

## Features
1. **Order Status Filter**: Multi-select dropdown for filtering by one or multiple order statuses
2. **Payment Method Filter**: Dropdown to filter by payment gateway used
3. **Customer Group Filter**: Filter by registered vs non-registered customers
4. **Shipping Method Filter**: Text search for shipping method names
5. **Customer Details Filters**:
   - Customer email (with wildcard support)
   - First name (billing)
   - Last name (billing)
   - Phone number (billing/shipping)
6. **Billing Country Filter**: Multi-select dropdown for customer billing countries
7. **Track Number Filter**: Search by shipment tracking numbers
8. **SKU Filter**: Search orders containing products with specific SKUs
9. **Date Range Filter**: Filter orders by date range using date pickers
10. **Order Total Filter**: Filter by order total amount range (from/to)

## User Interface Features
- **Modal Popup**: Filters displayed in a clean modal overlay triggered by "Additional Filters" button
- **Applied Filter Pills**: Active filters shown as removable pills below the table navigation
- **Individual Filter Removal**: Click X on any filter pill to remove that specific filter
- **Clear All Filters**: Single button to remove all active filters at once
- **Responsive Layout**: Filters organized in a grid layout within the modal
- **Visual Feedback**: Form validation and loading states for better UX

## Technical Implementation
- **AND Logic**: All filters work together using AND logic (results match ALL selected criteria)
- **HPOS Compatible**: Supports both legacy post-based orders and High-Performance Order Storage (HPOS)
- **Wildcard Support**: Text filters support wildcards (*) and multiple terms (comma-separated)
- **Performance Optimized**: Uses efficient database queries with proper indexing
- **Admin UI Integration**: Seamlessly integrates with existing WooCommerce orders page

## Dependencies
- **WordPress**: 5.0+
- **WooCommerce**: 3.0+
- **PHP**: 7.4+
- No external libraries required (uses native WordPress/WooCommerce APIs)

## Database Queries
- **Legacy Orders**: Uses `posts` and `postmeta` tables with optimized JOIN queries
- **HPOS Orders**: Uses `wc_orders` and `wc_orders_meta` tables with custom query clauses
- **Order Items**: Integrates with `woocommerce_order_items` and `woocommerce_order_itemmeta` for SKU filtering

## File Structure
```
app/DermadirectToolsMenu/OrderFilters/
├── AdminPage.php                    # Admin menu and settings page
├── OrderFiltersManager.php          # Main filtering logic and hooks
└── ORDER_FILTERS_NOTES.md          # This documentation

resources/css/admin/
└── order-page.css                  # Admin styling (compiled via Vite)

resources/js/admin/
└── order-page.js                   # Admin JavaScript functionality (compiled via Vite)

public/build/assets/
├── order-page-[hash].css           # Compiled CSS with hash
└── order-page-[hash].js            # Compiled JavaScript with hash
```

## Build System
- **Vite**: Assets are built using Vite build system
- **Auto-Compilation**: Run `npm run build` to compile assets
- **Cache Busting**: Hashed filenames for cache invalidation
- **Asset Loading**: Uses Laravel Vite plugin for proper asset enqueuing

## Filter Parameters
| Filter | Parameter Name | Type | Description |
|--------|---------------|------|-------------|
| Order Status | `post_status[]` | array | Array of order status keys |
| Payment Method | `payment_customer_filter` | string | Payment gateway ID |
| Customer Group | `nonregistered_users_filter` | string | 'registered_users' or 'nonregistered_users' |
| Shipping Method | `shipping_method_filter` | string | Shipping method name (supports wildcards) |
| Customer Email | `user_email_search` | string | Email address (supports wildcards) |
| First Name | `user_billing_first_name` | string | Billing first name (supports wildcards) |
| Last Name | `user_billing_last_name` | string | Billing last name (supports wildcards) |
| Phone Number | `user_phone` | string | Phone number (supports wildcards) |
| Billing Country | `user_billing_country[]` | array | Array of country codes |
| Track Number | `shipping_track_number` | string | Tracking number (supports wildcards) |
| SKU | `filter_search_sku` | string | Product SKU (supports wildcards) |
| Date Range | `filter_start_date`, `filter_end_date` | string | Date format: YYYY-MM-DD |
| Order Total | `order_total_start`, `order_total_end` | float | Numeric amounts |

## Security Features
- **Input Sanitization**: All user inputs are properly sanitized using WordPress functions
- **SQL Injection Prevention**: Uses `$wpdb->prepare()` for all database queries
- **XSS Protection**: All outputs are escaped using `esc_html()`, `esc_attr()`, etc.
- **Capability Checks**: Only users with `manage_options` capability can access settings

## Performance Considerations
- **Efficient Queries**: Uses indexed database columns where possible
- **Query Optimization**: Combines multiple conditions in single queries
- **Pagination Support**: Works with WooCommerce's native pagination
- **Caching Friendly**: Filter state is saved  (legacy orders)
   - `woocommerce_order_list_table_restrict_manage_orders` - Display filters (HPOS)
   - `views_edit-shop_order` - Add "Additional Filters" button (legacy)
   - `views_woocommerce_page_wc-orders` - Add "Additional Filters" button (HPOS)
   - `posts_where` - Modify SQL queries (legacy)
   - `woocommerce_orders_table_query_clauses` - Modify HPOS queries
   - `pre_get_posts` - Handle date range filtering (legacy)

2. **Admin Hooks**:
   - `admin_menu` - Register admin page
   - `admin_enqueue_scripts` - Load CSS/JS assets via Vite
   - Conditional loading only on orders pages

3. **JavaScript Features**:
   - Modal show/hide functionality
   - Filter pill removal
   - Form validation
   - Dynamic filter repositioning below tablenav
   - Select all/deselect all for multiselects
1. **WooCommerce Hooks**:
   - `restrict_manage_posts` - Display filters
   - `posts_where` - Modify SQL queries (legacy)
   - `woocommerce_orders_table_query_clauses` - Modify HPOS queries
   - `pre_get_posts` - Handle date range filtering
modal popup and filter pills
5. ✅ Add JavaScript for modal interaction and filter management
6. ✅ Support both HPOS and legacy order storage
7. ✅ Implement proper security measures
8. ✅ Add comprehensive documentation
9. ✅ Integrate with Vite build system
10. ✅ Add applied filters pills with individual removal
11.x] Order status multiselect works correctly
- [x] Payment method filter shows all active gateways
- [x] Customer group filter distinguishes registered/guest users
- [x] Shipping method text search with wildcards
- [x] Customer detail filters work with various input formats
- [x] Billing country multiselect with all WooCommerce countries
- [ ] Track number search integrates with tracking plugins
- [x] SKU search finds orders with matching product SKUs
- [x] Date range picker validates and filters correctly
- [x] Order total range accepts decimal values
- [x] All filters work together with AND logic
- [x] HPOS compatibility verified
- [x] Performance tested with large order datasets
- [x] Mobile responsive design verified
- [x] Accessibility features tested
- [x] Modal popup UI functions correctly
- [x] Applied filter pills display and remove properly
- [x] Clear all filters button works
- [x] Vite asset compilation working
- [x] Filter repositioning below tablenavll active gateways
- [ ] Customer group filter distinguishes registered/guest users
- [ ] Shipping method text search with wildcards
- [ ] Customer detail filters work with various input formats
- [ ] Billing country multiselect with all WooCommerce countries
- [ ] Track number search integrates with tracking plugins
- [ ] SKU search finds orders with matching product SKUs
- [ ] Date range picker validates and filters correctly
- [ ] Order total range accepts decimal values
- [ ] All filters work together with AND logic
- [ ] HPOS compatibility verified
- [ ] Performance tested with large order datasets
- [ ] Mobile responsive design verified
- [ ] Accessibility features tested

## Future Enhancements
- **Custom Field Filters**: Support for custom order meta fields
- **Advanced Date Filters**: Relative dates (last 30 days, this month, etc.)
- **Export Functionality**: Export filtered results to CSV
- **Saved Filter Sets**: Allow saving and loading common filter combinations
- **API Integration**: REST API endpoints for programmatic filtering
