import { test } from 'node:test';
import assert from 'node:assert/strict';
import { setupI18n } from './support/i18n';
import { changeLocale, hasKey, missingKeys } from '../../src/i18n/i18n';
import { NAV_GROUPS, navLabel, searchNav } from '../../src/layouts/tenantNav';
import { SEGMENT_LABELS, segmentKey, segmentLabel } from '../../src/navigation/breadcrumbLabels';

setupI18n();

test('every sidebar entry has a translation key whose English is its canonical label', async () => {
  await changeLocale('en');
  for (const group of NAV_GROUPS) {
    if (group.label) {
      assert.ok(group.labelKey && hasKey(group.labelKey), `group ${group.label}`);
      assert.equal(navLabel(group), group.label);
    }
    for (const item of group.items) {
      assert.ok(hasKey(item.labelKey), `item ${item.label}`);
      assert.equal(navLabel(item), item.label);
    }
  }
});

test('sidebar labels and menu search follow the language; the English identity stays', async () => {
  await changeLocale('id');
  const vehicle = NAV_GROUPS.find((g) => g.label === 'Vehicle')!;
  assert.equal(navLabel(vehicle), 'Kendaraan');
  assert.equal(vehicle.label, 'Vehicle');
  // Searching matches the shown (Indonesian) label …
  const hits = searchNav(NAV_GROUPS, 'riwayat').flatMap((g) => g.items.map((i) => i.to));
  assert.ok(hits.includes('/app/vehicle-history'));
  // … and the group is matched by its Indonesian name.
  assert.equal(searchNav(NAV_GROUPS, 'kendaraan').find((g) => g.label === 'Vehicle')?.items.length, vehicle.items.length);
  await changeLocale('en');
  assert.ok(searchNav(NAV_GROUPS, 'history').flatMap((g) => g.items).some((i) => i.to === '/app/vehicle-history'));
});

test('breadcrumb segments resolve to breadcrumb.<camelCase> keys with the same English', async () => {
  await changeLocale('en');
  for (const [segment, english] of Object.entries(SEGMENT_LABELS)) {
    assert.ok(hasKey(segmentKey(segment)), segment);
    assert.equal(segmentLabel(segment), english);
  }
  assert.equal(segmentKey('maintenance-policies'), 'breadcrumb.maintenancePolicies');
  await changeLocale('id');
  assert.equal(segmentLabel('vehicles'), 'Kendaraan');
  assert.equal(segmentLabel('unknown-segment'), 'Unknown Segment');
  await changeLocale('en');
  assert.deepEqual([...missingKeys], []);
});
