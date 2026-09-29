# Component Classification Taxonomy — Category & Subcategory Master

Status: **COMPLETE** (all phases). Continues `COMPONENT_GROUP_MASTER_STATUS.md`
on branch `claude/magical-volta-tv4xwl`.

## Owner decisions applied (follow-up)

- SKU is server-generated from Item Type + Component Group abbreviation
  (details in `COMPONENT_GROUP_MASTER_STATUS.md`). Category / Subcategory are
  NOT part of the SKU; an issued SKU never changes.
- **Component Group + Category are mandatory** for new Sparepart, Consumable,
  Tire and Rim Products and cannot be cleared once set; **Subcategory is
  optional** for every Item Type. Tools/Equipment may stay unclassified.
  Legacy unclassified Products are only held to the rule once their
  classification is edited (no backfill).

## Model

```
Item Type (products.product_type, independent dimension)
+ Component Group (component_groups, existing)
    -> Component Category   (component_categories, L2 Category / Assembly)
        -> Component Subcategory (component_subcategories, L3 Component Family)
            <-> Allowed Item Types (component_subcategory_item_types; empty = unrestricted)
Product: component_group_id / component_category_id / component_subcategory_id (nullable)
```

- Separate from `product_categories` (Superadmin commercial / Item-Type catalog
  that drives the Dynamic Product Form) and from `product_component_groups`
  (compatibility tagging). Nothing was merged or reused by name.
- Tenant-or-platform ownership, same as Component Group: baseline rows are
  platform (`tenant_id NULL`, `is_system`); tenants add their own rows.
- Codes: UPPER_SNAKE_CASE, unique per parent and owner, soft-deleted rows
  included (partial unique indexes + advisory-locked service check).
- Soft delete only; every FK to the taxonomy is `ON DELETE NO ACTION`.
- Effective availability for new data: self + every ancestor `ACTIVE` and not
  deleted. Children are never cascade-deleted or cascade-restored.

## Taxonomy data

- Source: `docs/reference/component-taxonomy.md` (authoritative, committed).
- Transcription: `backend/database/data/component_taxonomy.php` — explicit
  codes, source order (`sequence` 10, 20, …), source line per row, source
  "Contoh Item/Contoh" kept as `description` ("Examples: …"), nothing invented.
- Totals: **25 groups, 334 categories, 917 subcategories, 855 Item Type
  mappings**. Reference rows processed 917, seeded 917, excluded 0.
- Section V/W of the reference (illustrative trees such as "Guide Pin",
  "Repair Kit") are summaries, not table rows, and add no rows.
- `ComponentTaxonomyTest` re-parses the reference independently and asserts a
  1:1 match with the seeded database.

### Item Type mapping basis (recorded per row in the data file)

| Basis | Rows | Rule |
|---|---:|---|
| explicit | 77 | Engine table's own Item Type column |
| explicit_section | 49 | Wheel & Tyre sub-headings: Tire→TIRE, Rim→RIM, Wheel Related Spareparts→SPARE_PART, its "Consumable" category→CONSUMABLE |
| derived_material | 51 | Fluid/lubricant/chemical/coolant/refrigerant/additive/coating/"Consumable" categories → CONSUMABLE (structurally equivalent to Engine "Engine Service") |
| derived_filter | 17 | Replaceable filter elements → SPARE_PART + CONSUMABLE |
| derived_component | 644 | Hardware rows → SPARE_PART (structurally equivalent to Engine table rows) |
| unmapped | 79 | Attachment & Work Equipment, Optional Accessories, tyre "Inner Components" — ambiguous (Equipment/Tools/Sparepart), left unrestricted |

By type: SPARE_PART 746, CONSUMABLE 74, TIRE 25, RIM 10. No row maps to
TOOL/EQUIPMENT; Tools/Equipment Products may stay unclassified (classification
is mandatory only down to Category, and only for Sparepart/Consumable/Tire/Rim). All mappings are editable per Subcategory.

## Seeder (`ComponentTaxonomySeeder`, called from `MasterDataSeeder`)

Adds only natural keys that have never existed (active or soft-deleted);
never overwrites name/description/status/sequence/parent/item types, never
restores, never touches tenant rows. Future rows added to the data file are
picked up on the next deploy. Bulk insert under an advisory lock (~1–3 s).

## Rules enforced by `ComponentClassificationService`

- Product hierarchy: Subcategory ∈ Category ∈ Component Group; a lower level
  requires its parent; changed values must be effectively active; Item Type
  applicability checked when the Subcategory is chosen/changed.
- Product edit: unchanged (even retired) classification never blocks a save.
- Used Category/Subcategory cannot be re-parented; unused can.
- Item Types still used by existing Products cannot be removed from a
  Subcategory.
- Duplicate code (incl. deleted) and duplicate name (same parent) rejected.
- Audit: created / updated (incl. parent change) / deactivated / restored /
  item_types_changed.

## API

- Tenant `/app/component-categories`, `/app/component-subcategories` (CRUD +
  restore; baseline read-only for tenants), platform equivalents under
  `/platform/…`.
- Product-form lookups gated by `product.view`:
  `/app/product-classification/{component-groups,categories,subcategories}`
  (effective-active only; `include_inactive=1` for filters; subcategories carry
  `item_types` and `allowed` for the given `item_type`).
- Products: accept/return `component_group_id`, `component_category_id`,
  `component_subcategory_id` (+ `component_group`/`component_category`/
  `component_subcategory` relations, soft-deleted included); list filters on
  all three. No Product import/export exists in the codebase.

## Permissions

`component_category.{view,create,update,delete}` and
`component_subcategory.{view,create,update,delete}` in both scopes. Migration
`2026_09_29_000007` grants each tenant permission to every role holding the
matching `component_group.*` action.

## Frontend

- Master Data → Component Categories / Component Subcategories (tenant and
  platform): list, search, Component Group / Category / Item Type / status /
  deleted filters, sort, pagination, create, edit (parent locked when used),
  Allowed Item Types multi-select, soft delete + restore.
- Product create/edit: "Component Classification" cascading picker
  (`{ABBR} — {Name}` groups; explicit child reset; subcategories not applicable
  to the Item Type shown disabled; retired current values shown on edit).
- Product detail shows the classification (deleted levels marked); Product
  list has dependent Group → Category → Subcategory filters and a column.

## Not changed (scope)

Maintenance / Work Order / Inspection keep using Component Group only (they
have no Product-classification dependency). No existing Product was
backfilled or guessed from its name.
