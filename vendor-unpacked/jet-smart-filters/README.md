# JetSmartFilters

# ChangeLog

## 3.8.5
- FIX: Elementor Pro Products provider wrappers are now preserved when filtered requests return no results.
- FIX: Alphabet filtering now works with JetEngine term listings and Elementor taxonomy Loop Grids.
- FIX: WooCommerce archive pagination now updates correctly during AJAX filtering, including JetEngine archive templates.
- FIX: Filtered user meta queries no longer return duplicate users.
- FIX: WooCommerce product attribute filtering and indexed counts now support Unicode taxonomy names.
- FIX: Range filters now apply keyboard step changes correctly.
- FIX: Elementor Pro Loop Grid now preserves the posts-per-page value for Current Query sources.
- FIX: Signed AJAX requests now work correctly with JetEngine Calendar filtering.
- UPD: JetDashboard license handling now avoids automatic remote synchronization and refreshes plugin update data correctly after license changes.
- FIX: Self-referrer AJAX requests no longer collide with public WordPress provider query variables.

## 3.8.4
- FIX: Plain query parsing and self AJAX referrer URLs now support custom permalink symbols while preserving legacy URL compatibility.
- FIX: Polylang permalink filters and Apply Button redirects now use the correct translated pages and taxonomy terms.
- FIX: Indexed Collapsible Group filters no longer show parent toggles when all child terms are empty.
- FIX: Direct visits to self-referrer URLs now redirect to the clean frontend URL instead of being processed as AJAX requests.
- FIX: JetEngine archive templates no longer trigger warnings when pagination query properties are missing.
- FIX: Dynamic Range min/max values now use published products only.
- FIX: WooCommerce product taxonomy filters now hide terms with no catalog-visible products when out-of-stock items are hidden.
- UPD: JetDashboard now improves remote license synchronization and plugin management safeguards.
- FIX: Permalink filter values are now encoded correctly.

## 3.8.3.1
- FIX: SQL query handling for filters and the indexer was hardened, including meta filter indexer conditions.

## 3.8.3
- FIX: Hierarchical Select and grouped Select filters now calculate responsive widths correctly.
- FIX: WooCommerce attribute filtering and indexed counts now handle hidden out-of-stock variations correctly.
- FIX: Datepicker styles are now scoped to JetSmartFilters controls to prevent leakage into other datepickers.
- FIX: Gutenberg filter controls layout was corrected for the latest WordPress editor behavior.
- FIX: Elementor Pro Loop Grid filtering now works correctly on single templates and preserves query constraints with in-stock variation filtering.
- FIX: Range filter decimal maximum values now respect the default step.
- FIX: WooCommerce compatibility settings now show the correct notice when WooCommerce is inactive.
- FIX: Filtering inside Bricks popups now works correctly.
- FIX: Checkboxes and Radio filters in Bricks now apply Gap correctly between the marker and label on RTL websites.

## 3.8.2.1
- FIX: Dynamic Range min/max values now work correctly for CPT listing providers.
- FIX: Empty filter requests no longer trigger unnecessary provider data decoding.

## 3.8.2
- FIX: Private posts no longer appear in AJAX filter results.
- FIX: WPML-translated Apply Button redirects now preserve the correct translated URL.
- FIX: Additional Settings search now keeps parent chains visible.
- FIX: JetDashboard Smart Filters admin links now point to the correct plugin pages.
- FIX: Custom URL Symbols delimiters now work correctly with prefiltered URLs.
- FIX: Filter template scroll height is now rendered correctly.
- FIX: Hierarchical Select no longer duplicates level updates and stays synchronized after popup reopen.
- FIX: Visual filter "All" option color control now works correctly.
- FIX: Dynamic Range min/max values now work correctly with auto indexing, WooCommerce variation prices, non-indexed post types, and JetEngine custom storage.
- FIX: Search filter plain query parameters are now URL-encoded correctly.

## 3.8.1.1
- FIX: SQL injection, appeared on combination `Hide out of stock` option + taxonomies/attributes filter. 

## 3.8.1
- FIX: Active filter values set to `0` now remain visible and reload correctly.
- FIX: AJAX pagination now falls back to the correct page after filters are removed.
- FIX: Elementor Pro Loop Grid provider now works correctly on archive pages and uses the correct filtered post IDs.
- FIX: Date Period filter custom relative date limits and disabled date styling now work correctly.
- FIX: Dynamic Range filter display in the admin area was corrected.
- FIX: JetSmartFilters text domains and UI typos were corrected.
- FIX: Pagination Filter active page state now updates correctly.
- FIX: Apply button and filter labels are no longer rendered when filter markup is empty.
- FIX: Security hardening for SQL query handling.
- FIX: AJAX GET requests with `jsf_ajax=1` no longer trigger fatal errors.
- FIX: PHP warning for the missing `show_counter` option was fixed.

## 3.8.0.1
- FIX: fatal error after update
- FIX: dynamic range preload without provider query

## 3.8.0
- ADD: Listing Builder now supports JetEngine queries, including registration of custom query types.
- ADD: JetEngine blocks can now be used inside Listing Builder items with proper preview/render support.
- ADD: Range filter can dynamically recalculate min/max values based on the current filtering context.
- ADD: Filter duplication was added in the admin area with redirect to the duplicated filter edit page.
- ADD: JSF Listing items can now be made clickable.
- ADD: Filter Query macro now supports the `Array Value` parameter.
- UPD: Style Manager UI and shared framework loader were updated for the new builder behavior.
- FIX: JetEngine current object and query context are now handled correctly in JSF listings.
- FIX: Sorting Filter now correctly triggers the "Remove Filters" button state.
- FIX: Products Result Count widget values now update correctly during AJAX filtering.
- FIX: Visual filter option labels are rendered correctly.
- FIX: Pagination SEO canonical links were corrected for filtered pages.
- FIX: Listing Builder now saves posts query arguments correctly.
- FIX: `CollapsibleContent` now correctly respects the `initialOpen` prop.

## 3.7.5
- ADD: Divi addon compatibility
- ADD: Pagination Filter SEO
- UPD: Blocks ApiVersion 3
- FIX: Taxonomy term name in URL with tax-query options
- FIX: ePro Archive Products bug after resetting sorting filter
- FIX: add Load More Button role button
- FIX: Filter Listing Image Block height style
- FIX: Radio filter 'Add All' option for third-party plugins
- FIX: Active filters/Active tags with a numeric value
- FIX: JSF List block
- FIX: Bricks Pagination filter tabindex focus
- FIX: CCT filtering not working correctly with special characters
- FIX: PHPCS

## 3.7.4.1
- FIX: Bricks PHPCS

## 3.7.4
- ADD: PHPCS Check
- FIX: fatal error when using Query Builder and tax_query with "Taxonomy term name" => Slug 
- FIX: issue with Bricks Loop

## 3.7.3
- ADD: Allow to set dynamic default value for the filters;
- FIX: Issue with styles for filters inside Bricks popup;
- FIX: Compatibility Elementor filters widgets with WPML;
- FIX: Minor issues with new blocks styles manager;
- FIX: Search Filter. Issue with quotes in the search request when searching by custom fields;
- FIX: Listing Builder. Correctly handle Additional CSS classes for the blocks.

## 3.7.2
- ADD: Admin URL Structure Settings URL Aliases warning text
- FIX: WooCommerce Shortcode sorting
- FIX: Sort filter with Products
- FIX: Date Period filter reset with minDate & maxDate
- FIX: Visual filter Behavior->Radio all option remove indexer count
- FIX: filter Query Variable = _tax_query:: with Taxonomy term name type in URL = slug
- FIX: JetProductTable Warnings
- FIX: Query Variable: _plain_query Warning
- FIX: WooCommerce Shortcode provider with Apply type Page reload/Mixed 
- FIX: jet-elementor-extension framework


## 3.7.1
- ADD: compatibility with weglot
- ADD: filter additional settings text controls to WMPL Advanced Translation Editor
- ADD: indexer group_concat_max_len hook
- FIX: rewrite rules for custom taxonomy subposts
- FIX: Visual filter label XSS
- FIX: removing tags in active items
- FIX: WooCommerce Shortcode provider Pagination
- FIX: Filter is hidden by Indexer after disabling Indexer
- FIX: increase group_concat_max_len
- FIX: admin filter wpml taxonomy terms translate
- FIX: Woocommerce Attributes with JSF Permalinks
- FIX: Remove custom loop marker logic after Bricks 2.0 update

## 3.7.0
- ADD: Listing Builder
- ADD: New style manager

## 3.6.11
- FIX: Plain query for Date Period/Range
- FIX: admin settings subtitle font weight
- FIX: filter predefined value
- FIX: Active Filters/Tags label with " symbol
- FIX: If the parent element is excluded, preserve the hierarchy in its descendants
- FIX: Products Loop with Listing Grid
- FIX: woocommerce wpml multi currency current
- FIX: plain_query data with suffix

## 3.6.10
- UPD: indexer item_id change to BIGINT
- FIX: for warning after activating the plugin
- FIX: Date Period with Apply type -> Page reload
- FIX: Filter Label with HTML
- FIX: Editor block Search with apply type Mixed
- FIX: auto re-indexing
- FIX: add missing styles to enable Hierarchical Select customization for Bricks.

## 3.6.9
- FIX: Pagination for Products Loop is incorrect in some cases;
- FIX: Fatal error when submitting form on the page built with Bricks;
- FIX: PHP error in some cases.

## 3.6.8.2

- FIX: slug name in hierarchical select
- FIX: rollback if filter is not displayed

## 3.6.8.1

- FIX: active filter not working and breaks all filters on the page
- FIX: additional providers doesn't work
- FIX: filter indexer doesn't work properly
- FIX: Cross-site scripting(XSS) vulnerabilities

## 3.6.8

- FIX: Woocommerce archive pagination
- FIX: Wrong apply type if there are multiple identical filters on the same page
- FIX: woocommerse shortcode with query ID
- FIX: more than one plain query parameter
- FIX: js uniqueFilters update

## 3.6.7.1

- UPD: JetQuery Control module to the latest secure version

## 3.6.7

- ADD: Default Woo Archive provider
- ADD: cct checkboxes info
- FIX: Ensure parent class Jet_Smart_Filters_Provider_WooCommerce_Archive is included
- FIX: better compatibility with various themes & not found msg
- FIX: Apply Button Active Button State doesn't recognize Location & Distance filter
- FIX: Error when receiving cct data when jet engine is disabled

## 3.6.6

- FIX: range filters apply on Enter in Safari browser
- FIX: Remove dangerous tags from DB values before usage
- FIX: Elementor Archive & Archive Products providers & optimized DOM

## 3.6.5

- UPD: links in the Need Help button
- FIX: fixed query variable merging for 'Page Reload' filters in Bricks for more reliable behavior
- FIX: Deprecated Dynamic Properties
- FIX: warnings

## 3.6.4

- FIX: sorting filter with meta key containing date
- FIX: Active button state with Radio filter
- FIX: other filters changed indexer
- FIX: Bricks infinite scroll/load more after filtering
- FIX: Add validation for Bricks to check if selected filters are published
- FIX: Override the main WordPress query when the 'Is main query' option is enabled for Bricks query loop

## 3.6.3.1

- FIX: rating filter icon esc_attr (XSS)

## 3.6.3

- ADD: date range filter apply on enter
- ADD: disabling Rank Math SEO integration if there are SEO filter rules on the page
- FIX: filter styles with grouped items in popup
- FIX: search filter with _plain_query
- FIX: options from the postmeta table indexer
- FIX: Elementor Pro Archive Products provider with experimental optionOptimized Markup
- FIX: Uncaught TypeError: i.filterGroup.isCurrentProvider is not a function
- FIX: hierarchical toggle enabled after switching to another filter type
- FIX: Fatal errors in some cases
- FIX: indexer in some cases
- FIX: Added detail object to the bricks/ajax/query_result/displayed event to prevent TypeError on queryId
- FIX: Add check for window.JetSmartFilters object to prevent console errors

## 3.6.2

- FIX: filters with Elementor Portfolio widget
- FIX: if taxonomy does not exist
- FIX: canonical ref link with Yoast SEO
- FIX: Visual filter labels
- FIX: missing jQuery dependency in script enqueue
- FIX: ensured compatibility with Bricks query loop by forcing useQueryFilter to true for correct filtering with JetSmartFilters
- FIX: resolved issue where taxonomy filter didn't work with Bricks query loop when a parent term was selected in 'Terms (Include)'

## 3.6.1

- ADD: hidden filter
- ADD: custom URL symbols options
- ADD: allow to get options from the postmeta table
- FIX: exclude taxonomy terms options
- FIX: default filter value with JetEngine Lazy Load
- FIX: Sanitize the incoming settings using a dedicated method
- FIX: Disable 'Fixed position' option for Provider Preloader in Bricks query loop
- FIX: Vertical alignment for Preloader provider in Bricks query loop
- FIX: Initialize plugin in Bricks popup after JSF filtering
- FIX: Move inline JS for Bricks query loop and filters to external file
- FIX: Add '_query_type' property to ensure indexer works correctly
- FIX: Add updated_query property to AJAX filtering response
- FIX: Prepare queryUpdatePayload for passing to the handle method
- FIX: refactor method for updating filtering results using bricks method
- FIX: Handle auto-scroll option after refactoring for Bricks provider integration

## 3.6.0.1

- FIX: prevent system notices appearing

## 3.6.0

- ADD: SEO Title & Description
- ADD: taxonomy term name type in URL (ID/Slug)
- ADD: filters compatibility with the products variation
- ADD: filter taxonomy terms options sorting
- UPD: added description for "Apply button" with redirect
- FIX: re-init Bricks builder scripts after filtering dynamic calendar
- FIX: add property to store base query variables for "reload" filter type

## 3.5.8

- UPD: Compatibility with Elementor 3.26
- ADD: Ability to close the dropdown on clicking the Apply button
- FIX: indexer with `_tax_query`

## 3.5.7.1

- FIX: hot fix: hierarchical, alphabet, geomap filters

## 3.5.7

- ADD: apply button active state conditions
- FIX: work of two searches with different types together
- FIX: Sorting filter WPML
- FIX: filters are broken on front with "Dynamic Visibility"
- FIX: indexer with taxonomy source and _tax_query key
- FIX: posts disappearing when translating with Polylang
- FIX: included element section
- FIX: added props to event ajaxFilters/updated

## 3.5.6

- ADD: checkboxes list filter relational operator option between items
- ADD: page reload apply type on value change
- ADD: search filter input focus styles
- UPD: initialize filter styles before rendering the filters themselves
- FIX: indexer counter style "Position" option with elementor Optimized Control Loading
- FIX: do not display a filter if it was selected in the widget and then deleted or moved to trash
- FIX: Refactored provider "Bricks query loop" for better clarity

## 3.5.5

- ADD: Pagination Load More autoscroll
- FIX: hierarchical filter clearing select control from filling with browser cache after returning to page
- FIX: data source post args
- FIX: permalink rewrite rules for JSF
- FIX: active filters/tags with $ in value
- FIX: additional settings search

## 3.5.4

- ADD: filter hook 'jet-smart-filters/filters/predefined-value'
- FIX: CCT display bulk options
- FIX: elementor products filtering issue with Loop Carousel having terms query
- FIX: don't hide parent element if nested elements are not empty
- FIX: date range filter translation
- FIX: Indexer when filter option starts with +/*
- FIX: save "N Selected NUMBER OF NAMED ITEMS" option in Gutenberg
- FIX: Group terms by parents & Сollapsible styles
- FIX: Added method merge_query_vars
- FIX: remove font-awesome

## 3.5.3

- UPD: remove font-awesome
- UPD: JetDashboard to v2.2.0
- FIX: WooCommerce Shortcode with Query ID
- FIX: hierarchical filter fatal errore
- FIX: collapsible filter shift
- FIX: hide inactive filters
- FIX: generate new popups after filtering
- FIX: Add preloader to the first item of the bricks loop


## 3.5.2

- FIX: indexer for ePro Posts provider on the archive page
- FIX: ePro Archive Products with url aliases
- FIX: URL aliases + dynamic tag
- FIX: URL Structure Type for different permalink structures
- FIX: ePro Archive Products + Query ID with url aliases
- FIX: synchronization of hierarchical filters
- FIX: Re-initialize Bricks scripts in listing grid after JetSmartFilters filtering

## 3.5.1

- ADD: do_shortcode to ePro loop grid provider No Result Text option
- UPD: open collapsible checked items on initialization
- UPD: open the checkbox filter dropdown on top when the page at it's bottom
- FIX: range filter for safari
- FIX: html decode for search filter on reload
- FIX: Indexer + Custom query
- FIX: added support for native text control in Bricks loop when no results are found.

## 3.5.0

- ADD: SEO & Sitemap Rules
- ADD: "Add signatures to filters requests" option
- ADD: pagination 'Hide inactive' option
- ADD: Additional Settings -> Dropdown -> Add apply button
- ADD: pagination for JetEngine listing Data Store on AJAX
- ADD: create/verify request signatures to avoid request hacking
- ADD: 'jet-smart-filters/range-filter/search-query' filter
- FIX: twice init the Listing Grid after filtering
- FIX: error for 3.4.3+ version

## 3.4.5

- ADD: trimming accidentally entered spaces in the "Custom Field Key"
- ADD: 'jet-smart-filters/range-filter/string-callback-callable' filter
- UPD: JetWooBuilder Products Grid/List providers remake
- FIX: same filters synchronization
- FIX: url with plain_query
- FIX: Move blocks registration to 'init' hook
- FIX: removed the keyboard on devices for 'Date Period'
- FIX: elementor dynamic-tags require files

## 3.4.4

- FIX: links in Crocoblock Dashboard
- FIX: nested provider preloader
- FIX: ePro products add query args condition
- FIX: no update indexer if 'Change Counters' = 'never'
- FIX: the 'paged' property has added by default
- FIX: Deprecated: Creation of dynamic property on PHP 8.1+
- FIX: _plain_query::post__in single value

## 3.4.3

- FIX: Products Loop sorting on reload
- FIX: ePro loop grid sorting filter
- FIX: ePro Loop Grid if global widget
- FIX: url aliases
- FIX: location & distance filter with redirect
- FIX: getting additional filters
- FIX: deprecated

## 3.4.2

- FIX: indexer value with quotation mark
- FIX: datepicker on mobile devices
- FIX: ePro archive products pagination on reload
- FIX: epro-archive-products removed the addition of jet_smart_filters argument from the archive query
- FIX: plain URL aliases with pagination on direct link or reload
- FIX: Warning after update

## 3.4.1

- UPD: changing the default Ajax Request Type value to "self"
- UPD: Icons Font
- FIX: filter Apply on -> Click on apply button
- FIX: Filter Data Source->Custom Fields Bulk Manual Input meta field
- FIX: indexer auto re-indexing with serialized data
- FIX: query key_var check

## 3.4.0

- ADD: Date Range / Date Period datepicker available dates range
- ADD: Date Range / Date Period option Date Type -> Posted / Modified
- ADD: default filter value
- ADD: group terms by parents option Сollapsible
- FIX: prevent warning if no terms included/excluded
- FIX: Bricks. Critical Error
- UPD: jet-dashboard

## 3.3.2

- ADD: valid url params filter
- FIX: ePro Products / ePro Archive Products for search result page
- FIX: Is Checkbox Meta Field option for checkbox meta field created created using the ACF plugin
- FIX: function maybe_parse_repeater_options adding an array check
- FIX: prevent PHP notices
- FIX: if filter option "Exclude Or Include Items" is empty
- FIX: Ajax Request Type -> Referrer / Self plus mixed type pagination
- FIX: correctly parse meta parameters with colon
- FIX: additional providers of duplicate filters
- FIX(Bricks): override main WP query when 'Is main query' option is true
- FIX(Bricks): the method for deleting query cache has been added
- FIX(Bricks): disabling cache option during AJAX.

## 3.3.1

- ADD: datepicker calendar horizontal offset style option
- ADD: compat with Elementor Lazy Load Background Images feature
- FIX: Auto-index with checkboxes
- FIX: JSF loadmore compatibility for the query loop provider has added
- FIX: Archive Products added Automatically align buttons option when filtering
- FIX: ePro Products Source -> Current Query
- FIX: prevent php notice on calendar ajax action
- FIX: Range filter input type

## 3.3.0

- ADD: Added provider preloader
- ADD: Admin UI. Added a list of warnings why the update button is blocked
- ADD: All option and ability to deselect in visual filter when radio behavior
- ADD: Add possibility to use filter values in JetEngine query
- ADD: tabindex with Date Period
- UPD: Hierarchical select
- UPD: redesigned pagination and active elements templates to support unsafe-eval
- FIX: sync search filter with the same
- FIX: Range filter max value input
- FIX: Indexer not working correctly

## 3.2.6

- FIX: Indexer with \_tax_query key in Query Variable
- FIX: redirect for Product grid with Apply type -> Ajax
- FIX: re-init nested Bricks widgets after filtering; the subscription method has been changed
- FIX: Mixed type URL on page reload if 'hc' occurs
- FIX: Active Filters / Active tags if number value
- FIX: indexer with Data Source -> Custom Fields filter

## 3.2.5

- FIX: fatal error when WPML CMS is disable but WPML string translation is enable
- FIX: Select filter Get Choices From Field Data from WooCommerce Product Data meta box
- FIX: sorting from customizer is reset when use filter and Listing Grid
- FIX: JetEngin listing grid custom styles on load more
- FIX: Bricks. filtration on search result page
- FIX: Bricks. Accordion doesn't work after filtering

## 3.2.4

- ADD: sorting filter WPML support
- ADD: .gitattributes
- UPD: optimization for product indexing
- FIX: range filter input decimal numbers
- FIX: additional filters with URL params
- FIX: ePro loop load more with styles
- FIX: Active filters value Cross-Site Scripting (XSS)
- FIX: booking listing & indexer

## 3.2.3

- UPD: cherry-x-vue-ui
- FIX: eProo loop custom post types & draft posts with current query
- FIX: ePro Posts excerpt + filters
- FIX: rewrite permalink rule for custom structure post_id

## 3.2.2.1

- FIX: Security issue.

## 3.2.2

- FIX: ePro Loop indexer
- FIX: rating filter on page reload
- FIX: added filter bricks/query/force_run
- FIX: moved the location of the filter `bricks/query/no_results_content` before rendering

## 3.2.1

- ADD: block editor additional providers
- ADD: range filter Inputs thousands and decimal separators
- ADD: allow disabling apply button in the Search filter
- ADD: ePro loop "No Result Text" option
- UPD: allow to register custom query variables for different request types
- UPD: allow to rewrite default query for provider and query ID pair
- UPD: ePro loop default query
- UPD: jetDashboard framework
- FIX: changing duplicated filters on change
- FIX: ePro loop + predefined filters
- FIX: date range/period RTL datepicker arrows
- FIX: comparison operator with decimal numbers
- FIX: visual filter img alt
- FIX: date filter if date 1970-1-1
- FIX: ePro loop alternate template static item position
- FIX: admin multilingual custom flag
- FIX: hierarchical select filter shows empty options
- FIX: bricks showing and hiding the load more button after filtering

## 3.2.0

- ADD: Elementor Pro Loop Grid provider
- ADD: admin multilingual support
- ADD: additional settings dropdown N selected
- ADD: date period filter Min/Max Dates operations
- ADD: 'Comparison type' option for 'Comparison operator'
- ADD: process shortcodes in 'URL with filtered value' dynamic tag
- UPD: checkbox filter with dropdown update selected items on input change, not on filter change
- UPD: not include children for 'Intersection' relational operator
- UPD: filter each query type key after indexing
- UPD: JetDashboard module
- FIX: admin dropdown outside click
- FIX: custom fields JetEngine WPML string translation
- FIX: current WP Query & Indexer compatibility
- FIX: hierarchical filter with additional providers
- FIX: ePro Posts returns "0.66" value instead blank list for 0 results
- FIX: elementor popup with filters
- FIX: elementor popup with "Improved Asset Loading" option
- FIX: click Back button after applying filters with a redirect
- FIX: visual filter when dragging item changes image
- FIX: fatal error in dynamic tag when filter is deleted
- FIX: ePro Portfolio masonry

## 3.1.2

- ADD: process shortcodes in 'URL with filtered value' dynamic tag
- FIX: apply filter 'jet-smart-filters/render_filter_template/filter_id' for all filters
- FIX: current WP Query & Indexer compatibility
- FIX: date period filter period type "DAY"
- FIX: prevent php notices on php 8.2
- FIX: add filter Id to filter uniqueKey
- FIX: update duplicated hierarchical filter on reload
- FIX: fixed radio filter direction control in Bricks
- UPD: prevent from registering DOING_AJAX constant on non-admin-ajax referrers

## 3.1.1

- UPD: redesigned initialization of filters on the frontend
- ADD: pagination load more
- ADD: fieldset legends & aria-labels
- ADD: don't send ajax request if page hasn't provider
- ADD: filter 'jet-smart-filters/service/filter/serialized-keys'
- ADD: reinitFilters global method
- FIX: sitepath for url aliases
- FIX: URL aliases settings RTL
- FIX: fatal when Bricks query loop ("Is filterable" checked) in listing grid item

## 3.1.0

- ADD: Allow to replace selected parts of the filtered URLs with any alias words you want;
- ADD: Bricks Query Loop provider;
- ADD: PHP 8.2 compatibility;
- UPD: Improve security checks for edit filters settings requests;
- UPD: Visual filter dropdown select for taxonomies and posts data source;
- FIX: Exclude/include data source posts list;
- FIX: Admin filters list pagination;
- FIX: Keep third party URL params on filters clear;
- FIX: JetEngine Calendar and filters compatibility;
- FIX: Fatal error for when accessing admin area for non-admins users.

## 3.0.4

- ADD: Bricks builder compatibility;
- UPD: Filters builder icons;
- UPD: jet dashboard to 2.0.4;
- FIX: Ensure correct provider set from request;
- FIX: compatibility with JetWooBuilder 2.1.2;
- FIX: Visibility of classic admin editor fields in some cases.

## 3.0.3

- ADD: Filter Date Period dates limit
- ADD: indexer counter prefix/suffix & position style
- FIX: refactoring Active filter & Active tag filters
- FIX: elementor pro v3.9.0 popup
- FIX: ignore disabled filters on set data
- FIX: additional providers
- UPD: renamed url prefix from 'jet-smart-filters' to 'jsf'

## 3.0.2

- ADD: admin ability to open a filter from the list in a new tab
- ADD: pagination filter autoscroll option
- FIX: apply all hierarchical selects on redirect
- FIX: checkbox, radio & visual filter RTL
- FIX: active filters, active tag filters duplicate results after mixed url opening
- FIX: ePro Archive Posts taxonomy with multiple post types
- FIX: elementor responsive with url parameters
- FIX: additional settings placeholders translation
- FIX: adding tabindex attr

## 3.0.1

- ADD: admin RTL
- ADD: admin select search field for options
- ADD: admin advanced input for custom query var
- UPD: admin color-image icon
- FIX: admin exclude or include items on options changing
- FIX: admin media control SVG
- ADD: accessibility tabindex
- ADD: 'jet-smart-filters/inited' document event
- ADD: JS trigger before filters initialization
- ADD: allow to use tax query with different sources
- UPD: change icons
- UPD: tax query and new dynamic min/max callbacks
- FIX: prevent notices when Color Image options generated dynamically
- FIX: compatibility with custom options

## 3.0.0

Admin interface changes. Redesigned into single page application.

- FIX: prevent php notices after installation template by wizard
- FIX: prevent php notices on calendar request
- FIX: allow to correctly extend Jet_Smart_Filters_Hierarchy class
- FIX: woocommerce-archive hide out of stock items from the catalog on page reload
- FIX: date period editor block error (air-datepicker script)

## 2.3.14

- ADD: Query ID setting for blocks
- ADD: 'jet-smart-filters/query/request' to filter request before parsing query arguments
- FIX: Compatibility with Elementor 3.7
- FIX: Blocks editor and Listing Grid 'is_archive_template' option compatibility
- FIX: Merge default with current query args on ajax indexing
- FIX: Correctly pull dynamic min/max from meta values for range filter on terms archive pages
- FIX: Select filter. Don't add select_disabled_color control if the indexer is disabled
- FIX: JetEngine Calendar compatibility
- UPD: For indexer SQL query removed space between parenthesis and value. This causes an error for some clients
- UPD: Unchecked group items for intersection relational operator

## 2.3.13

- ADD: JetWooBuilder 2.0.0 version compatibility
- FIX: Blocks styles
- FIX: multi language without multi currency
- FIX: filter name Check Range > Check Range Filter

## 2.3.12

- ADD: reindex indexer DB table on plugin activate and update
- UPD: template parses special characters
- FIX: Permalink rewrite rules
- FIX: range filter with popup
- FIX: WPML WooCommerce multi currency price
- FIX: Date Range Filter datepicker current day
- FIX: Search filter RTL
- FIX: filter date period rtl scroll
- FIX: gutenberg console error
- FIX: indexer with current query args
- FIX: maps listing for Borlabs Cookies plugin
- FIX: additional filter settings input clears the 'X'
- FIX: show widget icon in elementor editor if filter not selected
- FIX: additional filter style search remove horizontal offset RTL
- FIX: additional filter style search remove horizontal offset RTL
- FIX: Checkbox styles for block editor

## 2.3.11

- UPD: replaced deprecated method \_register_controls to register_controls
- FIX: CheckBoxes additional settings dropdown
- FIX: Search filter spinner spins infinitely on submission with 'AJAX on typing'
- FIX: ePro widgets after filtration
- FIX: duplication of sublevels of a hierarchical select
- FIX: woocommerce shortcode attribute on page reload
- FIX: check hierarchy current page
- FIX: Radio filter with motion effects sticky
- FIX: Date range filter query & placeholder on redirect
- FIX: Date period filter for popup
- FIX: Select filter alignment style
- FIX: EPro Posts skin 'Full Content' settings

## 2.3.10

- ADD: elementor pro popup support
- FIX: jet-woo-products-grid/list Use Current Query option on archive page
- FIX: air-datepicker conflict
- FIX: taxanomies parent terms indexer
- FIX: compatibility with Elementor Pro 3.6
- UPD: jet-elementor-extension framework

## 2.3.9

- ADD: Custom Query Variable option for taxonomies source
- ADD: `URL with filtered value` dynamic tag
- UPD: Better JetEngine compatibility
- FIX: Select filter style options
- FIX: WPML tax sub terms indexer
- FIX: Filter label notice

## 2.3.8

- ADD: allow to filter indexer data before writing into DB
- UPD: setIndexedData updating result manually
- FIX: grammatical error correction from HoriSontal to HoriZontal
- FIX: clear range filter input
- FIX: hierarchical chain
- FIX: sanitize widgets settings before passing for rendering
- FIX: indexer with duplicates

## 2.3.7

- ADD: indexer on get filters data request sql SET SESSION group_concat_max_len
- ADD: check is indexer enabled on 'index_filters' method

## 2.3.6

- SYS: renamed indexer method

## 2.3.5

- FIX: JetEngine with Use Custom Query on AJAX compatibility
- FIX: JetEngine lazy load compatibility

## 2.3.4

- FIX: Indexer for custom database table prefix

## 2.3.3

- UPD: Indexer refactoring
- ADD: Auto re-indexing option
- FIX: Alphabet filter
- FIX: Duplicate labels in the filter widget when displaying multiple filters
- FIX: Date Range with one blank field
- FIX: Date Period day type
- FIX: Rating filter clear
- FIX: Check Range filter if item max value 0
- FIX: Range filter if item max value 0
- FIX: Range filter with negatives values
- FIX: elementor editor icons from fa to eicon
- FIX: guten blocks in widgets areas error on refresh
- FIX: remove console.log

## 2.3.2

- ADD: multi sorting
- ADD: Sorting filter Reset Field Appearance control
- FIX: url with additional filters
- FIX: apply button filter for gutenberg
- FIX: Alphabet filter
- FIX: Date period filter events duplication
- FIX: Active tag filter visibility for Hello Elementor theme
- FIX: guten get_editor_script_depends
- FIX: Radio All option label when Group terms by parents
- FIX: Date Range with page reload in Safari
- FIX: hierarchical chaining for identical taxonomies
- FIX: Range filter WooCommerce min/max prices with gets params
- FIX: Hierarchical label
- FIX: jet-engine-calendar current request query

## 2.3.1

- ADD: Query Builder settings to store for JetWooBuilder Product Grid/List providers;
- FIX: Custom query arguments for Product List provider.

## 2.3.0

- ADD: Alphabet filter
- ADD: Multiple query variable separated by comma
- ADD: Radio, Visual, CheckRange filters add additional settings
- ADD: CCT Data Source
- FIX: Additional filter settings dropdown without search
- FIX: range input slider
- FIX: relation AND between filters with the same taxonomy
- FIX: elementor pro Archive Products customizer default product sorting options

## 2.2.3

- ADD: compatibility with new jetEngine features
- UPD: pagination filter provider top offset change max to 999
- UPD: pagination filter items gap
- UPD: checkbox decorator offsets
- FIX: Products cat & tag default taxonomy
- FIX: elementor Scheme_Typography

## 2.2.2

- UPD: Range Filter
- FIX: Grouped Filters styles
- FIX: Minor bugs

## 2.2.1

- UPD: Allow to rewrite indexer query args
- UPD: Rolled back hide elementor widget container if all items are hidden by indexer
- FIX: JetEngine glossaries compatibility
- FIX: Avoid letter-casing related errors when checking if DB table is exists
- FIX: ePro archive products default query
- FIX: ePro Archive Products sorting on page reload if sorting presets are set in the customizer
- FIX: Products loop

## 2.2.0

- ADD: URL Structure Settings (Plain/Permalink)
- ADD: JetTabs ajax load template compatibility
- ADD: Hamburger Panel ajax load template compatibility
- ADD: Hide elementor widget container if all items are hidden by indexer
- ADD: Date period datepicker button text
- ADD: ePro Posts skin full content support
- FIX: Visual filter options list value
- FIX: Checkbox filter MORE/LESS ignore the item if it was hidden by the indexer as empty
- FIX: remove strip slashes on searching
- FIX: check current control on ajax redirect
- FIX: avoid PHP notices
- FIX: bugs fixing

## 2.1.1

- ADD: Hide filter label if all items is hidden
- ADD: Localized data extra_props
- FIX: Filter select grouped filters styles
- FIX: Date period format placeholder
- FIX: Hierarchy filter with single tax
- FIX: Visual filter image empty error
- FIX: EPro Archive Products add tax_query to store query

## 2.1.0

- ADD: New filter Date Period
- ADD: Checkboxes Additional Settings:
  - Search
  - More/Less
  - Dropdown
  - Scroll
- ADD: Radio
  - Ability to add options all
  - Ability to deselect radio buttons
- ADD: Added the ability to change styles in Gutenberg ( **required plugin Jet Style Manager** )
  Widgets that support styles:
  - Active Filters
  - Active Tags
  - Apply Button
  - Checkboxes
  - Check Range
  - Date Period
  - Date Range
  - Pagination
  - Radio
  - Range
  - Rating
  - Remove Filters
  - Search
  - Select
  - Sorting
  - Visual

## 2.0.6

- FIX: WordPress 5.6 compatibility

## 2.0.5

- UPD: jet dashboard to 2.0.4
- FIX: bugs fixing

## 2.0.4

- ADD: hide Elementor widgets: active filters, active tags and remove filters if not active
- ADD: hierarchical filter preloader class
- UPD: change indexer DB columns format
- UPD: jet dashboard to 2.0.0
- FIX: minor bugs

## 2.0.3

- ADD: JetWooBuilder 1.7.0 compatibility
- ADD: compatibility with upcoming jet-engine listing
- FIX: epro-archive widget for products posts

## 2.0.2

- ADD: 'Get from query meta key' callback for range filter
- UPD: wrapper action for jet-engine provider
- FIX: hierarchy filter with single taxonomy
- FIX: process listing grid with nested listing grid
- FIX: epro-archive widget default query tags and custom taxonomy

## 2.0.1

- ADD: jet-dashboard
- ADD: date format for date-range filter
- ADD: ajax content hooks for epro-products widget
- FIX: clearing select filter when returning to the filter page
- FIX: minor bugs

## 2.0.0

- ADD: added filter blocks for gutenberg
- FIX: ignoring a hidden filter in a general query
- FIX: range active items prefix and suffix
- FIX: hide active filter styles while there are no active filters
- FIX: indexer hide/disable items with disabled counter
- FIX: minor bugs

## 1.8.4

- ADD: additional providers repeater with provider and queryID
- ADD: ability to set negative values for range filter
- ADD: merge same query keys for filters with Exclude/Include option
- FIX: ePro Posts 'Open in new window' option
- FIX: clearing meta_query date on redirect
- FIX: term_taxonomy_id from term_id for hierarchy filter
- FIX: don't show the counter when the option is turned off while the indexer is on
- FIX: fix for duplicate pagination filters

## 1.8.3

- FIX: hierarchical select;
- FIX: indexer data key for manual input data source;
- FIX: pagination for Pro Product with query_id;

## 1.8.2

- ADD: allow using numbers in "query id" fields;
- FIX: hierarchical filters workflow with additional providers;
- FIX: filters workflow with the products loop widget;
- FIX: hide filters items in the Safari browser;
- FIX: minor bugs;

## 1.8.1

- FIX: redirect path url;
- FIX: provider widget query ID;
- FIX: reset field appearance;

## 1.8.0

- UPD: front-end code refactoring;
- ADD: allow to choose additional provider for filters;
- ADD: show empty terms for checkboxes, select, radio and visual filters;

## 1.7.2

- ADD: compatibility the Indexer with WPML plugin;
- FIX: applying Indexer functionality for page reload filters;
- FIX: compatibility the Indexer with JetPopup plugin;
- FIX: Checkbox, Check Range, Radio filters horizontal layout style controls;
- FIX: hierarchy levels options list on redirect;
- FIX: various minor fixes.

## 1.7.1

- ADD: Allow to get options for select, radio and checkboxes from custom field data (for JetEngine or ACF);
- FIX: Various fixes.

## 1.7.0

- ADD: Sorting widget;
- ADD: Support for Elementor Pro Portfolio widget;
- ADD: comparison operator for select and radio filters;
- ADD: Search Filter widget add apply on typing option;
- ADD: Relational operator for checkbox filter;
- ADD: Active Tags filter;
- ADD: New aply type for filters;
- UPD: Style options for checkbox, check range, radio, visual filters;
- FIX: Minor bugs.

## 1.6.2

- FIX: grouped filters styles
- FIX: better JetEngine compatibility
- FIX: hide grouped filters when indexer empty

## 1.6.1

- UPD: grouped filters styles
- FIX: various fixes

## 1.6.0

- ADD: allow to make redirect from filters to results page
- ADD: Hiearachical filters
- FIX: Various fixes

## 1.5.1

- FIX: Default query args in jet woo products grid widget

## 1.5.0

- ADD: Indexer functionality for checkboxes, check range, select, visual and radio filter types
- UPD: Hide remove all filters button if no active filters
- UPD: Filters Icons
- FIX: Various fixes

## 1.4.2

- FIX: Hot Fixes

## 1.4.1

- ADD: Need helps links to widgets
- ADD: Placeholders for inputs in Date Range Filter

## 1.4.0

- ADD: Visual filter
- ADD: Include/Exclude functionality
- ADD: Remove all filters button widget
- ADD: Inline layout options for radio, checkboxes, check-range filters
- ADD: Better compatibility with WPML and WooCommerce Multilingual plugins
- ADD: %woocommerce_currency_symbol% macros for range filter prefix and suffix options;
- FIX: Various fixes.
- ADD: Changelog;

## 1.3.2

- ADD: Compatibility with checkbox meta field created with Jet Engine - https://github.com/CrocoBlock/suggestions/issues/163;
- FIX: Merge default query args with current query args;

## 1.3.1

- ADD: Compatibility with WooCommerce Multilingual plugin;
- FIX: Bug with woocommerce archive provider in astra theme;
- FIX: Issue CrocoBlock/suggestions#186;
- FIX: Merging query args with default query args;
- UPD: Compatibility with JetEngine 1.4.0;
- FIX: Various fixes.

## 1.3.0

- ADD: Rating widget;
- ADD: Support for Elementor Pro Products widget;
- ADD: Support for Elementor Pro Archive Products widget;
- ADD: Apply search filter on enter press action
- FIX: Various fixes.

## 1.2.1

- ADD: Allow to filter query before filters applied;
- UPD: Better Compatibility with Elementor Pro;
- FIX: Templates select for JetWooBuilder widgets;
- FIX: Various fixes.

## 1.2.0

- ADD: Separate widget for Apply button;
- ADD: Support for Elementor Pro Posts widget;
- ADD: Support for Elementor Pro Archive widget;
- UPD: New options for Pagination widget;
- FIX: Various fixes.

## 1.1.0.1

- FIX: Large numbers comparing

## 1.1.0

- ADD: RU localization;
- ADD: allow to edit or disable prev/next controls in Pagination widget;
- ADD: allow to set step, number format and suffix for range and check range filters;
- UPD: allow to search by meta field in search filter;
- UPD: run Elementor ready triggers after apply filters;
- UPD: allow to filter same query variable by multiple filters.

## 1.0.0

- Initial release
