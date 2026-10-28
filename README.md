# WarpSearch for Magento 2 / Adobe Commerce

Instant, relevance-ranked **product search for Magento 2 and Adobe Commerce**, powered by
[WarpSearch](https://warpsearch.io/), the fast, affordable serverless search service. The module
indexes your catalog in WarpSearch and adds an as-you-type quick-search dropdown to the
storefront, with no extra search cluster to run or tune.

- **Instant quick search** on Magento's search box: product names, images and prices as shoppers
  type, keyboard navigation, and Enter on a suggestion opens the product
- **Relevance tiers**: name and SKU first, then categories, short description and searchable
  attributes, then description and child product names
- **All product types**: simple, configurable, grouped and bundle; parents carry their children's
  SKUs and names, and min/max prices are computed from enabled children
- **Always in sync**: product save/delete, stock, category assignment, mass attribute/status
  updates and website assignment are picked up and indexed through Magento's message queue
- **Multi-store**: one document per product per store view, with store-view labels, prices and
  visibility
- **Safe by design**: the dropdown calls a server-side proxy that holds the key and re-checks
  products in Magento, so keys never reach the browser and stale entries never show. If WarpSearch
  is unreachable, Magento's native autocomplete takes over within 3 seconds
- **CLI**: `bin/magento warpsearch:reindex` for full or partial reindexing

Docs: [warpsearch.io/docs/integration-magento](https://warpsearch.io/docs/integration-magento) ·
Plans: [warpsearch.io/use-cases/ecommerce](https://warpsearch.io/use-cases/ecommerce) ·
Support: [hello@warpsearch.io](mailto:hello@warpsearch.io)

## Requirements

Magento Open Source or Adobe Commerce 2.4.6 to 2.4.8, PHP 8.2 to 8.4, the MySQL message queue
(default), and a [WarpSearch account](https://warpsearch.io/). The free plan is enough to start.

## Installation

```bash
composer require warpsearch/module-search
bin/magento module:enable WarpSearch_Search
bin/magento setup:upgrade && bin/magento setup:di:compile
```

1. In the [WarpSearch console](https://warpsearch.io/console/), create a collection (no mapping
   needed) and an API key with the **writer** role on it.
2. In **Stores > Configuration > WarpSearch > Search**, set Enable = Yes (per store view), the API
   key, collection and index name. **API URL** defaults to `https://api.warpsearch.io`.
3. Make sure the queue consumer runs (below), then run `bin/magento warpsearch:reindex`.

The same settings work from the CLI:

```bash
bin/magento config:set warpsearch/general/api_key <key>          # stored encrypted
bin/magento config:set warpsearch/general/collection myshop_prod
bin/magento config:set --scope=stores --scope-code=default warpsearch/general/enabled 1
```

### Queue consumer

Changes go through the database queue `warpsearch.product.index`. Cron runs consumers when
`cron_consumers_runner` allows it; if you restrict the list, add the consumer in `app/etc/env.php`:

```php
'cron_consumers_runner' => ['cron_run' => true, 'max_messages' => 1000, 'consumers' => ['warpsearch.product.index']],
```

Or run it directly: `bin/magento queue:consumers:start warpsearch.product.index`. Setting **Index
through the message queue** to No indexes synchronously in the save request instead.

If indexing a message fails (for example WarpSearch unreachable after retries), Magento marks it
as an error and does not retry it; the product ids are logged (`WarpSearch indexing failed for
products ...`). Recover with `bin/magento warpsearch:reindex --ids=...` or a full reindex.

## CLI

```
bin/magento warpsearch:reindex [--store=code] [--ids=1,2,3] [--batch=100] [--queue]
```

Exit code 1 if any document failed.

## What gets indexed

A product is indexed in a store view when it is assigned to the store's website, enabled, visible
in **Search** or **Catalog, Search**, and in stock (unless out-of-stock products are shown).
Otherwise its document is removed, so disabling, unassigning and deleting take the same path.

- Text: name and SKU, categories, short description and searchable attributes, description and
  child names. Searchable attributes are indexed as text, filterable ones as keywords, using
  store-view labels for select and multiselect attributes.
- Prices: minimum and maximum final price for guests under store emulation. Configurable and
  grouped products use their enabled children's prices; bundles use Magento's bundle price model.
- Stock is read from the stock items written in the save request, so it is correct even before
  Magento's stock indexer runs. A composite is in stock when it and at least one enabled child are.
- When a child product changes or is deleted, its parents are re-indexed.

Not covered by events (schedule a periodic `warpsearch:reindex`): catalog price rules, special
price date expiry, imports that bypass the model layer, and products deleted while the module was
disabled.

Writes are idempotent upserts and deletes by id, batched (`batch_size`, default 100). Rate limits
and server errors are retried with backoff, and documents a cold collection rejects
(`warming_up`) are resubmitted for up to 2 minutes.

## Storefront

The module adds a quick-search dropdown to Magento's `#search` input. It calls
`/warpsearch/search/suggest?q=`, a server-side controller that queries WarpSearch and hydrates the
product ids from Magento (name, URL, image, formatted price, status and visibility re-checked).
It makes one attempt with a 3-second timeout; on any error the dropdown closes and native
autocomplete returns.

The `/catalogsearch/result` page and layered navigation keep using Magento's configured engine
(OpenSearch or Elasticsearch). A full search engine adapter for results pages and layered
navigation is on the roadmap.

## Limitations

- Customer-group and tier prices, and per-source MSI stock, are not modelled (guest price,
  default stock).
- Text analysis is one global setting (Standard or English), not per store view.
- Children's attribute values (for example a configurable's colours) are not copied to the parent.

## Development and tests

```bash
composer install && vendor/bin/phpunit
```

FieldNames, DocumentMapper and QueryBuilder tests run standalone. The Publisher and Client tests
need `magento/framework` and skip otherwise; inside a Magento project, run them with Magento's
PHPUnit and a bootstrap that loads `vendor/autoload.php`, then `Test/Unit/bootstrap.php`.

Tested with Magento Open Source 2.4.8-p5 on PHP 8.3 and 8.4, and 2.4.6-p15 on PHP 8.2; clean
`setup:di:compile`; Magento Coding Standard at severity 10 reports no errors.

## License

MIT, see [LICENSE](LICENSE). WarpSearch by BigData Boutique: [warpsearch.io](https://warpsearch.io/).
