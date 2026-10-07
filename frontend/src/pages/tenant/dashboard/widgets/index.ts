import type { WidgetDefinition } from '../WidgetCard';
import { CURRENT_WIDGETS } from './currentWidgets';
import { FINANCE_WIDGETS } from './financeWidgets';
import { OPS_WIDGETS } from './opsWidgets';
import { TREND_WIDGETS } from './trendWidgets';

/** Frontend renderers by audit widget ID. A catalog widget without a renderer is not shown. */
export const WIDGET_DEFINITIONS: Record<string, WidgetDefinition> = {
  ...CURRENT_WIDGETS,
  ...FINANCE_WIDGETS,
  ...TREND_WIDGETS,
  ...OPS_WIDGETS,
};
