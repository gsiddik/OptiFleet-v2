import { useState } from 'react';
import { ExcelImportModal } from '../../../components/ExcelImportModal';
import { FormField, inputStyle } from '../../../components/FormField';
import { ITEM_TYPES } from './CreateProductModal';
import type { ItemType } from '../../../types';
import { t } from '../../../i18n/i18n';

/**
 * Products → Import: one template per Item Type (its own specification columns). The chosen Item Type
 * selects the template to download and is sent with the upload, so a template of another Item Type is
 * rejected by the server. In an Item Type context (e.g. Rim) the Item Type is fixed.
 */
export function ProductImportModal({ initialItemType, locked = false, onClose, onImported }: { initialItemType?: string; locked?: boolean; onClose: () => void; onImported: () => void }) {
  const [itemType, setItemType] = useState<ItemType>((ITEM_TYPES as string[]).includes(initialItemType ?? '') ? (initialItemType as ItemType) : 'SPARE_PART');

  return (
    <ExcelImportModal
      key={itemType}
      title={t('productImport.modals.importProducts')}
      intro={t('productImport.help.intro')}
      templatePath="/app/products/import-template"
      templateFilename={`product-import-${itemType.toLowerCase().replace('_', '-')}.xlsx`}
      previewPath="/app/products/import/preview"
      importPath="/app/products/import"
      query={{ item_type: itemType }}
      onClose={onClose}
      onImported={onImported}
      templateControls={
        <FormField label={t('productImport.fields.itemTypeTemplate')} required>
          <select value={itemType} onChange={(e) => setItemType(e.target.value as ItemType)} disabled={locked} style={{ ...inputStyle, maxWidth: 260 }} aria-label={t('productImport.fields.itemTypeTemplate')} data-import-item-type>
            {ITEM_TYPES.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
        </FormField>
      }
    />
  );
}
